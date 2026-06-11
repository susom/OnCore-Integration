<?php
/**
 * Tests for the study-site cleanup routine (planStudySiteCleanup / applyStudySiteCleanup):
 * duplicate element_enum codes are consolidated, records repointed, value_mapping
 * pollution repaired + de-duplicated. Covers the data-safety guarantees:
 *   - canonical choice keeps the most-referenced code; removing a referenced code gates on ack
 *   - backward-compat vmap entries (oc with no current label) survive unchanged
 *   - second apply is a clean no-op (idempotent)
 *   - post-commit logging failure does not undo a committed cleanup
 *
 * Run with: php tests/cleanup_test.php
 */

namespace {
    class REDCap {
        public static $events = [];
        public static bool $throwOnLog = false;
        public static function reset(): void { self::$events = []; self::$throwOnLog = false; }
        public static function logEvent($d, $c = "", $sql = "", $rec = null, $ev = null, $pid = null) {
            if (self::$throwOnLog) throw new \RuntimeException('log boom');
            self::$events[] = ['desc' => $d, 'record' => $rec, 'pid' => $pid];
        }
    }
}

namespace Stanford\OnCoreIntegration {

    trait emLoggerTrait {
        public array $errors = [];
        function emLog(){} function emError($m=''){ $this->errors[]=$m; } function emDebug(){}
    }
    require_once __DIR__ . '/../classes/SiteMigration.php';

    class OnCoreIntegration {
        const REDCAP_ONCORE_FIELDS_MAPPING_NAME             = 'redcap-oncore-fields-mapping';
        const ONCORE_STUDY_SITE                             = 'studySites';
        const REDCAP_ENTITY_ONCORE_SITE_MIGRATION           = 'x';
        const REDCAP_ENTITY_ONCORE_SITE_MIGRATION_LOG       = 'redcap_entity_oncore_site_migration_log';
        const REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS = 'redcap_entity_oncore_migration_project_status';
    }

    class FakeResult {
        private array $rows; private int $i = 0;
        function __construct(array $rows){ $this->rows = $rows; }
        function fetch_assoc(): ?array { return $this->i < count($this->rows) ? $this->rows[$this->i++] : null; }
    }

    class MockModule {
        use emLoggerTrait;
        public string $enum = '';
        public string $fmJson = '';
        public ?string $savedFm = null;             // last setProjectSetting value
        public array $recordRowsByCode = [];        // code => [ {record,event_id,instance}, ... ]
        public array $refsByCode = [];              // code => branching-logic ref count
        public array $enumWrites = [];              // element_enum UPDATE payloads

        function getDataTable($pid){ return 'redcap_data'; }
        function getProjectSetting($k, $pid = null){
            return $k === 'redcap-oncore-fields-mapping' ? ($this->savedFm ?? $this->fmJson) : null;
        }
        function setProjectSetting($k, $v, $pid = null){
            if ($k === 'redcap-oncore-fields-mapping') $this->savedFm = $v;
        }
        function query($sql, $p){
            // 1) code-reference scan: "... <col> REGEXP ?" — code is inside the pattern param.
            if (strpos($sql, 'REGEXP') !== false) {
                $pattern = end($p);
                $code = preg_match('/\(([^)]+)\)/', (string)$pattern, $m) ? $m[1] : '';
                $c = (strpos($sql, 'branching_logic') !== false) ? (int)($this->refsByCode[$code] ?? 0) : 0;
                return new FakeResult([['c' => (string)$c]]);
            }
            // 2) capture rows about to be repointed
            if (strpos($sql, 'SELECT record, event_id, instance') !== false) {
                return new FakeResult($this->recordRowsByCode[(string)($p[2] ?? '')] ?? []);
            }
            // 3) record COUNT (countRecordsWithSiteCode / updateRecordValues)
            if (strpos($sql, 'COUNT(*)') !== false) {
                $n = count($this->recordRowsByCode[(string)($p[2] ?? '')] ?? []);
                return new FakeResult([['c' => (string)$n]]);
            }
            // 4) loadElementEnum
            if (strpos($sql, 'SELECT element_enum FROM') !== false) {
                return new FakeResult([['element_enum' => $this->enum]]);
            }
            // 5) element_enum write
            if (strpos($sql, 'UPDATE redcap_metadata SET element_enum') !== false) {
                $this->enumWrites[] = $p[0];
            }
            return null; // START TRANSACTION / COMMIT / log INSERT / UPDATE redcap_data
        }
    }

    function makeSM(MockModule $m): SiteMigration {
        $rc = new \ReflectionClass(SiteMigration::class);
        $sm = $rc->newInstanceWithoutConstructor();
        $p  = $rc->getProperty('module'); $p->setAccessible(true); $p->setValue($sm, $m);
        return $sm;
    }

    // Representative polluted project (subset of the real pid-248 mess).
    function pollutedEnum(): string {
        return implode(" \\n ", [
            '2, SHC Redwood City', '8, LPCH Main Hosp, Welch Rd & campus/nearby clinics',
            '20, Emeryville', '23, SCI-LPCH', '24, Tri-Valley (retired, merged into Main Hospital)',
            '25, Main Hospital', '26, Children\'s Hospital', '27, Redwood City',
            '28, Emeryville', "29, Children's Hospital",   // 28/29 = duplicate junk
        ]);
    }
    function pollutedFm(array $vmap): string {
        return json_encode(['pull' => ['studySites' => ['redcap_field' => 'suo_study_site', 'value_mapping' => $vmap]]]);
    }
    function pollutedVmap(): array {
        return [
            ['oc' => 'Main Hospital',        'rc' => '2'],   // wrong (2 = SHC Redwood City)
            ['oc' => "Children's Hospital",  'rc' => '3'],   // wrong
            ['oc' => 'Redwood City',         'rc' => '4'],   // wrong
            ['oc' => 'Redwood City',         'rc' => '2'],   // wrong + dup oc
            ['oc' => 'Emeryville',           'rc' => '5'],   // wrong
            ['oc' => 'Emeryville',           'rc' => '20'],  // correct
            ['oc' => 'LPCH Main Hosp, Welch Rd & campus/nearby clinics', 'rc' => '8'], // correct
            ['oc' => 'SCI-LPCH',             'rc' => '23'],  // correct (retired-but-present)
            ['oc' => 'Tri-Valley',           'rc' => '24'],  // correct (matches retired label)
            ['oc' => 'undefined',            'rc' => '17'],  // junk, unresolved → keep
        ];
    }

    $passed = 0; $failed = 0;
    function assert_equal($l, $a, $e){ global $passed,$failed; if($a===$e){$passed++; echo "  PASS  $l\n";}
        else {$failed++; echo "  FAIL  $l\n        expected: ".var_export($e,true)."\n        actual:   ".var_export($a,true)."\n";} }
    function assert_true($l,$c){ assert_equal($l,(bool)$c,true); }

    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 1: plan detects dup groups, canonical = lowest (no refs), vmap fixes\n";
    $m1 = new MockModule(); $m1->enum = pollutedEnum(); $m1->fmJson = pollutedFm(pollutedVmap());
    $plan = makeSM($m1)->planStudySiteCleanup(248);
    assert_equal('2 duplicate groups',            count($plan['duplicates']), 2);
    assert_equal('codes removed = [28,29]',        $plan['codes_removed'], ['28','29']);
    assert_equal('requires_ack false (junk dups)', $plan['requires_ack'], false);
    $byLabel = [];
    foreach ($plan['duplicates'] as $g) $byLabel[$g['label']] = $g['canonical'];
    assert_equal('Emeryville canonical = 20',          $byLabel['Emeryville'] ?? null, '20');
    assert_equal("Children's Hospital canonical = 26",  $byLabel["Children's Hospital"] ?? null, '26');
    // vmap fixes: Main Hospital 2→25, Children's 3→26, Redwood City 4→27 & 2→27, Emeryville 5→20
    assert_equal('5 vmap fixes', count($plan['vmap_fixes']), 5);
    $fixMap = [];
    foreach ($plan['vmap_fixes'] as $f) $fixMap[$f['oc'].'@'.$f['old_rc']] = $f['new_rc'];
    assert_equal('Main Hospital 2 → 25',  $fixMap['Main Hospital@2'] ?? null, '25');
    assert_equal("Children's 3 → 26",     $fixMap["Children's Hospital@3"] ?? null, '26');
    assert_equal('Emeryville 5 → 20',     $fixMap['Emeryville@5'] ?? null, '20');
    assert_true ('"undefined" reported unresolved',
        (bool)array_filter($plan['vmap_unresolved'], fn($u)=>$u['oc']==='undefined'));

    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 2: apply consolidates, repoints record (logged), fixes vmap, no pipe\n";
    \REDCap::reset();
    $m2 = new MockModule(); $m2->enum = pollutedEnum(); $m2->fmJson = pollutedFm(pollutedVmap());
    $m2->recordRowsByCode = ['28' => [['record'=>'77','event_id'=>'88','instance'=>null]]]; // 1 record at dup 28
    $out = makeSM($m2)->applyStudySiteCleanup(248);
    assert_equal('status completed',     $out['status'], 'completed');
    assert_equal('1 record repointed',   $out['recordsRepointed'], 1);
    assert_equal('2 codes removed',      $out['codesRemoved'], 2);
    assert_true ('element_enum rewritten', count($m2->enumWrites) === 1);
    assert_true ('written enum has NO 28/29 + no pipe',
        $m2->enumWrites[0] && strpos($m2->enumWrites[0], '|') === false
        && strpos($m2->enumWrites[0], '28, Emeryville') === false
        && strpos($m2->enumWrites[0], "29, Children") === false);
    // per-record log attached to record 77
    assert_true ('record 77 logged', (bool)array_filter(\REDCap::$events, fn($e)=>$e['record']==='77'));
    // resulting vmap: deduped, fixed, backward-compat preserved
    $finalVmap = json_decode($m2->savedFm, true)['pull']['studySites']['value_mapping'];
    $vm = [];
    foreach ($finalVmap as $e) $vm[$e['oc']][] = $e['rc'];
    assert_equal('Main Hospital → 25',           $vm['Main Hospital'] ?? null, ['25']);
    assert_equal('Redwood City deduped → [27]',  $vm['Redwood City'] ?? null, ['27']);
    assert_equal('Emeryville deduped → [20]',    $vm['Emeryville'] ?? null, ['20']);
    assert_equal('SCI-LPCH preserved → 23',      $vm['SCI-LPCH'] ?? null, ['23']);   // backward-compat survives
    assert_equal('undefined preserved → 17',     $vm['undefined'] ?? null, ['17']);  // unresolved kept

    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 3: idempotent — applying to the cleaned state is a no-op\n";
    // Feed the cleaned enum + fixed vmap back in.
    $m3 = new MockModule();
    $m3->enum   = $m2->enumWrites[0];
    $m3->fmJson = $m2->savedFm;
    $out3 = makeSM($m3)->applyStudySiteCleanup(248);
    assert_equal('second apply is noop', $out3['status'], 'noop');

    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 4: referenced removed-code gates on acknowledgement\n";
    // Both Emeryville codes referenced; 28 more → canonical 28, remove 20 (which has refs) → ack required.
    $m4 = new MockModule(); $m4->enum = pollutedEnum(); $m4->fmJson = pollutedFm(pollutedVmap());
    $m4->refsByCode = ['20' => 1, '28' => 2];
    $plan4 = makeSM($m4)->planStudySiteCleanup(248);
    assert_equal('canonical = 28 (most referenced)',
        (function($p){foreach($p['duplicates'] as $g) if($g['label']==='Emeryville') return $g['canonical']; return null;})($plan4), '28');
    assert_equal('requires_ack true', $plan4['requires_ack'], true);
    $threw = false;
    try { makeSM($m4)->applyStudySiteCleanup(248, false); } catch (\Throwable $e) { $threw = true; }
    assert_true('apply without ack throws', $threw);
    $m4b = new MockModule(); $m4b->enum = pollutedEnum(); $m4b->fmJson = pollutedFm(pollutedVmap()); $m4b->refsByCode = ['20'=>1,'28'=>2];
    $out4 = makeSM($m4b)->applyStudySiteCleanup(248, true);
    assert_equal('apply WITH ack proceeds', $out4['status'], 'completed');

    // ════════════════════════════════════════════════════════════════════════
    echo "\nTest 5: post-commit logging failure does NOT fail the cleanup\n";
    \REDCap::reset(); \REDCap::$throwOnLog = true;
    $m5 = new MockModule(); $m5->enum = pollutedEnum(); $m5->fmJson = pollutedFm(pollutedVmap());
    $m5->recordRowsByCode = ['28' => [['record'=>'77','event_id'=>'88','instance'=>null]]];
    $out5 = makeSM($m5)->applyStudySiteCleanup(248);
    assert_equal('still completed despite log throw', $out5['status'], 'completed');
    assert_true ('logging failure recorded via emError', !empty($m5->errors));

    echo "\nResults: $passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}
