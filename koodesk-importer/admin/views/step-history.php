<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-history.php — Import History
 *
 * Rendered in two contexts:
 *   - Admin page  (render_history):          $is_frontend = false, admin_url() links work
 *   - Frontend shortcode (render_history_shortcode): $is_frontend = true, use $start_over_url
 *
 * Variables provided:
 *   $history        array   — all import log entries
 *   $start_over_url string  — URL to the importer (frontend page or admin URL)
 *   $is_frontend    bool    — true when rendered via shortcode
 */

$is_frontend = $is_frontend ?? false;

// AJAX URL works identically on frontend and admin
$ajax_url = admin_url( 'admin-ajax.php' );
?>



<div class="kd-section kd-history">
	<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
		<h3 style="margin:0">Import History</h3>
		<?php if ( ! empty( $history ) ) : ?>
		<button type="button" class="kd-btn kd-btn--danger-outline kd-btn--sm" id="kd-clear-history">
			Clear All History
		</button>
		<?php endif; ?>
	</div>

	<p style="margin:0 0 1rem;font-size:13px;color:#555">
		A log of all completed imports. Use <strong>Undo</strong> to delete inserted records.
		Records that were <em>updated</em> (not newly created) cannot be automatically reversed.
	</p>

	<?php if ( empty( $history ) ) : ?>
	<p class="kd-empty-state">No imports recorded yet.</p>

	<?php else : ?>

	<?php /* ── Desktop table ── */ ?>
	<div class="kd-hist-table-wrap">
		<table class="kd-table kd-hist-table">
			<thead>
				<tr>
					<th>Date &amp; Time</th>
					<th>Session / Term</th>
					<th>Class</th>
					<th>Profile</th>
					<th>What was imported</th>
					<th>Status</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $history as $entry ) :
				$id          = esc_attr( $entry['id'] ?? '' );
				$import_ts   = (int)( $entry['import_time'] ?? 0 );
				$time_fmt    = $import_ts ? date_i18n( 'd M Y H:i', $import_ts ) : '—';
				$is_reversed = ! empty( $entry['reversed'] );
				$reversed_ts = (int)( $entry['reversed_at'] ?? 0 );
				$term_int    = (int)( $entry['term'] ?? 0 );
				$term_label  = $term_int ? 'Term ' . $term_int : '—';
				$session     = esc_html( $entry['session']      ?: '—' );
				$class_name  = esc_html( $entry['class_name']   ?: '—' );
				$profile     = esc_html( $entry['profile_name'] ?: '—' );

				// Summary text
				$parts = [];
				if ( ! empty( $entry['students_created'] ) )   $parts[] = $entry['students_created']   . ' student(s) created';
				if ( ! empty( $entry['records_inserted'] ) )   $parts[] = $entry['records_inserted']   . ' record(s) inserted';
				if ( ! empty( $entry['records_updated'] ) )    $parts[] = $entry['records_updated']    . ' record(s) updated';
				if ( ! empty( $entry['summaries_inserted'] ) ) $parts[] = $entry['summaries_inserted'] . ' summary/ies inserted';
				if ( ! empty( $entry['rows_skipped'] ) )       $parts[] = $entry['rows_skipped']       . ' row(s) skipped';
				$summary = implode( ', ', $parts ) ?: '—';

				// Can we undo?
				$has_records = ! empty( $entry['record_ids'] )
					|| ! empty( $entry['new_student_ids'] )
					|| ! empty( $entry['inserted_student_ids'] )
					|| ! empty( $entry['summary_ids'] );
				$can_reverse = ! $is_reversed && $has_records;
			?>
			<tr class="kd-hist-row <?php echo $is_reversed ? 'kd-hist-row--reversed' : ''; ?>"
				id="kd-hist-<?php echo $id; ?>">
				<td data-label="Date"><?php echo esc_html( $time_fmt ); ?></td>
				<td data-label="Session / Term"><?php echo $session . ' / ' . esc_html( $term_label ); ?></td>
				<td data-label="Class"><?php echo $class_name; ?></td>
				<td data-label="Profile"><?php echo $profile; ?></td>
				<td data-label="Imported"><?php echo esc_html( $summary ); ?></td>
				<td data-label="Status">
					<?php if ( $is_reversed ) : ?>
						<span class="kd-badge kd-badge--grey">
							Reversed<?php if ( $reversed_ts ) echo ' ' . date_i18n( 'd M', $reversed_ts ); ?>
						</span>
					<?php else : ?>
						<span class="kd-badge kd-badge--green">Imported</span>
					<?php endif; ?>
				</td>
				<td data-label="Actions" class="kd-hist-actions">
					<?php if ( $can_reverse ) : ?>
					<button type="button"
						class="kd-btn kd-btn--danger-outline kd-btn--sm kd-reverse-hist"
						data-id="<?php echo $id; ?>">
						Undo
					</button>
					<?php elseif ( ! $is_reversed ) : ?>
					<span style="color:#999;font-size:11px">Nothing to undo</span>
					<?php endif; ?>
					<button type="button"
						class="kd-btn kd-btn--ghost kd-btn--sm kd-delete-hist"
						data-id="<?php echo $id; ?>"
						title="Remove from history log"
						aria-label="Remove from history">✕</button>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div><!-- /.kd-hist-table-wrap -->

	<?php endif; // history not empty ?>


</div>

<style>
/* ── History-specific styles (scoped) ── */

.kd-history h3 { font-size:1.1rem; }

.kd-empty-state {
	color:#666;
	font-style:italic;
	padding:1rem 0;
}

/* Override table width for history — allow horizontal scroll */
.kd-hist-table-wrap {
	overflow-x: auto;
	-webkit-overflow-scrolling: touch;
	margin: 0 -1px;
}
.kd-hist-table {
	min-width: 700px;
	width: 100%;
}
.kd-hist-row--reversed td {
	opacity: .55;
}
.kd-hist-actions {
	white-space: nowrap;
	display: flex;
	gap: 4px;
	align-items: center;
	flex-wrap: wrap;
}

/* ── Frontend-safe button styles (don't rely on WP admin classes) ── */
.kd-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 7px 14px;
	border-radius: 3px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	text-decoration: none;
	border: 1px solid transparent;
	line-height: 1.2;
	transition: background .12s, border-color .12s, color .12s;
	background: none;
	font-family: inherit;
}
.kd-btn--primary {
	background: #2271b1;
	border-color: #135e96;
	color: #fff !important;
}
.kd-btn--primary:hover { background: #135e96; }

.kd-btn--secondary {
	background: #f6f7f7;
	border-color: #8c8f94;
	color: #1d2327 !important;
}
.kd-btn--secondary:hover { background: #dcdcde; }

.kd-btn--danger-outline {
	background: transparent;
	border-color: #b32d2e;
	color: #b32d2e !important;
}
.kd-btn--danger-outline:hover { background: #fef7f7; }

.kd-btn--ghost {
	background: transparent;
	border-color: #dcdcde;
	color: #666 !important;
	padding: 5px 8px;
}
.kd-btn--ghost:hover { background: #f6f7f7; border-color: #8c8f94; }

.kd-btn--sm { padding: 4px 10px; font-size: 12px; }

.kd-btn:disabled {
	opacity: .5;
	cursor: not-allowed;
}

/* ── Responsive: stack table rows on narrow screens ── */
@media (max-width: 680px) {
	.kd-hist-table-wrap { margin: 0; }
	.kd-hist-table,
	.kd-hist-table thead,
	.kd-hist-table tbody,
	.kd-hist-table th,
	.kd-hist-table td,
	.kd-hist-table tr { display: block; }

	.kd-hist-table thead { display: none; }

	.kd-hist-table tbody tr {
		margin-bottom: .75rem;
		border: 1px solid #dcdcde;
		border-radius: 4px;
		overflow: hidden;
	}
	.kd-hist-table td {
		display: flex;
		align-items: baseline;
		gap: .5rem;
		padding: 6px 10px;
		border: none;
		border-bottom: 1px solid #f0f0f1;
	}
	.kd-hist-table td:last-child { border-bottom: none; }
	.kd-hist-table td::before {
		content: attr(data-label) ': ';
		font-weight: 700;
		font-size: 11px;
		color: #555;
		min-width: 90px;
		flex-shrink: 0;
	}
	.kd-hist-actions { flex-direction: row; flex-wrap: wrap; }
}
</style>

<script>
(function(){
	var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
	var nonces  = {
		reverse: <?php echo wp_json_encode( wp_create_nonce('kd_reverse_import') ); ?>,
		delete:  <?php echo wp_json_encode( wp_create_nonce('kd_delete_hist_entry') ); ?>,
		clear:   <?php echo wp_json_encode( wp_create_nonce('kd_clear_history') ); ?>,
	};

	function postAjax(action, nonce, data, cb) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', nonce);
		Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
		fetch(ajaxUrl, {method:'POST', body:fd})
			.then(function(r){ return r.json(); })
			.then(cb)
			.catch(function(){ alert('Network error — please try again.'); });
	}

	// ── Undo ──────────────────────────────────────────────────────────
	document.addEventListener('click', function(e) {
		var btn = e.target.closest('.kd-reverse-hist');
		if (!btn) return;
		var id = btn.getAttribute('data-id');
		if (!confirm(
			'This will permanently delete all academic records, term summaries, and new students ' +
			'that were INSERTED during this import.\n\n' +
			'Records that were UPDATED (not newly created) cannot be undone.\n\n' +
			'Are you sure?'
		)) return;

		btn.disabled = true;
		var origText = btn.textContent;
		btn.textContent = '…';

		postAjax('kd_reverse_import', nonces.reverse, {log_key: id}, function(res) {
			if (res.success) {
				var d = res.data;
				var row = document.getElementById('kd-hist-' + id);
				if (row) {
					row.classList.add('kd-hist-row--reversed');
					// Update status cell
					var statusCell = row.querySelector('[data-label="Status"]');
					if (statusCell) statusCell.innerHTML = '<span class="kd-badge kd-badge--grey">Reversed</span>';
					// Update actions cell
					var actCell = row.querySelector('[data-label="Actions"]');
					if (actCell) {
						var delBtn = actCell.querySelector('.kd-delete-hist');
						actCell.innerHTML = '<span style="color:#0a5c2e;font-size:11px">✓ ' +
							d.records_deleted + ' record(s), ' +
							d.summaries_deleted + ' summary/ies, ' +
							d.students_deleted + ' student(s) removed</span>';
						if (delBtn) actCell.appendChild(delBtn);
					}
				}
			} else {
				alert('Undo failed: ' + (res.data || 'Unknown error'));
				btn.disabled = false;
				btn.textContent = origText;
			}
		});
	});

	// ── Delete entry from log ─────────────────────────────────────────
	document.addEventListener('click', function(e) {
		var btn = e.target.closest('.kd-delete-hist');
		if (!btn) return;
		var id = btn.getAttribute('data-id');
		if (!confirm('Remove this entry from the history log?\n\nThis does not affect any imported data.')) return;

		postAjax('kd_delete_hist_entry', nonces.delete, {entry_id: id}, function(res) {
			if (res.success) {
				var row = document.getElementById('kd-hist-' + id);
				if (row) {
					row.style.transition = 'opacity .2s';
					row.style.opacity = '0';
					setTimeout(function(){ row.remove(); }, 220);
				}
			}
		});
	});

	// ── Clear all ─────────────────────────────────────────────────────
	var clearBtn = document.getElementById('kd-clear-history');
	if (clearBtn) {
		clearBtn.addEventListener('click', function() {
			if (!confirm(
				'Clear the entire import history log?\n\n' +
				'This only removes the log entries — all imported data is kept.'
			)) return;
			clearBtn.disabled = true;
			postAjax('kd_clear_history', nonces.clear, {}, function(res) {
				if (res.success) location.reload();
				else { alert('Failed to clear history.'); clearBtn.disabled = false; }
			});
		});
	}
})();
</script>
