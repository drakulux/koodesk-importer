<?php
/**
 * Koodesk Importer – StaffImporter
 *
 * Orchestrates the "staff" import type: plan (dedup decisions from the
 * admin) → execute (create/update `staff` CCT records, build the
 * `qualification` repeater, resolve the role, and link the school_section /
 * subjects relations).
 *
 * Deliberately kept separate from Koodesk_Importer (students/academic
 * records) rather than folded in — staff rows have none of the class /
 * subject / term-summary concepts that class already carries, and keeping
 * it standalone avoids threading staff-only branches through that file's
 * already-large run_import().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Staff_Importer {

	/** JetEngine relation ID: school_section (parent) → staff (child) */
	const REL_SECTION_STAFF = 89;
	/** JetEngine relation ID: subjects (parent) → staff (child) */
	const REL_SUBJECT_STAFF = 139;

	/** JetEngine glossary ID backing the employment_type field options. */
	const GLOSSARY_EMPLOYMENT_TYPE = 427;

	/** @var Koodesk_Staff_Matcher */
	private Koodesk_Staff_Matcher $matcher;

	public function __construct( Koodesk_Staff_Matcher $matcher ) {
		$this->matcher = $matcher;
	}

	// -------------------------------------------------------------------------
	// Dry-run preview plan
	// -------------------------------------------------------------------------

	public function prepare_import_plan( array $rows, array $mapping, array $match_decisions ): array {
		$plan = [];

		$email_col = $this->find_mapped_col( $mapping, 'staff_email' );

		foreach ( $rows as $row_num => $row ) {
			$raw_name = $this->resolve_row_full_name( $row, $mapping );
			$email    = $email_col ? trim( (string) ( $row[ $email_col ] ?? '' ) ) : '';
			$lookup   = $this->matcher->normalise_name( $raw_name ) . '||' . $email;

			$decision = $match_decisions[ $lookup ] ?? null;

			if ( ! $decision ) {
				$plan[] = [
					'row_num'  => $row_num + 1,
					'raw_name' => $raw_name,
					'status'   => 'skip',
					'reason'   => 'No match decision recorded for this staff member.',
					'row'      => $row,
				];
				continue;
			}

			if ( $decision['action'] === 'skip' ) {
				$plan[] = [
					'row_num'  => $row_num + 1,
					'raw_name' => $raw_name,
					'status'   => 'skip',
					'reason'   => 'Skipped by admin.',
					'row'      => $row,
				];
				continue;
			}

			$plan[] = [
				'row_num'    => $row_num + 1,
				'raw_name'   => $raw_name,
				'status'     => $decision['action'], // 'use_existing' | 'create_new'
				'staff_id'   => (int) ( $decision['staff_id'] ?? 0 ),
				'row'        => $row,
			];
		}

		return $plan;
	}

	// -------------------------------------------------------------------------
	// Execution
	// -------------------------------------------------------------------------

	/**
	 * @param array $role_resolutions  raw_role_text => [ 'role_id' => int, ... ]
	 *                                 from Koodesk_Staff_Matcher::match_all_roles(),
	 *                                 with any admin corrections merged in.
	 */
	public function run_import( array $plan, array $mapping, array $role_resolutions = [] ): array {
		global $wpdb;

		$result = [
			'staff_created'       => 0,
			'staff_updated'       => 0,
			'rows_skipped'        => 0,
			'errors'              => [],
			'warnings'            => [],
			'new_staff'           => [],
			'inserted_staff_ids'  => [],
		];

		$staff_cct = $this->get_cct( 'staff' );
		if ( ! $staff_cct ) {
			$result['errors'][] = [ 'row' => 0, 'message' => 'The "staff" content type could not be found.' ];
			return $result;
		}
		$staff_table = $staff_cct->db->table();

		// Fields copied verbatim when present and non-empty. 'date_hired' is
		// handled separately (needs timestamp conversion), as is
		// 'employment_type' (needs glossary-option resolution). 'title' is
		// copied as-is (Mr./Mrs./Miss/Ms./Dr.) — trusting the CSV value
		// matches the radio field's options; no normalisation attempted.
		$scalar_fields = [
			'gender', 'date_of_birth', 'phone_number', 'address',
			'monthly_salary', 'staff_email', 'account_number', 'bank_name',
			'title',
		];

		foreach ( $plan as $item ) {
			if ( $item['status'] === 'skip' ) {
				$result['rows_skipped']++;
				continue;
			}

			$row = $item['row'];
			$is_new = ! ( $item['status'] === 'use_existing' && $item['staff_id'] > 0 );

			$first_col = $this->find_mapped_col( $mapping, 'first_name' );
			$last_col  = $this->find_mapped_col( $mapping, 'last_name' );

			if ( $first_col || $last_col ) {
				// First/Last Name columns mapped — use directly.
				$first     = $first_col ? trim( (string) ( $row[ $first_col ] ?? '' ) ) : '';
				$last      = $last_col  ? trim( (string) ( $row[ $last_col ]  ?? '' ) ) : '';
				$full_name = trim( $first . ' ' . $last );
			} else {
				// Full Name only — store it as-is WITHOUT attempting to
				// split it. first_name/last_name are left blank rather
				// than guessed, since nothing downstream reads them
				// independently of full_name, and any split risks being
				// wrong for surname-first conventions or names with a
				// middle name, for no actual benefit.
				$full_col  = $this->find_mapped_col( $mapping, 'full_name' );
				$full_name = $full_col ? trim( (string) ( $row[ $full_col ] ?? '' ) ) : '';
				$first     = '';
				$last      = '';
			}

			// Name is only required (and only ever written) when CREATING a
			// new staff record. Matched EXISTING staff never have their
			// name touched on update — avoids identity drift if, say, a
			// re-imported roster has slightly different name formatting.
			if ( $is_new && $full_name === '' ) {
				$result['errors'][] = [ 'row' => $item['row_num'], 'message' => 'Name is blank — row skipped.' ];
				$result['rows_skipped']++;
				continue;
			}

			$data = [
				'employment_status' => 'active',
				'cct_status'        => 'publish',
				'cct_author_id'     => get_current_user_id(),
			];
			if ( $is_new ) {
				$data['first_name'] = $first;
				$data['last_name']  = $last;
				$data['full_name']  = $full_name;
			}

			foreach ( $scalar_fields as $f ) {
				$col = $this->find_mapped_col( $mapping, $f );
				if ( ! $col ) continue;
				$val = trim( (string) ( $row[ $col ] ?? '' ) );
				if ( $val !== '' ) $data[ $f ] = $val;
			}

			// date_hired — convert to unix timestamp, matching how the CCT
			// already stores it elsewhere in Koodesk.
			$hired_col = $this->find_mapped_col( $mapping, 'date_hired' );
			if ( $hired_col && ! empty( $row[ $hired_col ] ) ) {
				$ts = $this->parse_date_to_timestamp( trim( (string) $row[ $hired_col ] ) );
				if ( $ts ) $data['date_hired'] = $ts;
			}

			// employment_type — resolve free text against the glossary (427)
			// options, falling back to a hardcoded map if the glossary can't
			// be read (see get_employment_type_options() docblock).
			$emp_col = $this->find_mapped_col( $mapping, 'employment_type' );
			if ( $emp_col && ! empty( $row[ $emp_col ] ) ) {
				$data['employment_type'] = $this->resolve_employment_type( trim( (string) $row[ $emp_col ] ) );
			}

			// Role — resolved once per distinct role text on the Match Roles
			// step; unmatched roles are left unassigned with a warning.
			$role_col = $mapping['role_col'] ?? '';
			if ( $role_col && ! empty( $row[ $role_col ] ) ) {
				$raw_role = trim( (string) $row[ $role_col ] );
				$role_id  = (int) ( $role_resolutions[ $raw_role ]['role_id'] ?? 0 );
				if ( $role_id ) {
					$data['staff_role'] = $role_id;
				} else {
					$result['warnings'][] = "Row {$item['row_num']} ({$item['raw_name']}): role \"{$raw_role}\" could not be matched — left unassigned.";
				}
			}

			// Qualification repeater — pipe-delimited free text, one
			// `qualification_list` row per segment.
			$qual_col = $mapping['qualification_col'] ?? '';
			if ( $qual_col && ! empty( $row[ $qual_col ] ) ) {
				$parts = array_values( array_filter( array_map( 'trim', explode( '|', (string) $row[ $qual_col ] ) ) ) );
				if ( ! empty( $parts ) ) {
					$data['qualification'] = array_map( fn( $p ) => [ 'qualification_list' => $p ], $parts );
				}
			}

			try {
				if ( ! $is_new ) {
					$data['_ID'] = $item['staff_id'];
					$staff_cct->get_item_handler()->update_item( $data );
					$staff_id = $item['staff_id'];
					$result['staff_updated']++;
				} else {
					$staff_cct->get_item_handler()->update_item( $data );
					$staff_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT _ID FROM {$staff_table} WHERE full_name = %s ORDER BY _ID DESC LIMIT 1",
						$full_name
					) );
					if ( ! $staff_id ) {
						throw new \Exception( 'Could not retrieve _ID for newly created staff record.' );
					}
					$result['staff_created']++;
					$result['inserted_staff_ids'][] = $staff_id;
					$result['new_staff'][] = [
						'full_name'  => $full_name,
						'staff_id'   => $staff_id,
						'role_name'  => $role_resolutions[ trim( (string) ( $row[ $role_col ] ?? '' ) ) ]['role_name'] ?? '',
						'email'      => $data['staff_email'] ?? '',
					];
				}
			} catch ( \Throwable $e ) {
				$result['errors'][] = [
					'row'     => $item['row_num'],
					'message' => "Failed to import staff \"{$full_name}\": " . $e->getMessage(),
				];
				$result['rows_skipped']++;
				continue;
			}

			// Relations — school_section (89) and subjects (139), both
			// pipe-delimited multi-value columns matched by name against
			// the target CCT.
			$this->link_multi_relation( $row, $mapping, 'section_col', self::REL_SECTION_STAFF, 'school_section', 'title', $staff_id, $result, $item['row_num'] );
			$this->link_multi_relation( $row, $mapping, 'subject_col', self::REL_SUBJECT_STAFF, 'subjects', 'subject_name', $staff_id, $result, $item['row_num'] );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Relation linking (pipe-delimited multi-value → JetEngine relation)
	// -------------------------------------------------------------------------

	private function link_multi_relation(
		array  $row,
		array  $mapping,
		string $mapping_key,
		int    $relation_id,
		string $cct_slug,
		string $name_field,
		int    $staff_id,
		array  &$result,
		int    $row_num
	): void {
		$col = $mapping[ $mapping_key ] ?? '';
		if ( $col === '' || empty( $row[ $col ] ) ) return;

		$parts = array_values( array_filter( array_map( 'trim', explode( '|', (string) $row[ $col ] ) ) ) );
		if ( empty( $parts ) ) return;

		if ( ! function_exists( 'jet_engine' ) ) return;

		try {
			$relation = jet_engine()->relations->get_active_relations( $relation_id );
		} catch ( \Throwable $e ) {
			$relation = null;
		}
		if ( ! $relation ) {
			$result['warnings'][] = "Row {$row_num}: relation #{$relation_id} not found — {$cct_slug} link(s) skipped.";
			return;
		}

		$target_cct = $this->get_cct( $cct_slug );
		if ( ! $target_cct ) return;
		$table = $target_cct->db->table();

		global $wpdb;
		$relation->set_update_context( 'parent' );

		foreach ( $parts as $p ) {
			$target_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT _ID FROM {$table} WHERE LOWER(TRIM({$name_field})) = %s LIMIT 1",
				strtolower( $p )
			) );

			if ( ! $target_id ) {
				$result['warnings'][] = "Row {$row_num}: could not match \"{$p}\" to an existing {$cct_slug} record — skipped.";
				continue;
			}

			try {
				$relation->update( $target_id, $staff_id );
			} catch ( \Throwable $e ) {
				$result['warnings'][] = "Row {$row_num}: failed to link \"{$p}\" ({$cct_slug}) — " . $e->getMessage();
			}
		}
	}

	// -------------------------------------------------------------------------
	// Employment type — glossary (427) resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve free-text employment type from the CSV against the glossary
	 * options, falling back to simple keyword heuristics, then to
	 * 'full_time' if nothing matches.
	 */
	private function resolve_employment_type( string $raw ): string {
		$norm     = strtolower( trim( $raw ) );
		$norm_key = preg_replace( '/[\s\-]+/', '_', $norm );

		$options = $this->get_employment_type_options();
		foreach ( $options as $value => $label ) {
			if ( $norm_key === strtolower( (string) $value ) || $norm === strtolower( (string) $label ) ) {
				return (string) $value;
			}
		}

		if ( str_contains( $norm, 'part' ) )     return 'part_time';
		if ( str_contains( $norm, 'contract' ) ) return 'contract';
		if ( str_contains( $norm, 'full' ) )     return 'full_time';

		// Nothing recognisable — default to full_time rather than leaving
		// the field empty, since employment_status separately still gets
		// 'active' regardless of this value.
		return 'full_time';
	}

	/**
	 * Fetch employment_type options from JetEngine glossary #427.
	 *
	 * IMPORTANT: JetEngine's glossary retrieval API differs across plugin
	 * versions and this repo's docs don't pin down the exact method name in
	 * use here — this tries the common `jet_engine()->glossaries` component
	 * defensively and falls back to a hardcoded map matching the values
	 * already seen elsewhere in Koodesk (full_time / part_time / contract)
	 * if the glossary can't be read. Verify this against your installed
	 * JetEngine version and adjust the method name below if it doesn't
	 * return real options.
	 */
	private function get_employment_type_options(): array {
		$fallback = [
			'full_time' => 'Full Time',
			'part_time' => 'Part Time',
			'contract'  => 'Contract',
		];

		if ( ! function_exists( 'jet_engine' ) ) return $fallback;

		try {
			$glossaries = jet_engine()->glossaries ?? null;
			if ( ! $glossaries ) return $fallback;

			$glossary = null;
			if ( method_exists( $glossaries, 'get_glossary_by_id' ) ) {
				$glossary = $glossaries->get_glossary_by_id( self::GLOSSARY_EMPLOYMENT_TYPE );
			} elseif ( method_exists( $glossaries, 'get_glossary' ) ) {
				$glossary = $glossaries->get_glossary( self::GLOSSARY_EMPLOYMENT_TYPE );
			}

			if ( empty( $glossary ) ) return $fallback;

			$raw_values = is_object( $glossary ) ? ( $glossary->values ?? [] ) : ( $glossary['values'] ?? [] );
			if ( empty( $raw_values ) ) return $fallback;

			$options = [];
			foreach ( $raw_values as $row ) {
				$value = is_array( $row ) ? ( $row['value'] ?? $row[0] ?? '' ) : '';
				$label = is_array( $row ) ? ( $row['label'] ?? $row[1] ?? $value ) : '';
				if ( $value !== '' ) $options[ $value ] = $label;
			}

			return ! empty( $options ) ? $options : $fallback;
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function find_mapped_col( array $mapping, string $db_field ): ?string {
		$fields = $mapping['staff_fields'] ?? [];
		$col = array_search( $db_field, $fields, true );
		return $col !== false ? $col : null;
	}

	/**
	 * Resolve a row's full name, same dual-mode logic as
	 * Koodesk_Importer::resolve_row_full_name() — First/Last Name columns
	 * (either one) take priority when mapped since combining them is
	 * lossless; Full Name is the fallback for schools whose CSV only has a
	 * single combined name column. This is only used for matching/lookup
	 * purposes here — a staff record CREATED from Full-Name-only data has
	 * first_name/last_name left blank rather than guessed apart (see
	 * run_import()).
	 */
	public function resolve_row_full_name( array $row, array $mapping ): string {
		$first_col = $this->find_mapped_col( $mapping, 'first_name' );
		$last_col  = $this->find_mapped_col( $mapping, 'last_name' );

		if ( $first_col || $last_col ) {
			$first = $first_col ? trim( (string) ( $row[ $first_col ] ?? '' ) ) : '';
			$last  = $last_col  ? trim( (string) ( $row[ $last_col ]  ?? '' ) ) : '';
			return trim( $first . ' ' . $last );
		}

		$full_col = $this->find_mapped_col( $mapping, 'full_name' );
		return $full_col ? trim( (string) ( $row[ $full_col ] ?? '' ) ) : '';
	}

	private function parse_date_to_timestamp( string $raw ): int {
		if ( $raw === '' ) return 0;
		if ( is_numeric( $raw ) && (int) $raw > 0 ) return (int) $raw;
		$ts = strtotime( $raw );
		return $ts !== false ? $ts : 0;
	}

	private function get_cct( string $slug ) {
		if ( ! function_exists( 'jet_engine' ) ) return null;
		try {
			$manager = jet_engine()->modules->get_module( 'custom-content-types' );
			if ( ! $manager ) return null;
			return $manager->instance->manager->get_content_types( $slug );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
