<?php
/**
 * Koodesk Importer – Importer (Orchestrator)
 * Coordinates all components to execute the actual import.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Importer {

	const REL_CLASSES_CLASSGROUP  = 26;
	const REL_CLASSGROUP_SCORING  = 462;
	const REL_CLASSGROUP_TEMPLATE = 511;

	/** @var Koodesk_Transformer */
	private Koodesk_Transformer $transformer;
	/** @var Koodesk_Student_Matcher */
	private Koodesk_Student_Matcher $matcher;
	/** @var Koodesk_Family_Resolver */
	private Koodesk_Family_Resolver $family_resolver;

	public function __construct(
		Koodesk_Transformer      $transformer,
		Koodesk_Student_Matcher  $matcher,
		Koodesk_Family_Resolver  $family_resolver
	) {
		$this->transformer     = $transformer;
		$this->matcher         = $matcher;
		$this->family_resolver = $family_resolver;
	}

	// -------------------------------------------------------------------------
	// Dry-run preview
	// -------------------------------------------------------------------------

	public function prepare_import_plan(
		array  $rows,
		array  $mapping,
		array  $match_decisions,
		string $name_col,
		string $ext_key_col = '',
		string $import_type = 'academic_records'
	): array {

		$import_ts   = time();
		$plan        = [];
		$class_cache = [];

		foreach ( $rows as $row_num => $row ) {
			$raw_name = trim( (string) ( $row[ $name_col ] ?? '' ) );
			$ext_key  = $ext_key_col !== '' ? trim( (string) ( $row[ $ext_key_col ] ?? '' ) ) : '';
			$lookup   = $this->matcher->normalise_name( $raw_name ) . '||' . $ext_key;

			$decision = $match_decisions[ $lookup ] ?? null;

			if ( ! $decision ) {
				$plan[] = [
					'row_num'  => $row_num + 1,
					'raw_name' => $raw_name,
					'status'   => 'skip',
					'reason'   => 'No match decision recorded for this student.',
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

			$class_info = $this->resolve_class_from_row( $row, $mapping, $class_cache );

			$plan[] = [
				'row_num'     => $row_num + 1,
				'raw_name'    => $raw_name,
				'status'      => $decision['action'],
				'student_id'  => (int) ( $decision['student_id'] ?? 0 ),
				'class_id'    => $class_info['class_id'],
				'class_name'  => $class_info['class_name'],
				'import_type' => $import_type,
				'row'         => $row,
				'import_ts'   => $import_ts,
			];
		}

		return $plan;
	}

	// -------------------------------------------------------------------------
	// Execution
	// -------------------------------------------------------------------------

	/**
	 * @param string $total_resolution  'csv' | 'calculated' — FIX #3
	 */
	public function run_import( array $plan, array $mapping, string $total_resolution = 'csv' ): array {
		global $wpdb;

		$result = [
			'students_created'          => 0,
			'academic_records_inserted' => 0,
			'academic_records_updated'  => 0,
			'summaries_inserted'        => 0,
			'summaries_updated'         => 0,
			'rows_skipped'              => 0,
			'errors'                    => [],
			'warnings'                  => [],
			'new_students'              => [],
			// FIX #14: track inserted IDs for reversal
			'inserted_record_ids'       => [],
			'inserted_summary_ids'      => [],
			'inserted_student_ids'      => [],
		];

		$cct_m        = $this->get_cct_manager();
		$ar_cct       = $cct_m->get_content_types( 'academic_record' );
		$summary_cct  = $cct_m->get_content_types( 'term_academic_summary' );
		$students_cct = $cct_m->get_content_types( 'students' );

		$ar_table      = $ar_cct->db->table();
		$summary_table = $summary_cct->db->table();

		$position_recalc_keys = [];
		$student_id_map = [];

		// ── Pass 1: create new students ──────────────────────────────────────
		foreach ( $plan as &$item ) {
			if ( $item['status'] === 'skip' ) {
				$result['rows_skipped']++;
				continue;
			}

			if ( $item['status'] === 'use_existing' ) {
				$lookup_key = $this->matcher->normalise_name( $item['raw_name'] ) . '||';
				$student_id_map[ $lookup_key ] = $item['student_id'];

				// For students import type: update existing student's profile fields
				// (e.g. add missing gender, DOB, guardian details)
				if ( isset( $item['import_type'] ) && $item['import_type'] === 'students' && $item['student_id'] > 0 ) {
					$this->update_existing_student_fields(
						$item['student_id'],
						$item['row'],
						$mapping,
						$students_cct
					);
				}
				continue;
			}

			if ( $item['status'] === 'create_new' ) {
				// Normalize student name — title case (preserves hyphens/mixed case)
				$normalized_name = $this->normalize_student_name( $item['raw_name'] );
				$name_parts = $this->matcher->split_name( $normalized_name );

				$new_student = [
					'first_name'        => $name_parts['first_name'],
					'last_name'         => $name_parts['last_name'],
					'full_name'         => $name_parts['full_name'],
					'enrollment_status' => 'enrolled',
					'cct_status'        => 'publish',
					'cct_author_id'     => get_current_user_id(),
					'current_class_id'  => $item['class_id'] ?: 0,
				];

				$student_field_map = $mapping['student_fields'] ?? [];
				// 'address' removed from here — it now lives on the `families` CCT
				// as `home_address`, handled below via FamilyResolver.
				$scalar_student_fields = [
					'gender', 'date_of_birth', 'nationality',
					'state_of_origin', 'religion', 'genotype', 'phone_number',
					'student_reg_number',
				];
				foreach ( $student_field_map as $csv_col => $db_field ) {
					if ( in_array( $db_field, $scalar_student_fields, true ) ) {
						$val = trim( (string) ( $item['row'][ $csv_col ] ?? '' ) );
						if ( $val !== '' ) {
							$new_student[ $db_field ] = $val;
						}
					}
				}

				$ext_key_col = $this->find_mapped_col( $mapping, 'external_student_key' );
				if ( $ext_key_col && ! empty( $item['row'][ $ext_key_col ] ) ) {
					$new_student['external_student_key'] = trim( $item['row'][ $ext_key_col ] );
				}

				try {
					$handler = $students_cct->get_item_handler();
					$handler->update_item( $new_student );

					$new_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT _ID FROM {$students_cct->db->table()}
						 WHERE full_name = %s
						 ORDER BY _ID DESC LIMIT 1",
						$name_parts['full_name']
					) );

					if ( ! $new_id ) {
						throw new \Exception( "Could not retrieve _ID for newly created student." );
					}

					$new_id = (int) $new_id;
					$item['student_id'] = $new_id;

					$reg = $wpdb->get_var( $wpdb->prepare(
						"SELECT student_reg_number FROM {$students_cct->db->table()} WHERE _ID = %d",
						$new_id
					) );

					// ── Family resolution (find-or-create + guardian repeater + relation link) ──
					$family_info = $this->resolve_family_for_row(
						$item['row'], $mapping, $name_parts, $new_id
					);
					if ( $family_info['error'] !== '' ) {
						$result['warnings'][] = "Row {$item['row_num']}: family not linked — {$family_info['error']}";
					}

					$result['students_created']++;
					$result['inserted_student_ids'][] = $new_id;
					$result['new_students'][] = [
						'full_name'   => $name_parts['full_name'],
						'student_id'  => $new_id,
						'reg_number'  => $reg ?: '',
						'gender'      => $new_student['gender'] ?? '',
						'class_name'  => $item['class_name'] ?? '',
						'family_id'   => $family_info['family_id'],
						'family_new'  => $family_info['created'],
					];

					if ( $family_info['created'] ) {
						$result['families_created'] = ( $result['families_created'] ?? 0 ) + 1;
					}
					if ( $family_info['linked'] ) {
						$result['students_linked_to_family'] = ( $result['students_linked_to_family'] ?? 0 ) + 1;
					}

					$lookup_key = $this->matcher->normalise_name( $item['raw_name'] ) . '||';
					$student_id_map[ $lookup_key ] = $new_id;

				} catch ( \Throwable $e ) {
					$result['errors'][] = [
						'row'     => $item['row_num'],
						'message' => "Failed to create student \"{$item['raw_name']}\": " . $e->getMessage(),
					];
					$item['status'] = 'skip';
					$result['rows_skipped']++;
					continue;
				}
			}
		}
		unset( $item );

		// ── Pass 2: insert academic records and term summaries ───────────────
		$grading_cache = [];

		foreach ( $plan as $item ) {
			if ( $item['status'] === 'skip' ) continue;

			$student_id = (int) $item['student_id'];
			if ( $student_id === 0 ) {
				$result['errors'][] = [ 'row' => $item['row_num'], 'message' => "Student ID is 0 for \"{$item['raw_name']}\" — skipped." ];
				continue;
			}

			$student_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT full_name, student_reg_number FROM {$students_cct->db->table()} WHERE _ID = %d",
				$student_id
			), ARRAY_A );

			$student_name = $student_row['full_name'] ?? $item['raw_name'];
			$student_reg  = $student_row['student_reg_number'] ?? '';

			// Capture session/term/class from the first valid row for import history
			if ( ! $context_set ) {
				$sess_col = $mapping['session_col'] ?? '';
				$term_col = $mapping['term_col']    ?? '';
				$term_raw = $term_col ? trim( (string)( $item['row'][ $term_col ] ?? '' ) ) : '';
				$term_map = $mapping['term_value_map'] ?? [];
				$result['context_session']    = $sess_col ? trim( (string)( $item['row'][ $sess_col ] ?? '' ) ) : '';
				$result['context_term']       = (int)( $term_map[ $term_raw ] ?? $term_raw );
				$result['context_class_name'] = $item['class_name'] ?? '';
				$context_set = true;
			}

			$class_id = (int) $item['class_id'];
			if ( ! isset( $grading_cache[ $class_id ] ) ) {
				$grading_cache[ $class_id ] = $class_id > 0
					? $this->get_grading_scale( $class_id )
					: [];
			}
			$grading_scale = $grading_cache[ $class_id ];

			try {
				$transformed = $this->transformer->transform_row(
					$item['row'],
					$mapping,
					$student_id,
					$student_name,
					$student_reg,
					$class_id,
					$item['class_name'],
					$grading_scale,
					$item['import_ts'],
					$total_resolution
				);
			} catch ( \Throwable $e ) {
				$result['errors'][] = [ 'row' => $item['row_num'], 'message' => "Transform error: " . $e->getMessage() ];
				continue;
			}

			foreach ( $transformed['warnings'] as $w ) {
				$result['warnings'][] = "Row {$item['row_num']}: {$w}";
			}

			// Insert / update academic records
			foreach ( $transformed['academic_records'] as $rec ) {
				$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT _ID FROM {$ar_table}
					 WHERE student_id = %d AND subject_id = %d
					   AND class_id = %d AND session = %s AND term = %d",
					$rec['student_id'], $rec['subject_id'],
					$rec['class_id'],   $rec['session'],   $rec['term']
				) );

				if ( $existing_id ) {
					$rec['_ID'] = $existing_id;
					$ar_cct->get_item_handler()->update_item( $rec );
					$result['academic_records_updated']++;
				} else {
					$ar_cct->get_item_handler()->update_item( $rec );
					// FIX #14: capture inserted IDs
					$new_rec_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT _ID FROM {$ar_table}
						 WHERE student_id = %d AND subject_id = %d AND session = %s AND term = %d
						 ORDER BY _ID DESC LIMIT 1",
						$rec['student_id'], $rec['subject_id'], $rec['session'], $rec['term']
					) );
					if ( $new_rec_id ) $result['inserted_record_ids'][] = (int) $new_rec_id;
					$result['academic_records_inserted']++;
				}

				$recalc_key = "{$rec['class_id']}|{$rec['subject_id']}|{$rec['session']}|{$rec['term']}";
				$position_recalc_keys[ $recalc_key ] = [
					'class_id'   => $rec['class_id'],
					'subject_id' => $rec['subject_id'],
					'session'    => $rec['session'],
					'term'       => $rec['term'],
				];
			}

			// Insert / update term summary
			$summ = $transformed['term_summary'];
			if ( $summ['subject_count'] > 0 ) {
				$existing_summ_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT _ID FROM {$summary_table}
					 WHERE student_id = %d AND class_id = %d AND session = %s AND term = %d",
					$summ['student_id'], $summ['class_id'], $summ['session'], $summ['term']
				) );

				if ( $existing_summ_id ) {
					$summ['_ID'] = $existing_summ_id;
					$summary_cct->get_item_handler()->update_item( $summ );
					$result['summaries_updated']++;
				} else {
					$summary_cct->get_item_handler()->update_item( $summ );
					$new_summ_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT _ID FROM {$summary_table}
						 WHERE student_id = %d AND session = %s AND term = %d
						 ORDER BY _ID DESC LIMIT 1",
						$summ['student_id'], $summ['session'], $summ['term']
					) );
					if ( $new_summ_id ) $result['inserted_summary_ids'][] = (int) $new_summ_id;
					$result['summaries_inserted']++;
				}
			}
		}

		// ── Pass 3: recalculate subject positions ────────────────────────────
		foreach ( $position_recalc_keys as $combo ) {
			$position_was_imported = $this->profile_has_position_col( $mapping );
			if ( ! $position_was_imported ) {
				$this->recalculate_subject_positions(
					$ar_cct, $ar_table,
					$combo['class_id'], $combo['subject_id'],
					$combo['session'],  $combo['term']
				);
			}
		}

		// ── Pass 4: recalculate overall class positions ──────────────────────
		$summary_recalc_done = [];
		foreach ( $position_recalc_keys as $combo ) {
			$recalc_key = "{$combo['class_id']}|{$combo['session']}|{$combo['term']}";
			if ( isset( $summary_recalc_done[ $recalc_key ] ) ) continue;
			$summary_recalc_done[ $recalc_key ] = true;

			$position_was_imported = $this->profile_has_summary_position( $mapping );
			if ( ! $position_was_imported ) {
				$this->recalculate_class_positions(
					$summary_table,
					$combo['class_id'], $combo['session'], $combo['term']
				);
			}
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// FIX #14: Reverse import
	// -------------------------------------------------------------------------

	public function reverse_import( array $log ): array {
		global $wpdb;
		$cct_m       = $this->get_cct_manager();
		$ar_cct      = $cct_m->get_content_types( 'academic_record' );
		$summary_cct = $cct_m->get_content_types( 'term_academic_summary' );
		$students_cct = $cct_m->get_content_types( 'students' );

		$deleted = [
			'records_deleted'  => 0,
			'summaries_deleted'=> 0,
			'students_deleted' => 0,
		];

		// Delete inserted academic records
		foreach ( (array) ( $log['record_ids'] ?? [] ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$wpdb->delete( $ar_cct->db->table(), [ '_ID' => $id ], [ '%d' ] );
				$deleted['records_deleted']++;
			}
		}

		// Delete inserted summaries
		foreach ( (array) ( $log['summary_ids'] ?? [] ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$wpdb->delete( $summary_cct->db->table(), [ '_ID' => $id ], [ '%d' ] );
				$deleted['summaries_deleted']++;
			}
		}

		// Delete newly created students (only students created in this import)
		foreach ( (array) ( $log['new_student_ids'] ?? [] ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$wpdb->delete( $students_cct->db->table(), [ '_ID' => $id ], [ '%d' ] );
				$deleted['students_deleted']++;
			}
		}

		return $deleted;
	}

	// -------------------------------------------------------------------------
	// FIX #13: Normalize student name to title case
	// -------------------------------------------------------------------------

	private function normalize_student_name( string $name ): string {
		$name = trim( $name );
		if ( $name === '' ) return $name;

		// Only normalize if name appears to be all-caps or all-lowercase.
		// If it already has mixed case with hyphens preserved (e.g. "Al-Amin"),
		// leave it alone so we don't corrupt correctly-entered names.
		$no_punct = preg_replace( '/[^a-zA-Z]/', '', $name );
		$is_all_caps  = ( $no_punct === strtoupper( $no_punct ) );
		$is_all_lower = ( $no_punct === strtolower( $no_punct ) );

		if ( ! $is_all_caps && ! $is_all_lower ) {
			// Already mixed case — leave hyphens and casing as-is
			return $name;
		}

		// Process word by word (spaces separate words)
		$words = explode( ' ', $name );
		$normalized = [];
		foreach ( $words as $word ) {
			if ( $word === '' ) continue;

			// Preserve hyphenated compound names: "AL-AMIN" → "Al-Amin"
			// But do NOT split on hyphens that are standalone dashes or decorative
			if ( strpos( $word, '-' ) !== false ) {
				$parts = explode( '-', $word );
				$converted = [];
				foreach ( $parts as $part ) {
					if ( $part === '' ) {
						$converted[] = ''; // preserve leading/trailing hyphens
					} else {
						$converted[] = ucfirst( strtolower( $part ) );
					}
				}
				$word = implode( '-', $converted );
			} else {
				$word = ucfirst( strtolower( $word ) );
			}
			$normalized[] = $word;
		}
		return implode( ' ', $normalized );
	}

	// -------------------------------------------------------------------------
	// Position recalculation
	// -------------------------------------------------------------------------

	private function recalculate_subject_positions( $ar_cct, string $ar_table, int $class_id, int $subject_id, string $session, int $term ): void {
		global $wpdb;
		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT _ID, total_score FROM {$ar_table}
			 WHERE class_id = %d AND subject_id = %d AND session = %s AND term = %d",
			$class_id, $subject_id, $session, $term
		), ARRAY_A );

		if ( empty( $items ) ) return;

		usort( $items, fn( $a, $b ) => floatval( $b['total_score'] ) <=> floatval( $a['total_score'] ) );

		$rank = 0; $prev = null; $extra = 0;
		foreach ( $items as $item ) {
			$rank++;
			$curr = (float) $item['total_score'];
			if ( $prev !== null && round( $curr, 2 ) === round( $prev, 2 ) ) {
				$display = $rank - ( $extra + 1 );
				$extra++;
			} else {
				$display = $rank;
				$extra   = 0;
			}
			$prev = $curr;
			$wpdb->update( $ar_table, [ 'subject_position' => $display ], [ '_ID' => $item['_ID'] ], [ '%d' ], [ '%d' ] );
		}
	}

	private function recalculate_class_positions( string $summary_table, int $class_id, string $session, int $term ): void {
		global $wpdb;
		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT _ID, term_total_score FROM {$summary_table}
			 WHERE class_id = %d AND session = %s AND term = %d",
			$class_id, $session, $term
		), ARRAY_A );

		if ( empty( $items ) ) return;

		usort( $items, fn( $a, $b ) => floatval( $b['term_total_score'] ) <=> floatval( $a['term_total_score'] ) );

		$rank = 0; $prev = null; $extra = 0;
		foreach ( $items as $item ) {
			$rank++;
			$curr = (float) $item['term_total_score'];
			if ( $prev !== null && round( $curr, 2 ) === round( $prev, 2 ) ) {
				$display = $rank - ( $extra + 1 );
				$extra++;
			} else {
				$display = $rank;
				$extra   = 0;
			}
			$prev = $curr;
			$wpdb->update( $summary_table, [ 'position_in_class' => $display ], [ '_ID' => $item['_ID'] ], [ '%d' ], [ '%d' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_grading_scale( int $class_id ): array {
		if ( ! function_exists( 'ar_get_grading_scale_for_class' ) ) return [];
		$cct_m       = $this->get_cct_manager();
		$scoring_cct = $cct_m->get_content_types( 'academic_scoring' );
		$info = ar_get_grading_scale_for_class(
			$class_id,
			self::REL_CLASSES_CLASSGROUP,
			self::REL_CLASSGROUP_SCORING,
			$scoring_cct
		);
		return $info['scale'] ?? [];
	}

	private function get_cct_manager() {
		return jet_engine()->modules->get_module( 'custom-content-types' )->instance->manager;
	}

	private function resolve_class_from_row( array $row, array $mapping, array &$class_cache ): array {
		global $wpdb;

		$class_col = $this->find_mapped_col( $mapping, 'current_class_name' );
		$class_raw = $class_col ? trim( (string) ( $row[ $class_col ] ?? '' ) ) : '';

		if ( $class_raw === '' ) return [ 'class_id' => 0, 'class_name' => '' ];
		if ( isset( $class_cache[ $class_raw ] ) ) return $class_cache[ $class_raw ];

		$classes_table = $wpdb->prefix . 'jet_cct_classes';
		$norm = strtolower( trim( $class_raw ) );

		$row_data = $wpdb->get_row( $wpdb->prepare(
			"SELECT _ID, class_name FROM {$classes_table}
			 WHERE LOWER(TRIM(class_name)) = %s LIMIT 1",
			$norm
		), ARRAY_A );

		if ( ! $row_data ) {
			$row_data = $wpdb->get_row( $wpdb->prepare(
				"SELECT _ID, class_name FROM {$classes_table}
				 WHERE LOWER(TRIM(class_name)) LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $norm ) . '%'
			), ARRAY_A );
		}

		$info = $row_data
			? [ 'class_id' => (int) $row_data['_ID'], 'class_name' => $row_data['class_name'] ]
			: [ 'class_id' => 0, 'class_name' => $class_raw ];

		$class_cache[ $class_raw ] = $info;
		return $info;
	}

	private function find_mapped_col( array $mapping, string $db_field ): ?string {
		$student_fields = $mapping['student_fields'] ?? [];
		$col = array_search( $db_field, $student_fields, true );
		return $col !== false ? $col : null;
	}

	private function profile_has_position_col( array $mapping ): bool {
		foreach ( $mapping['subjects'] ?? [] as $s ) {
			if ( ! empty( $s['position_col'] ) ) return true;
		}
		return false;
	}

	private function profile_has_summary_position( array $mapping ): bool {
		return in_array( 'position_in_class', $mapping['summary_fields'] ?? [], true );
	}
	// -------------------------------------------------------------------------
	// Update existing student fields (for students import type)
	// -------------------------------------------------------------------------

	private function update_existing_student_fields( int $student_id, array $row, array $mapping, $students_cct ): void {
		global $wpdb;

		// 'address' removed — now lives on the `families` CCT as `home_address`.
		$scalar_fields = [
			'gender', 'date_of_birth', 'nationality',
			'state_of_origin', 'religion', 'genotype', 'phone_number',
			'student_reg_number',
		];

		$updates = [ '_ID' => $student_id ];
		$student_field_map = $mapping['student_fields'] ?? [];

		foreach ( $student_field_map as $csv_col => $db_field ) {
			if ( ! in_array( $db_field, $scalar_fields, true ) ) continue;
			$val = trim( (string) ( $row[ $csv_col ] ?? '' ) );
			if ( $val === '' ) continue; // don't overwrite with blanks
			$updates[ $db_field ] = $val;
		}

		if ( count( $updates ) > 1 ) { // more than just _ID
			$students_cct->get_item_handler()->update_item( $updates );
		}

		// Resolve/link family + guardians for this existing student too,
		// so re-importing a roster with guardian columns can backfill family data.
		$full_name = $wpdb->get_var( $wpdb->prepare(
			"SELECT full_name FROM {$students_cct->db->table()} WHERE _ID = %d", $student_id
		) );
		$name_parts = $this->matcher->split_name( (string) $full_name );
		$this->resolve_family_for_row( $row, $mapping, $name_parts, $student_id );
	}

	/**
	 * Build guardian entries + family name from a CSV row and resolve/link
	 * the family record for the given student.
	 *
	 * @return array{family_id:int, created:bool, linked:bool, error:string}
	 */
	private function resolve_family_for_row( array $row, array $mapping, array $name_parts, int $student_id ): array {
		// Family name: explicit "Family Name" column if mapped, else the student's last name.
		$family_name = '';
		$family_col  = $this->find_mapped_col( $mapping, 'family_name' );
		if ( $family_col && ! empty( $row[ $family_col ] ) ) {
			$family_name = trim( (string) $row[ $family_col ] );
		} elseif ( ! empty( $name_parts['last_name'] ) ) {
			$family_name = $name_parts['last_name'];
		}

		// Home address — now stored on the family, not the student.
		$home_address = '';
		$addr_col = $this->find_mapped_col( $mapping, 'home_address' );
		if ( $addr_col && ! empty( $row[ $addr_col ] ) ) {
			$home_address = trim( (string) $row[ $addr_col ] );
		}

		// Build a single guardian entry from mapped guardian_fields, using the
		// `guardians` repeater sub-field keys defined on the families CCT.
		$guardian_fields  = $mapping['guardian_fields'] ?? [];
		$guardian_entries = [];
		if ( ! empty( $guardian_fields ) ) {
			$entry = [];
			foreach ( $guardian_fields as $sub_field => $csv_col ) {
				$val = trim( (string) ( $row[ $csv_col ] ?? '' ) );
				if ( $val !== '' ) $entry[ $sub_field ] = $val;
			}
			if ( ! empty( $entry['guardian_name'] ) ) {
				// Default primary_contact to true for the first/only guardian on this row
				if ( ! isset( $entry['primary_contact'] ) ) $entry['primary_contact'] = 'true';
				$guardian_entries[] = $entry;
			}
		}

		return $this->family_resolver->resolve_and_link(
			$family_name,
			$guardian_entries,
			$home_address,
			$student_id
		);
	}

}
