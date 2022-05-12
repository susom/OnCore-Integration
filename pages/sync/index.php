<?php

namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */
?>

<!-- Add this to <head> -->

<!-- Load required Bootstrap and BootstrapVue CSS -->
<!--    <link type="text/css" rel="stylesheet" href="//unpkg.com/bootstrap/dist/css/bootstrap.min.css"/>-->
<!--<link type="text/css" rel="stylesheet" href="//unpkg.com/bootstrap-vue@latest/dist/bootstrap-vue.min.css"/>-->
<link rel="stylesheet"
      href="https://uit.stanford.edu/sites/all/themes/open_framework/packages/bootstrap-2.3.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">
<!-- Load polyfills to support older browsers -->
<script src="//polyfill.io/v3/polyfill.min.js?features=es2015%2CIntersectionObserver"
        crossorigin="anonymous"></script>
<link href="https://unpkg.com/bootstrap@4.6.1/dist/css/bootstrap.min.css" rel="stylesheet"/>
<!-- Load Vue followed by BootstrapVue -->
<!--    <script src="//unpkg.com/vue@latest/dist/vue.min.js"></script>-->
<script src="https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.js"></script>

<script src="//unpkg.com/bootstrap-vue@latest/dist/bootstrap-vue.min.js"></script>

<!-- Load the following for BootstrapVueIcons support -->
<script src="//unpkg.com/bootstrap-vue@latest/dist/bootstrap-vue-icons.min.js"></script>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-beta.1/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-beta.1/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mdbvue/lib/index.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/axios/0.18.0/axios.js"></script>
<script src="https://unpkg.com/vue-select@latest"></script>
<link rel="stylesheet" href="https://unpkg.com/vue-select@latest/dist/vue-select.css">

<div id="app">
    <b-container>
        <b-row>
            <h3>REDCap/OnCore Interaction</h3>
        </b-row>
        <b-row>
            <p class="lead">Data Stored in OnCore must be synced and adjudicated periodically. The data will be pulled
                into an entity table and then matched against this projects REDCap data on the mapped fields.</p>
        </b-row>
        <b-table-simple striped hover>
            <b-thead>
                <b-tr>
                    <b-th>Last Scanned</span></b-th>
                    <b-th>Total Subjects</b-th>
                    <b-th>Full Matches</b-th>
                    <b-th>Partial Matches</b-th>
                    <b-th></b-th>
                </b-tr>
            </b-thead>
            <b-tbody>
                <b-tr>
                    <b-td>{{summary.last_scan}}</span></b-td>
                    <b-td>{{summary.total_subjects}}</b-td>
                    <b-td>{{summary.full_match_total}}</b-td>
                    <b-td>{{summary.partial_match_total}}</b-td>
                    <b-td>
                        <b-button size="sm" variant="danger" @click="syncProject()">Update
                            Refresh Sync Data
                        </b-button>
                    </b-td>
                </b-tr>
            </b-tbody>
        </b-table-simple>
        <div>{{test}}</div>
    </b-container>
</div>

<script>
    new Vue({
        el: "#app",
        created() {
            axios.interceptors.request.use((config) => {
                // trigger 'loading=true' event here
                this.showNoneDismissibleAlert = false
                this.showDismissibleAlert = false
                ajaxCalls.push(config)
                if (this.isLoading != undefined) {
                    this.isLoading = true
                }
                this.isDisabled = true
                return config;
            }, (error) => {
                // trigger 'loading=false' event here
                this.isLoading = false
                return Promise.reject(error);
            });

            axios.interceptors.response.use((response) => {
                // trigger 'loading=false' event here
                var temp = []
                temp = ajaxCalls.pop()
                if (ajaxCalls.length === 0) {
                    this.isLoading = false
                }
                this.isDisabled = false
                return response;
            }, (error) => {
                // trigger 'loading=false' event here
                this.isLoading = false
                return Promise.reject(error);
            });
        },
        data() {
            return {
                test: 'Hello World!',
                isLoading: false,
                isDisabled: false,
                ajaxURL: <?php echo $module->getUrl("ajax/handler.php"); ?>,
                summary: {
                    last_scan: '',
                    total_subjects: 0,
                    full_match_total: 0,
                    partial_match_total: 0
                }
            }
        },
        methods: {
            syncProject: function () {
                axios.get(this.ajaxUserTicketURL)
                    .then(response => {
                        this.items = this.allItems = response.data.data;
                        this.totalRows = this.items.length
                        if (this.items.length == undefined || this.items.length == 0) {
                            this.emptyTicketsTable = 'No Tickets Found'
                        }
                        this.filterTickets()
                    });
            }
        }
    });
</script>
