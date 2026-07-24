<?php
/**
 * Plugin Name:  Koodesk Data Importer
 * Plugin URI:   https://koodesk.com
 * Description:  Import students, academic records, and term summaries into
 *               Koodesk from school CSV result sheets. Supports saved mapping
 *               profiles so each school only maps columns once.
 * Version:      1.5.0
 * Author:       Koodesk
 * License:      GPLv2 or later
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin constants
define( 'KOODESK_IMPORTER_VERSION', '1.0.0' );
define( 'KOODESK_IMPORTER_FILE',    __FILE__ );
define( 'KOODESK_IMPORTER_DIR',     plugin_dir_path( __FILE__ ) );
define( 'KOODESK_IMPORTER_URL',     plugin_dir_url( __FILE__ ) );
define( 'KOODESK_IMPORTER_VIEWS',   KOODESK_IMPORTER_DIR . 'admin/views/' );

/**
 * Simple class autoloader — maps class names to files.
 * All Koodesk_* classes in includes/ and admin/ are loaded on demand.
 */
spl_autoload_register( function ( string $class_name ): void {
	if ( strpos( $class_name, 'Koodesk_' ) !== 0 ) return;

	// Convert Koodesk_File_Reader → class-file-reader.php
	$file_part = strtolower( str_replace( [ 'Koodesk_', '_' ], [ '', '-' ], $class_name ) );
	$filename  = 'class-' . $file_part . '.php';

	$locations = [
		KOODESK_IMPORTER_DIR . 'includes/' . $filename,
		KOODESK_IMPORTER_DIR . 'admin/'    . $filename,
	];

	foreach ( $locations as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
} );

/**
 * Bootstrap: instantiate all components and wire them together.
 * Runs on plugins_loaded so JetEngine is available.
 */
add_action( 'plugins_loaded', function (): void {

	// Bail if JetEngine isn't active
	if ( ! function_exists( 'jet_engine' ) ) {
		add_action( 'admin_notices', function (): void {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Koodesk Importer</strong> requires JetEngine to be installed and activated.';
			echo '</p></div>';
		} );
		return;
	}

	// ── Instantiate components in dependency order ──────────────────

	$serializer      = new Koodesk_Serializer();
	$file_reader     = new Koodesk_File_Reader();
	$profile_manager = new Koodesk_Profile_Manager();
	$classifier      = new Koodesk_Column_Classifier();
	$matcher         = new Koodesk_Student_Matcher();
	$transformer     = new Koodesk_Transformer( $serializer );
	$family_resolver = new Koodesk_Family_Resolver();
	$importer        = new Koodesk_Importer( $transformer, $matcher, $family_resolver );
	$import_history  = new Koodesk_Import_History();

	// ── Admin UI ─────────────────────────────────────────────────────
	// Instantiated on every request (not just is_admin()) so that the
	// [koodesk_importer] shortcode is registered on the frontend too.
	// Bricks Builder renders shortcodes outside the admin context, so
	// restricting instantiation to is_admin() prevents the shortcode
	// from resolving on any front-end page.
	new Koodesk_Admin_UI(
		$file_reader,
		$profile_manager,
		$classifier,
		$matcher,
		$transformer,
		$importer,
		$import_history
	);

}, 15 ); // priority 15 — after JetEngine's own plugins_loaded (priority 10)
