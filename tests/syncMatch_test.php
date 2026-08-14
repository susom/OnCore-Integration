<?php
/**
 * Standalone tests for empty-value / array handling in sync matching and pull preparation,
 * and for the study-site push error message:
 *   - flattenOnCoreValue()          — canonicalize OnCore values ([] / "[]" / null → '')
 *   - determineSyncedRecordMatch()  — records whose only "diff" is an empty OnCore array field
 *                                     must be FULL_MATCH, not stuck as PARTIAL_MATCH
 *   - prepareOnCoreRecordForSync()  — accept-OnCore-data must not write "[]" junk into REDCap
 *                                     and must not blank fields whose OnCore value is unmapped
 *   - getOnCoreStudySite()          — unmapped study site → informative error naming the site,
 *                                     not the misleading "Study site is missing"
 *
 * Context: OnCore array fields without a value mapping (e.g. additionalSubjectIds) arrive as []
 * (fresh API pull) or as the JSON string "[]" (oncore_subjects entity-table cache). The old raw
 * string compare "[]" != "" kept records PARTIAL_MATCH forever while the adjudication UI showed
 * both sides blank (see EMPTY_VALUES_SYNC_FIX.md).
 *
 * Run from the module root with: php tests/syncMatch_test.php
 */

namespace Stanford\OnCoreIntegration;

// ─── Stubs required before loading Subjects.php (see CLAUDE.md load-order rules) ───
trait emLoggerTrait
{
    public function emDebug(...$args) {}
    public function emError(...$args) {}
    public function emLog(...$args) {}
}

require_once __DIR__ . '/../classes/SubjectDemographics.php';
require_once __DIR__ . '/../classes/Subjects.php';
require_once __DIR__ . '/../classes/Mapping.php';

class OnCoreIntegration
{
    const FULL_MATCH = 2;
    const PARTIAL_MATCH = 3;
    const ONCORE_BIRTHDATE_FIELD = 'birthDate';

    public static function getEventNameUniqueId($name)
    {
        return $name;
    }
}

class Entities
{
    public static function createLog(...$args) {}
}

/**
 * Mapping stand-in: real getOnCoreMappedValue/getREDCapMappedValue/canUseDefaultValue logic,
 * fixture-driven getOncoreType/getRedcapValueSet (those read library/system settings in prod).
 */
class MockMapping extends Mapping
{
    private $types;
    private $choices;

    public function __construct($types = [], $choices = [])
    {
        $this->types = $types;
        $this->choices = $choices;
    }

    public function getOncoreType($oncore_field)
    {
        return $this->types[$oncore_field] ?? 'string';
    }

    public function getRedcapValueSet($redcap_field)
    {
        return $this->choices[$redcap_field] ?? [];
    }
}

function makeSubjects(Mapping $mapping): Subjects
{
    $ref = new \ReflectionClass(Subjects::class);
    $subjects = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getProperty('mapping');
    $prop->setAccessible(true);
    $prop->setValue($subjects, $mapping);
    return $subjects;
}

// ─── Tiny test runner ────────────────────────────────────────────────────────
$passed = 0;
$failed = 0;
function check(string $name, bool $ok, $extra = null): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name" . ($extra !== null ? ' — ' . json_encode($extra) : '') . "\n";
    }
}

// ─── Fixtures (mirror the customer project's pull mapping shape) ─────────────
$EV = 'ev1';
$pullFields = [
    'mrn' => ['redcap_field' => 'suo_mrn', 'event' => $EV, 'field_type' => 'text'],
    'gender' => ['redcap_field' => 'suo_gender', 'event' => $EV, 'field_type' => 'radio', 'value_mapping' => [
        ['oc' => 'Male', 'rc' => '2'],
        ['oc' => 'Female', 'rc' => '1'],
    ]],
    'race' => ['redcap_field' => 'suo_race', 'event' => $EV, 'field_type' => 'radio', 'value_mapping' => [
        ['oc' => 'White', 'rc' => '1'],
        ['oc' => 'Asian', 'rc' => '3'],
    ]],
    'additionalSubjectIds' => ['redcap_field' => 'suo_addl', 'event' => $EV, 'field_type' => 'text'],
];
$types = ['race' => 'array', 'additionalSubjectIds' => 'array', 'mrn' => 'string', 'gender' => 'text'];

$subjects = makeSubjects(new MockMapping($types));

function subjectWith(array $demo): array
{
    return ['demographics' => $demo];
}

function recordWith(string $ev, array $values): array
{
    return [$ev => $values];
}

$matchedDemo = ['mrn' => '5001009975', 'gender' => 'Male', 'race' => '["White"]', 'additionalSubjectIds' => '[]'];
$matchedRc = ['suo_mrn' => '5001009975', 'suo_gender' => '2', 'suo_race' => '1', 'suo_addl' => ''];

// ─── flattenOnCoreValue() ─────────────────────────────────────────────────────
echo "flattenOnCoreValue()\n";
check('null → \'\'', Subjects::flattenOnCoreValue(null) === '');
check('empty string stays empty', Subjects::flattenOnCoreValue('') === '');
check('cached JSON "[]" → \'\'', Subjects::flattenOnCoreValue('[]') === '');
check('real empty array → \'\'', Subjects::flattenOnCoreValue([]) === '');
check('JSON array joins values', Subjects::flattenOnCoreValue('["A","B"]') === 'A, B');
check('real array joins values', Subjects::flattenOnCoreValue(['A', 'B']) === 'A, B');
check('array of objects json-encodes items', Subjects::flattenOnCoreValue([['idType' => 'x', 'id' => 'y']]) === '{"idType":"x","id":"y"}');
check('plain text passes through', Subjects::flattenOnCoreValue('Male') === 'Male');
check('date-like string passes through', Subjects::flattenOnCoreValue('1989-08-07') === '1989-08-07');
check('non-JSON bracket text passes through', Subjects::flattenOnCoreValue('[not json') === '[not json');
check("string '0' passes through (real coded value)", Subjects::flattenOnCoreValue('0') === '0');

// ─── determineSyncedRecordMatch(): empty OnCore array fields ─────────────────
echo "\ndetermineSyncedRecordMatch() — empty OnCore array fields\n";

$status = $subjects->determineSyncedRecordMatch(subjectWith($matchedDemo), recordWith($EV, $matchedRc), $pullFields);
check('cached "[]" vs empty REDCap text → FULL_MATCH (the stuck-partial bug)', $status === OnCoreIntegration::FULL_MATCH, $status);

$demo = array_merge($matchedDemo, ['additionalSubjectIds' => []]);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $matchedRc), $pullFields);
check('fresh API [] vs empty REDCap text → FULL_MATCH', $status === OnCoreIntegration::FULL_MATCH, $status);

$demo = array_merge($matchedDemo, ['additionalSubjectIds' => null]);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $matchedRc), $pullFields);
check('null vs empty REDCap text → FULL_MATCH', $status === OnCoreIntegration::FULL_MATCH, $status);

$rc = array_merge($matchedRc, ['suo_addl' => '[]']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($matchedDemo), recordWith($EV, $rc), $pullFields);
check('REDCap junk "[]" written by old accept still FULL_MATCH', $status === OnCoreIntegration::FULL_MATCH, $status);

$demo = array_merge($matchedDemo, ['additionalSubjectIds' => '["A"]']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $matchedRc), $pullFields);
check('non-empty OnCore array vs empty REDCap → PARTIAL_MATCH', $status === OnCoreIntegration::PARTIAL_MATCH, $status);

$rc = array_merge($matchedRc, ['suo_addl' => 'A']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $rc), $pullFields);
check('OnCore ["A"] vs REDCap "A" → FULL_MATCH (flattened compare)', $status === OnCoreIntegration::FULL_MATCH, $status);

// ─── determineSyncedRecordMatch(): plain text + mapped fields still behave ───
echo "\ndetermineSyncedRecordMatch() — existing behavior preserved\n";

$rc = array_merge($matchedRc, ['suo_mrn' => '999']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($matchedDemo), recordWith($EV, $rc), $pullFields);
check('text mismatch still PARTIAL_MATCH', $status === OnCoreIntegration::PARTIAL_MATCH, $status);

$rc = array_merge($matchedRc, ['suo_gender' => '1']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($matchedDemo), recordWith($EV, $rc), $pullFields);
check('mapped radio mismatch still PARTIAL_MATCH', $status === OnCoreIntegration::PARTIAL_MATCH, $status);

$demo = array_merge($matchedDemo, ['gender' => null]);
$rc = array_merge($matchedRc, ['suo_gender' => '']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $rc), $pullFields);
check('mapped field: OnCore null + REDCap empty → FULL_MATCH (both-empty guard)', $status === OnCoreIntegration::FULL_MATCH, $status);

$demo = array_merge($matchedDemo, ['gender' => null]);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $matchedRc), $pullFields);
check('mapped field: OnCore null but REDCap has value → PARTIAL_MATCH', $status === OnCoreIntegration::PARTIAL_MATCH, $status);

$demo = array_merge($matchedDemo, ['gender' => 'Nonbinary']);
$rc = array_merge($matchedRc, ['suo_gender' => '']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $rc), $pullFields);
check('mapped field: unmapped non-empty OnCore value → PARTIAL_MATCH (no-map path kept)', $status === OnCoreIntegration::PARTIAL_MATCH, $status);

$demo = array_merge($matchedDemo, ['race' => '[]']);
$rc = array_merge($matchedRc, ['suo_race' => '']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $rc), $pullFields);
check('vmapped array field: empty OnCore array + empty REDCap → FULL_MATCH', $status === OnCoreIntegration::FULL_MATCH, $status);

// Regression: the radio/text branch used to iterate the raw JSON string instead of the decoded
// array, so race mismatches on entity-cached subjects (race = '["Asian"]') were silently skipped.
$demo = array_merge($matchedDemo, ['race' => '["Asian"]']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $matchedRc), $pullFields);
check('vmapped array field: cached JSON race mismatch detected → PARTIAL_MATCH', $status === OnCoreIntegration::PARTIAL_MATCH, $status);

$demo = array_merge($matchedDemo, ['race' => '["White"]']);
$rc = array_merge($matchedRc, ['suo_race' => '1']);
$status = $subjects->determineSyncedRecordMatch(subjectWith($demo), recordWith($EV, $rc), $pullFields);
check('vmapped array field: cached JSON race match → FULL_MATCH', $status === OnCoreIntegration::FULL_MATCH, $status);

// ─── prepareOnCoreRecordForSync(): accept-OnCore-data write values ───────────
echo "\nprepareOnCoreRecordForSync()\n";

$data = $subjects->prepareOnCoreRecordForSync(subjectWith($matchedDemo), $pullFields);
check('cached "[]" saved as \'\' (not literal "[]" junk)', ($data[$EV]['suo_addl'] ?? 'missing') === '');
check('mapped gender saved as REDCap code', ($data[$EV]['suo_gender'] ?? null) === '2');
check('vmapped array race saved as REDCap code', ($data[$EV]['suo_race'] ?? null) === '1');
check('plain text mrn saved as-is', ($data[$EV]['suo_mrn'] ?? null) === '5001009975');

$demo = array_merge($matchedDemo, ['additionalSubjectIds' => '["A","B"]']);
$data = $subjects->prepareOnCoreRecordForSync(subjectWith($demo), $pullFields);
check('non-empty array saved as joined readable text', ($data[$EV]['suo_addl'] ?? null) === 'A, B');

$demo = array_merge($matchedDemo, ['gender' => 'Nonbinary']);
$data = $subjects->prepareOnCoreRecordForSync(subjectWith($demo), $pullFields);
check('unmapped non-empty OnCore value leaves REDCap field untouched', !array_key_exists('suo_gender', $data[$EV] ?? []), $data);

$demo = array_merge($matchedDemo, ['gender' => null]);
$data = $subjects->prepareOnCoreRecordForSync(subjectWith($demo), $pullFields);
check('empty OnCore value clears the REDCap field', ($data[$EV]['suo_gender'] ?? 'missing') === '');

// ─── getOnCoreStudySite(): push error message ────────────────────────────────
echo "\ngetOnCoreStudySite()\n";

$siteChoices = ['suo_study_site' => [
    '14' => 'Byers Eye Institute', '18' => 'Main Hospital', '19' => 'Redwood City', '23' => 'Livermore',
]];
$sitesSubjects = makeSubjects(new MockMapping($types, $siteChoices));
$pushSiteField = ['redcap_field' => 'suo_study_site', 'event' => $EV, 'field_type' => 'radio', 'value_mapping' => [
    ['oc' => 'Byers Eye Institute', 'rc' => '14'],
    ['oc' => 'Main Hospital', 'rc' => '18'],
    ['oc' => 'Redwood City', 'rc' => '19'],
]];
$oncoreFieldsDef = ['studySites' => ['allow_default' => false]];

$method = new \ReflectionMethod(Subjects::class, 'getOnCoreStudySite');
$method->setAccessible(true);

$site = $method->invoke($sitesSubjects, recordWith($EV, ['suo_study_site' => '19']), $pushSiteField, $oncoreFieldsDef);
check('mapped code returns OnCore site name', $site === 'Redwood City', $site);

try {
    $method->invoke($sitesSubjects, recordWith($EV, ['suo_study_site' => '23']), $pushSiteField, $oncoreFieldsDef);
    check('unmapped site code throws', false);
} catch (\Exception $e) {
    check('unmapped site code throws', true);
    check('error names the record\'s site label', strpos($e->getMessage(), "Livermore") !== false, $e->getMessage());
    check('error lists configured OnCore sites', strpos($e->getMessage(), 'Redwood City') !== false, $e->getMessage());
    check('error is not the misleading "Study site is missing"', strpos($e->getMessage(), 'Study site is missing') === false, $e->getMessage());
}

$site = $method->invoke($sitesSubjects, recordWith($EV, ['suo_study_site' => '']), $pushSiteField, $oncoreFieldsDef);
check('empty site returns null (generic missing-site error path kept)', $site === null, $site);

$defField = array_merge($pushSiteField, ['default_value' => 'Main Hospital']);
$defDef = ['studySites' => ['allow_default' => true]];
$site = $method->invoke($sitesSubjects, recordWith($EV, ['suo_study_site' => '']), $defField, $defDef);
check('default value used when allowed and site empty', $site === 'Main Hospital', $site);

// ─── Summary ─────────────────────────────────────────────────────────────────
echo "\n=======================================\n";
echo "PASSED: $passed  FAILED: $failed\n";
exit($failed > 0 ? 1 : 0);
