# OnCore Study Site Migration — Implementation Plan

**Module:** OnCore Integration v9.9.9  
**Author:** ihabz  
**Date:** 2026-06-01 (rev 3 — flipped to clean-label exports: per-project new-code allocation for merges, in-place relabel for renames, code-reference scan)  
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
- Exports (label format) display old site names that no longer match OnCore — and for merged sites, three former codes export as three different labels, so analysts cannot group merged subjects without post-processing

The concrete trigger for this initiative is the May 2026 Stanford site re-org documented in `Subject Study Site Worksheet.pdf` (encoded as Appendix C below): nine renames plus four merges, with a handful of sites explicitly marked "Leave as-is".

The goal is a **Control Center administration page** that allows a super-user to define rename / merge / keep / sunset rules and apply them retroactively across all ~100 active projects, with **clean labels in exports** as the user-visible outcome. This requires writing to both the metadata side (`redcap_metadata`, settings, value_mapping) **and** to the project's sharded data table (`redcap_data` / `redcap_data2` / …) for merges and target-bound sunsets. Renames are handled in place (label rewrite only, no data table write). See [Section 16](#16-redcap-sharded-data-tables) for the shard resolution strategy.

---

## 2. Design Decisions & Constraints

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Migrate `redcap_data*` records | **Yes for `merge` & target-bound `sunset`; No for `rename` & `keep`** | Merges consolidate N old codes into one new code so a labels-export naturally groups merged subjects under a single label. Renames just relabel the existing code in place — no data write needed. |
| Allocate new codes in `element_enum` | **Yes for `merge` & target-bound `sunset`** | Per-project `max(existing_codes) + 1`. The new code carries the clean new label; old codes are retained with a `(retired — migrated to <NewSite>)` suffix for audit visibility and reversibility. |
| Field option labels | **Rename: in-place; Merge / Sunset(→target): add new + suffix old** | Renames change the existing code's label directly (`1, SHC Tri-Valley` → `1, Tri-Valley`). Merges append the new clean entry and mark each old entry as retired. |
| Handle OnCore sync forward-compat | **Yes, via `value_mapping`** | After merge, OnCore's new name maps to the new code. After rename, OnCore's new name maps to the unchanged code. Old `oc → rc` entries are retained in `value_mapping` so any stale OnCore payload still resolves. |
| Code-reference scan | **Yes — pre-flight scan, warn but do not block** | Scans `redcap_metadata.branching_logic`, `redcap_metadata.misc` (action tags), `redcap_alerts`, `redcap_surveys_emails`, `redcap_surveys_scheduler` (ASI), `redcap_reports_filter_logic` for `[siteField] = 'oldCode'` patterns referencing codes that will be rewritten. Warnings surface in preview + live UI; admin proceeds at their discretion. |
| Update entity subject table | **Not applicable** | `redcap_entity_oncore_subjects` has no `studySites` column. Study site lives in `redcap_metadata`, value mappings, and `redcap_data*`. |
| Execution granularity | **Per-project atomic transactions** | Each project either fully succeeds (metadata + value_mapping + project subset + record rewrites) or fully rolls back. Already-completed projects are never re-processed. |
| Sharded write safety | **`getDataTable($pid)` + table-name allowlist regex** | Resolve the project's shard via framework method; validate the returned name against `/^redcap_data[2-8]?$/` before interpolating into SQL. Never hard-code `redcap_data`. |
| Live progress | **Client-driven AJAX polling** | One project per AJAX call avoids PHP timeouts. Client polls every ~1.5 s. |
| Disable syncs during migration | **Yes — system-level flag** | All four cron jobs check `migration-in-progress` system setting and exit early if set. |
| Rule sets | **Persistent + re-runnable** | Rules saved as an entity record. Admin can re-run on newly-linked projects or after partial failures. |
| Dry-run / preview | **Yes — settings preview + optional deep (record-count) preview + CSV export** | Cheap preview computes settings/label/mapping deltas. Deep preview adds per-project record counts via the project's shard. Both run the code-reference scan. |
| Rollback | **Per-project transaction rollback** | If a project fails mid-way, its transaction rolls back. Completed projects are not rewound. |
| Audit trail | **Entity log + `REDCap::logEvent()`** | Entity log for granular tooling; `logEvent()` so the research team sees changes in the built-in REDCap audit log. |
| Multi-rule execution | **All rules in one pass per project** | Admin defines N rename + M merge + K keep + L sunset rules, saves once, runs once. All applied within a single per-project transaction. |

---

## 3. Data Model — What Actually Changes

Study site names flow through six storage locations. The migration writes to **five of them**. `redcap_log_event*` is only ever written through `REDCap::logEvent()` (shard-aware API), never with raw SQL.

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
**Change (rename, in-place):** Add a new `{"oc": "New Site Name", "rc": "<unchanged code>"}` entry pointing to the same rc code as the old site. Retain the old `oc` entry so any in-flight OnCore payload referring to the old name still resolves.  
**Change (merge):** Allocate a new per-project code (see §3.4) and add `{"oc": "Merged Site Name", "rc": "<new code>"}`. Retain all old `oc → rc` entries for the merged sites (they now point to retired codes, but stale OnCore payloads still resolve).  
**Change (sunset with `merged_into`):** Treated as a merge variant — new entry for the merge target, old sunset entry retained.  
**Change (sunset without target):** No new `value_mapping` entry; old entry retained for backward compat only.

Both the `pull` and `push` branches receive the same updates.

### 3.4 REDCap Metadata — Field Option Labels & Code Allocation

**Table:** `redcap_metadata`  
**Column:** `element_enum`  
**Format:** Pipe-delimited coded options: `"1, Stanford Hospital \| 2, Palo Alto VA \| 3, Menlo Park VA"`

This is the layer where the user-visible export behavior is decided. Each rule type has its own pattern:

#### 3.4.1 Rename — in-place relabel

Existing code keeps its number; only the label changes. Records on this code immediately export with the new label.

| Before | After |
|--------|-------|
| `7, SHC Tri-Valley` | `7, Tri-Valley` |

The old label is preserved in the migration entity log + `REDCap::logEvent()` audit entry, not in `element_enum`.

#### 3.4.2 Merge — allocate new code + suffix old

A new code is appended (per-project `max(existing_codes) + 1`) carrying the clean new label. Each old code that was merged in gets a `(retired — migrated to <NewSite>)` suffix on its label so the old options stay visible in the dropdown but are recognizably deprecated. The `redcap_data*` rewrite (§3.6) then moves all records onto the new code so labels-exports show one consolidated label.

| Before | After |
|--------|-------|
| `1, SCI-Palo Alto \| 4, SHC Main Hosp... \| 9, SHC Satellite & Other` | `1, SCI-Palo Alto (retired — migrated to Main Hospital) \| 4, SHC Main Hosp... (retired — migrated to Main Hospital) \| 9, SHC Satellite & Other (retired — migrated to Main Hospital) \| 24, Main Hospital` |

(`24` here is illustrative — the actual new code is `max(existing)+1` per project.)

#### 3.4.3 Sunset — suffix old label; optional target merge

If the rule has a `merged_into` target, the sunset is treated as a merge variant (new code allocated for the target if not already present, records rewritten). Otherwise, the old code's label is suffixed `(retired YYYY-MM-DD)` and records are left in place.

| Variant | Before | After |
|---------|--------|-------|
| With target | `5, SHC - Emeryville` | `5, SHC - Emeryville (retired 2025-09-04, migrated to Emeryville)` (plus a new code for Emeryville if not already allocated) |
| Without target | `5, SHC - Emeryville` | `5, SHC - Emeryville (retired 2025-09-04)` |

#### 3.4.4 Keep — no change

No write. The rule is recorded in the migration log so the History tab can show "intentionally left alone".

#### 3.4.5 Implementation notes

- The mapped REDCap field name comes from `redcap-oncore-fields-mapping` → `pull.studySites.redcap_field`. Only the project-scoped field is touched.
- A single parameterized `UPDATE redcap_metadata SET element_enum = ? WHERE project_id = ? AND field_name = ?` writes the modified enum. `redcap_metadata` is **not sharded** — see §16.
- Code allocation is per-project (each project's `element_enum` has its own code numbering). Two different projects may pick different new code numbers for the same new site name — that's expected and fine; `value_mapping` is also per-project.
- Re-run safety: the label-suffixing routine checks for existing `(retired — migrated to` / `(retired ` substrings before appending, and the code-allocation routine checks whether a code already labeled with the new site name exists for that field.

### 3.5 REDCap Data Tables — Record Value Rewrite

**Table:** `redcap_data` / `redcap_data2` / … / `redcap_data8` (sharded — one shard per project)  
**Resolution:** `$module->getDataTable($pid)` (framework v5+, see EM index "Data Methods"). Fallback: `SELECT data_table FROM redcap_projects WHERE project_id = ?`. The returned table name is validated against `/^redcap_data[2-8]?$/` before being interpolated into SQL — table names cannot be parameterized.

**When written:**

| Rule | Write? | What |
|------|--------|------|
| `rename` | No | Code stays the same; label was relabeled in place |
| `merge` | **Yes** | For each old code in the merge group, `UPDATE <shard> SET value = '<newCode>' WHERE project_id = ? AND field_name = ? AND value = '<oldCode>'`. One statement per old code → new code pair. |
| `sunset` with `merged_into` | **Yes** | Same as merge |
| `sunset` without target | No | Records stay on the retired code |
| `keep` | No | — |

**SQL pattern:**
```php
$dataTable = $this->validateDataTableName(
    $this->module->getDataTable($pid)   // e.g. "redcap_data3"
);
$this->module->query(
    "UPDATE `{$dataTable}`
        SET value = ?
      WHERE project_id = ?
        AND field_name = ?
        AND value = ?",
    [(string)$newCode, $pid, $fieldName, (string)$oldCode]
);
```

`field_name` must be the project's mapped studySites field, looked up from `redcap-oncore-fields-mapping` for that project. The query uses REDCap's standard composite index on `(project_id, field_name)`.

### 3.6 Tables That Are SCANNED but Not Written (Code Reference Scan)

Before any data rewrite, the migration scans the following tables for references to the old codes that will be replaced (merge / target-bound sunset only — rename leaves codes unchanged). Findings are surfaced as warnings; the admin chooses whether to proceed.

| Table | Column(s) | Why it matters |
|-------|-----------|----------------|
| `redcap_metadata` | `branching_logic`, `misc` (action tags), `element_validation_min/max`, `element_enum` (for `@CALCDATE`/calc fields referencing the dropdown) | Branching logic / calc fields written as `[site] = '1'` break silently when code 1 is rewritten |
| `redcap_alerts` | `trigger_logic`, `email_subject`, `email_content` (smart-variable refs) | Conditional alert firing |
| `redcap_surveys_emails` | `condition_logic` | Conditional invitations |
| `redcap_surveys_scheduler` | `condition_logic` | ASI scheduling |
| `redcap_reports_filter_logic` | `condition_string` | Filter logic in saved reports |

The scan is a single batched query per project filtered by the field name and the affected old codes. Results aggregate into a count per category surfaced in the preview / live UI.

### 3.7 What This Migration Does NOT Touch

| Table | Sharded? | Touched? | Why |
|-------|----------|----------|-----|
| `redcap_log_event*` | Yes | Only via `REDCap::logEvent()` | Shard routing handled by the REDCap API. We never query log tables with raw SQL. |
| `redcap_entity_oncore_subjects` | No | No | No `studySites` column in the entity |
| `redcap_projects` | No | Read-only (`data_table` lookup as fallback for shard resolution) | Never written |

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

### 4.4 Export Label Behavior

**Answer: Clean new label.** Historical records should export with the new site label, not a suffixed version of the old name. Rationale: a labels export is consumed by analysts who shouldn't have to mentally translate `"SCI-Palo Alto (changed to Main Hospital)"` back to "Main Hospital" — and shouldn't have to post-process to group merged subjects.

This is what motivates the rest of the design: in-place relabel for renames, new-code allocation + `redcap_data*` rewrite for merges.

### 4.5 Merge Grouping in Exports

**Answer: One consolidated group.** All records previously assigned to the merged old codes are rewritten to a single new code. A `GROUP BY site` on the export naturally yields one bucket for the merged site.

### 4.6 New Enrollment Behavior

**Answer: Allocate a new code for the new site (for merge / target-bound sunset).** Rationale: reusing the primary's old code would mean a new subject enrolled at "Main Hospital" exports as the primary's old name. A fresh code gives clean separation: old code rows reflect the historical reality (with a `(retired — migrated to X)` suffix in the dropdown), new code rows reflect post-migration enrollments.

For pure renames there is no new code — see §4.7.

### 4.7 Rename Strategy

**Answer: Relabel in place, no data rewrite.** For a one-to-one rename, simply changing `7, SHC Tri-Valley` to `7, Tri-Valley` in `element_enum` is sufficient. All existing records on code 7 immediately export as "Tri-Valley". No new code allocation, no `redcap_data*` write.

This diverges from how merges are handled (which need a new code because three old codes can't all become one same-numbered code). The trade-off: the old "SHC Tri-Valley" label is no longer visible in the dropdown after migration; it's preserved only in the migration entity log and `REDCap::logEvent()` audit entry.

### 4.8 Code References in Branching Logic / Alerts / Reports

**Answer: Scan, warn, do not block.** A pre-flight scan inspects every project's branching logic, alerts, ASI conditions, survey email conditions, report filters, and action tags for references to old codes that will be rewritten. Findings are surfaced as a warning count per project in the preview and live UI; the admin clicks "Acknowledge" per project (or "Acknowledge all" for the run) before the migration can proceed for that project.

The migration does **not** auto-rewrite logic references — regex-replacing live REDCap config is too risky.

### 4.9 Old Code Disposal

**Answer: Keep entries; suffix labels `(retired — migrated to <NewSite>)`.** After a merge moves all records off a code, the now-unused old code entry stays in `element_enum` with a deprecation suffix. Visible in the dropdown so a researcher reviewing the form sees the project's history. Reversible (the old code is still allocated, so a future "undo" tool could re-target it). Sunset entries get a date-bearing suffix `(retired YYYY-MM-DD[, migrated to <NewSite>])`.

---

## 5. Rule Types & Detailed Effects

Four rule types: `rename`, `merge`, `keep`, `sunset`. Examples use real names from the May 2026 worksheet.

The `primary_old_site` field on a merge rule is **informational only** (used to display a recommended primary in the UI). Unlike the previous draft, the primary's code is no longer reused — every merge allocates a fresh per-project code.

### 5.1 Rule: Rename — in-place relabel

```
old_site:  "SHC Tri-Valley"
new_site:  "Tri-Valley"
type:      rename
```

| Layer | Before | After |
|-------|--------|-------|
| Library site list | `["SHC Tri-Valley", ...]` | `["Tri-Valley", ...]` |
| Project site subset | `["SHC Tri-Valley", ...]` | `["Tri-Valley", ...]` |
| Value mapping (`pull` + `push`) | `[{"oc":"SHC Tri-Valley","rc":"7"}]` | `[{"oc":"SHC Tri-Valley","rc":"7"}, {"oc":"Tri-Valley","rc":"7"}]` (old retained for backward compat) |
| Field label (`redcap_metadata`) | `7, SHC Tri-Valley` | `7, Tri-Valley` |
| `redcap_data*` records | (code 7) | (code 7 — unchanged) |
| Code-reference scan | n/a — code 7 is not being rewritten | — |

Records continue to hold code `7`. The label-export change is immediate because `element_enum` is consulted at export time.

### 5.2 Rule: Merge — allocate new code + consolidate records

```
old_sites:        ["SCI-Palo Alto", "SHC Main Hosp...", "SHC Satellite & Other"]
new_site:         "Main Hospital"
primary_old_site: "SCI-Palo Alto"   ← informational; used to seed UI defaults
type:             merge
```

Assume the project's site field has existing codes `1, 4, 9, 12, 23`. The migration allocates new code `24`.

| Layer | Before | After |
|-------|--------|-------|
| Library site list | `[..., "SCI-Palo Alto", "SHC Main Hosp...", "SHC Satellite & Other"]` | `[..., "Main Hospital"]` (three old entries removed, one new added) |
| Project site subset | `["SCI-Palo Alto", "SHC Main Hosp..."]` | `["Main Hospital"]` |
| Value mapping (`pull` + `push`) | `[{"oc":"SCI-Palo Alto","rc":"1"}, {"oc":"SHC Main Hosp...","rc":"4"}, {"oc":"SHC Satellite & Other","rc":"9"}]` | …unchanged… **plus** `{"oc":"Main Hospital","rc":"24"}` |
| Field label — primary | `1, SCI-Palo Alto` | `1, SCI-Palo Alto (retired — migrated to Main Hospital)` |
| Field label — secondary | `4, SHC Main Hosp...` | `4, SHC Main Hosp... (retired — migrated to Main Hospital)` |
| Field label — secondary | `9, SHC Satellite & Other` | `9, SHC Satellite & Other (retired — migrated to Main Hospital)` |
| Field label — new | (none) | `24, Main Hospital` |
| `redcap_data*` records on code 1 | `value = '1'` | `value = '24'` (UPDATE on the project's shard) |
| `redcap_data*` records on code 4 | `value = '4'` | `value = '24'` |
| `redcap_data*` records on code 9 | `value = '9'` | `value = '24'` |
| Code-reference scan | — | runs against `[siteField] = '1' \| '4' \| '9'` patterns across `branching_logic`, `alerts`, `surveys_emails`, `surveys_scheduler`, `reports_filter_logic`, `misc` |

After migration, a labels-export shows `"Main Hospital"` for every formerly-merged record. A `GROUP BY` on the field gives one bucket.

### 5.3 Rule: Keep — no-op

```
site:  "Byers Eye Institute"
type:  keep
```

No changes applied. Logged so the History tab can report "intentionally left alone" vs. "no rule defined" (which is an error condition per §C.4).

### 5.4 Rule: Sunset — retire old code; optionally bind to a merge target

```
old_site:        "SHC - Emeryville"
retired_on:      "2025-09-04"
merged_into:     "Emeryville"    ← optional; if present, sunset behaves like a merge into Emeryville
type:            sunset
```

#### 5.4.1 With `merged_into` (target-bound sunset)

Behaves as a merge variant. If the target site already has an allocated code (e.g., a related merge rule already added "Emeryville" with code 24), reuse that code; otherwise allocate a fresh code.

| Layer | Behavior |
|-------|----------|
| Library site list | Old name removed |
| Project site subset | Old name removed |
| Value mapping | Old entry retained; new entry for `merged_into` target added (or reused if already present in this run) |
| Field label | `N, SHC - Emeryville` → `N, SHC - Emeryville (retired 2025-09-04, migrated to Emeryville)` |
| `redcap_data*` | Records on code `N` rewritten to the target's new code |
| Code-reference scan | Runs |

#### 5.4.2 Without `merged_into`

The site is retired but records stay on the old code. No data rewrite. No new code allocation. Useful when OnCore has retired a site and the records should remain attached to the historical name.

| Layer | Behavior |
|-------|----------|
| Library site list | Old name removed |
| Project site subset | Old name removed |
| Value mapping | Old entry retained; no new entry |
| Field label | `N, SHC - Emeryville` → `N, SHC - Emeryville (retired 2025-09-04)` |
| `redcap_data*` | Unchanged |
| Code-reference scan | Not run |

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
| `project_id` | integer | REDCap project ID (0 for system-level changes like `library_setting`) |
| `rule_id` | text | Rule UUID from rule set |
| `change_type` | text | `library_setting` \| `project_subset` \| `value_mapping` \| `field_label` \| `code_allocation` \| `record_value_migration` |
| `field_name` | text | REDCap field name (for field-level changes) |
| `old_value` | text | Value before change. For `record_value_migration`, the old code. For `code_allocation`, empty. |
| `new_value` | text | Value after change. For `record_value_migration`, the new code. For `code_allocation`, the new `"<code>, <label>"` pair. |
| `details` | text | Optional JSON for extra context — e.g., `{"rows_affected": 142, "data_table": "redcap_data3"}` for record migrations |
| `migrated_by` | text | REDCap username who ran the migration |
| `migrated_at` | integer | Unix timestamp |

New change_types introduced in rev 3:
- `code_allocation` — a fresh code was appended to `element_enum` for a new site (merge / target-bound sunset). `new_value` carries the new `"<code>, <label>"` pair.
- `record_value_migration` — `redcap_data*` rows were UPDATEd from one code to another. `details.rows_affected` records the count; `details.data_table` records which shard was hit.

### 6.3 `redcap_entity_oncore_migration_project_status`

Tracks which projects have been processed for each rule set, enabling safe re-runs and progress resumption.

| Column | Type | Description |
|--------|------|-------------|
| `migration_id` | integer | FK → `redcap_entity_oncore_site_migration.id` |
| `project_id` | integer | REDCap project ID |
| `status` | text | `pending` \| `in_progress` \| `completed` \| `failed` \| `skipped` \| `needs_ack` |
| `changes_applied` | integer | Count of individual changes made |
| `code_references_json` | text | JSON. Scan result. Shape: `{"branching_logic": N, "alerts": N, "surveys_emails": N, "surveys_scheduler": N, "reports": N, "action_tags": N, "details": [...]}`. `details` is a per-finding array used by the "View references" UI. |
| `acknowledged_at` | integer | Unix timestamp when admin clicked "Acknowledge" on this project's warnings. Null until then. |
| `acknowledged_by` | text | REDCap username who acknowledged warnings |
| `completed_at` | integer | Unix timestamp when status moved to `completed` / `failed` / `skipped` |
| `error_message` | text | Error detail if `failed` |

The `needs_ack` status is set during preview when the scan finds references. The migration loop refuses to process a `needs_ack` project until the admin clicks Acknowledge (which flips it to `pending`).

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

// Preview (no writes — but updates per-project status to pending / needs_ack)
public function previewMigration(int $ruleSetId, bool $deep = false): array
public function exportPreviewCSV(int $ruleSetId): string
public function acknowledgeProjectWarnings(int $migrationId, int $projectId): void

// Execution
public function startMigration(int $ruleSetId): string   // returns sessionId
public function processNextProject(string $sessionId): array
public function getMigrationStatus(string $sessionId): array
public function finalizeMigration(string $sessionId): void

// Internal — per-project orchestration
private function processProject(int $projectId, array $rules, int $migrationId): array
private function updateLibrarySettings(array $rules, int $libraryIndex): void  // runs once
private function updateProjectSiteSubset(int $pid, array $rules): array
private function updateValueMapping(int $pid, array $rules, array $codeAllocations): array

// Internal — element_enum / code allocation
private function loadElementEnum(int $pid, string $fieldName): array  // returns ['raw'=>..., 'codes'=>[code=>label, ...]]
private function relabelInPlace(array $codes, string $oldCode, string $newLabel): array
private function suffixCodeLabel(array $codes, string $oldCode, string $suffix): array
private function allocateNewCode(array $codes, string $newLabel): array  // returns [int $code, array $newCodes]
private function writeElementEnum(int $pid, string $fieldName, array $codes): void
private function planFieldChanges(int $pid, array $rules): array  // dry-run per project — drives both preview & execution
private function applyFieldChanges(int $pid, array $plan): array  // executes the plan; returns log entries

// Internal — sharded data writes
private function getProjectDataTable(int $pid): string
private function validateDataTableName(string $tableName): string
private function updateRecordValues(int $pid, string $fieldName, string $oldCode, string $newCode): int  // returns rows affected
private function countRecordsWithSiteCode(int $pid, string $fieldName, string $rcCode): int

// Internal — code reference scan
private function scanCodeReferences(int $pid, string $fieldName, array $oldCodes): array
private function persistCodeReferences(int $migrationId, int $pid, array $scanResult): void

// Internal — logging
private function logToEntity(int $pid, int $migrationId, array $changes): void
private function logToREDCap(int $pid, array $summary): void

// Cron gate
public static function isMigrationInProgress(AbstractExternalModule $module): bool
```

### Sharding rules for the class

- **Writes targeting `redcap_data*`:** only `updateRecordValues()`. Resolves the shard via `getDataTable($pid)`, validates with `validateDataTableName()` (allowlist regex), then interpolates into the SQL.
- **Reads targeting `redcap_data*`:** only `countRecordsWithSiteCode()` (deep preview) — same resolution path.
- **Writes targeting `redcap_metadata` / settings / entities:** never shard-sensitive — single tables.
- **Audit log writes:** only through `REDCap::logEvent()` — shard-aware API.
- **Never** hard-code `redcap_data` as a literal SQL table name.

### `processProject` — Per-Project Transaction

```php
private function processProject(int $projectId, array $rules, int $migrationId): array
{
    $status = $this->getProjectStatus($projectId, $migrationId);
    if ($status === 'completed' || $status === 'skipped') {
        return ['status' => 'skipped', 'project_id' => $projectId];
    }
    if ($status === 'needs_ack') {
        return [
            'status' => 'blocked',
            'project_id' => $projectId,
            'note' => 'Warnings unacknowledged — admin must Acknowledge before this project will run.',
        ];
    }

    $this->markProjectStatus($projectId, $migrationId, 'in_progress');
    $changes = [];

    try {
        // The whole per-project transaction wraps both metadata and data writes,
        // so a partial failure rolls back element_enum, settings, AND record rewrites.
        $this->module->query('START TRANSACTION', []);

        // 1. Plan the field-level changes (in-place relabel + new code allocations + suffixing).
        $plan = $this->planFieldChanges($projectId, $rules);

        // 2. Project-level settings.
        $changes = array_merge($changes, $this->updateProjectSiteSubset($projectId, $rules));
        $changes = array_merge($changes, $this->updateValueMapping($projectId, $rules, $plan['code_allocations']));

        // 3. element_enum (single UPDATE on redcap_metadata).
        $changes = array_merge($changes, $this->applyFieldChanges($projectId, $plan));

        // 4. Record value rewrites for merges + target-bound sunsets (sharded UPDATE).
        foreach ($plan['record_migrations'] as $rm) {
            $rows = $this->updateRecordValues(
                $projectId, $plan['field_name'], $rm['old_code'], $rm['new_code']
            );
            $changes[] = [
                'change_type' => 'record_value_migration',
                'field_name'  => $plan['field_name'],
                'old_value'   => $rm['old_code'],
                'new_value'   => $rm['new_code'],
                'details'     => json_encode([
                    'rows_affected' => $rows,
                    'data_table'    => $this->validateDataTableName($this->getProjectDataTable($projectId)),
                ]),
            ];
        }

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

### `planFieldChanges` — Pure Function Per Project

Returns a deterministic plan that's identical between preview and execution. This is what guarantees the preview is accurate.

```php
private function planFieldChanges(int $pid, array $rules): array
{
    $mapping = $this->getStudySiteMapping($pid);
    if (!$mapping) {
        return ['field_name' => null, 'code_allocations' => [], 'label_updates' => [], 'record_migrations' => []];
    }

    $fieldName = $mapping['redcap_field'];
    $enum = $this->loadElementEnum($pid, $fieldName);   // ['raw' => ..., 'codes' => [int => string]]
    $valueMap = $mapping['value_mapping'];

    $plan = [
        'field_name'        => $fieldName,
        'code_allocations'  => [],   // [['new_site'=>..., 'new_code'=>int, 'reason'=>'merge'|'sunset'], ...]
        'label_updates'     => [],   // [['code'=>int, 'old_label'=>..., 'new_label'=>...], ...]
        'record_migrations' => [],   // [['old_code'=>..., 'new_code'=>..., 'reason'=>...], ...]
    ];

    $codes = $enum['codes'];

    foreach ($rules as $rule) {
        $type = $rule['type'];

        if ($type === 'keep') {
            continue;
        }

        if ($type === 'rename') {
            $oldSite = $rule['old_sites'][0];
            $oldCode = $this->lookupRcCode($oldSite, $valueMap);
            if ($oldCode === null || !isset($codes[$oldCode])) continue;
            $plan['label_updates'][] = [
                'code' => $oldCode, 'rule_id' => $rule['id'],
                'mode' => 'in_place',
                'old_label' => $codes[$oldCode], 'new_label' => $rule['new_site'],
            ];
            $codes[$oldCode] = $rule['new_site']; // affects downstream allocation decisions
            continue;
        }

        if ($type === 'merge' || ($type === 'sunset' && !empty($rule['merged_into']))) {
            $newSite = $type === 'merge' ? $rule['new_site'] : $rule['merged_into'];

            // Reuse an already-allocated new code for this new site (in this run) if present.
            $newCode = $this->findCodeForLabel($codes, $newSite)
                    ?? $this->nextFreeCode($codes);
            if (!isset($codes[$newCode])) {
                $codes[$newCode] = $newSite;
                $plan['code_allocations'][] = [
                    'new_site' => $newSite, 'new_code' => $newCode, 'reason' => $type, 'rule_id' => $rule['id'],
                ];
            }

            foreach ($rule['old_sites'] as $oldSite) {
                $oldCode = $this->lookupRcCode($oldSite, $valueMap);
                if ($oldCode === null || !isset($codes[$oldCode])) continue;
                $suffix = $type === 'sunset' && !empty($rule['retired_on'])
                    ? "(retired {$rule['retired_on']}, migrated to {$newSite})"
                    : "(retired — migrated to {$newSite})";
                $plan['label_updates'][] = [
                    'code' => $oldCode, 'rule_id' => $rule['id'],
                    'mode' => 'suffix',
                    'old_label' => $codes[$oldCode], 'new_label' => $codes[$oldCode] . ' ' . $suffix,
                ];
                $codes[$oldCode] .= ' ' . $suffix;
                $plan['record_migrations'][] = [
                    'old_code' => $oldCode, 'new_code' => $newCode,
                    'rule_id' => $rule['id'], 'reason' => $type,
                ];
            }
            continue;
        }

        if ($type === 'sunset') {
            // Sunset without merged_into — label-only suffix, no record migration.
            foreach ($rule['old_sites'] as $oldSite) {
                $oldCode = $this->lookupRcCode($oldSite, $valueMap);
                if ($oldCode === null || !isset($codes[$oldCode])) continue;
                $suffix = "(retired {$rule['retired_on']})";
                $plan['label_updates'][] = [
                    'code' => $oldCode, 'rule_id' => $rule['id'],
                    'mode' => 'suffix',
                    'old_label' => $codes[$oldCode], 'new_label' => $codes[$oldCode] . ' ' . $suffix,
                ];
                $codes[$oldCode] .= ' ' . $suffix;
            }
        }
    }

    return $plan;
}
```

Re-run safety: `findCodeForLabel()` checks for an existing entry whose label equals the new site name (ignoring retirement suffixes on old codes). `label_updates[*].mode === 'suffix'` skips when the label already contains `(retired —` or `(retired YYYY-`.

### `updateRecordValues` — Sharded UPDATE

```php
private function updateRecordValues(int $pid, string $fieldName, string $oldCode, string $newCode): int
{
    $dataTable = $this->validateDataTableName($this->getProjectDataTable($pid));
    $sql = "UPDATE `{$dataTable}`
              SET value = ?
            WHERE project_id = ?
              AND field_name = ?
              AND value = ?";
    $this->module->query($sql, [(string)$newCode, $pid, $fieldName, (string)$oldCode]);
    // mysqli affected_rows isn't exposed by the EM query wrapper directly; query the count separately
    // (or rely on the framework's affected_rows accessor — implementation detail).
    return $this->module->getAffectedRowsForLastQuery();
}

private function validateDataTableName(string $tableName): string
{
    if (!preg_match('/^redcap_data[2-8]?$/', $tableName)) {
        throw new \RuntimeException(
            "Refusing to interpolate unexpected data-table name: " . var_export($tableName, true)
        );
    }
    return $tableName;
}

private function getProjectDataTable(int $pid): string
{
    // Framework v5+. The OnCore EM targets v12+ so this is the canonical path.
    if (method_exists($this->module, 'getDataTable')) {
        return (string)$this->module->getDataTable($pid);
    }
    // Safety fallback only.
    $r = $this->module->query('SELECT data_table FROM redcap_projects WHERE project_id = ?', [$pid]);
    $row = $r->fetch_assoc();
    return $row['data_table'] ?? 'redcap_data';
}
```

### `scanCodeReferences` — Pre-Flight Scan

Runs during preview for any project where a merge or target-bound sunset would rewrite codes. Returns aggregate counts and per-source details. Persisted to `redcap_entity_oncore_migration_project_status.code_references_json` so the run UI can re-display them without re-running the scan.

```php
private function scanCodeReferences(int $pid, string $fieldName, array $oldCodes): array
{
    if (empty($oldCodes)) return ['total' => 0];

    // Build the regex used in REGEXP — escaped field name + alternation over codes.
    $fieldRe = preg_quote($fieldName, '/');
    $codeRe  = implode('|', array_map(fn($c) => preg_quote((string)$c, '/'), $oldCodes));
    $pattern = "\\[{$fieldRe}\\][[:space:]]*=[[:space:]]*['\\\"]?({$codeRe})['\\\"]?";

    $result = [
        'branching_logic'   => 0,
        'action_tags'       => 0,
        'alerts'            => 0,
        'surveys_emails'    => 0,
        'surveys_scheduler' => 0,
        'reports'           => 0,
        'details'           => [],
    ];

    // One query per source — kept separate for readable result aggregation.
    foreach ([
        'branching_logic'   => ['table' => 'redcap_metadata',          'col' => 'branching_logic',    'where' => 'project_id = ?'],
        'action_tags'       => ['table' => 'redcap_metadata',          'col' => 'misc',               'where' => 'project_id = ?'],
        'alerts'            => ['table' => 'redcap_alerts',            'col' => 'trigger_logic',      'where' => 'project_id = ?'],
        'surveys_emails'    => ['table' => 'redcap_surveys_emails',    'col' => 'condition_logic',    'where' => 'survey_id IN (SELECT survey_id FROM redcap_surveys WHERE project_id = ?)'],
        'surveys_scheduler' => ['table' => 'redcap_surveys_scheduler', 'col' => 'condition_logic',    'where' => 'survey_id IN (SELECT survey_id FROM redcap_surveys WHERE project_id = ?)'],
        'reports'           => ['table' => 'redcap_reports',           'col' => 'limiter_logic',      'where' => 'project_id = ?'],
    ] as $key => $src) {
        $sql = "SELECT COUNT(*) AS c FROM {$src['table']} WHERE {$src['where']} AND {$src['col']} REGEXP ?";
        $r = $this->module->query($sql, [$pid, $pattern]);
        $row = $r->fetch_assoc();
        $result[$key] = (int)$row['c'];
    }

    $result['total'] = array_sum(array_intersect_key($result,
        array_flip(['branching_logic', 'action_tags', 'alerts', 'surveys_emails', 'surveys_scheduler', 'reports'])
    ));
    return $result;
}
```

`details` is populated on demand by a separate UI request (`getCodeReferenceDetails` AJAX action — see §8).

---

## 8. New AJAX Actions

Added to `auth-ajax-actions` in `config.json`. All require authenticated super-user context.

(Total: 15 actions. Two added in rev 3 for code-reference handling. The preview action's response shape was extended — same action name, richer payload.)

| Action | Description | Returns |
|--------|-------------|---------|
| `listSiteMigrationRuleSets` | List all saved rule sets | `[{id, name, status, created_at, ...}]` |
| `getSiteMigrationRuleSet` | Load single rule set with full rules | `{id, name, rules, ...}` |
| `saveSiteMigrationRuleSet` | Create or update a rule set | `{id}` |
| `deleteSiteMigrationRuleSet` | Delete a draft rule set | `{ok: true}` |
| `previewSiteMigration` | Dry-run preview — settings/label/mapping deltas + **code-reference scan** | `{totals: {...}, projects: [{pid, name, sitesAffected, labelChanges, mappingChanges, newCodes, recordsToMigrate, codeReferences: {branching_logic:N,...,total:N}, status, note}]}` |
| `previewSiteMigrationDeep` | Same as preview + per-project sharded `SELECT COUNT(*)` so `recordsToMigrate` is an exact count rather than an estimate | Same shape; `recordsToMigrate` filled in |
| `getCodeReferenceDetails` | Per-project drill-down for warning chip click — returns the actual rows that matched the scan regex | `[{source, table, row_id, snippet}, ...]` |
| `acknowledgeProjectWarnings` | Admin clicks Acknowledge for one project (flips `needs_ack` → `pending`) | `{ok: true, project_id, acknowledged_at}` |
| `exportMigrationPreview` | Generate CSV blob (one row per planned change, including allocated new codes and record migration counts) | `{csv, filename}` |
| `startSiteMigration` | Initialize migration session, persist scan results, set `migration-in-progress=true` | `{sessionId, total, projects: [...], blocked: [...]}` |
| `processNextMigrationProject` | Process one project, advance cursor | `{current, total, projectId, projectName, status, changesApplied, error, progress: {...}}` |
| `getMigrationStatus` | Current progress snapshot | `{current, total, completed, failed, skipped, needsAck, inProgress}` |
| `finalizeMigration` | Close session, re-enable crons | `{ok: true, finalized: true\|false, summary: {...}}` |
| `getMigrationHistory` | List past completed migrations | `[{id, name, status, completed, failed, skipped, total_projects, created}]` |
| `getMigrationProjectLog` | Per-project changes for a migration | `{status: {...}, changes: [{change_type, field_name, old_value, new_value, details, ...}]}` |

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
                                   - per project:
                                       planFieldChanges(pid, rules)   ← pure; no writes
                                       scanCodeReferences(pid, ...)    ← reads metadata/alerts/etc.
                                       persistCodeReferences(...)      ← into project_status entity
                                       set status = needs_ack iff any references found, else pending
                          ◄──────  {totals: {...}, projects: [
                                      {pid, name, sitesAffected, labelChanges, mappingChanges,
                                       newCodes: [{site, code}], recordsToMigrate: N,
                                       codeReferences: {total: 3, branching_logic: 2, alerts: 1},
                                       status: "needs_ack" | "pending" | "skipped"},
                                      ...
                                   ]}

3. Click warning chip     ──────► getCodeReferenceDetails(42, pid)
   on a project               ◄──────  [{source: "branching_logic", table: "redcap_metadata",
                                          row_id: 12345, snippet: "[site] = '1'"}, ...]
   Click "Acknowledge"    ──────► acknowledgeProjectWarnings(42, pid)
                          ◄──────  {ok: true}  (project flips to pending)

4. Export CSV (optional)  ──────► exportMigrationPreview(42)
                          ◄──────  CSV blob → browser download

5. Click Run Migration    ──────► startSiteMigration(42)
                                   - setSystemSetting('migration-in-progress', true)
                                   - updateLibrarySettings(rules)  ← system-level, done once
                                   - build ordered project list (only status=pending)
                                   - return blocked list (status=needs_ack) so the UI can warn
                          ◄──────  {sessionId, total: 83, projects: [...], blocked: [4 pids]}

6. Poll loop begins
   ┌─────────────────────────────────────────────────────────────────────────
   │ (repeats every ~1.5 s until current === total)
   │
   │  processNextMigrationProject ──────►  load next pending project
   │  (sessionId: "sess-abc")              BEGIN TRANSACTION
   │                                        plan = planFieldChanges(pid, rules)
   │                                        updateProjectSiteSubset(pid, rules)
   │                                        updateValueMapping(pid, rules, plan.code_allocations)
   │                                        applyFieldChanges(pid, plan)            ← redcap_metadata
   │                                        for each plan.record_migrations:
   │                                            updateRecordValues(pid, old, new)   ← sharded UPDATE
   │                                        logToEntity(pid, migrationId, changes)
   │                                        logToREDCap(pid, summary)
   │                                        markProjectStatus(pid, 'completed')
   │                                       COMMIT
   │                              ◄──────  {current: N, total: 83,
   │                                        projectId, projectTitle,
   │                                        status: "completed"|"failed"|"skipped"|"blocked",
   │                                        changesApplied: 6,
   │                                        newCodesAllocated: [{site, code}],
   │                                        recordsMigrated: 142,
   │                                        error: ""}
   │
   │  Update UI row for project N
   └─────────────────────────────────────────────────────────────────────────

7. current === total      ──────► finalizeMigration("sess-abc")
                                   - setSystemSetting('migration-in-progress', false)
                                   - if any project is still needs_ack/failed:
                                       rule set status stays 'active'
                                   - else mark rule set status = 'completed'
                          ◄──────  {ok: true, finalized: true|false,
                                    summary: {completed: 80, failed: 1, skipped: 2, needs_ack: 4}}

8. Show completion summary, link to History tab
```

### Idempotency

- `processNextProject` checks `redcap_entity_oncore_migration_project_status` before touching a project.
- `completed` and `skipped` projects are returned as `skipped` immediately.
- `needs_ack` projects are returned as `blocked` — they require explicit acknowledgement.
- `planFieldChanges` is pure: re-running it on a partially-migrated project produces a no-op plan (label suffixing skips entries that already contain `(retired —` / `(retired YYYY-`, code allocation reuses an existing code-by-label, record migration becomes a zero-row UPDATE).
- `value_mapping` additions check for duplicate `oc` + `rc` keys before inserting.
- `updateRecordValues` is naturally idempotent — once records are on the new code, the `WHERE value = oldCode` filter matches zero rows.

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
[ Generate Preview ]  [ Deep Preview (record counts) ]  [ Export CSV ]

Projects Affected: 83 of 100   ·   4 need acknowledgement   ·   17 already migrated
──────────────────────────────────────────────────────────────────────────────────
  PID    │ Title             │ Sites │ Label  │ Map    │ New    │ Records │ ⚠ Refs       │ Status
         │                   │       │ Δ      │ Δ      │ Codes  │ (deep)  │              │
  ──────────────────────────────────────────────────────────────────────────────────
  12345  │ STAR Trial        │ 2     │ 5      │ 4      │ 24:MH  │ 142     │ —            │ pending
  12346  │ VA Cohort Study   │ 3     │ 7      │ 6      │ 31:CH  │ 88      │ 3 ⚠ [view]   │ needs_ack
                                                          32:MH                                  ↳ Acknowledge
  12347  │ Archived Study    │ 0     │ 0      │ 0      │ —      │ —       │ —            │ skipped (no site mapping)
  12348  │ Alpha Trial       │ 1     │ 2      │ 2      │ —      │ —       │ —            │ pending     ← rename only, no new code
  ...
──────────────────────────────────────────────────────────────────────────────────
  17 already migrated · 82 pending · 4 needs_ack · 0 failed
```

Notes:
- "Sites" = number of old site names in the project that match a rule's `old_sites`
- "Label Δ" = number of `element_enum` label edits (in-place relabels + suffixes)
- "Map Δ" = number of `value_mapping` entries added (pull + push)
- "New Codes" = each `<newCode>: <SiteAcronym>` pair allocated for this project (merges + target-bound sunsets only)
- "Records (deep)" = exact COUNT(*) from the project's `redcap_data*` shard (only populated for Deep Preview)
- "⚠ Refs" = aggregate count from the code-reference scan; click `[view]` to drill into row-level details
- A project with non-zero "Refs" appears as `needs_ack` and is blocked from the run loop until the admin clicks `Acknowledge`

### Tab 4 — Live Execution

```
Rule Set: Q2 2026 Site Restructuring
⚠ This will disable OnCore sync crons for the duration. Confirm: [✓]
⚠ 4 projects are blocked (needs_ack). They will be skipped unless acknowledged in the Preview tab first.

[ Start Migration ]

────────────────────────────────────────────────
 Overall Progress:  ████████████░░░░░░  42 / 83 · 1 failed · 4 blocked
────────────────────────────────────────────────

  Project                      Status        New Codes         Records  Changes
  ───────────────────────────────────────────────────────────────────────────
  ✓  STAR Trial (12345)        completed     24:Main Hospital  142      6 changes
  ✓  VA Cohort Study (12346)   completed     31:Children's     88       9 changes
                                              32:Redwood City
  ⟳  Archived Study (12347)    skipped       —                 —        no site mapping
  ✗  Beta Trial (12400)        failed        —                 —        error: shard write failed
  ●  CURRENT: Alpha Trial      in_progress   …                 …        …
  ○  Gamma Study (12402)       pending
  ⏸  Delta Cohort (12403)      blocked       —                 —        warnings unacknowledged
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

**Example log entry visible in REDCap Project Audit Log (a merge):**
```
OnCore Site Migration
  Allocated new code 24 = "Main Hospital"
  Retired label: "1, SCI-Palo Alto" → "1, SCI-Palo Alto (retired — migrated to Main Hospital)"
  Retired label: "4, SHC Main Hosp..." → "4, SHC Main Hosp... (retired — migrated to Main Hospital)"
  Retired label: "9, SHC Satellite..." → "9, SHC Satellite... (retired — migrated to Main Hospital)"
  Migrated 142 records: code 1 → 24 (data table: redcap_data3)
  Migrated 38 records: code 4 → 24 (data table: redcap_data3)
  Migrated 11 records: code 9 → 24 (data table: redcap_data3)
  Value mapping added: OnCore "Main Hospital" → REDCap code "24"
  Project site subset updated: removed [SCI-Palo Alto, SHC Main Hosp..., SHC Satellite...], added [Main Hospital]
  Code-reference warnings: 3 (acknowledged by ihabz at 2026-06-01 10:42)
```

Research team can see this in **Project** → **Logging** → filtered by "OnCore Site Migration".

### Entity Migration Log

The `redcap_entity_oncore_site_migration_log` table records granular changes used by the History tab and internal tooling. One row per individual change. The `change_type` column now spans six values (see §6.2). For `record_value_migration` rows, the `details` JSON column carries `rows_affected` and `data_table` (the resolved shard name).

---

## 13. Performance Strategy

| Concern | Approach |
|---------|----------|
| ~100 projects × field label updates | Single parameterized `UPDATE redcap_metadata` per project (one row touched). `redcap_metadata` is **not sharded**. |
| Per-project record value rewrite (merge / target-bound sunset) | One `UPDATE <shard>` per (old code → new code) pair, scoped by `(project_id, field_name)`. REDCap's standard composite index on `(project_id, field_name)` covers the predicate; the additional `value = '<oldCode>'` filter is selective. Realistic per-project record counts (≤ ~50K rows on the merged field) complete in single-digit seconds. |
| Value mapping updates | JSON decode → mutate → encode → single `setProjectSetting` call |
| Project site subset updates | Same — single `setProjectSetting` call |
| Library setting update | Runs once at migration start via `setSystemSetting` — not per-project |
| Code-reference scan | One batched query per project: `SELECT 'branching_logic' AS src, COUNT(*) FROM redcap_metadata WHERE project_id=? AND branching_logic REGEXP '\\[<field>\\][[:space:]]*=[[:space:]]*[\\'"]?(<oldCode1>\|<oldCode2>\|…)' UNION ALL …` across six tables. Returns aggregate counts only; per-row detail loaded on demand when admin clicks the warning chip. |
| AJAX timeout risk | Each AJAX call processes exactly ONE project. Typical completion: label update + 2–3 mapping changes + 1–4 record-value UPDATEs + scan = well under 5 s for normal-sized projects. Outliers (very large projects on a merged field) capped at PHP `max_execution_time`; rollback on timeout. |
| Progress state | Stored in `redcap_entity_oncore_migration_project_status` — survives browser refresh |
| Polling interval | 1.5 s client-side — low overhead, smooth UI feel |
| Transaction scope | Per-project only — a failure in project N does not affect projects 1…N-1. Transaction wraps both `redcap_metadata` and `redcap_data*` writes, so a partial failure leaves the project in its pre-migration state. |
| Deep preview (optional) | One `SELECT COUNT(*) FROM <shard> WHERE project_id=? AND field_name=? AND value IN (…)` per project. Covered by the same composite index. Fires only when user clicks **Deep Preview**. |

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
| `OnCoreIntegration.php` | Add 3 new entity types in `redcap_entity_types()` (with extended log change_types + new `code_references_json` column on project status entity) |
| `OnCoreIntegration.php` | Add AJAX routing for 15 new actions in `redcap_module_ajax()` |
| `OnCoreIntegration.php` | Add cron guard (`SiteMigration::isMigrationInProgress`) to all 4 cron methods |
| `OnCoreIntegration.php` | Import / instantiate `SiteMigration` class |
| `config.json` | Add 15 new AJAX actions to `auth-ajax-actions` |
| `config.json` | Add control-center link for the new page |
| `config.json` | Add hidden `migration-in-progress` system setting |

---

## 15. Implementation Sequence

| Phase | Tasks | Notes |
|-------|-------|-------|
| **1 — Foundation** | Add 3 entity types to `redcap_entity_types()` (with extended log change_types + `code_references_json`/`acknowledged_at`/`acknowledged_by` columns); run `EntityDB::buildSchema()`; add `migration-in-progress` to `config.json` | Tables must exist before any other code runs |
| **2 — Core Logic (metadata side)** | Implement `SiteMigration.php` rule CRUD, `planFieldChanges`, `loadElementEnum`, `allocateNewCode`, `applyFieldChanges`, `updateProjectSiteSubset`, `updateValueMapping`, `updateLibrarySettings`, `logToEntity`, `logToREDCap` | Pure / unit-testable in isolation. `planFieldChanges` is the keystone — exercised by both preview and execution. |
| **3 — Shard-Aware Writes** | Implement `getProjectDataTable`, `validateDataTableName`, `updateRecordValues`, `countRecordsWithSiteCode` | Test the allowlist regex with hostile inputs. Verify `getDataTable()` returns expected name on a project assigned to `redcap_data2`+. |
| **4 — Code-Reference Scan** | Implement `scanCodeReferences`, `persistCodeReferences`, `acknowledgeProjectWarnings`. Wire `getCodeReferenceDetails`. | Verify the REGEXP pattern against fixtures of branching logic with single/double-quoted codes, spaces around `=`, and `(1)` vs `'1'` forms. |
| **5 — Preview** | Implement `previewMigration` (shallow + deep), `exportPreviewCSV`. Wire `previewSiteMigration`, `previewSiteMigrationDeep`, `exportMigrationPreview` AJAX actions. Preview must persist `needs_ack` / `pending` status per project. | Preview must be feature-complete before exposing Run. |
| **6 — Execution** | Implement `startMigration`, `processNextProject`, `finalizeMigration`, `getMigrationStatus`; wire AJAX actions; add cron guards | Test against a staging project first. Verify per-project transaction rolls back BOTH `redcap_metadata` and `redcap_data*` writes on simulated failure. |
| **7 — UI** | Build Control Center page with all 5 tabs; connect all AJAX actions; implement polling loop with live progress; warning chips + Acknowledge buttons; pause/stop |  |
| **8 — History & Audit** | Implement `getMigrationHistory`, `getMigrationProjectLog` AJAX; build History tab UI; verify `REDCap::logEvent()` entries appear in Project → Logging |  |
| **9 — Testing** | Dry-run preview on real data; execute against 2–3 test projects with seeded fake records on multiple shards; verify label updates, code allocations, value mappings, record migrations, audit log entries; verify cron guard works; verify idempotency on re-run; verify code-reference scan flags known branching logic |  |

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

This migration **writes to `redcap_data*` shards** (for merges and target-bound sunsets — see §3.5 and §5.2). Shard resolution and table-name safety are first-class concerns.

### What is sharded vs not

| Table family | Sharded? | How to resolve the right shard |
|--------------|----------|--------------------------------|
| `redcap_data*` (up to `redcap_data8`) | Yes | `$module->getDataTable($projectId)` (framework method) → returns the literal table name, e.g. `"redcap_data3"` or `"redcap_data8"`. Fallback: `SELECT data_table FROM redcap_projects WHERE project_id = ?`. |
| `redcap_log_event*` | Yes | Use `REDCap::logEvent()` — it routes internally; never query the log tables with raw SQL. |
| `redcap_metadata` | **No** | Single table; safe to `WHERE project_id = ?`. |
| `redcap_projects` | No | Single table. |
| `redcap_external_modules_settings` | No | Single table; settings APIs do the right thing. |
| EM entity tables (`redcap_entity_*`) | No | Single table per entity type. |

### Table-name safety

SQL parameter placeholders cannot stand in for table names. Every code path that interpolates a shard name into SQL **must** validate the resolved string against an allowlist regex before use:

```php
private function validateDataTableName(string $tableName): string
{
    if (!preg_match('/^redcap_data[2-8]?$/', $tableName)) {
        throw new \RuntimeException(
            "Refusing to interpolate unexpected data-table name: " . var_export($tableName, true)
        );
    }
    return $tableName;
}
```

Resolution flow inside the class:

```php
$dataTable = $this->validateDataTableName(
    $this->module->getDataTable($pid)
);
// $dataTable is now guaranteed to be one of redcap_data, redcap_data2…redcap_data8
$this->module->query(
    "UPDATE `{$dataTable}` SET value = ? WHERE project_id = ? AND field_name = ? AND value = ?",
    [$newCode, $pid, $fieldName, $oldCode]
);
```

### Implications for this migration

1. **Writes:** merges + target-bound sunsets issue `UPDATE <shard>` statements scoped by `(project_id, field_name, value=oldCode)`. The shard is resolved per project; the table name passes through the allowlist validator.
2. **Reads (deep preview):** `SELECT COUNT(*) FROM <shard> WHERE project_id=? AND field_name=? AND value IN (...)` — same shard resolution path, fires only when user clicks **Deep Preview**.
3. **REDCap audit log writes:** done via `REDCap::logEvent()`. The API picks the right `redcap_log_event*` shard internally.
4. **Backwards compat:** `getDataTable()` is available in framework v5+ (see `EXTERNAL_MODULES_INDEX.md`, "Data Methods"). The OnCore EM targets v12+, so the framework method is the canonical choice; the direct `redcap_projects.data_table` lookup is only a safety fallback.

### Where shard-resolution code paths exist in this module

| Location | Read/Write | Reason |
|----------|------------|--------|
| `SiteMigration::updateRecordValues()` | **Write** | Merge / target-bound sunset record rewrite |
| `SiteMigration::countRecordsWithSiteCode()` | Read | Deep preview record counts |
| `SiteMigration::scanCodeReferences()` (for `element_enum` calc-field refs only) | Read | Code-reference scan — note this scans `redcap_metadata`, not `redcap_data*`, but uses the same resolution path if a calc field stores derived values in `redcap_data*` (currently not in scope) |
| **Nowhere else** | — | All other code paths target non-sharded tables or sharding-aware APIs |

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
