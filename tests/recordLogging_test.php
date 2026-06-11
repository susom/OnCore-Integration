<?php
/**
 * Standalone tests for the record-value migration logging + human-summary layer:
 *   - getRecordsWithSiteCode()        — captures the rows updateRecordValues() rewrites
 *   - buildHumanChanges()             — per-rule plain-English summary for the UI
 *   - logRecordMigrationsToREDCap()   — per-record REDCap::logEvent() + volume cap
 *
 * Uses bracketed namespaces so a GLOBAL \REDCap stub can sit beside the
 * Stanford\OnCoreIntegration stubs and capture logEvent() calls.
 *
 * Run with: php tests/recordLogging_test.php
 */

// ─── Global stubs: \REDCap captures logEvent() calls. ───────────────────────
namespace {
    class REDCap
    {
        /** @var array<int,array> */
        public static $events = [];
        public static function reset(): void { self::$events = []; }
        public static function logEvent($description, $changes_made = "", $sql = "", $record = null, $event_id = null, $project_id = null)
        {
            self::$events[] = [
                'description' => $description,
                'changes'     => $changes_made,
                'record'      => $record,
                'event_id'    => $event_id,
                'pid'         => $project_id,
            ];
        }
    }
}

namespace Stanford\OnCoreIntegration {

    // emLoggerTrait must exist before SiteMigration is loaded (used at class-def time).
    trait emLoggerTrait
    {
        function emLog()   {}
        function emError() {}
        function emDebug() {}
    }

    require_once __DIR__ . '/../classes/SiteMigration.php';

    class OnCoreIntegration
    {
        const REDCAP_ONCORE_FIELDS_MAPPING_NAME   = 'oncore_fields_mapping';
        const ONCORE_STUDY_SITE                   = 'Study Site';
        const REDCAP_ENTITY_ONCORE_SITE_MIGRATION = 'redcap_entity_oncore_site_migration';
    }

    /** Multi-row result mock. */
    class FakeResult
    {
        private array $rows;
        private int   $i = 0;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function fetch_assoc(): ?array
        {
            return $this->i < count($this->rows) ? $this->rows[$this->i++] : null;
        }
    }

    class MockModule
    {
        /** @var array<int,array> rows returned for the record-capture SELECT */
        public array $recordRows = [];
        /** @var array<int,array{sql:string,params:array}> every query seen */
        public array $seen = [];

        public function getDataTable($pid): string { return 'redcap_data'; }

        public function query(string $sql, array $params): ?FakeResult
        {
            $this->seen[] = ['sql' => $sql, 'params' => $params];
            if (strpos($sql, 'SELECT record, event_id, instance') !== false) {
                return new FakeResult($this->recordRows);
            }
            return null;
        }
    }

    function makeSM(MockModule $mock): SiteMigration
    {
        $sm   = (new \ReflectionClass(SiteMigration::class))->newInstanceWithoutConstructor();
        $prop = (new \ReflectionClass(SiteMigration::class))->getProperty('module');
        $prop->setAccessible(true);
        $prop->setValue($sm, $mock);
        return $sm;
    }

    // ─── Tiny assert harness ────────────────────────────────────────────────
    $passed = 0; $failed = 0;
    function assert_equal($label, $actual, $expected) {
        global $passed, $failed;
        $ok = $actual === $expected;
        if ($ok) { $passed++; echo "  PASS  $label\n"; }
        else {
            $failed++;
            echo "  FAIL  $label\n        expected: " . var_export($expected, true)
               . "\n        actual:   " . var_export($actual, true) . "\n";
        }
    }
    function assert_true($label, $cond) { assert_equal($label, (bool)$cond, true); }

    // ════════════════════════════════════════════════════════════════════════
    // Test 1 — getRecordsWithSiteCode: parses rows + uses the right WHERE columns
    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 1: getRecordsWithSiteCode parses rows and uses (project_id, field_name, value) WHERE\n";

    $mock1 = new MockModule();
    $mock1->recordRows = [
        ['record' => '101', 'event_id' => '55', 'instance' => null],
        ['record' => '102', 'event_id' => '55', 'instance' => '2'],
    ];
    $sm1  = makeSM($mock1);
    $rows = $sm1->getRecordsWithSiteCode(248, 'study_site', '8');

    assert_equal('2 rows parsed',                 count($rows),               2);
    assert_equal('row 0 record',                  $rows[0]['record'],         '101');
    assert_equal('row 0 event_id',                $rows[0]['event_id'],       '55');
    assert_equal('row 0 instance null',           $rows[0]['instance'],       null);
    assert_equal('row 1 instance preserved',      $rows[1]['instance'],       '2');
    $sel = end($mock1->seen);
    assert_true('WHERE has project_id+field_name+value',
        strpos($sel['sql'], 'project_id = ? AND field_name = ? AND value = ?') !== false);
    assert_equal('params match (pid, field, code)', $sel['params'], [248, 'study_site', '8']);

    // ════════════════════════════════════════════════════════════════════════
    // Test 2 — buildHumanChanges: merge (deep) + rename + keep-omitted
    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 2: buildHumanChanges summarizes per rule; keep is omitted\n";

    $rules2 = [
        ['id' => 'merge-ch', 'type' => 'merge', 'new_site' => "Children's Hospital",
         'old_sites' => ['SCI-LPCH', 'LPCH Satellite & Other']],
        ['id' => 'rename-tv', 'type' => 'rename', 'new_site' => 'Tri-Valley',
         'old_sites' => ['SHC Tri-Valley']],
        ['id' => 'keep-byers', 'type' => 'keep', 'old_sites' => ['Byers Eye Institute']],
    ];
    $plan2 = [
        'field_name' => 'study_site',
        'code_allocations' => [
            ['rule_id' => 'merge-ch', 'new_site' => "Children's Hospital", 'new_code' => '30', 'reason' => 'merge'],
        ],
        'label_updates' => [
            ['rule_id' => 'merge-ch', 'code' => '23', 'mode' => 'suffix',
             'old_label' => 'SCI-LPCH', 'new_label' => "SCI-LPCH (retired, merged into Children's Hospital)"],
            ['rule_id' => 'merge-ch', 'code' => '9', 'mode' => 'suffix',
             'old_label' => 'LPCH Satellite & Other', 'new_label' => "LPCH Satellite & Other (retired, merged into Children's Hospital)"],
            ['rule_id' => 'rename-tv', 'code' => '24', 'mode' => 'in_place',
             'old_label' => 'SHC Tri-Valley', 'new_label' => 'Tri-Valley'],
        ],
        'record_migrations' => [
            ['rule_id' => 'merge-ch', 'old_code' => '23', 'new_code' => '30', 'reason' => 'merge'],
            ['rule_id' => 'merge-ch', 'old_code' => '9',  'new_code' => '30', 'reason' => 'merge'],
        ],
        'site_code_map' => ["Children's Hospital" => '30', 'Tri-Valley' => '24'],
    ];
    $sm2 = makeSM(new MockModule());
    $human = $sm2->buildHumanChanges($rules2, $plan2, ['23' => 12, '9' => 3]);

    assert_equal('2 rules summarized (keep omitted)', count($human), 2);
    assert_equal('first item is the merge',           $human[0]['kind'],     'merge');
    assert_equal('merge headline',                    $human[0]['headline'], "Merge → Children's Hospital");
    assert_equal('merge total records (12+3)',        $human[0]['records'],  15);
    assert_true('merge line names retired site + count',
        (bool)preg_grep('/SCI-LPCH.*12 record/', $human[0]['lines']));
    assert_true('merge mentions new code 30',
        (bool)preg_grep('/code 30/', $human[0]['lines']));
    assert_equal('second item is the rename',         $human[1]['kind'],     'rename');
    assert_equal('rename moves 0 records',            $human[1]['records'],  0);
    assert_true('rename line says label only',
        (bool)preg_grep('/label only/', $human[1]['lines']));

    // Non-deep (no counts) → records null for merge, lines still present.
    $humanShallow = $sm2->buildHumanChanges($rules2, $plan2);
    assert_equal('shallow merge records is null', $humanShallow[0]['records'], null);

    // ════════════════════════════════════════════════════════════════════════
    // Test 3 — logRecordMigrationsToREDCap: one logEvent per record, right args
    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 3: logRecordMigrationsToREDCap emits one logEvent per record\n";

    \REDCap::reset();
    $sm3 = makeSM(new MockModule());
    $sm3->logRecordMigrationsToREDCap(248, 'study_site', [
        [
            'old_code' => '23', 'new_code' => '30', 'reason' => 'merge',
            'old_label' => 'SCI-LPCH', 'new_site' => "Children's Hospital",
            'rows' => [
                ['record' => '101', 'event_id' => '55', 'instance' => null],
                ['record' => '102', 'event_id' => '55', 'instance' => '2'],
            ],
        ],
    ]);

    assert_equal('2 logEvent calls (one per row)', count(\REDCap::$events), 2);
    $e0 = \REDCap::$events[0];
    assert_equal('logEvent record',   $e0['record'],   '101');
    assert_equal('logEvent event_id', $e0['event_id'], 55);          // numeric cast
    assert_equal('logEvent pid',      $e0['pid'],      248);
    assert_true('changes_made formats field = newcode',
        strpos($e0['changes'], "study_site = '30'") !== false);
    assert_true('changes_made notes old code + human context',
        strpos($e0['changes'], "'23'") !== false
        && strpos($e0['changes'], "SCI-LPCH → Children's Hospital") !== false);

    // empty migrations / empty field → no calls
    \REDCap::reset();
    $sm3->logRecordMigrationsToREDCap(248, 'study_site', []);
    assert_equal('no calls for empty migrations', count(\REDCap::$events), 0);
    $sm3->logRecordMigrationsToREDCap(248, '', [['old_code' => '1', 'new_code' => '2', 'rows' => [['record' => '1', 'event_id' => '1']]]]);
    assert_equal('no calls when field name empty', count(\REDCap::$events), 0);

    // ════════════════════════════════════════════════════════════════════════
    // Test 4 — volume cap: ≤ CAP per-record + a single summary entry beyond it
    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 4: per-record logging capped at PER_RECORD_LOG_CAP + 1 summary\n";

    \REDCap::reset();
    $cap   = SiteMigration::PER_RECORD_LOG_CAP;
    $many  = [];
    for ($i = 0; $i < $cap + 5; $i++) {
        $many[] = ['record' => (string)(1000 + $i), 'event_id' => '55', 'instance' => null];
    }
    $sm4 = makeSM(new MockModule());
    $sm4->logRecordMigrationsToREDCap(248, 'study_site', [
        ['old_code' => '23', 'new_code' => '30', 'old_label' => 'SCI-LPCH',
         'new_site' => "Children's Hospital", 'rows' => $many],
    ]);

    assert_equal('CAP per-record + 1 summary', count(\REDCap::$events), $cap + 1);
    $last = end(\REDCap::$events);
    assert_true('summary entry mentions the 5 deferred + cap',
        strpos($last['description'], 'summary') !== false
        && strpos($last['changes'], '5 additional') !== false);
    assert_equal('summary has no record attached', $last['record'], null);

    // ─── Summary ─────────────────────────────────────────────────────────────
    echo "\nResults: $passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}
