# Empty-Value Sync Matching & Study-Site Push Error Fix

**Date:** 2026-08-14
**Files changed:** `classes/Subjects.php`
**Tests:** `tests/syncMatch_test.php` (39 tests, standalone, no DB)

## Customer report

On the Sync Diff page, records stayed **Partially Matched** even though the Adjudicate Diff
view showed the OnCore and REDCap data as identical. Accepting OnCore data and refreshing did
not resolve them. Separately, pushing a REDCap-only record to OnCore appeared to do nothing
(the OnCore count never increased) — the push modal showed the unhelpful error
*"Study site is missing"* even though the record had a study site selected.

Reproduced locally on pid 260 (linked OnCore protocol 23767): 4 stuck partial matches +
1 unpushable REDCap record.

## Root causes

### 1. Empty OnCore array values never compare equal to empty REDCap fields

OnCore array-typed demographics fields **without a value mapping** (e.g. `additionalSubjectIds`)
arrive in two representations:

- a real PHP array `[]` on a fresh API pull, or
- the JSON-encoded string `"[]"` when served from the `redcap_entity_oncore_subjects`
  entity-table cache (TEXT column; this is the common case since `forceDemographicsPull`
  is always false).

Two code paths then disagreed:

| Path | Logic | Verdict for `"[]"` vs `""` |
|------|-------|---------------------------|
| Adjudicate Diff UI (`Mapping::makeSyncTableHTML`) | type-aware: `json_decode` + `array_diff` | **match** → row hidden |
| Status calculation (`Subjects::determineSyncedRecordMatch`) | raw string compare (array handling only ran for fields *with* a value mapping) | **PARTIAL_MATCH** |

So every subject with an empty `additionalSubjectIds` was permanently Partially Matched while
the UI showed nothing to adjudicate. Worse, "Accept OnCore Data"
(`prepareOnCoreRecordForSync`) copied the raw value, writing the literal junk string `[]`
into the REDCap text field.

The same class of bug existed for **value-mapped** fields whose OnCore value is null/empty:
no value-mapping entry exists for "empty", so `empty($map)` forced PARTIAL_MATCH even when the
REDCap field was empty too.

### 2. Misleading push error for unmapped study sites

`getOnCoreStudySite()` looked up the record's study-site code in the push value mapping and
returned `$map['oc']` — `null` when the code wasn't mapped (locally: code 23 "Livermore",
while the project only maps Byers Eye Institute/14, Main Hospital/18, Redwood City/19).
`createOnCoreProtocolSubject()` then reported *"Study site is missing"*, which reads as a
data-entry problem rather than what it is: the record's site is not one of the protocol's
configured OnCore study sites.

### 3. (Found while fixing) Race compare silently skipped for cached subjects

In `determineSyncedRecordMatch`, the radio/text branch for array-typed *mapped* fields
iterated `$onCoreValue` (the raw value) instead of `$parsed` (the decoded array). For
entity-cached subjects the raw value is a JSON **string** like `'["Asian"]'` — `foreach` over
a string warns and skips, so race mismatches on cached subjects were never detected.

## Fixes (`classes/Subjects.php`)

1. **`Subjects::flattenOnCoreValue($value)`** (new, public static) — canonicalizes a value:
   `null` → `''`; JSON-array strings (leading `[`) are decoded; arrays are joined to
   `'A, B'` after dropping empty items (so `[]`/`"[]"` → `''`); scalars pass through.
2. **`determineSyncedRecordMatch`**
   - unmapped-field compare flattens **both** sides first (also heals records that already
     contain `"[]"` junk written by the old accept);
   - value-mapped non-array branch: both-sides-empty → field matches (`continue`), and the
     no-map lookup uses `$map['rc'] ?? null` (no warning);
   - array-mapped radio/text branch iterates `$parsed`, fixing the silent skip (#3);
   - the "can't find mapping" log now prints the field key + a scalar-safe value.
3. **`prepareOnCoreRecordForSync`** (Accept OnCore Data)
   - unmapped text fields are saved flattened — never raw JSON junk;
   - value-mapped fields: mapped → code as before; OnCore empty → `''` (clears stale data);
     **unmapped non-empty** OnCore value → field left untouched (the old code silently
     blanked it by writing `$map['rc']` = null).
4. **`getOnCoreStudySite`** — when the record *has* a study site but it isn't in the push
   value mapping, throws:
   > This record's study site 'Livermore' (choice 23) is not mapped to an OnCore study site
   > for this protocol. Study sites configured for this project: Byers Eye Institute, Main
   > Hospital, Redwood City. Correct the record's study site or add it to the Study Sites
   > value mapping on the Field Mapping page.
   
   An actually-empty study site still returns `null` so the generic
   "Study site is missing" error stays accurate for that case.

## Verification

- `php tests/syncMatch_test.php` — 39/39 pass, no warnings. All other suites still pass
  (199 total across the module).
- E2E on pid 260: one "Refresh Synced Data" flips all 4 records to **Fully Matched with zero
  data changes** (no accept needed, nothing written to records); pushing record 5 produces
  the informative study-site error above.

## Notes for support

Customers already affected may have the literal string `[]` stored in their mapped
additional-subject-IDs text field from a pre-fix "Accept OnCore Data" click. The new compare
treats `[]` and empty as equal, so those records match fine; the stored `[]` can be cleared
manually if it bothers anyone (it displays on the record home page).
