<?php

namespace Stanford\OnCoreIntegration;

/**
 * Class SiteMigration  —  rev 3 (clean-label exports: per-project new-code allocation
 * for merges + sharded redcap_data* record rewrite for merges & target-bound sunsets).
 *
 * See SITE_MIGRATION_PLAN.md (rev 3) §§3, 5, 7, 9, 16 and prompt.txt for the spec.
 *
 * Per rule-type effect summary:
 *   - rename             → in-place element_enum relabel; no new code; no record write.
 *   - merge              → allocate per-project max+1 code with clean new label;
 *                          suffix each old code's label "(retired, merged into <new>)";
 *                          UPDATE redcap_data* records from each old code → new code
 *                          (each rewritten record is logged via REDCap::logEvent()).
 *   - sunset w/ target   → merge variant; new label suffix carries "(retired YYYY-MM-DD, merged into <new>)".
 *   - sunset w/o target  → label-only suffix "(retired YYYY-MM-DD)"; no code allocation; no record write.
 *   - keep               → no-op.
 *
 * Tables written:
 *   - redcap_metadata (NOT sharded)               — UPDATE element_enum
 *   - redcap_external_modules_settings            — via $module setting APIs
 *   - redcap_data{,2..8} (SHARDED)                — UPDATE value via getDataTable() + allowlist regex
 *   - redcap_entity_oncore_site_migration{,_log,_migration_project_status}
 *
 * Tables NEVER written with raw SQL:
 *   - redcap_log_event*  — only via REDCap::logEvent() (shard-aware API)
 *
 * @package Stanford\OnCoreIntegration
 */
class SiteMigration
{
    use emLoggerTrait;

    /** Rule-set lifecycle */
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';

    /** Rule types */
    public const RULE_RENAME = 'rename';
    public const RULE_MERGE  = 'merge';
    public const RULE_KEEP   = 'keep';
    public const RULE_SUNSET = 'sunset';

    /** Per-project status values. */
    public const PROJECT_PENDING     = 'pending';
    public const PROJECT_NEEDS_ACK   = 'needs_ack';
    public const PROJECT_IN_PROGRESS = 'in_progress';
    public const PROJECT_COMPLETED   = 'completed';
    public const PROJECT_FAILED      = 'failed';
    public const PROJECT_SKIPPED     = 'skipped';

    /**
     * Max per-record REDCap::logEvent() entries written per project during record-value
     * migration. Beyond this, a single summarizing entry is logged per code pair so a
     * pathological project (thousands of subjects at a merged site) can't blow the
     * request's execution time with thousands of synchronous log INSERTs.
     */
    public const PER_RECORD_LOG_CAP = 500;

    /** Change-type values */
    public const CHANGE_LIBRARY_SETTING        = 'library_setting';
    public const CHANGE_PROJECT_SUBSET         = 'project_subset';
    public const CHANGE_VALUE_MAPPING          = 'value_mapping';
    public const CHANGE_FIELD_LABEL            = 'field_label';
    public const CHANGE_CODE_ALLOCATION        = 'code_allocation';
    public const CHANGE_RECORD_VALUE_MIGRATION = 'record_value_migration';

    /** @var OnCoreIntegration */
    private $module;

    public function __construct(OnCoreIntegration $module)
    {
        $this->module = $module;
    }

    // =======================================================================
    // Rule-set CRUD
    // =======================================================================

    public function getRuleSet(int $id): ?array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION;
        $r = $this->module->query("SELECT * FROM $table WHERE id = ? LIMIT 1", [$id]);
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row) {
            return null;
        }
        $row['rules'] = $this->decodeRules($row['rules'] ?? '');
        return $row;
    }

    public function listRuleSets(): array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION;
        $r = $this->module->query(
            "SELECT id, name, description, library_index, status, created_by, created, updated
             FROM $table ORDER BY id DESC",
            []
        );
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = $row;
        }
        return $out;
    }

    public function saveRuleSet(array $data): int
    {
        $rules = $this->normalizeRules($data['rules'] ?? []);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Rule set name is required.');
        }

        $row = [
            'name'          => $name,
            'description'   => (string)($data['description'] ?? ''),
            'rules'         => json_encode($rules, JSON_THROW_ON_ERROR),
            'library_index' => isset($data['library_index']) ? (int)$data['library_index'] : null,
            'status'        => $data['status'] ?? self::STATUS_DRAFT,
            'created_by'    => $data['created_by'] ?? (defined('USERID') ? USERID : 'system'),
        ];

        $id = (int)($data['id'] ?? 0);
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION;

        if ($id > 0) {
            $this->module->query(
                "UPDATE $table
                 SET name=?, description=?, rules=?, library_index=?, status=?, updated=?
                 WHERE id=?",
                [$row['name'], $row['description'], $row['rules'], $row['library_index'],
                 $row['status'], time(), $id]
            );
            return $id;
        }

        $now = time();
        $this->module->query(
            "INSERT INTO $table (name, description, rules, library_index, status, created_by, created, updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$row['name'], $row['description'], $row['rules'], $row['library_index'],
             $row['status'], $row['created_by'], $now, $now]
        );
        return (int)$this->lastInsertId();
    }

    public function deleteRuleSet(int $id): void
    {
        $existing = $this->getRuleSet($id);
        if (!$existing) {
            return;
        }
        if ($existing['status'] !== self::STATUS_DRAFT) {
            throw new \RuntimeException("Cannot delete rule set #$id — status is '{$existing['status']}'. Only drafts are deletable.");
        }
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION;
        $this->module->query("DELETE FROM $table WHERE id = ?", [$id]);
    }

    // =======================================================================
    // Layer 1 — Library site list (system-scope; runs ONCE inside startMigration)
    // =======================================================================

    public function updateLibrarySettings(array $rules, int $libraryIndex, int $migrationId): array
    {
        $libraries = $this->module->getSubSettings('libraries');
        if (!isset($libraries[$libraryIndex])) {
            throw new \RuntimeException("Library index $libraryIndex not found.");
        }

        $sites = OnCoreIntegration::getSubSettingsValuesAsArray(
            $libraries[$libraryIndex]['library-oncore-study-sites'] ?? [],
            'library-study-site'
        );
        $original = $sites;
        $changes = [];

        foreach ($rules as $rule) {
            if ($rule['type'] === self::RULE_KEEP) {
                continue;
            }
            // Remove every old site listed.
            foreach ($rule['old_sites'] as $oldSite) {
                $idx = array_search($oldSite, $sites, true);
                if ($idx !== false) {
                    array_splice($sites, $idx, 1);
                    $changes[] = [
                        'change_type' => self::CHANGE_LIBRARY_SETTING,
                        'rule_id'     => $rule['id'] ?? null,
                        'old_value'   => $oldSite,
                        'new_value'   => '',
                    ];
                }
            }
            // For rename/merge: add the new site if not already present.
            // For sunset-with-target: also add the merge-target name (covers
            // standalone sunsets where no companion merge already added it).
            // For sunset without target: do NOT add (the sunset retires the name).
            $shouldAddNew = false;
            $newSite = '';
            if ($rule['type'] === self::RULE_RENAME || $rule['type'] === self::RULE_MERGE) {
                $newSite = $rule['new_site'] ?? '';
                $shouldAddNew = $newSite !== '';
            } elseif ($rule['type'] === self::RULE_SUNSET && !empty($rule['merged_into'])) {
                $newSite = $rule['merged_into'];
                $shouldAddNew = true;
            }
            if ($shouldAddNew && !in_array($newSite, $sites, true)) {
                $sites[] = $newSite;
                $changes[] = [
                    'change_type' => self::CHANGE_LIBRARY_SETTING,
                    'rule_id'     => $rule['id'] ?? null,
                    'old_value'   => '',
                    'new_value'   => $newSite,
                ];
            }
        }

        if ($sites !== $original) {
            $this->writeLibrarySites($libraryIndex, $sites);
        }
        foreach ($changes as $c) {
            $this->logToEntity(0, $migrationId, [$c]);
        }
        return $changes;
    }

    // =======================================================================
    // Layer 2 — Project site subset
    // =======================================================================

    public function updateProjectSiteSubset(int $pid, array $rules): array
    {
        $raw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_PROJECT_SITE_STUDIES, $pid);
        $current = $raw ? (json_decode($raw, true) ?: []) : [];
        $original = $current;
        $changes = [];

        foreach ($rules as $rule) {
            if ($rule['type'] === self::RULE_KEEP) {
                continue;
            }
            foreach ($rule['old_sites'] as $oldSite) {
                $idx = array_search($oldSite, $current, true);
                if ($idx !== false) {
                    array_splice($current, $idx, 1);
                    $changes[] = [
                        'rule_id'     => $rule['id'] ?? null,
                        'change_type' => self::CHANGE_PROJECT_SUBSET,
                        'old_value'   => $oldSite,
                        'new_value'   => '',
                    ];
                }
            }
            if ($rule['type'] !== self::RULE_SUNSET) {
                $newSite = $rule['new_site'] ?? '';
                if ($newSite !== '' && !in_array($newSite, $current, true)) {
                    // Only add the new site if at least one old site was present.
                    $touched = false;
                    foreach ($rule['old_sites'] as $oldSite) {
                        if (in_array($oldSite, $original, true)) {
                            $touched = true;
                            break;
                        }
                    }
                    if ($touched) {
                        $current[] = $newSite;
                        $changes[] = [
                            'rule_id'     => $rule['id'] ?? null,
                            'change_type' => self::CHANGE_PROJECT_SUBSET,
                            'old_value'   => '',
                            'new_value'   => $newSite,
                        ];
                    }
                }
            }
        }

        if ($current !== $original) {
            $this->module->setProjectSetting(
                OnCoreIntegration::REDCAP_ONCORE_PROJECT_SITE_STUDIES,
                json_encode(array_values($current)),
                $pid
            );
        }
        return $changes;
    }

    // =======================================================================
    // Layer 3 — Value mapping (rev-3: receives the plan to bind merges to new codes)
    // =======================================================================

    /**
     * Append new {oc → rc} entries to redcap-oncore-fields-mapping[pull|push].studySites.value_mapping.
     *
     *   - rename: append {oc: new_site, rc: <unchanged code>}      (same rc as the renamed site)
     *   - merge / sunset with merged_into: append {oc: new_site, rc: <newly allocated code>}
     *   - sunset without merged_into: NO new entry
     *
     * All old oc→rc entries are retained for backward compat with in-flight OnCore payloads.
     * Both pull AND push branches receive the same updates.
     */
    public function updateValueMapping(int $pid, array $rules, array $plan): array
    {
        $raw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME, $pid);
        $mapping = $raw ? (json_decode($raw, true) ?: []) : [];
        $changes = [];

        // Lookup table: new-site → new-code from the plan's allocations.
        $allocByNewSite = [];
        foreach ($plan['code_allocations'] ?? [] as $alloc) {
            $allocByNewSite[(string)$alloc['new_site']] = (string)$alloc['new_code'];
        }

        $touched = false;
        foreach (['pull', 'push'] as $direction) {
            if (empty($mapping[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping'])) {
                continue;
            }
            $vmap =& $mapping[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping'];
            $fieldName = $mapping[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['redcap_field'] ?? null;

            foreach ($rules as $rule) {
                $type = $rule['type'] ?? '';
                if ($type === self::RULE_KEEP) {
                    continue;
                }

                $newSite = '';
                $newRc   = null;

                if ($type === self::RULE_RENAME) {
                    $newSite = $rule['new_site'] ?? '';
                    if ($newSite === '') {
                        continue;
                    }
                    // Prefer the planner's resolved code; fall back to vmap lookup for
                    // safety if site_code_map is absent (e.g. called from older code paths).
                    $newRc = $plan['site_code_map'][$newSite]
                          ?? $this->getRcCodeForSite($rule['old_sites'][0] ?? '', $vmap);
                } elseif ($type === self::RULE_MERGE
                       || ($type === self::RULE_SUNSET && !empty($rule['merged_into']))) {
                    $newSite = $type === self::RULE_MERGE
                        ? ($rule['new_site'] ?? '')
                        : ($rule['merged_into'] ?? '');
                    if ($newSite === '') {
                        continue;
                    }
                    // Use the plan's authoritative code (allocation or existing element_enum
                    // code found by findCodeForLabel).  Do NOT fall back to getRcCodeForSite
                    // here — value_mapping may carry a stale entry from a prior rename run
                    // that points to the wrong rc, causing the correct entry to be skipped.
                    $newRc = $plan['site_code_map'][$newSite]
                          ?? $allocByNewSite[$newSite]
                          ?? null;
                } else {
                    // Sunset without target — no new mapping entry.
                    continue;
                }

                if ($newRc === null || $newRc === '') {
                    continue;
                }

                // Idempotency.
                $exists = false;
                foreach ($vmap as $entry) {
                    if (($entry['oc'] ?? null) === $newSite
                        && (string)($entry['rc'] ?? '') === (string)$newRc) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) {
                    continue;
                }

                $vmap[] = ['oc' => $newSite, 'rc' => (string)$newRc];
                $touched = true;
                $changes[] = [
                    'rule_id'     => $rule['id'] ?? null,
                    'change_type' => self::CHANGE_VALUE_MAPPING,
                    'old_value'   => '',
                    'new_value'   => $newSite . ' (rc=' . $newRc . ')',
                    'field_name'  => $fieldName,
                    'details'     => json_encode(['direction' => $direction]),
                ];
            }
            unset($vmap);
        }

        if ($touched) {
            $this->module->setProjectSetting(
                OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME,
                json_encode($mapping),
                $pid
            );
        }
        return $changes;
    }

    // =======================================================================
    // Layer 4 — planFieldChanges (rev-3 keystone) + applyFieldChanges
    // =======================================================================

    /**
     * Pure planner. Same (pid, rules, current element_enum state) → same plan.
     * Re-running on a previously-migrated project produces a no-op plan.
     *
     * Return shape:
     *   [
     *     'field_name'        => 'site_dropdown' | null,
     *     'code_allocations'  => [['rule_id', 'new_site', 'new_code', 'reason'], ...],
     *     'label_updates'     => [['rule_id', 'code', 'mode'=>'in_place'|'suffix',
     *                              'old_label', 'new_label'], ...],
     *     'record_migrations' => [['rule_id', 'old_code', 'new_code', 'reason'], ...],
     *   ]
     */
    public function planFieldChanges(int $pid, array $rules): array
    {
        $plan = [
            'field_name'        => null,
            'code_allocations'  => [],
            'label_updates'     => [],
            'record_migrations' => [],
            // new_site => resolved_rc: authoritative source for updateValueMapping so it
            // doesn't fall back to a stale value_mapping entry when no allocation occurred.
            'site_code_map'     => [],
        ];

        $mapping = $this->getStudySiteMapping($pid);
        if (!$mapping) {
            return $plan;
        }
        $fieldName = $mapping['redcap_field'];
        $vmap      = $mapping['value_mapping'] ?? [];
        $plan['field_name'] = $fieldName;

        $enum  = $this->loadElementEnum($pid, $fieldName);
        $codes = $enum['codes']; // working copy; mutated as we plan

        foreach ($rules as $rule) {
            $type = $rule['type'] ?? '';

            if ($type === self::RULE_KEEP) {
                continue;
            }

            // ---- RENAME — in-place relabel -----------------------------------
            if ($type === self::RULE_RENAME) {
                $newSite = (string)($rule['new_site'] ?? '');
                if ($newSite === '') {
                    continue;
                }
                $oldSite = $rule['old_sites'][0] ?? '';
                $oldCode = $this->getRcCodeForSite($oldSite, $vmap);
                if ($oldCode === null) {
                    continue;
                }
                $oldCode = (string)$oldCode;
                if (!array_key_exists($oldCode, $codes)) {
                    continue;
                }
                $existingLabel = (string)$codes[$oldCode];
                // Skip if already renamed — compare against the clean label so a retire
                // suffix accidentally added by a previous run is not treated as a new name.
                if (trim($this->stripRetiredSuffix($existingLabel)) === trim($newSite)) {
                    continue;
                }
                // Don't rename a code that has already been retired by a merge/sunset;
                // doing so would strip the retirement marker.
                if ($this->labelAlreadyRetired($existingLabel)) {
                    continue;
                }
                $plan['label_updates'][] = [
                    'rule_id'   => $rule['id'] ?? null,
                    'code'      => $oldCode,
                    'mode'      => 'in_place',
                    'old_label' => $existingLabel,
                    'new_label' => $newSite,
                ];
                $codes[$oldCode] = $newSite;
                $plan['site_code_map'][$newSite] = $oldCode;
                continue;
            }

            // ---- MERGE or SUNSET with target ---------------------------------
            $isMerge = ($type === self::RULE_MERGE);
            $isSunsetWithTarget = ($type === self::RULE_SUNSET && !empty($rule['merged_into']));

            if ($isMerge || $isSunsetWithTarget) {
                $newSite = $isMerge ? (string)($rule['new_site'] ?? '')
                                    : (string)($rule['merged_into'] ?? '');
                if ($newSite === '') {
                    continue;
                }

                // Find or allocate a code for the new site.
                $existingCode = $this->findCodeForLabel($codes, $newSite);
                if ($existingCode !== null) {
                    $newCode = (string)$existingCode;
                } else {
                    $newCode = (string)$this->allocateNewCode($codes);
                    $codes[$newCode] = $newSite;
                    $plan['code_allocations'][] = [
                        'rule_id'  => $rule['id'] ?? null,
                        'new_site' => $newSite,
                        'new_code' => $newCode,
                        'reason'   => $type,
                    ];
                }
                // Record the authoritative code so updateValueMapping never falls back
                // to a stale value_mapping entry (e.g. one added by a previous rename).
                $plan['site_code_map'][$newSite] = $newCode;

                $suffix = ($type === self::RULE_SUNSET && !empty($rule['retired_on']))
                    ? "(retired {$rule['retired_on']}, merged into $newSite)"
                    : "(retired, merged into $newSite)";

                foreach ($rule['old_sites'] as $oldSite) {
                    $oldCode = $this->getRcCodeForSite($oldSite, $vmap);
                    if ($oldCode === null) {
                        // vmap has no entry for this old site — fall back to matching the
                        // element_enum label so the code is retired even when the project's
                        // value_mapping is missing or incomplete for this site.
                        $oldCode = $this->findCodeForLabel($codes, $oldSite);
                    }
                    if ($oldCode === null) {
                        continue;
                    }
                    $oldCode = (string)$oldCode;
                    if (!array_key_exists($oldCode, $codes)) {
                        continue;
                    }
                    if ((string)$oldCode === (string)$newCode) {
                        // Same code (e.g. label happened to match an existing code). No-op.
                        continue;
                    }
                    $existingLabel = (string)$codes[$oldCode];
                    if ($this->labelAlreadyRetired($existingLabel)) {
                        // Label was already retired by a prior run — no label_update, but
                        // still plan the record migration: the prior run may have retired the
                        // label and then failed or been rolled back before records were moved.
                        $plan['record_migrations'][] = [
                            'rule_id'  => $rule['id'] ?? null,
                            'old_code' => $oldCode,
                            'new_code' => $newCode,
                            'reason'   => $type,
                        ];
                        continue;
                    }
                    $newLabel = rtrim($existingLabel) . ' ' . $suffix;
                    $plan['label_updates'][] = [
                        'rule_id'   => $rule['id'] ?? null,
                        'code'      => $oldCode,
                        'mode'      => 'suffix',
                        'old_label' => $existingLabel,
                        'new_label' => $newLabel,
                    ];
                    $codes[$oldCode] = $newLabel;
                    $plan['record_migrations'][] = [
                        'rule_id'  => $rule['id'] ?? null,
                        'old_code' => $oldCode,
                        'new_code' => $newCode,
                        'reason'   => $type,
                    ];
                }
                continue;
            }

            // ---- SUNSET without target ---------------------------------------
            if ($type === self::RULE_SUNSET) {
                $date = (string)($rule['retired_on'] ?? '');
                $suffix = $date !== '' ? "(retired $date)" : "(retired)";
                foreach ($rule['old_sites'] as $oldSite) {
                    $oldCode = $this->getRcCodeForSite($oldSite, $vmap);
                    if ($oldCode === null) {
                        continue;
                    }
                    $oldCode = (string)$oldCode;
                    if (!array_key_exists($oldCode, $codes)) {
                        continue;
                    }
                    $existingLabel = (string)$codes[$oldCode];
                    if ($this->labelAlreadyRetired($existingLabel)) {
                        continue;
                    }
                    $newLabel = rtrim($existingLabel) . ' ' . $suffix;
                    $plan['label_updates'][] = [
                        'rule_id'   => $rule['id'] ?? null,
                        'code'      => $oldCode,
                        'mode'      => 'suffix',
                        'old_label' => $existingLabel,
                        'new_label' => $newLabel,
                    ];
                    $codes[$oldCode] = $newLabel;
                }
            }
        }

        return $plan;
    }

    /**
     * Turn a plan into per-rule, plain-English change items for the UI. Only rules that
     * actually affect THIS project (produced a label / code / record change) are returned,
     * so the preview reads as "what happens here", not the whole rule set.
     *
     * @param array $rules         the rule set's rules (for type + site names)
     * @param array $plan          output of planFieldChanges()
     * @param array $recordCounts  optional old_code => count; when present, record counts
     *                             are shown (deep preview) or reflect rows actually moved
     * @return array<int,array{rule_id:?string,type:string,kind:string,headline:string,lines:array,records:?int}>
     */
    public function buildHumanChanges(array $rules, array $plan, array $recordCounts = []): array
    {
        // Index plan entries by rule_id.
        $byRule = [];
        $push = function (string $bucket, $entry) use (&$byRule) {
            $rid = (string)($entry['rule_id'] ?? '');
            $byRule[$rid][$bucket][] = $entry;
        };
        foreach (($plan['label_updates']     ?? []) as $e) $push('labels',     $e);
        foreach (($plan['code_allocations']  ?? []) as $e) $push('allocs',     $e);
        foreach (($plan['record_migrations'] ?? []) as $e) $push('migrations', $e);

        $haveDeep = !empty($recordCounts);
        $items = [];

        foreach ($rules as $rule) {
            $rid  = (string)($rule['id'] ?? '');
            $type = (string)($rule['type'] ?? '');
            $entries = $byRule[$rid] ?? null;
            if (!$entries) {
                continue; // rule did nothing in this project — omit
            }
            $labels     = $entries['labels']     ?? [];
            $allocs     = $entries['allocs']      ?? [];
            $migrations = $entries['migrations']  ?? [];

            // clean old-label lookup by code (from suffix label updates)
            $oldLabelByCode = [];
            foreach ($labels as $lu) {
                if (($lu['mode'] ?? '') === 'suffix') {
                    $oldLabelByCode[(string)$lu['code']] = $this->stripRetiredSuffix((string)$lu['old_label']);
                }
            }

            $lines   = [];
            $records = $haveDeep ? 0 : null;

            // ---- RENAME ----
            if ($type === self::RULE_RENAME) {
                foreach ($labels as $lu) {
                    $lines[] = sprintf('“%s” → “%s” (label only — records keep code %s)',
                        $lu['old_label'], $lu['new_label'], $lu['code']);
                }
                $items[] = [
                    'rule_id'  => $rule['id'] ?? null,
                    'type'     => $type,
                    'kind'     => 'rename',
                    'headline' => 'Rename → ' . (string)($rule['new_site'] ?? ''),
                    'lines'    => $lines,
                    'records'  => 0,
                ];
                continue;
            }

            // ---- MERGE / SUNSET-with-target ----
            $isMergeLike = ($type === self::RULE_MERGE)
                || ($type === self::RULE_SUNSET && !empty($rule['merged_into']));
            if ($isMergeLike) {
                $newSite = $type === self::RULE_MERGE
                    ? (string)($rule['new_site'] ?? '')
                    : (string)($rule['merged_into'] ?? '');

                foreach ($allocs as $a) {
                    $lines[] = sprintf('New site “%s” added as code %s', $a['new_site'], $a['new_code']);
                }
                foreach ($migrations as $rm) {
                    $oc   = (string)$rm['old_code'];
                    $name = $oldLabelByCode[$oc] ?? ('code ' . $oc);
                    if ($haveDeep) {
                        $cnt = (int)($recordCounts[$oc] ?? 0);
                        $records += $cnt;
                        $lines[] = sprintf('“%s” retired → %d record(s) moved to “%s”', $name, $cnt, $newSite);
                    } else {
                        $lines[] = sprintf('“%s” retired → records moved to “%s”', $name, $newSite);
                    }
                }
                if (empty($migrations) && !empty($allocs)) {
                    $lines[] = 'No existing records use the retired sites in this project.';
                }
                $items[] = [
                    'rule_id'  => $rule['id'] ?? null,
                    'type'     => $type,
                    'kind'     => $type === self::RULE_MERGE ? 'merge' : 'sunset_target',
                    'headline' => ($type === self::RULE_MERGE ? 'Merge → ' : 'Retire & merge → ') . $newSite,
                    'lines'    => $lines,
                    'records'  => $records,
                ];
                continue;
            }

            // ---- SUNSET without target (label suffix only) ----
            if ($type === self::RULE_SUNSET) {
                foreach ($labels as $lu) {
                    $lines[] = sprintf('“%s” → “%s”', $lu['old_label'], $lu['new_label']);
                }
                $items[] = [
                    'rule_id'  => $rule['id'] ?? null,
                    'type'     => $type,
                    'kind'     => 'sunset',
                    'headline' => 'Retire (label only)',
                    'lines'    => $lines,
                    'records'  => 0,
                ];
            }
        }

        return $items;
    }

    /**
     * Apply planned label updates + code allocations to redcap_metadata.element_enum.
     * One UPDATE per (project, field). Returns change-log entries.
     */
    public function applyFieldChanges(int $pid, array $plan): array
    {
        $changes = [];
        $fieldName = $plan['field_name'] ?? null;
        if (!$fieldName) {
            return $changes;
        }
        if (empty($plan['label_updates']) && empty($plan['code_allocations'])) {
            return $changes;
        }

        $enum = $this->loadElementEnum($pid, $fieldName);
        $codes = $enum['codes'];

        // 1. Label updates.
        foreach ($plan['label_updates'] as $lu) {
            $code = (string)$lu['code'];
            if (!array_key_exists($code, $codes)) {
                continue;
            }
            $current = (string)$codes[$code];
            if ($lu['mode'] === 'suffix' && $this->labelAlreadyRetired($current)) {
                continue;
            }
            if ($current === (string)$lu['new_label']) {
                continue;
            }
            $codes[$code] = (string)$lu['new_label'];
            $changes[] = [
                'rule_id'     => $lu['rule_id'] ?? null,
                'change_type' => self::CHANGE_FIELD_LABEL,
                'field_name'  => $fieldName,
                'old_value'   => $code . ', ' . $current,
                'new_value'   => $code . ', ' . $lu['new_label'],
            ];
        }

        // 2. Code allocations (append).
        foreach ($plan['code_allocations'] as $alloc) {
            $code = (string)$alloc['new_code'];
            $label = (string)$alloc['new_site'];
            if (array_key_exists($code, $codes) && (string)$codes[$code] === $label) {
                continue;
            }
            $codes[$code] = $label;
            $changes[] = [
                'rule_id'     => $alloc['rule_id'] ?? null,
                'change_type' => self::CHANGE_CODE_ALLOCATION,
                'field_name'  => $fieldName,
                'old_value'   => '',
                'new_value'   => $code . ', ' . $label,
                'details'     => json_encode([
                    'new_site' => $label,
                    'reason'   => $alloc['reason'] ?? null,
                ]),
            ];
        }

        // 3. Persist only if changed.
        $newRaw = $this->serializeElementEnum($codes);
        if ($newRaw !== $enum['raw']) {
            $this->module->query(
                'UPDATE redcap_metadata SET element_enum = ? WHERE project_id = ? AND field_name = ?',
                [$newRaw, $pid, $fieldName]
            );
        }
        return $changes;
    }

    // =======================================================================
    // Layer 5 — Sharded record-value rewrites
    // =======================================================================

    /**
     * UPDATE the project's shard, rewriting value=<oldCode> → <newCode> for the mapped field.
     * Returns rows affected (counted via a pre-UPDATE SELECT for portability across query wrappers).
     */
    public function updateRecordValues(int $pid, string $fieldName, string $oldCode, string $newCode): int
    {
        $dataTable = $this->validateDataTableName($this->getProjectDataTable($pid));

        $countR = $this->module->query(
            "SELECT COUNT(*) AS c FROM `$dataTable`
             WHERE project_id = ? AND field_name = ? AND value = ?",
            [$pid, $fieldName, (string)$oldCode]
        );
        $row = $countR ? $countR->fetch_assoc() : null;
        $expected = (int)($row['c'] ?? 0);

        if ($expected === 0) {
            return 0;
        }

        $this->module->query(
            "UPDATE `$dataTable`
                SET value = ?
              WHERE project_id = ?
                AND field_name = ?
                AND value = ?",
            [(string)$newCode, $pid, $fieldName, (string)$oldCode]
        );
        return $expected;
    }

    private function validateDataTableName(string $tableName): string
    {
        if (!preg_match('/^redcap_data[2-8]?$/', $tableName)) {
            throw new \RuntimeException(
                "Refusing to interpolate unexpected data-table name: " . var_export($tableName, true)
            );
        }
        return $tableName;
    }

    public function getProjectDataTable(int $pid): string
    {
        if (method_exists($this->module, 'getDataTable')) {
            $name = $this->module->getDataTable($pid);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        $r = $this->module->query(
            'SELECT data_table FROM redcap_projects WHERE project_id = ? LIMIT 1',
            [$pid]
        );
        $row = $r ? $r->fetch_assoc() : null;
        $name = $row['data_table'] ?? '';
        if ($name === '' || !preg_match('/^redcap_data[2-8]?$/', $name)) {
            return 'redcap_data';
        }
        return $name;
    }

    public function countRecordsWithSiteCode(int $pid, string $fieldName, string $rcCode): int
    {
        $table = $this->validateDataTableName($this->getProjectDataTable($pid));
        $r = $this->module->query(
            "SELECT COUNT(*) AS c FROM `$table` WHERE project_id = ? AND field_name = ? AND value = ?",
            [$pid, $fieldName, (string)$rcCode]
        );
        $row = $r ? $r->fetch_assoc() : null;
        return (int)($row['c'] ?? 0);
    }

    /**
     * Return the exact rows that updateRecordValues() will rewrite — same
     * (project_id, field_name, value) WHERE clause, so the captured set equals
     * the updated set. Captured BEFORE the UPDATE so per-record REDCap::logEvent()
     * can attribute each data change to its record + event after the rewrite.
     *
     * @return array<int,array{record:string,event_id:string,instance:?string}>
     */
    public function getRecordsWithSiteCode(int $pid, string $fieldName, string $rcCode): array
    {
        $table = $this->validateDataTableName($this->getProjectDataTable($pid));
        $r = $this->module->query(
            "SELECT record, event_id, instance FROM `$table`
              WHERE project_id = ? AND field_name = ? AND value = ?",
            [$pid, $fieldName, (string)$rcCode]
        );
        $rows = [];
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $rows[] = [
                    'record'   => (string)($row['record'] ?? ''),
                    'event_id' => (string)($row['event_id'] ?? ''),
                    'instance' => $row['instance'] !== null ? (string)$row['instance'] : null,
                ];
            }
        }
        return $rows;
    }

    // =======================================================================
    // Code-reference scan
    // =======================================================================

    /**
     * Scan logic-bearing tables for references like  [<fieldName>] = '<oldCode>'.
     * One COUNT(*) per source. Returns aggregate counts.
     *
     * Per plan §3.6, covered sources: branching_logic, action_tags (misc),
     * alerts.trigger_logic, surveys_emails.condition_logic,
     * surveys_scheduler.condition_logic, reports.limiter_logic,
     * calc-field formulas (element_enum on calc/calc_legacy/text), and
     * element_validation_min / element_validation_max.
     */
    public function scanCodeReferences(int $pid, string $fieldName, array $oldCodes): array
    {
        $result = [
            'branching_logic'   => 0,
            'action_tags'       => 0,
            'alerts'            => 0,
            'surveys_emails'    => 0,
            'surveys_scheduler' => 0,
            'reports'           => 0,
            'calc_fields'       => 0,
            'validation_min'    => 0,
            'validation_max'    => 0,
            'total'             => 0,
        ];
        if (empty($oldCodes) || $fieldName === '') {
            return $result;
        }

        // MySQL REGEXP (POSIX ERE):  \[field\][[:space:]]*=[[:space:]]*['"]?(c1|c2|...)['"]?
        $fieldRe = $this->mysqlRegexQuote($fieldName);
        $codesRe = implode('|', array_map([$this, 'mysqlRegexQuote'], array_map('strval', $oldCodes)));
        $pattern = "\\[{$fieldRe}\\][[:space:]]*=[[:space:]]*['\"]?({$codesRe})['\"]?";

        $sources = [
            'branching_logic'   => ['table' => 'redcap_metadata',          'col' => 'branching_logic', 'where' => 'project_id = ?',          'params' => [$pid]],
            'action_tags'       => ['table' => 'redcap_metadata',          'col' => 'misc',            'where' => 'project_id = ?',          'params' => [$pid]],
            'alerts'            => ['table' => 'redcap_alerts',            'col' => 'trigger_logic',   'where' => 'project_id = ?',          'params' => [$pid]],
            'surveys_emails'    => ['table' => 'redcap_surveys_emails',    'col' => 'condition_logic', 'where' => 'survey_id IN (SELECT survey_id FROM redcap_surveys WHERE project_id = ?)', 'params' => [$pid]],
            'surveys_scheduler' => ['table' => 'redcap_surveys_scheduler', 'col' => 'condition_logic', 'where' => 'survey_id IN (SELECT survey_id FROM redcap_surveys WHERE project_id = ?)', 'params' => [$pid]],
            'reports'           => ['table' => 'redcap_reports',           'col' => 'limiter_logic',   'where' => 'project_id = ?',          'params' => [$pid]],
            // Calc fields store their formula in element_enum.
            'calc_fields'       => ['table' => 'redcap_metadata',          'col' => 'element_enum',    'where' => "project_id = ? AND element_type IN ('calc','calc_legacy','text')", 'params' => [$pid]],
            'validation_min'    => ['table' => 'redcap_metadata',          'col' => 'element_validation_min', 'where' => 'project_id = ?',   'params' => [$pid]],
            'validation_max'    => ['table' => 'redcap_metadata',          'col' => 'element_validation_max', 'where' => 'project_id = ?',   'params' => [$pid]],
        ];

        foreach ($sources as $key => $src) {
            try {
                $sql = "SELECT COUNT(*) AS c FROM {$src['table']} WHERE {$src['where']} AND {$src['col']} REGEXP ?";
                $params = array_merge($src['params'], [$pattern]);
                $r = $this->module->query($sql, $params);
                $row = $r ? $r->fetch_assoc() : null;
                $result[$key] = (int)($row['c'] ?? 0);
            } catch (\Throwable $e) {
                $this->module->emDebug("scanCodeReferences: source $key unavailable: " . $e->getMessage());
                $result[$key] = 0;
            }
        }

        $result['total'] =
              $result['branching_logic']
            + $result['action_tags']
            + $result['alerts']
            + $result['surveys_emails']
            + $result['surveys_scheduler']
            + $result['reports']
            + $result['calc_fields']
            + $result['validation_min']
            + $result['validation_max'];
        return $result;
    }

    /**
     * Per-project drill-down (up to 200 rows) — actual row-level matches with snippets.
     * Used by the warning-chip modal.
     */
    public function getCodeReferenceDetails(int $ruleSetId, int $pid): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            return [];
        }
        $rules = $ruleSet['rules'];

        $mapping = $this->getStudySiteMapping($pid);
        if (!$mapping) {
            return [];
        }
        $fieldName = $mapping['redcap_field'];
        $plan = $this->planFieldChanges($pid, $rules);
        $oldCodes = array_unique(array_map(
            fn($rm) => (string)$rm['old_code'],
            $plan['record_migrations'] ?? []
        ));
        if (empty($oldCodes)) {
            return [];
        }

        $fieldRe = $this->mysqlRegexQuote($fieldName);
        $codesRe = implode('|', array_map([$this, 'mysqlRegexQuote'], $oldCodes));
        $pattern = "\\[{$fieldRe}\\][[:space:]]*=[[:space:]]*['\"]?({$codesRe})['\"]?";

        $sources = [
            ['source' => 'branching_logic',   'table' => 'redcap_metadata',          'idCol' => 'field_name', 'col' => 'branching_logic', 'where' => 'project_id = ?',         'params' => [$pid]],
            ['source' => 'action_tags',       'table' => 'redcap_metadata',          'idCol' => 'field_name', 'col' => 'misc',            'where' => 'project_id = ?',         'params' => [$pid]],
            ['source' => 'alerts',            'table' => 'redcap_alerts',            'idCol' => 'alert_id',   'col' => 'trigger_logic',   'where' => 'project_id = ?',         'params' => [$pid]],
            ['source' => 'surveys_emails',    'table' => 'redcap_surveys_emails',    'idCol' => 'email_id',   'col' => 'condition_logic', 'where' => 'survey_id IN (SELECT survey_id FROM redcap_surveys WHERE project_id = ?)', 'params' => [$pid]],
            ['source' => 'surveys_scheduler', 'table' => 'redcap_surveys_scheduler', 'idCol' => 'ss_id',      'col' => 'condition_logic', 'where' => 'survey_id IN (SELECT survey_id FROM redcap_surveys WHERE project_id = ?)', 'params' => [$pid]],
            ['source' => 'reports',           'table' => 'redcap_reports',           'idCol' => 'report_id',  'col' => 'limiter_logic',   'where' => 'project_id = ?',         'params' => [$pid]],
            ['source' => 'calc_fields',       'table' => 'redcap_metadata',          'idCol' => 'field_name', 'col' => 'element_enum',    'where' => "project_id = ? AND element_type IN ('calc','calc_legacy','text')", 'params' => [$pid]],
            ['source' => 'validation_min',    'table' => 'redcap_metadata',          'idCol' => 'field_name', 'col' => 'element_validation_min', 'where' => 'project_id = ?',  'params' => [$pid]],
            ['source' => 'validation_max',    'table' => 'redcap_metadata',          'idCol' => 'field_name', 'col' => 'element_validation_max', 'where' => 'project_id = ?',  'params' => [$pid]],
        ];

        $out = [];
        $limit = 200;
        foreach ($sources as $src) {
            if (count($out) >= $limit) break;
            try {
                $remaining = max(1, $limit - count($out));
                $sql = "SELECT {$src['idCol']} AS row_id, {$src['col']} AS snippet
                          FROM {$src['table']}
                         WHERE {$src['where']} AND {$src['col']} REGEXP ?
                         LIMIT $remaining";
                $params = array_merge($src['params'], [$pattern]);
                $r = $this->module->query($sql, $params);
                while ($r && ($row = $r->fetch_assoc())) {
                    $snippet = (string)$row['snippet'];
                    if (strlen($snippet) > 400) {
                        $snippet = substr($snippet, 0, 400) . '…';
                    }
                    $out[] = [
                        'source'  => $src['source'],
                        'table'   => $src['table'],
                        'row_id'  => (string)$row['row_id'],
                        'snippet' => $snippet,
                    ];
                }
            } catch (\Throwable $e) {
                // Table missing — skip.
            }
        }
        return $out;
    }

    public function acknowledgeProjectWarnings(int $ruleSetId, int $pid): void
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $current = $this->getProjectStatus($ruleSetId, $pid);
        if ($current === null) {
            throw new \RuntimeException("No status row for migration #$ruleSetId / project #$pid.");
        }
        if ($current !== self::PROJECT_NEEDS_ACK) {
            return; // idempotent
        }
        $now = time();
        $by  = defined('USERID') ? USERID : 'system';
        $this->module->query(
            "UPDATE $table
                SET status = ?, acknowledged_at = ?, acknowledged_by = ?, updated = ?
              WHERE migration_id = ? AND project_id = ?",
            [self::PROJECT_PENDING, $now, $by, $now, $ruleSetId, $pid]
        );
    }

    // =======================================================================
    // Logging
    // =======================================================================

    public function logToEntity(int $pid, int $migrationId, array $changes): void
    {
        if (empty($changes)) {
            return;
        }
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION_LOG;
        $migratedBy = defined('USERID') ? USERID : 'system';
        $now = time();

        foreach ($changes as $c) {
            $this->module->query(
                "INSERT INTO $table
                   (migration_id, project_id, rule_id, change_type, field_name,
                    old_value, new_value, details, migrated_by, created, updated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $migrationId,
                    $pid,
                    $c['rule_id'] ?? null,
                    $c['change_type'] ?? '',
                    $c['field_name'] ?? null,
                    $c['old_value'] ?? null,
                    $c['new_value'] ?? null,
                    $c['details'] ?? null,
                    $migratedBy,
                    $now,
                    $now,
                ]
            );
        }
    }

    public function logToREDCap(int $pid, array $changes): void
    {
        if (empty($changes) || $pid <= 0) {
            return;
        }
        $lines = ['OnCore Site Migration applied:'];
        foreach ($changes as $c) {
            $ct = $c['change_type'] ?? '?';
            $old = $c['old_value'] ?? '';
            $new = $c['new_value'] ?? '';
            $extra = '';
            if ($ct === self::CHANGE_RECORD_VALUE_MIGRATION && !empty($c['details'])) {
                $d = is_array($c['details']) ? $c['details'] : (json_decode((string)$c['details'], true) ?: []);
                if (!empty($d)) {
                    $extra = sprintf(
                        ' [%d rows in %s]',
                        (int)($d['rows_affected'] ?? 0),
                        (string)($d['data_table'] ?? '?')
                    );
                }
            }
            $lines[] = sprintf(
                '- [%s] %s%s%s%s',
                $ct,
                $old,
                ($new !== '') ? ' → ' : '',
                $new,
                $extra
            );
        }
        \REDCap::logEvent(
            'OnCore Site Migration',
            implode("\n", $lines),
            '',     // sql
            null,   // record
            null,   // event
            $pid
        );
    }

    /**
     * Per-record audit logging for record-value migrations.
     *
     * Raw UPDATEs on redcap_data* bypass REDCap's normal data-change logging, so each
     * rewritten row is recorded here via REDCap::logEvent() attached to its record +
     * event_id. The entry type is "OTHER" (the public API forces it), so it appears in
     * the record's Logging trail. changes_made is formatted "<field> = '<newCode>'" with
     * the old→new context so it reads cleanly.
     *
     * Capped at PER_RECORD_LOG_CAP entries for the whole project; once exhausted, a single
     * summarizing entry per code pair states how many more rows were recoded.
     *
     * Best-effort: called AFTER commit. A logging failure never rolls back the migration.
     *
     * @param array<int,array{old_code:string,new_code:string,reason:?string,old_label:?string,new_site:?string,rows:array}> $migrations
     */
    public function logRecordMigrationsToREDCap(int $pid, string $fieldName, array $migrations, string $title = 'OnCore Site Migration — study site recoded'): void
    {
        if ($pid <= 0 || $fieldName === '' || empty($migrations)) {
            return;
        }
        $logged = 0;
        foreach ($migrations as $m) {
            $oldCode  = (string)($m['old_code'] ?? '');
            $newCode  = (string)($m['new_code'] ?? '');
            $newSite  = (string)($m['new_site'] ?? '');
            $oldLabel = (string)($m['old_label'] ?? '');
            $rows     = is_array($m['rows'] ?? null) ? $m['rows'] : [];

            $context = ($oldLabel !== '' && $newSite !== '')
                ? "$oldLabel → $newSite"
                : "site code $oldCode → $newCode";

            $deferred = 0;
            foreach ($rows as $row) {
                if ($logged >= self::PER_RECORD_LOG_CAP) {
                    $deferred++;
                    continue;
                }
                $record = (string)($row['record'] ?? '');
                if ($record === '') {
                    continue;
                }
                $eventId = (isset($row['event_id']) && $row['event_id'] !== '')
                    ? (int)$row['event_id'] : null;

                \REDCap::logEvent(
                    $title,
                    sprintf("%s = '%s'\n(migrated from '%s'; %s)", $fieldName, $newCode, $oldCode, $context),
                    '',         // sql
                    $record,    // record — attaches the entry to this record's log
                    $eventId,   // numeric event_id
                    $pid
                );
                $logged++;
            }

            if ($deferred > 0) {
                \REDCap::logEvent(
                    $title . ' (summary)',
                    sprintf(
                        "%s: %d additional record(s) recoded from '%s' to '%s' (%s). "
                        . "Per-record logging capped at %d entries for this project.",
                        $fieldName, $deferred, $oldCode, $newCode, $context, self::PER_RECORD_LOG_CAP
                    ),
                    '', null, null, $pid
                );
            }
        }
    }

    // =======================================================================
    // Cron guard primitives
    // =======================================================================

    public static function isMigrationInProgress(\ExternalModules\AbstractExternalModule $module): bool
    {
        return (bool)$module->getSystemSetting(OnCoreIntegration::SITE_MIGRATION_IN_PROGRESS);
    }

    public function disableCrons(): void
    {
        $this->module->setSystemSetting(OnCoreIntegration::SITE_MIGRATION_IN_PROGRESS, true);
    }

    public function enableCrons(): void
    {
        $this->module->setSystemSetting(OnCoreIntegration::SITE_MIGRATION_IN_PROGRESS, false);
    }

    // =======================================================================
    // History & audit retrieval
    // =======================================================================

    public function getMigrationHistory(): array
    {
        $rs = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION;
        $ps = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $r = $this->module->query(
            "SELECT rs.id, rs.name, rs.description, rs.status, rs.library_index,
                    rs.created_by, rs.created, rs.updated,
                    COALESCE(SUM(ps.status = ?), 0) AS completed,
                    COALESCE(SUM(ps.status = ?), 0) AS failed,
                    COALESCE(SUM(ps.status = ?), 0) AS skipped,
                    COALESCE(SUM(ps.status = ?), 0) AS pending,
                    COALESCE(SUM(ps.status = ?), 0) AS needs_ack,
                    COALESCE(SUM(ps.status = ?), 0) AS in_progress,
                    COUNT(ps.id) AS total_projects
             FROM $rs rs
             LEFT JOIN $ps ps ON ps.migration_id = rs.id
             GROUP BY rs.id, rs.name, rs.description, rs.status, rs.library_index,
                      rs.created_by, rs.created, rs.updated
             ORDER BY rs.id DESC",
            [
                self::PROJECT_COMPLETED, self::PROJECT_FAILED, self::PROJECT_SKIPPED,
                self::PROJECT_PENDING, self::PROJECT_NEEDS_ACK, self::PROJECT_IN_PROGRESS,
            ]
        );
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            foreach (['completed','failed','skipped','pending','needs_ack','in_progress','total_projects'] as $k) {
                $row[$k] = (int)$row[$k];
            }
            $out[] = $row;
        }
        return $out;
    }

    public function getMigrationProjectLog(int $ruleSetId, ?int $projectId = null): array
    {
        $logTable = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION_LOG;
        $statusTable = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;

        if ($projectId !== null) {
            $logsR = $this->module->query(
                "SELECT * FROM $logTable
                 WHERE migration_id = ? AND project_id = ?
                 ORDER BY id ASC",
                [$ruleSetId, $projectId]
            );
            $statusR = $this->module->query(
                "SELECT * FROM $statusTable
                 WHERE migration_id = ? AND project_id = ? LIMIT 1",
                [$ruleSetId, $projectId]
            );
            $statusRow = ($statusR && ($rr = $statusR->fetch_assoc())) ? $rr : null;
        } else {
            $logsR = $this->module->query(
                "SELECT * FROM $logTable
                 WHERE migration_id = ?
                 ORDER BY project_id ASC, id ASC",
                [$ruleSetId]
            );
            $statusRow = null;
        }

        $logs = [];
        while ($logsR && ($row = $logsR->fetch_assoc())) {
            // Decode the details JSON blob so the History modal receives a
            // structured object (data_table, rows_affected, reason, ...) instead
            // of a raw JSON string.
            if (isset($row['details']) && $row['details'] !== '' && $row['details'] !== null) {
                $decoded = json_decode((string)$row['details'], true);
                $row['details'] = is_array($decoded) ? $decoded : null;
            } else {
                $row['details'] = null;
            }
            $logs[] = $row;
        }
        return [
            'migration_id' => $ruleSetId,
            'project_id'   => $projectId,
            'status'       => $statusRow,
            'changes'      => $logs,
        ];
    }

    // =======================================================================
    // Execution engine
    // =======================================================================

    public function startMigration(int $ruleSetId): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }
        $rules = $ruleSet['rules'];

        $this->disableCrons();

        if ($ruleSet['status'] !== self::STATUS_ACTIVE && $ruleSet['status'] !== self::STATUS_COMPLETED) {
            $this->module->query(
                'UPDATE ' . OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION
                . ' SET status = ?, updated = ? WHERE id = ?',
                [self::STATUS_ACTIVE, time(), $ruleSetId]
            );
        }

        // System-scope library update runs ONCE per migration session.
        $libIdx = isset($ruleSet['library_index']) ? (int)$ruleSet['library_index'] : 0;
        try {
            $this->updateLibrarySettings($rules, $libIdx, $ruleSetId);
        } catch (\Throwable $e) {
            $this->module->emError('updateLibrarySettings failed: ' . $e->getMessage());
            $this->enableCrons();
            throw $e;
        }

        // Seed per-project status rows for any project that doesn't yet have one.
        $projects = $this->enumerateProjects();
        $existing = $this->loadProjectStatusMap($ruleSetId);
        $statusTable = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $now = time();
        foreach ($projects as $proj) {
            $pid = (int)$proj['project_id'];
            if (isset($existing[$pid])) {
                continue;
            }
            $this->module->query(
                "INSERT INTO $statusTable
                   (migration_id, project_id, status, changes_applied, created, updated)
                 VALUES (?, ?, ?, 0, ?, ?)",
                [$ruleSetId, $pid, self::PROJECT_PENDING, $now, $now]
            );
        }

        // Compute pending & blocked PIDs for the run UI banner.
        $statusRows = $this->loadProjectStatusMap($ruleSetId);
        $blocked = [];
        $pending = [];
        foreach ($statusRows as $pid => $st) {
            if ($st === self::PROJECT_NEEDS_ACK) $blocked[] = (int)$pid;
            elseif ($st === self::PROJECT_PENDING) $pending[] = (int)$pid;
        }

        return array_merge(
            [
                'sessionId' => $ruleSetId,
                'projects'  => $pending,
                'blocked'   => $blocked,
            ],
            $this->getMigrationStatus($ruleSetId)
        );
    }

    public function processNextProject(int $ruleSetId): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }

        $next = $this->claimNextPendingProject($ruleSetId);
        if ($next === null) {
            return [
                'sessionId'         => $ruleSetId,
                'projectId'         => null,
                'projectTitle'      => '',
                'status'            => 'idle',
                'changesApplied'    => 0,
                'newCodesAllocated' => [],
                'recordsMigrated'   => null,
                'error'             => '',
                'progress'          => $this->getMigrationStatus($ruleSetId),
            ];
        }

        $pid = (int)$next['project_id'];
        $title = $this->fetchProjectTitle($pid);
        $outcome = $this->processProject($pid, $ruleSet['rules'], $ruleSetId);

        return [
            'sessionId'         => $ruleSetId,
            'projectId'         => $pid,
            'projectTitle'      => $title,
            'status'            => $outcome['status'],
            'changesApplied'    => $outcome['changes'] ?? 0,
            'newCodesAllocated' => $outcome['newCodesAllocated'] ?? [],
            'recordsMigrated'   => $outcome['recordsMigrated'] ?? null,
            'human_changes'     => $outcome['human_changes'] ?? [],
            'error'             => $outcome['error'] ?? '',
            'progress'          => $this->getMigrationStatus($ruleSetId),
        ];
    }

    public function getMigrationStatus(int $ruleSetId): array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $r = $this->module->query(
            "SELECT status, COUNT(*) AS c FROM $table WHERE migration_id = ? GROUP BY status",
            [$ruleSetId]
        );
        $counts = [
            self::PROJECT_PENDING => 0, self::PROJECT_NEEDS_ACK => 0,
            self::PROJECT_IN_PROGRESS => 0, self::PROJECT_COMPLETED => 0,
            self::PROJECT_FAILED => 0, self::PROJECT_SKIPPED => 0,
        ];
        while ($r && ($row = $r->fetch_assoc())) {
            $counts[$row['status']] = (int)$row['c'];
        }
        $total = array_sum($counts);
        $current = $counts[self::PROJECT_COMPLETED] + $counts[self::PROJECT_FAILED] + $counts[self::PROJECT_SKIPPED];
        return [
            'total'      => $total,
            'current'    => $current,
            'pending'    => $counts[self::PROJECT_PENDING],
            'needs_ack'  => $counts[self::PROJECT_NEEDS_ACK],
            'inProgress' => $counts[self::PROJECT_IN_PROGRESS],
            'completed'  => $counts[self::PROJECT_COMPLETED],
            'failed'     => $counts[self::PROJECT_FAILED],
            'skipped'    => $counts[self::PROJECT_SKIPPED],
        ];
    }

    public function finalizeMigration(int $ruleSetId): array
    {
        $progress = $this->getMigrationStatus($ruleSetId);

        if ($progress['inProgress'] > 0) {
            throw new \RuntimeException(
                'Cannot finalize while projects are still in_progress. '
                . 'Wait for the active project to complete or fail.'
            );
        }

        $this->enableCrons();

        $allDone = ($progress['failed'] === 0
                 && $progress['pending'] === 0
                 && $progress['needs_ack'] === 0);
        if ($allDone) {
            $this->module->query(
                'UPDATE ' . OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION
                . ' SET status = ?, updated = ? WHERE id = ?',
                [self::STATUS_COMPLETED, time(), $ruleSetId]
            );
        }

        return [
            'ok'        => true,
            'finalized' => $allDone,
            'summary'   => $progress,
        ];
    }

    /**
     * Per-project transaction wrapping metadata + sharded data writes.
     *
     * Returns:
     *   ['status' => 'completed'|'failed'|'skipped',
     *    'changes' => int, 'newCodesAllocated' => [...], 'recordsMigrated' => int, 'error' => '']
     */
    public function processProject(int $projectId, array $rules, int $migrationId): array
    {
        $current = $this->getProjectStatus($migrationId, $projectId);
        if ($current === self::PROJECT_COMPLETED || $current === self::PROJECT_SKIPPED) {
            return ['status' => self::PROJECT_SKIPPED, 'changes' => 0,
                    'newCodesAllocated' => [], 'recordsMigrated' => 0, 'error' => ''];
        }
        // NOTE: PROJECT_NEEDS_ACK rows are filtered out by claimNextPendingProject(),
        // so they never reach processProject(). The run loop surfaces them via
        // startMigration()['blocked'] instead.
        if ($current !== self::PROJECT_IN_PROGRESS) {
            $this->markProjectStatus($migrationId, $projectId, self::PROJECT_IN_PROGRESS);
        }

        $allChanges        = [];
        $totalRows         = 0;
        $newCodesAllocated = [];

        try {
            $this->module->query('START TRANSACTION', []);

            $plan = $this->planFieldChanges($projectId, $rules);

            $subsetChanges  = $this->updateProjectSiteSubset($projectId, $rules);
            $mappingChanges = $this->updateValueMapping($projectId, $rules, $plan);
            $fieldChanges   = $this->applyFieldChanges($projectId, $plan);

            $allChanges = array_merge($subsetChanges, $mappingChanges, $fieldChanges);

            // Sharded record-value migrations (merge / target-bound sunset).
            $shardName = null;
            $capturedMigrations = [];   // captured-before-UPDATE rows, for per-record logging after commit
            $appliedCounts      = [];   // old_code => rows actually moved, for the human summary
            if (!empty($plan['record_migrations']) && !empty($plan['field_name'])) {
                $shardName = $this->validateDataTableName($this->getProjectDataTable($projectId));

                // Resolve human-readable names for per-record logging:
                //   old code → clean old site label (from the suffix label_updates)
                //   new code → new site name (from the plan's authoritative site_code_map)
                $oldLabelByCode = [];
                foreach ($plan['label_updates'] as $lu) {
                    if (($lu['mode'] ?? '') === 'suffix') {
                        $oldLabelByCode[(string)$lu['code']] = $this->stripRetiredSuffix((string)$lu['old_label']);
                    }
                }
                $newSiteByCode = [];
                foreach (($plan['site_code_map'] ?? []) as $site => $code) {
                    $newSiteByCode[(string)$code] = (string)$site;
                }

                foreach ($plan['record_migrations'] as $rm) {
                    $oldCode = (string)$rm['old_code'];
                    $newCode = (string)$rm['new_code'];

                    // Capture affected rows BEFORE the rewrite — identical WHERE to
                    // updateRecordValues(), so the captured set equals the updated set.
                    $affected = $this->getRecordsWithSiteCode($projectId, $plan['field_name'], $oldCode);

                    $rows = $this->updateRecordValues($projectId, $plan['field_name'], $oldCode, $newCode);
                    $totalRows += $rows;
                    $appliedCounts[$oldCode] = $rows;

                    if (!empty($affected)) {
                        $capturedMigrations[] = [
                            'old_code'  => $oldCode,
                            'new_code'  => $newCode,
                            'reason'    => $rm['reason'] ?? null,
                            'old_label' => $oldLabelByCode[$oldCode] ?? null,
                            'new_site'  => $newSiteByCode[$newCode] ?? null,
                            'rows'      => $affected,
                        ];
                    }

                    $allChanges[] = [
                        'rule_id'     => $rm['rule_id'] ?? null,
                        'change_type' => self::CHANGE_RECORD_VALUE_MIGRATION,
                        'field_name'  => $plan['field_name'],
                        'old_value'   => $oldCode,
                        'new_value'   => $newCode,
                        'details'     => json_encode([
                            'rows_affected' => $rows,
                            'data_table'    => $shardName,
                            'reason'        => $rm['reason'] ?? null,
                        ]),
                    ];
                }
            }

            $newCodesAllocated = array_map(
                fn($a) => ['code' => (string)$a['new_code'], 'site' => (string)$a['new_site']],
                $plan['code_allocations'] ?? []
            );

            // Persist entity log INSIDE the transaction.
            $this->logToEntity($projectId, $migrationId, $allChanges);

            $this->module->query('COMMIT', []);

            // Best-effort follow-ups — post-commit and FULLY ISOLATED. A logging failure
            // must never reach the outer catch: data is already committed, so flipping the
            // project to FAILED would be a lie AND would lose the per-record audit forever
            // (on re-run the capture SELECT finds nothing — records are already moved).
            try {
                $this->logToREDCap($projectId, $allChanges);
                $this->logRecordMigrationsToREDCap($projectId, (string)($plan['field_name'] ?? ''), $capturedMigrations);
            } catch (\Throwable $logErr) {
                $this->module->emError(
                    "Site migration post-commit logging for project $projectId failed "
                    . "(data already committed; project still COMPLETED): " . $logErr->getMessage()
                );
            }

            $this->markProjectStatus($migrationId, $projectId, self::PROJECT_COMPLETED, count($allChanges));

            return [
                'status'            => self::PROJECT_COMPLETED,
                'changes'           => count($allChanges),
                'newCodesAllocated' => $newCodesAllocated,
                'recordsMigrated'   => $totalRows,
                'human_changes'     => $this->buildHumanChanges($rules, $plan, $appliedCounts),
                'error'             => '',
            ];
        } catch (\Throwable $e) {
            try { $this->module->query('ROLLBACK', []); } catch (\Throwable $_) { /* swallow */ }
            $this->module->emError("Site migration project $projectId failed: " . $e->getMessage());
            $this->markProjectStatus($migrationId, $projectId, self::PROJECT_FAILED, 0, $e->getMessage());
            return [
                'status'            => self::PROJECT_FAILED,
                'changes'           => 0,
                'newCodesAllocated' => [],
                'recordsMigrated'   => 0,
                'error'             => $e->getMessage(),
            ];
        }
    }

    // =======================================================================
    // Execution internals
    // =======================================================================

    private function claimNextPendingProject(int $ruleSetId): ?array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $now = time();

        // Only PROJECT_PENDING is claimable. needs_ack rows are deliberately left alone.
        $sel = $this->module->query(
            "SELECT id, project_id FROM $table
             WHERE migration_id = ? AND status = ?
             ORDER BY project_id ASC LIMIT 1",
            [$ruleSetId, self::PROJECT_PENDING]
        );
        $row = ($sel && ($r = $sel->fetch_assoc())) ? $r : null;
        if (!$row) {
            return null;
        }

        $this->module->query(
            "UPDATE $table SET status = ?, updated = ?
             WHERE id = ? AND status = ?",
            [self::PROJECT_IN_PROGRESS, $now, (int)$row['id'], self::PROJECT_PENDING]
        );
        $confirm = $this->getProjectStatus($ruleSetId, (int)$row['project_id']);
        if ($confirm !== self::PROJECT_IN_PROGRESS) {
            return $this->claimNextPendingProject($ruleSetId);
        }
        return ['id' => (int)$row['id'], 'project_id' => (int)$row['project_id']];
    }

    private function getProjectStatus(int $ruleSetId, int $pid): ?string
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $r = $this->module->query(
            "SELECT status FROM $table WHERE migration_id = ? AND project_id = ? LIMIT 1",
            [$ruleSetId, $pid]
        );
        $row = ($r && ($rr = $r->fetch_assoc())) ? $rr : null;
        return $row['status'] ?? null;
    }

    private function markProjectStatus(int $ruleSetId, int $pid, string $status, int $changesApplied = 0, ?string $errorMessage = null): void
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $now = time();
        $completedAt = ($status === self::PROJECT_COMPLETED || $status === self::PROJECT_FAILED || $status === self::PROJECT_SKIPPED)
            ? $now : null;

        $this->module->query(
            "UPDATE $table
             SET status = ?, changes_applied = ?, completed_at = ?, error_message = ?, updated = ?
             WHERE migration_id = ? AND project_id = ?",
            [$status, $changesApplied, $completedAt, $errorMessage, $now, $ruleSetId, $pid]
        );
    }

    /** project_id => status */
    private function loadProjectStatusMap(int $ruleSetId): array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $r = $this->module->query(
            "SELECT project_id, status FROM $table WHERE migration_id = ?",
            [$ruleSetId]
        );
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[(int)$row['project_id']] = $row['status'];
        }
        return $out;
    }

    private function fetchProjectTitle(int $pid): string
    {
        $r = $this->module->query(
            'SELECT app_title FROM redcap_projects WHERE project_id = ? LIMIT 1',
            [$pid]
        );
        $row = ($r && ($rr = $r->fetch_assoc())) ? $rr : null;
        return (string)($row['app_title'] ?? '');
    }

    // =======================================================================
    // Preview
    // =======================================================================

    /**
     * Dry-run preview. Computes per-project deltas via planFieldChanges (pure),
     * runs the code-reference scan for projects with record_migrations, and
     * persists scan results / sets needs_ack status as appropriate.
     */
    public function previewMigration(int $ruleSetId, bool $deep = false): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }
        $rules = $ruleSet['rules'];

        $statusMap = $this->loadProjectStatusMap($ruleSetId);
        $projects  = $this->enumerateProjects();

        // Ensure each enumerated project has a status row (needed to persist scan + ack state).
        $statusTable = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $now = time();
        foreach ($projects as $proj) {
            $pid = (int)$proj['project_id'];
            if (!isset($statusMap[$pid])) {
                $this->module->query(
                    "INSERT INTO $statusTable
                       (migration_id, project_id, status, changes_applied, created, updated)
                     VALUES (?, ?, ?, 0, ?, ?)",
                    [$ruleSetId, $pid, self::PROJECT_PENDING, $now, $now]
                );
                $statusMap[$pid] = self::PROJECT_PENDING;
            }
        }

        $rows = [];
        foreach ($projects as $proj) {
            $pid   = (int)$proj['project_id'];
            $title = (string)($proj['app_title'] ?? '');
            $existingStatus = $statusMap[$pid] ?? null;

            if ($existingStatus === self::PROJECT_COMPLETED) {
                $rows[] = [
                    'project_id'       => $pid,
                    'project_title'    => $title,
                    'sitesAffected'    => 0,
                    'labelChanges'     => 0,
                    'mappingChanges'   => 0,
                    'newCodes'         => [],
                    'recordsToMigrate' => null,
                    'codeReferences'   => ['total' => 0],
                    'status'           => self::PROJECT_COMPLETED,
                    'note'             => 'Already migrated for this rule set',
                ];
                continue;
            }

            $mapping = $this->getStudySiteMapping($pid);
            if (!$mapping) {
                $this->markProjectStatus($ruleSetId, $pid, self::PROJECT_SKIPPED, 0, 'no site mapping');
                $rows[] = [
                    'project_id'       => $pid,
                    'project_title'    => $title,
                    'sitesAffected'    => 0,
                    'labelChanges'     => 0,
                    'mappingChanges'   => 0,
                    'newCodes'         => [],
                    'recordsToMigrate' => null,
                    'codeReferences'   => ['total' => 0],
                    'status'           => self::PROJECT_SKIPPED,
                    'note'             => 'No studySites mapping configured',
                ];
                continue;
            }

            $plan      = $this->planFieldChanges($pid, $rules);
            $fieldName = $plan['field_name'];

            // Counts.
            $sitesAffectedSet = [];
            foreach ($plan['label_updates']     as $lu) $sitesAffectedSet[$lu['code']]     = true;
            foreach ($plan['record_migrations'] as $rm) $sitesAffectedSet[$rm['old_code']] = true;
            $sitesAffected = count($sitesAffectedSet);

            $labelChanges = count($plan['label_updates']);
            $newCodes     = array_map(
                fn($a) => ['code' => (string)$a['new_code'], 'site' => (string)$a['new_site']],
                $plan['code_allocations']
            );

            // Simulate value-mapping additions WITHOUT writing.
            $mappingChanges = $this->countSimulatedMappingChanges($pid, $rules, $plan);

            // Deep preview: per-shard record counts.
            $recordsToMigrate = null;
            if ($deep && $fieldName) {
                $recordsToMigrate = 0;
                foreach ($plan['record_migrations'] as $rm) {
                    $recordsToMigrate += $this->countRecordsWithSiteCode($pid, $fieldName, (string)$rm['old_code']);
                }
            }

            // Code-reference scan only when record migrations are planned.
            $codeReferences = ['total' => 0];
            $oldCodes = array_unique(array_map(fn($rm) => (string)$rm['old_code'], $plan['record_migrations']));
            if (!empty($oldCodes) && $fieldName) {
                $codeReferences = $this->scanCodeReferences($pid, $fieldName, $oldCodes);
                $this->persistCodeReferences($ruleSetId, $pid, $codeReferences);
            } else {
                $this->persistCodeReferences($ruleSetId, $pid, ['total' => 0]);
            }

            // Status: needs_ack iff scan found references and not already acknowledged.
            $newStatus = $existingStatus;
            if ($codeReferences['total'] > 0) {
                $ackd = $this->getAcknowledgedAt($ruleSetId, $pid);
                $newStatus = $ackd ? self::PROJECT_PENDING : self::PROJECT_NEEDS_ACK;
            } elseif ($existingStatus === self::PROJECT_NEEDS_ACK) {
                $newStatus = self::PROJECT_PENDING;
            } elseif (!$existingStatus) {
                $newStatus = self::PROJECT_PENDING;
            }
            if ($newStatus !== $existingStatus
                && $existingStatus !== self::PROJECT_COMPLETED
                && $existingStatus !== self::PROJECT_IN_PROGRESS
                && $existingStatus !== self::PROJECT_FAILED) {
                $this->markProjectStatus($ruleSetId, $pid, $newStatus, 0);
            }

            $rows[] = [
                'project_id'       => $pid,
                'project_title'    => $title,
                'sitesAffected'    => $sitesAffected,
                'labelChanges'     => $labelChanges,
                'mappingChanges'   => $mappingChanges,
                'newCodes'         => $newCodes,
                'recordsToMigrate' => $recordsToMigrate,
                'codeReferences'   => $codeReferences,
                'status'           => $newStatus,
                'note'             => '',
            ];
        }

        return [
            'rule_set' => [
                'id'     => $ruleSet['id'],
                'name'   => $ruleSet['name'],
                'status' => $ruleSet['status'],
                'rules'  => $rules,
            ],
            'projects' => $rows,
            'totals'   => $this->summarizeTotals($rows),
        ];
    }

    public function exportPreviewCSV(int $ruleSetId): string
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }
        $rules = $ruleSet['rules'];
        $projects = $this->enumerateProjects();

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, [
            'project_id', 'project_title', 'change_type', 'rule_id',
            'code', 'old_code', 'new_code', 'old_label', 'new_label',
            'records_affected_estimate',
        ]);

        foreach ($projects as $proj) {
            $pid = (int)$proj['project_id'];
            $title = (string)($proj['app_title'] ?? '');
            $mapping = $this->getStudySiteMapping($pid);
            if (!$mapping) {
                continue;
            }
            $plan = $this->planFieldChanges($pid, $rules);
            $fieldName = $plan['field_name'] ?? '';

            // Project subset rows.
            foreach ($this->dryRunSubsetRemovals($pid, $rules) as $r) {
                fputcsv($fh, [$pid, $title, self::CHANGE_PROJECT_SUBSET, $r['rule_id'] ?? '',
                              '', '', '', $r['old_value'] ?? '', $r['new_value'] ?? '', '']);
            }
            // Code allocations.
            foreach ($plan['code_allocations'] as $alloc) {
                fputcsv($fh, [$pid, $title, self::CHANGE_CODE_ALLOCATION, $alloc['rule_id'] ?? '',
                              $alloc['new_code'], '', $alloc['new_code'], '', $alloc['new_site'], '']);
            }
            // Label updates.
            foreach ($plan['label_updates'] as $lu) {
                fputcsv($fh, [$pid, $title, self::CHANGE_FIELD_LABEL, $lu['rule_id'] ?? '',
                              $lu['code'], '', '', $lu['old_label'], $lu['new_label'], '']);
            }
            // Record migrations + estimate.
            foreach ($plan['record_migrations'] as $rm) {
                $est = $fieldName !== ''
                    ? $this->countRecordsWithSiteCode($pid, $fieldName, (string)$rm['old_code'])
                    : '';
                fputcsv($fh, [$pid, $title, self::CHANGE_RECORD_VALUE_MIGRATION, $rm['rule_id'] ?? '',
                              '', $rm['old_code'], $rm['new_code'], '', '', $est]);
            }
            // Value mapping additions.
            foreach ($this->dryRunValueMappingAdditions($pid, $rules, $plan) as $vm) {
                fputcsv($fh, [$pid, $title, self::CHANGE_VALUE_MAPPING, $vm['rule_id'] ?? '',
                              '', '', $vm['new_rc'] ?? '', '', $vm['new_oc'] ?? '', '']);
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    private function dryRunSubsetRemovals(int $pid, array $rules): array
    {
        $raw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_PROJECT_SITE_STUDIES, $pid);
        $subset = $raw ? (json_decode($raw, true) ?: []) : [];
        $out = [];
        foreach ($rules as $rule) {
            if (($rule['type'] ?? '') === self::RULE_KEEP) continue;
            foreach ($rule['old_sites'] as $oldSite) {
                if (in_array($oldSite, $subset, true)) {
                    $out[] = ['rule_id' => $rule['id'] ?? null, 'old_value' => $oldSite, 'new_value' => ''];
                }
            }
        }
        return $out;
    }

    private function dryRunValueMappingAdditions(int $pid, array $rules, array $plan): array
    {
        $raw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME, $pid);
        $mapping = $raw ? (json_decode($raw, true) ?: []) : [];
        $vmap = $mapping['pull'][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping']
             ?? $mapping['push'][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping']
             ?? [];

        $allocByNewSite = [];
        foreach ($plan['code_allocations'] ?? [] as $alloc) {
            $allocByNewSite[(string)$alloc['new_site']] = (string)$alloc['new_code'];
        }

        $out = [];
        foreach ($rules as $rule) {
            $type = $rule['type'] ?? '';
            if ($type === self::RULE_KEEP) continue;

            if ($type === self::RULE_RENAME) {
                $newSite = $rule['new_site'] ?? '';
                $rc = $plan['site_code_map'][$newSite]
                   ?? $this->getRcCodeForSite($rule['old_sites'][0] ?? '', $vmap);
                if ($newSite === '' || $rc === null) continue;
                if (!$this->mappingHas($vmap, $newSite, (string)$rc)) {
                    $out[] = ['rule_id' => $rule['id'] ?? null, 'new_oc' => $newSite, 'new_rc' => (string)$rc];
                }
                continue;
            }
            if ($type === self::RULE_MERGE
             || ($type === self::RULE_SUNSET && !empty($rule['merged_into']))) {
                $newSite = $type === self::RULE_MERGE
                    ? ($rule['new_site'] ?? '')
                    : ($rule['merged_into'] ?? '');
                // Use the planner's resolved code; do NOT fall back to getRcCodeForSite
                // which may return a stale entry from a prior rename.
                $rc = $plan['site_code_map'][$newSite] ?? $allocByNewSite[$newSite] ?? null;
                if ($newSite === '' || $rc === null) continue;
                if (!$this->mappingHas($vmap, $newSite, (string)$rc)) {
                    $out[] = ['rule_id' => $rule['id'] ?? null, 'new_oc' => $newSite, 'new_rc' => (string)$rc];
                }
            }
        }
        return $out;
    }

    private function mappingHas(array $vmap, string $oc, string $rc): bool
    {
        foreach ($vmap as $e) {
            if (($e['oc'] ?? null) === $oc && (string)($e['rc'] ?? '') === $rc) return true;
        }
        return false;
    }

    private function countSimulatedMappingChanges(int $pid, array $rules, array $plan): int
    {
        return count($this->dryRunValueMappingAdditions($pid, $rules, $plan));
    }

    private function persistCodeReferences(int $ruleSetId, int $pid, array $scan): void
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $this->module->query(
            "UPDATE $table SET code_references_json = ?, updated = ?
             WHERE migration_id = ? AND project_id = ?",
            [json_encode($scan), time(), $ruleSetId, $pid]
        );
    }

    private function getAcknowledgedAt(int $ruleSetId, int $pid): ?int
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $r = $this->module->query(
            "SELECT acknowledged_at FROM $table WHERE migration_id = ? AND project_id = ? LIMIT 1",
            [$ruleSetId, $pid]
        );
        $row = ($r && ($rr = $r->fetch_assoc())) ? $rr : null;
        return isset($row['acknowledged_at']) && $row['acknowledged_at'] !== null
            ? (int)$row['acknowledged_at']
            : null;
    }

    private function enumerateProjects(): array
    {
        $sql = "SELECT DISTINCT p.redcap_project_id AS project_id, rp.app_title
                FROM " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " p
                INNER JOIN redcap_projects rp ON rp.project_id = p.redcap_project_id
                WHERE p.status = ?
                  AND rp.date_deleted IS NULL
                ORDER BY p.redcap_project_id ASC";
        $r = $this->module->query($sql, [OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES]);
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = $row;
        }
        return $out;
    }

    private function summarizeTotals(array $rows): array
    {
        $t = [
            'projects' => count($rows),
            'pending' => 0, 'needs_ack' => 0, 'in_progress' => 0,
            'completed' => 0, 'failed' => 0, 'skipped' => 0,
            'sitesAffected' => 0, 'labelChanges' => 0, 'mappingChanges' => 0,
            'newCodes' => 0, 'recordsToMigrate' => 0, 'codeReferences' => 0,
        ];
        foreach ($rows as $r) {
            $st = $r['status'] ?? '';
            if (isset($t[$st])) $t[$st]++;
            $t['sitesAffected']    += (int)($r['sitesAffected'] ?? 0);
            $t['labelChanges']     += (int)($r['labelChanges'] ?? 0);
            $t['mappingChanges']   += (int)($r['mappingChanges'] ?? 0);
            $t['newCodes']         += count($r['newCodes'] ?? []);
            $t['recordsToMigrate'] += (int)($r['recordsToMigrate'] ?? 0);
            $t['codeReferences']   += (int)(($r['codeReferences']['total'] ?? 0));
        }
        // JS-friendly alias — site_migration.js reads totals.inProgress.
        $t['inProgress'] = $t['in_progress'];
        return $t;
    }

    // =======================================================================
    // element_enum parsing / allocation helpers
    // =======================================================================

    /**
     * Parse REDCap's element_enum ("1, Label A \n 2, Label B") into
     * ['1' => 'Label A', '2' => 'Label B']. Keys are kept as strings (codes may be
     * alphanumeric).
     *
     * REDCap delimits choices with the literal two-character sequence "\n"
     * (backslash + n), optionally padded with spaces — NOT a pipe and NOT a real
     * newline (see redcap core DataExport::…  implode(" \n ", …)). Within each
     * choice we split on the FIRST ", " only, so commas inside labels like
     * "Psychiatry: Page Mill, Porter Dr, other" are preserved.
     */
    public function parseElementEnum(string $raw): array
    {
        $codes = [];
        if ($raw === '') return $codes;
        // Split on a literal backslash-n delimiter with optional surrounding whitespace.
        $entries = preg_split('/\s*\\\\n\s*/', $raw);
        foreach ($entries as $entry) {
            if (!preg_match('/^\s*([^,]+?)\s*,\s*(.*)$/s', $entry, $m)) {
                continue;
            }
            $code = trim($m[1]);
            $label = trim($m[2]);
            if ($code === '') continue;
            $codes[$code] = $label;
        }
        return $codes;
    }

    /**
     * Serialize back to REDCap's canonical element_enum format: "code, label"
     * choices joined by " \n " (backslash-n with spaces), matching REDCap core's
     * own writer (DataExport: implode(" \n ", $choices)). MUST NOT use a pipe —
     * a pipe-delimited string parses as a single choice in REDCap.
     */
    public function serializeElementEnum(array $codes): string
    {
        $parts = [];
        foreach ($codes as $code => $label) {
            $parts[] = $code . ', ' . $label;
        }
        return implode(" \\n ", $parts);
    }

    public function loadElementEnum(int $pid, string $fieldName): array
    {
        $r = $this->module->query(
            'SELECT element_enum FROM redcap_metadata WHERE project_id = ? AND field_name = ? LIMIT 1',
            [$pid, $fieldName]
        );
        $row = ($r && ($rr = $r->fetch_assoc())) ? $rr : null;
        $raw = (string)($row['element_enum'] ?? '');
        return [
            'raw'   => $raw,
            'codes' => $this->parseElementEnum($raw),
        ];
    }

    /**
     * max(numeric codes) + 1 if ALL codes are numeric; otherwise the smallest unused
     * positive integer.
     */
    public function allocateNewCode(array $codes): int
    {
        if (empty($codes)) return 1;
        $allNumeric = true;
        $max = 0;
        foreach ($codes as $code => $_) {
            if (!preg_match('/^\d+$/', (string)$code)) {
                $allNumeric = false;
                break;
            }
            $max = max($max, (int)$code);
        }
        if ($allNumeric) {
            return $max + 1;
        }
        $used = [];
        foreach ($codes as $code => $_) {
            if (preg_match('/^\d+$/', (string)$code)) {
                $used[(int)$code] = true;
            }
        }
        $i = 1;
        while (isset($used[$i])) $i++;
        return $i;
    }

    /**
     * Find the code whose CLEAN label (with any trailing "(retired …)" stripped)
     * matches $label. Returns the code (string) or null.
     */
    public function findCodeForLabel(array $codes, string $label): ?string
    {
        $needle = trim($label);
        if ($needle === '') return null;
        foreach ($codes as $code => $existing) {
            $clean = $this->stripRetiredSuffix((string)$existing);
            if (trim($clean) === $needle) {
                return (string)$code;
            }
        }
        return null;
    }

    private function stripRetiredSuffix(string $label): string
    {
        return preg_replace('/\s*\(retired\b[^)]*\)\s*$/u', '', $label) ?? $label;
    }

    private function labelAlreadyRetired(string $label): bool
    {
        return (bool)preg_match('/\(retired\b/u', $label);
    }

    // =======================================================================
    // Misc helpers
    // =======================================================================

    private function getStudySiteMapping(int $pid): ?array
    {
        $raw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME, $pid);
        $mapping = $raw ? (json_decode($raw, true) ?: []) : [];
        foreach (['pull', 'push'] as $direction) {
            $entry = $mapping[$direction][OnCoreIntegration::ONCORE_STUDY_SITE] ?? null;
            if (is_array($entry) && !empty($entry['redcap_field'])) {
                return [
                    'redcap_field'  => $entry['redcap_field'],
                    'value_mapping' => $entry['value_mapping'] ?? [],
                ];
            }
        }
        return null;
    }

    private function getRcCodeForSite(string $siteName, array $valueMapping)
    {
        foreach ($valueMapping as $entry) {
            if (($entry['oc'] ?? null) === $siteName) {
                return $entry['rc'] ?? null;
            }
        }
        return null;
    }

    /**
     * Escape a literal string for safe use inside a MySQL POSIX REGEXP pattern.
     * MySQL REGEXP uses POSIX ERE — backslash-escape ERE metachars.
     */
    private function mysqlRegexQuote(string $s): string
    {
        // ERE metachars: . * + ? ( ) [ ] { } | ^ $ \
        return preg_replace('/([\\\\.\\*\\+\\?\\(\\)\\[\\]\\{\\}\\|\\^\\$])/', '\\\\$1', $s);
    }

    private function normalizeRules(array $rules): array
    {
        $out = [];
        $validTypes = [self::RULE_RENAME, self::RULE_MERGE, self::RULE_KEEP, self::RULE_SUNSET];
        foreach ($rules as $i => $r) {
            $type = $r['type'] ?? '';
            if (!in_array($type, $validTypes, true)) {
                throw new \InvalidArgumentException("Rule #$i: invalid type '$type'.");
            }
            $oldSites = array_values(array_filter(array_map('strval', $r['old_sites'] ?? []), 'strlen'));
            if (empty($oldSites)) {
                throw new \InvalidArgumentException("Rule #$i: old_sites is empty.");
            }
            $newSite = isset($r['new_site']) ? trim((string)$r['new_site']) : '';
            if (($type === self::RULE_RENAME || $type === self::RULE_MERGE) && $newSite === '') {
                throw new \InvalidArgumentException("Rule #$i ($type): new_site is required.");
            }
            if ($type === self::RULE_RENAME && count($oldSites) !== 1) {
                throw new \InvalidArgumentException("Rule #$i (rename): exactly one old_site required.");
            }
            $entry = [
                'id'               => $r['id'] ?? sprintf('rule-%d-%s', $i + 1, bin2hex(random_bytes(3))),
                'type'             => $type,
                'old_sites'        => $oldSites,
                'new_site'         => $newSite !== '' ? $newSite : null,
                'primary_old_site' => ($type === self::RULE_MERGE && !empty($r['primary_old_site']))
                                        ? (string)$r['primary_old_site']
                                        : null,
                'retired_on'       => ($type === self::RULE_SUNSET && !empty($r['retired_on']))
                                        ? (string)$r['retired_on']
                                        : null,
            ];
            // Sunset may bind to a merge target.
            if ($type === self::RULE_SUNSET && !empty($r['merged_into'])) {
                $entry['merged_into'] = (string)$r['merged_into'];
            } elseif ($type === self::RULE_SUNSET && $newSite !== '') {
                // Back-compat: sunset rule that carries new_site is treated as merged_into.
                $entry['merged_into'] = $newSite;
            }
            $out[] = $entry;
        }
        return $out;
    }

    private function decodeRules(string $json): array
    {
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeLibrarySites(int $libraryIndex, array $sites): void
    {
        $key = 'library-study-site';
        $existing = $this->module->getSystemSetting($key);
        $existing = is_array($existing) ? $existing : (json_decode((string)$existing, true) ?: []);
        if (!is_array($existing)) {
            $existing = [];
        }
        while (count($existing) <= $libraryIndex) {
            $existing[] = [];
        }
        $existing[$libraryIndex] = array_values($sites);
        $this->module->setSystemSetting($key, $existing);
    }

    private function lastInsertId()
    {
        $r = $this->module->query('SELECT LAST_INSERT_ID() AS id', []);
        $row = $r ? $r->fetch_assoc() : null;
        return $row['id'] ?? 0;
    }

    // =======================================================================
    // Single-project dry run + targeted migration
    // =======================================================================

    /**
     * Dry-run preview for one specific project.
     *
     * Returns the full planFieldChanges output (label_updates, code_allocations,
     * record_migrations) plus the code-reference scan result and — if $deep is
     * true — per-old-code record counts from the sharded data table.
     *
     * Side effects: creates a status row for the project if one doesn't exist yet,
     * persists the scan result, and flips the status to needs_ack / pending as
     * appropriate — same behaviour as the per-project loop inside previewMigration().
     */
    public function previewSingleProject(int $ruleSetId, int $pid, bool $deep = false): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }

        $title   = $this->fetchProjectTitle($pid);
        $mapping = $this->getStudySiteMapping($pid);
        if (!$mapping) {
            return [
                'project_id'        => $pid,
                'project_title'     => $title,
                'field_name'        => null,
                'label_updates'     => [],
                'code_allocations'  => [],
                'record_migrations' => [],
                'code_references'   => ['total' => 0],
                'record_counts'     => [],
                'human_changes'     => [],
                'status'            => self::PROJECT_SKIPPED,
                'note'              => 'No studySites mapping configured',
            ];
        }

        $plan      = $this->planFieldChanges($pid, $ruleSet['rules']);
        $fieldName = $plan['field_name'];

        // Code-reference scan for codes that will be rewritten (merge / target-bound sunset).
        $oldCodes = array_unique(array_map(
            fn($rm) => (string)$rm['old_code'],
            $plan['record_migrations']
        ));
        $codeRefs = (!empty($oldCodes) && $fieldName)
            ? $this->scanCodeReferences($pid, $fieldName, $oldCodes)
            : ['total' => 0];

        // Ensure status row exists; persist scan result; flip needs_ack / pending.
        $statusTable    = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $existingStatus = $this->getProjectStatus($ruleSetId, $pid);
        if ($existingStatus === null) {
            $now = time();
            $this->module->query(
                "INSERT INTO $statusTable (migration_id, project_id, status, changes_applied, created, updated) VALUES (?, ?, ?, 0, ?, ?)",
                [$ruleSetId, $pid, self::PROJECT_PENDING, $now, $now]
            );
            $existingStatus = self::PROJECT_PENDING;
        }
        $this->persistCodeReferences($ruleSetId, $pid, $codeRefs);

        $newStatus = $existingStatus;
        if ($codeRefs['total'] > 0) {
            $newStatus = $this->getAcknowledgedAt($ruleSetId, $pid)
                ? self::PROJECT_PENDING : self::PROJECT_NEEDS_ACK;
        } elseif ($existingStatus === self::PROJECT_NEEDS_ACK) {
            $newStatus = self::PROJECT_PENDING;
        }
        if ($newStatus !== $existingStatus
            && !in_array($existingStatus, [self::PROJECT_COMPLETED, self::PROJECT_IN_PROGRESS, self::PROJECT_FAILED], true)) {
            $this->markProjectStatus($ruleSetId, $pid, $newStatus, 0);
        }

        // Deep: per-old-code record counts from the sharded table.
        $recordCounts = [];
        if ($deep && $fieldName) {
            foreach ($plan['record_migrations'] as $rm) {
                $recordCounts[(string)$rm['old_code']] =
                    $this->countRecordsWithSiteCode($pid, $fieldName, (string)$rm['old_code']);
            }
        }

        return [
            'project_id'        => $pid,
            'project_title'     => $title,
            'field_name'        => $fieldName,
            'label_updates'     => $plan['label_updates'],
            'code_allocations'  => $plan['code_allocations'],
            'record_migrations' => $plan['record_migrations'],
            'code_references'   => $codeRefs,
            'record_counts'     => $recordCounts,
            'human_changes'     => $this->buildHumanChanges($ruleSet['rules'], $plan, $recordCounts),
            'status'            => $newStatus,
            'note'              => '',
        ];
    }

    /**
     * Migrate one specific project without running the full automated queue.
     *
     * On the first call (or if crons aren't disabled yet): activates the rule set,
     * disables OnCore sync crons, and runs updateLibrarySettings() (idempotent).
     *
     * Then calls processProject() for exactly the requested PID inside a single
     * per-project transaction. Does NOT re-enable crons — call finalizeMigration()
     * when all the projects you want to process have been handled.
     */
    public function migrateSpecificProject(int $ruleSetId, int $pid): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }

        // First-call init: disable crons + mark active + library update (all idempotent).
        if ($ruleSet['status'] === self::STATUS_DRAFT || !self::isMigrationInProgress($this->module)) {
            $this->disableCrons();
            if ($ruleSet['status'] === self::STATUS_DRAFT) {
                $this->module->query(
                    'UPDATE ' . OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION
                    . ' SET status = ?, updated = ? WHERE id = ?',
                    [self::STATUS_ACTIVE, time(), $ruleSetId]
                );
            }
            $libIdx = isset($ruleSet['library_index']) ? (int)$ruleSet['library_index'] : 0;
            try {
                $this->updateLibrarySettings($ruleSet['rules'], $libIdx, $ruleSetId);
            } catch (\Throwable $e) {
                $this->module->emError("migrateSpecificProject: updateLibrarySettings: " . $e->getMessage());
                // Non-fatal — continue with per-project migration.
            }
        }

        // Ensure a status row exists for this project.
        if ($this->getProjectStatus($ruleSetId, $pid) === null) {
            $now = time();
            $this->module->query(
                'INSERT INTO ' . OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS
                . ' (migration_id, project_id, status, changes_applied, created, updated) VALUES (?, ?, ?, 0, ?, ?)',
                [$ruleSetId, $pid, self::PROJECT_PENDING, $now, $now]
            );
        }

        $title   = $this->fetchProjectTitle($pid);
        $outcome = $this->processProject($pid, $ruleSet['rules'], $ruleSetId);

        return [
            'projectId'         => $pid,
            'projectTitle'      => $title,
            'status'            => $outcome['status'],
            'changesApplied'    => $outcome['changes'] ?? 0,
            'newCodesAllocated' => $outcome['newCodesAllocated'] ?? [],
            'recordsMigrated'   => $outcome['recordsMigrated'] ?? null,
            'human_changes'     => $outcome['human_changes'] ?? [],
            'error'             => $outcome['error'] ?? '',
            'progress'          => $this->getMigrationStatus($ruleSetId),
        ];
    }

    // =======================================================================
    // One-time remediation — clean up duplicate study-site codes + value_mapping
    // pollution left by earlier buggy runs (pre element_enum-delimiter fix).
    // =======================================================================

    /**
     * Plan a cleanup of a project's study-site field WITHOUT writing anything.
     *
     *   - Duplicate element_enum codes (identical trimmed labels) are consolidated
     *     onto ONE canonical code; the rest are removed and their records repointed.
     *   - value_mapping entries are repointed so each OnCore name maps to the code
     *     whose label matches it (fixes "Main Hospital -> 2" style pollution), and
     *     identical {oc,rc} pairs are de-duplicated. Entries whose oc has no current
     *     label match are left untouched (backward-compat) and reported.
     *
     * Canonical selection minimizes breakage: the code in a dup group that is MOST
     * referenced in project logic wins (tie -> lowest numeric code), so removing the
     * others breaks the fewest references. Because removal — unlike the migration's
     * relabel — is destructive to any branching-logic/report/ASI reference, if a code
     * slated for removal is still referenced anywhere, the plan sets requires_ack.
     */
    public function planStudySiteCleanup(int $pid): array
    {
        $plan = [
            'field_name'        => null,
            'duplicates'        => [],
            'codes_removed'     => [],
            'removed_with_refs' => [],
            'requires_ack'      => false,
            'records_repointed' => 0,
            'vmap_fixes'        => [],
            'vmap_unresolved'   => [],
            'vmap_deduped'      => 0,
            'note'              => '',
        ];

        $mapping = $this->getStudySiteMapping($pid);
        if (!$mapping) {
            $plan['note'] = 'No studySites mapping configured';
            return $plan;
        }
        $field = $mapping['redcap_field'];
        $plan['field_name'] = $field;

        $enum  = $this->loadElementEnum($pid, $field);
        $codes = $enum['codes'];

        // 1. Group codes by exact trimmed label; >1 in a group ⇒ duplicates.
        $byLabel = [];
        foreach ($codes as $code => $label) {
            $byLabel[trim((string)$label)][] = (string)$code;
        }

        $removed = []; // removedCode => canonicalCode
        foreach ($byLabel as $label => $group) {
            if (count($group) < 2) {
                continue;
            }
            $meta = [];
            foreach ($group as $c) {
                $refScan = $this->scanCodeReferences($pid, $field, [$c]);
                $meta[$c] = [
                    'refs'    => (int)($refScan['total'] ?? 0),
                    'records' => $this->countRecordsWithSiteCode($pid, $field, $c),
                ];
            }
            $canonical = $this->chooseCanonicalCode($group, $meta);

            $removeList = [];
            foreach ($group as $c) {
                if ($c === $canonical) continue;
                $removed[$c] = $canonical;
                $plan['codes_removed'][] = $c;
                $plan['records_repointed'] += $meta[$c]['records'];
                $removeList[] = ['code' => $c, 'records' => $meta[$c]['records'], 'refs' => $meta[$c]['refs']];
                if ($meta[$c]['refs'] > 0) {
                    $plan['removed_with_refs'][] = ['code' => $c, 'refs' => $meta[$c]['refs']];
                    $plan['requires_ack'] = true;
                }
            }
            $plan['duplicates'][] = [
                'label'          => $label,
                'canonical'      => $canonical,
                'canonical_refs' => $meta[$canonical]['refs'],
                'remove'         => $removeList,
            ];
        }

        // 2. Cleaned codes = current codes minus removed.
        $cleaned = $codes;
        foreach (array_keys($removed) as $rc) {
            unset($cleaned[$rc]);
        }

        // 3. value_mapping fixes (both directions) via the shared cleaner.
        $raw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME, $pid);
        $fm  = $raw ? (json_decode($raw, true) ?: []) : [];
        foreach (['pull', 'push'] as $direction) {
            $vmap = $fm[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping'] ?? null;
            if (!is_array($vmap)) continue;
            $res = $this->cleanValueMapping($vmap, $cleaned, $removed);
            foreach ($res['fixes'] as $f)      $plan['vmap_fixes'][]      = ['direction' => $direction] + $f;
            foreach ($res['unresolved'] as $u) $plan['vmap_unresolved'][] = ['direction' => $direction] + $u;
            $plan['vmap_deduped'] += $res['deduped'];
        }

        return $plan;
    }

    /**
     * Pick the canonical code for a duplicate-label group: most-referenced wins
     * (keep what logic points at), tie-break the lowest numeric code.
     */
    private function chooseCanonicalCode(array $group, array $meta): string
    {
        $best = null; $bestRefs = -1; $bestNum = PHP_INT_MAX;
        foreach ($group as $c) {
            $refs = (int)($meta[$c]['refs'] ?? 0);
            $num  = ctype_digit((string)$c) ? (int)$c : PHP_INT_MAX;
            if ($refs > $bestRefs || ($refs === $bestRefs && $num < $bestNum)) {
                $best = (string)$c; $bestRefs = $refs; $bestNum = $num;
            }
        }
        return (string)$best;
    }

    /**
     * Repoint each value_mapping entry's rc to the code whose label matches its oc
     * (on the cleaned code set); repoint references to removed dup codes onto their
     * canonical; drop identical {oc,rc} duplicates. Entries whose oc resolves to no
     * current label are kept verbatim (backward-compat) and reported as unresolved.
     *
     * @return array{vmap:array,fixes:array,unresolved:array,deduped:int}
     */
    private function cleanValueMapping(array $vmap, array $cleanedCodes, array $removed): array
    {
        $out = []; $seen = []; $fixes = []; $unresolved = []; $deduped = 0;
        foreach ($vmap as $entry) {
            $oc = (string)($entry['oc'] ?? '');
            $rc = (string)($entry['rc'] ?? '');
            if ($oc === '') continue; // drop empty oc rows

            $target = $this->findCodeForLabel($cleanedCodes, $oc);
            if ($target === null && isset($removed[$rc])) {
                $target = $removed[$rc]; // a reference to a removed dup → its canonical
            }
            if ($target !== null) {
                if ((string)$target !== $rc) {
                    $fixes[] = ['oc' => $oc, 'old_rc' => $rc, 'new_rc' => (string)$target];
                }
                $rc = (string)$target;
            } else {
                $unresolved[] = ['oc' => $oc, 'rc' => $rc]; // keep as-is
            }

            $key = $oc . "\x00" . $rc;
            if (isset($seen[$key])) { $deduped++; continue; }
            $seen[$key] = true;
            $out[] = ['oc' => $oc, 'rc' => $rc];
        }
        return ['vmap' => $out, 'fixes' => $fixes, 'unresolved' => $unresolved, 'deduped' => $deduped];
    }

    /**
     * Apply the cleanup planned by planStudySiteCleanup() in a single transaction:
     * repoint records off removed dup codes, drop those codes from element_enum, and
     * fix + de-dupe value_mapping. Per-record changes are logged via REDCap::logEvent
     * post-commit (isolated). Idempotent: a clean project returns status 'noop'.
     *
     * @throws \RuntimeException if removed codes are still referenced and !$acknowledged
     */
    public function applyStudySiteCleanup(int $pid, bool $acknowledged = false): array
    {
        $plan = $this->planStudySiteCleanup($pid);
        $field = $plan['field_name'];
        if (!$field) {
            return ['status' => 'skipped', 'note' => $plan['note'],
                    'recordsRepointed' => 0, 'codesRemoved' => 0, 'vmapFixes' => 0];
        }
        if (empty($plan['codes_removed']) && empty($plan['vmap_fixes']) && $plan['vmap_deduped'] === 0) {
            return ['status' => 'noop', 'recordsRepointed' => 0, 'codesRemoved' => 0, 'vmapFixes' => 0];
        }
        if ($plan['requires_ack'] && !$acknowledged) {
            throw new \RuntimeException(
                count($plan['removed_with_refs']) . ' duplicate code(s) being removed are still '
                . 'referenced in project logic (branching logic / alerts / ASI / reports). '
                . 'Review and acknowledge before applying — removal will leave those references dangling.'
            );
        }

        // removedCode => canonicalCode
        $removedMap = [];
        foreach ($plan['duplicates'] as $g) {
            foreach ($g['remove'] as $r) {
                $removedMap[(string)$r['code']] = (string)$g['canonical'];
            }
        }

        $changes  = [];
        $captured = [];
        $totalRepointed = 0;

        try {
            $this->module->query('START TRANSACTION', []);

            // 1. Repoint records off each removed dup code onto its canonical.
            $shard = $this->validateDataTableName($this->getProjectDataTable($pid));
            foreach ($removedMap as $old => $canon) {
                $rows = $this->getRecordsWithSiteCode($pid, $field, $old);
                $n    = $this->updateRecordValues($pid, $field, $old, $canon);
                $totalRepointed += $n;
                if (!empty($rows)) {
                    $captured[] = [
                        'old_code'  => $old,
                        'new_code'  => $canon,
                        'reason'    => 'duplicate_cleanup',
                        'old_label' => 'duplicate code ' . $old,
                        'new_site'  => 'code ' . $canon,
                        'rows'      => $rows,
                    ];
                }
                $changes[] = [
                    'change_type' => self::CHANGE_RECORD_VALUE_MIGRATION,
                    'field_name'  => $field,
                    'old_value'   => $old,
                    'new_value'   => $canon,
                    'details'     => json_encode(['rows_affected' => $n, 'data_table' => $shard, 'reason' => 'duplicate_cleanup']),
                ];
            }

            // 2. Drop removed dup codes from element_enum.
            $enum  = $this->loadElementEnum($pid, $field);
            $codes = $enum['codes'];
            foreach (array_keys($removedMap) as $old) {
                if (array_key_exists($old, $codes)) {
                    $changes[] = [
                        'change_type' => self::CHANGE_FIELD_LABEL,
                        'field_name'  => $field,
                        'old_value'   => $old . ', ' . $codes[$old],
                        'new_value'   => '(removed duplicate of code ' . $removedMap[$old] . ')',
                    ];
                    unset($codes[$old]);
                }
            }
            $newRaw = $this->serializeElementEnum($codes);
            if ($newRaw !== $enum['raw']) {
                $this->module->query(
                    'UPDATE redcap_metadata SET element_enum = ? WHERE project_id = ? AND field_name = ?',
                    [$newRaw, $pid, $field]
                );
            }

            // 3. Fix + de-dupe value_mapping (both directions).
            $raw    = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME, $pid);
            $fm     = $raw ? (json_decode($raw, true) ?: []) : [];
            $origFm = $fm;
            foreach (['pull', 'push'] as $direction) {
                $vmap = $fm[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping'] ?? null;
                if (!is_array($vmap)) continue;
                $res = $this->cleanValueMapping($vmap, $codes, $removedMap);
                $fm[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping'] = $res['vmap'];
                foreach ($res['fixes'] as $fx) {
                    $changes[] = [
                        'change_type' => self::CHANGE_VALUE_MAPPING,
                        'field_name'  => $field,
                        'old_value'   => $fx['oc'] . ' (rc=' . $fx['old_rc'] . ')',
                        'new_value'   => $fx['oc'] . ' (rc=' . $fx['new_rc'] . ')',
                        'details'     => json_encode(['direction' => $direction, 'reason' => 'cleanup']),
                    ];
                }
            }
            if ($fm !== $origFm) {
                $this->module->setProjectSetting(
                    OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME,
                    json_encode($fm),
                    $pid
                );
            }

            // Entity audit log INSIDE the transaction (migration_id 0 = standalone cleanup).
            $this->logToEntity($pid, 0, $changes);

            $this->module->query('COMMIT', []);
        } catch (\Throwable $e) {
            try { $this->module->query('ROLLBACK', []); } catch (\Throwable $_) { /* swallow */ }
            $this->module->emError("Study-site cleanup for project $pid failed: " . $e->getMessage());
            throw $e;
        }

        // Post-commit, isolated (a logging failure must not undo a committed cleanup).
        try {
            $this->logToREDCap($pid, $changes);
            $this->logRecordMigrationsToREDCap(
                $pid, $field, $captured,
                'OnCore Site Migration — duplicate study-site code consolidated'
            );
        } catch (\Throwable $logErr) {
            $this->module->emError("Cleanup post-commit logging for project $pid failed (committed): " . $logErr->getMessage());
        }

        return [
            'status'           => 'completed',
            'recordsRepointed' => $totalRepointed,
            'codesRemoved'     => count($plan['codes_removed']),
            'codes_removed'    => $plan['codes_removed'],
            'vmapFixes'        => count($plan['vmap_fixes']),
            'vmapDeduped'      => $plan['vmap_deduped'],
        ];
    }

    // =======================================================================
    // Schema migration — idempotent in-place upgrade for pre-rev-3 installs.
    // =======================================================================

    /**
     * Adds rev-3 columns to existing entity tables if they're missing.
     * Idempotent — safe to call on every request. Skips silently if the
     * entity tables haven't been built yet (EntityDB::buildSchema handles that).
     *
     * Column type strings are hard-coded from an internal allowlist below;
     * never interpolate untrusted input into ALTER TABLE.
     */
    public function ensureSchemaUpToDate(): void
    {
        $pairs = [
            OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS => [
                'code_references_json' => 'LONGTEXT NULL',
                'acknowledged_at'      => 'INT NULL',
                'acknowledged_by'      => 'VARCHAR(255) NULL',
            ],
            OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION_LOG => [
                'details' => 'LONGTEXT NULL',
            ],
        ];
        foreach ($pairs as $table => $cols) {
            // Skip if the entity table doesn't even exist yet — EntityDB::buildSchema will create it.
            try {
                $r = $this->module->query("SHOW TABLES LIKE ?", [$table]);
            } catch (\Throwable $_) {
                continue;
            }
            if (!$r || !$r->fetch_assoc()) {
                continue;
            }
            foreach ($cols as $col => $type) {
                try {
                    $check = $this->module->query(
                        "SELECT COUNT(*) AS c FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
                        [$table, $col]
                    );
                    $row = $check ? $check->fetch_assoc() : null;
                    if ((int)($row['c'] ?? 0) === 0) {
                        // Backticks around identifiers; $type is from the allowlist above.
                        $this->module->query("ALTER TABLE `$table` ADD COLUMN `$col` $type", []);
                        if (method_exists($this->module, 'emDebug')) {
                            $this->module->emDebug("ensureSchemaUpToDate: added column $col to $table");
                        }
                    }
                } catch (\Throwable $e) {
                    if (method_exists($this->module, 'emError')) {
                        $this->module->emError("ensureSchemaUpToDate: failed on $table.$col: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

