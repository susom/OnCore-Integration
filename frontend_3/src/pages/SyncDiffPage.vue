<template>
  <div>
    <input type="hidden" name="support-url" id="support-url" :value="supportUrl">

    <div v-if="loading" class="alert alert-info">Loading sync status...</div>
    <div>
      <div v-if="alertNotification" class="alert alert-danger" v-html="alertNotification" />

      <div v-if="disableFunctionality" class="alert alert-warning">
        Module functionality is disabled.
      </div>

      <div v-else id="oncore_mapping" class="container pull-left">
        <h3>REDCap/OnCore Interaction</h3>
        <p class="lead">
          Data Stored in OnCore must be synced and adjudicated periodically. The data will be pulled into
          an entity table and then matched against this projects REDCap data on the mapped fields.
        </p>

        <div v-if="linkedProtocol" class="linked_protocol">
          <b>Linked Protocol : </b>
          <span>IRB #{{ linkedProtocol.irbNo }} {{ linkedProtocol.title }} #{{ linkedProtocol.protocolId }}</span><br>
          <b>Library : </b> <span>{{ linkedProtocol.library }}</span><br>
          <b>Status : </b> <span>{{ linkedProtocol.status }}</span><br>
        </div>

        <div id="overview" class="container">
          <div id="filters">
            <div class="d-inline-block text-center stat-group oncore_summ" :class="{ picked: activeBin === 'oncore' }">
              <div class="stat d-inline-block">
                <p class="h1 mt-2 mb-0 p-0 oncore_only_count">{{ summary.oncoreOnlyCount }}</p>
                <i class="d-block">Not in REDCap</i>
                <template v-if="!hasPullMapping">
                  <i class="fas fa-info-circle" data-toggle="tooltip" data-placement="bottom"
                    title="You can't pull OnCore Data because Pull Mapping is not defined. Please define Pull Mapping from the mapping page"></i>
                  <a :href="fieldMapUrl">Go to Field Mapping</a>
                </template>
                <template v-else>
                  <i class="d-block">
                    <button class="btn btn-primary" :disabled="loadingBin" @click.prevent="openAdjudication('oncore')">
                      See Unlinked Subjects
                    </button>
                  </i>
                </template>
              </div>

              <div class="stat-body mt-3">
                <b class="stat-title d-block">Total OnCore Subjects</b>
                <i class="stat-text d-block total_oncore_count">{{ summary.totalOncoreCount }}</i>
              </div>
            </div>

            <div class="d-inline-block text-center stat-group all_linked" :class="{ picked: activeBin === 'partial' }">
              <div class="stat d-inline-block">
                <p class="h1 mt-2 mb-0 p-0 full_match_count">{{ summary.fullMatchCount }}</p>
                <i class="d-block">Fully Matched</i>
                <div v-if="summary.missingStatusCount > 0">
                  <i class="fas fa-info-circle" data-toggle="tooltip" data-placement="bottom"
                    :title="missingStatusTitle"></i>
                  (<span class="missing_status_count">{{ summary.missingStatusCount }}</span>)
                </div>
              </div>

              <div class="stat d-inline-block">
                <p class="h1 mt-2 mb-0 p-0 partial_match_count">{{ summary.partialMatchCount }}</p>
                <i class="d-block">Partially Matched</i>
                <template v-if="!hasPullMapping">
                  <i class="fas fa-info-circle" data-toggle="tooltip" data-placement="bottom"
                    title="You can't pull OnCore Data because Pull Mapping is not defined. Please define Pull Mapping from the mapping page"></i>
                  <a :href="fieldMapUrl">Go to Field Mapping</a>
                </template>
                <template v-else>
                  <button class="btn btn-warning" :disabled="loadingBin" @click.prevent="openAdjudication('partial')">
                    Adjudicate Diff
                  </button>
                </template>
              </div>

              <div class="stat-body mt-3">
                <b class="stat-title d-block">Total Subjects/Records : <span class="match_count">{{ summary.totalCount }}</span></b>
                <ul class="adjudication">
                  <li>Records Excluded: <span class="excluded_count">{{ summary.excludedCount }}</span></li>
                  <li>Records for adjudication: <span class="for_adjudication">{{ summary.totalCount - summary.excludedCount }}</span></li>
                </ul>

                <button class="btn btn-success" id="refresh_sync_diff" @click.prevent="refreshSyncDiff" :disabled="refreshing">
                  Refresh Synced Data
                </button>
              </div>
            </div>

            <div class="d-inline-block text-center stat-group redcap_summ" :class="{ picked: activeBin === 'redcap' }">
              <div class="stat d-inline-block">
                <p class="h1 mt-2 mb-0 p-0 redcap_only_count">{{ summary.redcapOnlyCount }}</p>
                <i class="d-block">Not in OnCore</i>
                <template v-if="!hasPushMapping">
                  <i class="fas fa-info-circle" data-toggle="tooltip" data-placement="bottom"
                    title="You can't push REDCap Records because Push Mapping is not defined. Please define Push Mapping from the mapping page"></i>
                  <a :href="fieldMapUrl">Go to Field Mapping</a>
                </template>
                <template v-else>
                  <i class="d-block">
                    <button class="btn btn-danger" :disabled="loadingBin" @click.prevent="openAdjudication('redcap')">
                      See Unlinked Records
                    </button>
                  </i>
                </template>
              </div>

              <div class="stat-body mt-3">
                <b class="stat-title d-block">Total REDCap Records</b>
                <i class="stat-text d-block total_redcap_count">{{ summary.totalRedcapCount }}</i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="modalVisible" id="blockingOverlay">
      <div id="pushModal">
        <h2 class="pushHDR">
          <span>{{ modalHeader }}</span><button type="button" class="close" @click="closeModal">×</button>
        </h2>

        <div class="pushBDY">
          <p class="lead pull-left">{{ modalLead }}</p>
          <span class="show_all pull-right">
            <button v-if="showAllToggle" class="btn btn-info" @click.prevent="toggleShowAll">
              {{ showAll ? 'Show Less' : 'Show All' }}
            </button>
          </span>

          <div class="modal_progress" :class="{ 'is-visible': showProgress }" v-show="showProgress">
            <div class="batch_counter"><em>{{ progressLabel }}</em> <b>{{ completedItems }}</b>/<i>{{ totalItems }}</i></div>
            <div id="pbar_box"><span id="pbar" :style="{ width: progressPercent }"></span></div>
            <div id="ajaxq_buttons">
              <em class="pull-left">*Due to asynchronous nature of this operation, pausing/canceling may not be exact.</em>
              <button class="pause btn btn-small btn-warning" @click.prevent="togglePause">{{ pauseLabel }}</button>
              <button class="cancel btn btn-small btn-danger" @click.prevent="cancelQueue">Cancel Sync</button>
            </div>
            <label>Errors:</label>
            <textarea id="modal_msg" disabled="disabled" v-model="errorLog"></textarea>
          </div>
        </div>

        <div class="pushDATA">
          <form class="oncore_match" @submit.prevent>
            <label v-if="activeBin === 'redcap' && hasFilterLogic" class="pull-right">
              <input type="checkbox" id="filter_logic" class="accept_diff" v-model="filterLogicEnabled" @change="openAdjudication('redcap')">
              Apply custom filter defined in Mapping page.
            </label>

            <div class="included">
              <table class="table table-striped includes">
                <thead>
                  <tr>
                    <th class="import"><input type="checkbox" class="check_all" v-model="checkAll" @change="toggleAll"></th>
                    <th>Subject Details</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>OnCore Property</th>
                    <th v-if="showOncoreData">OnCore Data</th>
                    <th v-if="showRedcapData">REDCap Data</th>
                  </tr>
                </thead>
                <tbody v-for="subject in includedSubjects" :key="subjectKey(subject)">
                  <tr v-for="(row, idx) in subject.rows" :key="rowKey(subject, row, idx)" :class="rowClass(row)" v-show="rowVisible(row)">
                    <template v-if="idx === 0">
                      <td class="import" :rowspan="subject.rows.length">
                        <input type="checkbox" class="accept_diff" :value="approvalId(subject)" v-model="selectedIds">
                      </td>
                      <td class="rc_id" :rowspan="subject.rows.length" :class="{ 'missing-status': !subject.oc_status }">
                        <div>MRN : {{ subject.mrn }}</div>
                        <div v-if="subject.rc_id">
                          REDCap ID : <a :href="subject.rc_url" target="_blank">{{ subject.rc_id }}</a>
                        </div>
                        <div v-if="subject.oc_pr_id">
                          OnCore Subject ID : <a :href="subject.oc_url" target="_blank">{{ subject.oc_pr_id }}</a>
                        </div>
                        <div v-if="subject.oc_status">OnCore Subject Status : {{ subject.oc_status }}</div>
                        <div v-else class="text-danger"><strong>OnCore Subject Status : NULL(Assign status from OnCore UI)</strong></div>
                        <button class="btn btn-sm btn-danger" @click.prevent="toggleExclude(subject)">
                          {{ subjectExcludedLabel(subject) }}
                        </button>
                      </td>
                      <td class="adj_status" :rowspan="subject.rows.length" :data-status_rowid="approvalId(subject)">
                        {{ rowStatuses[approvalId(subject)] || '' }}
                      </td>
                      <td class="adj_notes" :rowspan="subject.rows.length" :data-note_rowid="approvalId(subject)">
                        {{ rowNotes[approvalId(subject)] || '' }}
                      </td>
                    </template>
                    <td class="oc_data oc_field" :class="{ showit: row.diff }">{{ row.oc_alias }}</td>
                    <td v-if="showOncoreData" class="oc_data data" :class="{ showit: row.diff }">{{ row.oc_data }}</td>
                    <td v-if="showRedcapData" class="rc_data data" :class="{ showit: row.diff }">{{ row.rc_data }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <br>
            <h2>Excluded Subjects</h2>
            <p>The following subjects have been excluded from syncing with Oncore</p>
            <div class="excluded">
              <table class="table table-striped excludes">
                <thead>
                  <tr>
                    <th class="import">All</th>
                    <th>Subject Details</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>OnCore Property</th>
                    <th v-if="showOncoreData">OnCore Data</th>
                    <th v-if="showRedcapData">REDCap Data</th>
                  </tr>
                </thead>
                <tbody v-for="subject in excludedSubjects" :key="subjectKey(subject) + '-excluded'">
                  <tr v-for="(row, idx) in subject.rows" :key="rowKey(subject, row, idx)" :class="rowClass(row)" v-show="rowVisible(row)">
                    <template v-if="idx === 0">
                      <td class="import" :rowspan="subject.rows.length"></td>
                      <td class="rc_id" :rowspan="subject.rows.length">
                        <div>MRN : {{ subject.mrn }}</div>
                        <div v-if="subject.rc_id">
                          REDCap ID : <a :href="subject.rc_url" target="_blank">{{ subject.rc_id }}</a>
                        </div>
                        <div v-if="subject.oc_pr_id">
                          OnCore Subject ID : <a :href="subject.oc_url" target="_blank">{{ subject.oc_pr_id }}</a>
                        </div>
                        <button class="btn btn-sm btn-danger" @click.prevent="toggleExclude(subject)">
                          {{ subjectExcludedLabel(subject) }}
                        </button>
                      </td>
                      <td class="adj_status" :rowspan="subject.rows.length"></td>
                      <td class="adj_notes" :rowspan="subject.rows.length"></td>
                    </template>
                    <td class="oc_data oc_field" :class="{ showit: row.diff }">{{ row.oc_alias }}</td>
                    <td v-if="showOncoreData" class="oc_data data" :class="{ showit: row.diff }">{{ row.oc_data }}</td>
                    <td v-if="showRedcapData" class="rc_data data" :class="{ showit: row.diff }">{{ row.rc_data }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </form>
        </div>

        <div class="pushFTR">
          <div class="footer_action">
            <div v-if="footerMessage" class="alert alert-warning" v-html="footerMessage"></div>
            <button v-if="footerButton" class="btn btn-success" @click.prevent="submitAction">{{ footerButton }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="notification.show" id="blockingOverlay">
      <div id="notifModal" class="danger">
        <div class="notif_hdr"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="notif_bdy">
          <h3 class="headline">{{ notification.headline }}</h3>
          <div class="lead" v-html="notification.lead"></div>
        </div>
        <div class="notif_ftr"><button @click="closeNotification">Close</button></div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'SyncDiffPage',
  props: {
    boot: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      loading: true,
      refreshing: false,
      loadingBin: false,
      summary: {
        totalCount: 0,
        fullMatchCount: 0,
        partialMatchCount: 0,
        oncoreOnlyCount: 0,
        redcapOnlyCount: 0,
        totalOncoreCount: 0,
        totalRedcapCount: 0,
        excludedCount: 0,
        missingStatusCount: 0,
      },
      linkedProtocol: null,
      hasPullMapping: false,
      hasPushMapping: false,
      hasFilterLogic: false,
      overallPullStatus: false,
      overallPushStatus: false,
      canPush: true,
      projectStudySitesEmpty: true,
      alertNotification: '',
      disableFunctionality: false,
      supportUrl: '',
      modalVisible: false,
      activeBin: '',
      includedSubjects: [],
      excludedSubjects: [],
      showAll: false,
      showProgress: false,
      totalItems: 0,
      completedItems: 0,
      paused: false,
      cancelled: false,
      errorLog: '',
      rowStatuses: {},
      rowNotes: {},
      selectedIds: [],
      checkAll: true,
      filterLogicEnabled: false,
      notification: { show: false, headline: 'Error', lead: 'Something failed in the last operation.' },
    };
  },
  computed: {
    fieldMapUrl() {
      return this.boot.fieldMapUrl || '#';
    },
    missingStatusTitle() {
      const count = this.summary.missingStatusCount;
      const suffix = count > 1 ? 's are' : ' is';
      return `${count} OnCore Subject${suffix} missing Protocol Subject Status. Please update manually from OnCore UI.`;
    },
    showAllToggle() {
      return this.activeBin !== 'redcap';
    },
    progressPercent() {
      if (!this.totalItems) {
        return '0%';
      }
      return `${Math.round((this.completedItems / this.totalItems) * 100)}%`;
    },
    pauseLabel() {
      return this.paused ? 'Continue Sync' : 'Pause Sync';
    },
    progressLabel() {
      if (this.totalItems && this.completedItems >= this.totalItems) {
        return 'Completed!';
      }
      return '';
    },
    modalHeader() {
      if (this.activeBin === 'oncore') {
        return 'Pull OnCore subjects into REDCap';
      }
      if (this.activeBin === 'redcap') {
        return 'Push REDCap records into OnCore';
      }
      if (this.activeBin === 'partial') {
        return 'Adjudicate Partial Matches (Diff)';
      }
      return '';
    },
    modalLead() {
      if (this.activeBin === 'oncore') {
        return 'The following Subjects were found in the OnCore Protocol but not in the REDCap project.';
      }
      if (this.activeBin === 'redcap') {
        return 'The following REDCap records have an MRN not found in the OnCore Protocol.';
      }
      if (this.activeBin === 'partial') {
        return 'There was an MRN match between REDCap and OnCore, but the data is mis-matched. Choose to accept OnCore data (as the source of truth).';
      }
      return '';
    },
    showOncoreData() {
      return this.activeBin !== 'redcap';
    },
    showRedcapData() {
      return this.activeBin !== 'oncore';
    },
    footerButton() {
      if (this.activeBin === 'oncore') {
        return this.overallPullStatus ? 'Accept Oncore Data' : null;
      }
      if (this.activeBin === 'partial') {
        return this.overallPullStatus ? 'Accept Oncore Data' : null;
      }
      if (this.activeBin === 'redcap') {
        return this.canPush ? 'Push REDCap data to OnCore' : null;
      }
      return null;
    },
    footerMessage() {
      if (this.activeBin === 'redcap') {
        if (!this.canPush) {
          return "You can't push REDCap records to OnCore Protocol.";
        }
        if (!this.overallPushStatus) {
          if (this.projectStudySitesEmpty) {
            return "You can't push REDCap records Subjects. To push you must define push fields on <a href='" + this.fieldMapUrl + "'>mapping page</a>.";
          }
          return 'Push fields are not defined or incomplete. You can only push MRN exists in OnCore or OnStage(<strong>**A Study site still need to be mapped and selected. Or pick a default Study site from mapping page.</strong>).';
        }
      }
      if ((this.activeBin === 'oncore' || this.activeBin === 'partial') && !this.overallPullStatus) {
        return "You can't pull OnCore Subjects. To pull you must define pull fields on <a href='" + this.fieldMapUrl + "'>mapping page</a>.";
      }
      return '';
    },
  },
  mounted() {
    this.fetchMeta();
    this.fetchSummary();
  },
  watch: {
    selectedIds() {
      this.checkAll = this.selectedIds.length === this.includedSubjects.length;
    },
    includedSubjects() {
      this.checkAll = this.selectedIds.length === this.includedSubjects.length;
    },
  },
  methods: {
    async fetchMeta() {
      try {
        const data = await this.postAction('getSyncDiffMeta');
        this.linkedProtocol = data.linkedProtocol || null;
        this.hasPullMapping = !!data.hasPullMapping;
        this.hasPushMapping = !!data.hasPushMapping;
        this.hasFilterLogic = !!data.hasFilterLogic;
        this.overallPullStatus = !!data.overallPullStatus;
        this.overallPushStatus = !!data.overallPushStatus;
        this.canPush = !!data.canPush;
        this.projectStudySitesEmpty = !!data.projectStudySitesEmpty;
        this.alertNotification = data.alertNotification || '';
        this.disableFunctionality = !!data.disableFunctionality;
        this.supportUrl = data.supportUrl || '';
      } catch (error) {
        this.handleError(error);
      }
    },
    async fetchSummary() {
      this.loading = true;
      try {
        const data = await this.postAction('getSyncDiffSummary');
        this.summary = {
          totalCount: data.total_count || 0,
          fullMatchCount: data.full_match_count || 0,
          partialMatchCount: data.partial_match_count || 0,
          oncoreOnlyCount: data.oncore_only_count || 0,
          redcapOnlyCount: data.redcap_only_count || 0,
          totalOncoreCount: data.total_oncore_count || 0,
          totalRedcapCount: data.total_redcap_count || 0,
          excludedCount: data.excluded_count || 0,
          missingStatusCount: data.missing_oncore_status_count || 0,
        };
      } catch (error) {
        this.handleError(error);
      } finally {
        this.loading = false;
      }
    },
    async refreshSyncDiff() {
      this.refreshing = true;
      try {
        const data = await this.postAction('syncDiff');
        this.summary = {
          totalCount: data.total_count || 0,
          fullMatchCount: data.full_match_count || 0,
          partialMatchCount: data.partial_match_count || 0,
          oncoreOnlyCount: data.oncore_only_count || 0,
          redcapOnlyCount: data.redcap_only_count || 0,
          totalOncoreCount: data.total_oncore_count || 0,
          totalRedcapCount: data.total_redcap_count || 0,
          excludedCount: data.excluded_count || 0,
          missingStatusCount: data.missing_oncore_status_count || 0,
        };
      } catch (error) {
        this.handleError(error);
      } finally {
        this.refreshing = false;
      }
    },
    async openAdjudication(bin) {
      this.loadingBin = true;
      this.activeBin = bin;
      this.rowStatuses = {};
      this.rowNotes = {};
      this.selectedIds = [];
      this.checkAll = true;
      this.showAll = false;

      const filter = bin === 'redcap' && this.filterLogicEnabled ? 1 : null;
      try {
        const data = await this.postAction('getSyncDiffData', { bin, filter });
        this.includedSubjects = data.included || [];
        this.excludedSubjects = data.excluded || [];
        this.selectedIds = this.includedSubjects.map((subject) => this.approvalId(subject));
        this.modalVisible = true;
      } catch (error) {
        this.handleError(error);
      } finally {
        this.loadingBin = false;
      }
    },
    closeModal() {
      this.modalVisible = false;
    },
    toggleShowAll() {
      this.showAll = !this.showAll;
    },
    rowClass(row) {
      if (this.activeBin === 'partial' && !this.showAll && !row.diff) {
        return 'match';
      }
      return row.diff ? 'diff showit' : 'match';
    },
    rowVisible(row) {
      if (this.activeBin === 'partial' && !this.showAll) {
        return !!row.diff;
      }
      return true;
    },
    subjectKey(subject) {
      return `${subject.mrn}-${subject.entity_id || ''}`;
    },
    rowKey(subject, row, idx) {
      return `${this.subjectKey(subject)}-${row.oc_field}-${idx}`;
    },
    approvalId(subject) {
      if (this.activeBin === 'redcap') {
        return subject.rc_id;
      }
      return subject.oc_pr_id;
    },
    subjectExcludedLabel(subject) {
      const isExcluded = this.excludedSubjects.some((item) => item.entity_id === subject.entity_id);
      return isExcluded ? 'Re-Include' : 'Exclude';
    },
    toggleAll() {
      if (this.checkAll) {
        this.selectedIds = this.includedSubjects.map((subject) => this.approvalId(subject));
      } else {
        this.selectedIds = [];
      }
    },
    async toggleExclude(subject) {
      const isExcluded = this.excludedSubjects.some((item) => item.entity_id === subject.entity_id);
      const action = isExcluded ? 'includeSubject' : 'excludeSubject';
      try {
        await this.postAction(action, { entity_record_id: subject.entity_id });
        await this.openAdjudication(this.activeBin);
        await this.fetchSummary();
      } catch (error) {
        this.handleError(error);
      }
    },
    async submitAction() {
      if (this.activeBin === 'redcap') {
        await this.pushRecords();
      } else {
        await this.pullRecords();
      }
    },
    async pullRecords() {
      const ids = this.selectedIds.slice();
      if (!ids.length) {
        return;
      }
      this.startProgress(ids.length);
      for (const id of ids) {
        if (this.cancelled) {
          break;
        }
        await this.waitIfPaused();
        try {
          const record = this.findRecordByApprovalId(id);
          await this.postAction('approveSync', { record: { redcap: record.rc_id, oncore: record.oc_pr_id, mrn: record.mrn } });
          this.setRowStatus(id, true, 'Record synced successfully!');
        } catch (error) {
          this.setRowStatus(id, false, error.message || 'Failed');
        }
      }
      await this.refreshSyncDiff();
    },
    async pushRecords() {
      const ids = this.selectedIds.slice();
      if (!ids.length) {
        return;
      }
      this.startProgress(ids.length);
      for (const id of ids) {
        if (this.cancelled) {
          break;
        }
        await this.waitIfPaused();
        try {
          const record = this.findRecordByApprovalId(id);
          await this.postAction('pushToOncore', { record: { value: record.rc_id, mrn: record.mrn } });
          this.setRowStatus(id, true, 'Record pushed successfully!');
        } catch (error) {
          this.setRowStatus(id, false, error.message || 'Failed');
        }
      }
      await this.refreshSyncDiff();
    },
    findRecordByApprovalId(id) {
      return this.includedSubjects.find((subject) => this.approvalId(subject) === id) || {};
    },
    startProgress(total) {
      this.totalItems = total;
      this.completedItems = 0;
      this.errorLog = '';
      this.paused = false;
      this.cancelled = false;
      this.showProgress = true;
    },
    async waitIfPaused() {
      while (this.paused) {
        await new Promise((resolve) => setTimeout(resolve, 200));
      }
    },
    togglePause() {
      this.paused = !this.paused;
    },
    cancelQueue() {
      this.cancelled = true;
      this.paused = false;
      this.showProgress = false;
      this.selectedIds = [];
      this.checkAll = false;
    },
    setRowStatus(id, status, message) {
      this.completedItems += 1;
      this.rowStatuses[id] = status ? 'ok' : 'failed';
      if (!status) {
        this.logMessage(message);
      }
      this.rowNotes[id] = message;
    },
    logMessage(msg) {
      const clean = String(msg).replace(/<\/?[^>]+(>|$)/g, ' ');
      this.errorLog = this.errorLog ? `${this.errorLog}\n${clean}` : clean;
    },
    appendParams(params, key, value) {
      if (value === null || value === undefined) {
        return;
      }
      if (Array.isArray(value)) {
        value.forEach((item, index) => this.appendParams(params, `${key}[${index}]`, item));
        return;
      }
      if (typeof value === 'object') {
        Object.keys(value).forEach((childKey) => {
          this.appendParams(params, `${key}[${childKey}]`, value[childKey]);
        });
        return;
      }
      params.append(key, value);
    },
    postAction(action, payload = {}) {
      const params = new URLSearchParams();
      params.append('action', action);
      if (this.boot.csrfToken) {
        this.appendParams(params, 'redcap_csrf_token', this.boot.csrfToken);
      }
      Object.keys(payload).forEach((key) => {
        this.appendParams(params, key, payload[key]);
      });

      return fetch(this.boot.ajaxEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: params.toString(),
      }).then(async (response) => {
        const text = await response.text();
        const decoded = this.decodeObject(text);
        if (!response.ok) {
          throw decoded || { message: 'Request failed.' };
        }
        return decoded;
      });
    },
    decodeObject(value) {
      try {
        if (typeof value === 'object') {
          return value;
        }
        const txt = document.createElement('textarea');
        txt.innerHTML = value || '';
        const decoded = txt.value.replace(/[\n\r\t\s]+/g, ' ');
        return JSON.parse(decoded);
      } catch (error) {
        return null;
      }
    },
    handleError(error) {
      const message = error && error.message ? error.message : 'Please refresh the page and try again.';
      const support = this.supportUrl ? `<br><a target="_blank" href="${this.supportUrl}">For more information check Oncore Support Page</a>` : '';
      this.notification = {
        show: true,
        headline: 'Error',
        lead: `${message}${support}`,
      };
    },
    closeNotification() {
      this.notification.show = false;
    },
  },
};
</script>

<style>
#pushModal {
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
    border-radius: 8px;
    overflow: hidden;
}

#pushModal .pushHDR {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f5f7fb;
    border-bottom: 1px solid #e2e6ef;
}

#pushModal .pushBDY {
    padding: 10px 0 0;
}

#pushModal .pushBDY .lead {
    width: auto;
    max-width: calc(100% - 120px);
}

#pushModal .modal_progress.is-visible {
    display: block;
    margin: 10px 15px 0;
    padding: 10px 0 5px;
    background: #f7f8fa;
    border: 1px solid #e2e6ef;
    border-radius: 6px;
}

#pushModal #pbar_box {
    margin-top: 8px;
}

#pushModal .pushDATA {
    margin: 10px 0 0;
}

#pushModal .pushFTR {
    background: #f9fafc;
}

@media (max-width: 768px) {
    #pushModal .pushBDY .lead {
        max-width: 100%;
    }
    #pushModal .show_all {
        width: auto;
    }
}
</style>
