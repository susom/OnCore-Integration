<?php

namespace Stanford\OnCoreIntegration;

/** @var OnCoreIntegration $module */
try {

//URLS FOR SUPPORT ASSETS
    $oncore_css = $module->getUrl("assets/styles/oncore.css");
    $batch_css = $module->getUrl("assets/styles/batch_modal.css");
    $notif_css = $module->getUrl("assets/styles/notif_modal.css");
    $adjude_css = $module->getUrl("assets/styles/adjudication.css");

    $notif_js = $module->getUrl("assets/scripts/notif_modal.js");
    $oncore_js = $module->getUrl("assets/scripts/oncore.js");
    $adjudication_js = $module->getUrl("assets/scripts/adjudication_modal.js");

    $icon_ajax = $module->getUrl("assets/images/icon_ajax.gif");
    $ajax_endpoint = $module->getUrl("ajax/handler.php");

    $linked_protocol = array();
    $protocol_full = $module->getIntegratedProtocol();


    if ($protocol_full) {
        $protocol = $protocol_full["protocol"];
        $linked_protocol[] = "<div class='linked_protocol'>";
        $linked_protocol[] = "<b>Linked Protocol : </b> <span>IRB #{$protocol_full["irbNo"]} {$protocol["title"]} #{$protocol["protocolId"]}</span><br>";
        $linked_protocol[] = "<b>Library : </b> <span>{$protocol["library"]}</span><br>";
        $linked_protocol[] = "<b>Status : </b> <span>{$protocol["protocolStatus"]}</span><br/>";
        $linked_protocol[] = "</div>";
    }
    $linked_protocol = implode("\r\n", $linked_protocol);

    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

    $stanford_uit_custom_css = $module->getUrl("assets/styles/stanford_uit_custom.css");
    echo '<link rel="stylesheet" href="'.APP_PATH_CSS.'bootstrap.min.css">';
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
    <link rel="stylesheet" href="<?=$stanford_uit_custom_css?>">

    <input type="hidden" name="support-url" id="support-url"
           value="<?php echo $module->getSystemSetting('oncore-support-page-url') ?>">
    <link rel="stylesheet" href="<?= $oncore_css ?>">
    <link rel="stylesheet" href="<?= $batch_css ?>">
    <link rel="stylesheet" href="<?= $notif_css ?>">
    <link rel="stylesheet" href="<?= $adjude_css ?>">
    <?php


    //SUMMARY STATS FOR INITIAL PAGE LOAD
    $sync_summ = $module->getSyncDiffSummary();
    $total_subjects = $sync_summ["total_count"];
    $full_match_count = $sync_summ["full_match_count"];
    $match_count = $sync_summ["match_count"];
    $missing_status_count = $sync_summ['missing_oncore_status_count'];
    $partial_match_count = $sync_summ["partial_match_count"];
    $excluded_count = $sync_summ['excluded_count'];
    $oncore_count = $sync_summ["oncore_only_count"];
    $redcap_count = $sync_summ["redcap_only_count"];

    $filter_logic = $module->getMapping()->getOncoreConsentFilterLogic();
    $use_filter = "";
    if (!empty($filter_logic)) {
        $use_filter = '<label class="pull-right"><input type="checkbox" id="filter_logic" class="accept_diff" value="1"> Apply custom filter defined in Mapping page.</label>';
    }

    ?>


    <div id="oncore_mapping" class="container pull-left">
        <h3>REDCap/OnCore Interaction</h3>
        <p class="lead">Data Stored in OnCore must be synced and adjudicated periodically. The data will be pulled into
            an entity table and then matched against this projects REDCap data on the mapped fields.</p>

        <?php
        if ($module->getSystemSetting('display-alert-notification') != '') {
            ?>
            <div class="alert alert-danger"><?= $module->getSystemSetting('alert-notification') ?></div>
            <?php
        }

        if ($module->getSystemSetting('disable-functionality') == '') {
            
            if (empty($module->getUsers()->getOnCoreContact()) && !$module->isSuperUser()) {
                throw new \Exception("No OnCore Contact found for " . $module->getUser()->getUsername());
            }
            ?>

            <?= $linked_protocol ?>

            <div id="overview" class="container">
                <div id="filters">
                    <div class="d-inline-block text-center stat-group oncore_summ">
                        <div class="stat d-inline-block">
                            <p class='h1 mt-2 mb-0 p-0 oncore_only_count'><?php echo $sync_summ['oncore_only_count'] ?></p>
                            <i class="d-block">Not in REDCap</i>
                            <?php if (empty($module->getMapping()->getProjectFieldMappings()['pull'])) { ?>
                                <i class="fas fa-info-circle" data-toggle="tooltip" data-placement="bottom"
                                   title="You can`t pull OnCore Data because Pull Mapping is not defined. Please define Pull Mapping from the mapping page"></i>
                                <a href="<?php echo $module->getUrl('pages/field_map.php') ?>">Go to Field Mapping</a>
                            <?php } else { ?>
                                <i class="d-block">
                                    <button class='btn btn-primary getadjudication' data-bin='oncore' id='pull_data'>See
                                        Unlinked Subjects
                                    </button>
                                </i>
                            <?php } ?>
                        </div>

                        <div class="stat-body mt-3">
                            <b class="stat-title d-block">Total OnCore Subjects</b>
                            <i class="stat-text d-block total_oncore_count"><?php echo $sync_summ['total_oncore_count'] ?></i>
                        </div>
                    </div>

                    <div class="d-inline-block text-center stat-group all_linked">
                        <div class="stat d-inline-block">
                            <p class='h1 mt-2 mb-0 p-0 full_match_count'><?php echo $full_match_count ?></p>
                            <i class="d-block">Fully Matched</i>
                            <?php
                            $vis = $missing_status_count > 0 ? "" : "make_invis";
                            ?>
                            <div class="<?= $vis ?>">
                                <i class="fas fa-info-circle" data-toggle="tooltip" data-placement="bottom"
                                   title="<?= $missing_status_count ?> OnCore Subject<?= $missing_status_count > 1 ? "s are" : " is" ?> missing Protocol Subject Status. Please update manually from OnCore UI."></i>
                                (<span class="missing_status_count"><?= $missing_status_count ?></span>)
                            </div>
                        </div>

                        <div class="stat d-inline-block">
                            <p class='h1 mt-2 mb-0 p-0 partial_match_count'><?php echo $partial_match_count ?></p>
                            <i class="d-block">Partially Matched</i>
                            <?php if (empty($module->getMapping()->getProjectFieldMappings()['pull'])) { ?>
                                <i class="fas fa-info-circle" data-toggle="tooltip" data-placement="bottom"
                                   title="You can`t pull OnCore Data because Pull Mapping is not defined. Please define Pull Mapping from the mapping page"></i>
                                <a href="<?php echo $module->getUrl('pages/field_map.php') ?>">Go to Field Mapping</a>
                            <?php } else { ?>
                                <button class='btn btn-warning getadjudication' data-bin='partial'
                                        id='adjudicate_partials'>
                                    Adjudicate Diff
                                </button>
                            <?php } ?>
                        </div>

                        <div class="stat-body mt-3">

                            <b class="stat-title d-block">Total Subjects/Records : <span
                                        class="match_count"><?php echo $total_subjects ?></span></b>
                            <ul class="adjudication">
                                <li>Records Excluded: <span class="excluded_count"><?php echo $excluded_count ?></span>
                                </li>
                                <li>Records for adjudication: <span
                                            class="for_adjudication"><?php echo $total_subjects - $excluded_count ?></span>
                                </li>
                            </ul>

                            <button class='btn btn-success' id='refresh_sync_diff'>Refresh Synced Data</button>
                        </div>
                    </div>

                    <div class="d-inline-block text-center stat-group redcap_summ">
                        <div class="stat d-inline-block">
                            <p class='h1 mt-2 mb-0 p-0 redcap_only_count'><?php echo $sync_summ['redcap_only_count'] ?></p>
                            <i class="d-block">Not in OnCore</i>
                            <?php if (empty($module->getMapping()->getProjectFieldMappings()['push'])) { ?>
                                <i class="fas fa-info-circle" data-toggle="tooltip" data-placement="bottom"
                                   title="You can't push REDCap Records because Push Mapping is not defined. Please define Push Mapping from the mapping page"></i>
                                <a href="<?php echo $module->getUrl('pages/field_map.php') ?>">Go to Field Mapping</a>
                            <?php } else { ?>
                                <i class="d-block">
                                    <button class='btn btn-danger getadjudication' data-bin='redcap' id='push_data'>See
                                        Unlinked Records
                                    </button>
                                </i>
                            <?php } ?>
                        </div>

                        <div class="stat-body mt-3">
                            <b class="stat-title d-block">Total REDCap Records</b>
                            <i class="stat-text d-block total_redcap_count"><?php echo $sync_summ['total_redcap_count'] ?></i>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        } // end of disable functionality
        ?>
    </div>

    <!-- THIS WILL REMAIN HIDDEN -->
    <div class="tab-content">
        <div class="tab-pane" id="partial">
            <form id="syncFromOncore" class="oncore_match">
                <input type="hidden" name="matchtype" value="fullmatch"/>
                <div class="included"></div>
                <br>
                <h2>Excluded Subjects</h2>
                <p>The following subjects have been excluded from syncing with Oncore</p>
                <div class="excluded"></div>
            </form>
        </div>
        <div class="tab-pane" id="oncore">
            <form id="pullFromOncore" class="oncore_match">
                <input type="hidden" name="matchtype" value="oncoreonly"/>
                <div class="included"></div>
                <br>
                <h2>Excluded Subjects</h2>
                <p>The following subjects have been excluded from syncing with REDCap</p>
                <div class="excluded"></div>
            </form>
        </div>
        <div class="tab-pane" id="redcap">
            <form id="pushToOncore" class="oncore_match">
                <?= $use_filter ?>

                <input type="hidden" name="matchtype" value="redcaponly"/>
                <div class="included"></div>
                <br>
                <h2>Excluded Subjects</h2>
                <p>The following subjects have been excluded from syncing with Oncore</p>
                <div class="excluded"></div>
            </form>
        </div>
    </div>
    <style>
        #oncore_mapping button.loading:after {
            content: "";
            position: absolute;
            top: 0px;
            width: 25px;
            height: 25px;
            right: 5px;

            background: url(<?=$icon_ajax?>) 0 0 no-repeat;
            background-size: contain;
        }
    </style>
    <script src="<?= $adjudication_js ?>" type="text/javascript"></script>
    <script src="<?= $oncore_js ?>" type="text/javascript"></script>
    <script>
        $(document).ready(function () {
            var ajax_endpoint = "<?=$ajax_endpoint?>";
            var redcap_csrf_token = "<?=$module->getCSRFToken()?>";


            //THIS WILL QUEUE THE AJAX REQUESTS SO THEY DONT RACE CONDITION EACH OTHER
            var ajaxQueue = {
                queuedRequests: [],
                pauseFlag: false,
                addRequest: function (req) {
                    this.queuedRequests.push(req);
                    // if it's the first request, start execution
                    if (this.queuedRequests.length === 1) {
                        this.executeNextRequest();
                    }
                },
                clearQueue: function () {
                    this.queuedRequests = [];
                },
                executeNextRequest: function (subtract) {
                    var queuedRequests = this.queuedRequests;
                    var pauseFlag = this.pauseFlag;

                    if (subtract) {
                        console.log("something messed up subtract and continue");
                        queuedRequests.shift();
                    }

                    if (queuedRequests.length) {
                        queuedRequests[0]().then(function (data) {
                            // console.log("request complete", data);
                            // remove completed request from queue
                            queuedRequests.shift();
                            // if there are more requests, execute the next in line
                            if (queuedRequests.length && !pauseFlag) {
                                ajaxQueue.executeNextRequest();
                            }
                        });
                    }
                },
                pauseQueue: function () {
                    this.pauseFlag = !this.pauseFlag;
                },
                isPaused: function () {
                    return this.pauseFlag;
                }
            };


            //SHOW "no diff" MATCHES
            $("body").on("click", ".show_all_matched", function (e) {
                e.preventDefault();
                if (!$(this).hasClass('expanded')) {
                    $(".show_all_matched").html("Show Less");
                    $("tr td.rc_data, tr td.oc_data").show();
                    $(this).addClass('expanded')
                } else {
                    $(".show_all_matched").html("Show All");
                    $("tr td.rc_data:not(.showit), tr td.oc_data:not(.showit)").hide();
                    $(this).removeClass('expanded')
                }
            });

            //CHECKBOX BEHAVIOR
            $("body").on("change", ".check_all", function () {
                if ($(this).is(":checked")) {
                    //check all
                    $(this).closest("table").find(".accept_diff").prop("checked", true);
                } else {
                    //uncheck all
                    $(this).closest("table").find(".accept_diff").prop("checked", false);
                }
            });
            $("body").on("change", ".accept_diff", function () {
                if (!$(this).is(":checked")) {
                    //uncheck "check_all"
                    $(this).closest("table").find(".check_all").prop("checked", false);
                } else {
                    //if checked then re-enable the submit button (if it disabled)
                    $("#pushModal .footer_action button[disabled='disabled']").attr("disabled", false);
                }
            });

            //EXCLUDE SUBJECT FROM DIFF OVERWRITE
            $("body").on("click", ".exclude_subject, .include_subject", function (e) {
                e.preventDefault();
                var subject_mrn = $(this).data("subject_mrn");
                var entity_record_id = $(this).data("entity_id");

                var _parent_tbody = $("tbody." + subject_mrn);
                var _parent_form = _parent_tbody.closest(".oncore_match");

                var exclude_include = $(this).hasClass("exclude_subject") ? "excludeSubject" : "includeSubject";

                var _this = $(this);
                _this.addClass("loading").prop("disabled", "disabled");

                $.ajax({
                    url: ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action": exclude_include,
                        "entity_record_id": entity_record_id,
                        "redcap_csrf_token": redcap_csrf_token
                    },
                    // dataType: 'json'
                }).done(function (result) {
                    //fade the row
                    result = decode_object(result)
                    if (exclude_include == "excludeSubject") {
                        var _parent_clone = _parent_tbody.clone();
                        _parent_tbody.fadeOut("fast", function () {
                            //clone the row to exluded table
                            _parent_form.find("table.excludes thead").after(_parent_clone.first());

                            //disable clone
                            _parent_clone.find(".exclude_subject").text("Re-include").addClass("include_subject").removeClass("exclude_subject");

                            $(this).remove();
                        });
                    } else {
                        var _parent_clone = _parent_tbody.clone();
                        _parent_tbody.fadeOut("fast", function () {
                            //clone the row to exluded table
                            _parent_form.find("table.includes thead").after(_parent_clone.first());

                            //disable clone
                            _parent_clone.find(".include_subject").text("Exclude").addClass("exclude_subject").removeClass("include_subject");

                            $(this).remove();
                        });
                    }

                    _parent_clone.find(".loading").removeClass("loading").prop("disabled", false);
                    $("#refresh_sync_diff").trigger("click");
                }).fail(function (e) {
                    e.responseJSON = decode_object(e.responseText)
                    _this.removeClass("loading").prop("disabled", false);

                    var be_status = "";
                    var be_lead = "";
                    if (e.hasOwnProperty("responseJSON")) {
                        var response = JSON.parse(e.responseJSON)
                        be_status = response.hasOwnProperty("status") ? response.status + ". " : "";
                        be_lead = response.hasOwnProperty("message") ? response.message + "\r\n" : "";
                    }

                    var headline = be_status + "Record status failed to save";
                    var lead = be_lead + "Please refresh page and try again";
                    var notif = new notifModal(lead, headline);
                    notif.show();
                });
            });

            $("body").on("click", ".submit_pushpull", function (e) {
                $(".pushDATA form").submit();
            });

            //do fresh data manual pull
            $("#refresh_sync_diff").on("click", function (e) {
                e.preventDefault();
                var _this = $(this);
                _this.addClass("loading").prop("disabled", "disabled");

                $.ajax({
                    url: ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action": "syncDiff",
                        "redcap_csrf_token": redcap_csrf_token
                    },
                    //dataType: 'json'
                }).done(function (syncsummary) {
                    syncsummary = decode_object(syncsummary)
                    _this.removeClass("loading").prop("disabled", false);
                    updateOverview(syncsummary);
                }).fail(function (e) {
                    e.responseJSON = decode_object(e.responseText)
                    _this.removeClass("loading").prop("disabled", false);

                    var be_status = "";
                    var be_lead = "";
                    if (e.hasOwnProperty("responseJSON")) {
                        var response = e.responseJSON
                        be_status = response.hasOwnProperty("status") ? response.status + ". " : "";
                        be_lead = response.hasOwnProperty("message") ? response.message + "\r\n" : "";
                    }

                    var headline = be_status + "Failed to sync records";
                    var lead = be_lead + "Please try again";
                    var notif = new notifModal(lead, headline);
                    notif.show();
                });
            });

            //show hide adjudcation tables
            $(".getadjudication").click(function (e) {
                var _this = $(this);
                var bin = _this.data("bin");
                var use_filter = _this.data("use_filter") == "1" ? 1 : null;

                $(".stat-group").removeClass("picked");
                var par = _this.closest(".stat-group");

                _this.addClass("loading");
                $(".getadjudication").prop("disabled", "disabled");

                $(".tab-pane").removeClass("active");

                $.ajax({
                    url: ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action": "getSyncDiff",
                        "bin": bin,
                        "filter": use_filter,
                        "redcap_csrf_token": redcap_csrf_token,
                    },
                    //dataType: 'json'
                }).done(function (result) {

                    result = decode_object(result)
                    _this.removeClass("loading");

                    //POP OPEN THE MODAL
                    var adjModal = new adjudicationModal(bin);
                    window.adjModal = adjModal;
                    adjModal.show();

                    //THIS IS TO CONTINUE THE PROGRESS BAR UI INCASE THEY CLOSE THE MODAL AND REOPEN IT
                    //USES SESSION STORAGE TO TRACK NUMBER OF PROECESS IN AJAX QUEUe
                    if (sessionStorage.getItem("process_queue")) {
                        if (sessionStorage.getItem("process_queue") && ajaxQueue.queuedRequests.length) {
                            var ls_inprogress_processing = sessionStorage.getItem("process_queue")
                            var pullModal = window.adjModal;
                            pullModal.completedItems = ls_inprogress_processing - ajaxQueue.queuedRequests.length;
                            pullModal.totalItems = ls_inprogress_processing;
                            pullModal.showProgressUI();

                            if (ajaxQueue.isPaused()) {
                                pullModal.showContinue();
                            }
                        }
                    }

                    $(".getadjudication").prop("disabled", false);
                    par.addClass("picked");

                    $("#" + bin).addClass("active");
                    $("#" + bin).find(".included").empty().append(result["included"]);

                    if (use_filter) {
                        $("#" + bin).find("#filter_logic").attr("checked", true);
                    } else {
                        $("#" + bin).find("#filter_logic").attr("checked", false);
                    }

                    $("#" + bin).find(".excluded").empty().append(result["excluded"]);

                    $("#" + bin).clone().appendTo($("#pushModal .pushDATA"));


                    var footer_action = $(result["footer_action"]);
                    var show_all = $(result["show_all"]);
                    $("#pushModal .footer_action").append(footer_action);
                    $("#pushModal .show_all").append(show_all);

                    // $("#pushModal .show_all").prepend(footer_action.clone());
                }).fail(function (e) {
                    e.responseJSON = decode_object(e.responseText)
                    _this.removeClass("loading");
                    $(".getadjudication").prop("disabled", false);

                    var be_status = "";
                    var be_lead = "";
                    if (e.hasOwnProperty("responseJSON")) {
                        var response = e.responseJSON
                        be_status = response.hasOwnProperty("status") ? response.status + ". " : "";
                        be_lead = response.hasOwnProperty("message") ? response.message + "\r\n" : "";
                    }

                    var headline = be_status + "Failed to load adjudication records";
                    var lead = be_lead + "Please try again";
                    var notif = new notifModal(lead, headline);
                    notif.show();
                });
            });

            //oncore only
            $(document).on('click', '.submit_pullFromOnCore', function (e) {
                e.preventDefault();
                var form = $('.pushDATA').find('#pullFromOncore');
                pull(form)
            });

            // partial match
            $(document).on('click', '.submit_adjudicatePartial', function (e) {
                e.preventDefault();
                var form = $('.pushDATA').find('#syncFromOncore');
                pull(form);
            });

            // redcap only
            $(document).on('click', '.submit_pushToOnCore', function (e) {
                e.preventDefault();
                var form = $('.pushDATA').find('#pushToOncore');
                push(form);
            });

            // APPLY FILTER LOGIC FROM MAPPING PAGE (OPTIONAL)
            $(document).on('click', '#filter_logic', function (e) {
                if ($(this).is(":checked")) {
                    //ajax new set of results with filter flag
                    $("#push_data").data("use_filter", 1);
                    $("#push_data").trigger("click");
                } else {
                    //ajax full set of results
                    $("#push_data").data("use_filter", "");
                    $("#push_data").trigger("click");
                }
            });


            $(document).on('click', "#ajaxq_buttons .pause", function (e) {
                ajaxQueue.pauseQueue();
                if ($(this).hasClass("paused")) {
                    ajaxQueue.executeNextRequest();
                    $(this).removeClass("paused").html("Pause Sync");
                } else {
                    $(this).addClass("paused").html("Continue Sync");
                }
            });
            $(document).on('click', "#ajaxq_buttons .cancel", function (e) {
                if ($(".check_all").is(":checked")) {
                    $(".check_all").trigger("change");
                } else {
                    $(".accept_diff").each(function () {
                        if ($(this).is(":checked")) {
                            $(this).attr("checked", null);
                        }
                    });
                }

                $(".batch_counter").find("b").html("0");
                $(".batch_counter").find("i").html("0");

                $("#ajaxq_buttons").empty().append($("<i>")).text("Sync Canceled");

                ajaxQueue.clearQueue();
            });

            //ACCEPT ADJUDICATIONS
            function pull(form) {
                var approved_ids = [];
                form.find(".includes input[name='approved_ids']").each(function () {
                    if (this.checked) {
                        var _el = $(this);
                        var oncore_id = _el.val();
                        var rc_id = _el.data("rc_id");
                        var mrn = _el.data("mrn");
                        approved_ids.push({
                            "name": 'approved_ids',
                            'mrn': mrn,
                            "oncore": oncore_id,
                            "value": oncore_id,
                            "redcap": rc_id
                        });
                    }
                });

                if (approved_ids.length) {
                    var pullModal = window.adjModal;
                    pullModal.completedItems = 0;
                    pullModal.totalItems = approved_ids.length;
                    pullModal.showProgressUI();

                    //stuff in session storage temporarily incase close modal and ned to reopen
                    sessionStorage.setItem("process_queue", approved_ids.length);

                    var arr_length = approved_ids.length - 1;
                    for (var i in approved_ids) {
                        var _mrn = approved_ids[i]["mrn"];
                        var rc_id = approved_ids[i]["redcap"];
                        var oncore = approved_ids[i]["oncore"];
                        var temp = {"redcap": rc_id, "oncore": oncore, "mrn": _mrn}

                        //THIS IS JUST FOR SHOWMANSHIP, SO THE PROGRESS BAR "grows" AND NOT JUST APPEARS IN AN INSTANT
                        var rndInt = randomIntFromInterval(500, 1500);
                        (function (temp, rndInt, inc, tot) {
                            ajaxQueue.addRequest(function () {
                                // -- your ajax request goes here --
                                return $.ajax({
                                    url: ajax_endpoint,
                                    method: 'POST',
                                    data: {
                                        "action": "approveSync",
                                        "record": temp,
                                        "redcap_csrf_token": redcap_csrf_token
                                    }
                                }).done(function (result) {
                                    result = decode_object(result);
                                    pullModal.setRowStatus(result.id, true, result.message);

                                    //TRIGGER REFRESH SYNC AFTER LAST RECORD
                                    if (inc == tot) {
                                        $("#refresh_sync_diff").trigger("click");
                                    }
                                }).fail(function (e) {
                                    var result = decode_object(e.responseText);

                                    result.id = result.id == '' ? temp["oncore"] : result.id;
                                    pullModal.setRowStatus(result.id, false, result.message);

                                    // console.log("failure exception , restart queue?", result);
                                    ajaxQueue.executeNextRequest(1);
                                });

                                return new Promise(function (resolve, reject) {
                                    setTimeout(function () {
                                        resolve(data);
                                    }, rndInt);
                                });
                            });
                        })(temp, rndInt, i, arr_length);
                    }
                }
            };

            function push(form) {
                var approved_ids = []
                form.find(".includes input[name='approved_ids']:checked").each(function () {
                    if (this.checked) {
                        var _el = $(this);
                        var oncore_id = _el.data("oncore");
                        var rc_id = _el.val();
                        var mrn = _el.data("mrn");
                        approved_ids.push({
                            "name": 'approved_ids',
                            'mrn': mrn,
                            "oncore": oncore_id,
                            "value": rc_id,
                            "redcap": rc_id
                        });
                    }
                });

                if (approved_ids.length) {
                    var pushModal = window.adjModal;
                    pushModal.completedItems = 0;
                    pushModal.totalItems = approved_ids.length;
                    pushModal.showProgressUI();

                    //stuff in session storage temporarily incase close modal and ned to reopen
                    sessionStorage.setItem("process_queue", approved_ids.length);

                    var arr_length = approved_ids.length - 1;
                    for (var i in approved_ids) {
                        var rc_id = approved_ids[i]["value"];
                        var mrn = approved_ids[i]["mrn"];
                        var temp = {"value": rc_id, "mrn": mrn}

                        //THIS IS JUST FOR SHOWMANSHIP, SO THE PROGRESS BAR "grows" AND NOT JUST APPEARS IN AN INSTANT
                        var rndInt = randomIntFromInterval(500, 1500);

                        (function (temp, rndInt, inc, tot) {
                            ajaxQueue.addRequest(function () {
                                // -- your ajax request goes here --
                                return $.ajax({
                                    url: ajax_endpoint,
                                    method: 'POST',
                                    data: {
                                        "action": "pushToOncore",
                                        "record": temp,
                                        "redcap_csrf_token": redcap_csrf_token
                                    }
                                }).done(function (result) {
                                    result = decode_object(result);
                                    pushModal.setRowStatus(result.id, true, result.message);

                                    //TRIGGER REFRESH SYNC AFTER LAST RECORD
                                    if (inc == tot) {
                                        $("#refresh_sync_diff").trigger("click");
                                    }
                                }).fail(function (e) {
                                    var result = decode_object(e.responseText);
                                    result.id = result.id == '' ? temp["value"] : result.id;
                                    pushModal.setRowStatus(result.id, false, result.message);

                                    // console.log("failure exception , restart queue?", result);
                                    ajaxQueue.executeNextRequest(1);
                                });

                                return new Promise(function (resolve, reject) {
                                    setTimeout(function () {
                                        resolve(data);
                                    }, rndInt);
                                });
                            });
                        })(temp, rndInt, i, arr_length);
                    }
                }
            }
        });

        function updateOverview(syncsummary) {
            $("p.full_match_count").text(syncsummary["full_match_count"]);
            $("span.excluded_count").text(syncsummary["excluded_count"]);
            $("span.match_count").text(syncsummary["total_count"]);
            $("span.for_adjudication").text(syncsummary["total_count"] - syncsummary["excluded_count"]);

            $("p.partial_match_count").text(syncsummary["partial_match_count"]);

            $("p.oncore_only_count").text(syncsummary["oncore_only_count"]);
            $("i.total_oncore_count").text(syncsummary["total_oncore_count"]);

            $("p.redcap_only_count").text(syncsummary["redcap_only_count"]);
            $("i.total_redcap_count").text(syncsummary["total_redcap_count"]);

            $("span.missing_status_count").text(syncsummary["missing_oncore_status_count"]);
        };

        function randomIntFromInterval(min, max) { // min and max included
            return Math.floor(Math.random() * (max - min + 1) + min)
        }

        function escape(str) {
            str = str.replace(/&/g, "&amp;");
            str = str.replace(/>/g, "&gt;");
            str = str.replace(/</g, "&lt;");
            str = str.replace(/"/g, "&quot;");
            str = str.replace(/'/g, "&#039;");
            console.log(str)
            return str;
        }

        function unescape(s) {
            return s.replace(/&amp;/g, "&")
                .replace(/&lt;/g, "<")
                .replace(/&gt;/g, ">")
                .replace(/&#39;/g, "'")
                .replace(/&quot;/g, '"');
        }
    </script>
    <?php
} catch (\Exception $e) {
    ?>
    <div class="alert alert-danger"><?php echo $e->getMessage() . ($module->getProjectSetting('oncore-support-page-url') != '' ? ' <a target="_blank" href="' . $module->getProjectSetting('oncore-support-page-url') . '">For more information check Oncore Support Page</a>' : '') ?></div>
    <?php
}
