<?php
/**
 * Standalone tests for repeating-instrument support in Subjects:
 *   - isEmptyDataValue()           — empty semantics incl. checkbox arrays
 *   - flattenRepeatingInstances()  — merge repeat_instances data up to event level for reads
 *   - splitRowForRepeatingForms()  — split save rows so JSON imports into repeating forms succeed
 *
 * Context: \REDCap::getData nests fields on repeating instruments under
 * [record]['repeat_instances'][event_id][form][instance][field]. The module reads
 * $record[$eventId][$field], so projects whose mapped instrument repeats showed
 * zero records on the sync page (see REPEATING_INSTRUMENTS_FIX.md).
 *
 * Run with: php tests/repeatingInstances_test.php
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

echo "isEmptyDataValue()\n";
check('empty string is empty', Subjects::isEmptyDataValue(''));
check('null is empty', Subjects::isEmptyDataValue(null));
check("string '0' is NOT empty (real coded value)", !Subjects::isEmptyDataValue('0'));
check('non-empty string is not empty', !Subjects::isEmptyDataValue('35325059'));
check('empty array is empty', Subjects::isEmptyDataValue([]));
check('all-unchecked checkbox array is empty', Subjects::isEmptyDataValue(['1' => '0', '2' => '0']));
check('checkbox array with a checked box is not empty', !Subjects::isEmptyDataValue(['1' => '0', '2' => '1']));

echo "\nflattenRepeatingInstances()\n";

// Real-world shape captured from pid 258: only repeat_instances, no event-level data.
$records = [
    1 => [
        'repeat_instances' => [
            1017 => [
                'record_id' => [
                    1 => ['study_id' => '', 'mrn' => '35325059'],
                ],
            ],
        ],
    ],
];
$flat = Subjects::flattenRepeatingInstances($records);
check('repeat-only record: mrn readable at event level', ($flat[1][1017]['mrn'] ?? null) === '35325059', $flat);
check('repeat-only record: empty study_id merged as empty', array_key_exists('study_id', $flat[1][1017] ?? []) && $flat[1][1017]['study_id'] === '');

// Classic record without repeat_instances is untouched.
$records = [7 => [1017 => ['mrn' => '111']]];
check('classic record untouched', Subjects::flattenRepeatingInstances($records) === $records);

// Existing non-empty event-level value wins over instance value.
$records = [
    1 => [
        1017 => ['mrn' => 'event-level'],
        'repeat_instances' => [1017 => ['demographics' => [1 => ['mrn' => 'instance-level']]]],
    ],
];
$flat = Subjects::flattenRepeatingInstances($records);
check('event-level non-empty value not overwritten', $flat[1][1017]['mrn'] === 'event-level');

// Empty event-level value is filled from the instance.
$records = [
    1 => [
        1017 => ['mrn' => ''],
        'repeat_instances' => [1017 => ['demographics' => [1 => ['mrn' => '222']]]],
    ],
];
$flat = Subjects::flattenRepeatingInstances($records);
check('empty event-level value filled from instance', $flat[1][1017]['mrn'] === '222');

// Instances walked in ascending order; first non-empty wins even with unsorted keys.
$records = [
    1 => [
        'repeat_instances' => [
            1017 => [
                'demographics' => [
                    3 => ['mrn' => 'third'],
                    1 => ['mrn' => ''],
                    2 => ['mrn' => 'second'],
                ],
            ],
        ],
    ],
];
$flat = Subjects::flattenRepeatingInstances($records);
check('first non-empty instance wins (ascending order)', $flat[1][1017]['mrn'] === 'second', $flat);

// Checkbox: all-unchecked event-level array replaced by instance with a checked box.
$records = [
    1 => [
        1017 => ['race' => ['1' => '0', '2' => '0']],
        'repeat_instances' => [1017 => ['demographics' => [1 => ['race' => ['1' => '1', '2' => '0']]]]],
    ],
];
$flat = Subjects::flattenRepeatingInstances($records);
check('all-unchecked checkbox replaced by checked instance value', $flat[1][1017]['race'] === ['1' => '1', '2' => '0']);

// Non-array input passthrough (getData can return false).
check('non-array input returned as-is', Subjects::flattenRepeatingInstances(false) === false);

echo "\nsplitRowForRepeatingForms()\n";

$row = [
    'record_id' => '5',
    'redcap_event_name' => '187887',
    'mrn' => '35325059',
    'study_id' => 'PS-1',
];

// Nothing repeats → row passes through untouched.
$rows = Subjects::splitRowForRepeatingForms($row, 'record_id', ['mrn' => 'demographics', 'study_id' => 'demographics'], []);
check('no repeating forms: single unchanged row', $rows === [$row]);

// Whole event repeats → single row gains redcap_repeat_instance only.
$rows = Subjects::splitRowForRepeatingForms($row, 'record_id', [], [], true);
check('event repeats: one row with instance 1', count($rows) === 1 && $rows[0]['redcap_repeat_instance'] === 1);
check('event repeats: no repeat_instrument key', !isset($rows[0]['redcap_repeat_instrument']));

// All data fields on one repeating form → one repeat row, no base row.
$rows = Subjects::splitRowForRepeatingForms($row, 'record_id', ['mrn' => 'demographics', 'study_id' => 'demographics'], ['demographics']);
check('all fields repeating: single repeat row', count($rows) === 1, $rows);
check('repeat row carries instrument + instance', ($rows[0]['redcap_repeat_instrument'] ?? null) === 'demographics' && ($rows[0]['redcap_repeat_instance'] ?? null) === 1);
check('repeat row keeps record id + event name', $rows[0]['record_id'] === '5' && $rows[0]['redcap_event_name'] === '187887');
check('repeat row keeps field values', $rows[0]['mrn'] === '35325059' && $rows[0]['study_id'] === 'PS-1');

// Mixed forms → base row for non-repeating fields plus one row per repeating form.
$rows = Subjects::splitRowForRepeatingForms($row, 'record_id', ['mrn' => 'demographics', 'study_id' => 'enrollment'], ['demographics']);
check('mixed forms: two rows', count($rows) === 2, $rows);
$base = null;
$repeat = null;
foreach ($rows as $r) {
    if (isset($r['redcap_repeat_instrument'])) $repeat = $r; else $base = $r;
}
check('mixed forms: base row has non-repeating field only', $base !== null && isset($base['study_id']) && !isset($base['mrn']));
check('mixed forms: repeat row has repeating field only', $repeat !== null && isset($repeat['mrn']) && !isset($repeat['study_id']));
check('mixed forms: both rows keep record id', ($base['record_id'] ?? null) === '5' && ($repeat['record_id'] ?? null) === '5');

// Checkbox export names (field___code) resolve through their base field name.
$cbRow = ['record_id' => '5', 'race___1' => '1', 'race___2' => '0'];
$rows = Subjects::splitRowForRepeatingForms($cbRow, 'record_id', ['race' => 'demographics'], ['demographics']);
check('checkbox fields follow their base field form', count($rows) === 1 && ($rows[0]['race___1'] ?? null) === '1' && ($rows[0]['redcap_repeat_instrument'] ?? null) === 'demographics');

// Unknown field (no metadata match) stays in the base row.
$rows = Subjects::splitRowForRepeatingForms($row, 'record_id', ['mrn' => 'demographics'], ['demographics']);
$base = null;
foreach ($rows as $r) {
    if (!isset($r['redcap_repeat_instrument'])) $base = $r;
}
check('unknown-form field stays in base row', $base !== null && ($base['study_id'] ?? null) === 'PS-1');

echo "\nResults: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
