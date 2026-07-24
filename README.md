# koodesk-importer

A WordPress plugin for importing school result sheets and student records into the Koodesk school management system. Built for use with [JetEngine](https://crocoblock.com/plugins/jetengine/) Custom Content Types (CCTs) and designed to run on the frontend via shortcodes so school administrators who don't have WordPress backend access can manage their own imports.

---

## Features

### Import Types
- **Academic Records** — imports subject scores, grades, remarks, and term summaries from a class result sheet CSV
- **Students** — creates new student profiles and optionally links them to family records with guardian contact information

### Mapping Profiles
- Map CSV columns to database fields once, save as a named profile
- Reload a saved profile to pre-fill all column mappings for future imports of the same file format
- Auto-detect subjects, assessment columns (1st CA, 2nd CA, Exam, etc.), and term summary columns
- Per-subject "Detect columns" button fetches the best match for each subject from the live CSV headers
- Subject code field on the CCT is used as the authoritative match (e.g. `Eng` → English Language)

### Student Import
- Automatically normalises names from ALL-CAPS to Title Case (preserves legitimate hyphens)
- Matches existing students by name or external student key before creating duplicates
- Updates existing student profiles (gender, DOB, nationality, etc.) without overwriting blank fields

### Family Integration (Families CCT)
- On student import, finds or creates a `families` record using the student's last name (or an explicit "Family Name" CSV column)
- Builds the `guardians` repeater on the family from mapped CSV columns
- Deduplicates guardians across sibling imports — re-importing a second child won't create a duplicate parent entry
- Links student to family via JetEngine relation (ID 796, Families parent → Students child)
- Importing students without any family/guardian data is fully supported
- Home address is stored on the family record, not the student

### Preview & Safety
- Dry-run preview step shows exactly what will be imported before any data is written
- Detects score total mismatches before import (CSV total vs. sum of individual assessment components) with user-selectable resolution
- Flags students who already have records for the same session + term
- Total marks obtainable is calculated automatically as `subject count × 100` when not mapped

### Import History & Undo
- Every completed import is saved to a persistent import history log (WordPress option, no extra DB tables)
- Undo any past import directly from the history page — deletes inserted records, summaries, and newly created students (updated records cannot be auto-reversed)
- Guardian deduplication ensures a sibling's import never orphans the original guardian on undo

### Frontend Support
- All import steps run via shortcode — no WordPress admin access required for school staff
- No-refresh step navigation via AJAX (upload step uses a standard POST; all subsequent steps swap content without a page reload)
- CSS is output directly in the shortcode HTML for guaranteed rendering in Bricks Builder and other page builders

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| JetEngine | Any recent version with CCT module |

The following JetEngine CCTs must be created before use:

| Slug | Purpose |
|---|---|
| `students` | Student profiles |
| `families` | Family records (guardians repeater, home address) |
| `academic_record` | Per-subject scores per student per term |
| `term_academic_summary` | Overall term summary per student |
| `academic_scoring` | Grading scales linked to class groups |

---

## Installation

1. Download the plugin zip and upload to `wp-content/plugins/`
2. Rename the folder to `koodesk-importer` (remove the version suffix)
3. Activate via **Plugins → Installed Plugins**
4. The plugin appears in the WordPress admin as **KD Import** with a **History** submenu

> **Important:** Only one version of the plugin should be installed at a time. Delete any previous version folder before activating a new one.

---

## Shortcodes

### `[koodesk_importer]`

Renders the full multi-step importer on any frontend page. Requires the visitor to be logged in.

```
[koodesk_importer]
```

### `[koodesk_import_history]`

Renders the import history page with undo functionality. Accepts an optional `importer_url` attribute pointing to the page containing `[koodesk_importer]` — used for the "Back to Importer" link. If omitted, the plugin searches published pages for one containing `[koodesk_importer]` automatically.

```
[koodesk_import_history]
[koodesk_import_history importer_url="/data-import/"]
```

---

## Import Flow

```
Upload CSV
    ↓
Map Columns          ← save as profile for reuse
    ↓
Confirm Context      ← verify session, term, class; review full mapping with example values
    ↓
Match Students       ← match CSV rows to existing students or mark as new
    ↓
Preview              ← dry run, score mismatch warnings, existing-record warnings
    ↓
Run Import
    ↓
Result + Undo
```

- **Back buttons** are available on every step except Upload
- **Start Over** is always available and returns to the clean Upload step
- The Upload step always uses a standard page POST (required for file upload); all other steps use AJAX for seamless in-place navigation

---

## CSV Format

### Academic Records

One file per class, per term. First row must be column headers.

| Column type | Example headers |
|---|---|
| Student name | `Name`, `Student Name`, `Full Name` |
| Class | `Class`, `Current Class` |
| Term | `Term` |
| Session | `Session`, `Academic Session` |
| Subject assessment | `Eng 1st CA`, `Maths 2nd CA`, `Basic Sci Exam` |
| Subject total | `Eng Total`, `Basic Sci Total` |
| Subject grade | `Eng Grade` |
| Subject remark | `Computer Remark` |
| Subject position | `Maths Position` |
| Term total | `Total Scores`, `Grand Total` |
| Term average | `Average`, `Average Score` |
| Teacher remark | `Form Teacher Remark`, `Class Teacher Comment` |
| Principal remark | `Principal's Comment`, `Principal Remark` |
| Attendance | `Attendance`, `Days Present` |

Double-spaced headers (e.g. `CCA  1st CA`) are handled correctly — the importer normalises spacing for detection while preserving the original header string for data extraction.

### Students

One row per student.

| Column type | Example headers |
|---|---|
| Full name | `Name`, `Student Name` |
| Gender | `Gender`, `Sex` |
| Date of birth | `DOB`, `Date of Birth` |
| Class | `Class` |
| Admission number | `Admission No`, `Adm No` |
| Registration number | `Reg No`, `Reg Number` |
| Family name (optional) | `Family Name`, `Surname` |
| Home address | `Address`, `Home Address` |
| Guardian name | `Guardian Name`, `Parent Name` |
| Guardian phone | `Guardian Phone`, `Parent Phone` |
| Guardian WhatsApp | `Guardian WhatsApp` |
| Guardian email | `Guardian Email` |
| Guardian relationship | `Relationship` |
| Guardian occupation | `Guardian Occupation` |
| Guardian address | `Guardian Address` |

---

## Subject Detection

The classifier uses a two-pass approach:

1. **Pass 1 (Assessment detection)** — strips known assessment patterns (`1st CA`, `2nd CA`, `Exam`, etc.) from headers and matches the remainder against system subjects using:
   - Subject code (authoritative — set on the CCT record, e.g. `Eng` for English Language)
   - Full subject name
   - Word-level abbreviations (`Maths` → Mathematics, `Agric` → Agricultural Science, `Comp Stud` → Computer Studies)
   - Collapsed abbreviation map (`PHE` → Physical Education, `CRS` → Christian Religious Studies)

2. **Pass 2 (Meta detection)** — identifies Total/Grade/Remark/Position columns, but **only for subjects that already have detected assessment columns**. This prevents ghost subject entries (e.g. "Computer Science" with only a remark column) when two subjects share a common abbreviation prefix.

---

## JetEngine Configuration

### Relation IDs

Update these constants in `includes/class-importer.php` to match your JetEngine relation IDs:

```php
const REL_CLASSES_CLASSGROUP  = 26;   // Classes → Class Groups
const REL_CLASSGROUP_SCORING  = 462;  // Class Groups → Scoring/Grading
const REL_CLASSGROUP_TEMPLATE = 511;  // Class Groups → Assessment Templates
```

Update this constant in `includes/class-family-resolver.php`:

```php
const REL_FAMILY_STUDENT = 796;  // Families (parent) → Students (child)
```

---

## File Structure

```
koodesk-importer/
├── koodesk-importer.php          # Plugin bootstrap, autoloader, DI wiring
├── admin/
│   ├── class-admin-ui.php        # Main controller: routing, AJAX handlers, shortcodes
│   ├── js/
│   │   └── kd-navigation.js     # No-refresh AJAX step navigation
│   └── views/
│       ├── step-upload.php       # Step 1: file upload + profile selection
│       ├── step-classify.php     # Step 2: column mapping UI
│       ├── step-context.php      # Step 3: session/term confirmation + mapping review
│       ├── step-match.php        # Step 4: student matching
│       ├── step-preview.php      # Step 5: dry-run preview
│       ├── step-result.php       # Step 6: import result + undo
│       └── step-history.php      # Import history page (admin + frontend shortcode)
└── includes/
    ├── class-column-classifier.php  # CSV header → field/subject detection
    ├── class-family-resolver.php    # Find-or-create families, guardian dedup, relation linking
    ├── class-file-reader.php        # CSV parsing + session/term extraction
    ├── class-import-history.php     # Persistent import log (WP options)
    ├── class-importer.php           # Import orchestration (plan → execute)
    ├── class-profile-manager.php    # Mapping profile CRUD
    ├── class-serializer.php         # Assessment data serialisation for CCT storage
    ├── class-student-matcher.php    # Fuzzy name matching against existing students
    └── class-transformer.php       # Row → academic record / term summary payloads
```

---

## Import History

The plugin keeps a persistent log of up to 50 imports in a single WordPress option (`kd_import_history`). No additional database tables are created. Each log entry records:

- Import date/time, type, session, term, class
- Profile used
- Counts: students created, records inserted/updated, summaries, rows skipped
- IDs of newly inserted records (for undo)
- Whether the import has been reversed

The history page (available at **KD Import → History** in the admin, or via `[koodesk_import_history]` on the frontend) allows:

- **Undo** — deletes inserted records/summaries/students for any past import
- **Remove entry** — removes a log entry without touching data
- **Clear all** — clears the entire log

> Undo only affects records that were **newly inserted** during that import. Records that were **updated** (already existed) cannot be automatically reversed.

---

## Changelog

### 1.5.0
- **New:** `families` CCT integration — student imports now find-or-create family records, build the `guardians` repeater, and link via JetEngine relation
- **New:** Guardian deduplication across sibling imports
- Home address now stored on family record (`home_address`) rather than on the student

### 1.4.0
- **New:** No-refresh AJAX step navigation via `kd-navigation.js`
- **New:** Import History page with persistent undo (accessible from admin and frontend shortcode)
- **New:** `[koodesk_import_history]` shortcode
- **Fix:** Frontend CSS now renders correctly in Bricks Builder
- **Fix:** Mapped-column detection now handles double-spaced CSV headers (e.g. `CCA  1st CA`)
- **Fix:** `PRINCIPAL'S COMMENT` summary field detection (apostrophe normalisation)
- Total marks obtainable calculated automatically when not mapped in CSV

### 1.3.0
- Two-pass subject classifier: meta columns (Total/Grade/Remark/Position) restricted to subjects with detected assessments — eliminates ghost subject entries
- Subject code on CCT used as authoritative match for CSV prefix detection
- Per-subject "Detect columns" AJAX button
- Subject count badge in mapping UI
- Back buttons on every step

### 1.2.0
- `[koodesk_importer]` frontend shortcode
- Saved mapping profiles with auto-suffix on duplicate names
- "Continue without saving profile" option
- Preview step shows full subject mapping table with issue indicators
- Duplicate record warning before import

### 1.1.0
- Academic records import with assessment serialisation
- Fuzzy student name matching
- Student name normalisation (ALL-CAPS → Title Case, hyphen-safe)
- Position recalculation (subject and class-wide) after import
- Import reversal from result page

---

