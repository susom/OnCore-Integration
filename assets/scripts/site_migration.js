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
        }[status] || 'sm-badge-draft';
        return `<span class="sm-badge ${cls}">${escapeHtml(status || '')}</span>`;
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
        if (btn.classList.contains('sm-action-edit'))    return openEditor(btn.dataset.id);
        if (btn.classList.contains('sm-action-preview')) return openPreviewFor(btn.dataset.id);
        if (btn.classList.contains('sm-action-run'))     return openRunFor(btn.dataset.id);
        if (btn.classList.contains('sm-action-delete'))  return deleteRuleSet(btn.dataset.id);
        if (btn.classList.contains('sm-action-log'))     return openProjectLog(btn.dataset.id, btn.dataset.pid || null);
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

    function runPreview(deep) {
        const id = Number($('#sm-preview-rule-set').value);
        if (!id) { return showError('Select a rule set first.'); }
        const body = $('#sm-preview-body');
        body.innerHTML = '<tr><td colspan="8" class="text-muted">Generating preview…</td></tr>';
        $('#sm-preview-summary').innerHTML = '';
        const action = deep ? 'previewSiteMigrationDeep' : 'previewSiteMigration';
        ajax(action, { id: id }).then(function (resp) {
            if (!resp || !resp.projects) { body.innerHTML = '<tr><td colspan="8" class="text-danger">No projects returned.</td></tr>'; return; }
            renderPreviewSummary(resp);
            renderPreviewRows(resp.projects);
        }).catch(showAjaxError);
    }

    function renderPreviewSummary(resp) {
        const t = resp.totals || {};
        $('#sm-preview-summary').innerHTML = `
            <span class="sm-stat"><strong>${escapeHtml(t.projects || 0)}</strong> projects</span>
            <span class="sm-stat"><strong>${escapeHtml(t.pending || 0)}</strong> pending</span>
            <span class="sm-stat"><strong>${escapeHtml(t.skipped || 0)}</strong> skipped</span>
            <span class="sm-stat"><strong>${escapeHtml(t.sitesAffected || 0)}</strong> sites affected</span>
            <span class="sm-stat"><strong>${escapeHtml(t.labelChanges || 0)}</strong> label changes</span>
            <span class="sm-stat"><strong>${escapeHtml(t.mappingChanges || 0)}</strong> mapping changes</span>
            <span class="sm-stat"><strong>${escapeHtml(t.recordsAffected || 0)}</strong> records (deep)</span>
        `;
    }

    function renderPreviewRows(rows) {
        const body = $('#sm-preview-body');
        if (!rows.length) { body.innerHTML = '<tr><td colspan="8" class="text-muted">No projects.</td></tr>'; return; }
        body.innerHTML = rows.map(r => `
            <tr>
                <td>${escapeHtml(r.project_id)}</td>
                <td>${escapeHtml(r.project_title)}</td>
                <td>${escapeHtml(r.sitesAffected || 0)}</td>
                <td>${escapeHtml(r.labelChanges || 0)}</td>
                <td>${escapeHtml(r.mappingChanges || 0)}</td>
                <td>${escapeHtml(r.recordsAffected || 0)}</td>
                <td>${statusBadge(r.status)}</td>
                <td><small class="text-muted">${escapeHtml(r.note || '')}</small></td>
            </tr>
        `).join('');
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
        if (!confirm('Start migration for rule set #' + id + '? This sets migration-in-progress=true and pauses all OnCore sync crons.')) return;

        runState = { id: id, polling: true, pause: false, rows: {} };
        $('#sm-run-body').innerHTML = '';
        $('#sm-run-start').disabled = true;
        $('#sm-run-pause').disabled = false;
        $('#sm-run-finalize').disabled = true;
        ajax('startSiteMigration', { id: id }).then(function (resp) {
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
                  : resp.status === 'skipped' ? 'table-warning' : '';
        if (cls) tr.classList.add(cls);
        tr.innerHTML = `
            <td><strong>${escapeHtml(resp.projectTitle || '')}</strong> <small class="text-muted">(${escapeHtml(resp.projectId)})</small></td>
            <td>${statusBadge(resp.status)}</td>
            <td>${escapeHtml(resp.changesApplied || 0)}</td>
            <td><small class="text-danger">${escapeHtml(resp.error || '')}</small></td>
        `;
        $('#sm-run-body').appendChild(tr);
    }

    function updateProgress(p) {
        const total = Number(p.total || 0);
        const current = Number(p.current || 0);
        const pct = total > 0 ? Math.round(100 * current / total) : 0;
        $('#sm-progress-bar').style.width = pct + '%';
        $('#sm-progress-label').textContent = `${current} / ${total} processed`
            + (p.completed != null ? ` · ${p.completed} completed` : '')
            + (p.failed != null && p.failed > 0 ? ` · ${p.failed} failed` : '')
            + (p.skipped != null && p.skipped > 0 ? ` · ${p.skipped} skipped` : '');
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

            // Start on Rule Sets tab.
            activateTab('rule-sets');
        }
    });
})();
