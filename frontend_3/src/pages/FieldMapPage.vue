<template>
    <div>
        <input type="hidden" name="support-url" id="support-url" :value="supportUrl">

        <div v-if="loading" class="alert alert-info">Loading field mapping...</div>
        <div>
            <div v-if="alertNotification" class="alert alert-danger" v-html="alertNotification"/>

            <div v-if="disableFunctionality" class="alert alert-warning">
                Module functionality is disabled.
            </div>

            <div v-else id="field_mapping">
                <h1>REDCap - OnCore Field Mapping</h1>
                <p class="lead">
                    Map REDCap Variables to OnCore Properties and vice versa to ensure that data can be both pulled and
                    pushed between the two.
                </p>

                <ul class="nav nav-tabs">
                    <li :class="{ active: activeTab === 'oncore_configuration' }">
                        <a href="#" class="oncore_config"
                           @click.prevent="setTab('oncore_configuration')">Configurations</a>
                    </li>
                    <li v-if="showPullTab" :class="{ active: activeTab === 'pull_mapping' }">
                        <a href="#" class="optional pull_mapping" :class="overallPullStatusClass"
                           @click.prevent="setTab('pull_mapping')">
                            Pull Data From OnCore <i class="fa fa-times-circle"></i><i class="fa fa-check-circle"></i>
                        </a>
                    </li>
                    <li v-if="showPushTab" :class="{ active: activeTab === 'push_mapping' }">
                        <a href="#" class="optional push_mapping" :class="overallPushStatusClass"
                           @click.prevent="setTab('push_mapping')">
                            Push Data To OnCore <i class="fa fa-times-circle"></i><i class="fa fa-check-circle"></i>
                        </a>
                    </li>
                </ul>

                <div class="tab-content pull-left">
                    <div class="tab-pane" :class="{ active: activeTab === 'oncore_configuration' }">
                        <form id="oncore_config" class="container" @submit.prevent>
                            <h2>Oncore Project Linked</h2>
                            <div v-if="linkedProtocol" class="linked_protocol">
                                <b>Linked Protocol : </b>
                                <span>IRB #{{ linkedProtocol.irbNo }} {{
                                        linkedProtocol.title
                                    }} #{{ linkedProtocol.protocolId }}</span><br>
                                <b>Library : </b> <span>{{ linkedProtocol.library }}</span><br>
                                <b>Status : </b> <span>{{ linkedProtocol.status }}</span><br>
                            </div>

                            <p class="lead">Some configurations need to be set before using this module:</p>

                            <label class="map_dir">
                                <input
                                    type="checkbox"
                                    class="pCheck"
                                    :checked="pushPullPref.includes('pull_mapping')"
                                    @change="togglePushPull('pull_mapping', $event)"
                                >
                                Do you want to PULL subject data from OnCore to REDCap
                            </label>

                            <label class="map_dir">
                                <input
                                    type="checkbox"
                                    class="pCheck"
                                    :checked="pushPullPref.includes('push_mapping')"
                                    @change="togglePushPull('push_mapping', $event)"
                                >
                                Do you want to PUSH subject data from REDCap to OnCore
                            </label>
                        </form>

                        <form id="study_sites" class="container" @submit.prevent>
                            <h2>Select subset of study sites for this project</h2>
                            <p class="lead">No selections will default to using the entire set.</p>
                            <ul>
                                <li v-for="site in studySites" :key="site">
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="site_study_subset"
                                            :value="site"
                                            :checked="projectStudySites.includes(site)"
                                            @change="toggleStudySite(site, $event)"
                                        >
                                        <span>{{ site }}</span>
                                    </label>
                                </li>
                            </ul>
                        </form>
                    </div>

                    <div class="tab-pane" :class="{ active: activeTab === 'pull_mapping' }">
                        <form id="oncore_mapping" class="container" @submit.prevent>
                            <h2>Map OnCore properties to REDCap variables to PULL</h2>
                            <p class="lead">
                                Data stored in OnCore will have a fixed nomenclature. When linking an OnCore project to
                                a REDCap project the analogous REDCap field name will need to be manually mapped and
                                stored in the project's
                                EM Settings to be able to PULL.
                            </p>

                            <label class="map_dir">
                                <input type="checkbox" class="auto_pull" :checked="autoPull" @change="toggleAutoPull">
                                Schedule an auto-pull of records once mapping is complete?
                            </label>

                            <div id="oncore_prop_selector" class="pull-right" ref="pullDropdown">
                                <button
                                    class="btn btn-success btn-lg dropdown-toggle"
                                    type="button"
                                    id="dropdownMenu2"
                                    :aria-expanded="dropdownOpen ? 'true' : 'false'"
                                    @click.stop.prevent="toggleDropdown"
                                >
                                    + Add an OnCore Property to Map
                                </button>
                                <div class="dropdown-menu" aria-labelledby="dropdownMenu2" :class="{ show: dropdownOpen }">
                                    <h6 class="dropdown-header req_hdr">Required</h6>
                                    <button
                                        v-for="field in availablePullRequired"
                                        :key="field"
                                        class="dropdown-item oncore_pull_prop"
                                        type="button"
                                        @click.prevent="addOncoreProp(field)"
                                    >
                                        {{ field }}
                                    </button>
                                    <div class="dropdown-divider"></div>
                                    <h6 class="dropdown-header no_req_hdr">Optional</h6>
                                    <button
                                        v-for="field in availablePullOptional"
                                        :key="field"
                                        class="dropdown-item oncore_pull_prop"
                                        type="button"
                                        @click.prevent="addOncoreProp(field)"
                                    >
                                        {{ field }}
                                    </button>
                                </div>
                            </div>

                            <table class="table">
                                <thead>
                                <tr>
                                    <th class="td_oc_field">OnCore Property</th>
                                    <th class="td_rc_field">REDCap Field</th>
                                    <th class="td_rc_event centered">REDCap Event</th>
                                    <th class="td_pull centered">Pull Status</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                <template v-for="field in pullFields" :key="field">
                                    <tr :class="field">
                                        <td class="oc_field">
                                            {{ field }} <i class="fas fa-angle-double-right map_arrow"></i>
                                            <em v-if="field === 'protocolSubjectId'" class="protocol-warning">
                                                <strong>*By default, it is optional; however, it becomes mandatory if
                                                    your protocol permits duplicate MRNs.</strong>
                                            </em>
                                        </td>
                                        <td class="rc_selects">
                                            <select
                                                class="form-select form-select-sm mrn_field redcap_field property_select"
                                                :class="{ ok: fieldStatus[field]?.pull }"
                                                :name="field"
                                                data-mapdir="pull"
                                                :value="getPullMappingField(field)"
                                                @change="handlePullFieldChange(field, $event)"
                                            >
                                                <option value="-99">-Map REDCap Field-</option>
                                                <optgroup v-for="(fields, eventName) in redcapEvents" :label="eventName"
                                                          :key="eventName">
                                                    <option
                                                        v-for="rcField in fields"
                                                        :key="rcField"
                                                        :value="rcField"
                                                    >
                                                        {{ rcField }}
                                                    </option>
                                                </optgroup>
                                            </select>
                                        </td>
                                        <td class="rc_event centered">{{
                                                getRedcapEvent(getPullMappingField(field))
                                            }}
                                        </td>
                                        <td class="centered status pull" :class="{ ok: fieldStatus[field]?.pull }">
                                            <i class="fa fa-times-circle"></i><i class="fa fa-check-circle"></i>
                                        </td>
                                        <td>
                                            <i
                                                v-if="field !== 'mrn'"
                                                class="fas fa-trash delete_pull_prop"
                                                :data-oncore_prop="field"
                                                :data-req="oncoreFields[field]?.required"
                                                @click.prevent="deletePullField(field)"
                                            ></i>
                                        </td>
                                    </tr>

                                    <tr v-if="shouldShowPullValueMap(field)" :class="field + ' more'">
                                        <td colspan="5">
                                            <table class="value_map table-nostriped">
                                                <thead>
                                                <tr>
                                                    <th colspan="4" class="info">
                                                        <i v-if="isPullTextField(field)">
                                                            The OnCore property has the following valid values. Mapping
                                                            to a REDCap <b>text field</b>
                                                            is valid for <b>Pulling data only</b>.
                                                        </i>
                                                        <i v-else>
                                                            The OnCore property has the following valid values. Each
                                                            must be mapped in order to Pull data from
                                                            OnCore into REDCap.
                                                        </i>
                                                    </th>
                                                </tr>
                                                <tr>
                                                    <th class="td_oc_vset">Oncore Valid Values for <b>{{ field }}</b>
                                                    </th>
                                                    <th class="td_rc_vset">Redcap Enumerated Values for
                                                        <b>{{ getPullMappingField(field) }}</b></th>
                                                    <th class="centered td_map_status">Map Status</th>
                                                    <th class="td_vset_spacer"></th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <tr v-for="(ocValue, idx) in getOncoreValues(field)" :key="ocValue">
                                                    <td>{{ ocValue }} <i
                                                        class="fas fa-angle-double-right map_arrow"></i></td>
                                                    <td>
                                                        <select
                                                            class="form-select form-select-sm redcap_value value_select"
                                                            :class="{ ok: pullValueMap(field)[ocValue] }"
                                                            :name="field + '_' + idx"
                                                            :value="pullValueMap(field)[ocValue] || '-99'"
                                                            @change="handlePullValueChange(field, ocValue, $event)"
                                                        >
                                                            <option value="-99">-Map REDCap Value-</option>
                                                            <option
                                                                v-for="(label, code) in getRedcapValues(getPullMappingField(field))"
                                                                :key="code" :value="code">
                                                                {{ code }}, {{ label }}
                                                            </option>
                                                        </select>
                                                    </td>
                                                    <td class="centered value_map_status"
                                                        :class="{ ok: pullValueMap(field)[ocValue] }">
                                                        <i class="fa fa-times-circle"></i><i
                                                        class="fa fa-check-circle"></i>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                </template>
                                </tbody>
                            </table>
                        </form>
                    </div>

                    <div class="tab-pane" :class="{ active: activeTab === 'push_mapping' }">
                        <form id="consent_logic" class="container" @submit.prevent>
                            <h2>REDCap Filtering Logic</h2>
                            <p class="lead">
                                Each REDCap project may have a unique set up. In order to push data from REDCap to
                                OnCore it may be useful
                                to filter records on the REDCap side. Save filtering logic that can be applied
                                (optional) in the Push to
                                Oncore UI:
                            </p>

                            <label class="map_dir">
                                <div style="margin:0 30px;float:right;">
                                    <a
                                        href="#"
                                        style="text-decoration:underline;font-size:11px;font-weight:normal; margin-right:20px;"
                                        @click.prevent="openHelpPopup"
                                    >
                                        How do I use special functions?
                                    </a>
                                </div>

                                <textarea
                                    id="advanced_logic"
                                    placeholder="(e.g., [age] > 30 and [sex] = '1')"
                                    name="advanced_logic"
                                    class="x-form-field notesbox"
                                    style="width:95%;height:75px;resize:auto;"
                                    v-model="consentFilterLogic"
                                    @focus="handleLogicFocus"
                                    @keydown="handleLogicKeydown"
                                    @blur="handleLogicBlur"
                                />
                                <div
                                    style="border: 0; font-weight: bold; text-align: left; vertical-align: middle; height: 20px;"
                                    id="advanced_logic_Ok"
                                >&nbsp;
                                </div>

                                <p style="margin:10px 0;">
                                    <button class="button btn btn-info" id="saveFilterLogic"
                                            @click.prevent="saveFilterLogic">Save Filter Logic
                                    </button>
                                </p>
                            </label>
                        </form>

                        <form id="redcap_mapping" class="container" @submit.prevent>
                            <h2>Map REDCap variables to Oncore properties to PUSH</h2>
                            <p class="lead">
                                Data stored in OnCore will have a fixed nomenclature. When linking an OnCore project to
                                a REDCap project the analogous REDCap field name will need to be manually mapped and
                                stored in the project's
                                EM Settings to be able to PUSH.
                            </p>
                            <table class="table">
                                <thead>
                                <tr>
                                    <th class="td_oc_field">OnCore Property</th>
                                    <th class="td_rc_field">REDCap Field</th>
                                    <th class="td_rc_event centered">REDCap Event</th>
                                    <th class="td_push centered">Push Status</th>
                                </tr>
                                </thead>
                                <tbody class="required">
                                <template v-for="field in requiredPushFields" :key="field">
                                    <tr :class="{ 'table-warning': field === 'protocolSubjectId' }">
                                        <td class="oc_field">
                                            {{ field }} <i class="fas fa-angle-double-left map_arrow"></i>
                                            <em v-if="field === 'protocolSubjectId'" class="protocol-warning">
                                                <strong>*By default, it is optional; however, it becomes mandatory if
                                                    your protocol permits duplicate MRNs.</strong>
                                            </em>
                                        </td>
                                        <td class="rc_selects">
                                            <select
                                                class="form-select form-select-sm redcap_field property_select"
                                                :class="{ ok: fieldStatus[field]?.push }"
                                                :name="field"
                                                data-mapdir="push"
                                                :value="getPushMappingField(field)"
                                                :disabled="isUsingDefault(field)"
                                                @change="handlePushFieldChange(field, $event)"
                                            >
                                                <option value="-99">-Map REDCap Field-</option>
                                                <optgroup v-for="(fields, eventName) in redcapEvents" :label="eventName"
                                                          :key="eventName">
                                                    <option
                                                        v-for="rcField in fields"
                                                        :key="rcField"
                                                        :value="rcField"
                                                    >
                                                        {{ rcField }}
                                                    </option>
                                                </optgroup>
                                            </select>
                                            <label v-if="canUseDefault(field)">
                                                <input
                                                    class="use_default"
                                                    type="checkbox"
                                                    name="use_default"
                                                    :checked="isUsingDefault(field)"
                                                    @change="toggleDefault(field, $event)"
                                                >
                                                Use Default
                                            </label>
                                        </td>
                                        <td class="rc_event centered">{{
                                                getRedcapEvent(getPushMappingField(field))
                                            }}
                                        </td>
                                        <td class="centered status push" :class="{ ok: fieldStatus[field]?.push }">
                                            <i class="fa fa-times-circle"></i><i class="fa fa-check-circle"></i>
                                        </td>
                                    </tr>

                                    <tr v-if="shouldShowPushValueMap(field)" :class="field + ' more'">
                                        <td colspan="4">
                                            <table class="value_map">
                                                <thead>
                                                <tr>
                                                    <th colspan="4" class="info">
                                                        <i>
                                                            The REDCap field selected has the following enumerated
                                                            values. Each must be mapped in order to Push data from
                                                            REDCap to OnCore.
                                                        </i>
                                                    </th>
                                                </tr>
                                                <tr>
                                                    <th class="td_oc_vset">Oncore Valid Values for <b>{{ field }}</b>
                                                    </th>
                                                    <th class="td_rc_vset">Redcap Enumerated Values for
                                                        <b>{{ getPushMappingField(field) }}</b></th>
                                                    <th class="centered td_map_status">Map Status</th>
                                                    <th class="td_vset_spacer"></th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <tr v-for="(label, code) in getRedcapValues(getPushMappingField(field))"
                                                    :key="code">
                                                    <td>
                                                        <select
                                                            class="form-select form-select-sm oncore_value value_select"
                                                            :class="{ ok: pushValueMap(field)[code] }"
                                                            :name="getPushMappingField(field) + '_' + code"
                                                            :value="pushValueMap(field)[code] || '-99'"
                                                            @change="handlePushValueChange(field, code, $event)"
                                                        >
                                                            <option value="-99">-Map OnCore Value-</option>
                                    <option v-for="(ocValue, idx) in getOncoreValues(field)"
                                            :key="idx" :value="ocValue">
                                        {{ ocValue }}
                                    </option>
                                                        </select>
                                                        <i class="fas fa-angle-double-left map_arrow"></i>
                                                    </td>
                                                    <td>{{ code }}, {{ label }}</td>
                                                    <td class="centered value_map_status"
                                                        :class="{ ok: pushValueMap(field)[code] }">
                                                        <i class="fa fa-times-circle"></i><i
                                                        class="fa fa-check-circle"></i>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>

                                    <tr v-if="showDefaultSelect(field)" :class="field + ' more'">
                                        <td colspan="4">
                                            <table class="value_map">
                                                <thead>
                                                <tr>
                                                    <th colspan="4" class="info">
                                                        <i>Choose a default OnCore value for {{ field }}.</i>
                                                    </th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <tr>
                                                    <td>
                                                        <select
                                                            class="form-select form-select-sm value_select default_select"
                                                            :name="field"
                                                            :value="getDefaultValue(field) || '-99'"
                                                            @change="saveDefaultValue(field, $event)"
                                                        >
                                                            <option value="-99">-Map OnCore Value-</option>
                                                            <option v-for="(ocValue, idx) in getOncoreValues(field)"
                                                                    :key="idx" :value="ocValue">
                                                                {{ ocValue }}
                                                            </option>
                                                        </select>
                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                </template>
                                </tbody>

                                <tfoot>
                                <tr>
                                    <td colspan="4">
                                        <button class="btn btn-secondary btn-lg" type="button" id="show_optional"
                                                @click.prevent="toggleOptional">
                                            <b>{{ showOptional ? 'Hide' : 'Show' }}</b> Optional OnCore Properties
                                        </button>
                                    </td>
                                </tr>
                                </tfoot>

                                <tbody class="opt_props" v-show="showOptional">
                                <template v-for="field in optionalPushFields" :key="field">
                                    <tr :class="field">
                                        <td class="oc_field">
                                            {{ field }} <i class="fas fa-angle-double-left map_arrow"></i>
                                        </td>
                                        <td class="rc_selects">
                                            <select
                                                class="form-select form-select-sm redcap_field property_select"
                                                :class="{ ok: fieldStatus[field]?.push }"
                                                :name="field"
                                                data-mapdir="push"
                                                :value="getPushMappingField(field)"
                                                @change="handlePushFieldChange(field, $event)"
                                            >
                                                <option value="-99">-Map REDCap Field-</option>
                                                <optgroup v-for="(fields, eventName) in redcapEvents" :label="eventName"
                                                          :key="eventName">
                                                    <option
                                                        v-for="rcField in fields"
                                                        :key="rcField"
                                                        :value="rcField"
                                                    >
                                                        {{ rcField }}
                                                    </option>
                                                </optgroup>
                                            </select>
                                        </td>
                                        <td class="rc_event centered">{{
                                                getRedcapEvent(getPushMappingField(field))
                                            }}
                                        </td>
                                        <td class="centered status push" :class="{ ok: fieldStatus[field]?.push }">
                                            <i class="fa fa-times-circle"></i><i class="fa fa-check-circle"></i>
                                        </td>
                                    </tr>
                                    <tr v-if="shouldShowPushValueMap(field)" :class="field + ' more'">
                                        <td colspan="4">
                                            <table class="value_map">
                                                <thead>
                                                <tr>
                                                    <th colspan="4" class="info">
                                                        <i>
                                                            The REDCap field selected has the following enumerated
                                                            values. Each must be mapped in order to Push data from
                                                            REDCap to OnCore.
                                                        </i>
                                                    </th>
                                                </tr>
                                                <tr>
                                                    <th class="td_oc_vset">Oncore Valid Values for <b>{{ field }}</b>
                                                    </th>
                                                    <th class="td_rc_vset">Redcap Enumerated Values for
                                                        <b>{{ getPushMappingField(field) }}</b></th>
                                                    <th class="centered td_map_status">Map Status</th>
                                                    <th class="td_vset_spacer"></th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <tr v-for="(label, code) in getRedcapValues(getPushMappingField(field))"
                                                    :key="code">
                                                    <td>
                                                        <select
                                                            class="form-select form-select-sm oncore_value value_select"
                                                            :class="{ ok: pushValueMap(field)[code] }"
                                                            :name="getPushMappingField(field) + '_' + code"
                                                            :value="pushValueMap(field)[code] || '-99'"
                                                            @change="handlePushValueChange(field, code, $event)"
                                                        >
                                                            <option value="-99">-Map OnCore Value-</option>
                                    <option v-for="(ocValue, idx) in getOncoreValues(field)"
                                            :key="idx" :value="ocValue">
                                        {{ ocValue }}
                                    </option>
                                                        </select>
                                                        <i class="fas fa-angle-double-left map_arrow"></i>
                                                    </td>
                                                    <td>{{ code }}, {{ label }}</td>
                                                    <td class="centered value_map_status"
                                                        :class="{ ok: pushValueMap(field)[code] }">
                                                        <i class="fa fa-times-circle"></i><i
                                                        class="fa fa-check-circle"></i>
                                                    </td>
                                                    <td></td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr v-if="showDefaultSelect(field)" :class="field + ' more'">
                                        <td colspan="4">
                                            <table class="value_map">
                                                <thead>
                                                <tr>
                                                    <th colspan="4" class="info">
                                                        <i>Choose a default OnCore value for {{ field }}.</i>
                                                    </th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <tr>
                                                    <td>
                                                        <select
                                                            class="form-select form-select-sm value_select default_select"
                                                            :name="field"
                                                            :value="getDefaultValue(field) || '-99'"
                                                            @change="saveDefaultValue(field, $event)"
                                                        >
                                                            <option value="-99">-Map OnCore Value-</option>
                                                            <option v-for="(ocValue, idx) in getOncoreValues(field)"
                                                                    :key="idx" :value="ocValue">
                                                                {{ ocValue }}
                                                            </option>
                                                        </select>
                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                </template>
                                </tbody>
                            </table>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="notification.show" id="blockingOverlay">
            <div id="notifModal" class="danger">
                <div class="notif_hdr"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="notif_bdy">
                    <h3 class="headline">{{ notification.headline }}</h3>
                    <div class="lead" v-html="notification.lead"/>
                </div>
                <div class="notif_ftr">
                    <button @click="closeNotification">Close</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'FieldMapPage',
    props: {
        boot: {
            type: Object,
            default: () => ({}),
        },
    },
    data() {
        return {
            loading: true,
            activeTab: 'oncore_configuration',
            showOptional: false,
            pushPullPref: [],
            oncoreFields: {},
            redcapFields: {},
            mappings: {pull: {}, push: {}},
            oncoreSubset: [],
            requiredFields: [],
            fieldStatus: {},
            studySites: [],
            projectStudySites: [],
            overallPullStatus: false,
            overallPushStatus: false,
            autoPull: false,
            consentFilterLogic: '',
            linkedProtocol: null,
            alertNotification: '',
            disableFunctionality: false,
            supportUrl: '',
            dropdownOpen: false,
            notification: {show: false, headline: 'Error', lead: 'Something failed in the last operation.'},
        };
    },
    computed: {
        showPullTab() {
            return this.pushPullPref.includes('pull_mapping');
        },
        showPushTab() {
            return this.pushPullPref.includes('push_mapping');
        },
        overallPullStatusClass() {
            return this.overallPullStatus ? 'ok' : '';
        },
        overallPushStatusClass() {
            return this.overallPushStatus ? 'ok' : '';
        },
        pullFields() {
            return this.oncoreSubset;
        },
        requiredPushFields() {
            const required = new Set(this.requiredFields);
            required.add('protocolSubjectId');
            return Array.from(required);
        },
        optionalPushFields() {
            const required = new Set(this.requiredPushFields);
            return Object.keys(this.oncoreFields).filter((field) => !required.has(field));
        },
        redcapEvents() {
            const events = {};
            Object.keys(this.redcapFields).forEach((field) => {
                const eventName = this.redcapFields[field].event_name || 'Other';
                if (!events[eventName]) {
                    events[eventName] = [];
                }
                events[eventName].push(field);
            });
            return events;
        },
        availablePullRequired() {
            return Object.keys(this.oncoreFields).filter(
                (field) => this.oncoreFields[field].required === 'true' && !this.oncoreSubset.includes(field)
            );
        },
        availablePullOptional() {
            return Object.keys(this.oncoreFields).filter(
                (field) => this.oncoreFields[field].required !== 'true' && !this.oncoreSubset.includes(field)
            );
        },
    },
    mounted() {
        this.fetchFieldMapData();
        document.addEventListener('click', this.handleDocumentClick);
    },
    beforeUnmount() {
        document.removeEventListener('click', this.handleDocumentClick);
    },
    methods: {
        toggleDropdown() {
            this.dropdownOpen = !this.dropdownOpen;
        },
        handleDocumentClick(event) {
            if (!this.dropdownOpen) {
                return;
            }
            const dropdown = this.$refs.pullDropdown;
            if (dropdown && !dropdown.contains(event.target)) {
                this.dropdownOpen = false;
            }
        },
        async fetchFieldMapData() {
            this.loading = true;
            try {
                const data = await this.postAction('getFieldMapData');
                this.oncoreFields = data.oncoreFields || {};
                this.redcapFields = data.redcapFields || {};
                this.mappings = data.mappings || {pull: {}, push: {}};
                this.oncoreSubset = data.oncoreSubset || [];
                this.pushPullPref = this.normalizePushPullPref(data.pushPullPref || []);
                this.requiredFields = data.requiredFields || [];
                this.fieldStatus = data.fieldStatus || {};
                this.overallPullStatus = !!data.overallPullStatus;
                this.overallPushStatus = !!data.overallPushStatus;
                this.autoPull = !!data.autoPull;
                this.studySites = data.studySites || [];
                this.projectStudySites = data.projectStudySites || [];
                this.consentFilterLogic = data.consentFilterLogic || '';
                this.linkedProtocol = data.linkedProtocol || null;
                this.alertNotification = data.alertNotification || '';
                this.disableFunctionality = !!data.disableFunctionality;
                this.supportUrl = data.supportUrl || '';
            } catch (error) {
                this.handleError(error);
            } finally {
                this.loading = false;
            }
        },
        normalizePushPullPref(values) {
            if (!Array.isArray(values)) {
                return [];
            }
            const normalized = values.map((value) => {
                if (value === 'pull' || value === 'pull_mapping' || value === 1 || value === '1') {
                    return 'pull_mapping';
                }
                if (value === 'push' || value === 'push_mapping' || value === 2 || value === '2') {
                    return 'push_mapping';
                }
                return value;
            });
            return Array.from(new Set(normalized.filter(Boolean)));
        },
        setTab(tab) {
            this.activeTab = tab;
            if (tab !== 'pull_mapping') {
                this.dropdownOpen = false;
            }
        },
        toggleOptional() {
            this.showOptional = !this.showOptional;
        },
        async togglePushPull(tab, event) {
            const isChecked = event.target.checked;
            const next = new Set(this.pushPullPref);

            if (!isChecked) {
                if (!window.confirm('Unchecking this checkbox will delete all mapped fields.  Do you want to proceed?')) {
                    event.target.checked = true;
                    return;
                }
                next.delete(tab);
                const pushPull = tab === 'pull_mapping' ? 'pull' : 'push';
                await this.postAction('deleteMapping', {push_pull: pushPull});
            } else {
                next.add(tab);
            }

            await this.postAction('savePushPullPref', {pushpull_pref: Array.from(next)});
            if (this.activeTab === tab) {
                this.activeTab = 'oncore_configuration';
            }
            await this.fetchFieldMapData();
        },
        async addOncoreProp(field) {
            this.dropdownOpen = false;
            await this.postAction('saveOncoreSubset', {oncore_prop: field});
            await this.fetchFieldMapData();
        },
        async deletePullField(field) {
            if (field === 'protocolSubjectId') {
                if (!window.confirm('Are you sure you want to delete Protocol Subject Id Field? Integration will not work without this field if your protocol allows duplicate MRNs.')) {
                    return;
                }
            }
            await this.postAction('deletePullField', {oncore_prop: field});
            await this.fetchFieldMapData();
        },
        async saveStudySites() {
            await this.postAction('saveSiteStudies', {site_studies_subset: this.projectStudySites});
        },
        async toggleStudySite(site, event) {
            if (event.target.checked) {
                if (!this.projectStudySites.includes(site)) {
                    this.projectStudySites.push(site);
                }
            } else {
                this.projectStudySites = this.projectStudySites.filter((item) => item !== site);
            }
            await this.saveStudySites();
        },
        async toggleAutoPull(event) {
            const flag = event.target.checked;
            this.autoPull = flag;
            await this.postAction('schedulePull', {flag});
        },
        async saveFilterLogic() {
            await this.postAction('saveFilterLogic', {filter_logic_str: this.consentFilterLogic});
        },
        getRedcapEvent(redcapField) {
            if (!redcapField || redcapField === '-99') {
                return '';
            }
            return this.redcapFields[redcapField]?.event_name || '';
        },
        getRedcapFieldType(redcapField) {
            if (!redcapField || redcapField === '-99') {
                return '';
            }
            return this.redcapFields[redcapField]?.redcap_field_type || '';
        },
        getRedcapValues(redcapField) {
            if (!redcapField || redcapField === '-99') {
                return {};
            }
            return this.redcapFields[redcapField]?.select_choices || {};
        },
        getOncoreValues(oncoreField) {
            return this.oncoreFields[oncoreField]?.oncore_valid_values || [];
        },
        getPullMappingField(oncoreField) {
            return this.mappings.pull?.[oncoreField]?.redcap_field || '-99';
        },
        getPushMappingField(oncoreField) {
            return this.mappings.push?.[oncoreField]?.redcap_field || '-99';
        },
        pullValueMap(oncoreField) {
            const mapping = this.mappings.pull?.[oncoreField]?.value_mapping || [];
            const map = {};
            mapping.forEach((entry) => {
                map[entry.oc] = entry.rc;
            });
            return map;
        },
        pushValueMap(oncoreField) {
            const mapping = this.mappings.push?.[oncoreField]?.value_mapping || [];
            const map = {};
            mapping.forEach((entry) => {
                map[entry.rc] = entry.oc;
            });
            return map;
        },
        shouldShowPullValueMap(oncoreField) {
            const rcField = this.getPullMappingField(oncoreField);
            return !!rcField && rcField !== '-99' && this.getOncoreValues(oncoreField).length && Object.keys(this.getRedcapValues(rcField)).length;
        },
        shouldShowPushValueMap(oncoreField) {
            const rcField = this.getPushMappingField(oncoreField);
            return !!rcField && rcField !== '-99' && Object.keys(this.getRedcapValues(rcField)).length;
        },
        isPullTextField(oncoreField) {
            const rcField = this.getPullMappingField(oncoreField);
            return this.getRedcapFieldType(rcField) === 'text';
        },
        canUseDefault(oncoreField) {
            return this.oncoreFields[oncoreField]?.allow_default === 'true';
        },
        isUsingDefault(oncoreField) {
            const mapping = this.mappings.push?.[oncoreField];
            if (!mapping) {
                return false;
            }
            return !!mapping.default_value;
        },
        getDefaultValue(oncoreField) {
            return this.mappings.push?.[oncoreField]?.default_value || '';
        },
        showDefaultSelect(oncoreField) {
            return this.isUsingDefault(oncoreField) && this.getOncoreValues(oncoreField).length > 0;
        },
        async handlePullFieldChange(oncoreField, event) {
            const redcapField = event.target.value;
            const fieldType = this.getRedcapFieldType(redcapField);
            const valueMap = this.pullValueMap(oncoreField);
            const mapping = {
                mapping: 'pull',
                oncore_field: oncoreField,
                redcap_field: redcapField,
                event: this.getRedcapEvent(redcapField),
                field_type: fieldType,
                value_mapping: Object.keys(valueMap).map((oc) => ({oc, rc: valueMap[oc]})),
            };
            await this.postAction('saveMapping', {field_mappings: mapping, update_oppo: true});
            await this.fetchFieldMapData();
        },
        async handlePushFieldChange(oncoreField, event) {
            const redcapField = event.target.value;
            const fieldType = this.getRedcapFieldType(redcapField);
            const valueMap = this.pushValueMap(oncoreField);
            const mapping = {
                mapping: 'push',
                oncore_field: oncoreField,
                redcap_field: redcapField,
                event: this.getRedcapEvent(redcapField),
                field_type: fieldType,
                value_mapping: Object.keys(valueMap).map((rc) => ({oc: valueMap[rc], rc})),
            };
            await this.postAction('saveMapping', {field_mappings: mapping, update_oppo: true});
            await this.fetchFieldMapData();
        },
        async handlePullValueChange(oncoreField, ocValue, event) {
            const redcapValue = event.target.value;
            const currentMap = this.pullValueMap(oncoreField);
            if (redcapValue === '-99') {
                delete currentMap[ocValue];
            } else {
                currentMap[ocValue] = redcapValue;
            }
            const mapping = {
                mapping: 'pull',
                oncore_field: oncoreField,
                redcap_field: this.getPullMappingField(oncoreField),
                event: this.getRedcapEvent(this.getPullMappingField(oncoreField)),
                field_type: this.getRedcapFieldType(this.getPullMappingField(oncoreField)),
                value_mapping: Object.keys(currentMap).map((oc) => ({oc, rc: currentMap[oc]})),
            };
            await this.postAction('saveMapping', {field_mappings: mapping, update_oppo: false});
            await this.fetchFieldMapData();
        },
        async handlePushValueChange(oncoreField, rcValue, event) {
            const ocValue = event.target.value;
            const currentMap = this.pushValueMap(oncoreField);
            if (ocValue === '-99') {
                delete currentMap[rcValue];
            } else {
                currentMap[rcValue] = ocValue;
            }
            const mapping = {
                mapping: 'push',
                oncore_field: oncoreField,
                redcap_field: this.getPushMappingField(oncoreField),
                event: this.getRedcapEvent(this.getPushMappingField(oncoreField)),
                field_type: this.getRedcapFieldType(this.getPushMappingField(oncoreField)),
                value_mapping: Object.keys(currentMap).map((rc) => ({oc: currentMap[rc], rc})),
            };
            await this.postAction('saveMapping', {field_mappings: mapping, update_oppo: false});
            await this.fetchFieldMapData();
        },
        async toggleDefault(oncoreField, event) {
            if (event.target.checked) {
                const mapping = {
                    mapping: 'push',
                    oncore_field: oncoreField,
                    use_default: 1,
                };
                await this.postAction('saveMapping', {field_mappings: mapping, update_oppo: null});
            } else {
                const mapping = {
                    mapping: 'push',
                    oncore_field: oncoreField,
                    redcap_field: '-99',
                };
                await this.postAction('saveMapping', {field_mappings: mapping, update_oppo: null});
            }
            await this.fetchFieldMapData();
        },
        async saveDefaultValue(oncoreField, event) {
            const value = event.target.value;
            const mapping = {
                mapping: 'push',
                oncore_field: oncoreField,
                default_value: value,
                use_default: 1,
            };
            await this.postAction('saveMapping', {field_mappings: mapping, update_oppo: null});
            await this.fetchFieldMapData();
        },
        openHelpPopup() {
            if (typeof window.helpPopup === 'function') {
                window.helpPopup('5', 'category_33_question_1_tab_5');
            }
        },
        handleLogicFocus(event) {
            if (typeof window.openLogicEditor === 'function') {
                window.openLogicEditor(window.$ ? window.$(event.target) : event.target);
            }
        },
        handleLogicKeydown(event) {
            if (typeof window.logicSuggestSearchTip === 'function') {
                window.logicSuggestSearchTip(event.target, event);
            }
        },
        handleLogicBlur(event) {
            if (typeof window.logicHideSearchTip === 'function') {
                window.logicHideSearchTip(event.target);
            }
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
                    throw decoded || {message: 'Request failed.'};
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
#field_mapping #oncore_prop_selector {
    position: relative;
}

#field_mapping #oncore_prop_selector .dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    display: none;
    margin-top: 6px;
}

#field_mapping #oncore_prop_selector .dropdown-menu.show {
    display: block;
}
</style>
