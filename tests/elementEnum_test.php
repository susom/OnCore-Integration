<?php
/**
 * element_enum parse / serialize round-trip guard.
 *
 * REDCap delimits multiple-choice options with the literal two-character sequence
 * "\n" (backslash + n), NOT a pipe and NOT a real newline. A prior bug split/joined
 * on "|", so parseElementEnum() collapsed a real field into a single bogus choice
 * {1 => "<entire string>"} — which made allocateNewCode() return 2 (max(1)+1) and
 * hid every existing code from findCodeForLabel()/record migration.
 *
 * These fixtures are byte-exact samples of what REDCap stores (verified against the
 * live DB for project 248 and the untouched suo_race field). The serialize/round-trip
 * assertions are the data-corruption guard: they fail loudly if a "|" is ever written
 * back to element_enum across live projects.
 *
 * Run with: php tests/elementEnum_test.php
 */

namespace Stanford\OnCoreIntegration;

trait emLoggerTrait { function emLog(){} function emError(){} function emDebug(){} }

require_once __DIR__ . '/../classes/SiteMigration.php';

class OnCoreIntegration
{
    const REDCAP_ONCORE_FIELDS_MAPPING_NAME   = 'redcap-oncore-fields-mapping';
    const ONCORE_STUDY_SITE                   = 'Study Site';
    const REDCAP_ENTITY_ONCORE_SITE_MIGRATION = 'redcap_entity_oncore_site_migration';
}

function sm(): SiteMigration
{
    // parse/serialize/findCodeForLabel/allocateNewCode never touch $module.
    return (new \ReflectionClass(SiteMigration::class))->newInstanceWithoutConstructor();
}

$passed = 0; $failed = 0;
function assert_equal($label, $actual, $expected) {
    global $passed, $failed;
    if ($actual === $expected) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n        expected: " . var_export($expected, true)
                    . "\n        actual:   " . var_export($actual, true) . "\n"; }
}
function assert_true($label, $cond)  { assert_equal($label, (bool)$cond, true); }
function assert_false($label, $cond) { assert_equal($label, (bool)$cond, false); }

// Byte-exact REDCap samples. In a double-quoted PHP string "\\n" is backslash+n.
// (1) OnCore-template style — " \n " WITH surrounding spaces, labels contain commas:
$REAL_248 = "1, SHC Main Hosp, Pasteur, Welch & campus/nearby clinics \\n 2, SHC Redwood City \\n "
          . "8, LPCH Main Hosp, Welch Rd & campus/nearby clinics \\n 13, Psychiatry: Page Mill, Porter Dr, other \\n "
          . "25, Main Hospital \\n 26, Children's Hospital";
// (2) Online-Designer style — "\n" with NO spaces (exact suo_race bytes):
$NATIVE   = "1, White\\n2, Black or African American\\n98, Unknown";

$SM = sm();

// ════════════════════════════════════════════════════════════════════════════
echo "\nTest 1: parse REDCap \" \\n \"-delimited enum (spaces + commas in labels)\n";
$codes = $SM->parseElementEnum($REAL_248);
assert_equal('6 codes parsed',            count($codes), 6);
assert_equal('code 8 label (comma kept)', $codes['8'] ?? null, 'LPCH Main Hosp, Welch Rd & campus/nearby clinics');
assert_equal('code 13 (multi-comma)',     $codes['13'] ?? null, 'Psychiatry: Page Mill, Porter Dr, other');
assert_equal('code 25',                   $codes['25'] ?? null, 'Main Hospital');
assert_equal('code 26',                   $codes['26'] ?? null, "Children's Hospital");

// ════════════════════════════════════════════════════════════════════════════
echo "\nTest 2: parse native \"\\n\"-delimited enum (no surrounding spaces)\n";
$race = $SM->parseElementEnum($NATIVE);
assert_equal('3 codes parsed',  count($race), 3);
assert_equal('code 2 label',    $race['2'] ?? null, 'Black or African American');
assert_equal('code 98 label',   $race['98'] ?? null, 'Unknown');

// ════════════════════════════════════════════════════════════════════════════
echo "\nTest 3: serialize produces \" \\n \" delimiters and NEVER a pipe\n";
$out = $SM->serializeElementEnum(['1' => 'White', '2' => 'Black or African American']);
assert_true ('contains \" \\n \" delimiter', strpos($out, " \\n ") !== false);
assert_false('contains NO pipe',             strpos($out, '|') !== false);
assert_equal('exact serialized form', $out, "1, White \\n 2, Black or African American");

// ════════════════════════════════════════════════════════════════════════════
echo "\nTest 4: round-trip parse → serialize → re-parse is stable, no pipe\n";
$ser = $SM->serializeElementEnum($codes);
assert_false('round-trip output has no pipe', strpos($ser, '|') !== false);
assert_equal('re-parse equals first parse',   $SM->parseElementEnum($ser), $codes);

// ════════════════════════════════════════════════════════════════════════════
echo "\nTest 5: CORRUPTION GUARD — allocate a new code then serialize (the path that injected pipes)\n";
$codes2 = $codes;
$newCode = (string)$SM->allocateNewCode($codes2);   // max(26)+1 = 27  (NOT 2 — that was the bug)
assert_equal('allocateNewCode = 27 (not 2)', $newCode, '27');
$codes2[$newCode] = 'Brand New Site';
$serialized = $SM->serializeElementEnum($codes2);
assert_false('serialized field has NO pipe',          strpos($serialized, '|') !== false);
assert_true ('serialized field uses \" \\n \"',        strpos($serialized, " \\n ") !== false);
assert_true ('new option present',                    strpos($serialized, '27, Brand New Site') !== false);
assert_equal('REDCap re-parses to 7 codes',           count($SM->parseElementEnum($serialized)), 7);

// ════════════════════════════════════════════════════════════════════════════
echo "\nTest 6: \"already defined\" reuse — findCodeForLabel finds existing sites\n";
assert_equal('Main Hospital already at code 25',     $SM->findCodeForLabel($codes, 'Main Hospital'), '25');
assert_equal("Children's Hospital already at code 26", $SM->findCodeForLabel($codes, "Children's Hospital"), '26');
assert_equal('unknown site → null (would allocate)', $SM->findCodeForLabel($codes, 'Nonexistent Site'), null);

echo "\nResults: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
