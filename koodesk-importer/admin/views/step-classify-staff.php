<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-classify-staff.php
 * Variables: $import_type, $profile_id, $token, $headers, $parsed, $pm
 *            (saved profile mapping, or null), $existing_profile_names,
 *            $start_over_url
 */

$staff_fields = [
	'title'           => 'Title (Mr., Mrs., Miss, Ms., Dr.)',
	'full_name'       => 'Full Name',
	'first_name'      => 'First Name',
	'last_name'       => 'Last Name',
	'gender'          => 'Gender',
	'date_of_birth'   => 'Date of Birth',
	'phone_number'    => 'Phone Number',
	'address'         => 'Address',
	'staff_email'     => 'Email',
	'date_hired'      => 'Date Hired',
	'employment_type' => 'Employment Type (Full Time / Part Time / Contract)',
	'monthly_salary'  => 'Monthly Salary',
	'account_number'  => 'Account Number',
	'bank_name'       => 'Bank Name',
];

// Lightweight keyword-based auto-suggest — staff CSVs are simple flat
// exports with no subject/assessment structure, so this doesn't need the
// full Koodesk_Column_Classifier machinery built for academic records.
$suggest_keywords = [
	'title'           => [ 'title', 'salutation' ],
	'full_name'       => [ 'full name', 'staff name' ],
	'first_name'      => [ 'first name', 'firstname', 'given name' ],
	'last_name'       => [ 'last name', 'lastname', 'surname', 'family name' ],
	'gender'          => [ 'gender', 'sex' ],
	'date_of_birth'   => [ 'date of birth', 'dob', 'birth date' ],
	'phone_number'    => [ 'phone', 'mobile', 'contact number' ],
	'address'         => [ 'address', 'residential address', 'home address' ],
	'staff_email'     => [ 'email', 'e-mail' ],
	'date_hired'      => [ 'date hired', 'hire date', 'employment date', 'date employed' ],
	'employment_type' => [ 'employment type', 'employment', 'staff type', 'contract type' ],
	'monthly_salary'  => [ 'monthly salary', 'salary', 'pay' ],
	'account_number'  => [ 'account number', 'acct number', 'bank account' ],
	'bank_name'       => [ 'bank name', 'bank' ],
];
$role_keywords          = [ 'role', 'position', 'designation', 'staff role' ];
$qualification_keywords = [ 'qualification', 'qualifications', 'degree', 'degrees' ];
$section_keywords       = [ 'section', 'sections', 'school section' ];
$subject_keywords       = [ 'subject', 'subjects', 'teaching subject' ];

$suggest = [];
foreach ( $pm['staff_fields'] ?? [] as $col => $db ) $suggest[ $db ] = $col;

foreach ( $staff_fields as $db_field => $label ) {
	if ( isset( $suggest[ $db_field ] ) ) continue;
	foreach ( $headers as $h ) {
		$lower = strtolower( trim( $h ) );
		foreach ( $suggest_keywords[ $db_field ] as $kw ) {
			if ( str_contains( $lower, $kw ) ) { $suggest[ $db_field ] = $h; break 2; }
		}
	}
}

// Which name mode a saved profile actually used — defaults to First/Last
// since that was the only mode staff import originally supported.
$name_mode_default = 'first_last';
if ( $pm ) {
	$has_full = isset( $suggest['full_name'] );
	$has_first_last = isset( $suggest['first_name'] ) || isset( $suggest['last_name'] );
	if ( $has_full && ! $has_first_last ) $name_mode_default = 'full_name';
}

$find_by_keywords = function( array $keywords ) use ( $headers ): string {
	foreach ( $headers as $h ) {
		$lower = strtolower( trim( $h ) );
		foreach ( $keywords as $kw ) {
			if ( str_contains( $lower, $kw ) ) return $h;
		}
	}
	return '';
};

$suggested_role_col          = $pm['role_col']          ?? $find_by_keywords( $role_keywords );
$suggested_qualification_col = $pm['qualification_col'] ?? $find_by_keywords( $qualification_keywords );
$suggested_section_col       = $pm['section_col']       ?? $find_by_keywords( $section_keywords );
$suggested_subject_col       = $pm['subject_col']       ?? $find_by_keywords( $subject_keywords );

function kd_staff_header_select( string $name, string $selected, array $headers ): string {
	$html  = '<select name="' . esc_attr( $name ) . '" class="kd-select">';
	$html .= '<option value="">— Skip —</option>';
	foreach ( $headers as $h ) {
		$sel   = ( $h === $selected ) ? ' selected' : '';
		$html .= '<option value="' . esc_attr( $h ) . '"' . $sel . '>' . esc_html( $h ) . '</option>';
	}
	$html .= '</select>';
	return $html;
}

$editing_saved = ( $profile_id > 0 && $pm !== null );
?>

<div class="kd-section">
	<h3>Step 2 — Map Columns<?php if ( $editing_saved ) echo ' <span style="font-size:13px;font-weight:normal;color:#2271b1">(loaded from saved profile — review and adjust)</span>'; ?></h3>
	<p>
		<strong><?php echo count( $headers ); ?> columns · <?php echo intval( $parsed['filtered_row_count'] ); ?> staff rows</strong>
		<span style="color:#666;font-size:12px">&nbsp;(<?php echo intval( $parsed['raw_row_count'] - $parsed['filtered_row_count'] ); ?> empty rows filtered)</span>
	</p>

	<form method="post">
		<input type="hidden" name="kd_step"     value="context_confirm">
		<input type="hidden" name="kd_token"    value="<?php echo esc_attr( $token ); ?>">
		<input type="hidden" name="import_type" value="<?php echo esc_attr( $import_type ); ?>">
		<?php if ( $editing_saved ) : ?>
		<input type="hidden" name="profile_id"  value="<?php echo esc_attr( $profile_id ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'kd_classify', 'kd_classify_nonce' ); ?>

		<!-- ── Staff fields ────────────────────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem">
			<h4 style="margin-top:0">Staff Fields</h4>

			<div style="margin-bottom:.9rem">
				<strong style="font-size:13px;display:block;margin-bottom:.35rem">How is the staff member's name provided in your file?</strong>
				<label style="margin-right:1.25rem;font-weight:normal;font-size:13px">
					<input type="radio" name="name_mode" value="full_name" id="kd-name-mode-full"<?php checked( $name_mode_default, 'full_name' ); ?>>
					Single "Full Name" column
				</label>
				<label style="font-weight:normal;font-size:13px">
					<input type="radio" name="name_mode" value="first_last" id="kd-name-mode-split"<?php checked( $name_mode_default, 'first_last' ); ?>>
					Separate "First Name" + "Last Name" columns
				</label>
				<p class="description" style="margin:.4rem 0 0;font-size:11px">
					With a single Full Name column, it's stored as-is — First/Last Name are left blank rather than guessed apart.
				</p>
			</div>

			<table class="kd-table" style="max-width:680px">
				<thead><tr><th style="width:220px">Field</th><th>CSV Column</th><th style="width:160px">Detected</th></tr></thead>
				<tbody>
				<?php
				$name_field_modes = [ 'full_name' => 'full_name', 'first_name' => 'first_last', 'last_name' => 'first_last' ];
				foreach ( $staff_fields as $db_field => $label ) :
					$suggested = $suggest[ $db_field ] ?? '';
					$required  = in_array( $db_field, [ 'full_name', 'first_name', 'last_name' ], true );
					$name_mode_attr = isset( $name_field_modes[ $db_field ] ) ? ' data-kd-name-field="' . esc_attr( $name_field_modes[ $db_field ] ) . '"' : '';
				?>
				<tr<?php echo $name_mode_attr; ?>>
					<td><strong><?php echo esc_html( $label ); ?></strong><?php if ( $required ) echo ' <span style="color:#a00">*</span>'; ?></td>
					<td><?php echo kd_staff_header_select( 'staff_field_map[' . $db_field . ']', $suggested, $headers ); ?></td>
					<td><?php echo $suggested ? '<span class="kd-badge kd-badge--green">✓ ' . esc_html( $suggested ) . '</span>' : '<span class="kd-badge kd-badge--grey">None</span>'; ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="margin-top:.3rem"><span style="color:#a00">*</span> Required — either a single Full Name column, or First and Last Name combined.</p>
		</div>

		<script>
		(function(){
		    var fullRadio  = document.getElementById('kd-name-mode-full');
		    var splitRadio = document.getElementById('kd-name-mode-split');

		    function applyNameMode() {
		        var mode = (splitRadio && splitRadio.checked) ? 'first_last' : 'full_name';
		        document.querySelectorAll('[data-kd-name-field]').forEach(function(tr) {
		            var show = tr.getAttribute('data-kd-name-field') === mode;
		            tr.style.display = show ? '' : 'none';
		            if (!show) {
		                var sel = tr.querySelector('select');
		                if (sel) sel.value = '';
		            }
		        });
		    }

		    if (fullRadio)  fullRadio.addEventListener('change', applyNameMode);
		    if (splitRadio) splitRadio.addEventListener('change', applyNameMode);
		    applyNameMode();
		})();
		</script>

		<!-- ── Role ────────────────────────────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem;border-left:3px solid #72aee6">
			<h4 style="margin-top:0">Staff Role <span style="font-weight:normal;font-size:12px;color:#666">— optional</span></h4>
			<p class="description">The role text in this column (e.g. "Bursar", "Guidance Counselor") will be matched against your system's roles on the next step — any that can't be auto-matched will ask you to pick the correct one.</p>
			<table class="kd-table" style="max-width:520px">
				<tr>
					<td style="width:220px"><strong>Role Column</strong></td>
					<td><?php echo kd_staff_header_select( 'role_col', $suggested_role_col, $headers ); ?></td>
				</tr>
			</table>
		</div>

		<!-- ── Qualifications ──────────────────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem;border-left:3px solid #72aee6">
			<h4 style="margin-top:0">Qualifications <span style="font-weight:normal;font-size:12px;color:#666">— optional</span></h4>
			<p class="description">If your CSV lists multiple qualifications in one cell, separate them with a pipe character <code>|</code>, e.g. <code>Phd Physics|BSc Chemistry</code>. Each one becomes a separate qualification entry.</p>
			<table class="kd-table" style="max-width:520px">
				<tr>
					<td style="width:220px"><strong>Qualifications Column</strong></td>
					<td><?php echo kd_staff_header_select( 'qualification_col', $suggested_qualification_col, $headers ); ?></td>
				</tr>
			</table>
		</div>

		<!-- ── School sections & subjects ──────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem;border-left:3px solid #72aee6">
			<h4 style="margin-top:0">School Sections &amp; Subjects <span style="font-weight:normal;font-size:12px;color:#666">— optional</span></h4>
			<p class="description">Same pipe-delimited format as Qualifications, e.g. <code>Secondary|Primary</code> or <code>Yoruba|Civic</code>. Each value is matched against your existing sections/subjects by name — anything that doesn't match is skipped with a warning after import (not blocked).</p>
			<table class="kd-table" style="max-width:520px">
				<tr>
					<td style="width:220px"><strong>School Sections Column</strong></td>
					<td><?php echo kd_staff_header_select( 'section_col', $suggested_section_col, $headers ); ?></td>
				</tr>
				<tr>
					<td><strong>Subjects Taught Column</strong></td>
					<td><?php echo kd_staff_header_select( 'subject_col', $suggested_subject_col, $headers ); ?></td>
				</tr>
			</table>
		</div>

		<!-- ── Save profile ───────────────────────────────────────────── -->
		<?php if ( ! $editing_saved ) : ?>
		<div class="kd-section" style="margin-top:1rem;background:var(--bg-medium);border-color:var(--border-primary)">
			<h4 style="margin-top:0">Save Profile</h4>
			<label style="display:flex;align-items:flex-start;gap:.5rem;cursor:pointer">
				<input type="checkbox" id="kd-save-profile-chk" name="do_save_profile" value="1" style="margin-top:3px">
				<span style="color:var(--text-body)"><strong>Save this mapping as a profile for future imports</strong><br>
				<small style="color:var(--tertiary)">Profiles let you skip re-mapping when importing the same file format again.</small></span>
			</label>
			<div id="kd-profile-name-wrap" style="display:none;margin-top:.75rem">
				<label>
					<strong style="color:var(--text-body)">Profile Name</strong><br>
					<input type="text" name="profile_name" id="kd-profile-name" class="regular-text" placeholder="e.g. Termly Staff List" style="margin-top:4px">
					<span id="kd-profile-name-hint" style="font-size:11px;margin-left:.4rem"></span>
				</label>
			</div>
		</div>
		<?php endif; ?>

		<p class="submit" style="margin-top:1.5rem">
			<button type="submit" class="button button-primary">Continue →</button>
			<button type="submit" name="kd_step" value="upload" class="button kd-back-btn" formnovalidate>← Back</button>
			<a href="<?php echo esc_url( $start_over_url ); ?>" class="button">Start Over</a>
		</p>
	</form>
</div>

<script>
(function(){
'use strict';
var existingProfileNames = <?php echo wp_json_encode( $existing_profile_names ?? [] ); ?>;

var saveChk    = document.getElementById('kd-save-profile-chk');
var nameWrap   = document.getElementById('kd-profile-name-wrap');
var nameInput2 = document.getElementById('kd-profile-name');
var nameHint   = document.getElementById('kd-profile-name-hint');

if (saveChk) {
    saveChk.addEventListener('change', function() {
        nameWrap.style.display = this.checked ? '' : 'none';
        if (this.checked && nameInput2) nameInput2.focus();
    });
    if (saveChk.checked) nameWrap.style.display = '';
}
if (nameInput2) {
    nameInput2.addEventListener('input', function() {
        var val = this.value.trim();
        if (!val) { nameHint.textContent = ''; return; }
        if (existingProfileNames.indexOf(val) !== -1) {
            var i = 1;
            while (existingProfileNames.indexOf(val + ' (' + i + ')') !== -1) i++;
            nameHint.textContent = 'Name taken — will save as "' + val + ' (' + i + ')"';
            nameHint.style.color = '#664d03';
        } else {
            nameHint.textContent = 'Available';
            nameHint.style.color = '#0a5c2e';
        }
    });
}
})();
</script>
