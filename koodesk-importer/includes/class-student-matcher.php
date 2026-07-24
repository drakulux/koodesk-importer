<?php
/**
 * Koodesk Importer – StudentMatcher
 *
 * Resolves a student name (and optional external_student_key) from an import
 * row to an existing student in the students CCT, or flags the row as needing
 * a new student to be created.
 *
 * Matching tiers (in order):
 *
 *   Tier 1 – EXACT KEY MATCH
 *     external_student_key present in import row AND in students CCT.
 *     Status: 'matched', confidence: 'high'
 *
 *   Tier 2 – EXACT NAME MATCH (unique)
 *     Normalised full_name matches exactly one student.
 *     Status: 'matched', confidence: 'medium'
 *
 *   Tier 3 – AMBIGUOUS NAME
 *     Normalised full_name matches more than one student.
 *     Status: 'ambiguous', confidence: 'low'
 *     candidates: [ { _ID, full_name, student_reg_number, current_class_name } ]
 *
 *   Tier 4 – PARTIAL NAME MATCH
 *     LIKE search on full_name returns one or more candidates.
 *     Status: 'ambiguous' or 'not_found' depending on result count.
 *
 *   Tier 5 – NOT FOUND
 *     No match at any tier.
 *     Status: 'not_found'
 *     → admin can choose to create as new student
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Student_Matcher {

	/**
	 * Cache of students loaded for this import session.
	 * Populated by preload() to avoid N+1 queries.
	 * @var array[]
	 */
	private array $cache = [];

	/** @var bool */
	private bool $cache_loaded = false;

	// -------------------------------------------------------------------------
	// Preloading (call once before matching a batch of rows)
	// -------------------------------------------------------------------------

	/**
	 * Preload all published students into memory.
	 * For large schools (500+ students) this is still cheaper than individual
	 * queries per row. Call once at the start of the import.
	 */
	public function preload(): void {
		global $wpdb;

		$cct = $this->get_students_cct();
		if ( ! $cct ) return;

		$table = $cct->db->table();

		$rows = $wpdb->get_results(
			"SELECT _ID, full_name, first_name, last_name, student_reg_number,
			        external_student_key, current_class_id
			 FROM {$table}
			 WHERE cct_status = 'publish'",
			ARRAY_A
		);

		$this->cache = [];
		foreach ( $rows as $row ) {
			$this->cache[] = [
				'_ID'                  => (int) $row['_ID'],
				'full_name'            => $row['full_name'],
				'full_name_norm'       => $this->normalise_name( $row['full_name'] ),
				'first_name'           => $row['first_name'],
				'last_name'            => $row['last_name'],
				'student_reg_number'   => $row['student_reg_number'],
				'external_student_key' => $row['external_student_key'],
				'current_class_id'     => (int) $row['current_class_id'],
			];
		}

		$this->cache_loaded = true;
	}

	/**
	 * Clear the in-memory cache (call between import runs if needed).
	 */
	public function clear_cache(): void {
		$this->cache        = [];
		$this->cache_loaded = false;
	}

	// -------------------------------------------------------------------------
	// Matching
	// -------------------------------------------------------------------------

	/**
	 * Match a single import row to a student.
	 *
	 * @param string $raw_name            The name as it appears in the CSV.
	 * @param string $external_key        The school's own student ID (may be '').
	 *
	 * @return array {
	 *   status:      'matched'|'ambiguous'|'not_found'
	 *   confidence:  'high'|'medium'|'low'
	 *   student_id:  int   (0 if not matched)
	 *   student:     array (the matched student record, or null)
	 *   candidates:  array (for ambiguous matches)
	 *   name_used:   string (the normalised name we searched for)
	 * }
	 */
	public function match( string $raw_name, string $external_key = '' ): array {
		if ( ! $this->cache_loaded ) {
			$this->preload();
		}

		$base = [
			'status'     => 'not_found',
			'confidence' => 'low',
			'student_id' => 0,
			'student'    => null,
			'candidates' => [],
			'name_used'  => $this->normalise_name( $raw_name ),
		];

		// --- Tier 1: external_student_key exact match ---
		if ( $external_key !== '' ) {
			foreach ( $this->cache as $student ) {
				if (
					$student['external_student_key'] !== '' &&
					$student['external_student_key'] !== null &&
					(string) $student['external_student_key'] === (string) $external_key
				) {
					return array_merge( $base, [
						'status'     => 'matched',
						'confidence' => 'high',
						'student_id' => $student['_ID'],
						'student'    => $student,
					] );
				}
			}
		}

		// --- Tier 2 & 3: exact normalised full_name match ---
		$name_norm = $this->normalise_name( $raw_name );
		if ( $name_norm === '' ) return $base;

		$exact_matches = array_filter( $this->cache, function( $s ) use ( $name_norm ) {
			return $s['full_name_norm'] === $name_norm;
		} );
		$exact_matches = array_values( $exact_matches );

		if ( count( $exact_matches ) === 1 ) {
			return array_merge( $base, [
				'status'     => 'matched',
				'confidence' => 'medium',
				'student_id' => $exact_matches[0]['_ID'],
				'student'    => $exact_matches[0],
			] );
		}

		if ( count( $exact_matches ) > 1 ) {
			return array_merge( $base, [
				'status'     => 'ambiguous',
				'confidence' => 'low',
				'candidates' => $exact_matches,
			] );
		}

		// --- Tier 4: partial name match (any word overlap) ---
		$partial = $this->partial_match( $name_norm );

		if ( count( $partial ) === 1 ) {
			return array_merge( $base, [
				'status'     => 'ambiguous',  // still needs admin confirmation
				'confidence' => 'low',
				'candidates' => $partial,
			] );
		}

		if ( count( $partial ) > 1 ) {
			return array_merge( $base, [
				'status'     => 'ambiguous',
				'confidence' => 'low',
				'candidates' => $partial,
			] );
		}

		return $base; // not_found
	}

	/**
	 * Match all unique student names in a set of rows at once.
	 * Returns a keyed array: normalised_name → match result.
	 *
	 * @param array[]  $rows              Parsed CSV rows.
	 * @param string   $name_col         CSV column header for student name.
	 * @param string   $ext_key_col      CSV column header for external_student_key ('' if none).
	 * @return array
	 */
	public function match_all( array $rows, string $name_col, string $ext_key_col = '' ): array {
		if ( ! $this->cache_loaded ) {
			$this->preload();
		}

		$results = [];

		foreach ( $rows as $row ) {
			$raw_name    = trim( (string) ( $row[ $name_col ] ?? '' ) );
			$ext_key     = $ext_key_col !== '' ? trim( (string) ( $row[ $ext_key_col ] ?? '' ) ) : '';
			$lookup_key  = $this->normalise_name( $raw_name ) . '||' . $ext_key;

			if ( isset( $results[ $lookup_key ] ) ) continue; // already matched

			$results[ $lookup_key ] = $this->match( $raw_name, $ext_key );
			$results[ $lookup_key ]['raw_name'] = $raw_name;
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Name splitting
	// -------------------------------------------------------------------------

	/**
	 * Split a full name into first_name and last_name.
	 * Rule: last word = last_name, everything else = first_name.
	 *
	 * "AHMED FIRDAUSI"          → first: "AHMED",         last: "FIRDAUSI"
	 * "IBRAHIM MUHAMMAD KAITA"  → first: "IBRAHIM MUHAMMAD", last: "KAITA"
	 * "BELLO"                   → first: "BELLO",         last: ""
	 *
	 * @param string $full_name
	 * @return array [ 'first_name' => string, 'last_name' => string, 'full_name' => string ]
	 */
	public function split_name( string $full_name ): array {
		$name = trim( $full_name );
		if ( $name === '' ) {
			return [ 'first_name' => '', 'last_name' => '', 'full_name' => '' ];
		}

		$parts = preg_split( '/\s+/', $name );

		if ( count( $parts ) === 1 ) {
			return [ 'first_name' => $parts[0], 'last_name' => '', 'full_name' => $name ];
		}

		$last_name  = array_pop( $parts );
		$first_name = implode( ' ', $parts );

		return [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'full_name'  => $name,
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Partial word-overlap match in cache.
	 * Returns cache entries where at least one significant word from $name_norm
	 * appears in the student's normalised full_name.
	 */
	private function partial_match( string $name_norm ): array {
		$input_words = $this->significant_words( $name_norm );
		if ( empty( $input_words ) ) return [];

		$matches = [];
		foreach ( $this->cache as $student ) {
			$student_words = $this->significant_words( $student['full_name_norm'] );
			$overlap = array_intersect( $input_words, $student_words );
			// Require at least 2 words to overlap to reduce false positives
			if ( count( $overlap ) >= min( 2, count( $input_words ) ) ) {
				$matches[] = $student;
			}
		}

		return $matches;
	}

	/**
	 * Return "significant" words — strips common single-character initials.
	 */
	private function significant_words( string $name_norm ): array {
		$words = explode( ' ', $name_norm );
		return array_values( array_filter( $words, fn( $w ) => strlen( $w ) > 1 ) );
	}

	/**
	 * Normalise a name for comparison:
	 * lowercase, collapse whitespace, strip punctuation except hyphens.
	 */
	public function normalise_name( string $name ): string {
		$s = strtolower( trim( $name ) );
		$s = preg_replace( '/[^\p{L}\p{N}\s\-]/u', '', $s ); // keep hyphens
		$s = preg_replace( '/\s+/', ' ', $s );
		return trim( $s );
	}

	/**
	 * Get the students CCT instance.
	 */
	private function get_students_cct() {
		if ( ! function_exists( 'jet_engine' ) ) return null;
		$manager = jet_engine()->modules->get_module( 'custom-content-types' );
		if ( ! $manager ) return null;
		return $manager->instance->manager->get_content_types( 'students' );
	}
}
