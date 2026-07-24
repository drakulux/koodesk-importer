<?php
/**
 * Koodesk Importer – ColumnClassifier
 *
 * Detection philosophy:
 * - subject_code is ALWAYS checked first and is authoritative — if a subject
 *   has code "Eng", then "Eng 1st CA" MUST resolve to that subject, regardless
 *   of what other subjects contain the word "english".
 * - When matching a CSV fragment to a subject, ALL words of the fragment must
 *   be accounted for, not just the first. "comp stud" scores higher for
 *   "Computer Studies" than for "Computer Science" because "stud" maps to
 *   "studies". This prevents "Maths" matching "Further Mathematics" over
 *   "Mathematics", and "Comp Stud" matching "Computer Science" over
 *   "Computer Studies".
 * - Single-word abbreviations (PHE, CRS) are handled via SUBJECT_KEYWORD_MAP.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Column_Classifier {

	// ── Student CCT field patterns ────────────────────────────────────────
	private const STUDENT_FIELD_MAP = [
		'name'              => 'full_name',
		'student name'      => 'full_name',
		'full name'         => 'full_name',
		'pupil name'        => 'full_name',
		'student'           => 'full_name',
		'gender'            => 'gender',
		'sex'               => 'gender',
		'dob'               => 'date_of_birth',
		'date of birth'     => 'date_of_birth',
		'birth date'        => 'date_of_birth',
		'address'           => 'home_address',
		'home address'      => 'home_address',
		'residential address' => 'home_address',
		'family name'       => 'family_name',
		'surname'           => 'family_name',
		'family'            => 'family_name',
		'class'             => 'current_class_name',
		'current class'     => 'current_class_name',
		'class name'        => 'current_class_name',
		'admission no'      => 'external_student_key',
		'admission number'  => 'external_student_key',
		'adm no'            => 'external_student_key',
		'student id'        => 'external_student_key',
		'reg no'            => 'student_reg_number',
		'reg number'        => 'student_reg_number',
		'registration no'   => 'student_reg_number',
		'nationality'       => 'nationality',
		'state of origin'   => 'state_of_origin',
		'state'             => 'state_of_origin',
		'religion'          => 'religion',
		'genotype'          => 'genotype',
		'blood group'       => 'blood_group',
		'phone'             => 'phone_number',
		'phone number'      => 'phone_number',
	];

	private const TERM_PATTERNS    = [ 'term', 'term name' ];
	private const SESSION_PATTERNS = [ 'session', 'academic session', 'year' ];

	// ── Term summary field patterns (longest/most-specific first) ─────────
	private const SUMMARY_FIELD_MAP = [
		// Keys MUST be pre-normalised: lowercase, no punctuation, single spaces.
		// They are compared against normalise(header), so apostrophes etc must be absent.
		'total scores'         => 'term_total_score',
		'total score'          => 'term_total_score',
		'grand total'          => 'term_total_score',
		'average score'        => 'term_average',
		'average'              => 'term_average',
		'avg'                  => 'term_average',
		'position in class'    => 'position_in_class',
		'class position'       => 'position_in_class',
		'form teacher comment' => 'teacher_remark',
		'form teacher remark'  => 'teacher_remark',
		'class teacher remark' => 'teacher_remark',
		'class teacher comment'=> 'teacher_remark',
		'teacher remark'       => 'teacher_remark',
		'teacher comment'      => 'teacher_remark',
		// "PRINCIPAL'S COMMENT" normalises to "principal s comment" (apostrophe → space)
		'principal s comment'  => 'principal_remark',
		'principal s remark'   => 'principal_remark',
		'principals comment'   => 'principal_remark',
		'principal remark'     => 'principal_remark',
		'principal comment'    => 'principal_remark',
		'pass status'          => 'pass_status',
		'attendance'           => 'attendance_present',
		'days present'         => 'attendance_present',
		'days attended'        => 'attendance_present',
	];

	// ── Per-subject meta keywords ─────────────────────────────────────────
	private const META_KEYWORDS = [
		'total'    => 'total_col',
		'grade'    => 'grade_col',
		'remark'   => 'remark_col',
		'position' => 'position_col',
		'pos'      => 'position_col',
	];

	// ── Per-subject skip tokens ───────────────────────────────────────────
	private const PER_SUBJECT_SKIP = [
		'hs', 'highest', 'highest score', 'clav', 'class average', 'class avg',
	];

	// ── Assessment patterns ───────────────────────────────────────────────
	private const ASSESSMENT_PATTERNS = [
		'1st ca'        => '1st CA',
		'2nd ca'        => '2nd CA',
		'3rd ca'        => '3rd CA',
		'1st c.a'       => '1st CA',
		'2nd c.a'       => '2nd CA',
		'1st c.a.'      => '1st CA',
		'2nd c.a.'      => '2nd CA',
		'1st ca.'       => '1st CA',
		'2nd ca.'       => '2nd CA',
		'ca1'           => '1st CA',
		'ca2'           => '2nd CA',
		'ca3'           => '3rd CA',
		'ca 1'          => '1st CA',
		'ca 2'          => '2nd CA',
		'ca 3'          => '3rd CA',
		'test 1'        => '1st CA',
		'test 2'        => '2nd CA',
		'1st test'      => '1st CA',
		'2nd test'      => '2nd CA',
		'mid term test' => 'Mid Term Test',
		'mid term'      => 'Mid Term',
		'midterm'       => 'Mid Term',
		'exam'          => 'Exam',
		'examination'   => 'Exam',
		'final exam'    => 'Exam',
		'assignment'    => 'Assignment',
		'project'       => 'Project',
	];

	/**
	 * Map collapsed abbreviation → unique keyword in that subject's name.
	 * Used for subjects like PHE, CRS/IRS where the abbreviation is not a
	 * prefix of any word in the subject name.
	 */
	private const SUBJECT_KEYWORD_MAP = [
		'phe'    => 'physical',
		'crs'    => 'christian',
		'irs'    => 'islamic',
		'crsirs' => 'christian',
		'irscrs' => 'islamic',
		'bst'    => 'business',
		'cca'    => 'creative',
		'cpe'    => 'civic',
	];

	/**
	 * Word-level abbreviation expansions.
	 * Key = abbreviated form found in CSV headers.
	 * Value = full word it expands to.
	 * Used in score_match() to expand fragment words before comparing.
	 */
	private const WORD_ABBREVIATIONS = [
		'maths'  => 'mathematics',
		'math'   => 'mathematics',
		'eng'    => 'english',
		'lit'    => 'literature',
		'sci'    => 'science',
		'bsc'    => 'basic',
		'agric'  => 'agricultural',
		'agr'    => 'agricultural',
		'civ'    => 'civic',
		'comp'   => 'computer',
		'ict'    => 'computer',
		'phe'    => 'physical',
		'bus'    => 'business',
		'comm'   => 'commerce',
		'econ'   => 'economics',
		'stud'   => 'studies',
		'std'    => 'studies',
		'soc'    => 'social',
		'hist'   => 'history',
		'geog'   => 'geography',
		'geo'    => 'geography',
		'govt'   => 'government',
		'gov'    => 'government',
		'crs'    => 'christian',
		'irs'    => 'islamic',
		'hec'    => 'home',
		'fre'    => 'french',
		'ara'    => 'arabic',
		'yor'    => 'yoruba',
		'hau'    => 'hausa',
		'ibo'    => 'igbo',
		'tech'   => 'technology',
		'btech'  => 'technology',
		'bas'    => 'basic',
		'base'   => 'basic',
	];

	private const SKIP_PATTERNS = [
		'hs', 'highest score', 'clav', 'class average',
		'no in class', 'no. in class', 'number in class',
		'next term begins', 'term ending',
		'school fees', 'fees', 'form teacher', 'class teacher',
		'head teacher', 'stamp', 'signature',
		's/n', 'sn', 'serial', 'serial no', 'no', '#',
	];

	// =========================================================================
	// Public API
	// =========================================================================

	public function classify( array $headers, array $known_subjects, string $import_type ): array {
		$code_index = $this->build_code_index( $known_subjects );

		// Pass 1: Strategy A only — identify which subjects have assessment columns.
		// Strategy B (meta/total/grade) is restricted to these subjects so we never
		// create a ghost subject entry that only has a remark/grade but no scores.
		$subjects_with_assessments = [];
		if ( $import_type === 'academic_records' ) {
			$sorted_patterns = self::ASSESSMENT_PATTERNS;
			uksort( $sorted_patterns, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
			foreach ( $headers as $header ) {
				$norm = $this->normalise( $header );
				foreach ( $sorted_patterns as $pattern => $label ) {
					if ( ! str_contains( $norm, $pattern ) ) continue;
					$fragment = trim( str_replace( $pattern, '', $norm ) );
					if ( $fragment === '' ) continue;
					$subject = $this->match_subject_fragment( $fragment, $code_index, $known_subjects );
					if ( $subject ) {
						$subjects_with_assessments[ $subject['_ID'] ] = true;
						break;
					}
				}
			}
		}

		// Pass 2: Full classification with meta restricted to subjects from Pass 1.
		$results = [];
		foreach ( $headers as $header ) {
			$results[] = $this->classify_one(
				$header, $code_index, $known_subjects,
				$import_type, $subjects_with_assessments
			);
		}
		return $results;
	}

	public function classify_one(
		string $header,
		array  $code_index,
		array  $known_subjects,
		string $import_type,
		array  $subjects_with_assessments = [] // subject_id => true
	): array {
		$norm = $this->normalise( $header );

		$base = [
			'header'          => $header,
			'type'            => 'unknown',
			'db_field'        => '',
			'subject_id'      => 0,
			'subject_name'    => '',
			'assessment_hint' => '',
			'meta_role'       => '',
			'confidence'      => 'low',
		];

		if ( $this->matches_any( $norm, self::SKIP_PATTERNS ) ) {
			return array_merge( $base, [ 'type' => 'skip', 'confidence' => 'high' ] );
		}

		if ( $import_type === 'academic_records' ) {
			if ( $this->matches_any( $norm, self::TERM_PATTERNS ) ) {
				return array_merge( $base, [ 'type' => 'term_col',    'db_field' => 'term',    'confidence' => 'high' ] );
			}
			if ( $this->matches_any( $norm, self::SESSION_PATTERNS ) ) {
				return array_merge( $base, [ 'type' => 'session_col', 'db_field' => 'session', 'confidence' => 'high' ] );
			}
		}

		if ( isset( self::STUDENT_FIELD_MAP[ $norm ] ) ) {
			return array_merge( $base, [
				'type'       => 'student_field',
				'db_field'   => self::STUDENT_FIELD_MAP[ $norm ],
				'confidence' => 'high',
			] );
		}

		// Summary fields — exact match (check BEFORE subject detection so
		// "Total Scores" doesn't accidentally get claimed as a subject meta)
		if ( $import_type === 'academic_records' ) {
			// Try each summary pattern, longest-first to avoid partial shadowing
			$sorted_summary = self::SUMMARY_FIELD_MAP;
			uksort( $sorted_summary, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
			foreach ( $sorted_summary as $pattern => $db_field ) {
				if ( $norm === $pattern ) {
					return array_merge( $base, [
						'type'       => 'summary_field',
						'db_field'   => $db_field,
						'confidence' => 'high',
					] );
				}
			}
		}

		if ( $import_type === 'academic_records' ) {
			$match = $this->detect_subject_column( $norm, $code_index, $known_subjects, $subjects_with_assessments );
			if ( $match ) return array_merge( $base, $match );
		}

		// Fuzzy summary fallback — contains keyword as substring
		if ( $import_type === 'academic_records' ) {
			$sorted_summary = self::SUMMARY_FIELD_MAP;
			uksort( $sorted_summary, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
			foreach ( $sorted_summary as $pattern => $db_field ) {
				if ( str_contains( $norm, $pattern ) ) {
					return array_merge( $base, [
						'type'       => 'summary_field',
						'db_field'   => $db_field,
						'confidence' => 'medium',
					] );
				}
			}
		}

		return $base;
	}

	/**
	 * Classify all CSV headers for a specific subject.
	 * Called via AJAX when an admin clicks "Detect columns" on a subject block.
	 *
	 * Returns:
	 *   assessments  — [ ['label'=>.., 'col'=>..], ... ]
	 *   total_col    — CSV header string or ''
	 *   grade_col    — CSV header string or ''
	 *   remark_col   — CSV header string or ''
	 *   position_col — CSV header string or ''
	 */
	public function classify_for_subject( array $headers, array $subject ): array {
		$result = [
			'assessments'  => [],
			'total_col'    => '',
			'grade_col'    => '',
			'remark_col'   => '',
			'position_col' => '',
		];

		$tokens = $this->subject_tokens( $subject );

		foreach ( $headers as $header ) {
			$norm      = $this->normalise( $header );
			$remainder = $this->strip_subject_prefix( $norm, $tokens );
			if ( $remainder === null ) continue;

			// Skip class stat columns
			if ( $this->matches_any( $remainder, self::PER_SUBJECT_SKIP ) ) continue;
			foreach ( self::PER_SUBJECT_SKIP as $skip ) {
				if ( str_contains( $remainder, $skip ) ) continue 2;
			}

			// Assessment?
			foreach ( self::ASSESSMENT_PATTERNS as $pattern => $label ) {
				if ( str_contains( $remainder, $pattern ) || $remainder === $pattern ) {
					$result['assessments'][] = [ 'label' => $label, 'col' => $header ];
					continue 2;
				}
			}

			// Meta role?
			foreach ( self::META_KEYWORDS as $keyword => $role ) {
				if ( str_contains( $remainder, $keyword ) || $remainder === $keyword ) {
					if ( $result[ $role ] === '' ) $result[ $role ] = $header;
					continue 2;
				}
			}
		}

		return $result;
	}

	// =========================================================================
	// Subject detection
	// =========================================================================

	private function detect_subject_column(
		string $norm,
		array  $code_index,
		array  $known_subjects,
		array  $subjects_with_assessments = []
	): ?array {

		// ── Strategy A: strip assessment pattern, match remainder to subject ──
		// Process patterns longest-first to avoid "ca" matching before "1st ca"
		$sorted_patterns = self::ASSESSMENT_PATTERNS;
		uksort( $sorted_patterns, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

		foreach ( $sorted_patterns as $pattern => $label ) {
			if ( ! str_contains( $norm, $pattern ) ) continue;

			$fragment = trim( str_replace( $pattern, '', $norm ) );
			if ( $fragment === '' ) continue;

			$subject = $this->match_subject_fragment( $fragment, $code_index, $known_subjects );
			if ( $subject ) {
				return [
					'type'            => 'subject_score',
					'subject_id'      => $subject['_ID'],
					'subject_name'    => $subject['subject_name'],
					'assessment_hint' => $label,
					'confidence'      => 'high',
				];
			}
		}

		// ── Strategy B: subject prefix → examine remainder for meta / assessment ──
		// Collect ALL subjects that can strip a prefix, then pick the BEST match
		// (longest prefix token + most discriminating remainder tokens).
		$ordered   = $this->ordered_subjects_for_strategy_b( $code_index, $known_subjects );
		$candidates = [];

		foreach ( $ordered as $subject ) {
			$tokens = $this->subject_tokens( $subject );
			[ $remainder, $matched_token ] = $this->strip_subject_prefix_with_token( $norm, $tokens );
			if ( $remainder === null ) continue;

			// Skip class-stat columns
			if ( $this->matches_any( $remainder, self::PER_SUBJECT_SKIP ) ) {
				return [ 'type' => 'skip', 'confidence' => 'high' ];
			}
			$is_skip = false;
			foreach ( self::PER_SUBJECT_SKIP as $skip ) {
				if ( str_contains( $remainder, $skip ) ) { $is_skip = true; break; }
			}
			if ( $is_skip ) return [ 'type' => 'skip', 'confidence' => 'high' ];

			// Determine what this column is (meta or assessment)
			$role  = null;
			$label = null;
			foreach ( self::META_KEYWORDS as $keyword => $r ) {
				if ( str_contains( $remainder, $keyword ) || $remainder === $keyword ) {
					$role = $r; break;
				}
			}
			if ( ! $role ) {
				foreach ( $sorted_patterns as $pattern => $lbl ) {
					if ( str_contains( $remainder, $pattern ) ) {
						$label = $lbl; break;
					}
				}
			}
			if ( ! $role && ! $label ) continue; // not a subject column

			// For meta columns (total/grade/remark/position): only allow subjects
			// that already have assessment columns detected in Pass 1.
			// This prevents ghost subjects (e.g. "Computer Science" with only a remark)
			// when a shorter prefix like "computer" matches multiple subjects.
			if ( $role && ! empty( $subjects_with_assessments )
			     && ! isset( $subjects_with_assessments[ $subject['_ID'] ] ) ) {
				continue; // skip meta for subjects that have no detected assessments
			}

			// Score this candidate: longer matched token = more specific = higher score.
			// Also count how many of this subject's other tokens appear in the remainder
			// to break ties (e.g. "stud" in remainder → Computer Studies > Computer Science).
			$token_score  = strlen( $matched_token );
			$extra_tokens = 0;
			foreach ( $tokens as $t ) {
				if ( $t === $matched_token || strlen( $t ) <= 2 ) continue;
				if ( str_contains( $remainder, $t ) ) $extra_tokens++;
			}
			$total_score = $token_score * 10 + $extra_tokens;

			$candidates[] = [
				'subject'      => $subject,
				'remainder'    => $remainder,
				'role'         => $role,
				'label'        => $label,
				'score'        => $total_score,
			];
		}

		if ( empty( $candidates ) ) {
			// no subject matched
		} else {
			// Pick the highest-scoring candidate
			usort( $candidates, fn( $a, $b ) => $b['score'] - $a['score'] );
			$best = $candidates[0];

			if ( $best['role'] ) {
				return [
					'type'         => 'subject_meta',
					'subject_id'   => $best['subject']['_ID'],
					'subject_name' => $best['subject']['subject_name'],
					'meta_role'    => $best['role'],
					'confidence'   => 'high',
				];
			}
			if ( $best['label'] ) {
				return [
					'type'            => 'subject_score',
					'subject_id'      => $best['subject']['_ID'],
					'subject_name'    => $best['subject']['subject_name'],
					'assessment_hint' => $best['label'],
					'confidence'      => 'medium',
				];
			}
		}

		return null;
	}

	/**
	 * Match a CSV fragment (after stripping an assessment pattern) to a subject.
	 *
	 * Resolution order:
	 * 1. subject_code exact match (authoritative)
	 * 2. SUBJECT_KEYWORD_MAP (for PHE, CRS/IRS, etc.)
	 * 3. Scored fuzzy match using ALL words of the fragment
	 */
	private function match_subject_fragment(
		string $fragment,
		array  $code_index,
		array  $known_subjects
	): ?array {

		// 1. Subject code exact match — HIGHEST PRIORITY
		// If any subject has subject_code == fragment, that subject wins unconditionally.
		if ( isset( $code_index[ $fragment ] ) ) {
			return $code_index[ $fragment ];
		}

		// Also check if fragment starts with a subject code
		foreach ( $code_index as $code => $subject ) {
			if ( $code === '' ) continue;
			if ( str_starts_with( $fragment, $code ) ) {
				// The remainder after stripping the code should be empty or a known suffix
				$after_code = trim( substr( $fragment, strlen( $code ) ) );
				if ( $after_code === '' || isset( self::WORD_ABBREVIATIONS[ $after_code ] )
					|| strlen( $after_code ) <= 2 ) {
					return $subject;
				}
			}
		}

		// 2. Collapsed-abbreviation map (PHE, CRS, etc.)
		$collapsed = preg_replace( '/[^a-z0-9]/', '', $fragment );
		if ( isset( self::SUBJECT_KEYWORD_MAP[ $collapsed ] ) ) {
			$kw = self::SUBJECT_KEYWORD_MAP[ $collapsed ];
			foreach ( $known_subjects as $s ) {
				if ( str_contains( $this->normalise( $s['subject_name'] ), $kw ) ) return $s;
			}
		}
		foreach ( self::SUBJECT_KEYWORD_MAP as $abbr => $kw ) {
			if ( strlen( $abbr ) >= 3 && str_starts_with( $collapsed, $abbr ) ) {
				foreach ( $known_subjects as $s ) {
					if ( str_contains( $this->normalise( $s['subject_name'] ), $kw ) ) return $s;
				}
			}
		}

		// 3. Scored fuzzy match — considers ALL words of the fragment
		$best_score   = 3.0; // minimum threshold — lowered slightly to catch abbreviated multi-word names
		$best_subject = null;
		foreach ( $known_subjects as $s ) {
			$score = $this->score_match( $fragment, $this->normalise( $s['subject_name'] ) );
			if ( $score > $best_score ) {
				$best_score   = $score;
				$best_subject = $s;
			}
		}
		return $best_subject;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a code → subject map for subjects that have an explicit subject_code.
	 * Keys are normalised codes. Only subjects WITH a non-empty code are indexed here.
	 */
	private function build_code_index( array $known_subjects ): array {
		$map = [];
		foreach ( $known_subjects as $subject ) {
			$code = trim( (string) ( $subject['subject_code'] ?? '' ) );
			if ( $code === '' ) continue;
			$norm_code = $this->normalise( $code );
			if ( $norm_code !== '' ) {
				$map[ $norm_code ] = $subject;
			}
		}
		return $map;
	}

	/**
	 * All tokens by which a subject can be identified in a column header.
	 *
	 * For subjects WITH a subject_code: only the normalised code is returned.
	 * This prevents ambiguous word-level tokens from triggering false matches.
	 * For example, if "Eng" is the code for "English Language", we do NOT
	 * also add "english" or "language" as tokens — because those words appear
	 * in other subject names too.
	 *
	 * For subjects WITHOUT a subject_code: full name + per-word abbreviations.
	 */
	private function subject_tokens( array $subject ): array {
		$tokens    = [];
		$code      = trim( (string) ( $subject['subject_code'] ?? '' ) );
		$norm_name = $this->normalise( $subject['subject_name'] ?? '' );
		$norm_code = $code !== '' ? $this->normalise( $code ) : '';

		// 1. Subject code (highest priority — added first, and strip_subject_prefix
		//    sorts longest-first, so it wins over individual word tokens)
		if ( $norm_code !== '' ) {
			$tokens[] = $norm_code;
		}

		// 2. Full normalised name (catches exact full-name prefixes like "basic science total")
		$tokens[] = $norm_name;

		// 3. Per-word tokens with abbreviations — always added regardless of whether
		//    there is a subject_code, because the CSV may use abbreviations that
		//    don't match the stored code exactly (e.g. code "Base Sci" but CSV uses
		//    "Base Sci" which normalises to "base sci" — already covered by code token,
		//    but also "Basic Sci" or "BSci" which aren't the code).
		//    These are lower-priority because they appear after the code token in the
		//    sorted list (shorter tokens come after longer ones when equal length).
		$words = array_filter( explode( ' ', $norm_name ), fn( $w ) => strlen( $w ) > 2 );
		foreach ( $words as $w ) {
			$tokens[] = $w;
			$exp = self::WORD_ABBREVIATIONS[ $w ] ?? null;
			if ( $exp ) $tokens[] = $exp;
			foreach ( self::WORD_ABBREVIATIONS as $abbr => $full ) {
				if ( $full === $w ) $tokens[] = $abbr;
			}
		}

		// 4. SUBJECT_KEYWORD_MAP collapsed abbreviations
		foreach ( self::SUBJECT_KEYWORD_MAP as $abbr => $kw ) {
			if ( str_contains( $norm_name, $kw ) ) $tokens[] = $abbr;
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Strip the longest matching subject token from the start of $norm.
	 * Returns the trimmed remainder, or null if no token matched.
	 */
	private function strip_subject_prefix( string $norm, array $tokens ): ?string {
		[ $result ] = $this->strip_subject_prefix_with_token( $norm, $tokens );
		return $result;
	}

	/**
	 * Like strip_subject_prefix but also returns the matched token.
	 * Returns [ ?string $remainder, string $matched_token ]
	 */
	private function strip_subject_prefix_with_token( string $norm, array $tokens ): array {
		usort( $tokens, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
		foreach ( $tokens as $token ) {
			if ( $token === '' ) continue;
			if ( $norm === $token ) return [ '', $token ];
			if ( str_starts_with( $norm, $token ) ) {
				return [ trim( substr( $norm, strlen( $token ) ) ), $token ];
			}
		}
		return [ null, '' ];
	}

	/**
	 * Order subjects for Strategy B so code-having subjects are checked first.
	 * Within each group, subjects with longer codes/names are checked first.
	 */
	private function ordered_subjects_for_strategy_b( array $code_index, array $known_subjects ): array {
		$with_code    = [];
		$without_code = [];
		foreach ( $known_subjects as $s ) {
			$code = trim( (string) ( $s['subject_code'] ?? '' ) );
			if ( $code !== '' ) $with_code[]    = $s;
			else                $without_code[] = $s;
		}
		// Sort each group by name length desc (longer names = more specific)
		$sorter = fn( $a, $b ) => strlen( $b['subject_name'] ) - strlen( $a['subject_name'] );
		usort( $with_code,    $sorter );
		usort( $without_code, $sorter );
		return array_merge( $with_code, $without_code );
	}

	/**
	 * Score how well a CSV fragment matches a subject name.
	 *
	 * Key insight: we score every WORD in the fragment against every WORD in
	 * the subject name. A fragment word that doesn't match any subject word
	 * is treated as "extra noise" — we reduce the score by the proportion of
	 * unmatched fragment words. This means "Comp Stud" scores better for
	 * "Computer Studies" than for "Computer Science", because "stud" → "studies"
	 * matches in one but not the other.
	 */
	private function score_match( string $fragment, string $subject_norm ): float {
		if ( $fragment === '' || $subject_norm === '' ) return 0.0;

		$subj_words = array_values( array_filter( explode( ' ', $subject_norm ), fn( $w ) => strlen( $w ) > 1 ) );
		$frag_raw   = array_values( array_filter( explode( ' ', $fragment ),     fn( $w ) => strlen( $w ) > 1 ) );
		if ( empty( $subj_words ) || empty( $frag_raw ) ) return 0.0;

		// Expand fragment words (keep originals too)
		$frag_words = [];
		foreach ( $frag_raw as $fw ) {
			$frag_words[] = $fw;
			$exp = self::WORD_ABBREVIATIONS[ $fw ] ?? null;
			if ( $exp ) $frag_words[] = $exp;
		}
		$frag_words = array_unique( $frag_words );

		$score          = 0.0;
		$matched_subj   = 0;
		$matched_frag   = [];

		foreach ( $subj_words as $sw ) {
			$sw_matched = false;
			foreach ( $frag_words as $fi => $fw ) {
				$pts = 0;
				if ( $fw === $sw )                                               $pts = 10;
				elseif ( str_starts_with( $sw, $fw ) && strlen($fw) >= 3 )      $pts =  8;
				elseif ( str_starts_with( $fw, $sw ) && strlen($sw) >= 3 )      $pts =  8;
				elseif ( str_contains( $sw, $fw ) && strlen($fw) >= 3 )         $pts =  4;
				elseif ( str_contains( $fw, $sw ) && strlen($sw) >= 3 )         $pts =  4;

				if ( $pts > 0 ) {
					$score += $pts;
					$matched_subj++;
					$matched_frag[] = $fi;
					$sw_matched = true;
					break;
				}
			}
			if ( ! $sw_matched ) {
				// Unmatched subject word — penalise
				$score -= 5;
			}
		}

		// Penalise unmatched fragment words (they represent "noise" in the header)
		$unmatched_frag = count( $frag_raw ) - count( array_unique( $matched_frag ) );
		$score -= $unmatched_frag * 3;

		// Require at least half the subject words to match
		if ( $matched_subj < ceil( count( $subj_words ) / 2 ) ) return 0.0;

		return $score;
	}

	private function normalise( string $s ): string {
		$s = strtolower( trim( $s ) );
		$s = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $s );
		$s = preg_replace( '/\s+/', ' ', $s );
		return trim( $s );
	}

	private function matches_any( string $needle, array $patterns ): bool {
		return in_array( $needle, $patterns, true );
	}
}
