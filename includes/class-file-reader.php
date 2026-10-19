<?php
/**
 * Koodesk Importer – FileReader
 *
 * Handles CSV file upload via wp_handle_upload(), parses the file,
 * filters empty rows, and returns a normalized structure the rest of
 * the importer can work with.
 *
 * XLSX support is deliberately deferred — schools can save as CSV.
 * When XLSX support is added later, only this class needs to change.
 *
 * Return shape from parse():
 * [
 *   'headers' => ['Name', 'Class', 'Eng 1st CA', ...],   // row 0 as strings
 *   'rows'    => [
 *     ['Name' => 'AHMED FIRDAUSI', 'Class' => 'JSS 1A', 'Eng 1st CA' => '24', ...],
 *     ...
 *   ],
 *   'raw_row_count'      => 39,   // total rows in file before filtering
 *   'filtered_row_count' => 17,   // rows after removing blanks
 * ]
 *
 * On error returns:
 * [ 'error' => 'Human-readable message' ]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Koodesk_File_Reader {

	/**
	 * Maximum file size in bytes (5 MB).
	 * Most school result sheets are well under 500 KB.
	 */
	const MAX_FILE_SIZE = 5 * 1024 * 1024;

	/**
	 * Handle a $_FILES upload entry, validate it, store it, and parse it.
	 *
	 * @param array  $file_entry   $_FILES['csv_file'] or equivalent.
	 * @param string $name_col_hint  Header name expected to contain the student
	 *                               name — used to decide if a row is "empty".
	 *                               If empty string, first column is used.
	 * @return array  See class docblock for shape.
	 */
	public function handle_upload_and_parse( array $file_entry, string $name_col_hint = '' ): array {

		// --- basic upload validation ---
		if ( empty( $file_entry['tmp_name'] ) || ! is_uploaded_file( $file_entry['tmp_name'] ) ) {
			return [ 'error' => 'No file was uploaded or the upload was not valid. Please select a CSV file and try again.' ];
		}

		if ( $file_entry['size'] > self::MAX_FILE_SIZE ) {
			return [ 'error' => 'The uploaded file exceeds the 5 MB size limit. Please reduce the file size and try again.' ];
		}

		$ext = strtolower( pathinfo( $file_entry['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) {
			return [ 'error' => 'Only CSV files are supported. Please save your spreadsheet as CSV and re-upload.' ];
		}

		// --- WP safe upload ---
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_handle_upload( $file_entry, [ 'test_form' => false, 'mimes' => [ 'csv' => 'text/csv', 'txt' => 'text/plain' ] ] );

		if ( isset( $upload['error'] ) ) {
			return [ 'error' => 'Upload failed: ' . $upload['error'] ];
		}

		if ( empty( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
			return [ 'error' => 'Upload succeeded but file could not be located. Please try again.' ];
		}

		return $this->parse_file( $upload['file'], $name_col_hint );
	}

	/**
	 * Parse an already-on-disk CSV file.
	 * Caller is responsible for unlinking the file when done.
	 *
	 * @param string $file_path     Absolute path.
	 * @param string $name_col_hint Column header that identifies the student name.
	 * @return array
	 */
	public function parse_file( string $file_path, string $name_col_hint = '' ): array {

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return [ 'error' => 'File not found or not readable: ' . esc_html( $file_path ) ];
		}

		// Detect and strip BOM (Excel CSV exports often include UTF-8 BOM)
		$this->strip_bom( $file_path );

		$handle = @fopen( $file_path, 'r' );
		if ( $handle === false ) {
			return [ 'error' => 'Could not open the CSV file. Check file permissions.' ];
		}

		// Auto-detect delimiter: comma or semicolon (European Excel default)
		$delimiter = $this->detect_delimiter( $file_path );

		// First row = headers
		$raw_headers = fgetcsv( $handle, 0, $delimiter );
		if ( $raw_headers === false || empty( $raw_headers ) ) {
			fclose( $handle );
			return [ 'error' => 'Could not read the header row. Ensure the file is a valid CSV with headers in row 1.' ];
		}

		// Trim whitespace and normalise headers
		$headers = array_map( function( $h ) {
			return trim( (string) $h );
		}, $raw_headers );

		// Remove completely empty header columns (trailing commas in Excel exports)
		$valid_col_indices = [];
		foreach ( $headers as $idx => $h ) {
			if ( $h !== '' ) {
				$valid_col_indices[] = $idx;
			}
		}
		$headers = array_values( array_filter( $headers, fn( $h ) => $h !== '' ) );

		if ( empty( $headers ) ) {
			fclose( $handle );
			return [ 'error' => 'No valid column headers found in row 1.' ];
		}

		// Determine which column index to use for "is this row empty?" check
		$name_col_index = $this->resolve_name_col_index( $headers, $name_col_hint );

		// Read data rows
		$all_rows      = [];
		$filtered_rows = [];
		$raw_row_count = 0;

		while ( ( $raw = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			$raw_row_count++;

			// Build associative row using only valid-header indices
			$row = [];
			foreach ( $valid_col_indices as $col_pos => $orig_idx ) {
				$col_header      = $headers[ $col_pos ];
				$row[ $col_header ] = isset( $raw[ $orig_idx ] ) ? trim( (string) $raw[ $orig_idx ] ) : '';
			}

			$all_rows[] = $row;

			// Filter: skip rows where the name column is empty or just dashes/zeros
			if ( $this->row_is_empty( $row, $name_col_index !== null ? $headers[ $name_col_index ] : null ) ) {
				continue;
			}

			$filtered_rows[] = $row;
		}

		fclose( $handle );

		if ( empty( $filtered_rows ) ) {
			return [ 'error' => 'No student data rows found after filtering empty rows. Check that the Name column is populated.' ];
		}

		return [
			'headers'            => $headers,
			'rows'               => $filtered_rows,
			'raw_row_count'      => $raw_row_count,
			'filtered_row_count' => count( $filtered_rows ),
			'file_path'          => $file_path,
			'delimiter'          => $delimiter,
		];
	}

	/**
	 * Extract unique (session, term_raw) pairs from data rows.
	 * Returns array of [ 'session' => '2025/2026', 'term_raw' => 'First' ].
	 * Used to warn admin if the file contains multiple terms or sessions.
	 *
	 * @param array  $rows          Parsed rows from parse_file().
	 * @param string $session_col   Header of the session column (or '' to skip).
	 * @param string $term_col      Header of the term column (or '' to skip).
	 * @return array
	 */
	public function detect_session_term_values( array $rows, string $session_col, string $term_col ): array {
		$pairs = [];
		foreach ( $rows as $row ) {
			$session  = $session_col && isset( $row[ $session_col ] ) ? trim( $row[ $session_col ] ) : '';
			$term_raw = $term_col    && isset( $row[ $term_col ] )    ? trim( $row[ $term_col ] )    : '';
			$key = $session . '||' . $term_raw;
			if ( ! isset( $pairs[ $key ] ) ) {
				$pairs[ $key ] = [ 'session' => $session, 'term_raw' => $term_raw ];
			}
		}
		return array_values( $pairs );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Strip UTF-8 BOM from start of file in-place.
	 */
	private function strip_bom( string $file_path ): void {
		$content = file_get_contents( $file_path );
		if ( $content === false ) return;
		$bom = "\xEF\xBB\xBF";
		if ( substr( $content, 0, 3 ) === $bom ) {
			file_put_contents( $file_path, substr( $content, 3 ) );
		}
	}

	/**
	 * Detect CSV delimiter by sampling the first line.
	 * Returns ',' or ';'.
	 */
	private function detect_delimiter( string $file_path ): string {
		$handle = @fopen( $file_path, 'r' );
		if ( ! $handle ) return ',';
		$line = fgets( $handle );
		fclose( $handle );
		if ( $line === false ) return ',';
		$comma_count     = substr_count( $line, ',' );
		$semicolon_count = substr_count( $line, ';' );
		return $semicolon_count > $comma_count ? ';' : ',';
	}

	/**
	 * Find the index in $headers that corresponds to the name column.
	 * Tries $name_col_hint first, then looks for common patterns.
	 *
	 * @return int|null
	 */
	private function resolve_name_col_index( array $headers, string $hint ): ?int {
		// Exact match on hint
		if ( $hint !== '' ) {
			$idx = array_search( $hint, $headers, true );
			if ( $idx !== false ) return (int) $idx;
		}

		// Fuzzy match on common name column labels
		$patterns = [ 'name', 'student name', 'full name', 'pupil name', 'student' ];
		foreach ( $headers as $i => $h ) {
			if ( in_array( strtolower( $h ), $patterns, true ) ) {
				return $i;
			}
		}

		// Default: first column
		return 0;
	}

	/**
	 * Decide if a data row should be filtered out as "empty".
	 * A row is empty if the name column is blank, or looks like a
	 * placeholder (just dashes, zeros, or numbering).
	 *
	 * @param array       $row
	 * @param string|null $name_col  Column header for the name field.
	 * @return bool
	 */
	private function row_is_empty( array $row, ?string $name_col ): bool {
		if ( $name_col === null ) {
			// No name column identified — filter rows that are entirely empty
			foreach ( $row as $val ) {
				if ( $val !== '' ) return false;
			}
			return true;
		}

		$name = trim( $row[ $name_col ] ?? '' );

		if ( $name === '' ) return true;

		// Common placeholders schools leave in their templates
		$placeholders = [ '-', '--', '---', 'n/a', 'nil', '0', 'name' ];
		if ( in_array( strtolower( $name ), $placeholders, true ) ) return true;

		// Pure numeric "name" is likely a row number, not a student
		if ( is_numeric( $name ) ) return true;

		return false;
	}
}
