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
        all OnCore-linked REDCap projects. <strong>No records in <code>redcap_data</code>
        are written</strong> — only settings, value mappings, and field option labels.
    </p>

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
                <code>{"id"?, "type": "rename|merge|keep|sunset", "old_sites": [...], "new_site"?, "primary_old_site"?, "retired_on"?}</code>.
                See <code>SITE_MIGRATION_PLAN.md</code> §6.1 and Appendix C.
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
                    <th>Project ID</th><th>Title</th>
                    <th>Sites Affected</th><th>Label Changes</th>
                    <th>Mapping Changes</th><th>Records (deep)</th>
                    <th>Status</th><th>Note</th>
                </tr>
            </thead>
            <tbody id="sm-preview-body">
                <tr><td colspan="8" class="text-muted">Select a rule set and click Generate Preview.</td></tr>
            </tbody>
        </table>
    </div>

    <!-- TAB: Run Migration --------------------------------------------- -->
    <div class="sm-tab" data-tab-panel="run" style="display:none">
        <div class="alert alert-warning">
            <strong>Heads up:</strong> starting a migration sets
            <code>migration-in-progress = true</code>, which causes all 4 OnCore sync
            crons to skip until finalize. The system flag is cleared by Finalize Migration.
        </div>

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
                <tr><th>Project</th><th>Status</th><th>Changes</th><th>Error</th></tr>
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
