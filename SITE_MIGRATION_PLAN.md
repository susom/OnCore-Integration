# OnCore Study Site Migration — Implementation Plan

**Module:** OnCore Integration v9.9.9  
**Author:** ihabz  
**Date:** 2026-05-12  
**Status:** Planning

---

## Table of Contents

1. [Problem Statement](#1-problem-statement)
2. [Design Decisions & Constraints](#2-design-decisions--constraints)
3. [Data Model — What Actually Changes](#3-data-model--what-actually-changes)
4. [Open Decision Points](#4-open-decision-points)
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

---

## 1. Problem Statement

OnCore periodically renames study sites or merges multiple sites into one. When this happens:

- The REDCap field mapped to `studySites` still contains old coded values and old option labels
- The `redcap-oncore-fields-mapping` value mapping still references old OnCore site name strings
- The project-level site subset (`redcap-oncore-project-site-studies`) still lists old names
- The system-level library site list (`library-oncore-study-sites`) still lists old names
- Future OnCore syncs send new site names that fail to resolve to REDCap coded values

The goal is a **Control Center administration page** that allows a super-user to define rename/merge rules and apply them retroactively across all ~100 active projects — without modifying the underlying `redcap_data` table at all.

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

---

## 4. Open Decision Points

These require answers before implementation begins.

### 4.1 Merge — Primary Code Selection

When `"Palo Alto VA"` (rc=`"2"`) and `"Menlo Park VA"` (rc=`"3"`) merge into `"VA Palo Alto HCS"`, future OnCore syncs send `"VA Palo Alto HCS"`. This must map to exactly one existing REDCap coded value.

| Option | Description | Tradeoff |
|--------|-------------|----------|
| **A** _(recommended)_ | Admin explicitly selects the primary in the rule editor UI — e.g., a dropdown: *"Future 'VA Palo Alto HCS' records map to the same code as: [Palo Alto VA ▼]"* | Most transparent; admin controls exactly which code "wins" |
| **B** | Always default to the first listed old site's code, no UI choice | Simpler UI; may not match admin's intent |
| **C** | Create a new REDCap dropdown option for the merged site | Clean separation; requires data dict change; new code won't match any historical records |

**Default plan assumes Option A.**

### 4.2 Library Scope — Single vs. Multiple Libraries

Study sites are scoped to libraries. Each linked project uses one library (stored as `oncore_library` index in the protocol entity).

- If **one library** exists: rules apply globally; no library selector needed in UI.
- If **multiple libraries** exist: the rule editor should show a library selector so the admin knows which library's site list they are editing.

**Clarification needed: how many libraries are configured in your system?**

### 4.3 System Site List — Replace vs. Append

When a rename happens:

| Option | Behavior |
|--------|----------|
| **Replace** | `"Stanford Hospital"` removed from library, `"Stanford Medical Center"` added. Cleaner going forward; assumes OnCore has already been renamed. |
| **Append** | Both old and new names kept in the library list. Safe during transition; produces duplicate-looking list over time. |

**Default plan assumes Replace**, with both names kept in the `value_mapping` for backward compat.

---

## 5. Rule Types & Detailed Effects

### 5.1 Rule: Rename

```
old_site:  "Stanford Hospital"
new_site:  "Stanford Medical Center"
type:      rename
```

| Layer | Before | After |
|-------|--------|-------|
| Library site list | `["Stanford Hospital", ...]` | `["Stanford Medical Center", ...]` |
| Project site subset | `["Stanford Hospital"]` | `["Stanford Medical Center"]` |
| Value mapping | `[{"oc":"Stanford Hospital","rc":"1"}]` | `[{"oc":"Stanford Hospital","rc":"1"}, {"oc":"Stanford Medical Center","rc":"1"}]` |
| Field label | `1, Stanford Hospital` | `1, Stanford Hospital (changed to Stanford Medical Center)` |

### 5.2 Rule: Merge

```
old_sites: ["Palo Alto VA", "Menlo Park VA"]
new_site:  "VA Palo Alto Health Care System"
primary:   "Palo Alto VA"   ← admin-selected (Option A)
type:      merge
```

| Layer | Before | After |
|-------|--------|-------|
| Library site list | `["Palo Alto VA", "Menlo Park VA", ...]` | `["VA Palo Alto Health Care System", ...]` |
| Project site subset | `["Palo Alto VA", "Menlo Park VA"]` | `["VA Palo Alto Health Care System"]` |
| Value mapping | `[{"oc":"Palo Alto VA","rc":"2"},{"oc":"Menlo Park VA","rc":"3"}]` | `[{"oc":"Palo Alto VA","rc":"2"},{"oc":"Menlo Park VA","rc":"3"},{"oc":"VA Palo Alto Health Care System","rc":"2"}]` |
| Field label — Palo Alto VA | `2, Palo Alto VA` | `2, Palo Alto VA (merged into VA Palo Alto Health Care System)` |
| Field label — Menlo Park VA | `3, Menlo Park VA` | `3, Menlo Park VA (merged into VA Palo Alto Health Care System)` |

### 5.3 Rule: Keep

```
site:  "Site C"
type:  keep
```

No changes applied. Site C passes through migration untouched.

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
    "old_sites": ["Stanford Hospital"],
    "new_site": "Stanford Medical Center",
    "primary_old_site": null
  },
  {
    "id": "rule-2",
    "type": "merge",
    "old_sites": ["Palo Alto VA", "Menlo Park VA"],
    "new_site": "VA Palo Alto Health Care System",
    "primary_old_site": "Palo Alto VA"
  },
  {
    "id": "rule-3",
    "type": "keep",
    "old_sites": ["Site C"],
    "new_site": null,
    "primary_old_site": null
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
private function updateFieldLabels(int $pid, array $rules): array  // SQL UPDATE
private function logToEntity(int $pid, int $migrationId, array $changes): void
private function logToREDCap(int $pid, array $summary): void

// Cron gate
public static function isMigrationInProgress(): bool
public function disableCrons(): void
public function enableCrons(): void
```

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

| Action | Description | Returns |
|--------|-------------|---------|
| `listSiteMigrationRuleSets` | List all saved rule sets | `[{id, name, status, created_at, ...}]` |
| `getSiteMigrationRuleSet` | Load single rule set with full rules | `{id, name, rules, ...}` |
| `saveSiteMigrationRuleSet` | Create or update a rule set | `{id}` |
| `deleteSiteMigrationRuleSet` | Delete a draft rule set | `{ok: true}` |
| `previewSiteMigration` | Dry-run preview — counts only | `{projects: [{pid, name, affectedSites, labelChanges, status}]}` |
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
Name: [____________________________]
Description: [____________________________]
Library: [All Libraries ▼]   (shown only if multiple libraries detected)

Study Sites from Library
─────────────────────────────────────────────────────────
  Site Name                │  Action        │  Target Name
  ─────────────────────────────────────────────────────
  Stanford Hospital         │ ○Keep ○Rename ●Merge │ Group A  [VA Palo Alto HCS]
  Palo Alto VA              │ ○Keep ○Rename ●Merge │ Group A  [VA Palo Alto HCS]
  Menlo Park VA             │ ○Keep ○Rename ●Merge │ Group A  [VA Palo Alto HCS]
  Stanford Medical Center   │ ○Keep ●Rename ○Merge │ [Stanford Medical Center  ]
  Site C                    │ ●Keep ○Rename ○Merge │ —

  Merge Group A primary: [Palo Alto VA ▼]
  (New records for "VA Palo Alto HCS" will use Palo Alto VA's REDCap code)

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
| ~100 projects × field label updates | Single parameterized `UPDATE redcap_metadata` per project — no REDCap API round-trip |
| Value mapping updates | JSON decode → PHP mutation → JSON encode → single `setProjectSetting` call |
| Project site subset updates | Same — single `setProjectSetting` call |
| Library setting update | Runs once at migration start via `setSystemSetting` — not per-project |
| AJAX timeout risk | Each AJAX call processes exactly ONE project, typically completes in < 500 ms |
| Progress state | Stored in `redcap_entity_oncore_migration_project_status` — survives browser refresh |
| Polling interval | 1.5 s client-side — low overhead, smooth UI feel |
| Transaction scope | Per-project only — a failure in project N does not affect projects 1…N-1 |

---

## 14. Files to Create / Modify

### New Files

| File | Purpose |
|------|---------|
| `classes/SiteMigration.php` | All migration logic — rule management, preview, execution, audit |
| `pages/site_migration.php` | Control Center page — server-side shell, loads JS module |
| `frontend_3/site_migration/index.js` | Tab-based SPA UI — rule editor, live progress, history |
| `frontend_3/site_migration/style.css` | Page-specific styles |

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
