<?php
/**
 * Standalone tests for OnCore boolean demographics handling on push:
 *   - toOnCoreBoolean()             — REDCap '1'/'0' (and variants) → real JSON booleans
 *   - prepareREDCapRecordForSync()  — boolean fields converted and sent (the old gettype()
 *                                     check silently dropped them: gettype('0') is 'string',
 *                                     and even a real bool is 'boolean' ≠ library type 'bool')
 *   - fillMissingData()             — OnStage fill path must not send raw '1'/'0' strings
 *   - createOnCoreProtocolSubject() — non-bool boolean values rejected BEFORE the API call
 *                                     with an error that names the offending field
 *
 * Context: OnCore's API rejects non-boolean values with
 *   {"message":"Invalid boolean. Valid values are [true, false]","errorType":"FieldValidationError","field":null}
 * — field is null, so the module cannot name the offender from the response. Customers with all
 * fields mapped saw this on every push regardless of the True/False value entered in REDCap
 * (see PUSH_BOOLEANS_FIX.md).
 *
 * Run from the module root with: php tests/pushBooleans_test.php
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
    const ONCORE_BIRTHDATE_NOT_REQUIRED_FIELD = 'birthDateNotAvailable';
    const ONCORE_STUDY_SITE = 'studySites';
    const ONCORE_SUBJECT_SOURCE_TYPE_ONCORE = 'OnCore';

    public static $ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS = array(
        "mrn", "gender", "ethnicity", "race", "birthDate", "lastName", "firstName",
    );
    public static $ONCORE_DEMOGRAPHICS_BOOLEAN_FIELDS = array(
        "approximateBirthDate", "birthDateNotAvailable", "approximateExpiredDate",
    );

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
 * Mapping stand-in: real value-mapping/default-value logic, fixture-driven library lookups
 * (getOncoreField/getOncoreRequiredFields read system settings in prod).
 */
class MockMapping extends Mapping
{
    private $oncoreFields;

    public function __construct($oncoreFields = [])
    {
        $this->oncoreFields = $oncoreFields;
    }

    public function getOncoreField($oncore_field)
    {
        return $this->oncoreFields[$oncore_field] ?? [];
    }

    public function getOncoreRequiredFields()
    {
        return [];
    }
}

function makeSubjects(Mapping $mapping, bool $canPush = false): Subjects
{
    $ref = new \ReflectionClass(Subjects::class);
    $subjects = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getProperty('mapping');
    $prop->setAccessible(true);
    $prop->setValue($subjects, $mapping);
    if ($canPush) {
        $subjects->setCanPush(true);
    }
    return $subjects;
}

function injectRecords(Subjects $subjects, array $records): void
{
    $prop = (new \ReflectionClass(Subjects::class))->getProperty('redcapProjectRecords');
    $prop->setAccessible(true);
    $prop->setValue($subjects, $records);
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

// ─── Fixtures (mirror the customer project's push mapping + library types) ───
$EV = 'ev1';
$pushFields = [
    'mrn' => ['redcap_field' => 'suo_mrn', 'event' => $EV, 'field_type' => 'text'],
    'expiredDate' => ['redcap_field' => 'suo_death_date', 'event' => $EV, 'field_type' => 'text'],
    'birthDateNotAvailable' => ['redcap_field' => 'suo_birth_date_not_avail', 'event' => $EV, 'field_type' => 'truefalse'],
    'approximateBirthDate' => ['redcap_field' => 'suo_approx_birth_date', 'event' => $EV, 'field_type' => 'truefalse'],
    'approximateExpiredDate' => ['redcap_field' => 'suo_approx_death_date', 'event' => $EV, 'field_type' => 'truefalse'],
    'gender' => ['redcap_field' => 'suo_gender', 'event' => $EV, 'field_type' => 'radio', 'value_mapping' => [
        ['oc' => 'Male', 'rc' => '2'], ['oc' => 'Female', 'rc' => '1'],
    ]],
];
$oncoreFieldsDef = [
    'mrn' => ['oncore_field_type' => ['string'], 'required' => 'true', 'allow_default' => 'false'],
    'expiredDate' => ['oncore_field_type' => ['string'], 'required' => 'false', 'allow_default' => 'false'],
    'birthDateNotAvailable' => ['oncore_field_type' => ['bool'], 'required' => 'false', 'allow_default' => 'false'],
    'approximateBirthDate' => ['oncore_field_type' => ['bool'], 'required' => 'false', 'allow_default' => 'false'],
    'approximateExpiredDate' => ['oncore_field_type' => ['bool'], 'required' => 'false', 'allow_default' => 'false'],
    'gender' => ['oncore_field_type' => ['string'], 'required' => 'true', 'allow_default' => 'true'],
];

$subjects = makeSubjects(new MockMapping());

// ─── toOnCoreBoolean() ────────────────────────────────────────────────────────
echo "toOnCoreBoolean()\n";
check("'1' → true", Subjects::toOnCoreBoolean('1') === true);
check("'0' → false", Subjects::toOnCoreBoolean('0') === false);
check("'true' → true", Subjects::toOnCoreBoolean('true') === true);
check("'False' → false", Subjects::toOnCoreBoolean('False') === false);
check("'yes' → true", Subjects::toOnCoreBoolean('yes') === true);
check('real bool passes through', Subjects::toOnCoreBoolean(false) === false);
check('int 1 → true', Subjects::toOnCoreBoolean(1) === true);
check("'' → null (omit key)", Subjects::toOnCoreBoolean('') === null);
check('null → null (omit key)', Subjects::toOnCoreBoolean(null) === null);
check("garbage 'maybe' → null (omit key)", Subjects::toOnCoreBoolean('maybe') === null);

// ─── prepareREDCapRecordForSync(): booleans converted, not dropped ───────────
echo "\nprepareREDCapRecordForSync()\n";

$record = [$EV => [
    'suo_mrn' => '123',
    'suo_death_date' => '2020-01-01',
    'suo_birth_date_not_avail' => '0',   // customer set FALSE on all booleans
    'suo_approx_birth_date' => '1',
    'suo_approx_death_date' => '0',
    'suo_gender' => '2',
]];
injectRecords($subjects, ['5' => $record]);
$payload = $subjects->prepareREDCapRecordForSync('5', $pushFields, $oncoreFieldsDef);

check('FALSE truefalse sent as real false', ($payload['birthDateNotAvailable'] ?? 'missing') === false, $payload);
check('TRUE truefalse sent as real true', ($payload['approximateBirthDate'] ?? 'missing') === true, $payload);
check('death-date companion boolean present', ($payload['approximateExpiredDate'] ?? 'missing') === false, $payload);
check('json_encode emits literal booleans', strpos(json_encode($payload), '"approximateBirthDate":true') !== false, json_encode($payload));
check('string fields unaffected', ($payload['expiredDate'] ?? null) === '2020-01-01');
check('value-mapped field unaffected', ($payload['gender'] ?? null) === 'Male');

$record[$EV]['suo_approx_death_date'] = '';
injectRecords($subjects, ['5' => $record]);
$payload = $subjects->prepareREDCapRecordForSync('5', $pushFields, $oncoreFieldsDef);
check('empty boolean omitted entirely (OnCore treats present keys as required)', !array_key_exists('approximateExpiredDate', $payload), $payload);

// Regression: confirmed live against the OnCore sandbox — sending these fields as raw REDCap
// strings ('0'/'1') always gets "Invalid boolean. Valid values are [true, false]" from OnCore,
// regardless of value, while omitting the keys entirely is accepted. A boolean OnCore field
// with a (nonsensical, but reachable via the field-mapping UI) value_mapping must still be
// converted to a real boolean — value_mapping must never win for these fields.
$vmapFields = $pushFields;
$vmapFields['approximateBirthDate']['value_mapping'] = [['oc' => '0', 'rc' => '0'], ['oc' => '1', 'rc' => '1']];
$record[$EV]['suo_approx_birth_date'] = '0';
injectRecords($subjects, ['5' => $record]);
$payload = $subjects->prepareREDCapRecordForSync('5', $vmapFields, $oncoreFieldsDef);
check('boolean field with a value_mapping still sent as a real boolean (root cause fix)', ($payload['approximateBirthDate'] ?? 'missing') === false, $payload);
check('boolean field with a value_mapping is never a string', !is_string($payload['approximateBirthDate'] ?? null), $payload);

// ─── fillMissingData(): OnStage fill path ────────────────────────────────────
echo "\nfillMissingData()\n";

$onStage = [
    'mrn' => '123',
    'expiredDate' => null,
    'birthDateNotAvailable' => null,
    'approximateBirthDate' => null,
    'approximateExpiredDate' => null,
    'gender' => '',
];
$record[$EV]['suo_approx_death_date'] = '0';
$record[$EV]['suo_approx_birth_date'] = '1';
$filled = $subjects->fillMissingData($record, $onStage, $pushFields, $oncoreFieldsDef);
check('OnStage empty boolean filled with real false (was raw string \'0\')', ($filled['approximateExpiredDate'] ?? 'missing') === false, $filled);
check('OnStage empty boolean filled with real true (was raw string \'1\')', ($filled['approximateBirthDate'] ?? 'missing') === true, $filled);
check('OnStage empty text filled raw', ($filled['expiredDate'] ?? null) === '2020-01-01');
check('OnStage empty vmapped field filled with OnCore label', ($filled['gender'] ?? null) === 'Male');

$record[$EV]['suo_approx_death_date'] = '';
$filled = $subjects->fillMissingData($record, $onStage, $pushFields, $oncoreFieldsDef);
check('empty REDCap boolean leaves OnStage value untouched', array_key_exists('approximateExpiredDate', $filled) && is_null($filled['approximateExpiredDate']), $filled);

$onStageVmap = $onStage;
$record[$EV]['suo_approx_birth_date'] = '1';
$filled = $subjects->fillMissingData($record, $onStageVmap, $vmapFields, $oncoreFieldsDef);
check('OnStage fill: boolean field with a value_mapping still filled as a real boolean', ($filled['approximateBirthDate'] ?? 'missing') === true, $filled);

// ─── createOnCoreProtocolSubject(): named boolean validation before API call ─
echo "\ncreateOnCoreProtocolSubject()\n";

$libFields = ['birthDate' => ['allow_default' => 'true', 'oncore_field_type' => ['string']]];
$pushSubjects = makeSubjects(new MockMapping($libFields), true);
$demo = [
    'mrn' => '123', 'gender' => 'Male', 'ethnicity' => 'Unknown', 'race' => ['Unknown'],
    'birthDate' => '1980-01-10', 'lastName' => 'ZZ', 'firstName' => 'TEST',
    'approximateExpiredDate' => '0',   // the exact value the old code sent to OnCore
];
try {
    $pushSubjects->createOnCoreProtocolSubject('23767', 'Redwood City', null, $demo);
    check('string boolean rejected before API call', false);
} catch (\Exception $e) {
    check('string boolean rejected before API call', true);
    check('error names the offending field', strpos($e->getMessage(), 'approximateExpiredDate') !== false, $e->getMessage());
    check('error explains expected values', strpos($e->getMessage(), 'true or false') !== false, $e->getMessage());
}

$demo['approximateExpiredDate'] = false;
$demo['birthDateNotAvailable'] = true;
try {
    // real booleans must pass validation; the call then fails at the HTTP layer (no user object
    // in this standalone test), which proves validation was cleared.
    $pushSubjects->createOnCoreProtocolSubject('23767', 'Redwood City', null, $demo);
    check('real booleans pass validation', true);
} catch (\Exception $e) {
    check('real booleans pass validation', strpos($e->getMessage(), 'must be true or false') === false, $e->getMessage());
} catch (\Throwable $e) {
    // TypeError/Error from the missing Users object = validation passed, request layer reached
    check('real booleans pass validation', true);
}

// ─── Summary ─────────────────────────────────────────────────────────────────
echo "\n=======================================\n";
echo "PASSED: $passed  FAILED: $failed\n";
exit($failed > 0 ? 1 : 0);
