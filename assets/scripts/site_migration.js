/**
 * OnCore Study Site Migration — Control Center UI controller.
 *
 * Plain vanilla JS. Uses the JSMO ajax helper exposed by REDCap EM. No framework.
 * Extends the existing OnCoreIntegration JSMO with site-migration tab handlers.
 *
 * Loaded by pages/site_migration.php. Server-side enforces super-user; this file
 * assumes the page already gated access.
 */
;(function () {
    const module_name = 'OnCoreIntegration';
    const module = ExternalModules.Stanford[module_name];

    // ─── Helpers ────────────────────────────────────────────────────────────
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function statusBadge(status) {
        const cls = {
            'draft': 'sm-badge-draft', 'active': 'sm-badge-active',
            'completed': 'sm-badge-completed',
            'pending': 'sm-badge-pending', 'in_progress': 'sm-badge-active',
            'failed': 'sm-badge-failed', 'skipped': 'sm-badge-skipped',
            'needs_ack': 'sm-badge-needs-ack', 'blocked': 'sm-badge-needs-ack',
        }[status] || 'sm-badge-draft';
        return `<span class="sm-badge ${cls}">${escapeHtml(status || '')}</span>`;
    }
    function newCodesCell(arr) {
        if (!Array.isArray(arr) || arr.length === 0) return '<span class="text-muted">—</span>';
        return arr.map(nc => `<div class="sm-newcode-pill"><code>${escapeHtml(nc.code)}</code>: ${escapeHtml(nc.site || nc.new_site || '')}</div>`).join('');
    }

    // Plain-English, per-rule change summary (server-built via buildHumanChanges()).
    const SM_HC_ICON = { rename: '✏️', merge: '🔀', sunset_target: '🌇', sunset: '🌇' };
    function renderHumanChanges(items) {
        if (!Array.isArray(items) || items.length === 0) return '';
        const body = items.map(function (it) {
            const kind = it.kind || '';
            const recs = (it.records == null)
                ? ''
                : `<span class="sm-hc-records">${escapeHtml(it.records)} record${Number(it.records) === 1 ? '' : 's'}</span>`;
            const lines = (it.lines || []).map(l => `<li>${escapeHtml(l)}</li>`).join('');
            return `<div class="sm-hc-item sm-hc-${escapeHtml(kind)}">
                        <div class="sm-hc-head">
                            <span class="sm-hc-icon">${SM_HC_ICON[kind] || '•'}</span>
                            <strong>${escapeHtml(it.headline || '')}</strong>
                            ${recs}
                        </div>
                        ${lines ? `<ul class="sm-hc-lines">${lines}</ul>` : ''}
                    </div>`;
        }).join('');
        return `<div class="sm-human-changes">${body}</div>`;
    }
    function actionsCell(r, ruleSetId) {
        const pid = escapeHtml(r.project_id);
        const rsid = escapeHtml(ruleSetId);
        // Cleanup is remediation of the field itself — available regardless of migration status.
        const cleanup = `<button class="btn btn-link btn-sm sm-action-cleanup" data-pid="${pid}" title="Consolidate duplicate codes & repair value_mapping">Clean up</button>`;
        if (r.status === 'completed' || r.status === 'skipped') {
            return cleanup;
        }
        return `<button class="btn btn-link btn-sm sm-action-dryrun" data-pid="${pid}" data-rsid="${rsid}">Details</button>`
             + `<button class="btn btn-outline-success btn-sm sm-action-migrate-single" data-pid="${pid}" data-rsid="${rsid}">Migrate</button>`
             + cleanup;
    }

    function refsCell(refs, pid, ruleSetId) {
        if (!refs || !refs.total) return '<span class="text-muted">—</span>';
        return `<button class="btn btn-link btn-sm sm-refs-view sm-warning-chip"
                        data-pid="${escapeHtml(pid)}" data-rsid="${escapeHtml(ruleSetId)}">
                    ⚠ ${escapeHtml(refs.total)} <small>view</small>
                </button>`;
    }
    function fmtDate(unix) {
        if (!unix) return '';
        const d = new Date(unix * 1000);
        return d.toISOString().substring(0, 19).replace('T', ' ');
    }
    function ajax(action, payload) {
        return module.ajax(action, payload || {}).then(function (r) {
            // The PHP wrapper returns {success, result} where result is JSON-encoded.
            if (typeof r === 'string') {
                try { return JSON.parse(r); } catch (e) { return r; }
            }
            if (r && typeof r === 'object' && 'success' in r && 'result' in r) {
                try { return typeof r.result === 'string' ? JSON.parse(r.result) : r.result; }
                catch (e) { return r.result; }
            }
            return r;
        });
    }

    // ─── Tab switching ──────────────────────────────────────────────────────
    function activateTab(name) {
        $$('#smTabs .nav-link').forEach(a => a.classList.toggle('active', a.dataset.tab === name));
        $$('.sm-tab').forEach(p => p.style.display = (p.dataset.tabPanel === name ? '' : 'none'));
        // Side effects on tab open.
        if (name === 'rule-sets') loadRuleSets();
        if (name === 'preview' || name === 'run') loadRuleSetOptionsInto(['#sm-preview-rule-set', '#sm-run-rule-set']);
        if (name === 'history') loadHistory();
    }

    // ─── Tab 1: Rule Sets ──────────────────────────────────────────────────
    function loadRuleSets() {
        const body = $('#sm-rule-sets-body');
        body.innerHTML = '<tr><td colspan="6" class="text-muted">Loading…</td></tr>';
        ajax('listSiteMigrationRuleSets').then(function (rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                body.innerHTML = '<tr><td colspan="6" class="text-muted">No rule sets yet. Click "+ New Rule Set" to create one.</td></tr>';
                return;
            }
            body.innerHTML = rows.map(r => `
                <tr>
                    <td>${escapeHtml(r.id)}</td>
                    <td><strong>${escapeHtml(r.name)}</strong>${r.description ? '<br><small class="text-muted">' + escapeHtml(r.description) + '</small>' : ''}</td>
                    <td>${statusBadge(r.status)}</td>
                    <td>${r.library_index !== null ? escapeHtml(libNameFor(r.library_index)) : '<span class="text-muted">—</span>'}</td>
                    <td><small>${escapeHtml(fmtDate(r.created))}</small></td>
                    <td>
                        <button class="btn btn-link btn-sm sm-action-edit" data-id="${escapeHtml(r.id)}">Edit</button>
                        <button class="btn btn-link btn-sm sm-action-preview" data-id="${escapeHtml(r.id)}">Preview</button>
                        <button class="btn btn-link btn-sm sm-action-run" data-id="${escapeHtml(r.id)}">Run</button>
                        ${r.status === 'draft' ? `<button class="btn btn-link btn-sm text-danger sm-action-delete" data-id="${escapeHtml(r.id)}">Delete</button>` : ''}
                    </td>
                </tr>
            `).join('');
        }).catch(showAjaxError);
    }

    function libNameFor(idx) {
        const lib = (module.smConfig.libraries || []).find(l => Number(l.index) === Number(idx));
        return lib ? lib.name : ('Library ' + idx);
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('button');
        if (!btn) return;
        if (btn.classList.contains('sm-action-edit'))           return openEditor(btn.dataset.id);
        if (btn.classList.contains('sm-action-preview'))        return openPreviewFor(btn.dataset.id);
        if (btn.classList.contains('sm-action-run'))            return openRunFor(btn.dataset.id);
        if (btn.classList.contains('sm-action-delete'))         return deleteRuleSet(btn.dataset.id);
        if (btn.classList.contains('sm-action-log'))            return openProjectLog(btn.dataset.id, btn.dataset.pid || null);
        if (btn.classList.contains('sm-refs-view'))             return openRefsModal(btn.dataset.rsid, btn.dataset.pid);
        if (btn.classList.contains('sm-action-dryrun'))         return openDryRun(btn.dataset.rsid, btn.dataset.pid);
        if (btn.classList.contains('sm-action-migrate-single')) return migrateOneProject(btn.dataset.rsid, btn.dataset.pid, btn);
        if (btn.classList.contains('sm-action-cleanup'))        return openCleanup(btn.dataset.pid);
    });

    function deleteRuleSet(id) {
        if (!confirm('Delete rule set #' + id + '? Only drafts can be deleted.')) return;
        ajax('deleteSiteMigrationRuleSet', { id: Number(id) })
            .then(loadRuleSets)
            .catch(showAjaxError);
    }

    // ─── Tab 2: Rule Editor ────────────────────────────────────────────────
    function openEditor(id) {
        activateTab('editor');
        $('#sm-editor-msg').textContent = '';
        if (id && Number(id) > 0) {
            ajax('getSiteMigrationRuleSet', { id: Number(id) }).then(function (rs) {
                if (!rs) { return showError('Rule set not found.'); }
                $('#sm-rule-id').value = rs.id;
                $('#sm-name').value = rs.name || '';
                $('#sm-description').value = rs.description || '';
                $('#sm-library').value = rs.library_index || 0;
                $('#sm-status').value = rs.status || 'draft';
                $('#sm-rules-json').value = JSON.stringify(rs.rules || [], null, 2);
            }).catch(showAjaxError);
        } else {
            $('#sm-rule-id').value = 0;
            $('#sm-name').value = '';
            $('#sm-description').value = '';
            $('#sm-status').value = 'draft';
            $('#sm-rules-json').value = '[]';
        }
    }

    function validateRulesJson() {
        const raw = $('#sm-rules-json').value;
        try {
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) throw new Error('Top-level value must be an array.');
            $('#sm-editor-msg').className = 'ml-3 small text-success';
            $('#sm-editor-msg').textContent = '✓ JSON OK · ' + parsed.length + ' rule(s)';
            return parsed;
        } catch (e) {
            $('#sm-editor-msg').className = 'ml-3 small text-danger';
            $('#sm-editor-msg').textContent = 'JSON error: ' + e.message;
            return null;
        }
    }

    function saveRuleSet() {
        const rules = validateRulesJson();
        if (rules === null) return;
        const payload = {
            id: Number($('#sm-rule-id').value) || 0,
            name: $('#sm-name').value.trim(),
            description: $('#sm-description').value.trim(),
            library_index: Number($('#sm-library').value),
            rules: rules,
        };
        if (!payload.name) {
            $('#sm-editor-msg').className = 'ml-3 small text-danger';
            $('#sm-editor-msg').textContent = 'Name is required.';
            return;
        }
        ajax('saveSiteMigrationRuleSet', payload).then(function (r) {
            $('#sm-rule-id').value = r.id;
            $('#sm-editor-msg').className = 'ml-3 small text-success';
            $('#sm-editor-msg').textContent = '✓ Saved (#' + r.id + ')';
        }).catch(showAjaxError);
    }

    // Seed: May 2026 Stanford worksheet (Appendix C of SITE_MIGRATION_PLAN.md).
    const MAY_2026_SEED = [
        { id: 'merge-main-hospital',     type: 'merge',  old_sites: ['SCI-Palo Alto', 'SHC Main Hosp, Pasteur, Welch & campus/nearby clinics', 'SHC Satellite & Other'], new_site: 'Main Hospital',       primary_old_site: 'SCI-Palo Alto' },
        { id: 'merge-childrens-hospital',type: 'merge',  old_sites: ['SCI-LPCH', 'LPCH Main Hosp, Welch Rd & campus/nearby clinics', 'LPCH Satellite & Other'],         new_site: "Children's Hospital", primary_old_site: 'SCI-LPCH' },
        { id: 'merge-redwood-city',      type: 'merge',  old_sites: ['SHC Redwood City', 'SCI-Redwood City'],                                                            new_site: 'Redwood City',        primary_old_site: 'SHC Redwood City' },
        { id: 'merge-emeryville',        type: 'merge',  old_sites: ['SCI-Emeryville'],                                                                                  new_site: 'Emeryville',          primary_old_site: 'SCI-Emeryville' },
        { id: 'sunset-shc-emeryville',   type: 'sunset', old_sites: ['SHC - Emeryville'], new_site: 'Emeryville', retired_on: '2025-09-04' },
        { id: 'rename-quarry-rd',        type: 'rename', old_sites: ['Quarry Rd clinics;Hoover Pavilion'],         new_site: 'Quarry Rd clinics/Hoover Pavilion' },
        { id: 'rename-psychiatry',       type: 'rename', old_sites: ['Psychiatry: Page Mill, Porter Dr, other'],   new_site: 'Page Mill/Porter Dr' },
        { id: 'rename-tri-valley',       type: 'rename', old_sites: ['SHC Tri-Valley'],                            new_site: 'Tri-Valley' },
        { id: 'rename-south-bay',        type: 'rename', old_sites: ['SCI-South Bay'],                             new_site: 'South Bay' },
        { id: 'rename-livermore',        type: 'rename', old_sites: ['SCI - Livermore'],                           new_site: 'Livermore' },
        { id: 'keep-arastradero',        type: 'keep',   old_sites: ['1070 Arastradero'] },
        { id: 'keep-byers',              type: 'keep',   old_sites: ['Byers Eye Institute'] },
        { id: 'keep-ctru',               type: 'keep',   old_sites: ['CTRU (800 Welch Rd)'] },
        { id: 'keep-remote',             type: 'keep',   old_sites: ['Remote interactions (e.g., online/phone/survey)'] },
        { id: 'keep-lucas',              type: 'keep',   old_sites: ['Lucas Center'] },
        { id: 'keep-community',          type: 'keep',   old_sites: ['Community site'] },
        { id: 'keep-cnbi',               type: 'keep',   old_sites: ['Center for Cognitive and Neurobiological Imaging'] },
        { id: 'keep-ear-institute',      type: 'keep',   old_sites: ['Stanford Ear Institute'] },
    ];

    function seedMay2026() {
        if ($('#sm-rules-json').value.trim() && $('#sm-rules-json').value.trim() !== '[]' &&
            !confirm('Replace the current rules with the May 2026 Stanford worksheet?')) {
            return;
        }
        $('#sm-rules-json').value = JSON.stringify(MAY_2026_SEED, null, 2);
        if (!$('#sm-name').value.trim()) {
            $('#sm-name').value = 'Q2 2026 Stanford Site Restructuring';
        }
        if (!$('#sm-description').value.trim()) {
            $('#sm-description').value = 'Per Subject Study Site Worksheet (May 2026)';
        }
        validateRulesJson();
    }

    // ─── Tab 3: Preview ─────────────────────────────────────────────────────
    function openPreviewFor(id) {
        activateTab('preview');
        const sel = $('#sm-preview-rule-set');
        sel.value = String(id);
        runPreview(false);
    }

    let currentPreviewRuleSetId = null;

    function runPreview(deep) {
        const id = Number($('#sm-preview-rule-set').value);
        if (!id) { return showError('Select a rule set first.'); }
        currentPreviewRuleSetId = id;
        const body = $('#sm-preview-body');
        body.innerHTML = '<tr><td colspan="10" class="text-muted">Generating preview…</td></tr>';
        $('#sm-preview-summary').innerHTML = '';
        const action = deep ? 'previewSiteMigrationDeep' : 'previewSiteMigration';
        ajax(action, { id: id }).then(function (resp) {
            if (!resp || !resp.projects) { body.innerHTML = '<tr><td colspan="10" class="text-danger">No projects returned.</td></tr>'; return; }
            renderPreviewSummary(resp);
            renderPreviewRows(resp.projects, id);
        }).catch(showAjaxError);
    }

    function renderPreviewSummary(resp) {
        const t = resp.totals || {};
        $('#sm-preview-summary').innerHTML = `
            <span class="sm-stat"><strong>${escapeHtml(t.projects || 0)}</strong> projects</span>
            <span class="sm-stat"><strong>${escapeHtml(t.pending || 0)}</strong> pending</span>
            <span class="sm-stat"><strong>${escapeHtml(t.needs_ack || 0)}</strong> needs_ack</span>
            <span class="sm-stat"><strong>${escapeHtml(t.skipped || 0)}</strong> skipped</span>
            <span class="sm-stat"><strong>${escapeHtml(t.sitesAffected || 0)}</strong> sites affected</span>
            <span class="sm-stat"><strong>${escapeHtml(t.labelChanges || 0)}</strong> label Δ</span>
            <span class="sm-stat"><strong>${escapeHtml(t.mappingChanges || 0)}</strong> mapping Δ</span>
            <span class="sm-stat"><strong>${escapeHtml(t.newCodes || 0)}</strong> new codes</span>
            <span class="sm-stat"><strong>${escapeHtml(t.recordsToMigrate || 0)}</strong> records to migrate</span>
            <span class="sm-stat"><strong>${escapeHtml(t.codeReferences || 0)}</strong> ⚠ refs</span>
        `;
    }

    function renderPreviewRows(rows, ruleSetId) {
        const body = $('#sm-preview-body');
        if (!rows.length) { body.innerHTML = '<tr><td colspan="11" class="text-muted">No projects.</td></tr>'; return; }
        body.innerHTML = rows.map(r => `
            <tr data-pid="${escapeHtml(r.project_id)}">
                <td>${escapeHtml(r.project_id)}</td>
                <td>${escapeHtml(r.project_title)}</td>
                <td>${escapeHtml(r.sitesAffected || 0)}</td>
                <td>${escapeHtml(r.labelChanges || 0)}</td>
                <td>${escapeHtml(r.mappingChanges || 0)}</td>
                <td>${newCodesCell(r.newCodes)}</td>
                <td>${r.recordsToMigrate == null ? '<span class="text-muted">—</span>' : escapeHtml(r.recordsToMigrate)}</td>
                <td>${refsCell(r.codeReferences, r.project_id, ruleSetId)}</td>
                <td class="sm-row-status">${statusBadge(r.status)}</td>
                <td><small class="text-muted">${escapeHtml(r.note || '')}</small></td>
                <td class="sm-row-actions">${actionsCell(r, ruleSetId)}</td>
            </tr>
        `).join('');
    }

    // Code-reference modal + acknowledge
    let currentRefsContext = { ruleSetId: null, projectId: null };

    function openRefsModal(ruleSetId, projectId) {
        currentRefsContext = { ruleSetId: Number(ruleSetId), projectId: Number(projectId) };
        $('#sm-refs-title').textContent = `Code references — Project ${projectId}`;
        $('#sm-refs-status').textContent = 'Loading…';
        $('#sm-refs-body').innerHTML = '';
        $('#sm-refs-modal').style.display = '';
        ajax('getCodeReferenceDetails', { id: Number(ruleSetId), project_id: Number(projectId) })
            .then(function (rows) {
                if (!Array.isArray(rows) || !rows.length) {
                    $('#sm-refs-status').textContent = 'No references found (already cleared?).';
                    return;
                }
                $('#sm-refs-status').textContent = `${rows.length} reference(s) — review before acknowledging.`;
                $('#sm-refs-body').innerHTML = rows.map(r => `
                    <tr>
                        <td><code>${escapeHtml(r.source)}</code></td>
                        <td><small>${escapeHtml(r.table || '')}</small></td>
                        <td><small>${escapeHtml(r.row_id || '')}</small></td>
                        <td><code class="small">${escapeHtml(r.snippet || '')}</code></td>
                    </tr>
                `).join('');
            }).catch(showAjaxError);
    }

    function acknowledgeCurrentRefs() {
        const { ruleSetId, projectId } = currentRefsContext;
        if (!ruleSetId || !projectId) return;
        if (!confirm(`Acknowledge code references for Project ${projectId}? The migration will be allowed to run this project. You should already have updated any broken branching logic / alerts / filters.`)) return;
        ajax('acknowledgeProjectWarnings', { id: ruleSetId, project_id: projectId })
            .then(function () {
                $('#sm-refs-modal').style.display = 'none';
                runPreview(false);  // refresh so the row's status flips from needs_ack to pending
            }).catch(showAjaxError);
    }

    function exportCsv() {
        const id = Number($('#sm-preview-rule-set').value);
        if (!id) { return showError('Select a rule set first.'); }
        ajax('exportMigrationPreview', { id: id }).then(function (r) {
            if (!r || !r.csv) { return showError('No CSV returned.'); }
            const blob = new Blob([r.csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = r.filename || ('preview-' + id + '.csv');
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        }).catch(showAjaxError);
    }

    // ─── Per-project dry run + single-project migrate ──────────────────────

    let currentDryRunContext = { ruleSetId: null, pid: null };

    function openDryRun(ruleSetId, pid) {
        currentDryRunContext = { ruleSetId: Number(ruleSetId), pid: Number(pid) };
        $('#sm-dryrun-title').textContent = 'Dry Run — Project ' + pid;
        $('#sm-dryrun-content').innerHTML = '<span class="text-muted">Loading…</span>';
        $('#sm-dryrun-migrate').disabled = false;
        $('#sm-dryrun-modal').style.display = '';
        ajax('previewSingleMigrationProject', { id: Number(ruleSetId), project_id: Number(pid) })
            .then(function (data) {
                $('#sm-dryrun-title').textContent = 'Dry Run — '
                    + (data.project_title || 'Project ' + pid) + ' (#' + pid + ')';
                $('#sm-dryrun-content').innerHTML = renderDryRunContent(data);
                // Disable Migrate button if already completed / needs acknowledgement.
                if (data.status === 'completed' || data.status === 'skipped') {
                    $('#sm-dryrun-migrate').disabled = true;
                } else if (data.status === 'needs_ack') {
                    $('#sm-dryrun-migrate').disabled = true;
                    $('#sm-dryrun-migrate').title = 'Acknowledge code references first (in the ⚠ refs modal)';
                }
            })
            .catch(function (e) {
                $('#sm-dryrun-content').innerHTML = '<div class="text-danger">' + escapeHtml(String(e)) + '</div>';
            });
    }

    function renderDryRunContent(data) {
        if (!data.field_name) {
            return '<div class="alert alert-warning">' + escapeHtml(data.note || 'No studySites mapping configured.') + '</div>';
        }

        const deep = data.record_counts && Object.keys(data.record_counts).length > 0;
        let html = '<div class="mb-2"><strong>Field:</strong> <code>' + escapeHtml(data.field_name) + '</code>'
                 + ' &nbsp; ' + statusBadge(data.status)
                 + (deep ? '' : ' <small class="text-muted">— run “Deep Preview” for record counts</small>')
                 + '</div>';

        // 1) Plain-English summary — what actually happens in THIS project.
        const hc = renderHumanChanges(data.human_changes);
        html += '<h6 class="mt-2 sm-section-title">What will change</h6>';
        html += hc || '<p class="text-muted small">No site changes apply to this project.</p>';

        // 2) Code-reference warning — stays prominent (gates running the project).
        if (data.code_references && data.code_references.total > 0) {
            const refs = data.code_references;
            const detail = ['branching_logic','action_tags','alerts','surveys_emails','surveys_scheduler','reports','calc_fields']
                .filter(function (k) { return (refs[k] || 0) > 0; })
                .map(function (k) { return '<li>' + escapeHtml(k) + ': ' + escapeHtml(refs[k]) + '</li>'; })
                .join('');
            html += '<div class="alert alert-warning mt-3">'
                  + '<strong>⚠ ' + escapeHtml(refs.total) + ' code reference(s) found — review before migrating.</strong>'
                  + '<ul class="mt-1 mb-0 small">' + detail + '</ul>'
                  + '<div class="mt-1 small">Acknowledge this project in the <strong>⚠ Refs</strong> column of the preview table before running.</div>'
                  + '</div>';
        }

        // 3) Technical detail (raw codes / labels / record pairs) — collapsed by default.
        let tech = '';

        if (data.label_updates && data.label_updates.length > 0) {
            tech += '<h6 class="mt-3">Label Changes (' + data.label_updates.length + ')</h6>'
                  + '<table class="table table-sm"><thead><tr>'
                  + '<th>Code</th><th>Mode</th><th>Old Label</th><th>New Label</th>'
                  + '</tr></thead><tbody>'
                  + data.label_updates.map(function (lu) {
                      const modeCls = lu.mode === 'in_place' ? 'sm-badge-active' : 'sm-badge-pending';
                      return '<tr>'
                           + '<td><code>' + escapeHtml(lu.code) + '</code></td>'
                           + '<td><span class="sm-badge ' + modeCls + '">' + escapeHtml(lu.mode) + '</span></td>'
                           + '<td>' + escapeHtml(lu.old_label) + '</td>'
                           + '<td>' + escapeHtml(lu.new_label) + '</td>'
                           + '</tr>';
                  }).join('')
                  + '</tbody></table>';
        } else {
            tech += '<p class="text-muted small mt-2">No label changes planned.</p>';
        }

        if (data.code_allocations && data.code_allocations.length > 0) {
            tech += '<h6 class="mt-3">New Code Allocations (' + data.code_allocations.length + ')</h6>'
                  + '<table class="table table-sm"><thead><tr>'
                  + '<th>New Code</th><th>New Site</th><th>Reason</th>'
                  + '</tr></thead><tbody>'
                  + data.code_allocations.map(function (ca) {
                      return '<tr>'
                           + '<td><div class="sm-newcode-pill"><code>' + escapeHtml(ca.new_code) + '</code>: '
                           + escapeHtml(ca.new_site) + '</div></td>'
                           + '<td>' + escapeHtml(ca.new_site) + '</td>'
                           + '<td>' + escapeHtml(ca.reason || '') + '</td>'
                           + '</tr>';
                  }).join('')
                  + '</tbody></table>';
        }

        if (data.record_migrations && data.record_migrations.length > 0) {
            tech += '<h6 class="mt-3">Record Migrations (' + data.record_migrations.length + ' code pair(s))</h6>'
                  + '<table class="table table-sm"><thead><tr>'
                  + '<th>Old Code</th><th>→ New Code</th><th>Reason</th>'
                  + (deep ? '<th>Records</th>' : '')
                  + '</tr></thead><tbody>'
                  + data.record_migrations.map(function (rm) {
                      return '<tr>'
                           + '<td><code>' + escapeHtml(rm.old_code) + '</code></td>'
                           + '<td><code>' + escapeHtml(rm.new_code) + '</code></td>'
                           + '<td>' + escapeHtml(rm.reason || '') + '</td>'
                           + (deep ? '<td>' + escapeHtml(data.record_counts[rm.old_code] != null ? data.record_counts[rm.old_code] : '—') + '</td>' : '')
                           + '</tr>';
                  }).join('')
                  + '</tbody></table>';
        }

        html += '<details class="sm-tech-details mt-3">'
              + '<summary>Technical details (codes, labels, record pairs)</summary>'
              + '<div class="mt-2">' + tech + '</div></details>';

        return html;
    }

    function migrateOneProject(ruleSetId, pid, triggerBtn) {
        const title = triggerBtn
            ? triggerBtn.closest('tr')?.querySelector('td:nth-child(2)')?.textContent?.trim()
            : 'Project ' + pid;
        if (!confirm('Migrate ' + (title || 'project ' + pid) + ' (#' + pid + ')?\n\n'
                   + 'This will apply all planned changes. OnCore sync crons will be disabled until Finalize is called.')) {
            return;
        }
        if (triggerBtn) triggerBtn.disabled = true;
        // Also disable the Migrate button inside the dry-run modal if it's open for this project.
        if (currentDryRunContext.pid === Number(pid) && currentDryRunContext.ruleSetId === Number(ruleSetId)) {
            $('#sm-dryrun-migrate').disabled = true;
        }

        ajax('migrateSpecificProject', { id: Number(ruleSetId), project_id: Number(pid) })
            .then(function (resp) {
                // Update the preview table row in place.
                const tr = $('#sm-preview-body tr[data-pid="' + pid + '"]');
                if (tr) {
                    const statusCell = tr.querySelector('.sm-row-status');
                    if (statusCell) statusCell.innerHTML = statusBadge(resp.status);
                    const actCell = tr.querySelector('.sm-row-actions');
                    if (actCell) {
                        if (resp.status === 'completed') {
                            actCell.innerHTML = '<span class="text-success small">✓ ' + escapeHtml(resp.changesApplied) + ' changes</span>';
                        } else if (resp.status === 'failed') {
                            actCell.innerHTML = '<span class="text-danger small">✗ ' + escapeHtml(resp.error || 'failed') + '</span>';
                            if (triggerBtn) triggerBtn.disabled = false; // allow retry on failure
                        } else {
                            if (triggerBtn) triggerBtn.disabled = false;
                        }
                    }
                }
                // Update dry-run modal if it is open for this project.
                if (currentDryRunContext.pid === Number(pid)) {
                    $('#sm-dryrun-content').innerHTML += '<div class="alert alert-' + (resp.status === 'completed' ? 'success' : 'danger') + ' mt-3">'
                        + '<strong>' + escapeHtml(resp.status) + '</strong>'
                        + (resp.changesApplied ? ': ' + escapeHtml(resp.changesApplied) + ' changes applied' : '')
                        + (resp.recordsMigrated ? ', ' + escapeHtml(resp.recordsMigrated) + ' records rewritten' : '')
                        + (resp.error ? ': ' + escapeHtml(resp.error) : '')
                        + '</div>';
                    const doneHc = renderHumanChanges(resp.human_changes);
                    if (doneHc) {
                        $('#sm-dryrun-content').innerHTML +=
                            '<h6 class="mt-2 sm-section-title">Applied changes</h6>' + doneHc;
                    }
                }
            })
            .catch(function (e) {
                if (triggerBtn) triggerBtn.disabled = false;
                showAjaxError(e);
            });
    }

    // ─── Duplicate-code / value_mapping cleanup ──────────────────────────────
    let currentCleanupPid = null;

    function openCleanup(pid) {
        currentCleanupPid = Number(pid);
        $('#sm-cleanup-title').textContent = 'Clean up — Project ' + pid;
        $('#sm-cleanup-content').innerHTML = '<span class="text-muted">Analyzing…</span>';
        $('#sm-cleanup-apply').disabled = true;
        $('#sm-cleanup-modal').style.display = '';
        ajax('previewStudySiteCleanup', { project_id: Number(pid) })
            .then(function (plan) {
                $('#sm-cleanup-content').innerHTML = renderCleanupPlan(plan);
                const nothing = (!plan.codes_removed || !plan.codes_removed.length)
                              && (!plan.vmap_fixes || !plan.vmap_fixes.length)
                              && !plan.vmap_deduped;
                $('#sm-cleanup-apply').disabled = nothing;
                // Re-enable apply only when ack is satisfied.
                const ackBox = $('#sm-cleanup-ack');
                if (ackBox) {
                    $('#sm-cleanup-apply').disabled = nothing || !ackBox.checked;
                    ackBox.addEventListener('change', function () {
                        $('#sm-cleanup-apply').disabled = nothing || !ackBox.checked;
                    });
                }
            })
            .catch(function (e) {
                $('#sm-cleanup-content').innerHTML = '<div class="text-danger">' + escapeHtml(String(e)) + '</div>';
            });
    }

    function renderCleanupPlan(plan) {
        if (!plan.field_name) {
            return '<div class="alert alert-warning">' + escapeHtml(plan.note || 'No studySites mapping configured.') + '</div>';
        }
        const nDup = (plan.codes_removed || []).length;
        const nFix = (plan.vmap_fixes || []).length;
        if (!nDup && !nFix && !plan.vmap_deduped) {
            return '<div class="alert alert-success mb-0">Nothing to clean up — this field has no duplicate codes or value-mapping pollution.</div>';
        }

        let html = '<div class="mb-2 small text-muted">Field <code>' + escapeHtml(plan.field_name) + '</code></div>';

        // Reference gate (destructive removal of a referenced code).
        if (plan.requires_ack) {
            html += '<div class="alert alert-danger">'
                  + '<strong>⚠ ' + escapeHtml((plan.removed_with_refs || []).length)
                  + ' code(s) being removed are still referenced in project logic.</strong> '
                  + 'Removing them will leave those branching-logic / alert / report references dangling. '
                  + 'Fix those references first, or acknowledge to proceed anyway.'
                  + '</div>';
        }

        // Duplicate consolidations.
        if (nDup) {
            html += '<h6 class="mt-2 sm-section-title">Duplicate codes to consolidate</h6><ul class="sm-hc-lines">';
            (plan.duplicates || []).forEach(function (g) {
                const rm = (g.remove || []).map(function (r) {
                    const refWarn = r.refs > 0 ? ` <span class="text-danger">(${escapeHtml(r.refs)} ref${r.refs==1?'':'s'}!)</span>` : '';
                    return `code ${escapeHtml(r.code)}${refWarn} → ${escapeHtml(r.records)} record(s) repointed`;
                }).join('; ');
                html += `<li>“${escapeHtml(g.label)}”: keep code <code>${escapeHtml(g.canonical)}</code>, remove ${rm}</li>`;
            });
            html += '</ul>';
        }

        // value_mapping fixes.
        if (nFix || plan.vmap_deduped) {
            html += '<h6 class="mt-2 sm-section-title">Value-mapping repairs</h6><ul class="sm-hc-lines">';
            (plan.vmap_fixes || []).forEach(function (f) {
                html += `<li><span class="text-muted">[${escapeHtml(f.direction)}]</span> “${escapeHtml(f.oc)}”: rc <code>${escapeHtml(f.old_rc)}</code> → <code>${escapeHtml(f.new_rc)}</code></li>`;
            });
            if (plan.vmap_deduped) html += `<li>${escapeHtml(plan.vmap_deduped)} duplicate mapping entr${plan.vmap_deduped==1?'y':'ies'} removed</li>`;
            html += '</ul>';
        }

        // Unresolved (kept) — transparency.
        if ((plan.vmap_unresolved || []).length) {
            const u = plan.vmap_unresolved.map(function (x) { return escapeHtml(x.oc) + '→' + escapeHtml(x.rc); }).join(', ');
            html += '<details class="sm-tech-details mt-2"><summary>'
                  + escapeHtml(plan.vmap_unresolved.length) + ' mapping entr(y/ies) left unchanged (no current label match — kept for backward-compat)</summary>'
                  + '<div class="small text-muted mt-1">' + u + '</div></details>';
        }

        // Ack checkbox only when destructive-with-refs.
        if (plan.requires_ack) {
            html += '<div class="form-check mt-3"><input type="checkbox" class="form-check-input" id="sm-cleanup-ack">'
                  + '<label class="form-check-label" for="sm-cleanup-ack">I understand the referenced codes will be removed and their references left dangling.</label></div>';
        }
        return html;
    }

    function applyCleanup() {
        const pid = currentCleanupPid;
        if (!pid) return;
        const ackBox = $('#sm-cleanup-ack');
        const acknowledged = ackBox ? ackBox.checked : false;
        if (!confirm('Apply cleanup to project ' + pid + '?\n\nThis repoints records, removes duplicate field options, and repairs the value mapping. It cannot be auto-undone.')) return;
        $('#sm-cleanup-apply').disabled = true;
        ajax('applyStudySiteCleanup', { project_id: Number(pid), acknowledged: acknowledged })
            .then(function (res) {
                let msg;
                if (res.status === 'noop') {
                    msg = '<div class="alert alert-info mt-2 mb-0">Nothing to clean up.</div>';
                } else if (res.status === 'skipped') {
                    msg = '<div class="alert alert-warning mt-2 mb-0">' + escapeHtml(res.note || 'Skipped.') + '</div>';
                } else {
                    msg = '<div class="alert alert-success mt-2 mb-0"><strong>Cleanup applied.</strong> '
                        + escapeHtml(res.codesRemoved || 0) + ' duplicate code(s) removed, '
                        + escapeHtml(res.recordsRepointed || 0) + ' record(s) repointed, '
                        + escapeHtml(res.vmapFixes || 0) + ' mapping fix(es).</div>';
                }
                $('#sm-cleanup-content').innerHTML += msg;
            })
            .catch(function (e) { $('#sm-cleanup-apply').disabled = false; showAjaxError(e); });
    }

    // ─── Tab 4: Run Migration ───────────────────────────────────────────────
    let runState = { id: null, polling: false, pause: false, rows: {} };

    function openRunFor(id) {
        activateTab('run');
        const sel = $('#sm-run-rule-set');
        sel.value = String(id);
    }

    function startMigration() {
        const id = Number($('#sm-run-rule-set').value);
        if (!id) { return showError('Select a rule set first.'); }
        if (!confirm('Start migration for rule set #' + id + '? This sets migration-in-progress=true and pauses all OnCore sync crons. Merges and target-bound sunsets WILL rewrite records in redcap_data*.')) return;

        runState = { id: id, polling: true, pause: false, rows: {} };
        $('#sm-run-body').innerHTML = '';
        $('#sm-run-blocked-banner').style.display = 'none';
        $('#sm-run-blocked-banner').textContent = '';
        $('#sm-run-start').disabled = true;
        $('#sm-run-pause').disabled = false;
        $('#sm-run-finalize').disabled = true;
        ajax('startSiteMigration', { id: id }).then(function (resp) {
            if (resp && Array.isArray(resp.blocked) && resp.blocked.length > 0) {
                const banner = $('#sm-run-blocked-banner');
                banner.style.display = '';
                banner.innerHTML = `<strong>${resp.blocked.length} project(s) blocked (needs_ack):</strong>
                    they will be skipped unless their code-reference warnings are acknowledged from the Preview tab.
                    Blocked PIDs: <code>${escapeHtml(resp.blocked.join(', '))}</code>`;
            }
            updateProgress(resp);
            pollNext();
        }).catch(function (e) {
            runState.polling = false;
            $('#sm-run-start').disabled = false;
            showAjaxError(e);
        });
    }

    function pollNext() {
        if (!runState.polling) return;
        const id = runState.id;
        ajax('processNextMigrationProject', { id: id }).then(function (resp) {
            if (resp && resp.projectId !== null) {
                appendRunRow(resp);
            }
            updateProgress(resp.progress || {});
            if (runState.pause) {
                runState.polling = false;
                $('#sm-run-pause').disabled = true;
                $('#sm-run-finalize').disabled = false;
                return;
            }
            if (resp && resp.status === 'idle') {
                runState.polling = false;
                $('#sm-run-pause').disabled = true;
                $('#sm-run-finalize').disabled = false;
                return;
            }
            setTimeout(pollNext, 1500);
        }).catch(function (e) {
            runState.polling = false;
            $('#sm-run-start').disabled = false;
            showAjaxError(e);
        });
    }

    function appendRunRow(resp) {
        const tr = document.createElement('tr');
        const cls = resp.status === 'completed' ? 'table-success'
                  : resp.status === 'failed' ? 'table-danger'
                  : resp.status === 'skipped' ? 'table-warning'
                  : resp.status === 'blocked' ? 'table-info' : '';
        if (cls) tr.classList.add(cls);
        tr.innerHTML = `
            <td><strong>${escapeHtml(resp.projectTitle || '')}</strong> <small class="text-muted">(${escapeHtml(resp.projectId)})</small></td>
            <td>${statusBadge(resp.status)}</td>
            <td>${newCodesCell(resp.newCodesAllocated)}</td>
            <td>${resp.recordsMigrated == null ? '<span class="text-muted">—</span>' : escapeHtml(resp.recordsMigrated)}</td>
            <td>${escapeHtml(resp.changesApplied || 0)}</td>
            <td><small class="text-danger">${escapeHtml(resp.error || '')}</small></td>
        `;
        $('#sm-run-body').appendChild(tr);

        // Full-width detail row: plain-English summary of what changed in this project.
        const hc = renderHumanChanges(resp.human_changes);
        if (hc) {
            const tr2 = document.createElement('tr');
            tr2.classList.add('sm-run-detail');
            if (cls) tr2.classList.add(cls);
            tr2.innerHTML = `<td colspan="6">${hc}</td>`;
            $('#sm-run-body').appendChild(tr2);
        }
    }

    function updateProgress(p) {
        const total = Number(p.total || 0);
        const current = Number(p.current || 0);
        const pct = total > 0 ? Math.round(100 * current / total) : 0;
        $('#sm-progress-bar').style.width = pct + '%';
        $('#sm-progress-label').textContent = `${current} / ${total} processed`
            + (p.completed != null ? ` · ${p.completed} completed` : '')
            + (p.failed != null && p.failed > 0 ? ` · ${p.failed} failed` : '')
            + (p.skipped != null && p.skipped > 0 ? ` · ${p.skipped} skipped` : '')
            + (p.needs_ack != null && p.needs_ack > 0 ? ` · ${p.needs_ack} needs_ack` : '');
    }

    function pauseMigration() {
        if (!runState.polling) return;
        runState.pause = true;
        $('#sm-run-pause').textContent = 'Stopping after current…';
    }

    function finalizeMigration() {
        const id = runState.id || Number($('#sm-run-rule-set').value);
        if (!id) { return showError('No active migration to finalize.'); }
        if (!confirm('Finalize migration #' + id + '? This re-enables OnCore sync crons.')) return;
        ajax('finalizeMigration', { id: id }).then(function (r) {
            $('#sm-progress-label').textContent = r.finalized
                ? 'Migration finalized.'
                : 'Crons re-enabled. Rule set remains active (some projects failed or are still pending).';
            $('#sm-run-finalize').disabled = true;
            $('#sm-run-start').disabled = false;
        }).catch(showAjaxError);
    }

    // ─── Tab 5: History ─────────────────────────────────────────────────────
    function loadHistory() {
        const body = $('#sm-history-body');
        body.innerHTML = '<tr><td colspan="9" class="text-muted">Loading…</td></tr>';
        ajax('getMigrationHistory').then(function (rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                body.innerHTML = '<tr><td colspan="9" class="text-muted">No migrations yet.</td></tr>';
                return;
            }
            body.innerHTML = rows.map(r => `
                <tr>
                    <td>${escapeHtml(r.id)}</td>
                    <td><strong>${escapeHtml(r.name)}</strong></td>
                    <td>${statusBadge(r.status)}</td>
                    <td>${escapeHtml(r.completed)}</td>
                    <td>${r.failed > 0 ? '<span class="text-danger"><strong>' + escapeHtml(r.failed) + '</strong></span>' : '0'}</td>
                    <td>${escapeHtml(r.skipped)}</td>
                    <td>${escapeHtml(r.total_projects)}</td>
                    <td><small>${escapeHtml(fmtDate(r.created))}</small></td>
                    <td><button class="btn btn-link btn-sm sm-action-log" data-id="${escapeHtml(r.id)}">View Log</button></td>
                </tr>
            `).join('');
        }).catch(showAjaxError);
    }

    function openProjectLog(ruleSetId, projectId) {
        ajax('getMigrationProjectLog', { id: Number(ruleSetId), project_id: projectId ? Number(projectId) : null })
            .then(function (resp) {
                $('#sm-log-title').textContent = projectId
                    ? `Project ${projectId} — Migration #${ruleSetId}`
                    : `Migration #${ruleSetId} — all changes`;
                $('#sm-log-status').textContent = resp.status
                    ? `Status: ${resp.status.status} · Changes applied: ${resp.status.changes_applied || 0}`
                      + (resp.status.error_message ? ` · Error: ${resp.status.error_message}` : '')
                    : '';
                const rows = resp.changes || [];
                $('#sm-log-body').innerHTML = rows.length === 0
                    ? '<tr><td colspan="4" class="text-muted">No change rows logged.</td></tr>'
                    : rows.map(c => `
                        <tr>
                            <td><code>${escapeHtml(c.change_type)}</code>${c.project_id == 0 ? ' <small class="text-muted">(system)</small>' : ''}</td>
                            <td>${escapeHtml(c.field_name || '')}</td>
                            <td>${escapeHtml(c.old_value || '')}</td>
                            <td>${escapeHtml(c.new_value || '')}</td>
                        </tr>
                    `).join('');
                $('#sm-log-modal').style.display = '';
            }).catch(showAjaxError);
    }

    // ─── Shared utilities ───────────────────────────────────────────────────
    function loadRuleSetOptionsInto(selectors) {
        ajax('listSiteMigrationRuleSets').then(function (rows) {
            const html = (rows || []).map(r =>
                `<option value="${escapeHtml(r.id)}">#${escapeHtml(r.id)} — ${escapeHtml(r.name)} (${escapeHtml(r.status)})</option>`
            ).join('');
            selectors.forEach(s => {
                const el = $(s); if (!el) return;
                const cur = el.value;
                el.innerHTML = html || '<option value="">(no rule sets yet)</option>';
                if (cur && (rows || []).some(r => String(r.id) === cur)) el.value = cur;
            });
        }).catch(showAjaxError);
    }

    function showError(msg) { alert(msg); }
    function showAjaxError(e) {
        let msg = (e && e.message) ? e.message : (typeof e === 'string' ? e : 'Request failed.');
        if (e && e.responseText) msg = e.responseText;
        console.error('SiteMigration AJAX error:', e);
        alert(msg);
    }

    // ─── Init: extend the JSMO with the entry point ─────────────────────────
    Object.assign(module, {
        initSiteMigration: function () {
            // Tab nav
            $$('#smTabs .nav-link').forEach(a => {
                a.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    activateTab(this.dataset.tab);
                });
            });

            // Tab 1 toolbar
            $('#sm-new-rule-set').addEventListener('click', () => openEditor(0));
            $('#sm-reload-rule-sets').addEventListener('click', loadRuleSets);

            // Tab 2 toolbar
            $('#sm-seed-may2026').addEventListener('click', seedMay2026);
            $('#sm-validate-rules').addEventListener('click', validateRulesJson);
            $('#sm-save-rule-set').addEventListener('click', saveRuleSet);
            $('#sm-cancel-editor').addEventListener('click', () => activateTab('rule-sets'));

            // Tab 3 toolbar
            $('#sm-run-preview').addEventListener('click', () => runPreview(false));
            $('#sm-run-preview-deep').addEventListener('click', () => runPreview(true));
            $('#sm-export-csv').addEventListener('click', exportCsv);

            // Tab 4 toolbar
            $('#sm-run-start').addEventListener('click', startMigration);
            $('#sm-run-pause').addEventListener('click', pauseMigration);
            $('#sm-run-finalize').addEventListener('click', finalizeMigration);

            // Tab 5 toolbar
            $('#sm-reload-history').addEventListener('click', loadHistory);
            $('#sm-log-close').addEventListener('click', () => $('#sm-log-modal').style.display = 'none');

            // Code-references modal (Preview tab).
            $('#sm-refs-close').addEventListener('click', () => $('#sm-refs-modal').style.display = 'none');
            $('#sm-refs-ack').addEventListener('click', acknowledgeCurrentRefs);

            // Dry-run details modal (Preview tab).
            $('#sm-dryrun-close').addEventListener('click', () => $('#sm-dryrun-modal').style.display = 'none');
            $('#sm-dryrun-close-btn').addEventListener('click', () => $('#sm-dryrun-modal').style.display = 'none');
            $('#sm-dryrun-migrate').addEventListener('click', function () {
                const { ruleSetId, pid } = currentDryRunContext;
                if (!ruleSetId || !pid) return;
                migrateOneProject(ruleSetId, pid, null);
            });

            // Cleanup modal (Preview tab).
            $('#sm-cleanup-close').addEventListener('click', () => $('#sm-cleanup-modal').style.display = 'none');
            $('#sm-cleanup-close-btn').addEventListener('click', () => $('#sm-cleanup-modal').style.display = 'none');
            $('#sm-cleanup-apply').addEventListener('click', applyCleanup);

            // Start on Rule Sets tab.
            activateTab('rule-sets');
        }
    });
})();
