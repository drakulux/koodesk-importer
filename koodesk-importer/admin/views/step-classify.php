<?php if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * step-classify.php
 */

$ar_student_fields = [
	'full_name'            => 'Student Name',
	'external_student_key' => 'Admission / Student ID',
	'current_class_name'   => 'Class',
];
$st_student_fields = [
	'full_name'            => 'Full Name',
	'gender'               => 'Gender',
	'date_of_birth'        => 'Date of Birth',
	'current_class_name'   => 'Class',
	'external_student_key' => 'Admission / Student ID',
	'student_reg_number'   => 'Registration Number',
	'nationality'          => 'Nationality',
	'state_of_origin'      => 'State of Origin',
	'religion'             => 'Religion',
	'genotype'             => 'Genotype',
	'phone_number'         => 'Phone Number',
];
$student_fields = ( $import_type === 'academic_records' ) ? $ar_student_fields : $st_student_fields;

// Family fields — these now live on the `families` CCT, not the student.
// A family is automatically found-or-created using the student's last name
// (or an explicit "Family Name" column if mapped). Both fields are optional —
// students can be imported with no family data at all.
$family_fields = [
	'family_name'   => 'Family Name (optional override)',
	'home_address'  => 'Home Address',
];

$summary_db_fields = [
	'term_total_score'   => 'Term Total Score',
	'term_average'       => 'Term Average',
	'position_in_class'  => 'Position in Class',
	'teacher_remark'     => 'Teacher Remark',
	'principal_remark'   => 'Principal Remark',
	'total_marks'        => 'Total Marks Obtainable',
	'attendance_present' => 'Attendance (days present)',
];

// Guardian sub-fields — match the `guardians` repeater on the `families` CCT.
$guardian_sub_fields = [
	'guardian_name'         => 'Guardian / Parent Name',
	'guardian_relationship' => 'Relationship (e.g. Mother, Father)',
	'guardian_phone'        => 'Guardian Phone Number',
	'guardian_whatsapp'     => 'Guardian WhatsApp Number',
	'guardian_email'        => 'Guardian Email',
	'guardian_occupation'   => 'Guardian Occupation',
	'guardian_address'      => 'Guardian Address',
];

$pm = $saved_profile_mapping ?? null;

// Build auto-suggestions from classifier
$suggest = [];
foreach ( $suggestions as $s ) {
	$field = $s['db_field'] ?? '';
	$type  = $s['type']     ?? '';
	if ( $field && ! isset( $suggest[ $field ] ) ) $suggest[ $field ] = $s['header'];
	if ( $type === 'term_col'    ) $suggest['term']    = $s['header'];
	if ( $type === 'session_col' ) $suggest['session'] = $s['header'];
}
if ( $pm ) {
	foreach ( $pm['student_fields'] ?? [] as $col => $db ) $suggest[ $db ] = $col;
	if ( ! empty( $pm['term_col'] ) )    $suggest['term']    = $pm['term_col'];
	if ( ! empty( $pm['session_col'] ) ) $suggest['session'] = $pm['session_col'];
	foreach ( $pm['summary_fields'] ?? [] as $col => $db ) $suggest[ $db ] = $col;
}

// Guardian auto-detect
$guardian_keywords = [
	'guardian_name'         => [ 'guardian name', 'parent name', 'guardian', 'parent', 'father', 'mother' ],
	'guardian_phone'        => [ 'guardian phone', 'parent phone', 'parent contact', 'guardian mobile' ],
	'guardian_email'        => [ 'guardian email', 'parent email' ],
	'guardian_relationship' => [ 'relationship', 'relation' ],
	'guardian_address'      => [ 'guardian address', 'parent address' ],
];
foreach ( $guardian_keywords as $gf => $kws ) {
	if ( isset( $suggest[ $gf ] ) ) continue;
	if ( $pm && isset( $pm['guardian_fields'][ $gf ] ) ) { $suggest[ $gf ] = $pm['guardian_fields'][ $gf ]; continue; }
	foreach ( $headers as $h ) {
		$lower = strtolower( trim( $h ) );
		foreach ( $kws as $kw ) { if ( str_contains( $lower, $kw ) ) { $suggest[ $gf ] = $h; break 2; } }
	}
}

// Detect class from first row
$detected_class_name = '';
if ( ! empty( $suggest['current_class_name'] ) && ! empty( $parsed['rows'][0] ) ) {
	$detected_class_name = trim( (string)( $parsed['rows'][0][ $suggest['current_class_name'] ] ?? '' ) );
}
$detected_class_id = 0;
foreach ( $system_classes as $sc ) {
	if ( strtolower( trim( $sc['class_name'] ) ) === strtolower( $detected_class_name ) ) {
		$detected_class_id = (int)$sc['_ID']; break;
	}
}

// Build detected_subjects from saved profile or classifier
$subjects_by_id    = array_column( $known_subjects, null, '_ID' );
$detected_subjects = []; // only populated from saved profile for pre-fill
if ( $pm && ! empty( $pm['subjects'] ) ) {
	foreach ( $pm['subjects'] as $subj ) {
		$sid = (int)( $subj['subject_id'] ?? 0 );
		if ( ! $sid ) continue;
		$detected_subjects[] = [
			'subject_id'   => $sid,
			'subject_name' => $subj['subject_name'],
			'subject_code' => $subjects_by_id[ $sid ]['subject_code'] ?? '',
			'assessments'  => $subj['assessments'] ?? [],
			'meta'         => [
				'total_col'    => $subj['total_col']    ?? '',
				'grade_col'    => $subj['grade_col']    ?? '',
				'remark_col'   => $subj['remark_col']   ?? '',
				'position_col' => $subj['position_col'] ?? '',
			],
		];
	}
}

// Count auto-detectable subjects (for the auto-add button label)
$classifier_subject_count = 0;
$seen_subject_ids = [];
foreach ( $suggestions as $s ) {
	$sid = (int)( $s['subject_id'] ?? 0 );
	if ( $sid && $s['type'] === 'subject_score' && ! in_array( $sid, $seen_subject_ids ) ) {
		$seen_subject_ids[] = $sid;
		$classifier_subject_count++;
	}
}

// Shared assessment labels from classifier
$detected_labels = [];
foreach ( $suggestions as $s ) {
	$lbl = $s['assessment_hint'] ?? '';
	if ( $lbl && ! in_array( $lbl, $detected_labels ) ) $detected_labels[] = $lbl;
}
if ( empty( $detected_labels ) ) $detected_labels = [ '', '' ];

function kd_header_select( string $name, string $selected, array $headers, string $attrs = '' ): string {
	$html  = '<select name="' . esc_attr( $name ) . '" class="kd-select"' . ( $attrs ? " $attrs" : '' ) . '>';
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
	<p style="display:flex;align-items:baseline;gap:1.5rem;flex-wrap:wrap">
		<span>
			<strong><?php echo count( $headers ); ?> columns · <?php echo intval( $parsed['filtered_row_count'] ); ?> student rows</strong>
			<span style="color:#666;font-size:12px">&nbsp;(<?php echo intval( $parsed['raw_row_count'] - $parsed['filtered_row_count'] ); ?> empty rows filtered)</span>
		</span>
		<span style="font-size:12px;color:#666;background:#f6f7f7;padding:3px 9px;border-radius:10px;border:1px solid #dcdcde">
			<span style="display:inline-block;width:10px;height:10px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:2px;vertical-align:middle;margin-right:3px"></span>
			 greyed out in dropdown = column already mapped elsewhere
		</span>
	</p>

	<form method="post" id="kd-classify-form">
		<input type="hidden" name="kd_step"     value="context_confirm">
		<input type="hidden" name="kd_token"    value="<?php echo esc_attr( $token ); ?>">
		<input type="hidden" name="import_type" value="<?php echo esc_attr( $import_type ); ?>">
		<?php if ( $editing_saved ) : ?>
		<input type="hidden" name="profile_id"  value="<?php echo esc_attr( $profile_id ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'kd_classify', 'kd_classify_nonce' ); ?>

		<?php if ( $import_type === 'academic_records' && ! empty( $templates ) ) : ?>
		<p style="margin-bottom:1rem"><label><strong>Assessment Template (optional)</strong><br>
			<select name="assessment_template_id" class="regular-text">
				<option value="0">— None —</option>
				<?php foreach ( $templates as $tpl ) : ?>
					<option value="<?php echo esc_attr( $tpl['_ID'] ); ?>"><?php echo esc_html( $tpl['title'] ?? 'Template #' . $tpl['_ID'] ); ?></option>
				<?php endforeach; ?>
			</select></label>
		</p>
		<?php endif; ?>

		<!-- ── 1. Student / Context fields ──────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem">
			<h4 style="margin-top:0"><?php echo $import_type === 'academic_records' ? 'Student &amp; Context Fields' : 'Student Fields'; ?></h4>
			<table class="kd-table" style="max-width:680px">
				<thead><tr><th style="width:200px">Field</th><th>CSV Column</th><th style="width:160px">Detected</th></tr></thead>
				<tbody>
				<?php
				$required_fields = $import_type === 'academic_records' ? [ 'full_name', 'current_class_name', 'term', 'session' ] : [ 'full_name' ];
				$fields = $student_fields;
				if ( $import_type === 'academic_records' ) { $fields['term'] = 'Term'; $fields['session'] = 'Session'; }
				foreach ( $fields as $db_field => $label ) :
					$suggested = $suggest[ $db_field ] ?? '';
					$required  = in_array( $db_field, $required_fields, true );
				?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong><?php if ( $required ) echo ' <span style="color:#a00">*</span>'; ?></td>
					<td>
					<?php if ( $db_field === 'current_class_name' && ! empty( $system_classes ) ) : ?>
						<?php echo kd_header_select( 'student_field_map[' . $db_field . ']', $suggested, $headers, 'id="kd-class-col-select"' ); ?>
						<br><small style="color:#666;font-size:11px;display:block;margin-top:3px">Confirm system class:</small>
						<select name="class_confirm_id" id="kd-class-confirm" class="kd-select" style="max-width:240px;font-size:12px;margin-top:2px">
							<option value="">— No override —</option>
							<?php foreach ( $system_classes as $sc ) : ?>
								<option value="<?php echo esc_attr( $sc['_ID'] ); ?>"<?php selected( (int)$sc['_ID'], $detected_class_id ); ?>><?php echo esc_html( $sc['class_name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php echo kd_header_select( 'student_field_map[' . $db_field . ']', $suggested, $headers ); ?>
					<?php endif; ?>
					</td>
					<td><?php echo $suggested ? '<span class="kd-badge kd-badge--green">✓ ' . esc_html( $suggested ) . '</span>' : '<span class="kd-badge kd-badge--grey">None</span>'; ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $import_type === 'academic_records' ) echo '<p class="description" style="margin-top:.3rem"><span style="color:#a00">*</span> Required</p>'; ?>
		</div>

		<?php if ( $import_type === 'academic_records' ) : ?>

		<!-- ── 2. Assessment labels ───────────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem">
			<h4 style="margin-top:0">Assessment Labels <span style="font-weight:normal;font-size:12px;color:#666">— define once, shared by all subjects</span></h4>
			<p class="description">e.g. <strong>1st CA</strong>, <strong>2nd CA</strong>, <strong>Exam</strong> — these labels appear under each subject below.</p>
			<table class="kd-table" style="max-width:360px">
				<thead><tr><th>Label</th><th style="width:40px"></th></tr></thead>
				<tbody id="kd-labels-body">
				<?php foreach ( $detected_labels as $lbl ) : ?>
				<tr>
					<td><input type="text" name="shared_labels[]" value="<?php echo esc_attr( $lbl ); ?>" class="regular-text kd-shared-label" placeholder="e.g. 1st CA"></td>
					<td><button type="button" class="button button-small kd-remove-row">✕</button></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<button type="button" class="button button-small" id="kd-add-label-btn" style="margin-top:.4rem">+ Add Label</button>
		</div>

		<!-- ── 3. Subjects ────────────────────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem">
			<h4 style="margin-top:0">
				Subjects in This File
				<span id="kd-subject-count-badge" style="display:inline-block;margin-left:.5rem;background:#2271b1;color:#fff;border-radius:12px;padding:1px 10px;font-size:12px;font-weight:700;vertical-align:middle">0</span>
			</h4>
			<p class="description">Add each subject and map its CSV columns. Use <em>Detect columns</em> on each subject to auto-fill based on the selected subject name.</p>

			<?php if ( $classifier_subject_count > 0 && ! $editing_saved ) : ?>
			<p style="margin-bottom:.75rem">
				<button type="button" class="button" id="kd-autodetect-btn">
					✦ Auto-add all <?php echo $classifier_subject_count; ?> detected subjects
				</button>
				<span class="description" style="margin-left:.5rem">Adds all subjects detected from the CSV. Review after clicking.</span>
			</p>
			<?php endif; ?>

			<div id="kd-subjects-wrap"></div>
			<button type="button" class="button" id="kd-add-subject-btn" style="margin-top:.75rem">+ Add Subject</button>
		</div>

		<!-- ── 4. Term summary ────────────────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem">
			<h4 style="margin-top:0">Term Summary Columns <span style="font-weight:normal;font-size:12px;color:#666">— optional</span></h4>
			<table class="kd-table" style="max-width:750px">
				<thead><tr><th style="width:200px">Field</th><th>CSV Column</th><th>Detected</th></tr></thead>
				<tbody>
				<?php foreach ( $summary_db_fields as $db_field => $label ) :
					$suggested = $suggest[ $db_field ] ?? ''; ?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong></td>
					<td><?php echo kd_header_select( 'summary_field_map[' . $db_field . ']', $suggested, $headers ); ?></td>
					<td><?php echo $suggested ? '<span class="kd-badge kd-badge--green">✓ ' . esc_html( $suggested ) . '</span>' : '<span class="kd-badge kd-badge--grey">None</span>'; ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php endif; // academic_records ?>

		<?php if ( $import_type === 'students' ) : ?>
		<!-- ── Family Information ──────────────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem;border-left:3px solid #72aee6">
			<h4 style="margin-top:0">Family Information <span style="font-weight:normal;font-size:12px;color:#666">— optional</span></h4>
			<p class="description">
				Students are linked to a <strong>family</strong> record (shared by siblings).
				By default the family is found or created using the student's <strong>last name</strong> —
				you can map an explicit "Family Name" column instead if your CSV has one.
				If you skip this whole section, students will still import — they just won't be linked to a family.
			</p>
			<table class="kd-table" style="max-width:680px">
				<thead><tr><th style="width:240px">Field</th><th>CSV Column</th><th style="width:160px">Detected</th></tr></thead>
				<tbody>
				<?php foreach ( $family_fields as $db_field => $label ) :
					$suggested = $suggest[ $db_field ] ?? ''; ?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong></td>
					<td><?php echo kd_header_select( 'family_field_map[' . $db_field . ']', $suggested, $headers ); ?></td>
					<td><?php echo $suggested ? '<span class="kd-badge kd-badge--green">✓ ' . esc_html( $suggested ) . '</span>' : '<span class="kd-badge kd-badge--grey">None</span>'; ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- ── Guardian / Parent Contact ───────────────────────────────── -->
		<div class="kd-section" style="margin-top:1rem;border-left:3px solid #72aee6">
			<h4 style="margin-top:0">Guardian / Parent Contact <span style="font-weight:normal;font-size:12px;color:#666">— optional</span></h4>
			<p class="description">
				Map these columns if your CSV includes guardian information.
				Each piece of contact information must be in its own separate CSV column
				(e.g. one column for <em>Parent Name</em>, another for <em>Parent Phone</em>).
				One guardian entry per student row is added to that student's <strong>family</strong> record
				(into the <code>guardians</code> repeater) — siblings sharing the same family will share this guardian automatically.
			</p>
			<table class="kd-table" style="max-width:680px">
				<thead><tr><th style="width:240px">Guardian Field</th><th>CSV Column</th><th style="width:160px">Detected</th></tr></thead>
				<tbody>
				<?php foreach ( $guardian_sub_fields as $sub_field => $label ) :
					$suggested = $suggest[ $sub_field ] ?? ''; ?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong><br><code style="font-size:11px;color:#666"><?php echo esc_html( $sub_field ); ?></code></td>
					<td><?php echo kd_header_select( 'guardian_field_map[' . $sub_field . ']', $suggested, $headers ); ?></td>
					<td><?php echo $suggested ? '<span class="kd-badge kd-badge--green">✓ ' . esc_html( $suggested ) . '</span>' : '<span class="kd-badge kd-badge--grey">None</span>'; ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

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
					<input type="text" name="profile_name" id="kd-profile-name" class="regular-text" placeholder="e.g. JSS1A Term Result Sheet" style="margin-top:4px">
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

<?php
// ── Pre-compute all JS data in PHP before the script block ──────────────
// This avoids complex inline PHP-inside-wp_json_encode patterns that can
// produce malformed JSON and silently break the entire script.

$_js_headers = $headers;

$_sorted_subjects = $known_subjects;
usort( $_sorted_subjects, fn( $a, $b ) => strcasecmp( $a['subject_name'] ?? '', $b['subject_name'] ?? '' ) );
$_js_subjects = array_values( array_map( function( $s ) {
    return [
        'id'   => (int) $s['_ID'],
        'name' => $s['subject_name'],
        'code' => $s['subject_code'] ?? '',
    ];
}, $_sorted_subjects ) );

// Pre-filled subjects (from saved profile only — empty array for new imports)
$_js_prefilled = $detected_subjects;  // already built in PHP above the form

// Classifier-detected subjects (for the auto-add button)
$_classifier_by_id = [];
foreach ( $suggestions as $_s ) {
    $_sid = (int) ( $_s['subject_id'] ?? 0 );
    if ( ! $_sid ) continue;
    $_subj = $subjects_by_id[ $_sid ] ?? null;
    if ( ! $_subj ) continue;
    if ( ! isset( $_classifier_by_id[ $_sid ] ) ) {
        $_classifier_by_id[ $_sid ] = [
            'subject_id'   => $_sid,
            'subject_name' => $_subj['subject_name'],
            'subject_code' => $_subj['subject_code'] ?? '',
            'assessments'  => [],
            'meta'         => (object) [],
        ];
    }
    if ( ( $_s['type'] ?? '' ) === 'subject_score' && ( $_s['assessment_hint'] ?? '' ) !== '' ) {
        $_classifier_by_id[ $_sid ]['assessments'][] = [
            'label' => $_s['assessment_hint'],
            'col'   => $_s['header'],
        ];
    }
    if ( ( $_s['type'] ?? '' ) === 'subject_meta' && ( $_s['meta_role'] ?? '' ) !== '' ) {
        $_classifier_by_id[ $_sid ]['meta']->{ $_s['meta_role'] } = $_s['header'];
    }
}
$_js_classifier_detected = array_values( $_classifier_by_id );

$_js_existing_profile_names = $existing_profile_names ?? [];
$_js_ajax_url               = admin_url( 'admin-ajax.php' );
$_js_nonce                  = wp_create_nonce( 'kd_classify_subject' );
$_js_token                  = $token;
?>

<script>
(function(){
'use strict';

// ── Data from PHP ─────────────────────────────────────────────────────────
var headers              = <?php echo wp_json_encode( $_js_headers ); ?>;
var subjects             = <?php echo wp_json_encode( $_js_subjects ); ?>;
var preFilled            = <?php echo wp_json_encode( $_js_prefilled ); ?>;
var classifierDetected   = <?php echo wp_json_encode( $_js_classifier_detected ); ?>;
var existingProfileNames = <?php echo wp_json_encode( $_js_existing_profile_names ); ?>;
var ajaxUrl              = <?php echo wp_json_encode( $_js_ajax_url ); ?>;
var nonce                = <?php echo wp_json_encode( $_js_nonce ); ?>;
var token                = <?php echo wp_json_encode( $_js_token ); ?>;

// ── DOM refs ──────────────────────────────────────────────────────────────
var wrap = document.getElementById('kd-subjects-wrap');
var si   = 0;

// ── Utility ───────────────────────────────────────────────────────────────
function esc(s) {
    s = String(s == null ? '' : s);
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Build <option> list for a header <select>.
// Values are stored verbatim; only displayed text is HTML-escaped.
function headerOpts(selected) {
    var html = '<option value="">— Skip —</option>';
    headers.forEach(function(h) {
        var sel = (h === selected) ? ' selected' : '';
        html += '<option value="' + esc(h) + '"' + sel + '>' + esc(h) + '</option>';
    });
    return html;
}

function sharedLabels() {
    return Array.from(document.querySelectorAll('.kd-shared-label'))
        .map(function(el) { return el.value.trim(); })
        .filter(Boolean);
}

function updateCount() {
    var blocks = wrap.querySelectorAll('.kd-subject-block');
    var badge  = document.getElementById('kd-subject-count-badge');
    if (badge) badge.textContent = blocks.length;
    blocks.forEach(function(b, i) {
        var num = b.querySelector('.kd-subj-num');
        if (num) num.textContent = i + 1;
    });
    // Refresh mapped indicators whenever block structure changes
}


// ── Build one subject block ───────────────────────────────────────────────
function buildAssessRow(blockIdx, rowIdx, label, col) {
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td style="width:130px">'
        + '<input type="text"'
        + ' name="subject_map[' + blockIdx + '][assessments][' + rowIdx + '][label]"'
        + ' value="' + esc(label) + '"'
        + ' class="regular-text kd-assess-label" placeholder="e.g. 1st CA">'
        + '</td>'
        + '<td>'
        + '<select name="subject_map[' + blockIdx + '][assessments][' + rowIdx + '][col]" class="kd-select">'
        + headerOpts(col)
        + '</select>'
        + '</td>'
        + '<td style="width:28px">'
        + '<button type="button" class="button button-small kd-remove-assess-row"'
        + ' style="padding:2px 6px;color:#a00" title="Remove">x</button>'
        + '</td>';
    return tr;
}

function buildBlock(idx, subjectId, subjectName, subjectCode, assessments, meta) {
    meta = meta || {};

    var items = (assessments && assessments.length)
        ? assessments
        : sharedLabels().map(function(l) { return { label: l, col: '' }; });
    if (!items.length) items = [{ label: '', col: '' }];

    // Subject dropdown options
    var subjectOpts = '<option value="">— Select subject —</option>';
    subjects.forEach(function(s) {
        subjectOpts +=
            '<option value="' + s.id + '"'
            + (s.id == subjectId ? ' selected' : '')
            + ' data-code="' + esc(s.code) + '">'
            + esc(s.name)
            + '</option>';
    });

    // Meta selects (right column)
    var metaFields = [
        { key: 'total_col',    label: 'Total Score' },
        { key: 'grade_col',    label: 'Grade'       },
        { key: 'remark_col',   label: 'Remark'      },
        { key: 'position_col', label: 'Position'    },
    ];
    var metaHTML = '';
    metaFields.forEach(function(f) {
        metaHTML +=
            '<tr>'
            + '<td style="width:100px;font-size:12px;color:#666;padding:5px 8px"><strong>' + f.label + '</strong></td>'
            + '<td style="padding:5px 8px">'
            + '<select name="subject_map[' + idx + '][' + f.key + ']" class="kd-select" style="font-size:12px">'
            + headerOpts(meta[f.key] || '')
            + '</select>'
            + '</td>'
            + '</tr>';
    });

    // Display number
    var displayNum = wrap.querySelectorAll('.kd-subject-block').length + 1;

    // Create the block element using createElement + textContent / createElement
    // instead of one massive innerHTML to avoid any encoding issues.
    var div = document.createElement('div');
    div.className = 'kd-subject-block';
    div.id = 'kd-subj-' + idx;

    // Header row (number badge + subject select + buttons)
    var header = document.createElement('div');
    header.style.cssText = 'display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:.65rem';

    var numBadge = document.createElement('span');
    numBadge.className = 'kd-subj-num';
    numBadge.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#2271b1;color:#fff;font-size:11px;font-weight:700;flex-shrink:0';
    numBadge.textContent = displayNum;
    header.appendChild(numBadge);

    var subjSel = document.createElement('select');
    subjSel.name = 'subject_map[' + idx + '][subject_id]';
    subjSel.className = 'kd-subj-select';
    subjSel.style.cssText = 'max-width:260px;font-size:13px';
    subjSel.innerHTML = subjectOpts;
    header.appendChild(subjSel);

    var nameInput = document.createElement('input');
    nameInput.type = 'hidden';
    nameInput.name = 'subject_map[' + idx + '][subject_name]';
    nameInput.className = 'kd-subj-name';
    nameInput.value = subjectName;
    header.appendChild(nameInput);

    var codeInput = document.createElement('input');
    codeInput.type = 'hidden';
    codeInput.name = 'subject_map[' + idx + '][subject_code]';
    codeInput.className = 'kd-subj-code';
    codeInput.value = subjectCode;
    header.appendChild(codeInput);

    var detectBtn = document.createElement('button');
    detectBtn.type = 'button';
    detectBtn.className = 'button button-small kd-detect-cols';
    detectBtn.setAttribute('data-si', idx);
    detectBtn.title = 'Auto-fill columns for this subject';
    detectBtn.textContent = 'Detect columns';
    header.appendChild(detectBtn);

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'button button-small kd-remove-subj';
    removeBtn.style.cssText = 'color:#a00;border-color:#a00';
    removeBtn.textContent = 'Remove';
    header.appendChild(removeBtn);

    div.appendChild(header);

    // Body row (assessments left, meta right)
    var body = document.createElement('div');
    body.style.cssText = 'display:flex;gap:1.25rem;align-items:flex-start;flex-wrap:wrap';

    // Left: Assessment columns
    var leftCol = document.createElement('div');
    leftCol.style.cssText = 'flex:1;min-width:360px';

    var leftLabel = document.createElement('div');
    leftLabel.style.cssText = 'font-size:11px;font-weight:700;color:var(--tertiary);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem';
    leftLabel.textContent = 'Assessment columns';
    leftCol.appendChild(leftLabel);

    var table = document.createElement('table');
    table.className = 'kd-table';
    table.style.marginBottom = '.3rem';
    table.innerHTML =
        '<thead><tr>'
        + '<th style="width:130px">Label</th>'
        + '<th>CSV Column</th>'
        + '<th style="width:28px"></th>'
        + '</tr></thead>';

    var tbody = document.createElement('tbody');
    tbody.className = 'kd-assess-body';
    tbody.setAttribute('data-si', idx);
    items.forEach(function(a, ai) {
        tbody.appendChild(buildAssessRow(idx, ai, a.label || '', a.col || ''));
    });
    table.appendChild(tbody);
    leftCol.appendChild(table);

    var addRowBtn = document.createElement('button');
    addRowBtn.type = 'button';
    addRowBtn.className = 'button button-small kd-add-assess';
    addRowBtn.setAttribute('data-si', idx);
    addRowBtn.textContent = '+ Row';
    leftCol.appendChild(addRowBtn);
    body.appendChild(leftCol);

    // Right: Meta columns (Total / Grade / Remark / Position)
    var rightCol = document.createElement('div');
    rightCol.style.minWidth = '280px';

    var rightLabel = document.createElement('div');
    rightLabel.style.cssText = 'font-size:11px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem';
    rightLabel.textContent = 'Total / Grade / Remark / Position';
    rightCol.appendChild(rightLabel);

    var metaTable = document.createElement('table');
    metaTable.className = 'kd-table';
    metaTable.innerHTML = '<tbody>' + metaHTML + '</tbody>';
    rightCol.appendChild(metaTable);
    body.appendChild(rightCol);

    div.appendChild(body);

    // ── Wire up events ────────────────────────────────────────────────────

    // Subject select → update hidden name/code fields
    subjSel.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        nameInput.value = opt.textContent.trim();
        codeInput.value = opt.getAttribute('data-code') || '';
    });

    // Remove subject block
    removeBtn.addEventListener('click', function() {
        div.remove();
        updateCount();
    });

    // Detect columns via AJAX
    detectBtn.addEventListener('click', function() {
        var sid = parseInt(subjSel.value, 10);
        if (!sid) { alert('Please select a subject first.'); return; }

        detectBtn.disabled = true;
        detectBtn.textContent = 'Detecting...';

        var fd = new FormData();
        fd.append('action', 'kd_classify_for_subject');
        fd.append('nonce', nonce);
        fd.append('subject_id', sid);
        fd.append('subject_name', nameInput.value);
        fd.append('subject_code', codeInput.value);
        fd.append('kd_token', token);

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                detectBtn.disabled = false;
                detectBtn.textContent = 'Detect columns';
                if (!res.success) { alert('Detection failed: ' + (res.data || 'unknown')); return; }

                var det = res.data;

                // Refill assessment rows
                tbody.innerHTML = '';
                var detItems = (det.assessments && det.assessments.length)
                    ? det.assessments
                    : sharedLabels().map(function(l) { return { label: l, col: '' }; });
                if (!detItems.length) detItems = [{ label: '', col: '' }];
                detItems.forEach(function(a, ai) {
                    tbody.appendChild(buildAssessRow(idx, ai, a.label, a.col));
                });

                // Fill meta selects
                metaFields.forEach(function(f) {
                    if (!det[f.key]) return;
                    var sel = div.querySelector('select[name="subject_map[' + idx + '][' + f.key + ']"]');
                    if (sel) sel.value = det[f.key];
                });
            })
            .catch(function() {
                detectBtn.disabled = false;
                detectBtn.textContent = 'Detect columns';
                alert('Network error. Please try again.');
            });
    });

    return div;
}

// ── Auto-add detected subjects (button click only) ────────────────────────
var autoBtn = document.getElementById('kd-autodetect-btn');
if (autoBtn) {
    autoBtn.addEventListener('click', function() {
        wrap.innerHTML = '';
        si = 0;
        classifierDetected.forEach(function(d) {
            wrap.appendChild(buildBlock(si++, d.subject_id, d.subject_name, d.subject_code, d.assessments, d.meta));
        });
        autoBtn.textContent = classifierDetected.length + ' subjects added — review below';
        autoBtn.disabled = true;
        updateCount();
    });
}

// ── Pre-fill from saved profile (on page load) ────────────────────────────
if (preFilled.length > 0) {
    preFilled.forEach(function(d) {
        wrap.appendChild(buildBlock(si++, d.subject_id, d.subject_name, d.subject_code || '', d.assessments, d.meta));
    });
    updateCount();
}

// ── Add subject manually ──────────────────────────────────────────────────
document.getElementById('kd-add-subject-btn').addEventListener('click', function() {
    wrap.appendChild(buildBlock(si++, 0, '', '', null, {}));
    updateCount();
});

// ── Assessment row: add / remove (delegated on wrap) ─────────────────────
wrap.addEventListener('click', function(e) {
    // Add row
    var addBtn = e.target.closest('.kd-add-assess');
    if (addBtn) {
        var blockIdx = addBtn.getAttribute('data-si');
        var targetBody = wrap.querySelector('.kd-assess-body[data-si="' + blockIdx + '"]');
        if (targetBody) {
            var rowIdx = targetBody.querySelectorAll('tr').length;
            targetBody.appendChild(buildAssessRow(blockIdx, rowIdx, '', ''));
        }
        return;
    }
    // Remove row
    var rmBtn = e.target.closest('.kd-remove-assess-row');
    if (rmBtn) {
        var row = rmBtn.closest('tr');
        if (!row) return;
        var parentBody = row.closest('tbody');
        row.remove();
        // Re-index remaining rows
        if (parentBody) {
            var bIdx = parentBody.getAttribute('data-si');
            parentBody.querySelectorAll('tr').forEach(function(r, i) {
                var inp = r.querySelector('input[type="text"]');
                var sel = r.querySelector('select');
                if (inp) inp.name = 'subject_map[' + bIdx + '][assessments][' + i + '][label]';
                if (sel) sel.name = 'subject_map[' + bIdx + '][assessments][' + i + '][col]';
            });
        }
    }
});

// ── Shared label rows: add / remove ──────────────────────────────────────
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('kd-remove-row')) {
        var row = e.target.closest('tr');
        if (row) row.remove();
    }
});

document.getElementById('kd-add-label-btn').addEventListener('click', function() {
    var labelBody = document.getElementById('kd-labels-body');
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="text" name="shared_labels[]" value=""'
        + ' class="regular-text kd-shared-label" placeholder="e.g. Exam"></td>'
        + '<td><button type="button" class="button button-small kd-remove-row">✕</button></td>';
    labelBody.appendChild(tr);
});

// ── Save-profile checkbox ─────────────────────────────────────────────────
var saveChk   = document.getElementById('kd-save-profile-chk');
var nameWrap  = document.getElementById('kd-profile-name-wrap');
var nameInput2 = document.getElementById('kd-profile-name');
var nameHint  = document.getElementById('kd-profile-name-hint');

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

// Run once on page load to mark any pre-filled duplicates

})();
</script>

