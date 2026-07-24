<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-match.php
 * Variables: $import_type, $start_over_url, $profile_id, $token, $mapping,
 *            $match_results, $parsed, $name_col, $ext_key_col, $matcher
 */

$total     = count( $match_results );
$matched   = count( array_filter( $match_results, fn($r) => $r['status'] === 'matched' ) );
$ambiguous = count( array_filter( $match_results, fn($r) => $r['status'] === 'ambiguous' ) );
$not_found = count( array_filter( $match_results, fn($r) => $r['status'] === 'not_found' ) );

/**
 * Returns the student's display identifier — prefer reg number or external key,
 * never show internal _ID.
 */
function kd_student_display_id( array $student ): string {
    if ( ! empty( $student['student_reg_number'] ) ) {
        return 'Reg: ' . $student['student_reg_number'];
    }
    if ( ! empty( $student['external_student_key'] ) ) {
        return 'Adm: ' . $student['external_student_key'];
    }
    return '';
}
?>

<div class="kd-section">
    <h3>Step 4 — Match Students</h3>

    <?php if ( $import_type === 'students' ) : ?>
    <p>
        Review how each student in the file matches to existing Koodesk records.
        For <strong>students</strong>, matching lets you update existing student profiles (e.g. add missing DOB, gender, guardian details)
        instead of creating duplicates.
        <strong><?php echo $matched; ?> matched</strong>,
        <strong><?php echo $ambiguous; ?> need review</strong>,
        <strong><?php echo $not_found; ?> not found</strong>.
    </p>
    <p class="description">
        <strong>Matched (green):</strong> will update the existing student's profile fields.<br>
        <strong>Ambiguous (yellow):</strong> select the correct student, or create new / skip.<br>
        <strong>Not found (red):</strong> create as a new student, or skip.
    </p>
    <?php else : ?>
    <p>
        Review how each student in the file matches to an existing Koodesk student record.
        <strong><?php echo $matched; ?> matched</strong>,
        <strong><?php echo $ambiguous; ?> need review</strong>,
        <strong><?php echo $not_found; ?> not found</strong>.
    </p>
    <p class="description">
        <strong>Matched (green):</strong> records will be imported for this student — change action if needed.<br>
        <strong>Ambiguous (yellow):</strong> select the correct student from the list.<br>
        <strong>Not found (red):</strong> create as a new student, or skip.
    </p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="kd_step"     value="preview">
        <input type="hidden" name="kd_token"    value="<?php echo esc_attr( $token ); ?>">
        <input type="hidden" name="import_type" value="<?php echo esc_attr( $import_type ); ?>">
        <input type="hidden" name="profile_id"  value="<?php echo esc_attr( $profile_id ); ?>">

        <?php if ( $total === 0 ) : ?>
        <div class="kd-notice kd-notice--warning">No student rows found to match.</div>
        <?php else : ?>

        <!-- Quick-action bar -->
        <div style="margin:.75rem 0;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <strong style="font-size:13px">Quick set:</strong>
            <button type="button" class="button button-small" id="kd-bulk-matched">Set all Matched → Use existing</button>
            <button type="button" class="button button-small" id="kd-bulk-notfound">Set all Not Found → Create new</button>
            <button type="button" class="button button-small" id="kd-bulk-skipall">Set all → Skip</button>
        </div>

        <table class="kd-table" style="table-layout:fixed;width:100%">
            <thead>
                <tr>
                    <th style="width:22%">Name in File</th>
                    <th style="width:10%">Status</th>
                    <th style="width:30%">Matched Student</th>
                    <th style="width:22%">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $match_results as $lookup_key => $result ) :
                $status     = $result['status'];
                $raw_name   = $result['raw_name'];
                $student    = $result['student'] ?? null;
                $candidates = $result['candidates'] ?? [];
                $is_students_import = ( $import_type === 'students' );
                $default_matched_action = $is_students_import ? 'use_existing' : 'use_existing';
            ?>
            <tr class="kd-match-row kd-match-row--<?php echo esc_attr($status); ?>">
                <td><strong><?php echo esc_html( $raw_name ); ?></strong></td>
                <td>
                    <?php if ( $status === 'matched' ) : ?>
                        <span class="kd-badge kd-badge--green">Matched</span>
                    <?php elseif ( $status === 'ambiguous' ) : ?>
                        <span class="kd-badge kd-badge--yellow">Ambiguous</span>
                    <?php else : ?>
                        <span class="kd-badge kd-badge--red">Not Found</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( $status === 'matched' && $student ) :
                        $display_id = kd_student_display_id( $student );
                    ?>
                        <span><?php echo esc_html( $student['full_name'] ); ?></span>
                        <?php if ( $display_id ) : ?>
                            <br><small style="color:#666"><?php echo esc_html( $display_id ); ?>
                            <?php if ( ! empty( $student['current_class_name'] ) ) echo ' · ' . esc_html( $student['current_class_name'] ); ?>
                            </small>
                        <?php endif; ?>

                    <?php elseif ( $status === 'ambiguous' ) : ?>
                        <select name="match[<?php echo esc_attr($lookup_key); ?>][student_id]"
                            class="kd-ambig-select" style="max-width:100%;font-size:12px">
                            <option value="">— Select correct student —</option>
                            <?php foreach ( $candidates as $c ) :
                                $cid = kd_student_display_id( $c );
                            ?>
                                <option value="<?php echo intval($c['_ID']); ?>">
                                    <?php echo esc_html( $c['full_name'] ); ?>
                                    <?php if ( $cid ) echo ' (' . esc_html($cid) . ')'; ?>
                                    <?php if ( ! empty($c['current_class_name']) ) echo ' · ' . esc_html($c['current_class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php else : ?>
                        <em style="color:#999;font-size:12px">No match found</em>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    // Determine student_id and default action for this row
                    $row_student_id = 0;
                    $default_action = 'skip';
                    if ( $status === 'matched' ) {
                        $row_student_id = intval( $student['_ID'] ?? 0 );
                        $default_action = 'use_existing';
                    } elseif ( $status === 'ambiguous' ) {
                        $default_action = 'use_existing';
                    } else {
                        $default_action = 'create_new';
                    }
                    ?>
                    <input type="hidden"
                        name="match[<?php echo esc_attr($lookup_key); ?>][student_id]"
                        value="<?php echo $row_student_id; ?>"
                        class="kd-student-id-field">
                    <select name="match[<?php echo esc_attr($lookup_key); ?>][action]"
                        class="kd-action-select" style="width:100%;max-width:220px;font-size:13px">
                        <?php if ( $status !== 'not_found' ) : ?>
                            <option value="use_existing"<?php selected('use_existing', $default_action); ?>>
                                <?php echo $is_students_import ? 'Update existing' : 'Use existing'; ?>
                            </option>
                        <?php endif; ?>
                        <?php if ( ! $is_students_import || $status === 'not_found' ) : ?>
                            <option value="create_new"<?php selected('create_new', $default_action); ?>>
                                Create as new
                            </option>
                        <?php endif; ?>
                        <option value="skip"<?php selected('skip', $default_action); ?>>
                            Skip this row
                        </option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <p class="submit" style="margin-top:1.5rem">
            <button type="submit" class="button button-primary">Continue to Preview →</button>
            <button type="submit" name="kd_step" value="context_confirm" class="button kd-back-btn" formnovalidate>← Back to Confirm Context</button>
            <a href="<?php echo esc_url( $start_over_url ); ?>" class="button">Start Over</a>
        </p>
    </form>
</div>

<style>
.kd-match-row--matched td  { background: #f0fff4 !important; }
.kd-match-row--ambiguous td{ background: #fffbee !important; }
.kd-match-row--not_found td{ background: #fff5f5 !important; }
.kd-match-row:hover td     { filter: brightness(0.97); }
</style>

<script>
(function(){
    // Bulk actions
    function setAllSelects(selector, value) {
        document.querySelectorAll(selector + ' .kd-action-select').forEach(function(sel) {
            // Only set if the option exists in this select
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === value) { sel.value = value; break; }
            }
        });
    }

    var btnMatched  = document.getElementById('kd-bulk-matched');
    var btnNotFound = document.getElementById('kd-bulk-notfound');
    var btnSkipAll  = document.getElementById('kd-bulk-skipall');

    if (btnMatched)  btnMatched.addEventListener('click',  function(){ setAllSelects('.kd-match-row--matched',   'use_existing'); });
    if (btnNotFound) btnNotFound.addEventListener('click', function(){ setAllSelects('.kd-match-row--not_found', 'create_new'); });
    if (btnSkipAll)  btnSkipAll.addEventListener('click',  function(){ setAllSelects('.kd-match-row', 'skip'); });
})();
</script>
