<?php
/**
 * Koodesk Importer – Serializer
 *
 * Produces byte-identical PHP serialized strings for JetEngine CCT repeater
 * fields. The assessments repeater stored in wp_jet_cct_academic_record uses
 * the following exact PHP types per key:
 *
 *   label      → string  (s:)
 *   score      → string  (s:)   ← even though it looks numeric, stored as string
 *   max_score  → float   (d:)   ← PHP double
 *   is_locked  → bool    (b:)   ← always b:1 for imported records
 *   updated_at → int     (i:)   ← unix timestamp
 *
 * Example of a correctly serialized 3-item repeater:
 *   a:3:{
 *     s:6:"item-0";a:5:{s:5:"label";s:8:"1st C.A.";s:5:"score";s:2:"25";
 *       s:9:"max_score";d:30;s:9:"is_locked";b:1;s:10:"updated_at";i:1769452120;}
 *     s:6:"item-1";...
 *     s:6:"item-2";...
 *   }
 *
 * This class is dependency-free and fully unit-testable in isolation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_Serializer {

	/**
	 * Build and serialize an assessments repeater from a flat array of items.
	 *
	 * @param array $items  Each item:
	 *   [
	 *     'label'      => string,
	 *     'score'      => string|int|float|null,
	 *     'max_score'  => float|int|null,
	 *     'is_locked'  => bool,        (optional, defaults true for imports)
	 *     'updated_at' => int|null,    (optional, defaults to current time)
	 *   ]
	 * @param int   $import_timestamp  Unix timestamp to use for all items
	 *                                 when updated_at is not supplied.
	 *                                 Defaults to time().
	 *
	 * @return string  PHP serialize() output ready to insert into the DB.
	 */
	public function serialize_assessments( array $items, int $import_timestamp = 0 ): string {

		if ( $import_timestamp <= 0 ) {
			$import_timestamp = time();
		}

		$repeater = [];

		foreach ( $items as $i => $item ) {
			$key = 'item-' . $i;

			// --- type coercion to match JetEngine's stored format exactly ---

			// label: always string
			$label = (string) ( $item['label'] ?? '' );

			// score: always string — even "0" must be s:1:"0" not i:0
			// empty/null score becomes empty string ""
			$raw_score = $item['score'] ?? '';
			$score = ( $raw_score !== null && $raw_score !== '' )
				? (string) $raw_score
				: '';

			// max_score: float (PHP double) — null/missing becomes 0.0
			$max_score = isset( $item['max_score'] ) && $item['max_score'] !== null
				? (float) $item['max_score']
				: 0.0;

			// is_locked: bool — always true for imported historical records
			$is_locked = isset( $item['is_locked'] )
				? (bool) $item['is_locked']
				: true;

			// updated_at: int unix timestamp
			$updated_at = isset( $item['updated_at'] ) && $item['updated_at'] !== null
				? (int) $item['updated_at']
				: $import_timestamp;

			$repeater[ $key ] = [
				'label'      => $label,
				'score'      => $score,
				'max_score'  => $max_score,
				'is_locked'  => $is_locked,
				'updated_at' => $updated_at,
			];
		}

		return serialize( $repeater );
	}

	/**
	 * Build assessment items from mapped CSV data.
	 *
	 * Takes the assessment mappings from a profile (array of
	 * ['label' => ..., 'col' => ..., 'max_score' => ...]) and a CSV row
	 * (keyed by column header), and returns an array ready for
	 * serialize_assessments().
	 *
	 * @param array $assessment_mappings  From profile column_mapping subjects[n]['assessments']:
	 *   [ ['label' => '1st CA', 'col' => 'Eng 1st CA', 'max_score' => null], ... ]
	 * @param array $csv_row             Associative: [ 'Eng 1st CA' => '25', ... ]
	 * @param int   $import_timestamp
	 *
	 * @return array  Items array for serialize_assessments().
	 */
	public function build_assessment_items(
		array $assessment_mappings,
		array $csv_row,
		int $import_timestamp = 0
	): array {

		$items = [];

		foreach ( $assessment_mappings as $mapping ) {
			$label     = (string) ( $mapping['label'] ?? '' );
			$col       = (string) ( $mapping['col'] ?? '' );
			$max_score = isset( $mapping['max_score'] ) && $mapping['max_score'] !== null
				? (float) $mapping['max_score']
				: null;  // null = not known; caller may fill from template later

			// Pull score from the CSV row by the mapped column header
			$score = '';
			if ( $col !== '' && array_key_exists( $col, $csv_row ) ) {
				$raw = trim( (string) $csv_row[ $col ] );
				// Treat dashes, blanks, 'N/A', 'nil' as absent scores
				if ( $raw !== '' && $raw !== '-' && strtolower( $raw ) !== 'n/a' && strtolower( $raw ) !== 'nil' ) {
					$score = $raw;
				}
			}

			$items[] = [
				'label'      => $label,
				'score'      => $score,
				'max_score'  => $max_score ?? 0.0,
				'is_locked'  => true,
				'updated_at' => $import_timestamp > 0 ? $import_timestamp : time(),
			];
		}

		return $items;
	}

	/**
	 * Calculate total score from an array of assessment items.
	 * Only sums items where score is a non-empty numeric string.
	 *
	 * @param array $items  Output of build_assessment_items().
	 * @return float
	 */
	public function calculate_total( array $items ): float {
		$total = 0.0;
		foreach ( $items as $item ) {
			$s = $item['score'] ?? '';
			if ( $s !== '' && is_numeric( $s ) ) {
				$total += (float) $s;
			}
		}
		return $total;
	}

	/**
	 * Calculate total max score from an array of assessment items.
	 * Only sums items where the corresponding score is present
	 * (partial records should not inflate the max).
	 *
	 * @param array $items
	 * @return float
	 */
	public function calculate_max( array $items ): float {
		$max = 0.0;
		foreach ( $items as $item ) {
			$s = $item['score'] ?? '';
			if ( $s !== '' && is_numeric( $s ) ) {
				$max += (float) ( $item['max_score'] ?? 0.0 );
			}
		}
		return $max;
	}

	/**
	 * Verify that our serialization matches PHP's native serialize().
	 * Useful for debugging — call this during dev with a known good record.
	 *
	 * @param string $expected  The serialized string from the DB export.
	 * @param array  $items     The items array you expect to produce it.
	 * @param int    $ts        The timestamp used in that record.
	 * @return bool
	 */
	public function verify( string $expected, array $items, int $ts ): bool {
		$produced = $this->serialize_assessments( $items, $ts );
		return $produced === $expected;
	}
}
