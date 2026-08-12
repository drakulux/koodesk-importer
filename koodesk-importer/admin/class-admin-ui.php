<?php
/**
 * Koodesk Importer – AdminUI
 *
 * Registers the WP admin menu page and routes between the six import steps.
 * All rendering delegates to view files in admin/views/.
 *
 * Step flow:
 *   upload → classify (new profile) → context_confirm → match → preview → result
 *
 * State is passed between steps as hidden POST fields.
 * The uploaded CSV file path is stored in a transient keyed to a session token
 * so it survives across form submissions without re-uploading.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Admin_UI {

	const MENU_SLUG    = 'koodesk-importer';

	/** Tracks whether inline CSS has been output to avoid duplicates. */
	private static bool $css_printed = false;
	const TRANSIENT_TTL = 3600; // 1 hour

	/** @var Koodesk_File_Reader */
	private Koodesk_File_Reader $file_reader;
	/** @var Koodesk_Profile_Manager */
	private Koodesk_Profile_Manager $profile_manager;
	/** @var Koodesk_Column_Classifier */
	private Koodesk_Column_Classifier $classifier;
	/** @var Koodesk_Student_Matcher */
	private Koodesk_Student_Matcher $matcher;
	/** @var Koodesk_Transformer */
	private Koodesk_Transformer $transformer;
	/** @var Koodesk_Importer */
	private Koodesk_Importer $importer;
	/** @var Koodesk_Import_History */
	private Koodesk_Import_History $import_history;

	public function __construct(
		Koodesk_File_Reader       $file_reader,
		Koodesk_Profile_Manager   $profile_manager,
		Koodesk_Column_Classifier $classifier,
		Koodesk_Student_Matcher   $matcher,
		Koodesk_Transformer       $transformer,
		Koodesk_Importer          $importer,
		Koodesk_Import_History    $import_history
	) {
		$this->file_reader     = $file_reader;
		$this->profile_manager = $profile_manager;
		$this->classifier      = $classifier;
		$this->matcher         = $matcher;
		$this->transformer     = $transformer;
		$this->importer        = $importer;
		$this->import_history  = $import_history;

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_frontend_assets' ] );
		add_shortcode( 'koodesk_importer',        [ $this, 'render_shortcode' ] );
		add_shortcode( 'koodesk_import_history', [ $this, 'render_history_shortcode' ] );

		// AJAX handler for profile deletion
		add_action( 'wp_ajax_kd_delete_profile', [ $this, 'ajax_delete_profile' ] );

		// AJAX handler for import reversal
		add_action( 'wp_ajax_kd_reverse_import',        [ $this, 'ajax_reverse_import' ] );
		add_action( 'wp_ajax_kd_classify_for_subject', [ $this, 'ajax_classify_for_subject' ] );
		add_action( 'wp_ajax_kd_render_step',          [ $this, 'ajax_render_step' ] );
		add_action( 'wp_ajax_nopriv_kd_render_step',   [ $this, 'ajax_render_step' ] );
		add_action( 'wp_ajax_kd_delete_hist_entry',    [ $this, 'ajax_delete_hist_entry' ] );
		add_action( 'wp_ajax_kd_clear_history',        [ $this, 'ajax_clear_history' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			'Koodesk Importer',
			'KD Import',
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ],
			'dashicons-database-import',
			82
		);
		add_submenu_page(
			self::MENU_SLUG,
			'Import History',
			'History',
			'manage_options',
			self::MENU_SLUG . '-history',
			[ $this, 'render_history' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) return;
		wp_add_inline_style( 'wp-admin', $this->get_inline_css() );
		$this->enqueue_navigation_script();
	}

	public function enqueue_frontend_assets(): void {
		if ( ! wp_style_is( 'kd-importer', 'registered' ) ) {
			wp_register_style( 'kd-importer', false, [], null );
		}
		wp_enqueue_style( 'kd-importer' );
		wp_add_inline_style( 'kd-importer', $this->get_inline_css() );
		$this->enqueue_navigation_script();
	}

	/**
	 * Enqueue the navigation JS (no-refresh step transitions) on both admin and frontend.
	 * Uses wp_script_is() guard so it runs only once even if called multiple times.
	 */
	private function enqueue_navigation_script(): void {
		if ( wp_script_is( 'kd-navigation', 'enqueued' ) ) return;

		$js_url = KOODESK_IMPORTER_URL . 'admin/js/kd-navigation.js';
		wp_enqueue_script(
			'kd-navigation',
			$js_url,
			[],
			'1.4.0',
			true  // load in footer
		);

		// Pass the AJAX URL to the script as a global variable
		wp_add_inline_script(
			'kd-navigation',
			'window.KD_AJAX_URL = ' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ';',
			'before'
		);
	}

	/**
	 * Shortcode handler — [koodesk_importer]
	 * Accessible to any logged-in user; Bricks Builder controls page visibility.
	 */
	public function render_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="kd-notice">You must be logged in to access the importer.</p>';
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		ob_start();

		// Inline the CSS directly in shortcode output as a guaranteed fallback.
		// Bricks Builder may strip enqueued styles in its canvas/rendering pipeline.
		/*if ( ! self::$css_printed ) {
			self::$css_printed = true;
			echo '<style id="kd-importer-css">' . $this->get_inline_css() . '</style>';
		}*/
		echo '<div class="kd-importer kd-importer--frontend">';
		// heading removed

		// Allow ?kd_view=history on the same page to show the history view
		$kd_view = sanitize_key( $_GET['kd_view'] ?? '' );
		if ( $kd_view === 'history' ) {
			$this->render_frontend_history();
			echo '</div>';
			return ob_get_clean();
		}

		$step = sanitize_key( $_POST['kd_step'] ?? 'upload' );
		$this->render_step_indicator( $step );

		// FIX: "Start Over" on frontend returns to the same importer page
		// Use the REQUEST_URI (stripping query/POST state) so it always works
		// regardless of whether this is a singular page or not.
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		// Strip any query string so we get a clean page URL
		$uri_clean = strtok( $uri, '?' );
		$this->current_page_url = $uri_clean ?: '/';

		switch ( $step ) {
			case 'upload':          $this->step_upload();          break;
			case 'classify':        $this->step_classify();        break;
			case 'context_confirm': $this->step_context_confirm(); break;
			case 'match':           $this->step_match();           break;
			case 'preview':         $this->step_preview();         break;
			case 'run_import':      $this->step_run_import();      break;
			default:                $this->step_upload();
		}

		echo '</div>';

		return ob_get_clean();
	}

	/** URL to use for "Start Over" — varies by context */
	private string $current_page_url = '';

	/**
	 * Returns the correct "Start Over" URL based on context (admin vs frontend).
	 */
	public function get_start_over_url(): string {
		if ( ! empty( $this->current_page_url ) ) {
			return $this->current_page_url;
		}
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	// -------------------------------------------------------------------------
	// Main router
	// -------------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}

		$step = sanitize_key( $_POST['kd_step'] ?? $_GET['step'] ?? 'upload' );

		// FIX #1: set admin URL for start over
		$this->current_page_url = '';

		echo '<div class="wrap kd-importer">';
		// heading removed
		$this->render_step_indicator( $step );

		switch ( $step ) {
			case 'upload':          $this->step_upload();          break;
			case 'classify':        $this->step_classify();        break;
			case 'context_confirm': $this->step_context_confirm(); break;
			case 'match':           $this->step_match();           break;
			case 'preview':         $this->step_preview();         break;
			case 'run_import':      $this->step_run_import();      break;
			default:                $this->step_upload();
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Step 1: Upload
	// -------------------------------------------------------------------------

	private function step_upload(): void {
		$import_types = [
			'academic_records' => 'Academic Records',
			'students'         => 'Students',
		];

		$profiles_ar = $this->profile_manager->get_profiles( 'academic_records' );
		$profiles_st = $this->profile_manager->get_profiles( 'students' );
		$start_over_url = $this->get_start_over_url();

		include KOODESK_IMPORTER_VIEWS . 'step-upload.php';
	}

	// -------------------------------------------------------------------------
	// Step 2: Classify (new profile) or skip straight to context_confirm
	// -------------------------------------------------------------------------

	private function step_classify(): void {
		$import_type = sanitize_key( $_POST['import_type'] ?? '' );
		$profile_id  = intval( $_POST['profile_id'] ?? 0 );
		$token       = sanitize_key( $_POST['kd_token'] ?? '' );

		if ( empty( $token ) ) {
			$parsed = $this->file_reader->handle_upload_and_parse( $_FILES['csv_file'] ?? [] );
			if ( isset( $parsed['error'] ) ) {
				$this->show_error( $parsed['error'] );
				$this->step_upload();
				return;
			}
			$token         = $this->store_parsed_file( $parsed );
			$_POST['kd_token'] = $token;
		} else {
			$parsed = $this->load_parsed_file( $token );
			if ( ! $parsed ) {
				$this->show_error( 'Session expired. Please re-upload your file.' );
				$this->step_upload();
				return;
			}
		}

		// When a saved profile is selected: go to classify with the profile pre-filled
		// so the user can review/edit the full mapping before proceeding.
		$saved_profile_mapping = null;
		if ( $profile_id > 0 ) {
			$saved_profile_mapping = $this->profile_manager->get_column_mapping( $profile_id );
		}

		$headers        = $parsed['headers'];
		$known_subjects = $this->get_known_subjects();
		$suggestions    = $this->classifier->classify( $headers, $known_subjects, $import_type );
		$templates      = $this->get_assessment_templates();

		// Existing profile names for duplicate-name detection
		$existing_profiles      = $this->profile_manager->get_profiles( $import_type );
		$existing_profile_names = array_map( fn($p) => $p['profile_name'], $existing_profiles );

		// System classes for the class-confirm dropdown
		$system_classes = $this->get_system_classes();

		$start_over_url = $this->get_start_over_url();

		include KOODESK_IMPORTER_VIEWS . 'step-classify.php';
	}

	// -------------------------------------------------------------------------
	// Step 3: Context confirmation
	// -------------------------------------------------------------------------

	private function step_context_confirm(): void {
		$import_type = sanitize_key( $_POST['import_type'] ?? '' );
		$profile_id  = intval( $_POST['profile_id'] ?? 0 );
		$token       = sanitize_key( $_POST['kd_token'] ?? '' );

		// Save profile if checkbox was checked (only for new mappings, not existing profiles)
		if ( $profile_id === 0 && ! empty( $_POST['do_save_profile'] ) ) {
			$profile_name = sanitize_text_field( $_POST['profile_name'] ?? '' );
			if ( $profile_name === '' ) {
				$this->show_error( 'Please enter a profile name before saving.' );
				$this->step_classify();
				return;
			}
			$profile_id = $this->save_profile_from_post( $import_type );
			if ( ! $profile_id ) {
				// Errors already shown by save_profile_from_post — re-render classify
				$this->step_classify();
				return;
			}
		} elseif ( $profile_id === 0 ) {
			// No save — store the mapping in a transient keyed to the session token.
			// This avoids creating __temp_ profiles in the DB.
			$mapping = $this->build_mapping_from_post();
			$errors  = $this->profile_manager->validate_mapping( $mapping, $import_type );
			if ( ! empty( $errors ) ) {
				foreach ( $errors as $e ) $this->show_error( $e );
				$this->step_classify();
				return;
			}
			// Store mapping in transient; use a sentinel profile_id of -1 to signal "transient mapping"
			set_transient( 'kd_temp_mapping_' . $token, $mapping, self::TRANSIENT_TTL );
			$profile_id = -1;
		}

		$mapping = $profile_id === -1
			? get_transient( 'kd_temp_mapping_' . $token )
			: $this->profile_manager->get_column_mapping( $profile_id );
		if ( ! $mapping ) {
			$start_over_url = $this->get_start_over_url();
			echo '<div class="notice notice-error"><p><strong>Could not load mapping.</strong> '
				. '<a href="' . esc_url( $start_over_url ) . '">Start the import again</a>.</p></div>';
			return;
		}

		$parsed = $this->load_parsed_file( $token );
		if ( ! $parsed ) {
			$this->show_error( 'Session expired. Please re-upload your file.' );
			$this->step_upload();
			return;
		}

		$session_term_pairs = $this->file_reader->detect_session_term_values(
			$parsed['rows'],
			$mapping['session_col'] ?? '',
			$mapping['term_col']    ?? ''
		);

		$has_multiple_terms = count( $session_term_pairs ) > 1;
		$start_over_url = $this->get_start_over_url();

		// Pass mapping for the full review table
		$profile_mapping = $mapping;

		// Calculate total marks obtainable from subject count × 100
		// so the context step can display it even when not mapped from CSV
		$subject_count_estimated  = count( $mapping['subjects'] ?? [] );
		$total_marks_calculated   = $subject_count_estimated * 100;

		include KOODESK_IMPORTER_VIEWS . 'step-context.php';
	}

	// -------------------------------------------------------------------------
	// Step 4: Student matching
	// -------------------------------------------------------------------------

	private function step_match(): void {
		$import_type = sanitize_key( $_POST['import_type'] ?? '' );
		$profile_id  = intval( $_POST['profile_id'] ?? 0 );
		$token       = sanitize_key( $_POST['kd_token'] ?? '' );

		$mapping = $profile_id === -1
			? get_transient( 'kd_temp_mapping_' . $token )
			: $this->profile_manager->get_column_mapping( $profile_id );
		$parsed  = $this->load_parsed_file( $token );

		if ( ! $mapping || ! $parsed ) {
			$start_over_url = $this->get_start_over_url();
			echo '<div class="notice notice-error"><p><strong>Session data missing or expired.</strong> '
				. 'Please <a href="' . esc_url( $start_over_url ) . '">start the import again</a>.</p></div>';
			return;
		}

		$name_col    = array_search( 'full_name',            $mapping['student_fields'] ?? [], true ) ?: '';
		$ext_key_col = array_search( 'external_student_key', $mapping['student_fields'] ?? [], true ) ?: '';

		// Run matching for both academic_records AND students
		// (students import can update existing students with missing profile data)
		$this->matcher->preload();
		$match_results = $this->matcher->match_all( $parsed['rows'], $name_col, $ext_key_col );

		$matcher = $this->matcher;
		$start_over_url = $this->get_start_over_url();
		include KOODESK_IMPORTER_VIEWS . 'step-match.php';
	}

	// -------------------------------------------------------------------------
	// Step 5: Dry-run preview
	// -------------------------------------------------------------------------

	private function step_preview(): void {
		$import_type    = sanitize_key( $_POST['import_type'] ?? '' );
		$profile_id     = intval( $_POST['profile_id'] ?? 0 );
		$token          = sanitize_key( $_POST['kd_token'] ?? '' );
		$match_decisions = $this->decode_match_decisions( $_POST['match'] ?? [] );

		$mapping = $profile_id === -1
			? get_transient( 'kd_temp_mapping_' . $token )
			: $this->profile_manager->get_column_mapping( $profile_id );
		$parsed  = $this->load_parsed_file( $token );

		if ( ! $mapping || ! $parsed ) {
			$this->show_error( 'Session data missing.' );
			return;
		}

		$name_col    = array_search( 'full_name',            $mapping['student_fields'] ?? [], true ) ?: '';
		$ext_key_col = array_search( 'external_student_key', $mapping['student_fields'] ?? [], true ) ?: '';

		// FIX #4: Run a "dry" transform to detect issues before importing
		$plan = $this->importer->prepare_import_plan(
			$parsed['rows'],
			$mapping,
			$match_decisions,
			$name_col,
			$ext_key_col,
			$import_type
		);

		// FIX #4: Generate preview warnings (score mismatch, missing totals, etc.)
		$preview_issues = $this->generate_preview_issues( $plan, $mapping, $parsed['rows'] );

		$plan_counts = $this->count_plan( $plan );

		// FIX #3: Detect total-mismatch issues for subject assessments
		$total_mismatch_subjects = $this->detect_total_mismatches( $plan, $mapping );

		$decisions_token = sanitize_key( 'kd_decisions_' . wp_generate_password( 12, false ) );
		set_transient( $decisions_token, $match_decisions, self::TRANSIENT_TTL );

		// Detect existing records for this session+term combination
		$existing_records = ( $import_type === 'academic_records' )
			? $this->detect_existing_records( $plan, $mapping )
			: [];

		$start_over_url = $this->get_start_over_url();
		include KOODESK_IMPORTER_VIEWS . 'step-preview.php';
	}

	// -------------------------------------------------------------------------
	// Step 6: Run import and show result
	// -------------------------------------------------------------------------

	private function step_run_import(): void {
		if ( ! isset( $_POST['kd_import_nonce'] ) || ! wp_verify_nonce( $_POST['kd_import_nonce'], 'kd_run_import' ) ) {
			wp_die( 'Security check failed.' );
		}

		$import_type      = sanitize_key( $_POST['import_type'] ?? '' );
		$profile_id       = intval( $_POST['profile_id'] ?? 0 );
		$token            = sanitize_key( $_POST['kd_token'] ?? '' );
		$decisions_token  = sanitize_key( $_POST['kd_decisions_token'] ?? '' );

		// FIX #3: total mismatch resolution — 'csv' or 'calculated'
		$total_resolution = sanitize_key( $_POST['total_resolution'] ?? 'csv' );

		$mapping = $profile_id === -1
			? get_transient( 'kd_temp_mapping_' . $token )
			: $this->profile_manager->get_column_mapping( $profile_id );
		$parsed           = $this->load_parsed_file( $token );
		$match_decisions  = get_transient( $decisions_token ) ?: [];

		if ( ! $mapping || ! $parsed ) {
			$this->show_error( 'Session data missing. Please start the import again.' );
			return;
		}

		$name_col    = array_search( 'full_name',            $mapping['student_fields'] ?? [], true ) ?: '';
		$ext_key_col = array_search( 'external_student_key', $mapping['student_fields'] ?? [], true ) ?: '';

		$plan = $this->importer->prepare_import_plan(
			$parsed['rows'],
			$mapping,
			$match_decisions,
			$name_col,
			$ext_key_col,
			$import_type
		);

		// Pass total_resolution to importer
		$result = $this->importer->run_import( $plan, $mapping, $total_resolution );

		$this->profile_manager->touch( $profile_id );

		// Store import log in persistent history for undo from history page
		$log_key = $this->store_import_log( $result, $import_type, $profile_id, $mapping );
		$result['import_log_key'] = $log_key;

		delete_transient( $decisions_token );
		$this->cleanup_parsed_file( $token );

		$start_over_url = $this->get_start_over_url();
		$history_url    = $this->find_history_page_url();
		include KOODESK_IMPORTER_VIEWS . 'step-result.php';
	}

	// -------------------------------------------------------------------------
	// AJAX: Delete profile (FIX #2)
	// -------------------------------------------------------------------------

	public function ajax_delete_profile(): void {
		if ( ! check_ajax_referer( 'kd_delete_profile', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce.' );
		}
		if ( ! current_user_can( 'manage_options' ) && ! is_user_logged_in() ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$profile_id = intval( $_POST['profile_id'] ?? 0 );
		if ( $profile_id <= 0 ) {
			wp_send_json_error( 'Invalid profile ID.' );
		}
		$ok = $this->profile_manager->delete_profile( $profile_id );
		if ( $ok ) {
			wp_send_json_success( 'Profile deleted.' );
		} else {
			wp_send_json_error( 'Could not delete profile.' );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX: Reverse import (FIX #14)
	// -------------------------------------------------------------------------

	public function ajax_reverse_import(): void {
		if ( ! check_ajax_referer( 'kd_reverse_import', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce.' );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$log_key = sanitize_key( $_POST['log_key'] ?? '' );
		if ( empty( $log_key ) ) {
			wp_send_json_error( 'No import log key provided.' );
		}

		// Try persistent history first (new system), then fall back to transient (legacy)
		$log = $this->import_history->get_entry( $log_key );
		if ( $log ) {
			if ( ! empty( $log['reversed'] ) ) {
				wp_send_json_error( 'This import has already been reversed.' );
			}
		} else {
			// Legacy: transient-based log from result page
			$log = get_transient( $log_key );
			if ( ! $log ) {
				wp_send_json_error( 'Import log not found. It may have already been reversed or the history was cleared.' );
			}
		}

		$deleted = $this->importer->reverse_import( $log );

		// Mark as reversed in persistent history
		$this->import_history->mark_reversed( $log_key );
		// Also delete transient if it existed
		delete_transient( $log_key );

		wp_send_json_success( [
			'records_deleted'   => $deleted['records_deleted'],
			'summaries_deleted' => $deleted['summaries_deleted'],
			'students_deleted'  => $deleted['students_deleted'],
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: History operations
	// -------------------------------------------------------------------------

	public function ajax_delete_hist_entry(): void {
		if ( ! check_ajax_referer( 'kd_delete_hist_entry', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce.' );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$entry_id = sanitize_key( $_POST['entry_id'] ?? '' );
		if ( $entry_id ) {
			$this->import_history->delete_entry( $entry_id );
			wp_send_json_success();
		} else {
			wp_send_json_error( 'Missing entry ID.' );
		}
	}

	public function ajax_clear_history(): void {
		if ( ! check_ajax_referer( 'kd_clear_history', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce.' );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$this->import_history->clear_all();
		wp_send_json_success();
	}


	// -------------------------------------------------------------------------
	// Import History page
	// -------------------------------------------------------------------------

	public function render_history(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}
		$history        = $this->import_history->get_all();
		$start_over_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$is_frontend    = false;

		echo '<div class="wrap kd-importer">';
		echo '<h1>Import History</h1>';
		include KOODESK_IMPORTER_VIEWS . 'step-history.php';
		echo '</div>';
	}

	/**
	 * [koodesk_import_history] shortcode — dedicated history page for frontend.
	 * Also used when ?kd_view=history is appended to the importer page URL.
	 */
	/**
	 * Shortcode: [koodesk_import_history importer_url="/data-import/"]
	 * Optional attribute: importer_url — the URL of the page with [koodesk_importer]
	 */
	public function render_history_shortcode( array $atts = [] ): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="kd-notice">You must be logged in to view import history.</p>';
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$atts = shortcode_atts( [
			'importer_url' => '',  // URL of the page containing [koodesk_importer]
		], $atts, 'koodesk_import_history' );

		// Determine URL to send users back to the importer
		$importer_url = '';
		if ( ! empty( $atts['importer_url'] ) ) {
			$importer_url = esc_url( $atts['importer_url'] );
		} else {
			// Try to find a page with the [koodesk_importer] shortcode
			$importer_url = $this->find_importer_page_url();
		}

		// Set for back-links
		$uri_clean = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
		$this->current_page_url = $uri_clean;

		ob_start();

		/*if ( ! self::$css_printed ) {
			self::$css_printed = true;
			echo '<style id="kd-importer-css">' . $this->get_inline_css() . '</style>';
		}*/

		echo '<div class="kd-importer kd-importer--frontend">';
		echo '<h2>Import History</h2>';
		$this->render_frontend_history( $importer_url );
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * Render the history view in a frontend context.
	 * Used by both [koodesk_import_history] shortcode and ?kd_view=history.
	 */
	private function render_frontend_history( string $importer_url = '' ): void {
		$history = $this->import_history->get_all();
		// Use explicit importer URL if provided, else fall back to current page or admin
		$start_over_url = $importer_url ?: $this->get_start_over_url();
		$is_frontend    = true;
		include KOODESK_IMPORTER_VIEWS . 'step-history.php';
	}

	/**
	 * Find the URL of a page with [koodesk_import_history] shortcode.
	 */
	private function find_history_page_url(): string {
		global $wpdb;
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('page','post')
			   AND post_content LIKE '%[koodesk_import_history%'
			 ORDER BY ID ASC LIMIT 1"
		);
		if ( $page_id ) {
			return (string) get_permalink( (int) $page_id );
		}
		return admin_url( 'admin.php?page=koodesk-importer-history' );
	}

	/**
	 * Find the URL of a WordPress page that contains the [koodesk_importer] shortcode.
	 * Used to auto-populate the "Back to Importer" link when importer_url is not set.
	 */
	private function find_importer_page_url(): string {
		global $wpdb;
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('page','post')
			   AND post_content LIKE '%[koodesk_importer%'
			 ORDER BY ID ASC LIMIT 1"
		);
		if ( $page_id ) {
			return (string) get_permalink( (int) $page_id );
		}
		// Fall back to the admin page
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}


	// -------------------------------------------------------------------------
	// AJAX step renderer — enables no-refresh navigation
	// -------------------------------------------------------------------------

	public function ajax_render_step(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in.' );
		}

		// Simulate the normal step routing but capture output
		// The file upload step cannot be AJAX (file upload needs multipart form)
		// so upload always does a real POST; all other steps use AJAX.
		$step = sanitize_key( $_POST['kd_step'] ?? 'upload' );

		// Set context URL from referer for start-over links
		$referer = wp_get_referer();
		if ( $referer ) {
			$uri = parse_url( $referer, PHP_URL_PATH );
			$this->current_page_url = $uri ?: '';
		}

		ob_start();
		$this->render_step_indicator( $step );

		// 'upload' step cannot run via AJAX (needs a real page with file input).
		// Tell the JS to navigate to the page URL instead of swapping content.
		if ( $step === 'upload' ) {
			ob_end_clean();
			wp_send_json_success( [ 'redirect' => $this->get_start_over_url(), 'step' => 'upload' ] );
			return;
		}

		switch ( $step ) {
			case 'classify':        $this->step_classify();        break;
			case 'context_confirm': $this->step_context_confirm(); break;
			case 'match':           $this->step_match();           break;
			case 'preview':         $this->step_preview();         break;
			case 'run_import':
				if ( ! isset( $_POST['kd_import_nonce'] ) || ! wp_verify_nonce( $_POST['kd_import_nonce'], 'kd_run_import' ) ) {
					echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
					break;
				}
				$this->step_run_import();
				break;
			default:
				// Unknown step — return the upload form so user can restart cleanly
				$this->step_upload();
		}

		$html = ob_get_clean();
		wp_send_json_success( [ 'html' => $html, 'step' => $step ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: Classify columns for a specific subject (FIX: per-subject detect)
	// -------------------------------------------------------------------------

	public function ajax_classify_for_subject(): void {
		if ( ! check_ajax_referer( 'kd_classify_subject', 'nonce', false ) ) {
			wp_send_json_error( 'Invalid nonce.' );
		}

		$subject_id   = intval( $_POST['subject_id'] ?? 0 );
		$subject_name = sanitize_text_field( $_POST['subject_name'] ?? '' );
		$subject_code = sanitize_text_field( $_POST['subject_code'] ?? '' );
		$token        = sanitize_key( $_POST['kd_token'] ?? '' );

		if ( ! $subject_id || ! $token ) {
			wp_send_json_error( 'Missing subject_id or token.' );
		}

		$parsed = $this->load_parsed_file( $token );
		if ( ! $parsed ) {
			wp_send_json_error( 'Session expired.' );
		}

		$subject = [
			'_ID'          => $subject_id,
			'subject_name' => $subject_name,
			'subject_code' => $subject_code,
		];

		$detected = $this->classifier->classify_for_subject( $parsed['headers'], $subject );
		wp_send_json_success( $detected );
	}

	// -------------------------------------------------------------------------
	// Profile saving from classify POST
	// -------------------------------------------------------------------------

	private function save_profile_from_post( string $import_type ): int {
		$profile_name = sanitize_text_field( $_POST['profile_name'] ?? '' );
		if ( $profile_name === '' ) return 0;

		// FIX #7: Auto-suffix duplicate names
		$existing = $this->profile_manager->get_profiles( $import_type );
		$existing_names = array_column( $existing, 'profile_name' );
		$profile_name = $this->unique_profile_name( $profile_name, $existing_names );

		$template_id = intval( $_POST['assessment_template_id'] ?? 0 );
		$mapping = $this->build_mapping_from_post();

		$errors = $this->profile_manager->validate_mapping( $mapping, $import_type );
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $e ) $this->show_error( $e );
			return 0;
		}

		return $this->profile_manager->create_profile( $profile_name, $import_type, $mapping, $template_id );
	}

// create_temp_profile_from_post removed — mapping stored in transient instead

	/**
	 * Sanitize a CSV column name from POST: unslash (fix WP magic quotes) and
	 * trim, but do NOT collapse internal spaces — the original header may have
	 * double spaces (e.g. "CCA  1st CA") that must be preserved so the key
	 * matches the CSV row array.
	 */
	private function sanitize_col( string $raw ): string {
		return trim( wp_unslash( $raw ) );
	}

	/**
	 * Build mapping array from POST data (shared between save and temp).
	 */
	private function build_mapping_from_post(): array {
		$mapping = [
			'student_fields'  => [],
			'guardian_fields' => [],
			'subjects'        => [],
			'summary_fields'  => [],
			'skip_cols'       => [],
			'term_col'        => '',
			'session_col'     => '',
			'term_value_map'  => [ 'First' => 1, 'Second' => 2, 'Third' => 3 ],
		];

		foreach ( $_POST['student_field_map'] ?? [] as $db_field => $col ) {
			$db_field = sanitize_key( $db_field );
			$col      = $this->sanitize_col( $col );
			if ( $col === '' || $db_field === '' ) continue;
			if ( $db_field === 'term' )    { $mapping['term_col']    = $col; continue; }
			if ( $db_field === 'session' ) { $mapping['session_col'] = $col; continue; }
			$mapping['student_fields'][ $col ] = $db_field;
		}

		foreach ( $_POST['subject_map'] ?? [] as $subj_data ) {
			$subject_id   = intval( $subj_data['subject_id'] ?? 0 );
			$subject_name = $this->sanitize_col( $subj_data['subject_name'] ?? '' );
			if ( $subject_id === 0 ) continue;

			$assessments = [];
			foreach ( $subj_data['assessments'] ?? [] as $a ) {
				$label = $this->sanitize_col( $a['label'] ?? '' );
				$col   = $this->sanitize_col( $a['col'] ?? '' );
				if ( $label !== '' && $col !== '' ) {
					$assessments[] = [ 'label' => $label, 'col' => $col ];
				}
			}
			if ( empty( $assessments ) ) continue;

			$mapping['subjects'][] = [
				'subject_id'   => $subject_id,
				'subject_name' => $subject_name,
				'assessments'  => $assessments,
				'total_col'    => $this->sanitize_col( $subj_data['total_col']    ?? '' ),
				'grade_col'    => $this->sanitize_col( $subj_data['grade_col']    ?? '' ),
				'remark_col'   => $this->sanitize_col( $subj_data['remark_col']   ?? '' ),
				'position_col' => $this->sanitize_col( $subj_data['position_col'] ?? '' ),
			];
		}

		foreach ( $_POST['summary_field_map'] ?? [] as $db_field => $col ) {
			$db_field = sanitize_key( $db_field );
			$col      = $this->sanitize_col( $col );
			if ( $db_field !== '' && $db_field !== 'skip' && $col !== '' ) {
				$mapping['summary_fields'][ $col ] = $db_field;
			}
		}

		foreach ( $_POST['guardian_field_map'] ?? [] as $sub_field => $col ) {
			$sub_field = sanitize_key( $sub_field );
			$col       = $this->sanitize_col( $col );
			if ( $sub_field !== '' && $col !== '' ) {
				$mapping['guardian_fields'][ $sub_field ] = $col;
			}
		}

		// Family fields (family_name, home_address) are merged into student_fields
		// because Koodesk_Importer::find_mapped_col() only searches that array —
		// this keeps a single lookup path for "is X column mapped?" across the importer.
		foreach ( $_POST['family_field_map'] ?? [] as $db_field => $col ) {
			$db_field = sanitize_key( $db_field );
			$col      = $this->sanitize_col( $col );
			if ( $db_field !== '' && $col !== '' ) {
				$mapping['student_fields'][ $col ] = $db_field;
			}
		}

		return $mapping;
	}

	/**
	 * FIX #7: Generate a unique profile name by appending (1), (2), etc.
	 */
	private function unique_profile_name( string $name, array $existing ): string {
		if ( ! in_array( $name, $existing, true ) ) return $name;
		$i = 1;
		while ( in_array( "$name ($i)", $existing, true ) ) $i++;
		return "$name ($i)";
	}

	// -------------------------------------------------------------------------
	// FIX #4: Generate preview issues before importing
	// -------------------------------------------------------------------------

	private function generate_preview_issues( array $plan, array $mapping, array $rows ): array {
		$issues = [];

		foreach ( $plan as $item ) {
			if ( $item['status'] === 'skip' ) continue;
			$row = $item['row'];

			foreach ( $mapping['subjects'] ?? [] as $subj ) {
				$subject_name = $subj['subject_name'];

				// Check for total mismatch
				if ( ! empty( $subj['total_col'] ) && ! empty( $subj['assessments'] ) ) {
					$csv_total = trim( (string) ( $row[ $subj['total_col'] ] ?? '' ) );
					$calc = 0.0;
					foreach ( $subj['assessments'] as $a ) {
						$v = trim( (string) ( $row[ $a['col'] ] ?? '' ) );
						if ( is_numeric( $v ) ) $calc += (float) $v;
					}
					if ( $csv_total !== '' && is_numeric( $csv_total ) ) {
						$diff = abs( (float) $csv_total - $calc );
						if ( $diff > 1.0 && $calc > 0 ) {
							$issues[] = [
								'type'    => 'total_mismatch',
								'student' => $item['raw_name'],
								'subject' => $subject_name,
								'csv'     => (float) $csv_total,
								'calc'    => $calc,
							];
						}
					}
				}

				// Check for missing grade/remark columns
				if ( ! empty( $subj['grade_col'] ) ) {
					$grade_val = trim( (string) ( $row[ $subj['grade_col'] ] ?? '' ) );
					if ( $grade_val === '' ) {
						$issues[] = [
							'type'    => 'missing_grade',
							'student' => $item['raw_name'],
							'subject' => $subject_name,
						];
					}
				}
			}
		}

		return $issues;
	}

	// -------------------------------------------------------------------------
	// FIX #3: Detect per-subject total mismatches across all rows
	// -------------------------------------------------------------------------

	private function detect_total_mismatches( array $plan, array $mapping ): array {
		$mismatches = []; // subject_name => ['csv_uses' => n, 'calc_uses' => n]

		foreach ( $plan as $item ) {
			if ( $item['status'] === 'skip' ) continue;
			$row = $item['row'];
			foreach ( $mapping['subjects'] ?? [] as $subj ) {
				if ( empty( $subj['total_col'] ) ) continue;
				$csv_total = trim( (string) ( $row[ $subj['total_col'] ] ?? '' ) );
				if ( $csv_total === '' || ! is_numeric( $csv_total ) ) continue;
				$calc = 0.0;
				foreach ( $subj['assessments'] as $a ) {
					$v = trim( (string) ( $row[ $a['col'] ] ?? '' ) );
					if ( is_numeric( $v ) ) $calc += (float) $v;
				}
				if ( $calc > 0 && abs( (float) $csv_total - $calc ) > 1.0 ) {
					$n = $subj['subject_name'];
					if ( ! isset( $mismatches[ $n ] ) ) {
						$mismatches[ $n ] = [ 'count' => 0, 'example_csv' => 0, 'example_calc' => 0 ];
					}
					$mismatches[ $n ]['count']++;
					$mismatches[ $n ]['example_csv']  = (float) $csv_total;
					$mismatches[ $n ]['example_calc'] = $calc;
				}
			}
		}

		return $mismatches;
	}

	// -------------------------------------------------------------------------
	// Store import log for potential reversal (FIX #14)
	// -------------------------------------------------------------------------

	private function store_import_log( array $result, string $import_type, int $profile_id, array $mapping = [] ): string {
		$session    = $result['context_session']    ?? '';
		$term_int   = (int) ( $result['context_term']    ?? 0 );
		$class_name = $result['context_class_name'] ?? '';

		$profile_name = '';
		if ( $profile_id > 0 ) {
			$profiles = $this->profile_manager->get_profiles( $import_type );
			foreach ( $profiles as $p ) {
				if ( (int)( $p['_ID'] ?? 0 ) === $profile_id ) {
					$profile_name = $p['profile_name'] ?? '';
					break;
				}
			}
		}

		$id = $this->import_history->record(
			$result,
			$import_type,
			$profile_id,
			$profile_name,
			$session,
			$term_int,
			$class_name
		);

		return $id;
	}

	// -------------------------------------------------------------------------
	// Transient-based file session storage
	// -------------------------------------------------------------------------

	private function store_parsed_file( array $parsed ): string {
		$token = 'kd_import_' . wp_generate_password( 16, false );
		set_transient( $token, $parsed, self::TRANSIENT_TTL );
		return $token;
	}

	private function load_parsed_file( string $token ): ?array {
		if ( empty( $token ) ) return null;
		$data = get_transient( $token );
		return is_array( $data ) ? $data : null;
	}

	private function cleanup_parsed_file( string $token ): void {
		$data = get_transient( $token );
		if ( is_array( $data ) && ! empty( $data['file_path'] ) && file_exists( $data['file_path'] ) ) {
			@unlink( $data['file_path'] );
		}
		delete_transient( $token );
	}

	// -------------------------------------------------------------------------
	// Data helpers
	// -------------------------------------------------------------------------

	private function get_known_subjects(): array {
		if ( ! function_exists( 'jet_engine' ) ) return [];
		$cct_m = jet_engine()->modules->get_module( 'custom-content-types' );
		if ( ! $cct_m ) return [];
		$subjects_cct = $cct_m->instance->manager->get_content_types( 'subjects' );
		if ( ! $subjects_cct ) return [];
		return $subjects_cct->db->query( [ [ 'field' => 'cct_status', 'operator' => '=', 'value' => 'publish' ] ] ) ?: [];
	}

	private function get_assessment_templates(): array {
		if ( ! function_exists( 'jet_engine' ) ) return [];
		$cct_m = jet_engine()->modules->get_module( 'custom-content-types' );
		if ( ! $cct_m ) return [];
		$tpl_cct = $cct_m->instance->manager->get_content_types( 'assessment_templates' );
		if ( ! $tpl_cct ) return [];
		return $tpl_cct->db->query( [ [ 'field' => 'cct_status', 'operator' => '=', 'value' => 'publish' ] ] ) ?: [];
	}

	/** FIX #12: Get all classes for the class confirmation dropdown */
	private function get_system_classes(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_classes';
		// Check table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) return [];
		return $wpdb->get_results(
			"SELECT _ID, class_name FROM {$table} WHERE cct_status = 'publish' ORDER BY class_name ASC",
			ARRAY_A
		) ?: [];
	}

	private function decode_match_decisions( array $raw ): array {
		$decisions = [];
		foreach ( $raw as $lookup_key => $data ) {
			$action     = sanitize_key( $data['action'] ?? 'skip' );
			$student_id = intval( $data['student_id'] ?? 0 );
			$decisions[ $lookup_key ] = [ 'action' => $action, 'student_id' => $student_id ];
		}
		return $decisions;
	}

	private function count_plan( array $plan ): array {
		$counts = [ 'existing' => 0, 'new' => 0, 'skip' => 0 ];
		foreach ( $plan as $item ) {
			if ( $item['status'] === 'use_existing' )  $counts['existing']++;
			elseif ( $item['status'] === 'create_new' ) $counts['new']++;
			else $counts['skip']++;
		}
		return $counts;
	}

	/**
	 * Check which students in the plan already have academic records
	 * for the detected session and term.
	 * Returns array of [ student_id => [ 'name' => .., 'session' => .., 'term' => .. ] ]
	 */
	private function detect_existing_records( array $plan, array $mapping ): array {
		global $wpdb;

		$existing = [];

		if ( ! function_exists( 'jet_engine' ) ) return $existing;

		try {
			$cct_m  = jet_engine()->modules->get_module( 'custom-content-types' )->instance->manager;
			$ar_cct = $cct_m->get_content_types( 'academic_record' );
			$ar_table = $ar_cct->db->table();
		} catch ( \Throwable $e ) {
			return $existing;
		}

		foreach ( $plan as $item ) {
			if ( $item['status'] === 'skip' ) continue;
			$student_id = (int) ( $item['student_id'] ?? 0 );
			if ( $student_id <= 0 ) continue;

			$session  = trim( (string) ( $item['row'][ $mapping['session_col'] ?? '' ] ?? '' ) );
			$term_raw = trim( (string) ( $item['row'][ $mapping['term_col'] ?? '' ] ?? '' ) );
			$term_map = $mapping['term_value_map'] ?? [];
			$term_int = (int) ( $term_map[ $term_raw ] ?? $term_raw );

			if ( $session === '' || $term_int === 0 ) continue;

			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$ar_table}
				 WHERE student_id = %d AND session = %s AND term = %d",
				$student_id, $session, $term_int
			) );

			if ( $count > 0 ) {
				$existing[ $student_id ] = [
					'name'         => $item['raw_name'],
					'session'      => $session,
					'term'         => $term_int,
					'record_count' => $count,
				];
			}
		}

		return $existing;
	}

	// -------------------------------------------------------------------------
	// UI helpers
	// -------------------------------------------------------------------------

	private function render_step_indicator( string $current_step ): void {
		$steps = [
			'upload'          => '1. Upload',
			'classify'        => '2. Map Columns',
			'context_confirm' => '3. Confirm Context',
			'match'           => '4. Match Students',
			'preview'         => '5. Preview',
			'run_import'      => '6. Done',
		];
		echo '<div class="kd-steps">';
		foreach ( $steps as $slug => $label ) {
			$cls = $slug === $current_step ? 'kd-step kd-step--active' : 'kd-step';
			echo '<span class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
		}
		echo '</div>';
	}

	public function show_error( string $message ): void {
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}

	public function show_notice( string $message ): void {
		echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function get_inline_css(): string {
		return '
		/* ── Koodesk Importer — shared admin + frontend styles ── */

		.kd-importer {
			width: 100%;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			font-size: 14px;
			color: var(--text-body);
		}
		.kd-importer h2 {
			font-size: 1.4rem;
			font-weight: 700;
			margin: 0 0 1.25rem;
			color: var(--text-body);
		}

		/* ── Progress stepper ── */
		.kd-steps {
			display: flex;
			gap: 0;
			margin-bottom: 1.75rem;
			border-radius: 4px;
			overflow: hidden;
			box-shadow: 0 1px 3px rgba(0,0,0,.08);
		}
		.kd-step {
			flex: 1;
			padding: 9px 14px;
			background: var(--bg-medium);
			border-right: 1px solid var(--border-primary);
			border-top: 1px solid var(--border-primary);
			border-bottom: 1px solid var(--border-primary);
			font-size: 12px;
			font-weight: 500;
			color: var(--text-body);
			text-align: center;
			white-space: nowrap;
			transition: background .15s;
		}
		.kd-step:first-child { border-left: 1px solid var(--border-primary); border-radius: 4px 0 0 4px; }
		.kd-step:last-child  { border-right: 1px solid var(--border-primary); border-radius: 0 4px 4px 0; }
		.kd-step--active {
			background: #2271b1;
			color: #fff;
			border-color: #2271b1;
			font-weight: 700;
			position: relative;
			z-index: 1;
		}
		.kd-step--done {
			background: #e7f3ff;
			color: #2271b1;
			border-color: #b8d6f5;
		}

		/* ── Section cards ── */
		.kd-section {
			margin-top: 1.25rem;
			padding: 1.1rem 1.4rem;
			background: var(--bg-surface);
			border: 1px solid var(--border-primary);
			border-radius: 4px;
			box-shadow: 0 1px 2px rgba(0,0,0,.05);
		}
		.kd-section > h3,
		.kd-section > h4 {
			margin-top: 0;
			margin-bottom: .75rem;
			font-size: 1rem;
			color: var(--text-body);
		}

		/* ── Tables ── */
		.kd-table {
			width: 100%;
			border-collapse: collapse;
			margin-top: .75rem;
			font-size: 13px;
		}
		.kd-table th {
			background: var(--bg-medium);
			text-align: left;
			padding: 8px 11px;
			letter-spacing: 0.3px;
			border: 1px solid var(--border-primary);
			font-weight: 500;
			font-size: var(--font-size-tiny);
			color: var(--tertiary);
			text-transform: uppercase;
		}
		.kd-table td {
			padding: 7px 11px;
			border: 1px solid var(--border-primary);
			vertical-align: middle;
			background: var(--bg-surface);
		}
		.kd-table tr:nth-child(even) td { background: var(--bg-body); }
		.kd-table tr:hover td { background: var(--hover-color); }

		/* ── Badges ── */
		.kd-badge {
			display: inline-block;
			padding: 2px 9px;
			border-radius: 10px;
			font-size: 11px;
			font-weight: 600;
			letter-spacing: .02em;
		}
		.kd-badge--green  { background: #d1e7dd; color: #0a5c2e; }
		.kd-badge--yellow { background: #fff3cd; color: #664d03; }
		.kd-badge--red    { background: #f8d7da; color: #58151c; }
		.kd-badge--grey   { background: #e2e3e5; color: #41464b; }
		.kd-badge--blue   { background: #cfe2ff; color: #084298; }
		.kd-badge--orange { background: #ffe0b2; color: #7c3d00; }

		/* ── Form controls on frontend ── */
		.kd-importer--frontend input[type="text"],
		.kd-importer--frontend input[type="file"],
		.kd-importer--frontend select,
		.kd-importer--frontend textarea {
			box-sizing: border-box;
			width: 100%;
			max-width: 340px;
			padding: 7px 10px;
			border: 1px solid var(--border-primary);
			border-radius: 3px;
			font-size: 13px;
			background: var(--white-brown);
			color: var(--text-body);
			line-height: 1.4;
		}
		.kd-importer--frontend input[type="file"] {
			padding: 5px 8px;
			background: var(--white-brown);
			cursor: pointer;
		}
		.kd-importer--frontend select.kd-select { max-width: 280px; }
		.kd-importer--frontend input:focus,
		.kd-importer--frontend select:focus {
			border-color: #2271b1;
			outline: 2px solid rgba(34,113,177,.25);
		}
		.kd-importer--frontend label > strong,
		.kd-importer--frontend label > b {
			display: block;
			margin-bottom: 4px;
			font-size: 13px;
		}

		/* ── Buttons on frontend ── */
		.kd-importer--frontend .button,
		.kd-importer--frontend .button-primary {
			display: inline-block;
			padding: 8px 18px;
			border-radius: 3px;
			font-size: 13px;
			font-weight: 600;
			cursor: pointer;
			text-decoration: none;
			border: 1px solid #8c8f94;
			background: #f6f7f7;
			color: #1d2327;
			line-height: 1;
		}
		.kd-importer--frontend .button-primary {
			background: var(--primary);
			border-color: var(--primary-d-1);
			color: #fff;
		}	
		.kd-importer--frontend .button:hover { background: #E9F0F6; }
		.kd-importer--frontend .button-primary:hover { background: var(--primary-d-1); }
		.kd-importer--frontend .button-small {
			padding: 4px 10px;
			font-size: 12px;
		}
		.kd-importer--frontend p.submit { margin-top: 1.25rem; }

		/* ── Subject blocks ── */
		.kd-subject-block {
			border: 1px solid var(--border-primary);
			border-left: 3px solid #2271b1;
			padding: 1rem 1.1rem;
			margin-bottom: .9rem;
			background: var(--bg-body);
			border-radius: 0 4px 4px 0;
		}
		.kd-subject-block h4 { margin: 0 0 .65rem; }
		.kd-subject-block details { margin-top: .6rem; }
		.kd-subject-block summary {
			cursor: pointer;
			font-size: 12px;
			color: #2271b1;
			font-weight: 600;
			user-select: none;
		}
		.kd-subject-block summary:hover { text-decoration: underline; }

		/* ── Select fields with visible dropdown caret ── */
		.kd-importer select,
		.kd-importer--frontend select,
		.kd-select {
			-webkit-appearance: none;
			-moz-appearance:    none;
			appearance:         none;
			max-width: 280px;
			padding-right: 28px !important;
			background-image: url("data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%2350575e%22%20d%3D%22M1%201l5%205%205-5%22%2F%3E%3C%2Fsvg%3E");
			background-repeat: no-repeat;
			background-position: right 8px center;
			background-size: 10px 7px;
			cursor: pointer;
		}
		.kd-importer select option,
		.kd-importer--frontend select option { background: var(--white-brown); color: var(--text-body); }

		/* ── Mapped-column legend ── */
		.kd-mapped-legend {
			font-size: 11px;
			color: #888;
			margin-top: .3rem;
			display: block;
		}

		/* Already-mapped options get a light grey background — no Unicode needed */
		option.kd-opt-mapped {
			background-color: #f0f0f1;
			color: #999;
		}

		/* ── Back button ── */
		.kd-back-btn {
			background: #f6f7f7 !important;
			border-color: #8c8f94 !important;
			color: #50575e !important;
		}
		.kd-back-btn:hover {
			background: #E9F0F6 !important;
			color: #1d2327 !important;
		}

		/* ── Notice boxes ── */
		.kd-notice {
			padding: 10px 14px;
			border-left: 4px solid #72aee6;
			background: #e7f3ff;
			border-radius: 0 3px 3px 0;
			font-size: 13px;
			margin: .75rem 0;
		}
		.kd-notice--warning {
			border-left-color: #f0b849;
			border-top: 1px solid var(--border-primary);
			border-bottom: 1px solid var(--border-primary);
			border-right: 1px solid var(--border-primary);
			background: var(--yellow-dark);
		}
		.kd-notice--error {
			border-left-color: #d63638;
			background: var(--unassigned-back);
		}
		.notice.notice-error { border-top: 1px solid var(--border-primary); border-bottom: 1px solid var(--border-primary); border-right: 1px solid var(--border-primary); border-left: 3px solid #d63638; padding: 10px; background: var(--unassigned-back); }
		.notice.notice-info  { border-top: 1px solid var(--border-primary); border-bottom: 1px solid var(--border-primary); border-right: 1px solid var(--border-primary); border-left: 3px solid #72aee6; padding: 10px; background: var(--list-btn-hover); }

		/* ── Issue list in preview ── */
		.kd-issue-list { margin: .5rem 0; padding: 0; list-style: none; }
		.kd-issue-list li {
			padding: 4px 8px;
			border-left: 3px solid #f0b849;
			background: #fffbee;
			margin-bottom: 4px;
			font-size: 12px;
		}

		/* ── Responsive ── */
		@media (max-width: 700px) {
			.kd-steps { flex-wrap: wrap; }
			.kd-step  { flex: 0 0 50%; border-radius: 0; }
			.kd-importer--frontend input[type="text"],
			.kd-importer--frontend select { max-width: 100%; }
		}
		';
	}
}
