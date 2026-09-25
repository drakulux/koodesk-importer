<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-run-import-progress.php
 * Variables: $batch_id, $batch_nonce, $total_rows, $total_chunks, $is_staff_import
 *
 * Kicks off the chunked import client-side. This view's own inline <script>
 * does all the work: repeatedly POSTs to kd_process_import_batch until the
 * server reports done=true, then swaps the final result HTML into the
 * importer container itself (kd-navigation.js only intercepts <form>
 * submits, so a manually-driven fetch loop like this has to do its own
 * swap — duplicated in miniature from kd-navigation.js's swapContent()).
 */
?>

<div class="kd-section" id="kd-import-progress-section">
    <h3>Step 6 — Importing<?php echo $is_staff_import ? ' Staff' : ''; ?>…</h3>

    <div class="kd-notice kd-notice--warning" style="margin-bottom:1rem">
        <strong>Please don't close this page or navigate away</strong> — the import is running in the background
        and closing the page now will leave it partially complete.
    </div>

    <div style="max-width:520px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:.4rem">
            <span id="kd-progress-label">Starting import…</span>
            <span id="kd-progress-count">0 / <?php echo intval( $total_rows ); ?></span>
        </div>
        <div style="height:14px;border-radius:7px;background:var(--bg-medium);border:1px solid var(--border-primary);overflow:hidden">
            <div id="kd-progress-bar" style="height:100%;width:0%;background:var(--primary);transition:width .25s ease"></div>
        </div>
        <p id="kd-progress-status" style="margin-top:.6rem;font-size:12px;color:var(--tertiary)"></p>
    </div>

    <div id="kd-progress-error" style="display:none;margin-top:1rem"></div>
</div>

<script>
(function(){
'use strict';

var batchId      = <?php echo wp_json_encode( $batch_id ); ?>;
var nonce        = <?php echo wp_json_encode( $batch_nonce ); ?>;
var totalRows    = <?php echo (int) $total_rows; ?>;
var totalChunks  = <?php echo (int) $total_chunks; ?>;
var ajaxUrl      = window.KD_AJAX_URL || <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

var bar    = document.getElementById('kd-progress-bar');
var label  = document.getElementById('kd-progress-label');
var count  = document.getElementById('kd-progress-count');
var status = document.getElementById('kd-progress-status');
var errBox = document.getElementById('kd-progress-error');

// Warn on tab close/refresh while the batch is in flight.
var importInFlight = true;
function beforeUnloadHandler(e) {
    if (!importInFlight) return;
    e.preventDefault();
    e.returnValue = '';
    return '';
}
window.addEventListener('beforeunload', beforeUnloadHandler);

function getContainer() {
    return document.querySelector('.kd-importer');
}

// Minimal re-implementation of kd-navigation.js's swapContent(): replace
// the importer container's contents and re-execute any <script> tags
// (innerHTML-injected scripts don't run on their own).
function swapContent(html) {
    var container = getContainer();
    if (!container) return;

    var tmp = document.createElement('div');
    tmp.innerHTML = html;

    container.innerHTML = '';
    while (tmp.firstChild) {
        container.appendChild(tmp.firstChild);
    }

    container.querySelectorAll('script').forEach(function (old) {
        var fresh = document.createElement('script');
        Array.from(old.attributes).forEach(function (a) {
            fresh.setAttribute(a.name, a.value);
        });
        fresh.textContent = old.textContent;
        old.parentNode.replaceChild(fresh, old);
    });

    var top = container.getBoundingClientRect().top + window.scrollY - 16;
    window.scrollTo({ top: top > 0 ? top : 0, behavior: 'smooth' });
}

function showError(message) {
    importInFlight = false;
    window.removeEventListener('beforeunload', beforeUnloadHandler);
    errBox.style.display = '';
    errBox.innerHTML = '<div class="notice notice-error"><p><strong>Import stopped:</strong> ' + message + '</p></div>';
    label.textContent = 'Stopped';
}

function processNext() {
    var fd = new FormData();
    fd.append('action', 'kd_process_import_batch');
    fd.append('nonce', nonce);
    fd.append('batch_id', batchId);

    fetch(ajaxUrl, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                showError(res.data || 'Unknown error.');
                return;
            }

            var data = res.data;
            var pct = totalChunks > 0 ? Math.round((data.processed / totalChunks) * 100) : 100;
            bar.style.width = pct + '%';
            count.textContent = data.processed + ' / ' + totalChunks + ' batch' + (totalChunks === 1 ? '' : 'es');
            label.textContent = data.done ? 'Finishing up…' : 'Importing… (' + pct + '%)';

            if (data.done) {
                importInFlight = false;
                window.removeEventListener('beforeunload', beforeUnloadHandler);
                status.textContent = 'Done.';
                swapContent(data.html);
                return;
            }

            processNext();
        })
        .catch(function(err) {
            console.error('[KD Importer]', err);
            showError('Network error — the import may be partially complete. Refresh the Import History page to check.');
        });
}

status.textContent = 'Processing ' + totalRows + ' row(s) in ' + totalChunks + ' batch(es) of up to <?php echo (int) $chunk_size; ?> each.';
processNext();

})();
</script>
