<?php
/**
 * Koodesk Importer – FamilyResolver
 *
 * Handles the `families` CCT introduced alongside the student importer.
 *
 *   families (parent)  1 ───< students (child)
 *   JetEngine relation ID: 796
 *
 * Responsibilities:
 *   - Find an existing family by family_name (derived from the student's
 *     last name, or an explicit "Family Name" CSV column if mapped).
 *   - Create a new family record (with a generated family_code) when none
 *     exists yet for that surname.
 *   - Build the `guardians` repeater on the family from mapped CSV columns.
 *   - Link the family ↔ student via the JetEngine relation.
 *
 * Family matching is scoped to family_name only (not also class/session)
 * because a family can have children in different classes/sessions and
 * should still resolve to the same family record.
 *
 * Students can be imported WITHOUT any family data — if no last name can
 * be derived and no family fields are mapped, the import simply skips
 * family creation and linking for that row.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Family_Resolver {

	/** JetEngine relation ID: families (parent) → students (child) */
	const REL_FAMILY_STUDENT = 796;

	/** @var array<string,int> Cache of family_name (lowercased) => family _ID, for this run only */
	private array $resolved_cache = [];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Resolve (find or create) a family for a student row and link them.
	 *
	 * @param string $family_name      Resolved family name (e.g. student's last name)
	 * @param array  $guardian_entries List of guardian arrays built from mapped CSV columns.
	 *                                 Each entry uses the `guardians` repeater sub-field keys:
	 *                                 guardian_name, guardian_relationship, guardian_phone,
	 *                                 guardian_whatsapp, guardian_email, guardian_occupation,
	 *                                 guardian_address, primary_contact, can_pickup.
	 * @param string $home_address     Optional home address to store on the family.
	 * @param int    $student_id       The student _ID to link to this family.
	 * @return array{family_id:int, created:bool, linked:bool, error:string}
	 */
	public function resolve_and_link(
		string $family_name,
		array  $guardian_entries,
		string $home_address,
		int    $student_id
	): array {

		$family_name = trim( $family_name );

		// No family name and nothing else to go on — skip family handling entirely.
		// This is the explicit "import students without family data" path.
		if ( $family_name === '' && empty( $guardian_entries ) && $home_address === '' ) {
			return [ 'family_id' => 0, 'created' => false, 'linked' => false, 'error' => '' ];
		}

		if ( $family_name === '' ) {
			// We have guardian/address data but no name to key the family on —
			// can't safely find-or-create, so skip (avoid creating "Unnamed Family" records).
			return [ 'family_id' => 0, 'created' => false, 'linked' => false, 'error' => 'No family name available (could not derive from student name).' ];
		}

		try {
			[ $family_id, $created ] = $this->find_or_create_family( $family_name, $guardian_entries, $home_address );
		} catch ( \Throwable $e ) {
			return [ 'family_id' => 0, 'created' => false, 'linked' => false, 'error' => $e->getMessage() ];
		}

		if ( $family_id <= 0 ) {
			return [ 'family_id' => 0, 'created' => false, 'linked' => false, 'error' => 'Could not resolve family record.' ];
		}

		$linked = $this->link_student_to_family( $family_id, $student_id );

		return [ 'family_id' => $family_id, 'created' => $created, 'linked' => $linked, 'error' => '' ];
	}

	// -------------------------------------------------------------------------
	// Find or create
	// -------------------------------------------------------------------------

	/**
	 * @return array{0:int,1:bool} [family_id, was_created]
	 */
	private function find_or_create_family( string $family_name, array $guardian_entries, string $home_address ): array {
		global $wpdb;

		$cache_key = strtolower( $family_name );
		if ( isset( $this->resolved_cache[ $cache_key ] ) ) {
			$family_id = $this->resolved_cache[ $cache_key ];
			// Still merge any new guardian data into the existing family
			$this->merge_guardians_into_existing( $family_id, $guardian_entries );
			return [ $family_id, false ];
		}

		$families_cct = $this->get_families_cct();
		if ( ! $families_cct ) {
			throw new \Exception( 'Families content type is not available.' );
		}
		$table = $families_cct->db->table();

		// Look for an existing family with this exact name (case-insensitive)
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT _ID FROM {$table} WHERE LOWER(TRIM(family_name)) = %s ORDER BY _ID ASC LIMIT 1",
			strtolower( trim( $family_name ) )
		) );

		if ( $existing_id ) {
			$this->resolved_cache[ $cache_key ] = $existing_id;
			// Merge any new guardian info (e.g. a sibling import adds a guardian not seen before)
			$this->merge_guardians_into_existing( $existing_id, $guardian_entries );
			// Fill home_address if the existing family doesn't have one yet
			if ( $home_address !== '' ) {
				$current_addr = $wpdb->get_var( $wpdb->prepare(
					"SELECT home_address FROM {$table} WHERE _ID = %d", $existing_id
				) );
				if ( trim( (string) $current_addr ) === '' ) {
					$families_cct->get_item_handler()->update_item( [
						'_ID'          => $existing_id,
						'home_address' => $home_address,
					] );
				}
			}
			return [ $existing_id, false ];
		}

		// Create a new family
		$family_code = $this->generate_family_code( $families_cct, $table );

		$new_family = [
			'family_name'   => $family_name,
			'family_code'   => $family_code,
			'cct_status'    => 'publish',
			'cct_author_id' => get_current_user_id(),
		];
		if ( $home_address !== '' ) {
			$new_family['home_address'] = $home_address;
		}
		if ( ! empty( $guardian_entries ) ) {
			$new_family['guardians'] = $guardian_entries;
		}

		$families_cct->get_item_handler()->update_item( $new_family );

		$new_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT _ID FROM {$table} WHERE family_code = %s ORDER BY _ID DESC LIMIT 1",
			$family_code
		) );

		if ( ! $new_id ) {
			throw new \Exception( "Could not retrieve _ID for newly created family \"{$family_name}\"." );
		}

		$this->resolved_cache[ $cache_key ] = $new_id;
		return [ $new_id, true ];
	}

	/**
	 * When a family already exists, merge in any guardian entries from this
	 * row that aren't already present (matched by guardian_name, case-insensitive).
	 * This handles siblings being imported in the same or later runs — each
	 * sibling's row may list the same guardian; we don't want duplicates.
	 */
	private function merge_guardians_into_existing( int $family_id, array $new_guardians ): void {
		if ( empty( $new_guardians ) ) return;

		$families_cct = $this->get_families_cct();
		if ( ! $families_cct ) return;
		global $wpdb;
		$table = $families_cct->db->table();

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT guardians FROM {$table} WHERE _ID = %d", $family_id ) );
		$existing_guardians = [];
		if ( $raw ) {
			$decoded = maybe_unserialize( $raw );
			if ( is_array( $decoded ) ) $existing_guardians = $decoded;
		}

		$existing_names = array_map(
			fn( $g ) => strtolower( trim( (string) ( $g['guardian_name'] ?? '' ) ) ),
			$existing_guardians
		);

		$changed = false;
		foreach ( $new_guardians as $g ) {
			$name_norm = strtolower( trim( (string) ( $g['guardian_name'] ?? '' ) ) );
			if ( $name_norm === '' ) continue;
			if ( in_array( $name_norm, $existing_names, true ) ) continue; // already have this guardian
			$existing_guardians[] = $g;
			$existing_names[]     = $name_norm;
			$changed = true;
		}

		if ( $changed ) {
			$families_cct->get_item_handler()->update_item( [
				'_ID'       => $family_id,
				'guardians' => $existing_guardians,
			] );
		}
	}

	/**
	 * Generate a unique family code like FAM00001, FAM00002, ...
	 */
	private function generate_family_code( $families_cct, string $table ): string {
		global $wpdb;
		$max_id = (int) $wpdb->get_var( "SELECT MAX(_ID) FROM {$table}" );
		$attempt = $max_id + 1;

		do {
			$code = 'FAM' . str_pad( (string) $attempt, 5, '0', STR_PAD_LEFT );
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE family_code = %s", $code
			) );
			$attempt++;
		} while ( $exists > 0 );

		return $code;
	}

	// -------------------------------------------------------------------------
	// Relation linking
	// -------------------------------------------------------------------------

	private function link_student_to_family( int $family_id, int $student_id ): bool {
		if ( $family_id <= 0 || $student_id <= 0 ) return false;
		if ( ! function_exists( 'jet_engine' ) ) return false;

		try {
			$relations_module = jet_engine()->relations;
			if ( ! $relations_module ) return false;

			$relation = $relations_module->get_relation( self::REL_FAMILY_STUDENT );
			if ( ! $relation ) return false;

			// Avoid duplicate links: check if this pair already exists
			$existing = $relation->get_children( $family_id );
			$existing_ids = is_array( $existing ) ? array_map( 'intval', $existing ) : [];
			if ( in_array( $student_id, $existing_ids, true ) ) {
				return true; // already linked
			}

			$relation->update_relation( $family_id, $student_id );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_families_cct() {
		if ( ! function_exists( 'jet_engine' ) ) return null;
		try {
			$cct_m = jet_engine()->modules->get_module( 'custom-content-types' )->instance->manager;
			return $cct_m->get_content_types( 'families' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
