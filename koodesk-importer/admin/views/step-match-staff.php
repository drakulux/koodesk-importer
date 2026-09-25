<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-match-staff.php
 * Variables: $import_type, $token, $profile_id, $mapping, $match_results,
 *            $role_results, $all_roles, $matcher, $start_over_url
 */

$total     = count( $match_results );
$matched   = count( array_filter( $match_results, fn($r) => $r['status'] === 'matched' ) );
$ambiguous = count( array_filter( $match_results, fn($r) => $r['status'] === 'ambiguous' ) );
$not_found = count( array_filter( $match_results, fn($r) => $r['status'] === 'not_found' ) );

$roles_matched   = count( array_filter( $role_results, fn($r) => $r['status'] === 'matched' ) );
$roles_unmatched = count( array_filter( $role_results, fn($r) => $r['status'] === 'not_found' ) );
?>

<div class="kd-section">
    <h3>Step 4 — Match Staff &amp; Roles</h3>

    <form method="post">
        <input type="hidden" name="kd_step"     value="preview">
        <input type="hidden" name="kd_token"    value="<?php echo esc_attr( $token ); ?>">
        <input type="hidden" name="import_type" value="<?php echo esc_attr( $import_type ); ?>">
        <input type="hidden" name="profile_id"  value="<?php echo esc_attr( $profile_id ); ?>">

        <!-- ── Match Staff (dedup) ─────────────────────────────────────── -->
        <p>
            Review how each staff member in the file matches to an existing Koodesk staff record.
            <strong><?php echo $matched; ?> matched</strong>,
            <strong><?php echo $ambiguous; ?> need review</strong>,
            <strong><?php echo $not_found; ?> not found</strong>.
        </p>
        <p class="description">
            <strong>Matched (green):</strong> will update the existing staff record's profile fields.<br>
            <strong>Ambiguous (yellow):</strong> select the correct staff member, or create new / skip.<br>
            <strong>Not found (red):</strong> create as a new staff record, or skip.
        </p>

        <?php if ( $total === 0 ) : ?>
        <div class="kd-notice kd-notice--warning">No staff rows found to match.</div>
        <?php else : ?>

        <div style="margin:.75rem 0;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <strong style="font-size:13px">Quick set:</strong>
            <button type="button" class="button button-small" id="kd-bulk-matched">Set all Matched → Use existing</button>
            <button type="button" class="button button-small" id="kd-bulk-matched-skip">Set all Matched → Skip</button>
            <button type="button" class="button button-small" id="kd-bulk-notfound">Set all Not Found → Create new</button>
            <button type="button" class="button button-small" id="kd-bulk-notfound-skip">Set all Not Found → Skip</button>
            <button type="button" class="button button-small" id="kd-bulk-skipall">Set all → Skip</button>
        </div>

        <table class="kd-table" style="table-layout:fixed;width:100%">
            <thead>
                <tr>
                    <th style="width:22%">Name in File</th>
                    <th style="width:10%">Status</th>
                    <th style="width:30%">Matched Staff</th>
                    <th style="width:22%">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $match_results as $lookup_key => $result ) :
                $status     = $result['status'];
                $raw_name   = $result['raw_name'];
                $staff      = $result['staff'] ?? null;
                $candidates = $result['candidates'] ?? [];
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
                    <?php if ( $status === 'matched' && $staff ) : ?>
                        <span><?php echo esc_html( $staff['full_name'] ); ?></span>
                        <?php if ( ! empty( $staff['staff_email'] ) ) : ?>
                            <br><small style="color:#666"><?php echo esc_html( $staff['staff_email'] ); ?></small>
                        <?php endif; ?>
                    <?php elseif ( $status === 'ambiguous' ) : ?>
                        <select name="match[<?php echo esc_attr($lookup_key); ?>][staff_id]"
                            class="kd-ambig-select" style="max-width:100%;font-size:12px">
                            <option value="">— Select correct staff member —</option>
                            <?php foreach ( $candidates as $c ) : ?>
                                <option value="<?php echo intval($c['_ID']); ?>">
                                    <?php echo esc_html( $c['full_name'] ); ?>
                                    <?php if ( ! empty( $c['staff_email'] ) ) echo ' (' . esc_html($c['staff_email']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else : ?>
                        <em style="color:#999;font-size:12px">No match found</em>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $row_staff_id = 0;
                    $default_action = 'skip';
                    if ( $status === 'matched' ) {
                        $row_staff_id = intval( $staff['_ID'] ?? 0 );
                        $default_action = 'use_existing';
                    } elseif ( $status === 'ambiguous' ) {
                        $default_action = 'use_existing';
                    } else {
                        $default_action = 'create_new';
                    }
                    ?>
                    <input type="hidden"
                        name="match[<?php echo esc_attr($lookup_key); ?>][staff_id]"
                        value="<?php echo $row_staff_id; ?>"
                        class="kd-student-id-field">
                    <select name="match[<?php echo esc_attr($lookup_key); ?>][action]"
                        class="kd-action-select" style="width:100%;max-width:220px;font-size:13px">
                        <option value="use_existing"<?php selected('use_existing', $default_action); ?>>Update existing</option>
                        <option value="create_new"<?php selected('create_new', $default_action); ?>>Create as new</option>
                        <option value="skip"<?php selected('skip', $default_action); ?>>Skip this row</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ── Match Roles ─────────────────────────────────────────────── -->
        <?php if ( ! empty( $mapping['role_col'] ) ) : ?>
        <div class="kd-section" style="margin-top:1.5rem;border-left:3px solid #72aee6">
            <h4 style="margin-top:0">Match Roles</h4>
            <p class="description">
                Each distinct role text found in the "<?php echo esc_html( $mapping['role_col'] ); ?>" column is resolved once below and applied to every staff row using that text.
                <strong><?php echo $roles_matched; ?> matched</strong>, <strong><?php echo $roles_unmatched; ?> need a manual pick</strong>.
            </p>

            <?php if ( empty( $role_results ) ) : ?>
            <p class="kd-notice">No role values found in that column.</p>
            <?php else : ?>
            <table class="kd-table" style="max-width:680px">
                <thead><tr><th style="width:220px">Role Text in File</th><th style="width:100px">Status</th><th>Assign Role</th></tr></thead>
                <tbody>
                <?php foreach ( $role_results as $raw_role => $rr ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $raw_role ); ?></strong></td>
                    <td>
                        <?php if ( $rr['status'] === 'matched' ) : ?>
                            <span class="kd-badge kd-badge--green">Matched</span>
                        <?php else : ?>
                            <span class="kd-badge kd-badge--red">No match</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <select name="role_match[<?php echo esc_attr( $raw_role ); ?>]" class="kd-select" style="font-size:13px">
                            <option value="0">— Leave unassigned —</option>
                            <?php foreach ( $all_roles as $role ) :
                                $sel = ( (int) $rr['role_id'] === (int) $role['_ID'] ) ? ' selected' : '';
                            ?>
                                <option value="<?php echo intval( $role['_ID'] ); ?>"<?php echo $sel; ?>><?php echo esc_html( $role['role_name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <p class="submit" style="margin-top:1.5rem">
            <button type="submit" class="button button-primary">Continue to Preview →</button>
            <button type="submit" name="kd_step" value="context_confirm" class="button kd-back-btn" formnovalidate>← Back to Confirm Mapping</button>
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
    function setAllSelects(selector, value) {
        document.querySelectorAll(selector + ' .kd-action-select').forEach(function(sel) {
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === value) { sel.value = value; break; }
            }
        });
    }

    var btnMatched     = document.getElementById('kd-bulk-matched');
    var btnMatchedSkip = document.getElementById('kd-bulk-matched-skip');
    var btnNotFound    = document.getElementById('kd-bulk-notfound');
    var btnNotFoundSkip= document.getElementById('kd-bulk-notfound-skip');
    var btnSkipAll     = document.getElementById('kd-bulk-skipall');

    if (btnMatched)      btnMatched.addEventListener('click',      function(){ setAllSelects('.kd-match-row--matched',   'use_existing'); });
    if (btnMatchedSkip)  btnMatchedSkip.addEventListener('click',  function(){ setAllSelects('.kd-match-row--matched',   'skip'); });
    if (btnNotFound)     btnNotFound.addEventListener('click',     function(){ setAllSelects('.kd-match-row--not_found', 'create_new'); });
    if (btnNotFoundSkip) btnNotFoundSkip.addEventListener('click', function(){ setAllSelects('.kd-match-row--not_found', 'skip'); });
    if (btnSkipAll)  btnSkipAll.addEventListener('click',  function(){ setAllSelects('.kd-match-row', 'skip'); });
})();
</script>
