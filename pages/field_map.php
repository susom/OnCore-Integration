<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

//URLS FOR SUPPORT ASSETS
$oncore_css             = $module->getUrl("assets/styles/field_mapping.css");
$notif_css              = $module->getUrl("assets/styles/notif_modal.css");

$notif_js               = $module->getUrl("assets/scripts/notif_modal.js");

$icon_ajax              = $module->getUrl("assets/images/icon_ajax.gif");
$ajax_endpoint          = $module->getUrl("ajax/handler.php");

//INITIAL SET UP FOR MAPPING PAGE STATE PULL AND PUSH SIDE
$mapping                = $module->getMapping();
$project_oncore_subset  = $mapping->getProjectOncoreSubset();

$pushpull_pref          = $mapping->getProjectPushPullPref();
$overall_pull_status    = $mapping->getOverallPullStatus() ? "ok" : "";
$overall_push_status    = $mapping->getOverallPushStatus() ? "ok" : "";

$field_map_ui           = $mapping->makeFieldMappingUI();
$oncore_fields          = $field_map_ui["oncore_fields"];
$pull_html              = $field_map_ui["pull"];
$push_html              = $field_map_ui["push"]["required"];
$push_html_optional     = $field_map_ui["push"]["optional"];


//ONCORE STUDY SITE SUBSET SELECTION
$study_sites            = $module->getUsers()->getOnCoreStudySites();
$project_study_sites    = $mapping->getProjectSiteStudies();
$site_selection         = array();
$site_selection[]       = "<ul>\r\n";
foreach($study_sites as $site){
    $checked            = in_array($site, $project_study_sites) ? "checked" : "";
    $site_selection[]   = "<li><label><input type='checkbox' $checked name='site_study_subset' value='$site'><span>$site</span></label></li>\r\n";
}
$site_selection[]       = "</ul>\r\n";


//ONCORE PROPERTY DROP DOWN PICKER FOR PULL SIDE
$oncore_props   = $mapping->getOnCoreFieldDefinitions();
$req_field      = [];
$opt_field      = [];
foreach($oncore_props as $field => $props){
    if(in_array($field, $project_oncore_subset)){
        continue;
    }
    $req        = $props["required"];
    $selection  = "<button class='dropdown-item oncore_pull_prop' type='button' data-val='$field'>$field</button>";
    if($req == "true"){
        $req_field[] = $selection;
    }else{
        $opt_field[] = $selection;
    }
    $checked            = in_array($field, $project_oncore_subset) ? "checked" : "";
    $field_selection[]  = "<li><label><input type='checkbox' $checked name='oncore_field_subset' value='$field'><span>$field</span></label></li>\r\n";
}
$bs_dropdown    = array();
$bs_dropdown[]  = '<div class="dropdown-menu" aria-labelledby="dropdownMenu2">';
$bs_dropdown[]  = '<h6 class="dropdown-header req_hdr">Required</h6>';
$bs_dropdown[]  = implode("\r\n",$req_field);
$bs_dropdown[]  = '<div class="dropdown-divider"></div>';
$bs_dropdown[]  = '<h6 class="dropdown-header no_req_hdr">Optional</h6>';
$bs_dropdown[]  = implode("\r\n",$opt_field);
$bs_dropdown[]  = '</div>';
$pull_oncore_prop_dd = implode("\r\n",$bs_dropdown);
?>
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/open_framework/packages/bootstrap-2.3.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">
<link rel="stylesheet" href="<?=$oncore_css?>">
<link rel="stylesheet" href="<?= $notif_css ?>">

<div id="field_mapping">
    <h1>REDCap - OnCore Field Mapping</h1>
    <p class="lead">Map REDCap Variables to OnCore Properties and vice versa to ensure that data can be both pulled and pushed between the two.</p>
    <ul class="nav nav-tabs">
        <li class="active"><a data-toggle="tab" href="#oncore_config" class="oncore_config">Configurations</a></li>
        <li class=""><a data-toggle="tab" href="#pull_mapping" class="optional pull_mapping <?=$overall_pull_status?>">Pull Data From OnCore <i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></a></li>
        <li class=""><a data-toggle="tab" href="#push_mapping" class="optional push_mapping <?=$overall_push_status?>">Push Data To OnCore <i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></a></li>
    </ul>
    <div class="tab-content pull-left">
        <div class="tab-pane active" id="oncore_config">
            <form id="oncore_config" class="container">
                <h2>Oncore Project Linked</h2>
                <p class="lead">Some configurations need to be set before using this module:</p>

                <label class="map_dir">
                    <input type="checkbox" class="pCheck" data-tab="pull_mapping" value="1"> Do you want to PULL subject data from OnCore to REDCap
                </label>

                <label class="map_dir">
                    <input type="checkbox" class="pCheck" data-tab="push_mapping" value="2"> Do you want to PUSH subject data from REDCap to OnCore
                </label>
            </form>
            <form id="study_sites" class="container">
                <h2>Select subset of study sites for this project</h2>
                <p class="lead">No selections will default to using the entire set.</p>
                <?=implode("", $site_selection) ?>
            </form>
        </div>

        <div class="tab-pane" id="pull_mapping">
            <form id="oncore_mapping" class="container">
                <h2>Map OnCore properties to REDCap variables to PULL</h2>
                <p class="lead">Data stored in OnCore will have a fixed nomenclature. When linking an OnCore project to a REDCap
                    project the analogous REDCap field name will need to be manually mapped and stored in the project's EM
                    Settings to be able to PULL.</p>

                <div id="oncore_prop_selector" class="pull-right">
                    <button class="btn btn-success btn-lg dropdown-toggle" type="button" id="dropdownMenu2" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        + Add an OnCore Property to Map
                    </button>
                    <?=$pull_oncore_prop_dd?>
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
                    <?= $pull_html ?>
                    </tbody>
                </table>
            </form>
        </div>

        <div class="tab-pane" id="push_mapping">
            <form id="redcap_mapping" class="container">
                <h2>Map REDCap variables to Oncore properties to PUSH</h2>
                <p class="lead">Data stored in OnCore will have a fixed nomenclature. When linking an OnCore project to a REDCap
                    project the analogous REDCap field name will need to be manually mapped and stored in the project's EM
                    Settings to be able to PUSH.</p>
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
                    <?= $push_html ?>
                    </tbody>

                    <tfoot>
                    <tr><td colspan="4"><button class="btn btn-secondary btn-lg " type="button" id="show_optional" ><b>Show</b> Optional OnCore Properties</button></td></tr>
                    </tfoot>
                    <tbody class="opt_props">
                    <?= $push_html_optional ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
</div>
<style>
#field_mapping .loading::after{
    content:"";
    background:url(<?=$icon_ajax?>) 0 0 no-repeat;
    position:absolute;
    margin-left:10px; width:30px; height:30px;
    background-size:contain;
}

#field_mapping #study_sites .loading::after {
    top: 12px;
    right: 25px;
}
</style>
<script src="<?= $notif_js ?>" type="text/javascript"></script>
<script>
    $(document).ready(function () {
        var ajax_endpoint = "<?=$ajax_endpoint?>";
        //var oncore_fields = <?//=json_encode($oncore_fields)?>//;
        var oncore_fields = decode_object("<?=htmlentities(json_encode($oncore_fields, JSON_THROW_ON_ERROR)); ?>");
        //var pushpull_pref = <?//=json_encode($pushpull_pref)?>//;
        var pushpull_pref = decode_object("<?=htmlentities(json_encode($pushpull_pref, JSON_THROW_ON_ERROR)); ?>");
        var redcap_csrf_token = "<?=$module->getCSRFToken()?>";

        //THIS WILL QUEUE THE AJAX REQUESTS SO THEY DONT RACE CONDITION EACH OTHER
        var ajaxQueue = {
            queuedRequests: [],
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
            executeNextRequest: function () {
                var queuedRequests = this.queuedRequests;
                // console.log("request started");
                queuedRequests[0]().then(function (data) {
                    // console.log("request complete", data);
                    // remove completed request from queue
                    queuedRequests.shift();
                    // if there are more requests, execute the next in line
                    if (queuedRequests.length) {
                        ajaxQueue.executeNextRequest();
                    }
                });
            }
        };

        //SUPERFICIAL UI
        oncoreConfigVis(pushpull_pref);
        $("#field_mapping .opt_props").hide();
        $("#show_optional").click(function(e){
            e.preventDefault();

            if($("#field_mapping .opt_props").is(":visible")){
                $("#field_mapping .opt_props").slideUp();
                $(this).find("b").text("Show");
            }else{
                $("#field_mapping .opt_props").slideDown();
                $(this).find("b").text("Hide");
            }
        });

        //TAB BEHAVIOR
        $("#field_mapping ul.nav-tabs a").on("click", function(){
            $("li.active").removeClass("active");
            $(this).parent("li").addClass("active");
        });
        $(".pCheck").click(function(e){
            var tab_state = [];
            $(".pCheck:checked").each(function(){
                var tab = $(this).data("tab");
                tab_state.push(tab);
            });

            if(!$(this).is(":checked")){
                var tab = $(this).data("tab");

                if (confirm("Unchecking this checkbox will delete all mapped fields.  Do you want to proceed?") == true) {
                    //FIRE OFF AJAX TO CLEAR OUT RESPECTIVE TABs MAPPINGS
                    var push_pull = tab == "pull_mapping" ? "pull" : "push";
                    ajaxQueue.addRequest(function () {
                        // -- your ajax request goes here --
                        return $.ajax({
                            url: ajax_endpoint,
                            method: 'POST',
                            data: {
                                "action": "deleteMapping",
                                "push_pull": push_pull,
                                "redcap_csrf_token": redcap_csrf_token
                            },
                            //dataType: 'json'
                        }).done(function (result) {
                            //done
                            result = decode_object(result)
                            console.log("deleteMapping done");
                        }).fail(function (e) {
                            e.responseJSON = decode_object(e.responseText)
                            var be_status = "";
                            var be_lead = "";
                            if (e.hasOwnProperty("responseJSON")) {
                                be_status = e.responseJSON.hasOwnProperty("status") ? e.responseJSON.status + ". " : "";
                                be_lead = e.responseJSON.hasOwnProperty("message") ? e.responseJSON.message + "\r\n" : "";
                            }

                            var headline = be_status;
                            var lead = be_lead;
                            var notif = new notifModal(lead, headline);
                        });

                        return new Promise(function (resolve, reject) {
                            setTimeout(function () {
                                resolve(data);
                            }, 1000);
                        });
                    });
                } else {
                    return false;
                }
            }

            oncoreConfigVis();
            ajaxQueue.addRequest(function () {
                // -- your ajax request goes here --
                return $.ajax({
                    url: ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action": "savePushPullPref",
                        "pushpull_pref": tab_state,
                        "redcap_csrf_token": redcap_csrf_token
                    },
                    // dataType: 'json'
                }).done(function (result) {
                    //done
                    result = decode_object(result)
                    console.log("savePushPullPref done");
                }).fail(function (e) {
                    e.responseJSON = decode_object(e.responseText)
                    var be_status = "";
                    var be_lead = "";
                    if (e.hasOwnProperty("responseJSON")) {
                        be_status = e.responseJSON.hasOwnProperty("status") ? e.responseJSON.status + ". " : "";
                        be_lead = e.responseJSON.hasOwnProperty("message") ? e.responseJSON.message + "\r\n" : "";
                    }

                    var headline = be_status;
                    var lead = be_lead;
                    var notif = new notifModal(lead, headline);
                    notif.show();
                });

                return new Promise(function (resolve, reject) {
                    setTimeout(function () {
                        resolve(data);
                    }, 2000);
                });
            });
        });

        //SOME RESPONSIVE UI TO SEE
        //ON SELECT OF TOP LEVEL REDCAP FIELD
        $("#oncore_mapping, #redcap_mapping").on("change","select.redcap_field", function(){
            if($(this).find("option:selected")){
                var _opt            = $(this).find("option:selected");
                var map_direction   = $(this).data("mapdir") == "push" ? "push" : "pull";
                var is_rc_mapping   = map_direction == "push" ? 1 : 0;
                var redcap_field    = _opt.val();
                var oncore_field    = $(this).attr("name");
                var event_name      = _opt.data("eventname");
                var ftype           = _opt.data("type");
                var vmaps           = _opt.data("val_mapping");

                var no_refresh      = $(this).hasClass("second_level_trigger");
                var _select         = $(this);

                //UI UPDATE
                var _update_oppo    = true;
                $("select.redcap_field[name='"+oncore_field+"']").each(function(){
                    var temp_map_direction   = $(this).data("mapdir") == "push" ? "push" : "pull";

                    if(map_direction !== temp_map_direction){
                        //THIS MEANS THIS ITERATION IS THE OPPOSITE NUMBER SO WE MUST BE CAREFUL
                        if( $(this).find(":checked").length && $(this).find(":checked").val() !== "-99"){
                            _update_oppo = false;
                            return false;
                        }
                    }

                    if(redcap_field !== "-99"){
                        $(this).find("option[value='"+redcap_field+"']").prop("selected","selected");
                        if($(this).closest("tr").find(".rc_event").length){
                            $(this).closest("tr").find(".rc_event").empty();
                            $(this).closest("tr").find(".rc_event").html(event_name);
                        }
                    }else{
                        if(map_direction == temp_map_direction){
                            if($(this).closest("tr").find(".rc_event").length){
                                $(this).closest("tr").find(".rc_event").empty();
                                $(this).closest("tr").find(".rc_event").html(event_name);
                            }
                        }
                    }
                });

                var value_mapping   = [];
                if(vmaps){
                    if(map_direction == "pull"){
                        for(var oncore_value in vmaps){
                            var redcap_mapping = vmaps[oncore_value];
                            value_mapping.push({"oc" : oncore_value, "rc" : redcap_mapping});
                        }
                    }else{
                        for(var redcap_mapping in vmaps){
                            var oncore_value = vmaps[redcap_mapping];
                            value_mapping.push({"oc" : oncore_value, "rc" : redcap_mapping});
                        }
                    }
                }

                var field_maps = {
                      "mapping" : map_direction
                    , "oncore_field": oncore_field
                    , "redcap_field": redcap_field
                    , "event": event_name
                    , "field_type" : ftype
                    , "value_mapping" : value_mapping
                };

                $(this).parent().addClass("loading");

                disableSelects();

                ajaxQueue.addRequest(function () {
                    // -- your ajax request goes here --
                    return $.ajax({
                        url: ajax_endpoint,
                        method: 'POST',
                        data: {
                            "action": "saveMapping",
                            "field_mappings": field_maps,
                            "update_oppo": _update_oppo,
                            "redcap_csrf_token": redcap_csrf_token
                        },
                        // dataType: 'json'
                    }).done(function (result) {
                        // console.log("if upddate oppo,then update the vmaps too", _update_oppo);
                        result = decode_object(result)
                        _select.removeClass("second_level_trigger");

                        updatePushPullStatus(oncore_field, redcap_field, result);
                        updateOverAllStatus(result);
                        enableSelects();

                        if (redcap_field !== "-99") {
                            makeValueMappingRow(result, oncore_field, is_rc_mapping);
                            if (_update_oppo) {
                                makeValueMappingRow(result, oncore_field, !is_rc_mapping);
                            }
                        } else {
                            var parent_id = is_rc_mapping ? "#redcap_mapping" : "#oncore_mapping"
                            $(parent_id + " tr.more." + oncore_field).remove();
                        }

                        //remove spinners
                        $("#field_mapping .loading").removeClass("loading");
                    }).fail(function (e) {
                        e.responseJSON = decode_object(e.responseText)
                        var be_status = "";
                        var be_lead = "";
                        if (e.hasOwnProperty("responseJSON")) {
                            be_status = e.responseJSON.hasOwnProperty("status") ? e.responseJSON.status + ". " : "";
                            be_lead = e.responseJSON.hasOwnProperty("message") ? e.responseJSON.message + "\r\n" : "";
                        }

                        var headline = be_status + "Failed to save Mapping";
                        var lead = be_lead + "Please refresh page and try again";
                        var notif = new notifModal(lead, headline);
                        notif.show();
                    });

                    return new Promise(function (resolve, reject) {
                        setTimeout(function () {
                            resolve(data);
                        }, 100);
                    });
                });
            }
        });

        //ON SELECT OF FIXED VALUE SET REDCAP/ONCORE MAPPING
        $("#oncore_mapping").on("change","select.redcap_value", function(){
            if($(this).find("option:selected")){
                var _opt            = $(this).find("option:selected");

                var temp            = $(this).attr("name").split("_");
                var oncore_field    = temp[0];
                var oncore_val_i    = temp[1];
                var redcap_val_i    = _opt.val();

                var top_level       = $(this).data("rc_field");

                if ($("#oncore_mapping select[name='" + oncore_field + "']").find("option:selected").length) {
                    var val_mapping = $("#oncore_mapping select[name='" + oncore_field + "']").find("option:selected").data("val_mapping");

                    if (!val_mapping) {
                        val_mapping = {};
                    }
                    var oset = oncore_fields[oncore_field]["oncore_valid_values"];
                    if (redcap_val_i == "-99") {
                        if(val_mapping.hasOwnProperty(oset[oncore_val_i])){
                            delete val_mapping[oset[oncore_val_i]];
                        }
                    } else {
                        val_mapping[oset[oncore_val_i]] = redcap_val_i;
                    }

                    $("#oncore_mapping select[name='" + oncore_field + "']").find("option:selected").data("val_mapping", val_mapping);
                    $("#oncore_mapping select[name='" + oncore_field + "']").addClass("second_level_trigger");

                    $(this).parent().addClass("loading");
                    $("#oncore_mapping select[name='" + oncore_field + "']").trigger("change");
                }
            }
        });
        $("#redcap_mapping").on("change","select.oncore_value", function(){
            if($(this).find("option:selected").length){
                var _opt            = $(this).find("option:selected");

                var temp            = $(this).attr("name").split("_");
                var redcap_val_i    = temp.pop();
                var rc_field        = $(this).data("rc_field");;
                var oncore_val      = _opt.val();
                var oncore_field    = $(this).data("oc_field");

                if ($("#redcap_mapping select[name='" + oncore_field + "']").find("option:selected").length) {
                    var val_mapping = $("#redcap_mapping select[name='" + oncore_field + "']").find("option:selected").data("val_mapping");

                    if (!val_mapping) {
                        val_mapping = {};
                    }

                    var oset = oncore_fields[oncore_field]["oncore_valid_values"];
                    if (oncore_val == "-99") {
                        if(val_mapping.hasOwnProperty(redcap_val_i)){
                            delete val_mapping[redcap_val_i];
                        }
                    } else {
                        val_mapping[redcap_val_i] = oset[oncore_val];
                    }

                    $("#redcap_mapping select[name='" + oncore_field + "']").find("option:selected").data("val_mapping", val_mapping);
                    $("#redcap_mapping select[name='" + oncore_field + "']").addClass("second_level_trigger");

                    $(this).parent().addClass("loading");
                    $("#redcap_mapping select[name='" + oncore_field + "']").trigger("change");
                }
            }
        });

        //ONCORE ADD PULL PROP
        $("#oncore_mapping").on("click", ".oncore_pull_prop", function(e){
            e.preventDefault();
            var _this       = $(this);
            var oncore_prop = _this.data("val");

            $("#oncore_prop_selector .dropdown-toggle").prop("disabled", "disabled");

            disableSelects();
            ajaxQueue.addRequest(function () {
                // -- your ajax request goes here --
                return $.ajax({
                    url: ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action": "saveOncoreSubset",
                        "oncore_prop": oncore_prop,
                        "redcap_csrf_token": redcap_csrf_token
                    },
                    //  dataType: 'json'
                }).done(function (result) {
                    result = decode_object(result)
                    // result["pull"] = unescape(result["pull"])
                    enableSelects();
                    $("#oncore_mapping tbody").empty().append(result["pull"]);
                    $("#oncore_prop_selector .dropdown-toggle").prop("disabled", false);
                    _this.remove();

                    var fake_result = {"overallPull": false, "overallPush": null};
                    updateOverAllStatus(fake_result);
                }).fail(function (e) {
                    e.responseJSON = decode_object(e.responseText)
                    var be_status = "";
                    var be_lead = "";
                    if (e.hasOwnProperty("responseJSON")) {
                        be_status = e.responseJSON.hasOwnProperty("status") ? e.responseJSON.status + ". " : "";
                        be_lead = e.responseJSON.hasOwnProperty("message") ? e.responseJSON.message + "\r\n" : "";
                    }

                    var headline = be_status + "Failed to add property";
                    var lead = be_lead + "Please refresh page and try again";
                    var notif = new notifModal(lead, headline);
                    notif.show();
                });

                return new Promise(function (resolve, reject) {
                    setTimeout(function () {
                        resolve(data);
                    }, 100);
                });
            });
        });
        //DELETE PULL PROP
        $("#oncore_mapping").on("click", ".delete_pull_prop", function(e){
            e.preventDefault(e);
            var oncore_prop = $(this).data("oncore_prop");
            var _req = $(this).data("req");
            disableSelects();
            ajaxQueue.addRequest(function () {
                // -- your ajax request goes here --
                return $.ajax({
                    url: ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action": "deletePullField",
                        "oncore_prop": oncore_prop,
                        "redcap_csrf_token": redcap_csrf_token
                    },
                    //dataType: 'json'
                }).done(function (result) {
                    result = decode_object(result)
                    enableSelects();
                    $("#oncore_mapping tr." + oncore_prop).fadeOut(function () {
                        $(this).remove();
                        var addto = $("<button>").data("val", oncore_prop).attr("type", "button").addClass("dropdown-item oncore_pull_prop").text(oncore_prop);
                        if (_req) {
                            var hdr = ".req_hdr";
                            addto.insertAfter($("#oncore_prop_selector " + hdr));
                        } else {
                            var hdr = ".no_req_hdr";
                            addto.insertAfter($("#oncore_prop_selector " + hdr));
                        }

                        updateOverAllStatus(result);
                    });
                }).fail(function (e) {
                    e.responseJSON = decode_object(e.responseText)
                    enableSelects();

                    var be_status = "";
                    var be_lead = "";
                    if (e.hasOwnProperty("responseJSON")) {
                        be_status = e.responseJSON.hasOwnProperty("status") ? e.responseJSON.status + ". " : "";
                        be_lead = e.responseJSON.hasOwnProperty("message") ? e.responseJSON.message + "\r\n" : "";
                    }
                    var headline = be_status + "Failed to delete field";
                    var lead = be_lead + "Please refresh page and try again";
                    var notif       = new notifModal(lead,headline);
                    notif.show();
                });

                return new Promise(function (resolve, reject) {
                    setTimeout(function () {
                        resolve(data);
                    }, 100);
                });
            });
        })

        //SAVE SITE STUDIES SUB SET
        $("#study_sites").on("change", "input[name='site_study_subset']",function(){
            $(this).parent("label").addClass("loading");
            $("#study_sites input").prop("disabled","disabled");
            $("#study_sites").submit();
        });
        $("#study_sites").submit(function(e){
            e.preventDefault();
            var site_study_subset = $(this).find("input:checked").serializeArray();

            var sss = [];
            $(this).find("input:checked").each(function () {
                sss.push($(this).val());
            });

            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": "saveSiteStudies",
                    "site_studies_subset": sss,
                    "redcap_csrf_token": redcap_csrf_token
                },
                //dataType: 'json'
            }).done(function (result) {
                result = decode_object(result)
                $("#study_sites .loading").removeClass("loading");
                $("#study_sites input").prop("disabled", false);
                //done
            }).fail(function (e) {
                e.responseJSON = decode_object(e.responseText)
                $("#study_sites .loading").removeClass("loading");
                $("#study_sites input").prop("disabled", false);
                var be_status = "";
                var be_lead = "";
                if (e.hasOwnProperty("responseJSON")) {
                    be_status = e.responseJSON.hasOwnProperty("status") ? e.responseJSON.status + ". " : "";
                    be_lead = e.responseJSON.hasOwnProperty("message") ? e.responseJSON.message + "\r\n" : "";
                }

                var headline = be_status + "Failed to save Study Site";
                var lead        = be_lead + "Please refresh the page and try again";
                var notif       = new notifModal(lead,headline);
                notif.show();
            });
        });
    });

    function disableSelects(){
        $("#field_mapping select").each(function(){
            $(this).prop("disabled","disabled");
        });
    }

    function enableSelects(){
        $("#field_mapping select").each(function(){
            $(this).prop("disabled",false);
        });
    }

    function oncoreConfigVis(initial_pushpull){
        var show = false;
        $(".pCheck").each(function(){
            var tab = $(this).data("tab");

            if(initial_pushpull && initial_pushpull.includes(tab) ){
                $(this).attr("checked",true);
            }

            if($(this).is(":checked")){
                $("."+tab).fadeIn(function(){
                    $(this).css("display","block");
                });
                show = true;
            }else{
                $("."+tab).fadeOut();
            }
        });

        if(show || $("#oncore_fields").find("input:checked").length){
            $("#oncore_fields").fadeIn();
        }else{
            if(!$("#oncore_fields").find("input:checked").length){
                $("#oncore_fields").fadeOut();
            }
        }
    }

    function onlyUnique(value, index, self) {
        return self.indexOf(value) === index;
    }

    function updateOverAllStatus(result){
        var overallPull = result["overallPull"];
        var overallPush = result["overallPush"];

        if(overallPull !== null){
            $(".nav-tabs .pull_mapping").removeClass("ok");
            if(overallPull){
                $(".nav-tabs .pull_mapping").addClass("ok");
            }
        }

        if(overallPush !== null) {
            $(".nav-tabs .push_mapping").removeClass("ok");
            if (overallPush) {
                $(".nav-tabs .push_mapping").addClass("ok");
            }
        }
    }

    function updatePushPullStatus(oncore_field, redcap_field, result){
        var _el     = $("#oncore_mapping tr."+oncore_field);
        var _el2    = $("#redcap_mapping tr."+oncore_field);

        _el.find("td.status.pull").removeClass("ok");
        _el2.find("td.status.push").removeClass("ok");

        var pull_status = result["pull"] ? "ok" : "";
        var push_status = result["push"] ? "ok" : "";
        _el.find("td.status.pull").addClass(pull_status);
        _el.find(".property_select").removeClass("ok").addClass(pull_status);
        _el2.find("td.status.push").addClass(push_status);
        _el2.find(".property_select").removeClass("ok").addClass(push_status);
    }

    function makeValueMappingRow(result, oncore_field, rc_mapping) {
        var html        = result["html"];
        var parent_id   = rc_mapping ? "#redcap_mapping" : "#oncore_mapping";
        if ($(parent_id + " tr." + oncore_field).length) {
            //CLEAR EXISTING ROW BEFORE BUILDING NEW UI
            $(parent_id + " tr.more." + oncore_field).remove();
            $(html).insertAfter($(parent_id + " tr." + oncore_field));
        }
    }

    function unescape(s) {
        return s.replace(/&amp;/g, "&")
            .replace(/&lt;/g, "<")
            .replace(/&gt;/g, ">")
            .replace(/&#39;/g, "'")
            .replace(/&quot;/g, '"');
    }
</script>
