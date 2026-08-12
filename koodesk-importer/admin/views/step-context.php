<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-context.php
 * Variables: $import_type, $profile_id, $token, $mapping,
 *            $session_term_pairs, $has_multiple_terms, $parsed,
 *            $profile_mapping, $start_over_url
 */

// Example row — first student row, used to show what will actually be imported
$example_row = $parsed['rows'][0] ?? [];

/**
 * Look up a value from the example row by column name.
 * Falls back to case-insensitive and trimmed comparison because the mapping
 * may have been stored with slightly different whitespace than the CSV header.
 */
$get_example = function( string $col ) use ( $example_row ): string {
    if ( $col === '' ) return '';
    // 1. Exact match
    if ( array_key_exists( $col, $example_row ) ) {
        return trim( (string) $example_row[ $col ] );
    }
    // 2. Normalised fallback: collapse multiple spaces, lowercase, strip punctuation.
    //    Handles both:
    //    - Stored col has single space, CSV header has double space (e.g. "CCA  1st CA")
    //    - Stored col has apostrophe stripped (e.g. "PRINCIPAL'S COMMENT")
    $norm = strtolower( trim( preg_replace( '/\s+/', ' ', $col ) ) );
    $norm = preg_replace( '/[^a-z0-9\s]/', ' ', $norm );
    $norm = trim( preg_replace( '/\s+/', ' ', $norm ) );
    foreach ( $example_row as $k => $v ) {
        $k_norm = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $k ) ) );
        $k_norm = preg_replace( '/[^a-z0-9\s]/', ' ', $k_norm );
        $k_norm = trim( preg_replace( '/\s+/', ' ', $k_norm ) );
        if ( $k_norm === $norm ) {
            return trim( (string) $v );
        }
    }
    return '';
};
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
            Full Column Mapping Review — <span style="font-weight:normal;color:#666">using first student as example</span>
        </summary>

        <div style="margin-top:.85rem">

            <!-- Student fields -->
            <h4 style="font-size:13px;margin:.5rem 0 .4rem;color:var(--text-body)">Student &amp; Context Fields</h4>
            <table class="kd-table" style="max-width:780px">
                <thead>
                    <tr>
                        <th style="width:170px">DB Field</th>
                        <th style="width:220px">CSV Column</th>
                        <th>Example value (first student)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $profile_mapping['student_fields'] ?? [] as $csv_col => $db_field ) :
                    $example = $get_example( $csv_col );
                ?>
                <tr>
                    <td><code><?php echo esc_html($db_field); ?></code></td>
                    <td><?php echo esc_html($csv_col); ?></td>
                    <td>
                        <?php if ( $example !== '' ) : ?>
                            <span style="font-size:12px"><?php echo esc_html($example); ?></span>
                        <?php else : ?>
                            <em style="color:#999;font-size:12px">empty</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ( ! empty( $profile_mapping['term_col'] ) ) :
                    $example = $get_example( $profile_mapping['term_col'] ) ?? '';
                ?>
                <tr>
                    <td><code>term</code></td>
                    <td><?php echo esc_html($profile_mapping['term_col']); ?></td>
                    <td><?php if ( $example !== '' ) echo '<span style="font-size:12px">' . esc_html($example) . '</span>'; else echo '<em style="color:#999;font-size:12px">empty</em>'; ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( ! empty( $profile_mapping['session_col'] ) ) :
                    $example = $get_example( $profile_mapping['session_col'] ) ?? '';
                ?>
                <tr>
                    <td><code>session</code></td>
                    <td><?php echo esc_html($profile_mapping['session_col']); ?></td>
                    <td><?php if ( $example !== '' ) echo '<span style="font-size:12px">' . esc_html($example) . '</span>'; else echo '<em style="color:#999;font-size:12px">empty</em>'; ?></td>
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
                    <?php foreach ( $subj['assessments'] as $a ) :
                        $example = $get_example( $a['col'] ) ?? '';
                    ?>
                    <tr>
                        <td><?php echo esc_html($a['label']); ?></td>
                        <td><code><?php echo esc_html($a['col']); ?></code></td>
                        <td>
                            <?php if ( $example !== '' ) : ?>
                                <span style="font-size:12px"><?php echo esc_html($example); ?></span>
                            <?php else : ?>
                                <em style="color:#999;font-size:12px">empty</em>
                            <?php endif; ?>
                        </td>
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
                        $example = $get_example( $subj[$key] ) ?? '';
                    ?>
                    <tr>
                        <td><?php echo $label; ?></td>
                        <td><code><?php echo esc_html($subj[$key]); ?></code></td>
                        <td>
                            <?php if ( $example !== '' ) : ?>
                                <span style="font-size:12px"><?php echo esc_html($example); ?></span>
                            <?php else : ?>
                                <em style="color:#999;font-size:12px">empty</em>
                            <?php endif; ?>
                        </td>
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
                <?php foreach ( $profile_mapping['summary_fields'] as $csv_col => $db_field ) :
                    $example = $get_example( $csv_col );
                ?>
                <tr>
                    <td><code><?php echo esc_html($db_field); ?></code></td>
                    <td><?php echo esc_html($csv_col); ?></td>
                    <td>
                        <?php if ( $example !== '' ) : ?>
                            <span><?php echo esc_html($example); ?></span>
                        <?php else : ?>
                            <em style="color:#999;font-size:12px">empty</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Total marks obtainable (calculated, always shown) -->
            <?php if ( isset( $total_marks_calculated ) && $total_marks_calculated > 0 ) : ?>
            <p style="margin:.6rem 0 0;font-size:13px;color:var(--text-body)">
                <strong style=" color: var(--tertiary)">Total marks obtainable (calculated):</strong>
                <?php echo intval( $total_marks_calculated ); ?>
                <span style="color:#888">(<?php echo intval( $subject_count_estimated ); ?> subjects × 100 — used when no total marks column is mapped)</span>
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
