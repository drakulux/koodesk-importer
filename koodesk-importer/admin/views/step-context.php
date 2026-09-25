<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-context.php
 * Variables: $import_type, $profile_id, $token, $mapping,
 *            $session_term_pairs, $has_multiple_terms, $parsed,
 *            $profile_mapping, $start_over_url
 */

// Cap how many rows we build a client-side preview payload for — keeps the
// page light on very large files. Above this, the picker is hidden and the
// review just falls back to the first row like before.
$kd_context_preview_row_cap = 500;

/**
 * Look up a value from a given row by column name. Falls back to
 * case-insensitive/whitespace-normalised comparison because the stored
 * mapping may differ slightly from the CSV header (e.g. double spaces,
 * stripped apostrophes).
 */
$get_example_from = function( string $col, array $row ): string {
    if ( $col === '' ) return '';
    if ( array_key_exists( $col, $row ) ) {
        return trim( (string) $row[ $col ] );
    }
    $norm = strtolower( trim( preg_replace( '/\s+/', ' ', $col ) ) );
    $norm = preg_replace( '/[^a-z0-9\s]/', ' ', $norm );
    $norm = trim( preg_replace( '/\s+/', ' ', $norm ) );
    foreach ( $row as $k => $v ) {
        $k_norm = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $k ) ) );
        $k_norm = preg_replace( '/[^a-z0-9\s]/', ' ', $k_norm );
        $k_norm = trim( preg_replace( '/\s+/', ' ', $k_norm ) );
        if ( $k_norm === $norm ) {
            return trim( (string) $v );
        }
    }
    return '';
};

// Example row — defaults to the first student row; the dropdown below (JS)
// swaps the displayed values without a page reload once rendered.
$example_row = $parsed['rows'][0] ?? [];
$get_example = function( string $col ) use ( $get_example_from, $example_row ): string {
    return $get_example_from( $col, $example_row );
};

/**
 * Render an example-value cell with a data attribute so the row-picker JS
 * can find and update it later. Always a single <span> (rather than
 * separate <span>/<em> tags) so swapping rows only ever needs to toggle a
 * class + text content, never the tag itself.
 */
function kd_render_example_cell( string $col, string $example ): void {
    $is_empty = ( $example === '' );
    $cls = 'kd-example-value' . ( $is_empty ? ' is-empty' : '' );
    $text = $is_empty ? 'empty' : $example;
    echo '<span class="' . esc_attr( $cls ) . '" data-kd-col="' . esc_attr( $col ) . '">' . esc_html( $text ) . '</span>';
}

// ── Collect every CSV column actually referenced in the mapping review,
//    so the client-side preview payload only carries what's needed. ──────
$referenced_cols = [];
foreach ( $profile_mapping['student_fields'] ?? [] as $csv_col => $db_field ) $referenced_cols[] = $csv_col;
if ( ! empty( $profile_mapping['term_col'] ) )    $referenced_cols[] = $profile_mapping['term_col'];
if ( ! empty( $profile_mapping['session_col'] ) ) $referenced_cols[] = $profile_mapping['session_col'];
foreach ( $profile_mapping['subjects'] ?? [] as $subj ) {
    foreach ( $subj['assessments'] ?? [] as $a ) {
        if ( ! empty( $a['col'] ) ) $referenced_cols[] = $a['col'];
    }
    foreach ( [ 'total_col', 'grade_col', 'remark_col', 'position_col' ] as $k ) {
        if ( ! empty( $subj[ $k ] ) ) $referenced_cols[] = $subj[ $k ];
    }
}
foreach ( $profile_mapping['summary_fields'] ?? [] as $csv_col => $db_field ) $referenced_cols[] = $csv_col;
$referenced_cols = array_values( array_unique( $referenced_cols ) );

// ── Build the row-picker's option list + per-row preview payload. ────────
// Name resolution respects both modes — First/Last (either mapped) takes
// priority since it's lossless, Full Name is the fallback.
$name_first_col = array_search( 'first_name', $profile_mapping['student_fields'] ?? [], true ) ?: '';
$name_last_col  = array_search( 'last_name',  $profile_mapping['student_fields'] ?? [], true ) ?: '';
$name_full_col  = array_search( 'full_name',  $profile_mapping['student_fields'] ?? [], true ) ?: '';
$resolve_row_label = function( array $row ) use ( $name_first_col, $name_last_col, $name_full_col ): string {
    if ( $name_first_col || $name_last_col ) {
        $first = $name_first_col ? trim( (string) ( $row[ $name_first_col ] ?? '' ) ) : '';
        $last  = $name_last_col  ? trim( (string) ( $row[ $name_last_col ]  ?? '' ) ) : '';
        return trim( $first . ' ' . $last );
    }
    return $name_full_col ? trim( (string) ( $row[ $name_full_col ] ?? '' ) ) : '';
};
$show_row_picker = count( $parsed['rows'] ) > 1 && count( $parsed['rows'] ) <= $kd_context_preview_row_cap;
$preview_rows = [];
if ( $show_row_picker ) {
    foreach ( $parsed['rows'] as $i => $row ) {
        $label = $resolve_row_label( $row );
        if ( $label === '' ) $label = 'Row ' . ( $i + 1 );
        $values = [];
        foreach ( $referenced_cols as $col ) {
            $values[ $col ] = $get_example_from( $col, $row );
        }
        $preview_rows[] = [ 'label' => $label, 'values' => $values ];
    }
}
?>

<div class="kd-section">
    <h3>Step 3 — Confirm Context</h3>

    <?php if ( $has_multiple_terms ) : ?>
    <div class="notice notice-error" style="margin:0 0 1rem">
        <p><strong>Multiple terms or sessions detected in this file.</strong>
        The importer expects one file per class per term. Please split the file and re-upload.</p>
    </div>
    <?php else : ?>

    <p>Check the detected import context and review the full column mapping below before continuing.</p>

    <?php $pair = $session_term_pairs[0] ?? []; ?>
    <table class="kd-table" style="max-width:520px">
        <tr><th style="width:180px">Session</th>
            <td><strong><?php echo esc_html( $pair['session'] ?? '—' ); ?></strong>
                <?php if ( empty($pair['session']) ) echo '<span class="kd-badge kd-badge--red" style="margin-left:6px">Not detected — check Session column</span>'; ?>
            </td>
        </tr>
        <tr><th>Term</th>
            <td><strong><?php echo esc_html( $pair['term_raw'] ?? '—' ); ?></strong>
                <?php
                $term_map = $mapping['term_value_map'] ?? [];
                $raw = $pair['term_raw'] ?? '';
                $int_val = $term_map[ $raw ] ?? null;
                if ( $int_val ) echo '<span class="kd-badge kd-badge--green" style="margin-left:6px">→ Term ' . intval( $int_val ) . '</span>';
                elseif ( $raw ) echo '<span class="kd-badge kd-badge--red" style="margin-left:6px">Cannot resolve — check term_value_map</span>';
                ?>
            </td>
        </tr>
        <tr><th>Student rows</th><td><?php echo intval( $parsed['filtered_row_count'] ); ?></td></tr>
        <tr><th>Subjects mapped</th><td><?php echo count( $mapping['subjects'] ?? [] ); ?></td></tr>
        <tr><th>Import type</th><td><?php echo esc_html( $import_type ); ?></td></tr>
    </table>

    <!-- ── Full mapping review with example values ──────────────────────── -->
    <?php if ( ! empty( $profile_mapping ) ) : ?>
    <details style="margin-top:1.5rem" open>
        <summary style="cursor:pointer;font-weight:700;font-size:14px">
            Full Column Mapping Review — <span style="font-weight:normal;color:#666">using <span id="kd-context-row-desc">first student</span> as example</span>
        </summary>

        <div style="margin-top:.85rem">

            <?php if ( $show_row_picker ) : ?>
            <div style="margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <label for="kd-context-row-select" style="font-size:12px;font-weight:600;color:var(--tertiary)">Preview a different row:</label>
                <select id="kd-context-row-select" class="kd-select" style="max-width:280px;font-size:13px">
                    <?php foreach ( $preview_rows as $i => $pr ) : ?>
                        <option value="<?php echo intval( $i ); ?>"<?php selected( $i, 0 ); ?>><?php echo esc_html( $pr['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Student fields -->
            <h4 style="font-size:13px;margin:.5rem 0 .4rem;color:var(--text-body)">Student &amp; Context Fields</h4>
            <table class="kd-table" style="max-width:780px">
                <thead>
                    <tr>
                        <th style="width:170px">DB Field</th>
                        <th style="width:220px">CSV Column</th>
                        <th>Example value</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $profile_mapping['student_fields'] ?? [] as $csv_col => $db_field ) : ?>
                <tr>
                    <td><code><?php echo esc_html($db_field); ?></code></td>
                    <td><?php echo esc_html($csv_col); ?></td>
                    <td><?php kd_render_example_cell( $csv_col, $get_example( $csv_col ) ); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! empty( $profile_mapping['term_col'] ) ) : ?>
                <tr>
                    <td><code>term</code></td>
                    <td><?php echo esc_html($profile_mapping['term_col']); ?></td>
                    <td><?php kd_render_example_cell( $profile_mapping['term_col'], $get_example( $profile_mapping['term_col'] ) ); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( ! empty( $profile_mapping['session_col'] ) ) : ?>
                <tr>
                    <td><code>session</code></td>
                    <td><?php echo esc_html($profile_mapping['session_col']); ?></td>
                    <td><?php kd_render_example_cell( $profile_mapping['session_col'], $get_example( $profile_mapping['session_col'] ) ); ?></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Subjects -->
            <?php if ( ! empty( $profile_mapping['subjects'] ) ) : ?>
            <h4 style="font-size:13px;margin:1.2rem 0 .4rem;color:var(--text-body)">Subject Mappings (<?php echo count($profile_mapping['subjects']); ?>)</h4>
            <?php foreach ( $profile_mapping['subjects'] as $subj ) : ?>
            <div class="kd-subject-block" style="margin-bottom:.75rem">
                <h4 style="margin:0 0 .4rem;font-size:13px"><?php echo esc_html( $subj['subject_name'] ); ?></h4>
                <table class="kd-table" style="max-width:780px">
                    <thead><tr>
                        <th style="width:120px">Assessment</th>
                        <th style="width:220px">CSV Column</th>
                        <th>Example value</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $subj['assessments'] as $a ) : ?>
                    <tr>
                        <td><?php echo esc_html($a['label']); ?></td>
                        <td><code><?php echo esc_html($a['col']); ?></code></td>
                        <td><?php kd_render_example_cell( $a['col'], $get_example( $a['col'] ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php
                    $meta_cols = [
                        'total_col'    => 'Total',
                        'grade_col'    => 'Grade',
                        'remark_col'   => 'Remark',
                        'position_col' => 'Position',
                    ];
                    foreach ( $meta_cols as $key => $label ) :
                        if ( empty( $subj[ $key ] ) ) continue;
                    ?>
                    <tr>
                        <td><?php echo $label; ?></td>
                        <td><code><?php echo esc_html($subj[$key]); ?></code></td>
                        <td><?php kd_render_example_cell( $subj[ $key ], $get_example( $subj[ $key ] ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Summary fields -->
            <?php if ( ! empty( $profile_mapping['summary_fields'] ) ) : ?>
            <h4 style="font-size:13px;margin:1.2rem 0 .4rem;color:var(--text-body)">Term Summary Fields</h4>
            <table class="kd-table" style="max-width:780px">
                <thead><tr>
                    <th style="width:170px">DB Field</th>
                    <th style="width:220px">CSV Column</th>
                    <th>Example value</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $profile_mapping['summary_fields'] as $csv_col => $db_field ) : ?>
                <tr>
                    <td><code><?php echo esc_html($db_field); ?></code></td>
                    <td><?php echo esc_html($csv_col); ?></td>
                    <td><?php kd_render_example_cell( $csv_col, $get_example( $csv_col ) ); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Total marks obtainable (calculated, always shown) -->
            <?php if ( isset( $total_marks_calculated ) && $total_marks_calculated > 0 ) : ?>
            <p style="margin:.6rem 0 0;font-size:13px;color:var(--text-body)">
                <strong style=" color: var(--tertiary)">Total marks obtainable (calculated):</strong>
                <?php echo intval( $total_marks_calculated ); ?>
                <?php if ( ! empty( $total_marks_all_real_max ) ) : ?>
                    <span style="color:#888">(from the max scores you set for each assessment label — used when no total marks column is mapped)</span>
                <?php elseif ( ! empty( $total_marks_any_real_max ) ) : ?>
                    <span style="color:#888">(using your mapped max scores where set; subjects without a matching label assume 100 — used when no total marks column is mapped)</span>
                <?php else : ?>
                    <span style="color:#888">(<?php echo intval( $subject_count_estimated ); ?> subjects × 100 — set max scores on the Map Columns step for an accurate total; used when no total marks column is mapped)</span>
                <?php endif; ?>
            </p>
            <?php endif; ?>
            <?php endif; ?>

        </div><!-- /mapping review -->
    </details>
    <?php endif; ?>

    <form method="post" style="margin-top:1.5rem">
        <input type="hidden" name="kd_step"     value="match">
        <input type="hidden" name="kd_token"    value="<?php echo esc_attr( $token ); ?>">
        <input type="hidden" name="import_type" value="<?php echo esc_attr( $import_type ); ?>">
        <input type="hidden" name="profile_id"  value="<?php echo esc_attr( $profile_id ); ?>">

        <p class="submit">
            <button type="submit" class="button button-primary">Mapping looks correct — Continue to Student Matching →</button>
            <button type="submit" name="kd_step" value="classify" class="button kd-back-btn" formnovalidate>← Back to Map Columns</button>
            <a href="<?php echo esc_url( $start_over_url ); ?>" class="button">Start Over</a>
        </p>
    </form>

    <?php endif; ?>
</div>

<?php if ( $show_row_picker ) : ?>
<style>
.kd-example-value { font-size: 12px; }
.kd-example-value.is-empty { color: #999; font-style: italic; }
</style>
<script>
(function(){
    var previewRows = <?php echo wp_json_encode( $preview_rows ); ?>;
    var select = document.getElementById('kd-context-row-select');
    var desc   = document.getElementById('kd-context-row-desc');
    if (!select) return;

    function applyRow(idx) {
        var row = previewRows[idx];
        if (!row) return;

        if (desc) desc.textContent = row.label;

        Object.keys(row.values).forEach(function(col) {
            var val = row.values[col];
            var isEmpty = (val === '');
            document.querySelectorAll('[data-kd-col]').forEach(function(el) {
                if (el.getAttribute('data-kd-col') !== col) return;
                el.textContent = isEmpty ? 'empty' : val;
                el.classList.toggle('is-empty', isEmpty);
            });
        });
    }

    select.addEventListener('change', function() {
        applyRow(parseInt(this.value, 10));
    });
})();
</script>
<?php endif; ?>
