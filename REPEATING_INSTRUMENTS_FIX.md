# Fix: Sync page shows zero records when mapped fields live on a repeating instrument

**Date:** 2026-08-07
**Symptom:** `pages/sync_diff.php` (e.g. pid 258) showed **0** for every count — Total REDCap Records, Total OnCore Subjects, matches — even though the protocol was linked and the linkage entity table held 31 rows. "Refresh Synced Data" completed without error and still showed zeros.

## Root cause

`\REDCap::getData(..., 'return_format' => 'array')` nests fields that live on a **repeating instrument or repeating event** under:

```
[record]['repeat_instances'][event_id][form_name][instance][field]
```

instead of the flat `[record][event_id][field]` shape the module assumes everywhere (~19 call sites read `$record[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']]`).

In project 258 every instrument — including `record_id`, the form holding the mapped `mrn` — is configured as repeating (`redcap_events_repeat` has one row per form for event 1017). So for every record:

1. `Subjects::setSyncedRecords()` read `$record[1017]['mrn']` → `null`,
2. the empty-MRN guard hit `continue`, and every linkage row was silently skipped,
3. `getSyncedRecords()` returned `[]` → `prepareSyncedRecordsSummaries()` counted zeros.

The same shape mismatch also silently broke MRN auto-matching (`getREDCapRecordIdViaMRN`) and full/partial match evaluation (`determineSyncedRecordMatch`).

### Secondary issue found (not fixed, be aware)

The project's saved field mapping stores `"event": "187887"` — an event id from a **different REDCap instance** (mapping imported/copied). `getEventNameUniqueId('187887')` fails the unique-name lookup and falls back to `$Proj->firstEventId`, which happens to be correct for a classic single-event project like 258. **In a longitudinal multi-event project this fallback would silently read the first event instead of the mapped one.** Re-saving the mapping on the Field Mapping page fixes the stored value.

## Fix (classes/Subjects.php, classes/Protocols.php)

### Read path — `Subjects::flattenRepeatingInstances()`

Static, pure. Applied to the result of both `\REDCap::getData` calls (`setRedcapProjectRecords()` and the duplicate-MRN lookup inside `getREDCapRecordIdViaMRN()`). It merges repeating data **up to the event level** so all existing `$record[$eventId][$field]` reads work unchanged:

- instances are walked in **ascending order**; the **first non-empty value** per field wins;
- existing non-empty event-level values are never overwritten;
- "empty" understands checkbox arrays (`Subjects::isEmptyDataValue()` — an all-unchecked array counts as empty, a literal `'0'` does not).

### Write path — `Subjects::prepareRowsForSave()` / `splitRowForRepeatingForms()`

REDCap JSON imports **reject** fields on a repeating instrument unless the row carries `redcap_repeat_instrument`/`redcap_repeat_instance` and contains only that instrument's fields. Without this, pull-adjudication would have failed right after the read fix made the lists visible.

- `splitRowForRepeatingForms()` (static, pure — unit tested) splits a flat save row: non-repeating fields stay in a base row; fields on each repeating form move to their own row with `redcap_repeat_instrument` + `redcap_repeat_instance = 1`; whole-event repetition adds only the instance. Checkbox export names (`field___code`) resolve through their base field. Record id / `redcap_event_name` are kept on every split row.
- `prepareRowsForSave()` is the DB-backed wrapper (looks up `redcap_events_repeat` + `redcap_metadata`) used by the three `\REDCap::saveData` sites:
  - `Subjects::updateREDCapWithProtocolSubjectId()`
  - `Subjects::pullOnCoreRecordsIntoREDCap()`
  - `Protocols::syncRecords()` (protocolSubjectId backfill for duplicate-MRN protocols)

Instance 1 is targeted on write, matching the read side (data entered before a form was switched to repeating is stored with `instance = NULL`, which REDCap treats as instance 1).

## Verification

- **E2E (Playwright, logged in as a real user):** before the fix the sync page showed all zeros; after, it shows **Total REDCap Records 31 / Not in OnCore 31**, and the "See Unlinked Records" adjudication modal renders all 31 rows with their MRNs. (OnCore side is genuinely 0 in this dev instance — protocol 23683 has no subjects; no module exceptions logged.)
- **Unit tests:** new suite `tests/repeatingInstances_test.php` (28 tests) covers `isEmptyDataValue`, `flattenRepeatingInstances`, `splitRowForRepeatingForms`. All pre-existing suites still pass (132 tests).
- **Regression A/B (2026-08-10):** the sync-diff summary was computed for **all 25 OnCore-linked projects** in this instance twice — once with the pre-fix `classes/Subjects.php`/`classes/Protocols.php` (via `git checkout HEAD~1 -- …`) and once with the fixed code — using a read-only probe running through `redcap_connect.php` in the web container. **24 of 25 projects returned byte-identical summaries**; the only difference was pid 258 (the repeating-instrument project), which went from all-zero counts to 31 records. Results were also identical across probe runs three days apart.

```bash
php tests/repeatingInstances_test.php
```

## Not covered / future work

- The write-path split targets instance 1 only; the module has no concept of "which instance is the OnCore-synced one" if a site intentionally stores per-visit demographics.
- `getREDCapRecordIdViaMRN`'s inline `getData` uses `filterLogic` on mapped fields; REDCap filter logic against repeating-instrument fields may still behave differently (`[form][instance]` smart variables are not used).
- Stale event ids in saved mappings (see secondary issue above) should ideally be validated/migrated when the mapping page loads.
