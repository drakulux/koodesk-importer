<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-preview.php
 * Variables: $import_type, $profile_id, $token, $mapping,
 *            $plan, $plan_counts, $decisions_token,
 *            $preview_issues, $total_mismatch_subjects, $start_over_url,
 *            $zero_flags (['row_flags'=>[row_num=>true], 'subject_flags'=>[row_num=>[subject_name=>true]]])
 */
$subject_count = count( $mapping['subjects'] ?? [] );
$est_records   = $plan_counts['existing'] * $subject_count + $plan_counts['new'] * $subject_count;

// FIX #4: Group issues by type for display
$mismatch_count = 0;
foreach ( $preview_issues as $issue ) {
    if ( $issue['type'] === 'total_mismatch' ) $mismatch_count++;
}
$has_mismatches = ! empty( $total_mismatch_subjects );

// ── Zero-score flags: build row_num => raw_name lookup from the plan ──────
$zero_flags    = $zero_flags ?? [ 'row_flags' => [], 'subject_flags' => [] ];
$row_flags     = $zero_flags['row_flags']     ?? [];
$subject_flags = $zero_flags['subject_flags'] ?? [];
$row_names     = [];
foreach ( $plan as $p ) {
    $row_names[ $p['row_num'] ] = $p['raw_name'];
}
$has_zero_flags = ! empty( $row_flags ) || ! empty( $subject_flags );
// Rows that are fully flagged don't also need their per-subject flags listed
// separately — the whole row is already excluded by default.
$subject_flags_standalone = array_diff_key( $subject_flags, $row_flags );

// Wording for the plan-counts table below — "student" for academic_records
// (matching students to receive grades) and students-import, "staff" for
// the staff import type.
$noun_singular = ( $import_type === 'staff' ) ? 'staff member' : 'student';
$noun_plural    = ( $import_type === 'staff' ) ? 'staff'        : 'students';
$noun_plural_cap = ucfirst( $noun_plural );
?>

<div class="kd-section">
    <h3>Step 5 — Confirm Import</h3>

    <form method="post" id="kd-preview-form">
        <input type="hidden" name="kd_step"           value="run_import">
        <input type="hidden" name="kd_token"           value="<?php echo esc_attr( $token ); ?>">
        <input type="hidden" name="import_type"        value="<?php echo esc_attr( $import_type ); ?>">
        <input type="hidden" name="profile_id"         value="<?php echo esc_attr( $profile_id ); ?>">
        <input type="hidden" name="kd_decisions_token" value="<?php echo esc_attr( $decisions_token ); ?>">
        <input type="hidden" name="total_resolution"   value="csv" id="kd-total-resolution">
        <?php wp_nonce_field( 'kd_run_import', 'kd_import_nonce' ); ?>

    <!-- Existing records warning -->
    <?php if ( ! empty( $existing_records ) ) : ?>
    <div class="kd-notice kd-notice--warning" style="margin-top:1rem">
        <strong>⚠ <?php echo count( $existing_records ); ?> student(s) already have records for this session and term</strong>
        <p style="margin:.5rem 0 0;font-size:13px">
            Importing will <strong>update</strong> existing records for these students — existing scores and grades will be overwritten.
            Review the list below and use the <em>Skip</em> action on the match step if you do not want to overwrite.
        </p>
        <details style="margin-top:.6rem">
            <summary style="cursor:pointer;font-size:12px;font-weight:600">Show affected students (<?php echo count( $existing_records ); ?>)</summary>
            <table class="kd-table" style="margin-top:.5rem;max-width:640px;font-size:12px">
                <thead><tr><th>Student</th><th>Session</th><th>Term</th><th>Existing records</th></tr></thead>
                <tbody>
                <?php foreach ( $existing_records as $sid => $info ) : ?>
                <tr>
                    <td><?php echo esc_html( $info['name'] ); ?></td>
                    <td><?php echo esc_html( $info['session'] ); ?></td>
                    <td>Term <?php echo intval( $info['term'] ); ?></td>
                    <td><?php echo intval( $info['record_count'] ); ?> subject record(s)</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </div>
    <?php endif; ?>

    <!-- Zero-score flags -->
    <?php if ( $has_zero_flags ) : ?>
    <div class="kd-notice kd-notice--warning" style="margin-bottom:1.25rem">
        <strong>⚠ Zero-score rows/subjects flagged</strong>
        <p style="margin:.5rem 0 0;font-size:13px">
            These students scored zero on every assessment for the subject(s) listed — likely they
            didn't sit that subject (or weren't present that term). They are <strong>excluded from
            this import by default</strong>. Tick any you want to include anyway.
        </p>

        <?php if ( ! empty( $row_flags ) ) : ?>
        <div style="margin-top:.75rem">
            <strong style="font-size:12px">Whole row flagged (all mapped subjects scored zero)</strong>
            <table class="kd-table" style="margin-top:.4rem;max-width:520px">
                <thead><tr><th style="width:60px">Row</th><th>Student</th><th style="width:140px">Include anyway?</th></tr></thead>
                <tbody>
                <?php foreach ( $row_flags as $row_num => $_ ) : ?>
                <tr>
                    <td><?php echo intval( $row_num ); ?></td>
                    <td><?php echo esc_html( $row_names[ $row_num ] ?? '—' ); ?></td>
                    <td>
                        <label style="font-weight:normal;font-size:12px">
                            <input type="checkbox" name="zero_include[rows][<?php echo intval( $row_num ); ?>]" value="1">
                            Include
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $subject_flags_standalone ) ) : ?>
        <div style="margin-top:.9rem">
            <strong style="font-size:12px">Individual subject(s) flagged</strong>
            <table class="kd-table" style="margin-top:.4rem;max-width:620px">
                <thead><tr><th style="width:60px">Row</th><th>Student</th><th>Subject</th><th style="width:140px">Include anyway?</th></tr></thead>
                <tbody>
                <?php foreach ( $subject_flags_standalone as $row_num => $subjects ) : ?>
                    <?php foreach ( $subjects as $subject_name => $_ ) : ?>
                    <tr>
                        <td><?php echo intval( $row_num ); ?></td>
                        <td><?php echo esc_html( $row_names[ $row_num ] ?? '—' ); ?></td>
                        <td><?php echo esc_html( $subject_name ); ?></td>
                        <td>
                            <label style="font-weight:normal;font-size:12px">
                                <input type="checkbox" name="zero_include[subjects][<?php echo intval( $row_num ); ?>][<?php echo esc_attr( $subject_name ); ?>]" value="1">
                                Include
                            </label>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ( $has_mismatches ) : ?>
    <div class="kd-notice kd-notice--warning" style="margin-bottom:1.25rem">
        <strong>⚠ Score total mismatches detected</strong>
        <p style="margin:.5rem 0 0">
            <?php echo count( $total_mismatch_subjects ); ?> subject(s) have rows where the CSV total
            does not match the sum of individual assessment scores. Choose how to handle it below.
        </p>
    </div>
    <?php elseif ( ! $has_zero_flags ) : ?>
    <p style="color:#0a5c2e;font-weight:500">✓ No score issues detected. Review the summary below and click <strong>Run Import</strong> to proceed.</p>
    <?php endif; ?>

    <!-- FIX #4: Enhanced preview table -->
    <table class="kd-table" style="max-width:520px">
        <tr><th><?php echo esc_html( $noun_plural_cap ); ?> matched to existing</th><td><?php echo intval( $plan_counts['existing'] ); ?></td></tr>
        <tr><th>New <?php echo esc_html( $noun_plural ); ?> to create</th>       <td><?php echo intval( $plan_counts['new'] ); ?></td></tr>
        <tr><th>Rows to skip</th>                 <td><?php echo intval( $plan_counts['skip'] ); ?></td></tr>
        <?php if ( $import_type === 'academic_records' ) : ?>
        <tr><th>Subjects mapped</th>              <td><?php echo intval( $subject_count ); ?></td></tr>
        <tr><th>Est. academic records</th>        <td>up to <?php echo intval( $est_records ); ?></td></tr>
        <tr><th>Term summaries</th>               <td>up to <?php echo intval( $plan_counts['existing'] + $plan_counts['new'] ); ?></td></tr>
        <?php if ( $has_mismatches ) : ?>
        <tr style="background:#fff9e6">
            <th style="color:#664d03">⚠ Total mismatches</th>
            <td style="color:#664d03"><?php echo count( $total_mismatch_subjects ); ?> subject(s)</td>
        </tr>
        <?php endif; ?>
        <?php if ( $has_zero_flags ) : ?>
        <tr style="background:#fff9e6">
            <th style="color:#664d03">⚠ Zero-score rows flagged</th>
            <td style="color:#664d03"><?php echo count( $row_flags ); ?> row(s), <?php echo count( $subject_flags_standalone ); ?> row(s) with a flagged subject</td>
        </tr>
        <?php endif; ?>
        <?php endif; ?>
    </table>

    <!-- Subjects being imported — table view -->
    <?php if ( $import_type === 'academic_records' && ! empty( $mapping['subjects'] ) ) : ?>
    <details style="margin-top:1rem" open>
        <summary style="cursor:pointer;font-weight:600">Subjects being imported (<?php echo $subject_count; ?>)</summary>
        <table class="kd-table" style="margin-top:.6rem;font-size:12px">
            <thead>
                <tr>
                    <th style="width:180px">Subject</th>
                    <th>Assessment columns</th>
                    <th style="width:120px">Total col</th>
                    <th style="width:120px">Grade col</th>
                    <th style="width:120px">Remark col</th>
                    <th style="width:120px">Position col</th>
                    <th style="width:80px">Issues</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $mapping['subjects'] as $subj ) :
                $has_mismatch = isset( $total_mismatch_subjects[ $subj['subject_name'] ] );
                $assess_labels = array_map( fn($a) => $a['label'] . ' → ' . $a['col'], $subj['assessments'] );
            ?>
            <tr>
                <td><strong><?php echo esc_html( $subj['subject_name'] ); ?></strong></td>
                <td>
                    <?php foreach ( $subj['assessments'] as $a ) : ?>
                        <span style="display:inline-block;margin:1px 2px;background:var(--list-btn-hover);padding:1px 6px;border-radius:3px;white-space:nowrap">
                            <?php echo esc_html( $a['label'] ); ?> → <code style="font-size:11px"><?php echo esc_html( $a['col'] ); ?></code>
                        </span>
                    <?php endforeach; ?>
                </td>
                <td><?php echo ! empty( $subj['total_col'] )    ? '<code>' . esc_html( $subj['total_col'] )    . '</code>' : '<em style="color:#bbb">—</em>'; ?></td>
                <td><?php echo ! empty( $subj['grade_col'] )    ? '<code>' . esc_html( $subj['grade_col'] )    . '</code>' : '<em style="color:#bbb">—</em>'; ?></td>
                <td><?php echo ! empty( $subj['remark_col'] )   ? '<code>' . esc_html( $subj['remark_col'] )   . '</code>' : '<em style="color:#bbb">—</em>'; ?></td>
                <td><?php echo ! empty( $subj['position_col'] ) ? '<code>' . esc_html( $subj['position_col'] ) . '</code>' : '<em style="color:#bbb">—</em>'; ?></td>
                <td>
                    <?php if ( $has_mismatch ) : ?>
                        <span class="kd-badge kd-badge--orange">⚠ <?php echo intval( $total_mismatch_subjects[$subj['subject_name']]['count'] ); ?> row(s)</span>
                    <?php else : ?>
                        <span class="kd-badge kd-badge--green">✓</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <?php
    // FIX #4: Show detailed mismatch information
    if ( $has_mismatches ) :
    ?>
    <div class="kd-section" style="margin-top:1rem;border-left:3px solid #f0b849;background:#fffbee">
        <h4 style="margin-top:0;color:#664d03">Total / Score Mismatches</h4>
        <p style="font-size:13px">For each subject below, the CSV's "Total" column value differs from the sum of the individual assessment scores. All assessment fields <strong>will be imported</strong>. Choose how to handle the total:</p>
        <table class="kd-table" style="max-width:680px">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Rows affected</th>
                    <th>Example: CSV total vs calculated</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $total_mismatch_subjects as $subj_name => $info ) : ?>
                <tr>
                    <td><?php echo esc_html( $subj_name ); ?></td>
                    <td><?php echo intval( $info['count'] ); ?></td>
                    <td><?php echo esc_html( $info['example_csv'] ); ?> vs <?php echo esc_html( $info['example_calc'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:1rem">
            <strong>How should totals be resolved?</strong><br>
            <label style="display:block;margin-top:.4rem">
                <input type="radio" name="total_resolution_preview" value="csv" checked>
                Use the CSV total as-is (import what the file says)
            </label>
            <label style="display:block;margin-top:.3rem">
                <input type="radio" name="total_resolution_preview" value="calculated">
                Recalculate total from individual scores
            </label>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $skipped = array_filter( $plan, fn($p) => $p['status'] === 'skip' );
    if ( ! empty( $skipped ) ) :
    ?>
    <details style="margin-top:1rem">
        <summary style="cursor:pointer;font-weight:600">Skipped rows (<?php echo count($skipped); ?>)</summary>
        <table class="kd-table" style="margin-top:.5rem">
            <thead><tr><th>Row</th><th>Name</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ( $skipped as $s ) : ?>
                <tr>
                    <td><?php echo intval( $s['row_num'] ); ?></td>
                    <td><?php echo esc_html( $s['raw_name'] ); ?></td>
                    <td><?php echo esc_html( $s['reason'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <p class="submit" style="margin-top:1.5rem">
        <button type="submit" class="button button-primary button-hero">Run Import</button>
        <button type="submit" name="kd_step" value="match" class="button kd-back-btn" formnovalidate>← Back to Match <?php echo esc_html( $noun_plural_cap ); ?></button>
        <a href="<?php echo esc_url( $start_over_url ); ?>" class="button">Start Over</a>
    </p>
    </form>
</div>

<script>
// Sync the radio buttons to the hidden field that gets submitted
document.querySelectorAll('input[name="total_resolution_preview"]').forEach(function(r) {
    r.addEventListener('change', function() {
        var hidden = document.getElementById('kd-total-resolution');
        if (hidden) hidden.value = this.value;
    });
});
</script>
