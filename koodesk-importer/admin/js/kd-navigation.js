/**
 * Koodesk Importer — no-refresh step navigation
 *
 * Intercepts form submissions inside .kd-importer and swaps step content
 * via AJAX instead of doing a full page reload.
 *
 * Exceptions that still use real navigation:
 *   - The upload step (has a file input — needs multipart POST)
 *   - When the server returns a { redirect: url } response (e.g. "Back" from step 2)
 */
(function () {
    'use strict';

    function getContainer() {
        return document.querySelector('.kd-importer');
    }

    function setLoading(on) {
        var c = getContainer();
        if (!c) return;
        c.style.opacity       = on ? '0.55' : '';
        c.style.pointerEvents = on ? 'none'  : '';
        c.style.transition    = 'opacity .15s';
    }

    /**
     * Replace the importer container's inner HTML with new content,
     * then re-execute any <script> tags (innerHTML-injected scripts don't run).
     */
    function swapContent(html) {
        var container = getContainer();
        if (!container) return;

        // Parse into a temporary node
        var tmp = document.createElement('div');
        tmp.innerHTML = html;

        // Swap children
        container.innerHTML = '';
        while (tmp.firstChild) {
            container.appendChild(tmp.firstChild);
        }

        // Re-run <script> elements
        container.querySelectorAll('script').forEach(function (old) {
            var fresh = document.createElement('script');
            Array.from(old.attributes).forEach(function (a) {
                fresh.setAttribute(a.name, a.value);
            });
            fresh.textContent = old.textContent;
            old.parentNode.replaceChild(fresh, old);
        });

        // Scroll importer into view
        var top = container.getBoundingClientRect().top + window.scrollY - 16;
        window.scrollTo({ top: top > 0 ? top : 0, behavior: 'smooth' });
    }

    /* ── Intercept form submits inside the importer ────────────────── */

    document.addEventListener('submit', function (e) {
        var form = e.target;

        // Only handle forms inside the importer
        if (!form.closest('.kd-importer')) return;

        // File-upload step must use a real POST — skip interception
        if (form.querySelector('input[type="file"]')) return;

        e.preventDefault();
        setLoading(true);

        var fd = new FormData(form);

        // Back/step buttons carry name="kd_step" on the <button> element.
        // FormData doesn't always include the clicked button automatically
        // (depends on browser), so force it from e.submitter.
        if (e.submitter && e.submitter.name === 'kd_step') {
            fd.set('kd_step', e.submitter.value);
        }

        fd.set('action', 'kd_render_step');

        fetch(window.KD_AJAX_URL || '', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                setLoading(false);

                if (!res.success) {
                    alert('Step error: ' + (res.data || 'Unknown error'));
                    return;
                }

                // Server asked for a full redirect (e.g. "Back" to upload step)
                if (res.data.redirect) {
                    window.location.href = res.data.redirect;
                    return;
                }

                swapContent(res.data.html);
            })
            .catch(function (err) {
                setLoading(false);
                console.error('[KD Importer]', err);
                alert('Navigation error — please try again.');
            });
    });

}());
