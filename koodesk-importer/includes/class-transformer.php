<?php
/**
 * Koodesk Importer – Transformer
 * Converts a wide-form CSV row into normalized payloads for DB insertion.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Transformer {

	/** @var Koodesk_Serializer */
	private Koodesk_Serializer $serializer;

	public function __construct( Koodesk_Serializer $serializer ) {
		$this->serializer = $serializer;
	}

	/**
	 * Retrieve a value from a CSV data row by column name.
	 * Tries exact match first, then falls back to normalised comparison
	 * (collapsing multiple spaces, trimming) so that column names stored
	 * with single spaces still match CSV headers that have double spaces.
	 */
	private function row_get( array $row, string $col ): string {
		if ( $col === '' ) return '';
		// Exact match
		if ( array_key_exists( $col, $row ) ) return trim( (string) $row[ $col ] );
		// Normalised fallback: collapse multiple spaces and compare case-insensitively
		$col_norm = strtolower( preg_replace( '/\s+/', ' ', trim( $col ) ) );
		foreach ( $row as $k => $v ) {
			$k_norm = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $k ) ) );
			if ( $k_norm === $col_norm ) return trim( (string) $v );
		}
		return '';
	}

	/**
	 * Fill in a `max_score` on each assessment definition from the shared
	 * `assessment_max_scores` label lookup (built from the Assessment
	 * Labels table / assessment template on the mapping step), unless the
	 * assessment definition already carries its own max_score.
	 *
	 * @param array $assessments  [ ['label'=>.., 'col'=>.., 'max_score'=>optional], ... ]
	 * @param array $mapping      Full column mapping, may contain 'assessment_max_scores'.
	 * @return array  Same shape, with max_score filled in where available.
	 */
	private function apply_assessment_max_scores( array $assessments, array $mapping ): array {
		$lookup = $mapping['assessment_max_scores'] ?? [];
		if ( empty( $lookup ) ) return $assessments;

		foreach ( $assessments as &$a ) {
			if ( isset( $a['max_score'] ) && $a['max_score'] !== null && $a['max_score'] !== '' ) continue;
			$label = $a['label'] ?? '';
			if ( $label !== '' && isset( $lookup[ $label ] ) ) {
				$a['max_score'] = (float) $lookup[ $label ];
			}
		}
		unset( $a );

		return $assessments;
	}

	/**
	 * Transform one wide CSV row into normalized payloads.
	 *
	 * @param string $total_resolution  'csv' | 'calculated' — FIX #3
	 */
	public function transform_row(
		array  $csv_row,
		array  $mapping,
		int    $student_id,
		string $student_name,
		string $student_reg,
		int    $class_id,
		string $class_name,
		array  $grading_scale,
		int    $import_ts,
		string $total_resolution = 'csv'
	): array {

		$warnings        = [];
		$academic_records = [];

		$session  = $this->resolve_session( $csv_row, $mapping );
		$term_int = $this->resolve_term( $csv_row, $mapping, $warnings );

		$subject_totals = 0.0;
		$subject_max    = 0.0;
		$subject_count  = 0;

		foreach ( $mapping['subjects'] ?? [] as $subj_map ) {
			$subject_id   = (int) ( $subj_map['subject_id'] ?? 0 );
			$subject_name = (string) ( $subj_map['subject_name'] ?? '' );

			if ( $subject_id === 0 ) {
				$warnings[] = "Subject mapping missing subject_id for \"{$subject_name}\" — skipped.";
				continue;
			}

			$assessment_items = $this->serializer->build_assessment_items(
				$this->apply_assessment_max_scores( $subj_map['assessments'] ?? [], $mapping ),
				$csv_row,
				$import_ts
			);

			$has_any_score = false;
			foreach ( $assessment_items as $ai ) {
				if ( $ai['score'] !== '' ) { $has_any_score = true; break; }
			}
			if ( ! $has_any_score ) continue;

			// Validate scores
			foreach ( $assessment_items as $ai ) {
				if ( $ai['score'] !== '' && is_numeric( $ai['score'] ) ) {
					$score_val = (float) $ai['score'];
					$max_val   = (float) ( $ai['max_score'] ?? 0 );
					if ( $max_val > 0 && $score_val > $max_val ) {
						$warnings[] = "{$subject_name} \"{$ai['label']}\": score {$score_val} exceeds max {$max_val}.";
					}
				}
			}

			$calculated_total = $this->serializer->calculate_total( $assessment_items );
			$calculated_max   = $this->serializer->calculate_max( $assessment_items );

			// FIX #3: Always import assessment fields; resolve total based on user choice
			// Also FIX: if grade_col has a value, import it as-is (don't recalculate)
			$total_score = $calculated_total;
			if ( ! empty( $subj_map['total_col'] ) ) {
				$csv_total = $this->row_get( $csv_row, $subj_map['total_col'] );
				if ( $csv_total !== '' && is_numeric( $csv_total ) ) {
					$csv_total_f = (float) $csv_total;
					if ( abs( $csv_total_f - $calculated_total ) > 1.0 && $calculated_total > 0 ) {
						// FIX #3: Use resolution choice instead of always using CSV
						if ( $total_resolution === 'calculated' ) {
							$warnings[] = "{$subject_name}: using calculated total {$calculated_total} (CSV was {$csv_total_f}).";
							$total_score = $calculated_total;
						} else {
							$warnings[] = "{$subject_name}: CSV total {$csv_total_f} differs from calculated {$calculated_total} — using CSV value.";
							$total_score = $csv_total_f;
						}
					} else {
						$total_score = $csv_total_f;
					}
				}
			}

			// FIX: if grade_col has a value, use it directly — don't resolve from system
			$grade  = '';
			$remark = '';

			if ( ! empty( $subj_map['grade_col'] ) ) {
				$csv_grade = $this->row_get( $csv_row, $subj_map['grade_col'] );
				if ( $csv_grade !== '' ) $grade = $csv_grade;
			}

			// Only calculate from scale if no CSV grade provided
			if ( $grade === '' && ! empty( $grading_scale ) && $calculated_max > 0 ) {
				$percentage = round( ( $total_score / $calculated_max ) * 100, 2 );
				foreach ( $grading_scale as $band ) {
					if ( $percentage >= $band['min'] && $percentage <= $band['max'] ) {
						$grade  = $band['label'];
						$remark = $band['remark'];
						break;
					}
				}
			}

			// FIX: if remark_col has a value, use it directly
			if ( ! empty( $subj_map['remark_col'] ) ) {
				$csv_remark = $this->row_get( $csv_row, $subj_map['remark_col'] );
				if ( $csv_remark !== '' ) $remark = $csv_remark;
			}

			$subject_position = 0;
			if ( ! empty( $subj_map['position_col'] ) ) {
				$csv_pos = $this->row_get( $csv_row, $subj_map['position_col'] );
				if ( $csv_pos !== '' && is_numeric( $csv_pos ) ) {
					$subject_position = (int) $csv_pos;
				}
			}

			$academic_records[] = [
				'student_id'       => $student_id,
				'student_name'     => $student_name,
				'subject_id'       => $subject_id,
				'subject_name'     => $subject_name,
				'class_id'         => $class_id,
				'class_name'       => $class_name,
				'session'          => $session,
				'term'             => $term_int,
				'assessments'      => $this->serializer->serialize_assessments( $assessment_items, $import_ts ),
				'total_score'      => $total_score,
				'grade'            => $grade,
				'remark'           => $remark,
				'subject_position' => $subject_position,
				'cct_status'       => 'publish',
			];

			$subject_totals += $total_score;
			$subject_max    += $calculated_max;
			$subject_count++;
		}

		$term_summary = $this->build_term_summary(
			$csv_row,
			$mapping,
			$student_id,
			$student_name,
			$student_reg,
			$class_id,
			$class_name,
			$session,
			$term_int,
			$subject_count,
			$subject_totals,
			$subject_max,
			$grading_scale
		);

		return [
			'academic_records' => $academic_records,
			'term_summary'     => $term_summary,
			'warnings'         => $warnings,
		];
	}

	// -------------------------------------------------------------------------
	// Term summary builder
	// -------------------------------------------------------------------------

	private function build_term_summary(
		array  $csv_row,
		array  $mapping,
		int    $student_id,
		string $student_name,
		string $student_reg,
		int    $class_id,
		string $class_name,
		string $session,
		int    $term_int,
		int    $subject_count,
		float  $subject_totals,
		float  $subject_max,
		array  $grading_scale
	): array {

		$summary_map = $mapping['summary_fields'] ?? [];

		$row_get_fn = fn( string $col ) => $this->row_get( $csv_row, $col );
		$get_summary = function( string $db_field ) use ( $csv_row, $summary_map, $row_get_fn ): string {
			$col = array_search( $db_field, $summary_map, true );
			if ( $col === false ) return '';
			return $row_get_fn( (string) $col );
		};

		$term_total_csv = $get_summary( 'term_total_score' );
		$term_total = ( $term_total_csv !== '' && is_numeric( $term_total_csv ) )
			? (float) $term_total_csv
			: $subject_totals;

		$term_avg_csv = $get_summary( 'term_average' );
		$term_average = ( $term_avg_csv !== '' && is_numeric( $term_avg_csv ) )
			? (float) $term_avg_csv
			: ( $subject_count > 0 ? round( $subject_totals / $subject_count, 2 ) : 0.0 );

		$pos_csv = $get_summary( 'position_in_class' );
		$position_in_class = ( $pos_csv !== '' && is_numeric( $pos_csv ) ) ? (int) $pos_csv : 0;

		// FIX #15: teacher_remark and principal_remark are separate fields
		$teacher_remark   = $get_summary( 'teacher_remark' );
		$principal_remark = $get_summary( 'principal_remark' );

		// class_teacher — historical snapshot of who was in charge of this
		// class for this term/session, distinct from teacher_remark (their
		// written comment). Stored as free text since the person may not
		// still hold that role, or even still be on staff, by the time the
		// record is viewed later.
		$class_teacher = $get_summary( 'class_teacher' );

		$attendance_csv = $get_summary( 'attendance_present' );
		if ( $attendance_csv === '' ) {
			$attendance_csv = $get_summary( 'attendance_total_present' );
		}
		$attendance_count = ( $attendance_csv !== '' && is_numeric( $attendance_csv ) )
			? (int) $attendance_csv
			: 0;

		$total_marks_csv = $get_summary( 'total_marks' );
		if ( $total_marks_csv !== '' && is_numeric( $total_marks_csv ) ) {
			// Use the CSV value if explicitly mapped
			$total_marks = (float) $total_marks_csv;
		} elseif ( $subject_max > 0 ) {
			// Use calculated max from assessment templates
			$total_marks = $subject_max;
		} else {
			// Fall back: subject_count × 100 (each subject is out of 100)
			$total_marks = (float) ( $subject_count * 100 );
		}

		$term_grade = '';
		if ( ! empty( $grading_scale ) && $subject_max > 0 ) {
			$pct = round( ( $subject_totals / $subject_max ) * 100, 2 );
			foreach ( $grading_scale as $band ) {
				if ( $pct >= $band['min'] && $pct <= $band['max'] ) {
					$term_grade = $band['label'];
					break;
				}
			}
		}

		return [
			'student_id'              => $student_id,
			'student_name'            => $student_name,
			'student_reg_number'      => $student_reg,
			'class_id'                => $class_id,
			'class_name'              => $class_name,
			'session'                 => $session,
			'term'                    => $term_int,
			'subject_count'           => $subject_count,
			'term_total_score'        => $term_total,
			'term_average'            => $term_average,
			'term_grade'              => $term_grade,
			'position_in_class'       => $position_in_class,
			'teacher_remark'          => $teacher_remark,
			'principal_remark'        => $principal_remark,
			'class_teacher'           => $class_teacher,
			'total_marks'             => $total_marks,
			'attendance_total_present'=> $attendance_count,
			'pass_status'             => '',
			'cct_status'              => 'publish',
		];
	}

	private function resolve_session( array $csv_row, array $mapping ): string {
		$col = $mapping['session_col'] ?? '';
		if ( $col === '' ) return '';
		return trim( (string) $this->row_get( $csv_row, $col ) );
	}

	private function resolve_term( array $csv_row, array $mapping, array &$warnings ): int {
		$col = $mapping['term_col'] ?? '';
		if ( $col === '' ) return 0;

		$raw = trim( (string) $this->row_get( $csv_row, $col ) );
		if ( $raw === '' ) {
			$warnings[] = 'Term column is empty for this row.';
			return 0;
		}

		if ( is_numeric( $raw ) && in_array( (int) $raw, [ 1, 2, 3 ], true ) ) {
			return (int) $raw;
		}

		$term_map = $mapping['term_value_map'] ?? [];
		if ( isset( $term_map[ $raw ] ) ) {
			return (int) $term_map[ $raw ];
		}

		foreach ( $term_map as $label => $int_val ) {
			if ( strtolower( $label ) === strtolower( $raw ) ) {
				return (int) $int_val;
			}
		}

		$warnings[] = "Could not resolve term value \"{$raw}\" to 1/2/3. Check the term_value_map in your profile.";
		return 0;
	}
}
