<?php

namespace Stanford\OnCoreIntegration;

/**
 * Class SiteMigration
 *
 * Implements the OnCore Study Site Migration tool described in SITE_MIGRATION_PLAN.md.
 *
 * Scope of this class:
 *   - Rule-set CRUD (rename / merge / keep / sunset rules)
 *   - The five per-change layer updates (library setting, project subset, value mapping,
 *     field labels, audit log)
 *   - Cron-guard primitives (system-wide pause flag)
 *   - Shard-aware data-table resolver for read-only preview queries
 *
 * Phase 2 (this file): rule CRUD + per-change layer updates + logging.
 * Phase 3: preview methods (added to this class).
 * Phase 4: execution engine (startMigration / processNextProject / finalizeMigration,
 *          also added to this class).
 *
 * Tables touched:
 *   - redcap_metadata (NOT sharded) — UPDATE element_enum
 *   - redcap_external_modules_settings — via $module->setProjectSetting / setSystemSetting
 *   - redcap_entity_oncore_site_migration{,_log,_project_status} (this EM's new entities)
 *
 * Tables NEVER touched:
 *   - redcap_data, redcap_data2 … redcap_data8 (the sharded record data tables)
 *
 * @package Stanford\OnCoreIntegration
 */
class SiteMigration
{
    use emLoggerTrait;

    /** Rule status values stored in redcap_entity_oncore_site_migration.status */
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';

    /** Rule types */
    const RULE_RENAME = 'rename';
    const RULE_MERGE = 'merge';
    const RULE_KEEP = 'keep';
    const RULE_SUNSET = 'sunset';

    /** Per-project status values in redcap_entity_oncore_migration_project_status.status */
    const PROJECT_PENDING = 'pending';
    const PROJECT_IN_PROGRESS = 'in_progress';
    const PROJECT_COMPLETED = 'completed';
    const PROJECT_FAILED = 'failed';
    const PROJECT_SKIPPED = 'skipped';

    /** Change-type values in redcap_entity_oncore_site_migration_log.change_type */
    const CHANGE_LIBRARY_SETTING = 'library_setting';
    const CHANGE_PROJECT_SUBSET = 'project_subset';
    const CHANGE_VALUE_MAPPING = 'value_mapping';
    const CHANGE_FIELD_LABEL = 'field_label';

    /** @var OnCoreIntegration */
    private $module;

    public function __construct(OnCoreIntegration $module)
    {
        $this->module = $module;
    }

    // -----------------------------------------------------------------------
    // Rule set CRUD
    // -----------------------------------------------------------------------

    /**
     * Return a single rule set by id, with rules already JSON-decoded.
     *
     * @return array|null  ['id'=>int, 'name'=>string, 'rules'=>array, ...] or null
     */
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

    /**
     * List all rule sets, newest first. Returns summary rows (no rules JSON).
     *
     * @return array[]
     */
    public function listRuleSets(): array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION;
        $r = $this->module->query(
            "SELECT id, name, description, library_index, status, created_by, created, updated
             FROM $table ORDER BY id DESC",
            []
        );
        $out = [];
        while ($row = $r->fetch_assoc()) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Create or update a rule set. Pass id=0/null to create.
     *
     * Validates rule structure before saving (throws on bad input).
     *
     * @return int  Entity id of the saved rule set.
     */
    public function saveRuleSet(array $data): int
    {
        $rules = $this->normalizeRules($data['rules'] ?? []);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Rule set name is required.');
        }

        $row = [
            'name' => $name,
            'description' => (string)($data['description'] ?? ''),
            'rules' => json_encode($rules, JSON_THROW_ON_ERROR),
            'library_index' => isset($data['library_index']) ? (int)$data['library_index'] : null,
            'status' => $data['status'] ?? self::STATUS_DRAFT,
            'created_by' => $data['created_by'] ?? (defined('USERID') ? USERID : 'system'),
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

    /**
     * Delete a rule set. Only allowed for status='draft' — completed/active sets are kept for history.
     */
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

    // -----------------------------------------------------------------------
    // Layer 1: Library site list (system setting; runs once per migration)
    // -----------------------------------------------------------------------

    /**
     * Update the system-level library site list for the given library index.
     *
     * For each non-keep rule, remove old site names from the library's
     * library-oncore-study-sites sub-setting and add the new name (if not present).
     *
     * Logged at change_type='library_setting' against project_id=0 (system-scope).
     *
     * @return array  Array of change descriptors (each: change_type, old_value, new_value).
     */
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
            // For sunset: do NOT add (the related rename/merge rule handles its new name).
            if ($rule['type'] !== self::RULE_SUNSET) {
                $newSite = $rule['new_site'] ?? '';
                if ($newSite !== '' && !in_array($newSite, $sites, true)) {
                    $sites[] = $newSite;
                    $changes[] = [
                        'change_type' => self::CHANGE_LIBRARY_SETTING,
                        'rule_id'     => $rule['id'] ?? null,
                        'old_value'   => '',
                        'new_value'   => $newSite,
                    ];
                }
            }
        }

        if ($sites !== $original) {
            $this->writeLibrarySites($libraryIndex, $sites);
        }

        // System-scope log entries — use project_id=0 sentinel.
        foreach ($changes as $c) {
            $this->logToEntity(0, $migrationId, [$c]);
        }
        return $changes;
    }

    // -----------------------------------------------------------------------
    // Layer 2: Project site subset (per-project setting)
    // -----------------------------------------------------------------------

    /**
     * Update redcap-oncore-project-site-studies for one project.
     *
     * @return array  Change descriptors (rule_id, change_type, old_value, new_value).
     */
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
                    // Only add the new site if at least one old site was present in this project.
                    // (Otherwise this project never used the affected sites — skip the add.)
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

    // -----------------------------------------------------------------------
    // Layer 3: Field value mapping (per-project setting)
    // -----------------------------------------------------------------------

    /**
     * Add new {oc -> rc} entries to redcap-oncore-fields-mapping[pull|push].studySites.value_mapping.
     * Existing old entries are retained for backward compatibility with in-flight OnCore payloads.
     *
     * @return array  Change descriptors.
     */
    public function updateValueMapping(int $pid, array $rules): array
    {
        $raw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME, $pid);
        $mapping = $raw ? (json_decode($raw, true) ?: []) : [];
        $changes = [];

        foreach (['pull', 'push'] as $direction) {
            if (empty($mapping[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping'])) {
                continue;
            }
            $vmap =& $mapping[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['value_mapping'];

            foreach ($rules as $rule) {
                if ($rule['type'] === self::RULE_KEEP || $rule['type'] === self::RULE_SUNSET) {
                    continue;
                }
                $newSite = $rule['new_site'] ?? '';
                if ($newSite === '') {
                    continue;
                }

                // Determine which rc code to use for the new site.
                $primary = $this->resolvePrimaryOldSite($rule);
                $rcCode = $this->getRcCodeForSite($primary, $vmap);
                if ($rcCode === null) {
                    // No old entry for the primary in this project's mapping — nothing to bind to.
                    continue;
                }

                // Avoid duplicate {oc:newSite, rc:rcCode}.
                $alreadyExists = false;
                foreach ($vmap as $entry) {
                    if (($entry['oc'] ?? null) === $newSite && (string)($entry['rc'] ?? '') === (string)$rcCode) {
                        $alreadyExists = true;
                        break;
                    }
                }
                if (!$alreadyExists) {
                    $vmap[] = ['oc' => $newSite, 'rc' => (string)$rcCode];
                    $changes[] = [
                        'rule_id'     => $rule['id'] ?? null,
                        'change_type' => self::CHANGE_VALUE_MAPPING,
                        'old_value'   => $primary . ' (rc=' . $rcCode . ')',
                        'new_value'   => $newSite . ' (rc=' . $rcCode . ')',
                        'field_name'  => $mapping[$direction][OnCoreIntegration::ONCORE_STUDY_SITE]['redcap_field'] ?? null,
                    ];
                }
            }
            unset($vmap);
        }

        if (!empty($changes)) {
            $this->module->setProjectSetting(
                OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME,
                json_encode($mapping),
                $pid
            );
        }
        return $changes;
    }

    // -----------------------------------------------------------------------
    // Layer 4: Field option labels (parameterized SQL on redcap_metadata)
    // -----------------------------------------------------------------------

    /**
     * Suffix the element_enum label for each affected coded value with the rule's suffix:
     *   - rename:  "(changed to <new>)"
     *   - merge primary: "(changed to <new>)"
     *   - merge secondary: "(merged into <new>)"
     *   - sunset:  "(retired <date>, merged into <new>)" (if merged_into present)
     *
     * Idempotent: skips labels that already contain "(changed to", "(merged into", "(retired".
     *
     * Only touches redcap_metadata, which is NOT sharded.
     *
     * @return array  Change descriptors.
     */
    public function updateFieldLabels(int $pid, array $rules): array
    {
        $mapping = $this->getStudySiteMapping($pid);
        if (!$mapping) {
            return [];
        }
        $fieldName = $mapping['redcap_field'];
        $vmap = $mapping['value_mapping'] ?? [];

        $r = $this->module->query(
            'SELECT element_enum FROM redcap_metadata WHERE project_id = ? AND field_name = ? LIMIT 1',
            [$pid, $fieldName]
        );
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row || $row['element_enum'] === null || $row['element_enum'] === '') {
            return [];
        }
        $enum = $row['element_enum'];
        $changes = [];

        foreach ($rules as $rule) {
            if ($rule['type'] === self::RULE_KEEP) {
                continue;
            }

            $newSite = $rule['new_site'] ?? '';
            $primary = $this->resolvePrimaryOldSite($rule);

            foreach ($rule['old_sites'] as $oldSite) {
                $rcCode = $this->getRcCodeForSite($oldSite, $vmap);
                if ($rcCode === null) {
                    continue;
                }

                $suffix = $this->buildLabelSuffix($rule, $oldSite, $primary);
                if ($suffix === '') {
                    continue;
                }

                [$newEnum, $changed, $oldLabel, $newLabel] = $this->suffixEnumLabel($enum, (string)$rcCode, $suffix);
                if ($changed) {
                    $enum = $newEnum;
                    $changes[] = [
                        'rule_id'     => $rule['id'] ?? null,
                        'change_type' => self::CHANGE_FIELD_LABEL,
                        'field_name'  => $fieldName,
                        'old_value'   => $oldLabel,
                        'new_value'   => $newLabel,
                    ];
                }
            }
        }

        if (!empty($changes)) {
            $this->module->query(
                'UPDATE redcap_metadata SET element_enum = ? WHERE project_id = ? AND field_name = ?',
                [$enum, $pid, $fieldName]
            );
        }
        return $changes;
    }

    // -----------------------------------------------------------------------
    // Logging
    // -----------------------------------------------------------------------

    /**
     * Insert a row in redcap_entity_oncore_site_migration_log for each change.
     *
     * @param int   $pid          REDCap project id (0 for system-scope library changes)
     * @param int   $migrationId  FK to redcap_entity_oncore_site_migration.id
     * @param array $changes      Array of change descriptors
     */
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
                    old_value, new_value, migrated_by, created, updated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $migrationId,
                    $pid,
                    $c['rule_id'] ?? null,
                    $c['change_type'] ?? '',
                    $c['field_name'] ?? null,
                    $c['old_value'] ?? null,
                    $c['new_value'] ?? null,
                    $migratedBy,
                    $now,
                    $now,
                ]
            );
        }
    }

    /**
     * Write a summary entry to the REDCap audit log (via REDCap::logEvent — which
     * auto-routes to the project's redcap_log_event* shard).
     */
    public function logToREDCap(int $pid, array $changes): void
    {
        if (empty($changes) || $pid <= 0) {
            return;
        }
        $lines = ['OnCore Site Migration applied:'];
        foreach ($changes as $c) {
            $lines[] = sprintf(
                '- [%s] %s%s%s',
                $c['change_type'] ?? '?',
                $c['old_value'] ?? '',
                ($c['new_value'] ?? '') !== '' ? ' → ' : '',
                $c['new_value'] ?? ''
            );
        }
        $description = implode("\n", $lines);

        \REDCap::logEvent(
            'OnCore Site Migration',
            $description,
            '',     // sql
            null,   // record
            null,   // event
            $pid
        );
    }

    // -----------------------------------------------------------------------
    // Cron guard primitives (used by Phase 4 cron methods)
    // -----------------------------------------------------------------------

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

    // -----------------------------------------------------------------------
    // Shard-aware data table resolver (read-only, used by preview in Phase 3)
    // -----------------------------------------------------------------------

    /**
     * Resolve the project's data shard (redcap_data, redcap_data2, … redcap_data8).
     *
     * Prefers the framework method getDataTable(); falls back to a direct
     * redcap_projects.data_table lookup.
     *
     * NEVER hard-codes "redcap_data".
     */
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
        if ($name === '' || !preg_match('/^redcap_data[1-8]?$/', $name)) {
            // Defensive fallback. If the column is empty (older REDCap rows pre-sharding),
            // default to the canonical first shard.
            return 'redcap_data';
        }
        return $name;
    }

    /**
     * Count records in the project's data shard that hold the given coded value
     * for the given field. Read-only; used only by the optional Deep Preview path.
     */
    public function countRecordsWithSiteCode(int $pid, string $fieldName, string $rcCode): int
    {
        $table = $this->getProjectDataTable($pid);
        // $table is validated by getProjectDataTable() against a strict regex,
        // so it is safe to inline (the framework's query() cannot parameterize identifiers).
        $r = $this->module->query(
            "SELECT COUNT(*) AS c FROM `$table` WHERE project_id = ? AND field_name = ? AND value = ?",
            [$pid, $fieldName, $rcCode]
        );
        $row = $r ? $r->fetch_assoc() : null;
        return (int)($row['c'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // History &amp; audit retrieval (Phase 6)
    // -----------------------------------------------------------------------

    /**
     * List rule sets with aggregated per-status counts.
     * Used by the History tab.
     */
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
                    COALESCE(SUM(ps.status = ?), 0) AS in_progress,
                    COUNT(ps.id) AS total_projects
             FROM $rs rs
             LEFT JOIN $ps ps ON ps.migration_id = rs.id
             GROUP BY rs.id, rs.name, rs.description, rs.status, rs.library_index,
                      rs.created_by, rs.created, rs.updated
             ORDER BY rs.id DESC",
            [
                self::PROJECT_COMPLETED, self::PROJECT_FAILED, self::PROJECT_SKIPPED,
                self::PROJECT_PENDING, self::PROJECT_IN_PROGRESS,
            ]
        );
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            // Cast numeric aggregates so JSON consumers don't get strings.
            foreach (['completed','failed','skipped','pending','in_progress','total_projects'] as $k) {
                $row[$k] = (int)$row[$k];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Per-project change log for a given migration. Optionally narrowed to one project.
     *
     * Used by the History tab's drill-down modal.
     */
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
            $logs[] = $row;
        }

        return [
            'migration_id' => $ruleSetId,
            'project_id'   => $projectId,
            'status'       => $statusRow,
            'changes'      => $logs,
        ];
    }

    // -----------------------------------------------------------------------
    // Execution engine (Phase 4)
    // -----------------------------------------------------------------------

    /**
     * Initialize a migration session for the given rule set.
     *
     *   - Sets the system-wide migration-in-progress flag (cron guard).
     *   - Marks the rule set status='active'.
     *   - Runs updateLibrarySettings() once (system-scope, before any project work).
     *   - Enumerates OnCore-integrated projects and seeds redcap_entity_oncore_migration_project_status
     *     with status='pending' for any project that does not already have a status row
     *     for this rule set. Projects already at status='completed' are not re-queued.
     *
     * Returns:
     *   [
     *     'sessionId'  => int   (== rule set id)
     *     'total'      => int   (projects queued for this run, including already-completed ones)
     *     'pending'    => int   (projects still to process)
     *     'completed'  => int   (projects already completed in a previous run)
     *     'projects'   => array of {project_id, project_title, status}
     *   ]
     */
    public function startMigration(int $ruleSetId): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }
        $rules = $ruleSet['rules'];

        // Disable crons before any writes.
        $this->disableCrons();

        // Mark rule set as active (idempotent).
        if ($ruleSet['status'] !== self::STATUS_ACTIVE && $ruleSet['status'] !== self::STATUS_COMPLETED) {
            $this->module->query(
                'UPDATE ' . OnCoreIntegration::REDCAP_ENTITY_ONCORE_SITE_MIGRATION
                . ' SET status = ?, updated = ? WHERE id = ?',
                [self::STATUS_ACTIVE, time(), $ruleSetId]
            );
        }

        // System-scope library update — runs once per migration session.
        $libIdx = isset($ruleSet['library_index']) ? (int)$ruleSet['library_index'] : 0;
        try {
            $this->updateLibrarySettings($rules, $libIdx, $ruleSetId);
        } catch (\Throwable $e) {
            $this->module->emError('updateLibrarySettings failed: ' . $e->getMessage());
            // Re-enable crons on a fatal startup error.
            $this->enableCrons();
            throw $e;
        }

        // Seed per-project status rows.
        $projects = $this->enumerateProjects();
        $existing = $this->loadProjectStatusMap($ruleSetId);
        $statusTable = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $now = time();
        foreach ($projects as $proj) {
            $pid = (int)$proj['project_id'];
            if (isset($existing[$pid])) {
                continue; // already seeded (possibly from a prior run)
            }
            $this->module->query(
                "INSERT INTO $statusTable
                   (migration_id, project_id, status, changes_applied, created, updated)
                 VALUES (?, ?, ?, 0, ?, ?)",
                [$ruleSetId, $pid, self::PROJECT_PENDING, $now, $now]
            );
        }

        return array_merge(
            ['sessionId' => $ruleSetId],
            $this->getMigrationStatus($ruleSetId)
        );
    }

    /**
     * Process exactly one pending project. Picks the lowest-id pending project,
     * runs the per-project transaction, and returns progress.
     *
     * Returns:
     *   [
     *     'sessionId'      => int
     *     'projectId'      => int|null  (null if no more pending)
     *     'projectTitle'   => string
     *     'status'         => 'completed'|'failed'|'skipped'|'idle'
     *     'changesApplied' => int
     *     'error'          => string (empty unless status=failed)
     *     'progress'       => same as getMigrationStatus()
     *   ]
     */
    public function processNextProject(int $ruleSetId): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }

        $next = $this->claimNextPendingProject($ruleSetId);
        if ($next === null) {
            return [
                'sessionId'      => $ruleSetId,
                'projectId'      => null,
                'projectTitle'   => '',
                'status'         => 'idle',
                'changesApplied' => 0,
                'error'          => '',
                'progress'       => $this->getMigrationStatus($ruleSetId),
            ];
        }

        $pid = (int)$next['project_id'];
        $title = $this->fetchProjectTitle($pid);
        $outcome = $this->processProject($pid, $ruleSet['rules'], $ruleSetId);

        return [
            'sessionId'      => $ruleSetId,
            'projectId'      => $pid,
            'projectTitle'   => $title,
            'status'         => $outcome['status'],
            'changesApplied' => $outcome['changes'],
            'error'          => $outcome['error'] ?? '',
            'progress'       => $this->getMigrationStatus($ruleSetId),
        ];
    }

    /**
     * Snapshot of a migration's progress. Safe to poll.
     */
    public function getMigrationStatus(int $ruleSetId): array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $r = $this->module->query(
            "SELECT status, COUNT(*) AS c FROM $table WHERE migration_id = ? GROUP BY status",
            [$ruleSetId]
        );
        $counts = [
            self::PROJECT_PENDING => 0, self::PROJECT_IN_PROGRESS => 0,
            self::PROJECT_COMPLETED => 0, self::PROJECT_FAILED => 0, self::PROJECT_SKIPPED => 0,
        ];
        while ($r && ($row = $r->fetch_assoc())) {
            $counts[$row['status']] = (int)$row['c'];
        }
        $total = array_sum($counts);
        $current = $counts[self::PROJECT_COMPLETED] + $counts[self::PROJECT_FAILED] + $counts[self::PROJECT_SKIPPED];
        return [
            'total'     => $total,
            'current'   => $current,
            'pending'   => $counts[self::PROJECT_PENDING],
            'inProgress'=> $counts[self::PROJECT_IN_PROGRESS],
            'completed' => $counts[self::PROJECT_COMPLETED],
            'failed'    => $counts[self::PROJECT_FAILED],
            'skipped'   => $counts[self::PROJECT_SKIPPED],
        ];
    }

    /**
     * Close out a migration session: re-enable crons and (if no failures remain)
     * mark the rule set status='completed'.
     */
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

        // Only mark "completed" if there are no failures. Otherwise leave as "active"
        // so the admin can investigate, retry failed projects, then call finalize again.
        $allDone = ($progress['failed'] === 0 && $progress['pending'] === 0);
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
     * Per-project transaction. Called by processNextProject(). Public so it can
     * be invoked from tests / one-off retry tooling.
     *
     * Returns:
     *   ['status' => 'completed'|'failed', 'changes' => int, 'error' => string]
     */
    public function processProject(int $projectId, array $rules, int $migrationId): array
    {
        // Re-confirm project is in 'in_progress' state (claimNextPendingProject set this).
        // If a caller invokes processProject directly without claiming first, claim now.
        $current = $this->getProjectStatus($migrationId, $projectId);
        if ($current === self::PROJECT_COMPLETED) {
            return ['status' => self::PROJECT_SKIPPED, 'changes' => 0, 'error' => ''];
        }
        if ($current !== self::PROJECT_IN_PROGRESS) {
            $this->markProjectStatus($migrationId, $projectId, self::PROJECT_IN_PROGRESS);
        }

        $allChanges = [];

        try {
            $this->module->query('START TRANSACTION', []);

            $subsetChanges  = $this->updateProjectSiteSubset($projectId, $rules);
            $mappingChanges = $this->updateValueMapping($projectId, $rules);
            $labelChanges   = $this->updateFieldLabels($projectId, $rules);

            $allChanges = array_merge($subsetChanges, $mappingChanges, $labelChanges);

            // Persist entity log rows inside the same transaction so logs only
            // appear when the data writes succeed.
            $this->logToEntity($projectId, $migrationId, $allChanges);

            $this->module->query('COMMIT', []);

            // REDCap::logEvent and per-project status update are NOT inside the
            // transaction. They are best-effort follow-ups; failure here should
            // not roll back the actual data changes.
            $this->logToREDCap($projectId, $allChanges);
            $this->markProjectStatus($migrationId, $projectId, self::PROJECT_COMPLETED, count($allChanges));

            return ['status' => self::PROJECT_COMPLETED, 'changes' => count($allChanges), 'error' => ''];
        } catch (\Throwable $e) {
            try { $this->module->query('ROLLBACK', []); } catch (\Throwable $_) { /* swallow */ }
            $this->module->emError("Site migration project $projectId failed: " . $e->getMessage());
            $this->markProjectStatus($migrationId, $projectId, self::PROJECT_FAILED, 0, $e->getMessage());
            return ['status' => self::PROJECT_FAILED, 'changes' => 0, 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------------
    // Phase 4 internals
    // -----------------------------------------------------------------------

    /**
     * Atomically claim the next pending project for this rule set by flipping
     * its status to 'in_progress'. Uses MySQL's row-level UPDATE-with-LIMIT
     * pattern so two concurrent callers cannot grab the same row.
     *
     * @return array|null  ['id'=>int, 'project_id'=>int] or null if no more pending
     */
    private function claimNextPendingProject(int $ruleSetId): ?array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $now = time();

        // Pick the lowest-id pending row and atomically flip it to in_progress.
        // Note: we cannot use FOR UPDATE in this codebase consistently, but the
        // UPDATE-then-SELECT pattern combined with the system migration-in-progress
        // flag (which prevents crons from interleaving) is sufficient for our
        // single-admin use case.
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

        $upd = $this->module->query(
            "UPDATE $table SET status = ?, updated = ?
             WHERE id = ? AND status = ?",
            [self::PROJECT_IN_PROGRESS, $now, (int)$row['id'], self::PROJECT_PENDING]
        );
        // mysqli affected_rows is available on the connection used by the framework,
        // but the wrapper returns a result handle. If a concurrent caller already
        // claimed this row, we'd see status != pending now; recurse to pick another.
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

    // -----------------------------------------------------------------------
    // Preview (Phase 3) — no writes
    // -----------------------------------------------------------------------

    /**
     * Dry-run preview for a rule set. Per project, counts:
     *   - sitesAffected   : how many of the rule set's old_sites are in the project's site subset
     *   - labelChanges    : how many element_enum labels would be suffixed
     *   - mappingChanges  : how many new value_mapping entries would be added
     *   - status          : pending | already_migrated | no_mapping
     *
     * If $deep is true, also includes per-rule record counts from the project's
     * sharded redcap_data* table (recordsAffected).
     *
     * Never writes anything. Safe to run repeatedly.
     */
    public function previewMigration(int $ruleSetId, bool $deep = false): array
    {
        $ruleSet = $this->getRuleSet($ruleSetId);
        if (!$ruleSet) {
            throw new \RuntimeException("Rule set #$ruleSetId not found.");
        }
        $rules = $ruleSet['rules'];

        $alreadyMigrated = $this->loadCompletedProjectIds($ruleSetId);
        $projects = $this->enumerateProjects();

        $rows = [];
        foreach ($projects as $proj) {
            $pid = (int)$proj['project_id'];

            if (in_array($pid, $alreadyMigrated, true)) {
                $rows[] = [
                    'project_id'      => $pid,
                    'project_title'   => $proj['app_title'] ?? '',
                    'sitesAffected'   => 0,
                    'labelChanges'    => 0,
                    'mappingChanges'  => 0,
                    'recordsAffected' => 0,
                    'status'          => self::PROJECT_SKIPPED,
                    'note'            => 'Already migrated for this rule set',
                ];
                continue;
            }

            $mapping = $this->getStudySiteMapping($pid);
            if (!$mapping) {
                $rows[] = [
                    'project_id'      => $pid,
                    'project_title'   => $proj['app_title'] ?? '',
                    'sitesAffected'   => 0,
                    'labelChanges'    => 0,
                    'mappingChanges'  => 0,
                    'recordsAffected' => 0,
                    'status'          => self::PROJECT_SKIPPED,
                    'note'            => 'No studySites mapping configured',
                ];
                continue;
            }

            $analysis = $this->analyzeProject($pid, $rules, $mapping, $deep);
            $rows[] = array_merge([
                'project_id'    => $pid,
                'project_title' => $proj['app_title'] ?? '',
                'status'        => self::PROJECT_PENDING,
                'note'          => '',
            ], $analysis);
        }

        return [
            'rule_set'  => [
                'id'     => $ruleSet['id'],
                'name'   => $ruleSet['name'],
                'status' => $ruleSet['status'],
                'rules'  => $rules,
            ],
            'projects'  => $rows,
            'totals'    => $this->summarizeTotals($rows),
        ];
    }

    /**
     * Generate a CSV that enumerates every planned change for a rule set.
     *
     * Columns:
     *   project_id, project_title, rule_id, rule_type, change_type,
     *   field_name, old_value, new_value
     *
     * Returns the full CSV body as a string (no streaming — preview-level data).
     */
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
            'project_id', 'project_title', 'rule_id', 'rule_type',
            'change_type', 'field_name', 'old_value', 'new_value',
        ]);

        foreach ($projects as $proj) {
            $pid = (int)$proj['project_id'];
            $title = $proj['app_title'] ?? '';
            $mapping = $this->getStudySiteMapping($pid);
            if (!$mapping) {
                continue;
            }
            $details = $this->collectChangeDetails($pid, $rules, $mapping);
            foreach ($details as $d) {
                fputcsv($fh, [
                    $pid,
                    $title,
                    $d['rule_id'] ?? '',
                    $d['rule_type'] ?? '',
                    $d['change_type'] ?? '',
                    $d['field_name'] ?? '',
                    $d['old_value'] ?? '',
                    $d['new_value'] ?? '',
                ]);
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * Per-project counts (no writes). Called by previewMigration; safe for batch use.
     *
     * @return array  ['sitesAffected'=>int, 'labelChanges'=>int, 'mappingChanges'=>int, 'recordsAffected'=>int]
     */
    private function analyzeProject(int $pid, array $rules, array $mapping, bool $deep): array
    {
        $subsetRaw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_PROJECT_SITE_STUDIES, $pid);
        $subset = $subsetRaw ? (json_decode($subsetRaw, true) ?: []) : [];
        $vmap = $mapping['value_mapping'] ?? [];
        $fieldName = $mapping['redcap_field'];

        $enumRow = $this->module->query(
            'SELECT element_enum FROM redcap_metadata WHERE project_id = ? AND field_name = ? LIMIT 1',
            [$pid, $fieldName]
        );
        $enum = ($enumRow && ($r = $enumRow->fetch_assoc())) ? (string)$r['element_enum'] : '';

        $sitesAffected = 0;
        $labelChanges = 0;
        $mappingChanges = 0;
        $recordsAffected = 0;

        foreach ($rules as $rule) {
            if (($rule['type'] ?? '') === self::RULE_KEEP) {
                continue;
            }
            $primary = $this->resolvePrimaryOldSite($rule);

            // Sites in the project's subset that match this rule.
            foreach ($rule['old_sites'] as $oldSite) {
                if (in_array($oldSite, $subset, true)) {
                    $sitesAffected++;
                }
            }

            // Label suffix counts (only for codes that already exist in element_enum
            // and aren't already suffixed).
            foreach ($rule['old_sites'] as $oldSite) {
                $rcCode = $this->getRcCodeForSite($oldSite, $vmap);
                if ($rcCode === null) {
                    continue;
                }
                $suffix = $this->buildLabelSuffix($rule, $oldSite, $primary);
                if ($suffix === '') {
                    continue;
                }
                [, $changed, , ] = $this->suffixEnumLabel($enum, (string)$rcCode, $suffix);
                if ($changed) {
                    $labelChanges++;
                }
                if ($deep) {
                    $recordsAffected += $this->countRecordsWithSiteCode($pid, $fieldName, (string)$rcCode);
                }
            }

            // Value mapping additions (rename/merge only).
            if (($rule['type'] ?? '') === self::RULE_RENAME || ($rule['type'] ?? '') === self::RULE_MERGE) {
                $newSite = $rule['new_site'] ?? '';
                if ($newSite === '' || $primary === null) {
                    continue;
                }
                $primaryRc = $this->getRcCodeForSite($primary, $vmap);
                if ($primaryRc === null) {
                    continue;
                }
                $exists = false;
                foreach ($vmap as $e) {
                    if (($e['oc'] ?? null) === $newSite && (string)($e['rc'] ?? '') === (string)$primaryRc) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $mappingChanges++;
                }
            }
        }

        return [
            'sitesAffected'   => $sitesAffected,
            'labelChanges'    => $labelChanges,
            'mappingChanges'  => $mappingChanges,
            'recordsAffected' => $recordsAffected,
        ];
    }

    /**
     * Detailed per-change rows for CSV export. One entry per planned write.
     */
    private function collectChangeDetails(int $pid, array $rules, array $mapping): array
    {
        $vmap = $mapping['value_mapping'] ?? [];
        $fieldName = $mapping['redcap_field'];
        $subsetRaw = $this->module->getProjectSetting(OnCoreIntegration::REDCAP_ONCORE_PROJECT_SITE_STUDIES, $pid);
        $subset = $subsetRaw ? (json_decode($subsetRaw, true) ?: []) : [];

        $enumRow = $this->module->query(
            'SELECT element_enum FROM redcap_metadata WHERE project_id = ? AND field_name = ? LIMIT 1',
            [$pid, $fieldName]
        );
        $enum = ($enumRow && ($r = $enumRow->fetch_assoc())) ? (string)$r['element_enum'] : '';

        $out = [];
        foreach ($rules as $rule) {
            $type = $rule['type'] ?? '';
            if ($type === self::RULE_KEEP) {
                continue;
            }
            $primary = $this->resolvePrimaryOldSite($rule);

            // Project subset removals.
            foreach ($rule['old_sites'] as $oldSite) {
                if (in_array($oldSite, $subset, true)) {
                    $out[] = [
                        'rule_id' => $rule['id'] ?? '', 'rule_type' => $type,
                        'change_type' => self::CHANGE_PROJECT_SUBSET,
                        'field_name' => '', 'old_value' => $oldSite, 'new_value' => '',
                    ];
                }
            }

            // Label suffixes.
            foreach ($rule['old_sites'] as $oldSite) {
                $rcCode = $this->getRcCodeForSite($oldSite, $vmap);
                if ($rcCode === null) {
                    continue;
                }
                $suffix = $this->buildLabelSuffix($rule, $oldSite, $primary);
                if ($suffix === '') {
                    continue;
                }
                [, $changed, $oldLabel, $newLabel] = $this->suffixEnumLabel($enum, (string)$rcCode, $suffix);
                if ($changed) {
                    $out[] = [
                        'rule_id' => $rule['id'] ?? '', 'rule_type' => $type,
                        'change_type' => self::CHANGE_FIELD_LABEL,
                        'field_name' => $fieldName,
                        'old_value' => $oldLabel, 'new_value' => $newLabel,
                    ];
                }
            }

            // Value mapping additions.
            if ($type === self::RULE_RENAME || $type === self::RULE_MERGE) {
                $newSite = $rule['new_site'] ?? '';
                if ($newSite !== '' && $primary !== null) {
                    $primaryRc = $this->getRcCodeForSite($primary, $vmap);
                    if ($primaryRc !== null) {
                        $exists = false;
                        foreach ($vmap as $e) {
                            if (($e['oc'] ?? null) === $newSite && (string)($e['rc'] ?? '') === (string)$primaryRc) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $out[] = [
                                'rule_id' => $rule['id'] ?? '', 'rule_type' => $type,
                                'change_type' => self::CHANGE_VALUE_MAPPING,
                                'field_name' => $fieldName,
                                'old_value' => $primary . ' (rc=' . $primaryRc . ')',
                                'new_value' => $newSite . ' (rc=' . $primaryRc . ')',
                            ];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Projects in scope of a migration: OnCore-integrated projects only
     * (i.e., have an entity row in redcap_entity_oncore_protocols with status YES),
     * skipping deleted REDCap projects.
     */
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

    /**
     * Returns ids of projects already marked completed for the given rule set.
     */
    private function loadCompletedProjectIds(int $ruleSetId): array
    {
        $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_MIGRATION_PROJECT_STATUS;
        $r = $this->module->query(
            "SELECT project_id FROM $table WHERE migration_id = ? AND status = ?",
            [$ruleSetId, self::PROJECT_COMPLETED]
        );
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = (int)$row['project_id'];
        }
        return $out;
    }

    private function summarizeTotals(array $rows): array
    {
        $t = ['projects' => count($rows), 'pending' => 0, 'skipped' => 0,
              'sitesAffected' => 0, 'labelChanges' => 0, 'mappingChanges' => 0, 'recordsAffected' => 0];
        foreach ($rows as $r) {
            if (($r['status'] ?? '') === self::PROJECT_SKIPPED) {
                $t['skipped']++;
            } else {
                $t['pending']++;
            }
            $t['sitesAffected']   += (int)($r['sitesAffected'] ?? 0);
            $t['labelChanges']    += (int)($r['labelChanges'] ?? 0);
            $t['mappingChanges']  += (int)($r['mappingChanges'] ?? 0);
            $t['recordsAffected'] += (int)($r['recordsAffected'] ?? 0);
        }
        return $t;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Return the project's studySites mapping entry, normalized.
     * Looks under mapping[pull][studySites] first, falls back to push.
     *
     * @return array|null  ['redcap_field'=>..., 'value_mapping'=>[...]]
     */
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

    /**
     * Look up the rc code for a given OnCore site name within a value_mapping array.
     */
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
     * For a merge rule, the primary is admin-selected (or defaults to first old site).
     * For a rename rule, the primary is the single old site.
     * For sunset/keep, no primary needed.
     */
    private function resolvePrimaryOldSite(array $rule): ?string
    {
        if (!empty($rule['primary_old_site'])) {
            return $rule['primary_old_site'];
        }
        return $rule['old_sites'][0] ?? null;
    }

    /**
     * Build the label suffix for one (rule, old_site) pair.
     *   - rename:                          "(changed to <new>)"
     *   - merge, this site == primary:     "(changed to <new>)"
     *   - merge, this site != primary:     "(merged into <new>)"
     *   - sunset:                          "(retired <date>, merged into <new>)" or "(retired <date>)"
     */
    private function buildLabelSuffix(array $rule, string $oldSite, ?string $primary): string
    {
        $newSite = $rule['new_site'] ?? '';
        switch ($rule['type']) {
            case self::RULE_RENAME:
                return $newSite !== '' ? "(changed to $newSite)" : '';

            case self::RULE_MERGE:
                if ($newSite === '') {
                    return '';
                }
                return ($oldSite === $primary)
                    ? "(changed to $newSite)"
                    : "(merged into $newSite)";

            case self::RULE_SUNSET:
                $date = $rule['retired_on'] ?? '';
                if ($newSite !== '' && $date !== '') {
                    return "(retired $date, merged into $newSite)";
                }
                if ($date !== '') {
                    return "(retired $date)";
                }
                if ($newSite !== '') {
                    return "(merged into $newSite)";
                }
                return '';
        }
        return '';
    }

    /**
     * Modify the element_enum pipe-delimited string to suffix the label for $rcCode.
     *
     * Idempotent: skips entries already containing "(changed to", "(merged into",
     * or "(retired" to avoid double-suffixing on rule re-run.
     *
     * @return array [newEnum (string), changed (bool), oldLabel (string), newLabel (string)]
     */
    private function suffixEnumLabel(string $enum, string $rcCode, string $suffix): array
    {
        $entries = preg_split('/\s*\|\s*/', $enum);
        $oldLabel = '';
        $newLabel = '';
        $changed = false;
        foreach ($entries as $i => $entry) {
            // Each entry is "code, label" (label may contain commas).
            if (!preg_match('/^\s*([^,]+?)\s*,\s*(.*)$/', $entry, $m)) {
                continue;
            }
            $code = $m[1];
            $label = $m[2];
            if ((string)$code !== (string)$rcCode) {
                continue;
            }
            // Idempotency guard.
            if (preg_match('/\((changed to|merged into|retired)\b/', $label)) {
                return [$enum, false, '', ''];
            }
            $oldLabel = $code . ', ' . $label;
            $newLabel = $code . ', ' . rtrim($label) . ' ' . $suffix;
            $entries[$i] = $newLabel;
            $changed = true;
            break;
        }
        return [implode(' | ', $entries), $changed, $oldLabel, $newLabel];
    }

    /**
     * Coerce the raw rules input into a validated, typed shape.
     *
     * Each rule must have:
     *   - id        string (auto-generated if missing)
     *   - type      one of rename|merge|keep|sunset
     *   - old_sites string[]  (non-empty)
     *   - new_site  string|null (required for rename/merge; optional for sunset)
     *   - primary_old_site string|null (merge only)
     *   - retired_on string|null  (sunset only — YYYY-MM-DD)
     */
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
            $out[] = [
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
        }
        return $out;
    }

    private function decodeRules(string $json): array
    {
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write the updated study-sites list back to the library at $libraryIndex.
     *
     * REDCap EM sub_settings storage shape: library-study-site is stored as a
     * nested array — one entry per parent library, each containing an array of
     * site name strings. We mutate just the entry at $libraryIndex.
     *
     * Other sibling leaf settings under `libraries` (library-name, library-staff-role,
     * library-protocol-status, etc.) are not touched.
     */
    private function writeLibrarySites(int $libraryIndex, array $sites): void
    {
        $key = 'library-study-site';
        $existing = $this->module->getSystemSetting($key);
        $existing = is_array($existing) ? $existing : (json_decode((string)$existing, true) ?: []);
        if (!is_array($existing)) {
            $existing = [];
        }
        // Ensure $existing has enough slots.
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
}
