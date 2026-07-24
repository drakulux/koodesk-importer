<?php
/**
 * Koodesk Importer – ImportHistory
 *
 * Persists a log of every completed import in a WP option so that admins
 * can review past imports and trigger a reversal from the Import History page
 * even after navigating away from the import result screen.
 *
 * Storage: wp_options row 'kd_import_history' — a JSON-encoded array of entries,
 * newest first, capped at 50 entries to avoid unbounded growth.
 *
 * Each entry:
 * {
 *   "id":               "kd_import_abc123",       // unique key
 *   "import_time":      1717000000,
 *   "import_type":      "academic_records",
 *   "profile_id":       7,
 *   "profile_name":     "JSS1A Term Result",
 *   "session":          "2025/2026",
 *   "term":             1,
 *   "class_name":       "JSS 1A",
 *   "students_created": 0,
 *   "records_inserted": 42,
 *   "records_updated":  3,
 *   "summaries_inserted":2,
 *   "rows_skipped":     1,
 *   "new_student_ids":  [...],
 *   "record_ids":       [...],       // IDs of INSERTED records only (not updated)
 *   "summary_ids":      [...],
 *   "reversed":         false,
 *   "reversed_at":      null
 * }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Import_History {

	const OPTION_KEY = 'kd_import_history';
	const MAX_ENTRIES = 50;

	// -------------------------------------------------------------------------
	// Record an import
	// -------------------------------------------------------------------------

	public function record(
		array  $result,
		string $import_type,
		int    $profile_id,
		string $profile_name,
		string $session,
		int    $term,
		string $class_name
	): string {
		$id = 'kd_import_' . wp_generate_password( 16, false );

		$entry = [
			'id'                 => $id,
			'import_time'        => time(),
			'import_type'        => $import_type,
			'profile_id'         => $profile_id,
			'profile_name'       => $profile_name,
			'session'            => $session,
			'term'               => $term,
			'class_name'         => $class_name,
			'students_created'   => (int) ( $result['students_created']          ?? 0 ),
			'records_inserted'   => (int) ( $result['academic_records_inserted'] ?? 0 ),
			'records_updated'    => (int) ( $result['academic_records_updated']  ?? 0 ),
			'summaries_inserted' => (int) ( $result['summaries_inserted']        ?? 0 ),
			'rows_skipped'       => (int) ( $result['rows_skipped']              ?? 0 ),
			'new_student_ids'    => array_map( 'intval', $result['inserted_student_ids'] ?? [] ),
			'record_ids'         => array_map( 'intval', $result['inserted_record_ids']  ?? [] ),
			'summary_ids'        => array_map( 'intval', $result['inserted_summary_ids'] ?? [] ),
			'reversed'           => false,
			'reversed_at'        => null,
		];

		$history = $this->get_all();
		array_unshift( $history, $entry );
		// Cap at MAX_ENTRIES
		if ( count( $history ) > self::MAX_ENTRIES ) {
			$history = array_slice( $history, 0, self::MAX_ENTRIES );
		}
		update_option( self::OPTION_KEY, $history, false );

		return $id;
	}

	// -------------------------------------------------------------------------
	// Retrieve
	// -------------------------------------------------------------------------

	public function get_all(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}

	public function get_entry( string $id ): ?array {
		foreach ( $this->get_all() as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $id ) return $entry;
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Mark as reversed
	// -------------------------------------------------------------------------

	public function mark_reversed( string $id ): void {
		$history = $this->get_all();
		foreach ( $history as &$entry ) {
			if ( ( $entry['id'] ?? '' ) === $id ) {
				$entry['reversed']    = true;
				$entry['reversed_at'] = time();
				break;
			}
		}
		unset( $entry );
		update_option( self::OPTION_KEY, $history, false );
	}

	// -------------------------------------------------------------------------
	// Delete a single entry
	// -------------------------------------------------------------------------

	public function delete_entry( string $id ): void {
		$history = array_filter( $this->get_all(), fn( $e ) => ( $e['id'] ?? '' ) !== $id );
		update_option( self::OPTION_KEY, array_values( $history ), false );
	}

	// -------------------------------------------------------------------------
	// Clear all history
	// -------------------------------------------------------------------------

	public function clear_all(): void {
		delete_option( self::OPTION_KEY );
	}
}
