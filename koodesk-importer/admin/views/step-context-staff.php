<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-context-staff.php
 * Variables: $import_type, $profile_id, $token, $mapping, $parsed,
 *            $profile_mapping, $start_over_url
 */

$kd_context_preview_row_cap = 500;

$get_example_from = function( string $col, array $row ): string {
    if ( $col === '' ) return '';
    if ( array_key_exists( $col, $row ) ) {
        return trim( (string) $row[ $col ] );
    }
    $norm = strtolower( trim( preg_replace( '/\s+/', ' ', $col ) ) );
    foreach ( $row as $k => $v ) {
        $k_norm = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $k ) ) );
        if ( $k_norm === $norm ) return trim( (string) $v );
    }
    return '';
};

$example_row = $parsed['rows'][0] ?? [];
$get_example = function( string $col ) use ( $get_example_from, $example_row ): string {
    return $get_example_from( $col, $example_row );
};

function kd_render_staff_example_cell( string $col, string $example ): void {
    $is_empty = ( $example === '' );
    $cls = 'kd-example-value' . ( $is_empty ? ' is-empty' : '' );
    $text = $is_empty ? 'empty' : $example;
    echo '<span class="' . esc_attr( $cls ) . '" data-kd-col="' . esc_attr( $col ) . '">' . esc_html( $text ) . '</span>';
}

// ── Collect every CSV column actually referenced in the review. ──────────
$referenced_cols = [];
foreach ( $profile_mapping['staff_fields'] ?? [] as $csv_col => $db_field ) $referenced_cols[] = $csv_col;
foreach ( [ 'role_col', 'qualification_col', 'section_col', 'subject_col' ] as $k ) {
    if ( ! empty( $profile_mapping[ $k ] ) ) $referenced_cols[] = $profile_mapping[ $k ];
}
$referenced_cols = array_values( array_unique( $referenced_cols ) );

// ── Row-picker option list + per-row preview payload. Name resolution
//    respects both modes — First/Last (either mapped) takes priority,
//    Full Name is the fallback. ────────────────────────────────────────
$first_col = array_search( 'first_name', $profile_mapping['staff_fields'] ?? [], true ) ?: '';
$last_col  = array_search( 'last_name',  $profile_mapping['staff_fields'] ?? [], true ) ?: '';
$full_col  = array_search( 'full_name',  $profile_mapping['staff_fields'] ?? [], true ) ?: '';
$show_row_picker = count( $parsed['rows'] ) > 1 && count( $parsed['rows'] ) <= $kd_context_preview_row_cap;
$preview_rows = [];
if ( $show_row_picker ) {
    foreach ( $parsed['rows'] as $i => $row ) {
        if ( $first_col || $last_col ) {
            $first = $first_col ? trim( (string) ( $row[ $first_col ] ?? '' ) ) : '';
            $last  = $last_col  ? trim( (string) ( $row[ $last_col ]  ?? '' ) ) : '';
            $label = trim( $first . ' ' . $last );
        } else {
            $label = $full_col ? trim( (string) ( $row[ $full_col ] ?? '' ) ) : '';
        }
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
    <h3>Step 3 — Confirm Mapping</h3>
    <p>Review the mapping below, then continue.</p>

    <table class="kd-table" style="max-width:520px">
        <tr><th style="width:180px">Staff rows</th><td><?php echo intval( $parsed['filtered_row_count'] ); ?></td></tr>
        <tr><th>Import type</th><td>Staff</td></tr>
    </table>

    <details style="margin-top:1.25rem" open>
        <summary style="cursor:pointer;font-weight:700;font-size:14px">
            Full Column Mapping Review — <span style="font-weight:normal;color:#666">using <span id="kd-context-row-desc">first row</span> as example</span>
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

            <table class="kd-table" style="max-width:780px">
                <thead>
                    <tr>
                        <th style="width:170px">Field</th>
                        <th style="width:220px">CSV Column</th>
                        <th>Example value</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $profile_mapping['staff_fields'] ?? [] as $csv_col => $db_field ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $db_field ); ?></code></td>
                    <td><?php echo esc_html( $csv_col ); ?></td>
                    <td><?php kd_render_staff_example_cell( $csv_col, $get_example( $csv_col ) ); ?></td>
                </tr>
                <?php endforeach; ?>

                <?php if ( ! empty( $profile_mapping['role_col'] ) ) : ?>
                <tr>
                    <td><code>staff_role</code></td>
                    <td><?php echo esc_html( $profile_mapping['role_col'] ); ?></td>
                    <td><?php kd_render_staff_example_cell( $profile_mapping['role_col'], $get_example( $profile_mapping['role_col'] ) ); ?></td>
                </tr>
                <?php endif; ?>

                <?php if ( ! empty( $profile_mapping['qualification_col'] ) ) : ?>
                <tr>
                    <td><code>qualification</code></td>
                    <td><?php echo esc_html( $profile_mapping['qualification_col'] ); ?></td>
                    <td><?php kd_render_staff_example_cell( $profile_mapping['qualification_col'], $get_example( $profile_mapping['qualification_col'] ) ); ?></td>
                </tr>
                <?php endif; ?>

                <?php if ( ! empty( $profile_mapping['section_col'] ) ) : ?>
                <tr>
                    <td><code>school_section (89)</code></td>
                    <td><?php echo esc_html( $profile_mapping['section_col'] ); ?></td>
                    <td><?php kd_render_staff_example_cell( $profile_mapping['section_col'], $get_example( $profile_mapping['section_col'] ) ); ?></td>
                </tr>
                <?php endif; ?>

                <?php if ( ! empty( $profile_mapping['subject_col'] ) ) : ?>
                <tr>
                    <td><code>subjects (139)</code></td>
                    <td><?php echo esc_html( $profile_mapping['subject_col'] ); ?></td>
                    <td><?php kd_render_staff_example_cell( $profile_mapping['subject_col'], $get_example( $profile_mapping['subject_col'] ) ); ?></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </details>

    <form method="post" style="margin-top:1.5rem">
        <input type="hidden" name="kd_step"     value="match">
        <input type="hidden" name="kd_token"    value="<?php echo esc_attr( $token ); ?>">
        <input type="hidden" name="import_type" value="<?php echo esc_attr( $import_type ); ?>">
        <input type="hidden" name="profile_id"  value="<?php echo esc_attr( $profile_id ); ?>">

        <p class="submit">
            <button type="submit" class="button button-primary">Mapping looks correct — Continue to Match Staff →</button>
            <button type="submit" name="kd_step" value="classify" class="button kd-back-btn" formnovalidate>← Back to Map Columns</button>
            <a href="<?php echo esc_url( $start_over_url ); ?>" class="button">Start Over</a>
        </p>
    </form>
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
