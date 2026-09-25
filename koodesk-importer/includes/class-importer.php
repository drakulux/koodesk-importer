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
		string $ext_key_col = '',
		string $import_type = 'academic_records'
	): array {

		$import_ts   = time();
		$plan        = [];
		$class_cache = [];

		foreach ( $rows as $row_num => $row ) {
			$raw_name = $this->resolve_row_full_name( $row, $mapping );
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

	/**
	 * Resolve a row's full name, supporting both name-source modes:
	 *   - First/Last Name columns mapped (either one) → combine them
	 *     directly. Preferred when present since it's lossless.
	 *   - Full Name column mapped → used as-is. This is only used for
	 *     matching/lookup purposes here — the stored first_name/last_name
	 *     for a student CREATED from Full-Name-only data are left blank
	 *     rather than guessed apart (see the create_new block below).
	 * Public because the admin UI needs the same resolution when building
	 * the Match Students step's per-row lookup keys.
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

	// -------------------------------------------------------------------------
	// Zero-score flagging (per-subject and per-row)
	// -------------------------------------------------------------------------

	/**
	 * Scan the plan for rows/subjects where every assessment score is zero —
	 * likely meaning the student didn't sit that subject (or wasn't present
	 * at all that term). Flags default to EXCLUDED; the admin can override
	 * on the preview step to include them anyway.
	 *
	 * Returns:
	 * [
	 *   'row_flags'     => [ row_num => true ],                    // ALL subjects zero for this student
	 *   'subject_flags' => [ row_num => [ subject_name => true ] ], // this subject zero for this student
	 * ]
	 */
	public function detect_zero_score_flags( array $plan, array $mapping ): array {
		$row_flags     = [];
		$subject_flags = [];

		foreach ( $plan as $item ) {
			if ( $item['status'] === 'skip' ) continue;
			$row = $item['row'];

			$subjects_with_any_score    = 0;
			$subjects_considered        = 0;
			$zero_subjects_for_this_row = [];

			foreach ( $mapping['subjects'] ?? [] as $subj ) {
				$assessments = $subj['assessments'] ?? [];
				if ( empty( $assessments ) ) continue;

				$has_any_value   = false;
				$all_zero_or_blank = true;

				foreach ( $assessments as $a ) {
					$v = trim( (string) ( $row[ $a['col'] ] ?? '' ) );
					if ( $v === '' ) continue;
					$has_any_value = true;
					if ( ! is_numeric( $v ) || (float) $v !== 0.0 ) {
						$all_zero_or_blank = false;
					}
				}

				// Only flag when the student actually has entries for this
				// subject (has_any_value) and every one of them is zero —
				// a subject with no columns filled at all is just "not
				// imported for this student", not a zero-score flag.
				if ( $has_any_value && $all_zero_or_blank ) {
					$subjects_considered++;
					$zero_subjects_for_this_row[ $subj['subject_name'] ] = true;
				} elseif ( $has_any_value ) {
					$subjects_considered++;
					$subjects_with_any_score++;
				}
			}

			if ( ! empty( $zero_subjects_for_this_row ) ) {
				$subject_flags[ $item['row_num'] ] = $zero_subjects_for_this_row;
			}

			// Row-level flag: every subject that had data at all came back
			// all-zero (and there was at least one subject to judge from).
			if ( $subjects_considered > 0 && $subjects_with_any_score === 0 ) {
				$row_flags[ $item['row_num'] ] = true;
			}
		}

		return [
			'row_flags'     => $row_flags,
			'subject_flags' => $subject_flags,
		];
	}

	// -------------------------------------------------------------------------
	// Execution
	// -------------------------------------------------------------------------

	/**
	 * @param string $total_resolution     'csv' | 'calculated' — FIX #3
	 * @param array  $zero_score_overrides Row/subject combinations the admin
	 *                                     chose to INCLUDE despite being
	 *                                     flagged all-zero. Shape:
	 *                                     [ 'rows' => [row_num=>true], 'subjects' => [row_num => [subject_name=>true]] ]
	 *                                     Anything flagged and NOT present
	 *                                     here is excluded from import.
	 */
	public function run_import( array $plan, array $mapping, string $total_resolution = 'csv', array $zero_score_overrides = [] ): array {
		global $wpdb;

		$result = [
			'students_created'          => 0,
			'academic_records_inserted' => 0,
			'academic_records_updated'  => 0,
			'summaries_inserted'        => 0,
			'summaries_updated'         => 0,
			'rows_skipped'              => 0,
			'rows_flagged_excluded'     => 0,
			'subjects_flagged_excluded' => 0,
			'errors'                    => [],
			'warnings'                  => [],
			'new_students'              => [],
			// FIX #14: track inserted IDs for reversal
			'inserted_record_ids'       => [],
			'inserted_summary_ids'      => [],
			'inserted_student_ids'      => [],
		];

		$flags               = $this->detect_zero_score_flags( $plan, $mapping );
		$row_flags           = $flags['row_flags'];
		$subject_flags       = $flags['subject_flags'];
		$included_rows       = $zero_score_overrides['rows']     ?? [];
		$included_subjects   = $zero_score_overrides['subjects'] ?? [];

		$cct_m        = $this->get_cct_manager();
		$ar_cct       = $cct_m->get_content_types( 'academic_record' );
		$summary_cct  = $cct_m->get_content_types( 'term_academic_summary' );
		$students_cct = $cct_m->get_content_types( 'students' );
		$enrollment_cct   = $cct_m->get_content_types( 'class_enrollment' );
		$promotions_cct   = $cct_m->get_content_types( 'student_promotions' );

		$ar_table      = $ar_cct->db->table();
		$summary_table = $summary_cct->db->table();

		$position_recalc_keys = [];
		$student_id_map = [];

		// Current global term/session — used as the enrollment fallback when
		// a row doesn't map enrollment_term/enrollment_session columns.
		$current_term_session = $this->get_current_term_session();

		// ── Pass 1: create new students ──────────────────────────────────────
		foreach ( $plan as &$item ) {
			if ( $item['status'] === 'skip' ) {
				$result['rows_skipped']++;
				continue;
			}

			// Row-level zero-score exclusion (academic_records only — doesn't
			// apply to the students import type, which has no assessments).
			if ( isset( $item['import_type'] ) && $item['import_type'] === 'academic_records'
				&& isset( $row_flags[ $item['row_num'] ] ) && empty( $included_rows[ $item['row_num'] ] ) ) {
				$result['rows_flagged_excluded']++;
				$result['warnings'][] = "Row {$item['row_num']} ({$item['raw_name']}): all subjects scored zero — excluded (flagged). Include it from the preview step if this was intentional.";
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

					// Even for an existing student being matched during a
					// "students" import, keep class_enrollment / promotion
					// history in sync with whatever class this row assigns.
					$this->sync_enrollment_and_promotion(
						$item['student_id'],
						$item['row'],
						$mapping,
						$item['class_id'] ?? 0,
						$item['class_name'] ?? '',
						$enrollment_cct,
						$promotions_cct,
						$current_term_session,
						$result
					);
				}
				continue;
			}

			if ( $item['status'] === 'create_new' ) {
				// Name construction is mode-aware: if the row actually
				// provided First/Last Name columns, use them directly.
				// If only a combined Full Name column is available, it's
				// stored as-is WITHOUT attempting to split it — first_name
				// and last_name are left blank rather than guessed, since
				// nothing downstream reads them independently of full_name,
				// and any split (last-word-is-surname or otherwise) risks
				// being wrong for surname-first conventions or names with
				// a middle name, for no actual benefit.
				$first_col = $this->find_mapped_col( $mapping, 'first_name' );
				$last_col  = $this->find_mapped_col( $mapping, 'last_name' );

				if ( $first_col || $last_col ) {
					$first = $first_col ? $this->normalize_student_name( trim( (string) ( $item['row'][ $first_col ] ?? '' ) ) ) : '';
					$last  = $last_col  ? $this->normalize_student_name( trim( (string) ( $item['row'][ $last_col ]  ?? '' ) ) ) : '';
					$name_parts = [
						'first_name' => $first,
						'last_name'  => $last,
						'full_name'  => trim( $first . ' ' . $last ),
					];
				} else {
					$name_parts = [
						'first_name' => '',
						'last_name'  => '',
						'full_name'  => $this->normalize_student_name( $item['raw_name'] ),
					];
				}

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
				// 'address' is the student's OWN address field (distinct from
				// `home_address` on the linked `families` CCT, which is
				// handled separately below via FamilyResolver).
				$scalar_student_fields = [
					'gender', 'date_of_birth', 'nationality',
					'state_of_origin', 'religion', 'genotype', 'phone_number',
					'student_reg_number', 'address',
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

				// Registration date — mapped column if present, else leave
				// unset so the CCT's own default/creation time is used.
				$reg_date_col = $this->find_mapped_col( $mapping, 'registration_date' );
				if ( $reg_date_col && ! empty( $item['row'][ $reg_date_col ] ) ) {
					$reg_ts = $this->parse_date_to_timestamp( trim( (string) $item['row'][ $reg_date_col ] ) );
					if ( $reg_ts ) {
						$new_student['registration_date'] = $reg_ts;
					}
				}

				// Enrollment term/session — mapped columns if present, else
				// fall back to a source that depends on import type:
				//   - academic_records: this row's OWN term/session (the
				//     record being imported), since a historical import
				//     shouldn't enroll a brand-new student under whatever
				//     the system's "current" term/session happens to be.
				//   - students / anything else: the global current
				//     system term/session.
				$enroll_term_col    = $this->find_mapped_col( $mapping, 'enrollment_term' );
				$enroll_session_col = $this->find_mapped_col( $mapping, 'enrollment_session' );

				if ( ( $item['import_type'] ?? '' ) === 'academic_records' ) {
					$row_term_session = $this->resolve_row_term_session_from_academic_record( $item['row'], $mapping );
					$fallback_term    = $row_term_session['term']    !== '' ? $row_term_session['term']    : ( $current_term_session['term']    ?? '' );
					$fallback_session = $row_term_session['session'] !== '' ? $row_term_session['session'] : ( $current_term_session['session'] ?? '' );
				} else {
					$fallback_term    = $current_term_session['term']    ?? '';
					$fallback_session = $current_term_session['session'] ?? '';
				}

				$enrollment_term    = $enroll_term_col    && ! empty( $item['row'][ $enroll_term_col ] )
					? trim( (string) $item['row'][ $enroll_term_col ] )
					: $fallback_term;
				$enrollment_session = $enroll_session_col && ! empty( $item['row'][ $enroll_session_col ] )
					? trim( (string) $item['row'][ $enroll_session_col ] )
					: $fallback_session;

				if ( $enrollment_term !== '' )    $new_student['enrollment_term']    = $enrollment_term;
				if ( $enrollment_session !== '' ) $new_student['enrollment_session'] = $enrollment_session;

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
					// Only attempted when the admin actually mapped a family
					// or guardian field — otherwise every import (including
					// routine academic_records/grade imports) would silently
					// create/link a family from the student's own last name,
					// which isn't what "no family fields mapped" signals.
					if ( $this->has_family_mapping( $mapping ) ) {
						$family_info = $this->resolve_family_for_row(
							$item['row'], $mapping, $name_parts, $new_id
						);
						if ( $family_info['error'] !== '' ) {
							$result['warnings'][] = "Row {$item['row_num']}: family not linked — {$family_info['error']}";
						}
					} else {
						$family_info = [ 'family_id' => 0, 'created' => false, 'linked' => false, 'error' => '' ];
					}

					// ── class_enrollment + student_promotions (movement record) ──
					$this->write_enrollment_and_promotion_for_new_student(
						$new_id,
						$item['row'],
						$mapping,
						$item['class_id'] ?? 0,
						$item['class_name'] ?? '',
						$enrollment_term,
						$enrollment_session,
						$enrollment_cct,
						$promotions_cct,
						$result
					);

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
		$context_set   = false;

		foreach ( $plan as $item ) {
			if ( $item['status'] === 'skip' ) continue;
			if ( isset( $item['import_type'] ) && $item['import_type'] === 'academic_records'
				&& isset( $row_flags[ $item['row_num'] ] ) && empty( $included_rows[ $item['row_num'] ] ) ) {
				continue; // already counted/warned in Pass 1
			}

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

			// Subjects flagged all-zero for THIS row that the admin did not
			// choose to include get stripped from the mapping passed to the
			// transformer, so no academic_record is written for them.
			$row_subject_flags   = $subject_flags[ $item['row_num'] ] ?? [];
			$row_included_subjects = $included_subjects[ $item['row_num'] ] ?? [];
			$effective_mapping   = $mapping;
			if ( ! empty( $row_subject_flags ) ) {
				$excluded_this_row = array_diff_key( $row_subject_flags, $row_included_subjects );
				if ( ! empty( $excluded_this_row ) ) {
					$effective_mapping['subjects'] = array_values( array_filter(
						$mapping['subjects'] ?? [],
						fn( $s ) => ! isset( $excluded_this_row[ $s['subject_name'] ] )
					) );
					$result['subjects_flagged_excluded'] += count( $excluded_this_row );
				}
			}

			try {
				$transformed = $this->transformer->transform_row(
					$item['row'],
					$effective_mapping,
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
	// class_enrollment + student_promotions helpers
	// -------------------------------------------------------------------------

	/**
	 * Read the current system term/session from JetEngine options, the same
	 * source used by the Promotions module ('session-options::current_term'
	 * / 'session-options::current_session').
	 *
	 * @return array{term:string,session:string}
	 */
	private function get_current_term_session(): array {
		$term = '';
		$session = '';
		if ( function_exists( 'jet_engine' ) ) {
			try {
				$term    = (string) jet_engine()->listings->data->get_option( 'session-options::current_term' );
				$session = (string) jet_engine()->listings->data->get_option( 'session-options::current_session' );
			} catch ( \Throwable $e ) {
				// leave blank — caller treats blank term/session as "skip"
			}
		}
		return [ 'term' => $term, 'session' => $session ];
	}

	/**
	 * For an academic_records row, resolve THIS ROW'S OWN term/session (the
	 * record actually being imported) rather than the global "current"
	 * system term/session — used as the enrollment fallback when a new
	 * student has to be created and no explicit enrollment_term/session
	 * column is mapped. Mirrors Koodesk_Transformer::resolve_term() /
	 * resolve_session() but quietly (no warnings — a resolution miss here
	 * just falls through to the global current term/session instead).
	 *
	 * @return array{term:int|string,session:string}
	 */
	private function resolve_row_term_session_from_academic_record( array $row, array $mapping ): array {
		$session_col = $mapping['session_col'] ?? '';
		$session = $session_col !== '' ? trim( (string) ( $row[ $session_col ] ?? '' ) ) : '';

		$term_col = $mapping['term_col'] ?? '';
		$term_raw = $term_col !== '' ? trim( (string) ( $row[ $term_col ] ?? '' ) ) : '';
		$term_map = $mapping['term_value_map'] ?? [];
		$term = '';

		if ( $term_raw !== '' ) {
			if ( is_numeric( $term_raw ) && in_array( (int) $term_raw, [ 1, 2, 3 ], true ) ) {
				$term = (int) $term_raw;
			} elseif ( isset( $term_map[ $term_raw ] ) ) {
				$term = (int) $term_map[ $term_raw ];
			} else {
				foreach ( $term_map as $label => $int_val ) {
					if ( strtolower( $label ) === strtolower( $term_raw ) ) { $term = (int) $int_val; break; }
				}
			}
		}

		return [ 'term' => $term, 'session' => $session ];
	}

	/**
	 * Upsert a class_enrollment record for (student_id, session) — same
	 * uniqueness rule used by the Promotions module.
	 */
	private function upsert_class_enrollment( $enrollment_cct, int $student_id, int $class_id, string $session ): void {
		if ( ! $enrollment_cct || ! $student_id || ! $class_id || $session === '' ) return;

		$enrollment_cct->db->set_format_flag( ARRAY_A );

		try {
			$args = $enrollment_cct->prepare_query_args( [
				[ 'field' => 'student_id', 'operator' => '=', 'value' => $student_id ],
				[ 'field' => 'session',    'operator' => '=', 'value' => $session ],
			] );
			$existing = $enrollment_cct->db->query( $args, 1, 0, [], 'AND' );
		} catch ( \Throwable $e ) {
			$existing = [];
		}

		$existing_id = 0;
		if ( ! empty( $existing ) && is_array( $existing ) ) {
			$first = reset( $existing );
			$existing_id = intval( $first['_ID'] ?? 0 );
		}

		$handler = $enrollment_cct->get_item_handler();
		try {
			if ( $existing_id ) {
				$handler->update_item( [
					'_ID'        => $existing_id,
					'student_id' => $student_id,
					'class_id'   => $class_id,
					'session'    => $session,
				] );
			} else {
				$handler->update_item( [
					'student_id' => $student_id,
					'class_id'   => $class_id,
					'session'    => $session,
				] );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Koodesk_Importer::upsert_class_enrollment failed for student ' . $student_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Write the class_enrollment + student_promotions ("enrolled" movement
	 * record) for a brand-new student created during import.
	 */
	private function write_enrollment_and_promotion_for_new_student(
		int    $student_id,
		array  $row,
		array  $mapping,
		int    $class_id,
		string $class_name,
		string $enrollment_term,
		string $enrollment_session,
		$enrollment_cct,
		$promotions_cct,
		array  &$result
	): void {
		if ( ! $class_id || $enrollment_session === '' ) return;

		$this->upsert_class_enrollment( $enrollment_cct, $student_id, $class_id, $enrollment_session );

		if ( ! $promotions_cct ) return;

		$student_name = trim( (string) ( $row[ $this->find_mapped_col( $mapping, 'full_name' ) ?: '' ] ?? '' ) );

		try {
			$promotions_cct->get_item_handler()->update_item( [
				'term'              => is_numeric( $enrollment_term ) ? (int) $enrollment_term : $enrollment_term,
				'session'           => $enrollment_session,
				'from_class_id'     => 0,
				'to_class_id'       => $class_id,
				'to_class_name'     => $class_name,
				'from_class_name'   => '',
				'student_id'        => $student_id,
				'student_name'      => $student_name,
				'promotion_status'  => 'enrolled',
				'date_processed'    => time(),
				'promotion_batch_id'=> 0,
				'cct_status'        => 'publish',
				'cct_author_id'     => get_current_user_id(),
			] );
		} catch ( \Throwable $e ) {
			$result['warnings'][] = "Could not create student_promotions record for student {$student_id}: " . $e->getMessage();
		}
	}

	/**
	 * Keep class_enrollment / student_promotions in sync for a student that
	 * was matched to an EXISTING record during a "students" import (not
	 * newly created). Only writes anything if the row actually resolves a
	 * class + term/session to enroll into.
	 */
	private function sync_enrollment_and_promotion(
		int    $student_id,
		array  $row,
		array  $mapping,
		int    $class_id,
		string $class_name,
		$enrollment_cct,
		$promotions_cct,
		array  $current_term_session,
		array  &$result
	): void {
		if ( ! $class_id ) return;

		$enroll_term_col    = $this->find_mapped_col( $mapping, 'enrollment_term' );
		$enroll_session_col = $this->find_mapped_col( $mapping, 'enrollment_session' );
		$term    = $enroll_term_col    && ! empty( $row[ $enroll_term_col ] )    ? trim( (string) $row[ $enroll_term_col ] )    : ( $current_term_session['term'] ?? '' );
		$session = $enroll_session_col && ! empty( $row[ $enroll_session_col ] ) ? trim( (string) $row[ $enroll_session_col ] ) : ( $current_term_session['session'] ?? '' );

		if ( $session === '' ) return;

		$this->write_enrollment_and_promotion_for_new_student(
			$student_id, $row, $mapping, $class_id, $class_name, $term, $session,
			$enrollment_cct, $promotions_cct, $result
		);
	}

	/**
	 * Convert a date string (or unix timestamp already) from a CSV cell into
	 * a unix timestamp, matching the format `registration_date` and
	 * `date_hired` are stored in elsewhere in Koodesk.
	 */
	private function parse_date_to_timestamp( string $raw ): int {
		if ( $raw === '' ) return 0;
		if ( is_numeric( $raw ) && (int) $raw > 0 ) return (int) $raw;
		$ts = strtotime( $raw );
		return $ts !== false ? $ts : 0;
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

		// 'address' is the student's own address field — distinct from
		// `home_address` on the linked `families` CCT (handled separately).
		$scalar_fields = [
			'gender', 'date_of_birth', 'nationality',
			'state_of_origin', 'religion', 'genotype', 'phone_number',
			'student_reg_number', 'address',
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
		// so re-importing a roster with guardian columns can backfill family
		// data — but only when the admin actually mapped a family/guardian
		// field. Without that signal, a routine profile-field update
		// shouldn't silently create/link a family from the student's own
		// last name.
		if ( $this->has_family_mapping( $mapping ) ) {
			// Use whatever is ACTUALLY stored for this student — not a
			// guessed split of full_name. If they were created from a
			// Full-Name-only import, last_name may genuinely be blank
			// here, which correctly means "no derivable family name from
			// this student's name" rather than a guess.
			$stored = $wpdb->get_row( $wpdb->prepare(
				"SELECT first_name, last_name, full_name FROM {$students_cct->db->table()} WHERE _ID = %d", $student_id
			), ARRAY_A );
			$name_parts = [
				'first_name' => $stored['first_name'] ?? '',
				'last_name'  => $stored['last_name']  ?? '',
				'full_name'  => $stored['full_name']  ?? '',
			];
			$this->resolve_family_for_row( $row, $mapping, $name_parts, $student_id );
		}
	}

	/**
	 * Whether the admin mapped ANY family- or guardian-related field —
	 * family_name, home_address, or at least one guardian sub-field.
	 * Family/guardian resolution (find-or-create + relation link) is only
	 * attempted when this is true; otherwise every import — including
	 * routine grade/academic-records imports with no family data at all —
	 * would silently create a family record keyed off the student's own
	 * last name, which isn't what "nothing mapped" should mean.
	 */
	private function has_family_mapping( array $mapping ): bool {
		if ( $this->find_mapped_col( $mapping, 'family_name' ) !== null ) return true;
		if ( $this->find_mapped_col( $mapping, 'home_address' ) !== null ) return true;
		return ! empty( $mapping['guardian_fields'] );
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
