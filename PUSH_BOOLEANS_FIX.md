# Push: OnCore Boolean Fields Rejected / Silently Dropped

**Date:** 2026-08-17
**Files changed:** `OnCoreIntegration.php`, `classes/Subjects.php`, `ajax/handler.php`
**Tests:** `tests/pushBooleans_test.php` (29 tests, standalone, no DB)

## Customer report

> I had every field mapped and tried to push REDCap to OnCore. It gave a message saying a
> boolean was missing and it needed true or false. I put FALSE on all the booleans and it
> still failed. I redid the mapping with just the required fields and it synced.

Follow-up ask: make the error name the offending field — OnCore's own error message doesn't
say which one.

## Investigation

OnCore has three boolean demographics fields: `approximateBirthDate`, `birthDateNotAvailable`,
`approximateExpiredDate`. All are optional (`required: false`, no `allow_default`).

Tested directly against the OnCore sandbox (protocol 23767) to isolate the cause:

| Payload | Result |
|---|---|
| Boolean keys omitted entirely | `201` accepted |
| Boolean keys as real JSON `false` | `201` accepted |
| Boolean keys as REDCap's raw string `'0'` | `400 Invalid boolean. Valid values are [true, false]` (`field: null`) |
| Partial address (`streetAddress` only, no city/state/zip) | `201` accepted |
| `birthDate`/`expiredDate` present, companion booleans omitted | `201` accepted |

This ruled out a field-interdependency issue (the "partial address" hypothesis, by analogy to
optional fields that become mandatory once related fields are filled — doesn't apply here) and
pinned the cause precisely: **sending these fields as strings, in any form, is both necessary
and sufficient** to reproduce the customer's exact error, regardless of which value (true-ish or
false-ish) was chosen. Omitting the keys is always safe.

Two independent code paths were sending REDCap's raw `'1'`/`'0'` strings instead of JSON booleans:

1. **No `value_mapping` configured** (e.g. mapping is left as a bare radio/dropdown/`truefalse`
   field): `prepareREDCapRecordForSync`'s unmapped branch does `gettype($redcapValue)` (returns
   `'string'`) against `oncore_field_type` (`['bool']`) — mismatch → the field is **silently
   dropped**. Harmless by itself (dropping is safe, per the table above), but it also means a
   customer who *thinks* they mapped these fields never actually sends them.
2. **A `value_mapping` is present** on the field (reachable via the Field Mapping UI: mapping a
   REDCap radio/dropdown field to a boolean OnCore field triggers a value-mapping table, since the
   UI decides whether to show one based on the *REDCap* field having enumerated choices — it never
   checks whether the *OnCore* field is boolean-typed, which has no enumerable "valid values" to
   map in the first place). Here `$data[$key] = $map['oc']` sends whichever string is stored in the
   mapping (`'0'`, `'1'`, `'true'`, `'False'`, whatever the customer picked) — **always a string**,
   which OnCore always rejects. This is the customer's actual scenario: full mapping → this path
   engaged → guaranteed failure no matter what boolean value was chosen. Reduced mapping → the
   field mapping was removed entirely → back to path 1 (dropped, harmless) → push succeeded.

Confirmed end-to-end: built a payload replicating the customer's exact broken config (radio field
with a `value_mapping` on a boolean OnCore field, REDCap value `'0'`) through the real
`prepareREDCapRecordForSync` → posted it to the OnCore sandbox → `201` after the fix.

## Fixes

1. **`OnCoreIntegration::$ONCORE_DEMOGRAPHICS_BOOLEAN_FIELDS`** (new) — names the three fields.
2. **`Subjects::toOnCoreBoolean($value)`** (new) — converts `'1'/'0'/'true'/'false'/'yes'/'no'`
   (any case) and real bools/ints to a real `bool`; returns `null` for empty/unrecognized so the
   caller omits the key entirely rather than sending a bad value.
3. **`prepareREDCapRecordForSync`** and **`fillMissingData`** — boolean-typed OnCore fields are
   now handled **first, unconditionally, before checking `value_mapping`**. A value_mapping on a
   boolean field is nonsensical (OnCore has no enumerable valid values for it) and is now ignored
   entirely in favor of converting the raw REDCap value directly.
4. **`createOnCoreProtocolSubject`** — defense-in-depth: validates any boolean demographics field
   that is still non-bool right before the API call and throws *"approximateExpiredDate must be
   true or false (got '0')."* — naming the field, unlike OnCore's own `field: null` response.
5. **`ajax/handler.php`** — hardened the existing field-prefixing of OnCore error messages
   (`!empty()` instead of truthy-check on a possibly-missing key, and a fallback for a missing
   `message` key) so a malformed OnCore error body doesn't throw a PHP warning on top of the
   original error.

## Note on the field-mapping UI

The underlying cause of the *value_mapping-present* path is a UI gap: `makeValueMappingUI_RC`
renders a value-mapping table whenever the **REDCap** field has enumerated choices, without
checking whether the **OnCore** field itself is boolean (and therefore has no `oncore_valid_values`
to offer — confirmed empty for both configured libraries). The generated table has no working
`<select>` for the OnCore side in that case, so a customer configuring "just the required fields"
never hits this, but anyone deliberately mapping a Yes/No-style REDCap field to a boolean OnCore
property lands in a broken, empty-dropdown state. The push-side fix above makes the *result*
correct regardless of what ends up stored in `value_mapping` for these three fields, so no
customer-visible failure is possible anymore — a UI-level fix (skip rendering value-mapping for
boolean OnCore fields) would prevent the confusing dead-end table but is not required for
correctness and was left out of scope for this fix.

## Verification

- `php tests/pushBooleans_test.php` — 29/29 pass, no warnings. All other suites unaffected
  (228 total across the module).
- E2E against the OnCore sandbox: the customer's exact broken config (radio value_mapping on a
  boolean field, `'0'`/`'1'` strings) now produces a payload with real JSON booleans and OnCore
  returns `201`.
