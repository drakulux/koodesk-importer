<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-result.php
 * Variables: $result (import result array), $start_over_url, $import_type
 */
$has_errors   = ! empty( $result['errors'] );
$has_warnings = ! empty( $result['warnings'] );
$new_students = $result['new_students'] ?? [];
$new_staff    = $result['new_staff'] ?? [];
$is_staff     = ( $import_type ?? '' ) === 'staff';
$import_log_key = $result['import_log_key'] ?? '';
?>

<div class="kd-section">
    <h3>Import Complete</h3>

    <?php if ( ! $has_errors ) : ?>
    <div class="notice notice-success" style="margin:0 0 1rem"><p><strong>Import finished successfully.</strong></p></div>
    <?php else : ?>
    <div class="notice notice-warning" style="margin:0 0 1rem"><p><strong>Import finished with some errors — see below.</strong></p></div>
    <?php endif; ?>

    <?php if ( $is_staff ) : ?>
    <table class="kd-table" style="max-width:480px">
        <tr><th>New staff created</th>   <td><?php echo intval( $result['staff_created'] ?? 0 ); ?></td></tr>
        <tr><th>Existing staff updated</th><td><?php echo intval( $result['staff_updated'] ?? 0 ); ?></td></tr>
        <tr><th>Rows skipped</th>        <td><?php echo intval( $result['rows_skipped'] ?? 0 ); ?></td></tr>
    </table>
    <?php else : ?>
    <table class="kd-table" style="max-width:480px">
        <tr><th>New students created</th>          <td><?php echo intval( $result['students_created'] ); ?></td></tr>
        <?php if ( ! empty( $result['families_created'] ) ) : ?>
        <tr><th>New family records created</th>    <td><?php echo intval( $result['families_created'] ); ?></td></tr>
        <?php endif; ?>
        <?php if ( ! empty( $result['students_linked_to_family'] ) ) : ?>
        <tr><th>Students linked to a family</th>    <td><?php echo intval( $result['students_linked_to_family'] ); ?></td></tr>
        <?php endif; ?>
        <tr><th>Academic records inserted</th>     <td><?php echo intval( $result['academic_records_inserted'] ); ?></td></tr>
        <tr><th>Academic records updated</th>      <td><?php echo intval( $result['academic_records_updated'] ); ?></td></tr>
        <tr><th>Term summaries inserted</th>       <td><?php echo intval( $result['summaries_inserted'] ); ?></td></tr>
        <tr><th>Term summaries updated</th>        <td><?php echo intval( $result['summaries_updated'] ); ?></td></tr>
        <tr><th>Rows skipped</th>                  <td><?php echo intval( $result['rows_skipped'] ); ?></td></tr>
    </table>
    <?php endif; ?>

    <?php if ( $is_staff && ! empty( $new_staff ) ) : ?>
    <div style="margin-top:1.5rem">
        <h4>New Staff Created</h4>
        <table class="kd-table" style="max-width:680px">
            <thead><tr><th>Name</th><th>Role</th><th>Email</th></tr></thead>
            <tbody>
            <?php foreach ( $new_staff as $ns ) : ?>
                <tr>
                    <td><?php echo esc_html( $ns['full_name'] ); ?></td>
                    <td><?php echo esc_html( $ns['role_name'] ?: '—' ); ?></td>
                    <td><?php echo esc_html( $ns['email'] ?: '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ( ! $is_staff && ! empty( $new_students ) ) : ?>
    <div style="margin-top:1.5rem">
        <h4>New Students Created — Save This Reference Sheet</h4>
        <p class="description">
            These are the reg numbers and details assigned to newly created students.
            Download this list and keep it for future imports that reference these students.
        </p>
        <!-- FIX #5: Show reg number, gender, class — no internal student_id -->
        <table class="kd-table" style="max-width:780px">
            <thead><tr><th>Name</th><th>Reg Number</th><th>Gender</th><th>Class</th><th>Family</th></tr></thead>
            <tbody>
            <?php foreach ( $new_students as $ns ) : ?>
                <tr>
                    <td><?php echo esc_html( $ns['full_name'] ); ?></td>
                    <td><?php echo esc_html( $ns['reg_number'] ); ?></td>
                    <td><?php echo esc_html( $ns['gender'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $ns['class_name'] ?? '' ); ?></td>
                    <td>
                        <?php if ( ! empty( $ns['family_id'] ) ) : ?>
                            <span class="kd-badge <?php echo ! empty($ns['family_new']) ? 'kd-badge--blue' : 'kd-badge--grey'; ?>">
                                <?php echo ! empty($ns['family_new']) ? 'New family' : 'Linked'; ?>
                            </span>
                        <?php else : ?>
                            <span style="color:#999;font-size:11px">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // FIX #5: CSV uses reg_number (not student_id), includes gender and class
        $csv_rows = [ "Name,Reg Number,Gender,Class\n" ];
        foreach ( $new_students as $ns ) {
            $csv_rows[] = '"' . str_replace('"','""',$ns['full_name']) . '",'
                . '"' . str_replace('"','""',$ns['reg_number'] ?? '') . '",'
                . '"' . str_replace('"','""',$ns['gender'] ?? '') . '",'
                . '"' . str_replace('"','""',$ns['class_name'] ?? '') . '"' . "\n";
        }
        $csv_data = implode( '', $csv_rows );
        $csv_b64  = base64_encode( $csv_data );
        ?>
        <p style="margin-top:.75rem">
            <a href="data:text/csv;base64,<?php echo $csv_b64; ?>"
                download="koodesk-new-students-<?php echo date('Y-m-d'); ?>.csv"
                class="button button-primary">Download New Students CSV</a>
        </p>
    </div>
    <?php endif; ?>

    <?php if ( $has_errors ) : ?>
    <div style="margin-top:1.5rem">
        <h4>Errors (<?php echo count($result['errors']); ?>)</h4>
        <table class="kd-table">
            <thead><tr><th style="width:60px">Row</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach ( $result['errors'] as $e ) : ?>
                <tr>
                    <td><?php echo intval( $e['row'] ); ?></td>
                    <td style="color:#a00"><?php echo esc_html( $e['message'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ( $has_warnings ) : ?>
    <details style="margin-top:1rem">
        <summary style="cursor:pointer;font-weight:600">Warnings (<?php echo count($result['warnings']); ?>)</summary>
        <ul style="margin-top:.5rem">
        <?php foreach ( $result['warnings'] as $w ) : ?>
            <li><?php echo esc_html( $w ); ?></li>
        <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>

    <!-- FIX #14: Reverse import option — not yet supported for staff imports -->
    <?php if ( $import_log_key && ! $is_staff ) : ?>
    <div class="kd-section" style="margin-top:1.5rem;border-left:3px solid #d63638;background:var(--bg-body)">
        <h4 style="margin-top:0;color:#d63638">Undo This Import</h4>
        <p style="font-size:13px">
            You can reverse this import within 24 hours. This will delete all newly inserted academic records,
            term summaries, and any new student records created during this import.
            Records that were <em>updated</em> (not newly inserted) cannot be automatically undone.
        </p>
        <button type="button" class="button" id="kd-reverse-btn"
            style="border-color:#d63638;color:#d63638"
            data-log-key="<?php echo esc_attr( $import_log_key ); ?>">
            Reverse This Import
        </button>
        <span id="kd-reverse-status" style="margin-left:.75rem;font-size:13px"></span>
    </div>
    <?php elseif ( $is_staff ) : ?>
    <div class="kd-notice" style="margin-top:1.5rem">
        Undo isn't available for staff imports yet — double-check the results above before re-running.
    </div>
    <?php endif; ?>

    <p style="margin-top:1.5rem">
        <a href="<?php echo esc_url( $start_over_url ); ?>" class="button button-primary">Start Another Import</a>
        <a href="<?php
            // Frontend: append ?kd_view=history to the current page URL.
            // Admin: link to the admin history page.
            $history_url = $start_over_url;
            if ( is_admin() ) {
                $history_url = admin_url( 'admin.php?page=koodesk-importer-history' );
            } else {
                $history_url = add_query_arg( 'kd_view', 'history', $start_over_url );
            }
            echo esc_url( $history_url );
            ?>" class="button">View Import History</a>
    </p>
</div>

<?php if ( $import_log_key && ! $is_staff ) : ?>
<script>
(function(){
    var btn    = document.getElementById('kd-reverse-btn');
    var status = document.getElementById('kd-reverse-status');
    var nonce  = <?php echo wp_json_encode( wp_create_nonce('kd_reverse_import') ); ?>;
    var ajaxUrl = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;

    btn.addEventListener('click', function() {
        if (!confirm('This will delete all academic records and term summaries inserted during this import, as well as any new students created. Are you sure?')) return;
        btn.disabled = true;
        btn.textContent = 'Reversing…';
        status.textContent = '';

        var fd = new FormData();
        fd.append('action', 'kd_reverse_import');
        fd.append('nonce', nonce);
        fd.append('log_key', btn.getAttribute('data-log-key'));

        fetch(ajaxUrl, { method:'POST', body:fd })
            .then(r => r.json())
            .then(function(res) {
                if (res.success) {
                    var d = res.data;
                    status.textContent = '✓ Reversed: ' + d.records_deleted + ' record(s), '
                        + d.summaries_deleted + ' summary/ies, '
                        + d.students_deleted + ' student(s) deleted.';
                    status.style.color = '#0a5c2e';
                    btn.remove();
                } else {
                    status.textContent = 'Error: ' + (res.data || 'Unknown error');
                    status.style.color = '#a00';
                    btn.disabled = false;
                    btn.textContent = 'Reverse This Import';
                }
            });
    });
})();
</script>
<?php endif; ?>
