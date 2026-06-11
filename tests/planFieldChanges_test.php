<?php
/**
 * Standalone test for planFieldChanges — exercises the "label already retired"
 * fix: a previously-retired old_code must still appear in record_migrations even
 * though it must NOT produce a duplicate label_update.
 *
 * Run with: php tests/planFieldChanges_test.php
 */

namespace Stanford\OnCoreIntegration;

// ─── Step 1: emLoggerTrait stub — must come BEFORE SiteMigration.php is
//     loaded because SiteMigration uses it at class-definition time.

trait emLoggerTrait
{
    function emLog()   {}
    function emError() {}
    function emDebug() {}
}

// ─── Step 2: Load the class under test.

require_once __DIR__ . '/../classes/SiteMigration.php';

// ─── Step 3: Remaining stubs. OnCoreIntegration must be defined here because
//     getStudySiteMapping() references OnCoreIntegration::ONCORE_STUDY_SITE at
//     runtime. ONCORE_STUDY_SITE must match the key used in buildMock().

class OnCoreIntegration
{
    const REDCAP_ONCORE_FIELDS_MAPPING_NAME = 'oncore_fields_mapping';
    const ONCORE_STUDY_SITE                 = 'Study Site';
    const REDCAP_ENTITY_ONCORE_SITE_MIGRATION = 'redcap_entity_oncore_site_migration';
}

class FakeResult
{
    private ?array $row;
    private bool   $fetched = false;
    public function __construct(?array $row) { $this->row = $row; }
    public function fetch_assoc(): ?array
    {
        if ($this->fetched) return null;
        $this->fetched = true;
        return $this->row;
    }
}

class MockModule
{
    public string $projectSettingJson = '';
    public string $elementEnumRaw     = '';

    public function getProjectSetting(string $key, ?int $pid = null): ?string
    {
        return $this->projectSettingJson ?: null;
    }
    public function query(string $sql, array $params): ?FakeResult
    {
        if (stripos($sql, 'element_enum') !== false) {
            return new FakeResult(['element_enum' => $this->elementEnumRaw]);
        }
        return null;
    }
}

// ─── Step 4: Factory — inject mock via reflection (module property is private).

function makeSM(MockModule $mock): SiteMigration
{
    $sm   = (new \ReflectionClass(SiteMigration::class))->newInstanceWithoutConstructor();
    $prop = (new \ReflectionClass(SiteMigration::class))->getProperty('module');
    $prop->setAccessible(true);
    $prop->setValue($sm, $mock);
    return $sm;
}

// ─── Step 5: Test helpers.

$pass = 0;
$fail = 0;

function assert_equal(string $label, $got, $expected): void
{
    global $pass, $fail;
    if ($got === $expected) {
        echo "  PASS  $label\n";
        $pass++;
    } else {
        echo "  FAIL  $label\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        got:      " . var_export($got, true) . "\n";
        $fail++;
    }
}

function assert_true(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        echo "  PASS  $label\n";
        $pass++;
    } else {
        echo "  FAIL  $label\n";
        $fail++;
    }
}

function buildMock(array $vmapEntries, array $codes): MockModule
{
    $mock = new MockModule();
    $mock->projectSettingJson = json_encode([
        'pull' => [
            'Study Site' => [
                'redcap_field'  => 'suo_study_site',
                'value_mapping' => $vmapEntries,
            ],
        ],
    ]);
    $parts = [];
    foreach ($codes as $code => $label) {
        $parts[] = "$code, $label";
    }
    // REDCap delimits element_enum choices with " \n " (backslash-n), not a pipe.
    $mock->elementEnumRaw = implode(" \\n ", $parts);
    return $mock;
}

// ─── Shared fixtures ────────────────────────────────────────────────────────

$mergeRule = [
    'id'        => 'merge-childrens-hospital',
    'type'      => 'merge',
    'new_site'  => "Children's Hospital",
    'old_sites' => [
        'SCI-LPCH',
        'LPCH Main Hosp, Welch Rd & campus/nearby clinics',
        'LPCH Satellite & Other',
    ],
];

// Project 248 vmap — only LPCH Main Hosp mapped (rc=8), plus stale tail entry.
$vmap248 = [
    ['oc' => 'LPCH Main Hosp, Welch Rd & campus/nearby clinics', 'rc' => '8'],
    ['rc' => '8', 'oc' => 'LPCH Main Hosp, Welch Rd & campus/nearby clinics'],
    ['oc' => "Children's Hospital", 'rc' => '3'],  // stale tail from prior buggy run
];

// Full vmap covering all three old sites.
$vmapFull = array_merge($vmap248, [
    ['oc' => 'SCI-LPCH',               'rc' => '23'],
    ['oc' => 'LPCH Satellite & Other', 'rc' => '9'],
]);

// ═══════════════════════════════════════════════════════════════════════════
// Test 1 — FRESH label: both label_update AND record_migration produced
// ═══════════════════════════════════════════════════════════════════════════
echo "\nTest 1: FRESH label (not yet retired) → label_update + record_migration\n";

$sm1   = makeSM(buildMock($vmap248, [
    '3'  => 'Lucas Center',
    '8'  => 'LPCH Main Hosp, Welch Rd & campus/nearby clinics',
    '26' => "Children's Hospital",
]));
$plan1 = $sm1->planFieldChanges(248, [$mergeRule]);

$lu8 = array_values(array_filter($plan1['label_updates'],     fn($x) => (string)$x['code']     === '8'));
$rm8 = array_values(array_filter($plan1['record_migrations'], fn($x) => (string)$x['old_code'] === '8'));

assert_equal('label_updates has entry for code 8',      count($lu8),                        1);
assert_equal('label_update mode is suffix',             $lu8[0]['mode'] ?? null,            'suffix');
assert_equal('record_migrations has entry for code 8',  count($rm8),                        1);
assert_equal('record_migration targets code 26',        (string)($rm8[0]['new_code'] ?? ''), '26');
assert_equal('no code_allocations (26 exists)',         count($plan1['code_allocations']),   0);

// ═══════════════════════════════════════════════════════════════════════════
// Test 2 — ALREADY RETIRED label: record_migration produced, NO label_update
//          *** This is the exact bug fix for project 248 ***
// ═══════════════════════════════════════════════════════════════════════════
echo "\nTest 2: RETIRED label → record_migration WITHOUT label_update (bug fix)\n";

$sm2   = makeSM(buildMock($vmap248, [
    '3'  => 'Lucas Center',
    '8'  => "LPCH Main Hosp, Welch Rd & campus/nearby clinics (retired — migrated to Children's Hospital)",
    '26' => "Children's Hospital",
]));
$plan2 = $sm2->planFieldChanges(248, [$mergeRule]);

$lu8_2 = array_values(array_filter($plan2['label_updates'],     fn($x) => (string)$x['code']     === '8'));
$rm8_2 = array_values(array_filter($plan2['record_migrations'], fn($x) => (string)$x['old_code'] === '8'));

assert_equal('label_updates does NOT have entry for code 8', count($lu8_2),                        0);
assert_equal('record_migrations HAS entry for code 8',       count($rm8_2),                        1);
assert_equal('record_migration targets code 26',             (string)($rm8_2[0]['new_code'] ?? ''), '26');
assert_equal('no code_allocations',                          count($plan2['code_allocations']),      0);

// ═══════════════════════════════════════════════════════════════════════════
// Test 3 — ALL three old sites retired → 3 record_migrations, 0 label_updates
// ═══════════════════════════════════════════════════════════════════════════
echo "\nTest 3: ALL old sites retired → 3 record_migrations, 0 label_updates\n";

$sm3   = makeSM(buildMock($vmapFull, [
    '8'  => "LPCH Main Hosp, Welch Rd & campus/nearby clinics (retired — migrated to Children's Hospital)",
    '9'  => "LPCH Satellite & Other (retired — migrated to Children's Hospital)",
    '23' => "SCI-LPCH (retired — migrated to Children's Hospital)",
    '26' => "Children's Hospital",
]));
$plan3 = $sm3->planFieldChanges(248, [$mergeRule]);

assert_equal('0 label_updates when all labels retired',  count($plan3['label_updates']),     0);
assert_equal('3 record_migrations for 3 retired codes',  count($plan3['record_migrations']), 3);
assert_equal('all record_migrations target code 26',
    array_unique(array_column($plan3['record_migrations'], 'new_code')), ['26']);

// ═══════════════════════════════════════════════════════════════════════════
// Test 4 — MIXED: 1 retired + 2 fresh → 2 label_updates + 3 record_migrations
// ═══════════════════════════════════════════════════════════════════════════
echo "\nTest 4: MIXED (1 retired + 2 fresh) → 2 label_updates + 3 record_migrations\n";

$sm4   = makeSM(buildMock($vmapFull, [
    '8'  => "LPCH Main Hosp, Welch Rd & campus/nearby clinics (retired — migrated to Children's Hospital)",
    '9'  => 'LPCH Satellite & Other',
    '23' => 'SCI-LPCH',
    '26' => "Children's Hospital",
]));
$plan4 = $sm4->planFieldChanges(248, [$mergeRule]);

$luCodes = array_column($plan4['label_updates'],     'code');
$rmCodes = array_column($plan4['record_migrations'], 'old_code');

assert_equal('2 label_updates for non-retired codes',   count($plan4['label_updates']),     2);
assert_equal('3 record_migrations (all 3 old sites)',   count($plan4['record_migrations']), 3);
assert_true('code 8 absent from label_updates',         !in_array('8',  $luCodes, true));
assert_true('code 8 present in record_migrations',       in_array('8',  $rmCodes, true));
assert_true('code 9 present in label_updates',           in_array('9',  $luCodes, true));
assert_true('code 9 present in record_migrations',       in_array('9',  $rmCodes, true));
assert_true('code 23 present in label_updates',          in_array('23', $luCodes, true));
assert_true('code 23 present in record_migrations',      in_array('23', $rmCodes, true));

// ═══════════════════════════════════════════════════════════════════════════
// Test 5 — Regression: old_code === new_code → no self-migration
// ═══════════════════════════════════════════════════════════════════════════
echo "\nTest 5: old_code === new_code is still skipped (no self-migration)\n";

$sm5   = makeSM(buildMock(
    [['oc' => 'LPCH Main Hosp, Welch Rd & campus/nearby clinics', 'rc' => '26']],
    ['26' => "Children's Hospital"]
));
$plan5 = $sm5->planFieldChanges(248, [$mergeRule]);

assert_equal('0 record_migrations when old_code === new_code', count($plan5['record_migrations']), 0);
assert_equal('0 label_updates when old_code === new_code',     count($plan5['label_updates']),     0);

// ─── Summary ────────────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
// Tests for the SHC Tri-Valley / SHC Main Hosp bugs
// ═══════════════════════════════════════════════════════════════════════════

$renameTriValleyRule = [
    'id'        => 'rename-tri-valley',
    'type'      => 'rename',
    'old_sites' => ['SHC Tri-Valley'],
    'new_site'  => 'Tri-Valley',
];

$mergeMainHospRule = [
    'id'        => 'merge-main-hospital',
    'type'      => 'merge',
    'new_site'  => 'Main Hospital',
    'old_sites' => ['SCI-Palo Alto', 'SHC Main Hosp, Pasteur, Welch & campus/nearby clinics', 'SHC Satellite & Other'],
];

// ─── Test 6: Rename idempotency — label already shows "Tri-Valley (retired…)" ───
// The rename rule must NOT plan an in_place update that strips the suffix,
// and it must NOT re-write plan['site_code_map']["Tri-Valley"] = "24" to
// interfere with the merge.
echo "\nTest 6: Rename skips code whose clean label already matches target\n";

$vmapTriValley = [
    ['oc' => 'SHC Tri-Valley', 'rc' => '24'],
    ['oc' => 'SHC Main Hosp, Pasteur, Welch & campus/nearby clinics', 'rc' => '1'],
];
$codesTriValleyRetired = [
    '1'  => 'SHC Main Hosp, Pasteur, Welch & campus/nearby clinics',
    '24' => 'Tri-Valley (retired — migrated to Main Hospital)',  // wrongly retired in prior run
    '25' => 'Main Hospital',
];

$sm6   = makeSM(buildMock($vmapTriValley, $codesTriValleyRetired));
$plan6 = $sm6->planFieldChanges(1, [$renameTriValleyRule, $mergeMainHospRule]);

// Rename must be a no-op for code 24 — clean label "Tri-Valley" already matches.
$lu24 = array_values(array_filter($plan6['label_updates'], fn($x) => (string)$x['code'] === '24'));
assert_equal('rename produces NO label_update for already-renamed retired code', count($lu24), 0);

// Merge must retire code 1 ("SHC Main Hosp...").
$lu1 = array_values(array_filter($plan6['label_updates'], fn($x) => (string)$x['code'] === '1'));
assert_equal('merge retires code 1 (SHC Main Hosp...)',       count($lu1),                        1);
assert_equal('label_update mode for code 1 is suffix',        $lu1[0]['mode'] ?? null,            'suffix');

// Record migration for code 1 → 25.
$rm1 = array_values(array_filter($plan6['record_migrations'], fn($x) => (string)$x['old_code'] === '1'));
assert_equal('record_migration code 1 → 25 planned',          count($rm1),                        1);
assert_equal('record_migration targets code 25',              (string)($rm1[0]['new_code'] ?? ''), '25');

// ─── Test 7: Rename skips a code that was retired (not matched by strip) ───
echo "\nTest 7: Rename skips code whose label is retired under a DIFFERENT name\n";

// Code 24 was originally "SHC Tri-Valley", then wrongly got "(retired — migrated to Main Hospital)"
// WITHOUT being renamed first. The rename target is "Tri-Valley". Since strip("SHC Tri-Valley
// (retired — migrated to Main Hospital)") = "SHC Tri-Valley" ≠ "Tri-Valley", the rename
// should still skip (labelAlreadyRetired guard).
$codesWronglyRetired = [
    '1'  => 'SHC Main Hosp, Pasteur, Welch & campus/nearby clinics',
    '24' => 'SHC Tri-Valley (retired — migrated to Main Hospital)',
    '25' => 'Main Hospital',
];

$sm7   = makeSM(buildMock($vmapTriValley, $codesWronglyRetired));
$plan7 = $sm7->planFieldChanges(1, [$renameTriValleyRule, $mergeMainHospRule]);

$lu24_7 = array_values(array_filter($plan7['label_updates'], fn($x) => (string)$x['code'] === '24'));
assert_equal('rename skips already-retired code (even with different base name)', count($lu24_7), 0);

// ─── Test 8: Merge falls back to element_enum label when vmap has no entry ──
// "SHC Main Hosp..." is not in the project's vmap but the element_enum has
// code 1 = "SHC Main Hosp..." — the fallback must find and retire code 1.
echo "\nTest 8: Merge retires code found only via element_enum label (vmap fallback)\n";

$vmapOnlyTriValley = [
    ['oc' => 'SHC Tri-Valley', 'rc' => '24'],
    // No entry for "SHC Main Hosp..." — vmap is incomplete.
];
$codesForFallback = [
    '1'  => 'SHC Main Hosp, Pasteur, Welch & campus/nearby clinics',
    '24' => 'SHC Tri-Valley',
    '25' => 'Main Hospital',
];

$sm8   = makeSM(buildMock($vmapOnlyTriValley, $codesForFallback));
$plan8 = $sm8->planFieldChanges(1, [$mergeMainHospRule]);

// Code 1 must be retired despite having no vmap entry.
$lu1_8 = array_values(array_filter($plan8['label_updates'], fn($x) => (string)$x['code'] === '1'));
assert_equal('fallback: merge retires code 1 via element_enum label', count($lu1_8),             1);
assert_equal('fallback: label_update mode is suffix',                  $lu1_8[0]['mode'] ?? null, 'suffix');

$rm1_8 = array_values(array_filter($plan8['record_migrations'], fn($x) => (string)$x['old_code'] === '1'));
assert_equal('fallback: record_migration code 1 → 25',                count($rm1_8),             1);

// Code 24 ("SHC Tri-Valley") is NOT in old_sites of merge-main-hospital — must not be touched.
$lu24_8 = array_values(array_filter($plan8['label_updates'], fn($x) => (string)$x['code'] === '24'));
assert_equal('fallback: code 24 (SHC Tri-Valley) NOT touched by merge', count($lu24_8), 0);

// ─── Test 9: real project-248 enum — Issue 1 (no dup allocation) + Issue 2 (record found) ──
// Regression for the "|"-vs-"\n" parse bug. With the real element_enum (where
// "Children's Hospital" already exists at code 26 and record 2 sits at code 8):
//   • the merge must REUSE code 26 — no new allocation (Issue 1: "added as code 2"),
//   • and must plan a record migration for code 8 (Issue 2: "no records use retired sites").
echo "\nTest 9: real pid-248 enum → reuse existing code, find record at code 8\n";

$codes248 = [
    '1'  => 'SHC Main Hosp, Pasteur, Welch & campus/nearby clinics',
    '2'  => 'SHC Redwood City',
    '8'  => 'LPCH Main Hosp, Welch Rd & campus/nearby clinics', // record 2 lives here
    '9'  => 'LPCH Satellite & Other',
    '23' => 'SCI-LPCH',
    '25' => 'Main Hospital',
    '26' => "Children's Hospital",                              // merge target ALREADY defined
];
$vmap248real = [
    ['oc' => 'SCI-LPCH',                                          'rc' => '23'],
    ['oc' => 'LPCH Main Hosp, Welch Rd & campus/nearby clinics',  'rc' => '8'],
    ['oc' => 'LPCH Satellite & Other',                            'rc' => '9'],
];

$sm9   = makeSM(buildMock($vmap248real, $codes248));
$plan9 = $sm9->planFieldChanges(248, [$mergeRule]);   // $mergeRule = Children's Hospital merge

// Issue 1: "Children's Hospital" exists (26) → reuse it, allocate nothing.
assert_equal('Issue 1: NO new code allocated (reuse 26)', count($plan9['code_allocations']), 0);

// Issue 2: record migration planned for code 8 → 26 (record 2 will move).
$rm8_9 = array_values(array_filter($plan9['record_migrations'], fn($x) => (string)$x['old_code'] === '8'));
assert_equal('Issue 2: record_migration planned for code 8', count($rm8_9), 1);
assert_equal('Issue 2: code 8 migrates to existing code 26', (string)($rm8_9[0]['new_code'] ?? ''), '26');

// All three LPCH old sites should be retired into 26.
$rmNew9 = array_unique(array_column($plan9['record_migrations'], 'new_code'));
assert_equal('all LPCH old sites target code 26', $rmNew9, ['26']);

echo "\n";
echo "Results: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
