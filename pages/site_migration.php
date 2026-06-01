<?php

namespace Stanford\OnCoreIntegration;

use ExternalModules\ExternalModules;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

// Server-side guard: Control Center / super-user only.
if (!$module->isSuperUser()) {
    echo '<div class="alert alert-danger">Site Migration requires super-user privileges.</div>';
    return;
}

// Resolve the available libraries so the rule editor's library selector can
// show the right names. Reuses the existing getSubSettings('libraries') wiring.
$libraries = [];

// workaround
$projects = ExternalModules::getEnabledProjects($module->PREFIX);
$project = $projects->fetch_assoc();
$_GET['pid'] = $project['project_id'];
$module->setProjectId($project['project_id']);
foreach ($module->getSubSettings('libraries') as $idx => $lib) {
    $libraries[] = [
        'index' => $idx,
        'name'  => $lib['library-name'] ?? sprintf('Library %d', $idx),
        'sites' => OnCoreIntegration::getSubSettingsValuesAsArray(
            $lib['library-oncore-study-sites'] ?? [],
            'library-study-site'
        ),
    ];
}

$cssUrl = $module->getUrl('assets/styles/site_migration.css');
$jsUrl  = $module->getUrl('assets/scripts/site_migration.js');

$module->initializeJavascriptModuleObject();
?>
<link rel="stylesheet" href="<?= $module->escape($cssUrl) ?>">
<div id="oncore-site-migration" class="container-fluid">
    <h2>OnCore Study Site Migration</h2>
    <p class="text-muted small">
        Apply OnCore study-site renames, merges, keeps, and sunsets retroactively across
        all OnCore-linked REDCap projects.
    </p>
    <div class="sm-intro-callout">
        <div><strong>What gets written:</strong></div>
        <ul class="mb-0">
            <li><code>redcap_metadata.element_enum</code> — labels: rename relabels in place; merge allocates a new code and suffixes old ones <code>(retired — migrated to X)</code></li>
            <li><code>redcap_external_modules_settings</code> — project site subset + value mapping + library site list</li>
            <li><code>redcap_data*</code> (sharded) — <strong>merge and target-bound sunset rewrite record values</strong> from old code → new code on the project's resolved shard (<code>getDataTable($pid)</code> + allowlist regex). Renames leave records untouched.</li>
        </ul>
        <div class="mt-1 small">
            Before any merge runs, a code-reference scan flags branching logic / alerts / ASI / report filters that reference the old codes. Findings appear as warnings; admin must Acknowledge each project before its migration is allowed to run.
        </div>
    </div>

    <ul class="nav nav-tabs" id="smTabs" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-tab="rule-sets" href="#">Rule Sets</a></li>
        <li class="nav-item"><a class="nav-link" data-tab="editor" href="#">Rule Editor</a></li>
        <li class="nav-item"><a class="nav-link" data-tab="preview" href="#">Preview</a></li>
        <li class="nav-item"><a class="nav-link" data-tab="run" href="#">Run Migration</a></li>
        <li class="nav-item"><a class="nav-link" data-tab="history" href="#">History</a></li>
    </ul>

    <!-- TAB: Rule Sets ------------------------------------------------- -->
    <div class="sm-tab" data-tab-panel="rule-sets">
        <div class="sm-toolbar">
            <button class="btn btn-primary btn-sm" id="sm-new-rule-set">+ New Rule Set</button>
            <button class="btn btn-link btn-sm" id="sm-reload-rule-sets">Reload</button>
        </div>
        <table class="table table-sm sm-rule-sets-table">
            <thead>
                <tr><th>ID</th><th>Name</th><th>Status</th><th>Library</th><th>Created</th><th>Actions</th></tr>
            </thead>
            <tbody id="sm-rule-sets-body">
                <tr><td colspan="6" class="text-muted">Loading…</td></tr>
            </tbody>
        </table>
    </div>

    <!-- TAB: Rule Editor ---------------------------------------------- -->
    <div class="sm-tab" data-tab-panel="editor" style="display:none">
        <form id="sm-editor-form">
            <input type="hidden" id="sm-rule-id" value="0">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Name</label>
                    <input type="text" class="form-control form-control-sm" id="sm-name" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Library</label>
                    <select class="form-control form-control-sm" id="sm-library">
                        <?php foreach ($libraries as $lib): ?>
                            <option value="<?= (int)$lib['index'] ?>">
                                <?= $module->escape($lib['name']) ?> (<?= count($lib['sites']) ?> sites)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label>Status</label>
                    <input type="text" class="form-control form-control-sm" id="sm-status" value="draft" disabled>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" class="form-control form-control-sm" id="sm-description"
                       placeholder="e.g. Per Subject Study Site Worksheet (May 2026)">
            </div>

            <div class="sm-rules-toolbar">
                <strong>Rules (JSON)</strong>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="sm-seed-may2026">
                    Load May 2026 Stanford Worksheet
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="sm-validate-rules">
                    Validate JSON
                </button>
            </div>
            <textarea id="sm-rules-json" class="form-control sm-rules-textarea" rows="18"
                      placeholder='[{"type":"keep","old_sites":["Byers Eye Institute"]}]'></textarea>
            <small class="form-text text-muted">
                Rule schema:
                <code>{"id"?, "type": "rename|merge|keep|sunset", "old_sites": [...], "new_site"?, "primary_old_site"?, "merged_into"?, "retired_on"?}</code>.
                <br>
                <strong>rename</strong> = relabel the existing code in place; no <code>redcap_data*</code> write.
                <strong>merge</strong> = allocate a new per-project code (<code>max+1</code>), suffix old codes <code>(retired — migrated to X)</code>, rewrite records on the project's shard.
                <strong>sunset</strong> = suffix label <code>(retired YYYY-MM-DD)</code>; if <code>merged_into</code> is set, also rewrites records.
                <strong>keep</strong> = no-op.
                <br>
                <code>primary_old_site</code> is informational only (used to seed the UI default) — the primary's code is <strong>not</strong> reused; merges always allocate a fresh code.
                See <code>SITE_MIGRATION_PLAN.md</code> §5 and Appendix C.
            </small>

            <div class="mt-3">
                <button type="button" class="btn btn-primary btn-sm" id="sm-save-rule-set">Save as Draft</button>
                <button type="button" class="btn btn-secondary btn-sm" id="sm-cancel-editor">Cancel</button>
                <span id="sm-editor-msg" class="ml-3 small"></span>
            </div>
        </form>
    </div>

    <!-- TAB: Preview --------------------------------------------------- -->
    <div class="sm-tab" data-tab-panel="preview" style="display:none">
        <div class="sm-toolbar">
            <label class="mb-0 mr-2">Rule Set:</label>
            <select id="sm-preview-rule-set" class="form-control form-control-sm sm-rule-select"></select>
            <button class="btn btn-primary btn-sm" id="sm-run-preview">Generate Preview</button>
            <button class="btn btn-outline-primary btn-sm" id="sm-run-preview-deep" title="Counts records in redcap_data*">
                Deep Preview (record counts)
            </button>
            <button class="btn btn-outline-secondary btn-sm" id="sm-export-csv">Export CSV</button>
        </div>

        <div id="sm-preview-summary" class="sm-summary"></div>

        <table class="table table-sm sm-preview-table">
            <thead>
                <tr>
                    <th>PID</th>
                    <th>Title</th>
                    <th title="Sites in this project that match a rule's old_sites">Sites</th>
                    <th title="element_enum label edits">Label Δ</th>
                    <th title="value_mapping entries added">Map Δ</th>
                    <th title="Codes newly allocated for merge / target-bound sunset">New Codes</th>
                    <th title="Records that will be (or were, in deep preview) rewritten">Records</th>
                    <th title="Code references found in branching logic, alerts, ASI, reports">⚠ Refs</th>
                    <th>Status</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody id="sm-preview-body">
                <tr><td colspan="10" class="text-muted">Select a rule set and click Generate Preview.</td></tr>
            </tbody>
        </table>

        <!-- Code-reference details modal (filled by getCodeReferenceDetails) -->
        <div id="sm-refs-modal" class="sm-modal" style="display:none">
            <div class="sm-modal-inner">
                <div class="sm-modal-header">
                    <strong id="sm-refs-title">Code references</strong>
                    <button type="button" class="close" id="sm-refs-close">&times;</button>
                </div>
                <div class="sm-modal-body">
                    <div id="sm-refs-status" class="small text-muted mb-2"></div>
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Source</th><th>Table</th><th>Row ID</th><th>Snippet</th></tr>
                        </thead>
                        <tbody id="sm-refs-body"></tbody>
                    </table>
                    <div class="text-right">
                        <button type="button" class="btn btn-primary btn-sm" id="sm-refs-ack">
                            Acknowledge — allow this project to run
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB: Run Migration --------------------------------------------- -->
    <div class="sm-tab" data-tab-panel="run" style="display:none">
        <div class="alert alert-warning">
            <strong>Heads up:</strong> starting a migration sets
            <code>migration-in-progress = true</code>, which causes all 4 OnCore sync
            crons to skip until finalize. The system flag is cleared by Finalize Migration.
            <br>
            Merges and target-bound sunsets will write to the project's
            <code>redcap_data*</code> shard (resolved per project via
            <code>getDataTable($pid)</code>). Per-project transactions wrap both metadata
            and data writes, so a failure rolls back atomically.
        </div>
        <div id="sm-run-blocked-banner" class="alert alert-info" style="display:none"></div>

        <div class="sm-toolbar">
            <label class="mb-0 mr-2">Rule Set:</label>
            <select id="sm-run-rule-set" class="form-control form-control-sm sm-rule-select"></select>
            <button class="btn btn-success btn-sm" id="sm-run-start">Start Migration</button>
            <button class="btn btn-warning btn-sm" id="sm-run-pause" disabled>Pause (after current project)</button>
            <button class="btn btn-primary btn-sm" id="sm-run-finalize" disabled>Finalize</button>
        </div>

        <div id="sm-run-progress" class="sm-progress">
            <div class="sm-progress-bar-wrap"><div class="sm-progress-bar" id="sm-progress-bar"></div></div>
            <div class="sm-progress-label" id="sm-progress-label">No active migration.</div>
        </div>

        <table class="table table-sm sm-run-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Status</th>
                    <th title="Codes newly allocated">New Codes</th>
                    <th title="Records rewritten in redcap_data*">Records</th>
                    <th>Changes</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody id="sm-run-body"></tbody>
        </table>
    </div>

    <!-- TAB: History --------------------------------------------------- -->
    <div class="sm-tab" data-tab-panel="history" style="display:none">
        <div class="sm-toolbar">
            <button class="btn btn-link btn-sm" id="sm-reload-history">Reload</button>
        </div>
        <table class="table table-sm sm-history-table">
            <thead>
                <tr>
                    <th>ID</th><th>Name</th><th>Status</th>
                    <th>Completed</th><th>Failed</th><th>Skipped</th><th>Total</th>
                    <th>Created</th><th></th>
                </tr>
            </thead>
            <tbody id="sm-history-body">
                <tr><td colspan="9" class="text-muted">Loading…</td></tr>
            </tbody>
        </table>

        <!-- Project log modal -->
        <div id="sm-log-modal" class="sm-modal" style="display:none">
            <div class="sm-modal-inner">
                <div class="sm-modal-header">
                    <strong id="sm-log-title">Project log</strong>
                    <button type="button" class="close" id="sm-log-close">&times;</button>
                </div>
                <div class="sm-modal-body">
                    <div id="sm-log-status" class="small text-muted"></div>
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Change Type</th><th>Field</th><th>Old Value</th><th>New Value</th></tr>
                        </thead>
                        <tbody id="sm-log-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= $module->escape($jsUrl) ?>"></script>
<script>
    (function () {
        const module = <?= $module->getJavascriptModuleObjectName() ?>;
        module.smConfig = {
            libraries: <?= json_encode($libraries, JSON_THROW_ON_ERROR) ?>,
            csrfToken: '<?= $module->escape($module->getCSRFToken()) ?>',
        };
        if (typeof module.initSiteMigration === 'function') {
            module.initSiteMigration();
        } else {
            console.error('initSiteMigration is not defined; check assets/scripts/site_migration.js');
        }
    })();
</script>
