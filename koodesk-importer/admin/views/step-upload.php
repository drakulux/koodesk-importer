<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="kd-section">
	<h3>Step 1 — Upload File &amp; Select Import Type</h3>
	<p>Upload a CSV export of your school's result sheet or student list. Each file should cover one class, one term, and one session.</p>

	<form method="post" enctype="multipart/form-data">
		<input type="hidden" name="kd_step" value="classify">
		<?php wp_nonce_field( 'kd_upload', 'kd_upload_nonce' ); ?>

		<table class="form-table" style="max-width:700px">
			<tr>
				<th scope="row"><label for="import_type">Import Type</label></th>
				<td>
					<select name="import_type" id="import_type" required class="regular-text">
						<option value="">— Choose —</option>
						<option value="academic_records">Academic Records</option>
						<option value="students">Students</option>
					</select>
					<p class="description">
						<strong>Academic Records</strong> — imports subject scores, grades, and term summaries into existing students.<br>
						<strong>Students</strong> — creates new student records from a list.
					</p>
				</td>
			</tr>

			<tr id="row-profile" style="display:none">
				<th scope="row"><label for="profile_id">Mapping Profile</label></th>
				<td>
					<select name="profile_id" id="profile_id" class="regular-text">
						<option value="0">— Create a new profile —</option>
					</select>
					<p class="description">Select a saved profile to re-use column mapping, or choose "Create a new profile" to map columns now.</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="csv_file">CSV File</label></th>
				<td>
					<input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" required>
					<p class="description">CSV only. First row must be column headers. One class per file.</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary">Continue →</button>
		</p>
	</form>
</div>

<?php
// Show existing profiles — FIX #2: add delete button
$all_profiles = array_merge( $profiles_ar, $profiles_st );
if ( ! empty( $all_profiles ) ) : ?>
<div class="kd-section">
	<h3>Saved Profiles</h3>
	<table class="kd-table">
		<thead><tr><th>Name</th><th>Type</th><th>Last Used</th><th style="width:80px">Action</th></tr></thead>
		<tbody>
		<?php foreach ( $all_profiles as $p ) : ?>
			<tr id="kd-profile-row-<?php echo intval($p['_ID']); ?>">
				<td><?php echo esc_html( $p['profile_name'] ); ?></td>
				<td><?php echo esc_html( $p['import_type'] ); ?></td>
				<td><?php echo $p['last_used'] ? esc_html( date( 'Y-m-d H:i', (int)$p['last_used'] ) ) : '—'; ?></td>
				<td>
					<button type="button"
						class="button button-small kd-delete-profile"
						data-id="<?php echo intval($p['_ID']); ?>"
						data-name="<?php echo esc_attr($p['profile_name']); ?>"
						style="color:#a00;border-color:#a00">
						Delete
					</button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>

<script>
(function(){
	var typeSelect    = document.getElementById('import_type');
	var profileRow    = document.getElementById('row-profile');
	var profileSelect = document.getElementById('profile_id');

	var arProfiles = <?php echo wp_json_encode( array_map( function($p){ return ['id'=>(int)$p['_ID'],'name'=>$p['profile_name']]; }, $profiles_ar ) ); ?>;
	var stProfiles = <?php echo wp_json_encode( array_map( function($p){ return ['id'=>(int)$p['_ID'],'name'=>$p['profile_name']]; }, $profiles_st ) ); ?>;

	typeSelect.addEventListener('change', function(){
		var val = this.value;
		profileSelect.innerHTML = '<option value="0">— Create a new profile —</option>';
		var list = (val === 'academic_records') ? arProfiles : (val === 'students') ? stProfiles : [];
		list.forEach(function(p){
			var opt = document.createElement('option');
			opt.value = p.id;
			opt.textContent = p.name;
			profileSelect.appendChild(opt);
		});
		profileRow.style.display = (list.length > 0) ? '' : 'none';
	});

	// FIX #2: Delete profile
	var nonce = <?php echo wp_json_encode( wp_create_nonce('kd_delete_profile') ); ?>;
	var ajaxUrl = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;

	document.addEventListener('click', function(e) {
		var btn = e.target.closest('.kd-delete-profile');
		if (!btn) return;
		var id   = btn.getAttribute('data-id');
		var name = btn.getAttribute('data-name');
		if (!confirm('Delete profile "' + name + '"? This cannot be undone.')) return;
		btn.disabled = true;
		btn.textContent = 'Deleting…';
		var fd = new FormData();
		fd.append('action', 'kd_delete_profile');
		fd.append('nonce', nonce);
		fd.append('profile_id', id);
		fetch(ajaxUrl, { method:'POST', body:fd })
			.then(r => r.json())
			.then(function(res) {
				if (res.success) {
					var row = document.getElementById('kd-profile-row-' + id);
					if (row) row.remove();
					// Also remove from profile dropdowns
					arProfiles = arProfiles.filter(function(p){ return p.id != id; });
					stProfiles = stProfiles.filter(function(p){ return p.id != id; });
				} else {
					alert('Delete failed: ' + (res.data || 'Unknown error'));
					btn.disabled = false;
					btn.textContent = 'Delete';
				}
			});
	});
})();
</script>
