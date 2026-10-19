<?php
/**
 * Koodesk Importer – StaffMatcher
 *
 * Two independent responsibilities, both needed for the staff import type:
 *
 *   1. STAFF DEDUP MATCHING — resolves a staff name (and optional email)
 *      from an import row to an existing `staff` CCT record, mirroring the
 *      tier system in Koodesk_Student_Matcher (email as the "external key"
 *      equivalent, then exact/ambiguous name match).
 *
 *   2. ROLE TEXT MATCHING — staff role is free text in the CSV (e.g.
 *      "Bursar", "Guidance Counselor") but must resolve to a `roles` CCT
 *      _ID (`staff_role` field, singular). Unlike name matching this is a
 *      per-DISTINCT-VALUE dictionary problem, not a per-row problem: the
 *      same handful of role strings repeat across many staff rows, so we
 *      collect distinct role texts once and resolve each to a role_id (or
 *      flag it for the admin to pick manually on the Match Roles screen).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Staff_Matcher {

	/** @var array[] Preloaded existing staff for this import session. */
	private array $cache = [];
	private bool  $cache_loaded = false;

	/** @var array[] Preloaded roles CCT rows ( _ID, role_name ). */
	private array $roles_cache = [];
	private bool  $roles_loaded = false;

	// -------------------------------------------------------------------------
	// Staff dedup matching
	// -------------------------------------------------------------------------

	public function preload(): void {
		global $wpdb;

		$cct = $this->get_staff_cct();
		if ( ! $cct ) return;

		$table = $cct->db->table();

		$rows = $wpdb->get_results(
			"SELECT _ID, full_name, first_name, last_name, staff_email
			 FROM {$table}
			 WHERE cct_status = 'publish'",
			ARRAY_A
		);

		$this->cache = [];
		foreach ( $rows as $row ) {
			$this->cache[] = [
				'_ID'            => (int) $row['_ID'],
				'full_name'      => $row['full_name'],
				'full_name_norm' => $this->normalise_name( $row['full_name'] ),
				'first_name'     => $row['first_name'],
				'last_name'      => $row['last_name'],
				'staff_email'    => $row['staff_email'],
			];
		}

		$this->cache_loaded = true;
	}

	/**
	 * Match a single import row to a staff member.
	 *
	 * @return array {
	 *   status:     'matched'|'ambiguous'|'not_found'
	 *   staff_id:   int
	 *   staff:      array|null
	 *   candidates: array
	 * }
	 */
	public function match( string $raw_name, string $email = '' ): array {
		if ( ! $this->cache_loaded ) $this->preload();

		$base = [
			'status'     => 'not_found',
			'staff_id'   => 0,
			'staff'      => null,
			'candidates' => [],
		];

		// Tier 1: email exact match — staff_email is the closest analogue
		// to external_student_key for staff records.
		if ( $email !== '' ) {
			foreach ( $this->cache as $s ) {
				if ( $s['staff_email'] !== '' && $s['staff_email'] !== null
					&& strtolower( trim( (string) $s['staff_email'] ) ) === strtolower( $email ) ) {
					return array_merge( $base, [ 'status' => 'matched', 'staff_id' => $s['_ID'], 'staff' => $s ] );
				}
			}
		}

		// Tier 2/3: exact normalised full_name match
		$name_norm = $this->normalise_name( $raw_name );
		if ( $name_norm === '' ) return $base;

		$exact = array_values( array_filter( $this->cache, fn( $s ) => $s['full_name_norm'] === $name_norm ) );

		if ( count( $exact ) === 1 ) {
			return array_merge( $base, [ 'status' => 'matched', 'staff_id' => $exact[0]['_ID'], 'staff' => $exact[0] ] );
		}
		if ( count( $exact ) > 1 ) {
			return array_merge( $base, [ 'status' => 'ambiguous', 'candidates' => $exact ] );
		}

		return $base; // not_found
	}

	/**
	 * Match all unique staff names in a set of rows. Returns a keyed array:
	 * normalised_name||email → match result (mirrors Koodesk_Student_Matcher::match_all()).
	 */
	public function match_all( array $rows, string $name_col, string $email_col = '' ): array {
		if ( ! $this->cache_loaded ) $this->preload();

		$results = [];
		foreach ( $rows as $row ) {
			$raw_name = trim( (string) ( $row[ $name_col ] ?? '' ) );
			$email    = $email_col !== '' ? trim( (string) ( $row[ $email_col ] ?? '' ) ) : '';
			$lookup   = $this->normalise_name( $raw_name ) . '||' . $email;

			if ( isset( $results[ $lookup ] ) ) continue;

			$results[ $lookup ] = $this->match( $raw_name, $email );
			$results[ $lookup ]['raw_name'] = $raw_name;
		}

		return $results;
	}

	public function normalise_name( string $name ): string {
		$s = strtolower( trim( $name ) );
		$s = preg_replace( '/[^\p{L}\p{N}\s\-]/u', '', $s );
		$s = preg_replace( '/\s+/', ' ', $s );
		return trim( $s );
	}

	/**
	 * Split a combined full name into first_name/last_name — mirrors
	 * Koodesk_Student_Matcher::split_name(), including the $order
	 * parameter for surname-first naming conventions. Used when a staff
	 * row only provides a single Full Name column rather than separate
	 * First/Last Name columns.
	 *
	 * @param string $order 'first_first' | 'last_first'
	 * @return array{first_name:string,last_name:string,full_name:string}
	 */
	public function split_name( string $full_name, string $order = 'first_first' ): array {
		$name = trim( $full_name );
		if ( $name === '' ) {
			return [ 'first_name' => '', 'last_name' => '', 'full_name' => '' ];
		}

		$parts = preg_split( '/\s+/', $name );

		if ( count( $parts ) === 1 ) {
			return [ 'first_name' => $parts[0], 'last_name' => '', 'full_name' => $name ];
		}

		if ( $order === 'last_first' ) {
			$last_name  = array_shift( $parts );
			$first_name = implode( ' ', $parts );
		} else {
			$last_name  = array_pop( $parts );
			$first_name = implode( ' ', $parts );
		}

		return [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'full_name'  => $name,
		];
	}

	// -------------------------------------------------------------------------
	// Role text matching
	// -------------------------------------------------------------------------

	public function preload_roles(): void {
		global $wpdb;

		$cct = $this->get_roles_cct();
		if ( ! $cct ) return;

		$table = $cct->db->table();

		$rows = $wpdb->get_results(
			"SELECT _ID, role_name FROM {$table} WHERE cct_status = 'publish'",
			ARRAY_A
		);

		$this->roles_cache  = $rows ?: [];
		$this->roles_loaded = true;
	}

	public function get_all_roles(): array {
		if ( ! $this->roles_loaded ) $this->preload_roles();
		return $this->roles_cache;
	}

	/**
	 * Resolve a single role text to a role_id, or null if no confident match.
	 * Exact match (case/whitespace-insensitive) first, then a loose
	 * substring match as a fallback.
	 */
	public function match_role( string $raw_role ): ?int {
		if ( ! $this->roles_loaded ) $this->preload_roles();

		$norm = strtolower( trim( $raw_role ) );
		if ( $norm === '' ) return null;

		foreach ( $this->roles_cache as $r ) {
			if ( strtolower( trim( (string) $r['role_name'] ) ) === $norm ) {
				return (int) $r['_ID'];
			}
		}

		foreach ( $this->roles_cache as $r ) {
			$rn = strtolower( trim( (string) $r['role_name'] ) );
			if ( $rn === '' ) continue;
			if ( str_contains( $norm, $rn ) || str_contains( $rn, $norm ) ) {
				return (int) $r['_ID'];
			}
		}

		return null;
	}

	/**
	 * Collect every DISTINCT role text found in the role column across all
	 * rows and resolve each one once.
	 *
	 * @return array raw_role_text => [ 'status' => 'matched'|'not_found', 'role_id' => int|null, 'role_name' => string ]
	 */
	public function match_all_roles( array $rows, string $role_col ): array {
		if ( $role_col === '' ) return [];
		if ( ! $this->roles_loaded ) $this->preload_roles();

		$results = [];
		foreach ( $rows as $row ) {
			$raw = trim( (string) ( $row[ $role_col ] ?? '' ) );
			if ( $raw === '' || isset( $results[ $raw ] ) ) continue;

			$role_id = $this->match_role( $raw );
			$results[ $raw ] = [
				'status'    => $role_id ? 'matched' : 'not_found',
				'role_id'   => $role_id,
				'role_name' => $role_id ? $this->role_name_by_id( $role_id ) : '',
			];
		}

		return $results;
	}

	private function role_name_by_id( int $id ): string {
		foreach ( $this->roles_cache as $r ) {
			if ( (int) $r['_ID'] === $id ) return (string) $r['role_name'];
		}
		return '';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_staff_cct() {
		if ( ! function_exists( 'jet_engine' ) ) return null;
		try {
			$manager = jet_engine()->modules->get_module( 'custom-content-types' );
			if ( ! $manager ) return null;
			return $manager->instance->manager->get_content_types( 'staff' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function get_roles_cct() {
		if ( ! function_exists( 'jet_engine' ) ) return null;
		try {
			$manager = jet_engine()->modules->get_module( 'custom-content-types' );
			if ( ! $manager ) return null;
			return $manager->instance->manager->get_content_types( 'roles' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
