# OnCore Study Site Migration — Implementation Plan

**Module:** OnCore Integration v9.9.9  
**Author:** ihabz  
**Date:** 2026-05-18 (rev 2 — added sharded data tables + May 2026 Stanford worksheet)  
**Status:** Planning  
**Source mapping:** `Subject Study Site Worksheet.pdf`

---

## Table of Contents

1. [Problem Statement](#1-problem-statement)
2. [Design Decisions & Constraints](#2-design-decisions--constraints)
3. [Data Model — What Actually Changes](#3-data-model--what-actually-changes)
4. [Open Decision Points (Answered)](#4-open-decision-points-answered)
5. [Rule Types & Detailed Effects](#5-rule-types--detailed-effects)
6. [New Database Entities](#6-new-database-entities)
7. [New PHP Class: SiteMigration](#7-new-php-class-sitemigration)
8. [New AJAX Actions](#8-new-ajax-actions)
9. [Execution Flow](#9-execution-flow)
10. [Control Center Page UI](#10-control-center-page-ui)
11. [Cron Guard Pattern](#11-cron-guard-pattern)
12. [Audit & Logging Strategy](#12-audit--logging-strategy)
13. [Performance Strategy](#13-performance-strategy)
14. [Files to Create / Modify](#14-files-to-create--modify)
15. [Implementation Sequence](#15-implementation-sequence)
16. [REDCap Sharded Data Tables](#16-redcap-sharded-data-tables)

---

## 1. Problem Statement

OnCore periodically renames study sites or merges multiple sites into one. When this happens:

- The REDCap field mapped to `studySites` still contains old coded values and old option labels
- The `redcap-oncore-fields-mapping` value mapping still references old OnCore site name strings
- The project-level site subset (`redcap-oncore-project-site-studies`) still lists old names
- The system-level library site list (`library-oncore-study-sites`) still lists old names
- Future OnCore syncs send new site names that fail to resolve to REDCap coded values

The concrete trigger for this initiative is the May 2026 Stanford site re-org documented in `Subject Study Site Worksheet.pdf` (encoded as Appendix C below): nine renames plus four merges, with a handful of sites explicitly marked "Leave as-is".

The goal is a **Control Center administration page** that allows a super-user to define rename/merge/keep rules and apply them retroactively across all ~100 active projects — without modifying the underlying `redcap_data*` tables at all. (Note: REDCap shards record data across up to eight tables — `redcap_data`, `redcap_data2`, … `redcap_data8`. The migration deliberately stays on the metadata / settings side of the line and never has to choose a shard for its writes. See [Section 16](#16-redcap-sharded-data-tables).)

---

## 2. Design Decisions & Constraints

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Migrate `redcap_data` records | **No** | The REDCap coded values stay intact. Historical data remains unchanged. |
| Handle OnCore sync forward-compat | **Yes, via value_mapping** | Add new site name → same REDCap code entry. Old entries kept for backward compat. |
| Update REDCap field option labels | **Yes — suffix only** | Researchers see `"Old Site (changed to New Site)"` — no data loss, full traceability. |
| Update entity subject table | **Not applicable** | `redcap_entity_oncore_subjects` stores person-level demographics only; no `studySites` column exists in that entity. Study site lives in `redcap_metadata` labels and value mappings. |
| Execution granularity | **Per-project atomic transactions** | Each project either fully succeeds or fully rolls back. Already-completed projects are never re-processed. |
| Live progress | **Client-driven AJAX polling** | One project per AJAX call avoids PHP timeouts. Client polls every ~1.5 s and updates the UI. |
| Disable syncs during migration | **Yes — system-level flag** | All four cron jobs check `migration-in-progress` system setting and exit early if set. |
| Rule sets | **Persistent + re-runnable** | Rules saved as an entity record. Admin can re-run on newly-linked projects or after partial failures. |
| Dry-run / preview | **Yes — count preview + CSV export** | Preview shows per-project impact before any writes. CSV lists every label change and mapping change. |
| Rollback | **Per-project transaction rollback** | If a project fails mid-way, its transaction rolls back. Completed projects are not rewound. |
| Audit trail | **Both entity log + REDCap audit log** | Entity log for internal tooling; `REDCap::logEvent()` so the research team can see changes in the built-in audit trail. |
| Multi-rule execution | **All rules in one pass** | Admin defines N rename + M merge + K keep rules, saves them, runs once. All applied atomically per project. |

---

## 3. Data Model — What Actually Changes

Study site names flow through five independent storage locations. **`redcap_data` is not touched.**

### 3.1 System Setting — Library Site List

**Setting key:** `library-oncore-study-sites` (sub_settings inside the `libraries` repeatable sub_settings)  
**Internal sub_settings key:** `library-study-site`  
**Loaded by:** `OnCoreIntegration::getSubSettingsValuesAsArray($library['library-oncore-study-sites'], 'library-study-site')`  
**Change:** Remove old site entries, add new site entries within the relevant library.

### 3.2 Project Setting — Site Subset

**Setting key:** `redcap-oncore-project-site-studies`  
**Format:** JSON-encoded array of site name strings e.g. `["Stanford Hospital", "Palo Alto VA"]`  
**Read by:** `Mapping::getProjectSiteStudies()`  
**Write by:** `Mapping::setProjectSiteStudies()`  
**Change:** Replace old site names with new site names in this array.

### 3.3 Project Setting — Field Value Mapping

**Setting key:** `redcap-oncore-fields-mapping`  
**Format:** JSON with structure:
```json
{
  "pull": {
    "studySites": {
      "redcap_field": "site_dropdown",
      "event": "baseline_arm_1",
      "field_type": "dropdown",
      "value_mapping": [
        { "oc": "Stanford Hospital", "rc": "1" },
        { "oc": "Palo Alto VA",      "rc": "2" }
      ]
    }
  },
  "push": { ... }
}
```
**Change (rename):** Add a new `{"oc": "New Site Name", "rc": "same_rc_code"}` entry. Retain the old entry for backward compatibility with any historical OnCore API responses still referencing the old name.  
**Change (merge):** Add `{"oc": "Merged Site Name", "rc": "PRIMARY_CODE"}` where `PRIMARY_CODE` is the admin-selected primary target code. Both old entries are retained.

### 3.4 REDCap Metadata — Field Option Labels

**Table:** `redcap_metadata`  
**Column:** `element_enum`  
**Format:** Pipe-delimited coded options: `"1, Stanford Hospital | 2, Palo Alto VA | 3, Menlo Park VA"`  
**Change:** Suffix the label of each old site's option.  

| Rule type | Before | After |
|-----------|--------|-------|
| Rename | `1, Stanford Hospital` | `1, Stanford Hospital (changed to Stanford Medical Center)` |
| Merge | `2, Palo Alto VA` | `2, Palo Alto VA (merged into VA Palo Alto HCS)` |
| Merge | `3, Menlo Park VA` | `3, Menlo Park VA (merged into VA Palo Alto HCS)` |

**Implementation note:** The mapped REDCap field name is retrieved from `redcap-oncore-fields-mapping` → `pull.studySites.redcap_field`. Only the specific instrument field in that project is updated. Uses a parameterized SQL UPDATE for performance (no `REDCap::getData` round-trip).

`redcap_metadata` is **not sharded** — see [Section 16](#16-redcap-sharded-data-tables) — so the project-scoped `UPDATE redcap_metadata WHERE project_id = ?` is safe regardless of which `redcap_data*` shard the project lives in.

### 3.5 What This Migration Does NOT Touch

| Table | Sharded? | Touched by migration? | Why |
|-------|----------|------------------------|-----|
| `redcap_data` … `redcap_data8` | **Yes** | **No** | Historical coded values stay intact. Records that hold the old code keep that code; only the option *label* gets a suffix. |
| `redcap_log_event` (and shards) | Yes | Indirectly, via `REDCap::logEvent()` | The REDCap API routes to the correct shard automatically. We never query it with raw SQL. |
| `redcap_metadata` | No (single table) | **Yes** — `element_enum` updated per project | Project scope enforced via `WHERE project_id = ?`. |
| `redcap_external_modules_settings` | No | Yes — project-level and system-level settings | Standard EM settings API. |
| `redcap_entity_oncore_*` (this EM) | No | Yes — new rows in 3 entity types | New tables added in this migration. |

The "no shard math required" property is a load-bearing design decision: it means the migration is auditable as a settings/metadata operation, not as a data migration.

---

## 4. Open Decision Points (Answered)

Original draft left these open. The May 2026 Stanford worksheet (Appendix C) answers them concretely.

### 4.1 Merge — Primary Code Selection

**Answer: Option A (admin selects in UI), with a convention seeded from the worksheet.**

Convention from the worksheet: in each merge group, the row whose disposition is **"Rename"** is the primary; all other rows in that group have the disposition **"Move to Line N (NewName)"** or **"Sunset"** and merge into the primary's rc code.

Example (Main Hospital group):

| Old site | Disposition | Role |
|----------|-------------|------|
| SCI-Palo Alto | Rename → Main Hospital | **Primary** — its rc code becomes "Main Hospital"'s code |
| SHC Main Hosp, Pasteur, Welch & campus/nearby clinics | Move to "Main Hospital" | Merge into primary |
| SHC Satellite & Other | Move to "Main Hospital" | Merge into primary |

The UI still surfaces a primary dropdown so the admin can override; the default selection is the row with the "Rename" disposition flag.

### 4.2 Library Scope — Single vs. Multiple Libraries

**Answer: Treat as potentially multi-library, but the May 2026 worksheet is single-library (Stanford).**

The worksheet's "Under Stanford?" column shows three sites currently outside Stanford (SCI-South Bay, SCI-Emeryville, SCI - Livermore line… though Livermore is marked Yes — only **SCI-South Bay** and **SCI-Emeryville** are flagged `No`). For those, the OnCore-side org move ("move under Stanford") is a manual SCI step done outside REDCap. By the time the REDCap migration runs, those sites are expected to already be in OnCore's Stanford library.

Implications:
- **Within scope:** rename/merge/keep rules **inside a single library**.
- **Out of scope for v1:** moving a site from one EM library to another. If the SCI library exists as a separate EM library, an admin still has to manually add the new name to the Stanford library and remove from SCI. The UI shows the library selector when more than one library is configured.

### 4.3 System Site List — Replace vs. Append

**Answer: Replace** (old name removed from `library-oncore-study-sites`, new name added) — both names retained in `value_mapping` for backward compat with any OnCore record still emitting the old name during the cutover window.

Sunset rows (e.g., **SHC - Emeryville**, retired 2025-09-04) are also **removed** from the library list. The value_mapping entry for the sunset name is retained so that any in-flight historical OnCore payload still resolves; no new value_mapping entry is added for the sunset name's "new" mapping because OnCore will never emit it again.

---

## 5. Rule Types & Detailed Effects

Four rule types: `rename`, `merge`, `keep`, `sunset`. Examples below use real names from the May 2026 worksheet.

### 5.1 Rule: Rename

```
old_site:  "SHC Tri-Valley"
new_site:  "Tri-Valley"
type:      rename
```

| Layer | Before | After |
|-------|--------|-------|
| Library site list | `["SHC Tri-Valley", ...]` | `["Tri-Valley", ...]` |
| Project site subset | `["SHC Tri-Valley"]` | `["Tri-Valley"]` |
| Value mapping | `[{"oc":"SHC Tri-Valley","rc":"7"}]` | `[{"oc":"SHC Tri-Valley","rc":"7"}, {"oc":"Tri-Valley","rc":"7"}]` |
| Field label | `7, SHC Tri-Valley` | `7, SHC Tri-Valley (changed to Tri-Valley)` |

### 5.2 Rule: Merge

```
old_sites: ["SCI-Palo Alto", "SHC Main Hosp, Pasteur, Welch & campus/nearby clinics", "SHC Satellite & Other"]
new_site:  "Main Hospital"
primary:   "SCI-Palo Alto"   ← row marked "Rename" in worksheet
type:      merge
```

| Layer | Before | After |
|-------|--------|-------|
| Library site list | `["SCI-Palo Alto", "SHC Main Hosp...", "SHC Satellite & Other", ...]` | `["Main Hospital", ...]` |
| Project site subset | `["SCI-Palo Alto", "SHC Main Hosp..."]` | `["Main Hospital"]` |
| Value mapping | `[{"oc":"SCI-Palo Alto","rc":"1"},{"oc":"SHC Main Hosp...","rc":"4"},{"oc":"SHC Satellite & Other","rc":"9"}]` | …unchanged… **plus** `{"oc":"Main Hospital","rc":"1"}` |
| Field label — SCI-Palo Alto (primary) | `1, SCI-Palo Alto` | `1, SCI-Palo Alto (changed to Main Hospital)` |
| Field label — SHC Main Hosp... | `4, SHC Main Hosp...` | `4, SHC Main Hosp... (merged into Main Hospital)` |
| Field label — SHC Satellite & Other | `9, SHC Satellite & Other` | `9, SHC Satellite & Other (merged into Main Hospital)` |

Note: the primary's label uses the **"changed to"** suffix (it's effectively a rename whose code is being reused); the secondary rows use **"merged into"**.

### 5.3 Rule: Keep

```
site:  "Byers Eye Institute"
type:  keep
```

No changes applied. Site passes through migration untouched. Used explicitly so preview/history can report "intentionally left alone" vs. "rule missing".

### 5.4 Rule: Sunset

```
old_site:        "SHC - Emeryville"
retired_on:      "2025-09-04"
merged_into:     "Emeryville"    ← optional; for label suffix only
type:            sunset
```

Variant of merge for sites OnCore has retired and will never emit again.

| Layer | Behavior |
|-------|----------|
| Library site list | Old name removed. No new entry added (handled by the related rename/merge rule). |
| Project site subset | Old name removed. |
| Value mapping | Old entry retained for in-flight backward compat. **No new entry added** — OnCore will not emit this name again. |
| Field label | `N, SHC - Emeryville` → `N, SHC - Emeryville (retired 2025-09-04, merged into Emeryville)` |

---

## 6. New Database Entities

Three new entity types added inside `redcap_entity_types()` in `OnCoreIntegration.php`.

### 6.1 `redcap_entity_oncore_site_migration`

Stores persistent migration rule sets.

| Column | Type | Description |
|--------|------|-------------|
| `name` | text | Human-readable name e.g. "Q2 2026 Site Restructuring" |
| `description` | text | Optional notes |
| `rules` | json | Serialized array of rule objects (see schema below) |
| `library_index` | integer | Which library index these rules apply to (null = all) |
| `status` | text | `draft` \| `active` \| `completed` |
| `created_by` | text | REDCap username |
| `created_at` | integer | Unix timestamp |
| `updated_at` | integer | Unix timestamp |

**Rule JSON schema:**
```json
[
  {
    "id": "rule-1",
    "type": "rename",
    "old_sites": ["SHC Tri-Valley"],
    "new_site": "Tri-Valley",
    "primary_old_site": null,
    "retired_on": null
  },
  {
    "id": "rule-2",
    "type": "merge",
    "old_sites": [
      "SCI-Palo Alto",
      "SHC Main Hosp, Pasteur, Welch & campus/nearby clinics",
      "SHC Satellite & Other"
    ],
    "new_site": "Main Hospital",
    "primary_old_site": "SCI-Palo Alto",
    "retired_on": null
  },
  {
    "id": "rule-3",
    "type": "keep",
    "old_sites": ["Byers Eye Institute"],
    "new_site": null,
    "primary_old_site": null,
    "retired_on": null
  },
  {
    "id": "rule-4",
    "type": "sunset",
    "old_sites": ["SHC - Emeryville"],
    "new_site": "Emeryville",
    "primary_old_site": null,
    "retired_on": "2025-09-04"
  }
]
```

### 6.2 `redcap_entity_oncore_site_migration_log`

Per-change audit log. One row per atomic change per project.

| Column | Type | Description |
|--------|------|-------------|
| `migration_id` | integer | FK → `redcap_entity_oncore_site_migration.id` |
| `project_id` | integer | REDCap project ID |
| `rule_id` | text | Rule UUID from rule set |
| `change_type` | text | `library_setting` \| `project_subset` \| `value_mapping` \| `field_label` |
| `field_name` | text | REDCap field name (for `field_label` changes) |
| `old_value` | text | The value before change |
| `new_value` | text | The value after change |
| `migrated_by` | text | REDCap username who ran the migration |
| `migrated_at` | integer | Unix timestamp |

### 6.3 `redcap_entity_oncore_migration_project_status`

Tracks which projects have been processed for each rule set, enabling safe re-runs and progress resumption.

| Column | Type | Description |
|--------|------|-------------|
| `migration_id` | integer | FK → `redcap_entity_oncore_site_migration.id` |
| `project_id` | integer | REDCap project ID |
| `status` | text | `pending` \| `in_progress` \| `completed` \| `failed` \| `skipped` |
| `changes_applied` | integer | Count of individual changes made |
| `completed_at` | integer | Unix timestamp |
| `error_message` | text | Error detail if `failed` |

---

## 7. New PHP Class: SiteMigration

**File:** `classes/SiteMigration.php`  
**Namespace:** `Stanford\OnCoreIntegration`

### Public API

```php
// Rule set management
public function getRuleSet(int $id): array
public function listRuleSets(): array
public function saveRuleSet(array $data): int           // returns entity id
public function deleteRuleSet(int $id): void

// Preview (no writes)
public function previewMigration(int $ruleSetId): array  // per-project impact counts
public function exportPreviewCSV(int $ruleSetId): string // returns CSV content

// Execution
public function startMigration(int $ruleSetId): string   // returns sessionId
public function processNextProject(string $sessionId): array  // processes one project, returns progress
public function getMigrationStatus(string $sessionId): array
public function finalizeMigration(string $sessionId): void

// Internal — called by processNextProject
private function processProject(int $projectId, array $rules, int $migrationId): array
private function updateLibrarySettings(array $rules, int $libraryIndex): void  // runs once
private function updateProjectSiteSubset(int $pid, array $rules): array
private function updateValueMapping(int $pid, array $rules): array
private function updateFieldLabels(int $pid, array $rules): array  // SQL UPDATE — redcap_metadata (not sharded)
private function logToEntity(int $pid, int $migrationId, array $changes): void
private function logToREDCap(int $pid, array $summary): void

// Shard-aware preview helpers (read-only; only used if previewIncludesRecordCounts is true)
private function getProjectDataTable(int $pid): string         // returns redcap_data / redcap_data2 / ...
private function countRecordsWithSiteCode(int $pid, string $fieldName, string $rcCode): int

// Cron gate
public static function isMigrationInProgress(): bool
public function disableCrons(): void
public function enableCrons(): void
```

### Sharding rules for the class

- **Writes:** all writes are to non-sharded tables (`redcap_metadata`, `redcap_external_modules_settings`, EM entity tables) or via APIs that handle sharding internally (`REDCap::logEvent()`). No `redcap_data*` write.
- **Reads (optional preview only):** when computing record-level usage counts, `getProjectDataTable($pid)` resolves the correct shard. Implementation should prefer the framework method `$this->module->getDataTable($pid)` (per `EXTERNAL_MODULES_INDEX.md`, "Data Methods"); if not available in the deployed framework version, fall back to `SELECT data_table FROM redcap_projects WHERE project_id = ?`.
- **Never** hard-code the literal `redcap_data` table name in any SQL emitted by this class.

### `processProject` — Per-Project Transaction

```php
private function processProject(int $projectId, array $rules, int $migrationId): array
{
    // 1. Skip if already migrated for this rule set
    if ($this->isProjectAlreadyMigrated($projectId, $migrationId)) {
        return ['status' => 'skipped', 'project_id' => $projectId];
    }

    $this->markProjectStatus($projectId, $migrationId, 'in_progress');
    $changes = [];

    try {
        $this->module->query('START TRANSACTION', []);

        $changes[] = $this->updateProjectSiteSubset($projectId, $rules);
        $changes[] = $this->updateValueMapping($projectId, $rules);
        $changes[] = $this->updateFieldLabels($projectId, $rules);

        $this->logToEntity($projectId, $migrationId, $changes);
        $this->logToREDCap($projectId, $changes);

        $this->module->query('COMMIT', []);
        $this->markProjectStatus($projectId, $migrationId, 'completed', count($changes));

        return ['status' => 'completed', 'project_id' => $projectId, 'changes' => count($changes)];

    } catch (\Throwable $e) {
        $this->module->query('ROLLBACK', []);
        $this->markProjectStatus($projectId, $migrationId, 'failed', 0, $e->getMessage());
        return ['status' => 'failed', 'project_id' => $projectId, 'error' => $e->getMessage()];
    }
}
```

### `updateFieldLabels` — SQL Pattern

```php
private function updateFieldLabels(int $pid, array $rules): array
{
    // Get the REDCap field mapped to studySites for this project
    $mapping = $this->getStudySiteMapping($pid);
    if (!$mapping) return [];  // not mapped in this project

    $fieldName  = $mapping['redcap_field'];
    $valueMap   = $mapping['value_mapping'];  // [{"oc":"...","rc":"..."}]

    // Build current element_enum from redcap_metadata
    $result = $this->module->query(
        'SELECT element_enum FROM redcap_metadata WHERE project_id = ? AND field_name = ? LIMIT 1',
        [$pid, $fieldName]
    );
    $row = $result->fetch_assoc();
    if (!$row) return [];

    $enum = $row['element_enum'];
    $changes = [];

    foreach ($rules as $rule) {
        if ($rule['type'] === 'keep') continue;

        $suffix = $rule['type'] === 'rename'
            ? "(changed to {$rule['new_site']})"
            : "(merged into {$rule['new_site']})";

        foreach ($rule['old_sites'] as $oldSite) {
            // Find the rc code for this old site via value_mapping
            $rcCode = $this->getRcCodeForSite($oldSite, $valueMap);
            if ($rcCode === null) continue;

            // Suffix the label for this code in element_enum
            // element_enum format: "1, Label One | 2, Label Two"
            $oldPattern = "/(\b{$rcCode},\s*)([^|]+?)(\s*\||\s*$)/";
            $newEnum = preg_replace_callback($oldPattern, function ($m) use ($suffix) {
                $label = rtrim($m[2]);
                // Avoid double-suffixing on re-run
                if (str_contains($label, '(changed to') || str_contains($label, '(merged into')) {
                    return $m[0];
                }
                return $m[1] . $label . ' ' . $suffix . $m[3];
            }, $enum);

            if ($newEnum !== $enum) {
                $changes[] = [
                    'change_type' => 'field_label',
                    'field_name'  => $fieldName,
                    'old_value'   => $oldSite,
                    'new_value'   => $oldSite . ' ' . $suffix,
                ];
                $enum = $newEnum;
            }
        }
    }

    if (!empty($changes)) {
        $this->module->query(
            'UPDATE redcap_metadata SET element_enum = ? WHERE project_id = ? AND field_name = ?',
            [$enum, $pid, $fieldName]
        );
    }

    return $changes;
}
```

---

## 8. New AJAX Actions

Added to `auth-ajax-actions` in `config.json`. All require authenticated super-user context.

(Total: 13 actions, +1 from the original draft to expose the optional shard-aware deep preview.)

| Action | Description | Returns |
|--------|-------------|---------|
| `listSiteMigrationRuleSets` | List all saved rule sets | `[{id, name, status, created_at, ...}]` |
| `getSiteMigrationRuleSet` | Load single rule set with full rules | `{id, name, rules, ...}` |
| `saveSiteMigrationRuleSet` | Create or update a rule set | `{id}` |
| `deleteSiteMigrationRuleSet` | Delete a draft rule set | `{ok: true}` |
| `previewSiteMigration` | Dry-run preview — counts only (settings-level overlap; cheap) | `{projects: [{pid, name, affectedSites, labelChanges, status}]}` |
| `previewSiteMigrationDeep` | Optional record-level usage counts (queries the project's sharded `redcap_data*` shard via `getProjectDataTable($pid)`) | `{projects: [{pid, name, affectedSites, labelChanges, recordsAffected, status}]}` |
| `exportMigrationPreview` | Generate CSV blob | CSV string |
| `startSiteMigration` | Initialize migration session | `{sessionId, total, projects: [...]}` |
| `processNextMigrationProject` | Process one project, advance cursor | `{current, total, projectId, projectName, status, errors}` |
| `getMigrationStatus` | Current progress snapshot | `{current, total, completed, failed, skipped, inProgress}` |
| `finalizeMigration` | Close session, re-enable crons | `{ok: true, summary: {...}}` |
| `getMigrationHistory` | List past completed migrations | `[{id, name, completedAt, projectCount, ...}]` |
| `getMigrationProjectLog` | Per-project changes for a migration | `[{change_type, field_name, old_value, new_value, ...}]` |

---

## 9. Execution Flow

### Sequence Diagram

```
Admin Browser                     Server (AJAX)                    Database
──────────────                    ─────────────                    ────────

1. Save rule set          ──────► saveSiteMigrationRuleSet  ──────► INSERT entity_site_migration
   (name + rules)         ◄──────  {id: 42}

2. Click Preview          ──────► previewSiteMigration(42)
                                   - loads all OnCore-enabled projects
                                   - per project: checks site subset overlap
                                   - counts label changes per project
                          ◄──────  {projects: [{pid, name, hits: 3, status: "pending"}, ...]}

3. Export CSV (optional)  ──────► exportMigrationPreview(42)
                          ◄──────  CSV blob → browser download

4. Click Run Migration    ──────► startSiteMigration(42)
                                   - setSystemSetting('migration-in-progress', true)
                                   - updateLibrarySettings(rules)  ← system-level, done once
                                   - build ordered project list (skip already migrated)
                                   - store in entity as session record
                          ◄──────  {sessionId: "sess-abc", total: 87, projects: [...]}

5. Poll loop begins
   ┌─────────────────────────────────────────────────────────────────────────
   │ (repeats every ~1.5 s until current === total)
   │
   │  processNextMigrationProject ──────►  load next pending project from session
   │  (sessionId: "sess-abc")              BEGIN TRANSACTION
   │                                        updateProjectSiteSubset(pid, rules)
   │                                        updateValueMapping(pid, rules)
   │                                        updateFieldLabels(pid, rules)
   │                                        logToEntity(pid, migrationId, changes)
   │                                        logToREDCap(pid, summary)
   │                                        markProjectStatus(pid, 'completed')
   │                                       COMMIT
   │                              ◄──────  {current: N, total: 87, projectName: "...",
   │                                        status: "completed"|"failed"|"skipped",
   │                                        changesApplied: 4, errors: []}
   │
   │  Update UI row for project N  (✓ green / ✗ red / ⟳ skipped)
   └─────────────────────────────────────────────────────────────────────────

6. current === total      ──────► finalizeMigration("sess-abc")
                                   - setSystemSetting('migration-in-progress', false)
                                   - mark rule set status = 'completed'
                          ◄──────  {ok: true, summary: {completed: 85, failed: 1, skipped: 1}}

7. Show completion modal
   with per-status counts
   and link to History tab
```

### Idempotency

- `processNextProject` checks `redcap_entity_oncore_migration_project_status` before touching a project.
- If a project shows `completed` for this `migration_id`, it is returned as `skipped` immediately.
- The label suffix code checks for existing `(changed to` / `(merged into` substrings before appending, preventing double-suffixing on re-runs.
- Value mapping additions check for duplicate `oc` keys before inserting.

---

## 10. Control Center Page UI

**File:** `pages/site_migration.php`  
**Control Center link:** "OnCore Site Migration"  
**Icon:** `fas fa-exchange-alt`  
**Access:** Super-user only (enforced server-side)

### Tab 1 — Rule Sets

```
┌─────────────────────────────────────────────────────────┐
│  OnCore Study Site Migration                [+ New Rule Set] │
├─────────────────────────────────────────────────────────┤
│  Name                      │ Status  │ Last Run  │ Actions  │
│  ─────────────────────────────────────────────────────  │
│  Q2 2026 Site Restructuring │ active  │ —         │ Edit Preview Run Delete │
│  2025 VA Consolidation      │ completed│ 2025-11-03│ View Log                │
└─────────────────────────────────────────────────────────┘
```

### Tab 2 — Rule Editor

```
Name: [Q2 2026 Stanford Site Restructuring     ]
Description: [Per Subject Study Site Worksheet ]
Library: [Stanford ▼]   (shown only if multiple libraries detected)

Study Sites from Library
──────────────────────────────────────────────────────────────────────────────────
  Site Name                                                  │ Action                    │ Target / Group
  ──────────────────────────────────────────────────────────────────────────────
  SCI-Palo Alto                                              │ ○Keep ○Rename ●Merge ○Sunset │ Main Hospital  (group MH)
  SHC Main Hosp, Pasteur, Welch & campus/nearby clinics      │ ○Keep ○Rename ●Merge ○Sunset │ Main Hospital  (group MH)
  SHC Satellite & Other                                      │ ○Keep ○Rename ●Merge ○Sunset │ Main Hospital  (group MH)
  SCI-LPCH                                                   │ ○Keep ○Rename ●Merge ○Sunset │ Children's Hospital (group CH)
  LPCH Main Hosp, Welch Rd & campus/nearby clinics           │ ○Keep ○Rename ●Merge ○Sunset │ Children's Hospital (group CH)
  LPCH Satellite & Other                                     │ ○Keep ○Rename ●Merge ○Sunset │ Children's Hospital (group CH)
  SHC Redwood City                                           │ ○Keep ○Rename ●Merge ○Sunset │ Redwood City   (group RC)
  SCI-Redwood City                                           │ ○Keep ○Rename ●Merge ○Sunset │ Redwood City   (group RC)
  SCI-Emeryville                                             │ ○Keep ○Rename ●Merge ○Sunset │ Emeryville     (group EM)
  SHC - Emeryville                                           │ ○Keep ○Rename ○Merge ●Sunset │ Emeryville  (retired 2025-09-04)
  Quarry Rd clinics;Hoover Pavilion                          │ ○Keep ●Rename ○Merge ○Sunset │ Quarry Rd clinics/Hoover Pavilion
  Psychiatry: Page Mill, Porter Dr, other                    │ ○Keep ●Rename ○Merge ○Sunset │ Page Mill/Porter Dr
  SHC Tri-Valley                                             │ ○Keep ●Rename ○Merge ○Sunset │ Tri-Valley
  SCI-South Bay                                              │ ○Keep ●Rename ○Merge ○Sunset │ South Bay
  SCI - Livermore                                            │ ○Keep ●Rename ○Merge ○Sunset │ Livermore
  1070 Arastradero, Byers Eye Institute, CTRU (800 Welch Rd),│
  Remote interactions, Lucas Center, Community site,         │ ●Keep ○Rename ○Merge ○Sunset │ —
  Center for Cognitive..., Stanford Ear Institute            │

  Merge Group MH primary: [SCI-Palo Alto ▼]   ← row marked "Rename" in worksheet; admin can override
  Merge Group CH primary: [SCI-LPCH ▼]
  Merge Group RC primary: [SHC Redwood City ▼]
  Merge Group EM primary: [SCI-Emeryville ▼]

[ Save as Draft ]  [ Save & Activate ]
```

### Tab 3 — Preview

```
Rule Set: [Q2 2026 Site Restructuring ▼]
[ Generate Preview ]  [ Export CSV ]

Projects Affected: 83 of 100
─────────────────────────────────────────────────────────────────────────
  Project ID │ Project Title          │ Sites Affected │ Label Changes │ Status
  ─────────────────────────────────────────────────────────────────────
  12345      │ STAR Trial             │ 2              │ 3             │ pending
  12346      │ VA Cohort Study        │ 3              │ 4             │ pending
  12347      │ Archived Study         │ 0              │ 0             │ skipped (no site mapping)
  ...
─────────────────────────────────────────────────────────────────────────
  17 projects already migrated (skipped) │ 83 pending │ 0 failed
```

### Tab 4 — Live Execution

```
Rule Set: Q2 2026 Site Restructuring
⚠ This will disable OnCore sync crons for the duration. Confirm: [✓]

[ Start Migration ]

────────────────────────────────────────────────
 Overall Progress:  ████████████░░░░░░  42 / 87
────────────────────────────────────────────────

  Project                        Status          Changes
  ─────────────────────────────────────────────────────
  ✓  STAR Trial (12345)          completed       4 changes
  ✓  VA Cohort Study (12346)     completed       6 changes
  ⟳  Archived Study (12347)      skipped         —
  ●  CURRENT: Alpha Trial (12400) in_progress    …
  ○  Beta Trial (12401)          pending
  ○  Gamma Study (12402)         pending
  ...

[ Pause ]   (pause takes effect after current project completes)
```

### Tab 5 — History

```
Migration History
────────────────────────────────────────────────────────────
  Migration                    │ Run Date   │ Completed │ Failed │ Skipped
  ──────────────────────────────────────────────────────────────────────
  Q2 2026 Site Restructuring   │ 2026-05-14 │ 85        │ 1      │ 1
    ▼ Project Detail
    ✓ STAR Trial (12345)       │ 4 changes  │ [View Log]
    ✗ VA Cohort Study (12346)  │ Error: ...  │ [View Log]
    ⟳ Archived Study (12347)   │ No site mapping configured
```

**View Log** opens a modal showing the `redcap_entity_oncore_site_migration_log` rows for that project: each row is a `change_type | field_name | old_value → new_value`.

---

## 11. Cron Guard Pattern

Add to each of the four cron methods in `OnCoreIntegration.php`:

```php
public function onCoreProtocolsScanCron(): void
{
    if (SiteMigration::isMigrationInProgress($this)) {
        $this->emLog("onCoreProtocolsScanCron: skipped — site migration in progress");
        return;
    }
    // ... existing cron logic
}

// Same guard added to:
// updateOnCoreSubjectsDemographics()
// redcapCleanupEntityRecords()
// onCoreAutoPullCron()
```

`SiteMigration::isMigrationInProgress()` simply reads the system setting:

```php
public static function isMigrationInProgress(AbstractExternalModule $module): bool
{
    return (bool) $module->getSystemSetting('migration-in-progress');
}
```

The `migration-in-progress` system setting must be added to `config.json` as a hidden system setting (not shown in UI, read-only):

```json
{
  "key": "migration-in-progress",
  "name": "Site Migration In Progress",
  "type": "checkbox",
  "hidden": true,
  "super-users-only": true
}
```

---

## 12. Audit & Logging Strategy

### REDCap Audit Log (`REDCap::logEvent`)

One entry per project, written inside the transaction immediately before COMMIT:

```php
\REDCap::logEvent(
    "OnCore Site Migration",   // action
    implode("\n", $summary),   // changes description (one line per rule applied)
    null,                      // sql (not applicable)
    null,                      // record
    null,                      // event
    $projectId
);
```

**Example log entry visible in REDCap Project Audit Log:**
```
OnCore Site Migration
  Renamed: "Stanford Hospital" → label updated to "Stanford Hospital (changed to Stanford Medical Center)"
  Value mapping added: OnCore "Stanford Medical Center" → REDCap code "1"
  Project site subset updated: removed "Stanford Hospital", added "Stanford Medical Center"
```

Research team can see this in **Project** → **Logging** → filtered by "OnCore Site Migration".

### Entity Migration Log

The `redcap_entity_oncore_site_migration_log` table records granular changes used by the History tab and internal tooling. One row per individual change (e.g., one row for the label update, one row for each value_mapping addition).

---

## 13. Performance Strategy

| Concern | Approach |
|---------|----------|
| ~100 projects × field label updates | Single parameterized `UPDATE redcap_metadata` per project — no REDCap API round-trip. `redcap_metadata` is **not sharded**, so one UPDATE per project hits a single table. |
| Value mapping updates | JSON decode → PHP mutation → JSON encode → single `setProjectSetting` call |
| Project site subset updates | Same — single `setProjectSetting` call |
| Library setting update | Runs once at migration start via `setSystemSetting` — not per-project |
| AJAX timeout risk | Each AJAX call processes exactly ONE project, typically completes in < 500 ms |
| Progress state | Stored in `redcap_entity_oncore_migration_project_status` — survives browser refresh |
| Polling interval | 1.5 s client-side — low overhead, smooth UI feel |
| Transaction scope | Per-project only — a failure in project N does not affect projects 1…N-1 |
| Deep preview (optional) | Issues one `SELECT COUNT(*)` against the project's `redcap_data*` shard (resolved via `getProjectDataTable($pid)`). The query is `WHERE project_id=? AND field_name=? AND value IN (...)` — covered by REDCap's standard index on (`project_id`,`field_name`). One round-trip per project; only fires when user clicks **Deep Preview**. |

---

## 14. Files to Create / Modify

### New Files

| File | Purpose |
|------|---------|
| `classes/SiteMigration.php` | All migration logic — rule management, preview, execution, audit |
| `pages/site_migration.php` | Control Center page — single PHP file rendering the 5-tab shell, inline JS bootstrap |
| `assets/scripts/site_migration.js` | Vanilla JS — tab switching, AJAX polling loop, rule-editor state |
| `assets/styles/site_migration.css` | Page-specific styles |

### Modified Files

| File | Changes |
|------|---------|
| `OnCoreIntegration.php` | Add 3 new entity types in `redcap_entity_types()` |
| `OnCoreIntegration.php` | Add AJAX routing for 12 new actions in `redcap_module_ajax()` |
| `OnCoreIntegration.php` | Add cron guard (`isMigrationInProgress`) to all 4 cron methods |
| `OnCoreIntegration.php` | Import / instantiate `SiteMigration` class |
| `config.json` | Add 12 new AJAX actions to `auth-ajax-actions` |
| `config.json` | Add control-center link for the new page |
| `config.json` | Add hidden `migration-in-progress` system setting |

---

## 15. Implementation Sequence

| Phase | Tasks | Notes |
|-------|-------|-------|
| **1 — Foundation** | Add 3 entity types to `redcap_entity_types()`; run `EntityDB::buildSchema()` to create tables; add `migration-in-progress` to `config.json` | Tables must exist before any other code runs |
| **2 — Core Logic** | Implement `SiteMigration.php` — rule CRUD, `updateLibrarySettings`, `updateProjectSiteSubset`, `updateValueMapping`, `updateFieldLabels`, `logToEntity`, `logToREDCap` | Unit-testable in isolation |
| **3 — Preview** | Implement `previewMigration` and `exportPreviewCSV`; wire up `previewSiteMigration` and `exportMigrationPreview` AJAX actions | Preview must be fully complete before exposing Run |
| **4 — Execution** | Implement `startMigration`, `processNextProject`, `finalizeMigration`, `getMigrationStatus`; wire up AJAX actions; add cron guards | Test against a staging project first |
| **5 — UI** | Build Control Center page with all 5 tabs; connect all AJAX actions; implement polling loop with live progress; add pause/stop |  |
| **6 — History & Audit** | Implement `getMigrationHistory`, `getMigrationProjectLog` AJAX; build History tab UI |  |
| **7 — Testing** | Dry-run preview on real data; execute against 2–3 test projects; verify label updates, value mappings, REDCap audit log entries; verify cron guard works; verify idempotency on re-run |  |

---

## Appendix A — Value Mapping Format Reference

The `redcap-oncore-fields-mapping` project setting JSON stores value mappings as follows:

```json
{
  "pull": {
    "studySites": {
      "redcap_field": "site_field_name",
      "event": "baseline_arm_1",
      "field_type": "dropdown",
      "value_mapping": [
        { "oc": "Stanford Hospital",        "rc": "1" },
        { "oc": "Palo Alto VA",             "rc": "2" },
        { "oc": "Menlo Park VA",            "rc": "3" }
      ]
    }
  },
  "push": {
    "studySites": {
      "redcap_field": "site_field_name",
      "event": "baseline_arm_1",
      "field_type": "dropdown",
      "value_mapping": [
        { "oc": "Stanford Hospital",        "rc": "1" },
        { "oc": "Palo Alto VA",             "rc": "2" },
        { "oc": "Menlo Park VA",            "rc": "3" }
      ]
    }
  }
}
```

`getMappedRedcapValueSet($field_key, $push=false)` in `Mapping.php` transforms this into:
- Pull: `["Stanford Hospital" => "1", "Palo Alto VA" => "2"]`
- Push: `["1" => "Stanford Hospital", "2" => "Palo Alto VA"]`

After migration adds a rename entry, the array gains `"Stanford Medical Center" => "1"` for pull.

---

## Appendix B — Library Sub-Settings Structure

Study sites are nested two levels deep:

```
System Settings
└── libraries (repeatable sub_settings)
    ├── library-name:                  "Stanford Library"
    ├── library-oncore-field-definition: "{...json...}"
    ├── library-oncore-study-sites (repeatable sub_settings)
    │   ├── library-study-site:  "Stanford Hospital"
    │   ├── library-study-site:  "Palo Alto VA"
    │   └── library-study-site:  "Menlo Park VA"
    ├── library-oncore-staff-roles (repeatable sub_settings)
    │   └── library-staff-role:  "Principal Investigator"
    └── library-oncore-protocol-statuses (repeatable sub_settings)
        └── library-protocol-status: "Open to Accrual"
```

Loaded at project context via:
```php
$libraries = $this->getDefinedLibraries(); // getSubSettings('libraries', $pid)
$sites = OnCoreIntegration::getSubSettingsValuesAsArray(
    $libraries[$libraryIndex]['library-oncore-study-sites'],
    'library-study-site'
);
```

Migration updates the system-level sub_settings JSON for the affected library's `library-oncore-study-sites` entries using `setSystemSetting('libraries', $updatedLibraries)`.

---

## 16. REDCap Sharded Data Tables

REDCap shards record data horizontally across up to eight physical tables: `redcap_data`, `redcap_data2`, `redcap_data3`, `redcap_data4`, `redcap_data5`, `redcap_data6`, `redcap_data7`, `redcap_data8`. Each project is assigned to exactly one shard, recorded in `redcap_projects.data_table`. The same sharding scheme applies to `redcap_log_event` (`redcap_log_event2`, …).

### What is sharded vs not

| Table family | Sharded? | How to resolve the right shard |
|--------------|----------|--------------------------------|
| `redcap_data*` (up to `redcap_data8`) | Yes | `$module->getDataTable($projectId)` (framework method) → returns the literal table name, e.g. `"redcap_data3"` or `"redcap_data8"`. Fallback: `SELECT data_table FROM redcap_projects WHERE project_id = ?`. |
| `redcap_log_event*` | Yes | Use `REDCap::logEvent()` — it routes internally; never query the log tables with raw SQL. |
| `redcap_metadata` | **No** | Single table; safe to `WHERE project_id = ?`. |
| `redcap_projects` | No | Single table. |
| `redcap_external_modules_settings` | No | Single table; settings APIs do the right thing. |
| EM entity tables (`redcap_entity_*`) | No | Single table per entity type. |

### Implications for this migration

1. **Writes:** zero `redcap_data*` writes (by design). All writes target non-sharded tables or use sharding-aware APIs.
2. **Reads (deep preview only):** when answering "how many existing records reference this old site?", call `getProjectDataTable($pid)` first, then issue the COUNT against the returned table name. Hard-coding `redcap_data` would silently miss every project that lives on `redcap_data2`+.
3. **REDCap audit log writes:** done via `REDCap::logEvent()`. The API picks the right `redcap_log_event*` shard internally — we never compute it ourselves.
4. **Backwards compat:** `getDataTable()` is available in framework v5+ (see `EXTERNAL_MODULES_INDEX.md`, "Data Methods"). The OnCore EM already targets v12+, so the framework method is the canonical choice. Direct `redcap_projects.data_table` lookup is only a safety fallback.

### Where `getDataTable` MUST be used in this module

| Location | Reason |
|----------|--------|
| `SiteMigration::countRecordsWithSiteCode()` | Deep preview record counts |
| Any future "find records still holding code X" tooling | Same |
| **Nowhere else** in this migration | All other code paths touch non-sharded tables or sharded-API methods |

---

## Appendix C — May 2026 Stanford Site Mapping (Source: `Subject Study Site Worksheet.pdf`)

This appendix encodes the worksheet into the rule schema from §6.1 so the rule set can be seeded directly into the rule editor.

### C.1 Worksheet rows → rule classification

| # | Old Site (worksheet) | Worksheet "Proposed New Name" | Disposition | Rule type | Group |
|---|----------------------|-------------------------------|-------------|-----------|-------|
| 1 | SCI-Palo Alto | Main Hospital | Rename | `merge` (primary) | MH |
| 2 | SHC Main Hosp, Pasteur, Welch & campus/nearby clinics | Main Hospital | Move to "Main Hospital" | `merge` | MH |
| 3 | SCI-LPCH | Children's Hospital | Rename | `merge` (primary) | CH |
| 4 | LPCH Main Hosp, Welch Rd & campus/nearby clinics | Children's Hospital | Move to "Children's Hospital" | `merge` | CH |
| 5 | SHC Redwood City | Redwood City | Rename | `merge` (primary) | RC |
| 6 | Quarry Rd clinics;Hoover Pavilion | Quarry Rd clinics/Hoover Pavilion | Rename | `rename` | — |
| 7 | 1070 Arastradero | — | Leave as-is | `keep` | — |
| 8 | Byers Eye Institute | — | Leave as-is | `keep` | — |
| 9 | LPCH Satellite & Other | Children's Hospital | Move to "Children's Hospital" | `merge` | CH |
| 10 | CTRU (800 Welch Rd) | — | Leave as-is | `keep` | — |
| 11 | Remote interactions (e.g., online/phone/survey) | — | Leave as-is | `keep` | — |
| 12 | Lucas Center | — | Leave as-is | `keep` | — |
| 13 | SHC Satellite & Other | Main Hospital | Move to "Main Hospital" | `merge` | MH |
| 14 | Psychiatry: Page Mill, Porter Dr, other | Page Mill/Porter Dr | Rename | `rename` | — |
| 15 | Community site | — | Leave as-is | `keep` | — |
| 16 | Center for Cognitive and Neurobiological Imaging | — | Leave as-is | `keep` | — |
| 17 | SCI-South Bay | South Bay | Manual (SCI move + rename) | `rename` | — |
| 18 | SHC Tri-Valley | Tri-Valley | Rename | `rename` | — |
| 19 | SCI-Redwood City | Redwood City | Move to "Redwood City" (rename done; patients pending) | `merge` | RC |
| 20 | Stanford Ear Institute | — | Leave as-is | `keep` | — |
| 21 | SCI-Emeryville | Emeryville | Manual (SCI move + rename) | `merge` (primary) | EM |
| 22 | SCI - Livermore | Livermore | Rename | `rename` | — |
| 23 | SHC - Emeryville | Emeryville | Sunset; retired 2025-09-04 | `sunset` | EM |

### C.2 Seed rule set (JSON)

```json
[
  {
    "id": "merge-main-hospital",
    "type": "merge",
    "old_sites": [
      "SCI-Palo Alto",
      "SHC Main Hosp, Pasteur, Welch & campus/nearby clinics",
      "SHC Satellite & Other"
    ],
    "new_site": "Main Hospital",
    "primary_old_site": "SCI-Palo Alto"
  },
  {
    "id": "merge-childrens-hospital",
    "type": "merge",
    "old_sites": [
      "SCI-LPCH",
      "LPCH Main Hosp, Welch Rd & campus/nearby clinics",
      "LPCH Satellite & Other"
    ],
    "new_site": "Children's Hospital",
    "primary_old_site": "SCI-LPCH"
  },
  {
    "id": "merge-redwood-city",
    "type": "merge",
    "old_sites": ["SHC Redwood City", "SCI-Redwood City"],
    "new_site": "Redwood City",
    "primary_old_site": "SHC Redwood City"
  },
  {
    "id": "merge-emeryville",
    "type": "merge",
    "old_sites": ["SCI-Emeryville"],
    "new_site": "Emeryville",
    "primary_old_site": "SCI-Emeryville"
  },
  {
    "id": "sunset-shc-emeryville",
    "type": "sunset",
    "old_sites": ["SHC - Emeryville"],
    "new_site": "Emeryville",
    "retired_on": "2025-09-04"
  },
  { "id": "rename-quarry-rd",   "type": "rename", "old_sites": ["Quarry Rd clinics;Hoover Pavilion"],         "new_site": "Quarry Rd clinics/Hoover Pavilion" },
  { "id": "rename-psychiatry",  "type": "rename", "old_sites": ["Psychiatry: Page Mill, Porter Dr, other"],    "new_site": "Page Mill/Porter Dr" },
  { "id": "rename-south-bay",   "type": "rename", "old_sites": ["SCI-South Bay"],                              "new_site": "South Bay" },
  { "id": "rename-tri-valley",  "type": "rename", "old_sites": ["SHC Tri-Valley"],                             "new_site": "Tri-Valley" },
  { "id": "rename-livermore",   "type": "rename", "old_sites": ["SCI - Livermore"],                            "new_site": "Livermore" },
  { "id": "keep-arastradero",   "type": "keep",   "old_sites": ["1070 Arastradero"] },
  { "id": "keep-byers",         "type": "keep",   "old_sites": ["Byers Eye Institute"] },
  { "id": "keep-ctru",          "type": "keep",   "old_sites": ["CTRU (800 Welch Rd)"] },
  { "id": "keep-remote",        "type": "keep",   "old_sites": ["Remote interactions (e.g., online/phone/survey)"] },
  { "id": "keep-lucas",         "type": "keep",   "old_sites": ["Lucas Center"] },
  { "id": "keep-community",     "type": "keep",   "old_sites": ["Community site"] },
  { "id": "keep-cnbi",          "type": "keep",   "old_sites": ["Center for Cognitive and Neurobiological Imaging"] },
  { "id": "keep-ear-institute", "type": "keep",   "old_sites": ["Stanford Ear Institute"] }
]
```

### C.3 Notable cross-library / OnCore-side caveats

These items have OnCore-side prerequisites that the REDCap migration **does not** perform. They must be done in OnCore before (or in parallel with) this migration:

| Item | Out-of-scope action | Handler |
|------|--------------------|---------|
| SCI-South Bay → South Bay | Move from SCI library to Stanford library in OnCore, then rename | OnCore admin (manual) |
| SCI-Emeryville → Emeryville | Move from SCI library to Stanford library in OnCore, then rename | OnCore admin (Juan, per worksheet — "Done") |
| SCI - Livermore → Livermore | Rename inside SCI in OnCore | OnCore admin (Agnes, per worksheet — "Done") |
| SCI-Redwood City → Redwood City | Records' patient assignment moves in OnCore | OnCore admin (in progress per worksheet) |
| SHC - Emeryville | Already retired in OnCore on 2025-09-04 | OnCore admin (done) |

The REDCap migration assumes that by the time it runs, OnCore is the source of the new names. If it runs early, future syncs may still emit old names — but that is harmless because the `value_mapping` retains the old `oc → rc` entry per §4.3.

### C.4 Sites NOT in the worksheet

Any site present in `library-oncore-study-sites` for the Stanford library that is **not** listed above represents an unknown — the rule editor should flag these as "no rule defined" and refuse to start the migration until the admin explicitly adds a `keep` rule or another disposition. This guards against an admin running the migration with an outdated worksheet and silently leaving sites in an undefined state.
