<?php
/**
 * Integration-style test for SiteMigration::processProject() — the per-project
 * transaction + post-commit logging path that the planner-only tests never reach.
 *
 * The headline case is the post-commit isolation guard: if \REDCap::logEvent()
 * throws AFTER commit, the project must still be reported COMPLETED (the data is
 * already committed). Without the guard, the outer catch flips it to FAILED and,
 * on re-run, the per-record audit is lost forever (records already moved → the
 * capture SELECT returns nothing).
 *
 * Run with: php tests/processProject_test.php
 */

namespace {
    class REDCap
    {
        public static $events = [];
        public static bool $throwOnLog = false;
        public static function reset(): void { self::$events = []; self::$throwOnLog = false; }
        public static function logEvent($description, $changes_made = "", $sql = "", $record = null, $event_id = null, $project_id = null)
        {
            if (self::$throwOnLog) {
                throw new \RuntimeException('simulated logEvent failure');
            }
            self::$events[] = ['description' => $description, 'record' => $record, 'pid' => $project_id];
        }
    }
}

namespace Stanford\OnCoreIntegration {

    trait emLoggerTrait
    {
        public array $errors = [];
        function emLog()   {}
        function emError($m = '') { $this->errors[] = $m; }
        function emDebug() {}
    }

    require_once __DIR__ . '/../classes/SiteMigration.php';

    class OnCoreIntegration
    {
        const REDCAP_ONCORE_FIELDS_MAPPING_NAME             = 'redcap-oncore-fields-mapping';
        const REDCAP_ONCORE_PROJECT_SITE_STUDIES            = 'redcap-oncore-project-site-studies';
        const ONCORE_STUDY_SITE                             = 'Study Site';
        const REDCAP_ENTITY_ONCORE_SITE_MIGRATION           = 'redcap_entity_oncore_site_migration';
        const REDCAP_ENTITY_ONCORE_SITE_MIGRATION_LOG       = 'redcap_entity_oncore_site_migration_log';
        const REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS = 'redcap_entity_oncore_migration_project_status';
        const REDCAP_ENTITY_ONCORE_PROTOCOLS                = 'redcap_entity_oncore_protocols';
        const ONCORE_PROTOCOL_STATUS_YES                    = 2;
    }

    class FakeResult
    {
        private array $rows; private int $i = 0;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function fetch_assoc(): ?array
        {
            return $this->i < count($this->rows) ? $this->rows[$this->i++] : null;
        }
    }

    /**
     * Mock that answers every query processProject() issues for a single merge:
     *   element_enum "23, SCI-LPCH | 26, Children's Hospital", vmap {SCI-LPCH: 23},
     *   one record (#77) sitting at code 23.
     */
    class MockModule
    {
        use emLoggerTrait;
        public array $seen = [];
        public string $enumRaw = "23, SCI-LPCH \\n 26, Children's Hospital";
        /** rows the capture SELECT returns */
        public array $records = [['record' => '77', 'event_id' => '88', 'instance' => null]];
        public bool $linkageApproved = true;

        public function getDataTable($pid): string { return 'redcap_data'; }

        public function getProjectSetting(string $key, ?int $pid = null): ?string
        {
            if ($key === OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME) {
                return json_encode([
                    'pull' => [
                        OnCoreIntegration::ONCORE_STUDY_SITE => [
                            'redcap_field'  => 'study_site',
                            'value_mapping' => ['SCI-LPCH' => '23'],
                        ],
                    ],
                ]);
            }
            return null; // project-site-studies, etc.
        }
        public function setProjectSetting(string $key, $value, ?int $pid = null): void {}

        public function query(string $sql, array $params): ?FakeResult
        {
            $this->seen[] = ['sql' => $sql, 'params' => $params];
            if (strpos($sql, 'redcap_entity_oncore_protocols') !== false) {
                return new FakeResult($this->linkageApproved ? [['ok' => '1']] : []);
            }
            if (strpos($sql, 'SELECT element_enum') !== false) {
                return new FakeResult([['element_enum' => $this->enumRaw]]);
            }
            if (strpos($sql, 'SELECT record, event_id, instance') !== false) {
                return new FakeResult($this->records);
            }
            if (strpos($sql, 'SELECT COUNT(*)') !== false) {
                return new FakeResult([['c' => (string)count($this->records)]]);
            }
            // status SELECT → null (fresh project); START TRANSACTION / COMMIT /
            // UPDATE / INSERT → null.
            return null;
        }

        /** Did markProjectStatus() write this status for the project? */
        public function markedStatus(string $status): bool
        {
            foreach ($this->seen as $q) {
                if (strpos($q['sql'], 'SET status = ?, changes_applied') !== false
                    && ($q['params'][0] ?? null) === $status) {
                    return true;
                }
            }
            return false;
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

    $MERGE = [[
        'id' => 'merge-ch', 'type' => 'merge', 'new_site' => "Children's Hospital",
        'primary_old_site' => 'SCI-LPCH', 'old_sites' => ['SCI-LPCH'],
    ]];

    $passed = 0; $failed = 0;
    function assert_equal($label, $actual, $expected) {
        global $passed, $failed;
        if ($actual === $expected) { $passed++; echo "  PASS  $label\n"; }
        else { $failed++; echo "  FAIL  $label\n        expected: " . var_export($expected, true)
                        . "\n        actual:   " . var_export($actual, true) . "\n"; }
    }
    function assert_true($label, $cond) { assert_equal($label, (bool)$cond, true); }

    // ════════════════════════════════════════════════════════════════════════
    // Test 1 — happy path: one merge → COMPLETED, 1 record moved, logs emitted
    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 1: processProject merge happy path → COMPLETED + per-record log\n";
    \REDCap::reset();
    $mock1 = new MockModule();
    $out1  = makeSM($mock1)->processProject(248, $MERGE, 7);

    assert_equal('status COMPLETED',           $out1['status'],          'completed');
    assert_equal('1 record migrated',          $out1['recordsMigrated'], 1);
    assert_equal('marked COMPLETED in status', $mock1->markedStatus('completed'), true);
    assert_true('human_changes has the merge', !empty($out1['human_changes']));
    assert_equal('merge headline',             $out1['human_changes'][0]['headline'], "Merge → Children's Hospital");
    assert_equal('merge moved 1 record',       $out1['human_changes'][0]['records'], 1);
    // logToREDCap summary (record=null) + one per-record entry (record=77)
    $perRecord = array_filter(\REDCap::$events, fn($e) => $e['record'] === '77');
    assert_equal('one per-record logEvent (record 77)', count($perRecord), 1);

    // ════════════════════════════════════════════════════════════════════════
    // Test 2 — post-commit logging throws → STILL COMPLETED (isolation guard)
    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 2: post-commit logEvent throws → project still COMPLETED (not FAILED)\n";
    \REDCap::reset();
    \REDCap::$throwOnLog = true;
    $mock2 = new MockModule();
    $out2  = makeSM($mock2)->processProject(248, $MERGE, 7);

    assert_equal('status COMPLETED despite log failure', $out2['status'],          'completed');
    assert_equal('1 record still reported migrated',     $out2['recordsMigrated'], 1);
    assert_equal('NOT marked FAILED',                    $mock2->markedStatus('failed'),    false);
    assert_equal('marked COMPLETED',                     $mock2->markedStatus('completed'), true);
    assert_true('logging failure recorded via emError',  !empty($mock2->errors));

    // ════════════════════════════════════════════════════════════════════════
    // Test 3 — unapproved OnCore linkage → SKIPPED, never mutated
    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 3: unapproved OnCore linkage → SKIPPED, no transaction\n";
    \REDCap::reset();
    $mock3 = new MockModule();
    $mock3->linkageApproved = false;
    $out3 = makeSM($mock3)->processProject(248, $MERGE, 7);
    assert_equal('status SKIPPED',            $out3['status'], 'skipped');
    assert_equal('0 records migrated',        $out3['recordsMigrated'], 0);
    assert_equal('marked SKIPPED in status',  $mock3->markedStatus('skipped'), true);
    $startedTxn = (bool)array_filter($mock3->seen, fn($q) => stripos($q['sql'], 'START TRANSACTION') !== false);
    assert_equal('no transaction opened',     $startedTxn, false);

    echo "\nResults: $passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}
