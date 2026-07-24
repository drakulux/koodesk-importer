<?php
/**
 * Koodesk Importer – ProfileManager
 *
 * All reads and writes to the import_mapping CCT go through this class.
 * Nothing else in the importer touches the CCT directly.
 *
 * The import_mapping CCT has these fields (created manually in JetEngine UI):
 *   profile_name           text
 *   import_type            text   ('academic_records' | 'students')
 *   assessment_template_id number (optional, 0 if unused)
 *   last_used              number (unix timestamp)
 *   column_mapping         textarea (JSON)
 *
 * Plus JetEngine auto-fields: _ID, cct_status, cct_author_id,
 *   cct_created, cct_modified.
 *
 * column_mapping JSON shape (academic_records):
 * {
 *   "student_fields": {
 *     "Name": "full_name",
 *     "Gender": "gender",
 *     "STUDENT ID": "external_student_key"
 *   },
 *   "term_col": "Term",
 *   "session_col": "Session",
 *   "term_value_map": { "First": 1, "Second": 2, "Third": 3 },
 *   "subjects": [
 *     {
 *       "subject_id": 1,
 *       "subject_name": "English Language",
 *       "assessments": [
 *         { "label": "1st CA", "col": "Eng 1st CA" },
 *         { "label": "2nd CA", "col": "Eng 2nd CA" },
 *         { "label": "Exam",   "col": "Eng Exam" }
 *       ],
 *       "total_col":    "Eng Total",
 *       "grade_col":    "Eng Grade",
 *       "remark_col":   "eng remark",
 *       "position_col": "Eng Position"
 *     }
 *   ],
 *   "summary_fields": {
 *     "Total Scores":        "term_total_score",
 *     "Average Score":       "term_average",
 *     "Position in Class":   "position_in_class",
 *     "Teacher Remark": "teacher_remark",
 *     "Principal Remark": "principal_remark"
 *   },
 *   "skip_cols": ["eng hs", "eng clav", ...]
 * }
 *
 * column_mapping JSON shape (students):
 * {
 *   "student_fields": {
 *     "Name":      "full_name",
 *     "Gender":    "gender",
 *     "Adm No":    "external_student_key",
 *     "Class":     "current_class_name",
 *     "DOB":       "date_of_birth",
 *     "Family Name":  "family_name",   // optional — overrides last-name-derived family
 *     "Home Address": "home_address"   // stored on the linked `families` CCT record
 *   },
 *   "guardian_fields": {
 *     "guardian_name":         "Parent Name",
 *     "guardian_relationship": "Relationship",
 *     "guardian_phone":        "Parent Phone",
 *     "guardian_whatsapp":     "Parent WhatsApp",
 *     "guardian_email":        "Parent Email",
 *     "guardian_occupation":   "Parent Occupation",
 *     "guardian_address":      "Parent Address"
 *   }
 * }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Profile_Manager {

	const CCT_SLUG = 'import_mapping';

	/** @var \Jet_Engine\Modules\Custom_Content_Types\Content_Type|null */
	private $cct = null;

	// -------------------------------------------------------------------------
	// Fetching profiles
	// -------------------------------------------------------------------------

	/**
	 * Get all saved profiles, optionally filtered by import_type.
	 *
	 * @param string $import_type  '' = all, 'academic_records' | 'students'
	 * @return array[]  Array of profile arrays (raw CCT items), newest first.
	 */
	public function get_profiles( string $import_type = '' ): array {
		$cct = $this->get_cct();
		if ( ! $cct ) return [];

		$query = [];
		if ( $import_type !== '' ) {
			$query[] = [
				'field'    => 'import_type',
				'operator' => '=',
				'value'    => $import_type,
			];
		}

		// status = publish only
		$query[] = [
			'field'    => 'cct_status',
			'operator' => '=',
			'value'    => 'publish',
		];

		$items = $cct->db->query( $query );
		if ( empty( $items ) ) return [];

		// Sort by last_used descending (most recently used first)
		usort( $items, function( $a, $b ) {
			return intval( $b['last_used'] ?? 0 ) <=> intval( $a['last_used'] ?? 0 );
		} );

		return $items;
	}

	/**
	 * Get a single profile by its CCT _ID.
	 *
	 * @param int $profile_id
	 * @return array|null  Raw CCT item or null if not found.
	 */
	public function get_profile( int $profile_id ): ?array {
		$cct = $this->get_cct();
		if ( ! $cct ) return null;

		$item = $cct->db->get_item( $profile_id );
		return ! empty( $item ) ? $item : null;
	}

	/**
	 * Get the decoded column_mapping from a profile.
	 *
	 * @param int $profile_id
	 * @return array|null  Decoded associative array or null on failure.
	 */
	public function get_column_mapping( int $profile_id ): ?array {
		$profile = $this->get_profile( $profile_id );
		if ( ! $profile ) return null;

		return $this->decode_mapping( $profile['column_mapping'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Saving profiles
	// -------------------------------------------------------------------------

	/**
	 * Save a new profile. Returns the new _ID or 0 on failure.
	 *
	 * @param string $profile_name
	 * @param string $import_type       'academic_records' | 'students'
	 * @param array  $column_mapping    Associative array — will be JSON-encoded.
	 * @param int    $template_id       assessment_template_id (0 if not applicable).
	 * @return int  New profile _ID, or 0 on failure.
	 */
	public function create_profile(
		string $profile_name,
		string $import_type,
		array  $column_mapping,
		int    $template_id = 0
	): int {

		$cct = $this->get_cct();
		if ( ! $cct ) return 0;

		$handler = $cct->get_item_handler();

		$data = [
			'profile_name'           => sanitize_text_field( $profile_name ),
			'import_type'            => sanitize_key( $import_type ),
			'assessment_template_id' => $template_id,
			'last_used'              => time(),
			'column_mapping'         => wp_json_encode( $column_mapping, JSON_UNESCAPED_UNICODE ),
			'cct_status'             => 'publish',
			'cct_author_id'          => get_current_user_id(),
		];

		$handler->update_item( $data );

		// JetEngine inserts and returns the new _ID via the handler
		// We need to query for the most recently created item with this name
		$results = $cct->db->query( [
			[ 'field' => 'profile_name', 'operator' => '=', 'value' => $profile_name ],
			[ 'field' => 'import_type',  'operator' => '=', 'value' => $import_type ],
		] );

		if ( empty( $results ) ) return 0;

		// Return highest _ID (most recently inserted)
		$ids = array_column( $results, '_ID' );
		return ! empty( $ids ) ? (int) max( $ids ) : 0;
	}

	/**
	 * Update an existing profile's column_mapping and touch last_used.
	 *
	 * @param int   $profile_id
	 * @param array $column_mapping
	 * @return bool
	 */
	public function update_mapping( int $profile_id, array $column_mapping ): bool {
		$cct = $this->get_cct();
		if ( ! $cct ) return false;

		$handler = $cct->get_item_handler();
		$handler->update_item( [
			'_ID'            => $profile_id,
			'column_mapping' => wp_json_encode( $column_mapping, JSON_UNESCAPED_UNICODE ),
			'last_used'      => time(),
		] );

		return true;
	}

	/**
	 * Touch the last_used timestamp on a profile (called at start of each import).
	 *
	 * @param int $profile_id
	 */
	public function touch( int $profile_id ): void {
		$cct = $this->get_cct();
		if ( ! $cct ) return;

		$cct->get_item_handler()->update_item( [
			'_ID'       => $profile_id,
			'last_used' => time(),
		] );
	}

	/**
	 * Delete a profile by ID.
	 *
	 * @param int $profile_id
	 * @return bool
	 */
	public function delete_profile( int $profile_id ): bool {
		global $wpdb;
		$cct = $this->get_cct();
		if ( ! $cct ) return false;

		$table = $cct->db->table();
		$result = $wpdb->delete( $table, [ '_ID' => $profile_id ], [ '%d' ] );
		return $result !== false;
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Validate a column_mapping array for the given import type.
	 * Returns an array of error strings (empty = valid).
	 *
	 * @param array  $mapping
	 * @param string $import_type
	 * @return string[]
	 */
	public function validate_mapping( array $mapping, string $import_type ): array {
		$errors = [];

		if ( $import_type === 'academic_records' ) {
			// Must have at least one subject
			if ( empty( $mapping['subjects'] ) ) {
				$errors[] = 'No subjects are mapped. Map at least one subject before saving.';
			}

			// Each subject must have at least one assessment column
			foreach ( $mapping['subjects'] ?? [] as $i => $subj ) {
				if ( empty( $subj['subject_id'] ) ) {
					$errors[] = 'Subject row ' . ( $i + 1 ) . ' is missing a subject ID.';
				}
				if ( empty( $subj['assessments'] ) ) {
					$errors[] = 'Subject "' . esc_html( $subj['subject_name'] ?? '?' ) . '" has no assessment columns mapped.';
				}
			}

			// Must have a way to identify students
			$student_fields = $mapping['student_fields'] ?? [];
			$has_name = in_array( 'full_name', $student_fields, true );
			$has_key  = in_array( 'external_student_key', $student_fields, true );
			if ( ! $has_name && ! $has_key ) {
				$errors[] = 'No student identifier mapped. Map either "Full Name" or "External Student Key".';
			}

			// Must have term and session columns
			if ( empty( $mapping['term_col'] ) ) {
				$errors[] = 'No Term column mapped.';
			}
			if ( empty( $mapping['session_col'] ) ) {
				$errors[] = 'No Session column mapped.';
			}
		}

		if ( $import_type === 'students' ) {
			$student_fields = $mapping['student_fields'] ?? [];
			if ( ! in_array( 'full_name', $student_fields, true ) ) {
				$errors[] = 'No Name column mapped. The student name is required.';
			}
		}

		return $errors;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Decode column_mapping JSON safely.
	 *
	 * @param string $json
	 * @return array|null
	 */
	public function decode_mapping( string $json ): ?array {
		if ( empty( $json ) ) return null;
		$decoded = json_decode( $json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) return null;
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Get (and cache) the import_mapping CCT instance.
	 *
	 * @return \Jet_Engine\Modules\Custom_Content_Types\Content_Type|null
	 */
	private function get_cct() {
		if ( $this->cct !== null ) return $this->cct;

		if ( ! function_exists( 'jet_engine' ) ) return null;

		$manager = jet_engine()->modules->get_module( 'custom-content-types' );
		if ( ! $manager ) return null;

		$this->cct = $manager->instance->manager->get_content_types( self::CCT_SLUG );
		return $this->cct;
	}

	/**
	 * Build a default term_value_map from a set of raw term values found in
	 * the uploaded file, using common Nigerian school term labels.
	 *
	 * @param string[] $raw_values  e.g. ['First', 'first', '1st Term']
	 * @return array  e.g. ['First' => 1]
	 */
	public function build_term_value_map( array $raw_values ): array {
		$known = [
			// English ordinal forms
			'first'        => 1, '1st'         => 1, '1st term'    => 1,
			'term 1'       => 1, 'term one'     => 1, '1'           => 1,
			'second'       => 2, '2nd'          => 2, '2nd term'    => 2,
			'term 2'       => 2, 'term two'     => 2, '2'           => 2,
			'third'        => 3, '3rd'          => 3, '3rd term'    => 3,
			'term 3'       => 3, 'term three'   => 3, '3'           => 3,
		];

		$map = [];
		foreach ( $raw_values as $raw ) {
			$normalised = strtolower( trim( (string) $raw ) );
			if ( isset( $known[ $normalised ] ) ) {
				$map[ $raw ] = $known[ $normalised ];
			}
		}
		return $map;
	}
}
