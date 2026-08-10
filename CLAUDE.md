# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

DO NOT USE SUBAGENT FOR ANY REQUESTS RELATED TO THIS MODULE. The code is complex and stateful, and the subagent's lack of execution context and memory will lead to confusion and errors.

use ../EXTERNAL_MODULES_INDEX.md for general instructions on working with REDCap External Modules in this codebase.

use ../REDCAP_TECHNICAL_REFERENCE.md for general REDCap development guidelines and best practices.
## Running Tests

```bash
# Run the planFieldChanges unit test suite (32 tests, no database required)
php tests/planFieldChanges_test.php
```

No build step or package install is required. The test file is self-contained and runs standalone.

## Architecture Overview

This is a **REDCap External Module (EM)** built on the REDCap EM framework (`AbstractExternalModule`). It integrates REDCap projects with OnCore (a clinical trial management system) by syncing participant data bidirectionally.

### Entry points

- **`OnCoreIntegration.php`** — Main module class. Extends `AbstractExternalModule`. Defines all constants (`ONCORE_STUDY_SITE`, `REDCAP_ENTITY_*`, etc.) and lazy-initializes service objects (`getMapping()`, `getProtocols()`, `getSubjects()`, etc.).
- **`ajax/handler.php`** — Action dispatcher for all AJAX calls. The `action` POST param routes to the appropriate service method. All site migration actions are routed here too.
- **`pages/`** — PHP pages rendered by REDCap (project-level and control-center). Pages access the module via the injected `$module` variable.
- **`config.json`** — Declares EM metadata, settings keys, cron jobs, AJAX action allowlist, and control-center/project links.

### Core service classes (`classes/`)

| Class | Responsibility |
|-------|---------------|
| `Protocols.php` | OnCore protocol lookup and IRB matching |
| `Subjects.php` | Subject sync logic (pull OnCore → REDCap, push REDCap → OnCore) |
| `Mapping.php` | Manages `redcap-oncore-fields-mapping` (OC↔RC field mapping + value mapping) |
| `Users.php` | OnCore user/contact role checks |
| `Entities.php` | REDCap Entity API wrappers (entity tables for protocols, subjects, linkage) |
| `SiteMigration.php` | Study-site rename/merge/sunset migration engine (see below) |

### Settings storage

All persistent state lives in `redcap_external_modules_settings` via the EM framework:
- `redcap-oncore-fields-mapping` (project) — JSON: field map + value_mapping (OC label → RC code)
- `redcap-oncore-project-site-studies` (project) — JSON array: project's site name subset
- `libraries` (system) — repeatable sub_settings: library name, field definitions, study sites list
- `migration-in-progress` (system) — flag that pauses all 4 OnCore crons during a migration run

### Site Migration feature (`classes/SiteMigration.php`)

The most complex subsystem. See `SITE_MIGRATION_PLAN.md` for the full spec.

**Rule types:** `rename`, `merge`, `keep`, `sunset` (with/without `merged_into` target).

**Five migration layers applied per project:**
1. Library settings — update site names in `library-oncore-study-sites`
2. Project site subset — update `redcap-oncore-project-site-studies`
3. Value mapping — add new OC→RC entries; retain old entries for stale-payload compat
4. Field labels (`element_enum` in `redcap_metadata`) — in-place relabel (rename) or allocate new code + suffix old codes `(retired, merged into X)` (merge/sunset)
5. Record values — `UPDATE redcap_data{,2..8}` old code → new code (merge and target-bound sunset only). Each rewritten record is also logged via `\REDCap::logEvent()` attached to that record (see logging invariants).

**Key methods:**
- `planFieldChanges(int $pid, array $rules)` — pure planner; returns a plan array with `label_updates`, `record_migrations`, `code_allocations`, `site_code_map`. Mutates a working copy of `$codes` (not the DB).
- `buildHumanChanges(array $rules, array $plan, array $recordCounts = [])` — pure; turns a plan into per-rule plain-English items (`headline`, `lines`, `records`, `kind`) for the UI. Only rules that affect *this* project are returned. Surfaced as `human_changes` in the preview / migrate AJAX responses.
- `applyFieldChanges(int $pid, array $plan)` — executes the plan inside a per-project transaction.
- `getRecordsWithSiteCode(int $pid, string $field, string $code)` — returns the exact `[{record,event_id,instance}]` rows `updateRecordValues()` will rewrite (identical WHERE), captured *before* the UPDATE for per-record logging.
- `logRecordMigrationsToREDCap(int $pid, string $field, array $migrations)` — post-commit, best-effort; one `\REDCap::logEvent()` per rewritten record, capped at `PER_RECORD_LOG_CAP` (then one summary entry per code pair).
- `allocateNewCode(array $codes)` — returns `max(numeric codes) + 1`.
- `getRcCodeForSite(string $site, array $vmap)` — strict string equality lookup in value_mapping.
- `findCodeForLabel(array $codes, string $label)` — finds RC code by element_enum label (strips retired suffixes); fallback when vmap is incomplete.
- `labelAlreadyRetired(string $label)` — matches `(retired\b` pattern (independent of the suffix phrase).
- `stripRetiredSuffix(string $label)` — removes any `(retired…)` trailing suffix.

**Critical invariants:**
- A code whose label is already retired still gets a `record_migrations` entry (the prior run may have crashed before the data write). Only `label_updates` is skipped.
- The MERGE loop falls back to `findCodeForLabel` when `getRcCodeForSite` returns null — the project's vmap may be incomplete.
- Rename checks `stripRetiredSuffix(existingLabel) === newSite` (idempotent) and guards against renaming already-retired codes (which would strip the retirement marker).
- `site_code_map` in the plan is the authoritative new_site → RC code map; `updateValueMapping` uses it to avoid stale vmap fallback.
- Shard writes use `getDataTable($pid)` and validate the returned table name against `/^redcap_data[2-8]?$/` before interpolating into SQL.
- **`element_enum` delimiter is ` \n ` (literal backslash-n), NOT `|` and NOT a real newline.** REDCap core serializes choices with `implode(" \n ", …)` and converts `\n`↔`|` only for ODM/XML export. `parseElementEnum`/`serializeElementEnum` must use `\n`; a `|` round-trip collapses the whole field into one bogus choice `{1: <entire string>}`, which silently breaks code lookup, record migration, and makes `allocateNewCode` return 2 (`max(1)+1`). Test fixtures must use ` \n ` too. Note: `mysql` CLI escapes `\`→`\\` in non-`--raw` output, so feed byte-exact values (HEX-decode) when reproducing against the live DB.

**Logging invariants:**
- Raw `redcap_data*` UPDATEs bypass REDCap's native data-change logging, so each rewritten row is recorded with `\REDCap::logEvent($desc, "$field = '$newCode'…", '', $record, $eventId, $pid)`. The public API forces event type `"OTHER"`, so entries appear in the record's **Logging** trail (not the field-level Data-History popup). `$record` must be captured *before* the UPDATE (same WHERE as the rewrite) or the captured set won't match the moved set.
- Empty capture → no log (also prevents duplicate logs when a completed/retried project re-runs and the UPDATE matches 0 rows).
- Per-record logging is best-effort and runs *after* COMMIT — a logging failure never rolls back the migration. Capped at `PER_RECORD_LOG_CAP` per project to bound request time.
- `logToREDCap()` still writes one project-level summary entry; `logToEntity()` writes the structured audit rows *inside* the transaction.

### Duplicate / value_mapping cleanup (remediation)

`planStudySiteCleanup(pid)` / `applyStudySiteCleanup(pid, $acknowledged)` repair residue left by pre-delimiter-fix runs:
- **Duplicate `element_enum` codes** (identical trimmed labels, e.g. two "Emeryville") → consolidate onto one canonical code, repoint records (logged), drop the extras. Canonical = the **most-referenced** code in project logic (tie → lowest); removing a still-referenced code sets `requires_ack` and `applyStudySiteCleanup` refuses without `acknowledged=true` (deletion, unlike the migration's relabel, leaves references dangling).
- **`value_mapping`** → repoint each `{oc,rc}` so `rc = findCodeForLabel(cleanedCodes, oc)` (fixes "Main Hospital → 2" artifacts), de-dupe identical pairs, and **leave entries whose `oc` has no current label match untouched** (backward-compat — e.g. a renamed OnCore name, or `SCI-LPCH→23`). Applies to both pull and push directions.
- Transactional + idempotent (second run → `noop`); post-commit logging isolated. AJAX: `previewStudySiteCleanup` / `applyStudySiteCleanup`. UI: per-row "Clean up" button in the Preview tab.

**Tests (160 total):** `planFieldChanges_test.php` (planner + delimiter regression, 36), `recordLogging_test.php` (capture / `buildHumanChanges` / per-record logging via global `\REDCap` stub, 28), `processProject_test.php` (transaction + post-commit isolation, 16), `elementEnum_test.php` (parse/serialize/round-trip corruption guard, byte-exact fixtures, 21), `cleanup_test.php` (dup consolidation, ack gating, idempotency, vmap backward-compat, isolation, 31), `repeatingInstances_test.php` (repeating-instrument read flatten + save-row split, 28 — see `REPEATING_INSTRUMENTS_FIX.md`).

### Repeating instruments (sync support)

`\REDCap::getData` nests fields on repeating instruments/events under `[record]['repeat_instances'][event][form][instance][field]`, but the module reads `$record[$eventId][$field]` everywhere. `Subjects::flattenRepeatingInstances()` (applied after every `getData`) merges instance data up to the event level (ascending instances, first non-empty wins, event-level values take precedence). On writes, `Subjects::prepareRowsForSave()` splits save rows per repeating form and adds `redcap_repeat_instrument`/`redcap_repeat_instance = 1` (JSON imports reject repeating-form fields without them). Full background in `REPEATING_INSTRUMENTS_FIX.md`.

### Test file load-order constraint

When writing new standalone tests (no PHPUnit), the declaration order in the test file is mandatory:

1. Declare `trait emLoggerTrait` stub **before** `require_once SiteMigration.php` — PHP resolves `use emLoggerTrait` at class-definition time.
2. `require_once SiteMigration.php`
3. Declare `class OnCoreIntegration` stub with all required constants (`ONCORE_STUDY_SITE`, `REDCAP_ONCORE_FIELDS_MAPPING_NAME`, `REDCAP_ENTITY_ONCORE_SITE_MIGRATION`).
4. Declare mock/helper classes.

Use `ReflectionClass::newInstanceWithoutConstructor()` + `ReflectionProperty::setValue()` to inject a mock into `SiteMigration::$module` (private property) without calling the real constructor.
