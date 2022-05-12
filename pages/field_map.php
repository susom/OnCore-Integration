<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$oncore_css         = $module->getUrl("assets/styles/field_mapping.css");
$ajax_endpoint      = $module->getUrl("ajax/handler.php");
$icon_ajax          = $module->getUrl("assets/images/icon_ajax.gif");
$mapping            = $module->getMapping();

$pushpull_pref          = $module->getMapping()->getProjectPushPullPref();
$oncore_props           = $module->getMapping()->getOnCoreFieldDefinitions();
$project_oncore_subset  = $module->getMapping()->getProjectOncoreSubset();

$module->emDebug($pushpull_pref);

$field_map_ui       = $mapping->makeFieldMappingUI();
$field_map_ui_pull  = $field_map_ui["pull"];
$field_map_ui_push  = $field_map_ui["push"];

$oncore_fields          = $field_map_ui["oncore_fields"];
$req_pull               = $field_map_ui_pull["required"];
$not_req_pull           = $field_map_ui_pull["not_required"];
$req_push               = $field_map_ui_push["required"];
$not_req_push           = $field_map_ui_push["not_required"];

$overall_pull_status    = $module->getMapping()->getOverallPullStatus() ? "ok" : "";
$overall_push_status    = $module->getMapping()->getOverallPushStatus() ? "ok" : "";

$oncore_props           = $module->getMapping()->getOnCoreFieldDefinitions();
$project_oncore_subset  = $module->getMapping()->getProjectOncoreSubset();
$field_selection         = array();
$field_selection[]       = "<ul>\r\n";
foreach($oncore_props as $field => $props){
    $checked            = in_array($field, $project_oncore_subset) ? "checked" : "";

    $field_selection[]   = "<li><label><input type='checkbox' $checked name='oncore_field_subset' value='$field'><span>$field</span></label></li>\r\n";
}
$field_selection[]       = "</ul>\r\n";
?>
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/open_framework/packages/bootstrap-2.3.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">
<link rel="stylesheet" href="<?=$oncore_css?>">

<div id="field_mapping">
    <h1>REDCap - OnCore Field Mapping</h1>
    <p class="lead">Map REDCap Variables to OnCore Properties and vice versa to ensure that data can be both pulled and pushed between the two.</p>
    <ul class="nav nav-tabs">
        <li class="active"><a data-toggle="tab" href="#oncore_config" class="oncore_config">Configurations</a></li>
        <li class=""><a data-toggle="tab" href="#pull_mapping" class="optional pull_mapping <?=$overall_pull_status?>">Pull Data From OnCore <i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></a></li>
        <li class=""><a data-toggle="tab" href="#push_mapping" class="optional push_mapping <?=$overall_push_status?>">Push Data To OnCore <i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></a></li>
    </ul>
    <div class="tab-content">
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

                <div id="oncore_fields">
                    <h2>Choose which OnCore Properties will be used with this REDCap project</h2>
                    <?=implode("", $field_selection) ?>
                </div>
            </form>
        </div>

        <div class="tab-pane" id="pull_mapping">
            <form id="oncore_mapping" class="container">
                <h2>Map OnCore Fields to REDCap project variables to PULL</h2>
                <p class="lead">Data stored in OnCore will have a fixed nomenclature. When linking an OnCore project to a REDCap
                    project the analogous REDCap field name will need to be manually mapped and stored in the project's EM
                    Settings to be able to PULL.</p>
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th class="td_oc_field">OnCore Property</th>
                        <th class="td_rc_field">REDCap Field</th>
                        <th class="td_rc_event centered">REDCap Event</th>
                        <th class="td_pull centered">Pull Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?= $req_pull ?>

                    <tr class="show_optional required">
                        <td colspan="4" ><a href="#" ><span>Show</span> Optional Fields +</a></td>
                    </tr>

                    <?= $not_req_pull ?>
                    </tbody>
                </table>
            </form>
        </div>

        <div class="tab-pane" id="push_mapping">
            <form id="redcap_mapping" class="container">
                <h2>Map REDCap fields to Oncore properties to PUSH</h2>
                <p class="lead">Data stored in OnCore will have a fixed nomenclature. When linking an OnCore project to a REDCap
                    project the analogous REDCap field name will need to be manually mapped and stored in the project's EM
                    Settings to be able to PUSH.</p>
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th class="td_oc_field">OnCore Property</th>
                        <th class="td_rc_field">REDCap Field</th>
                        <th class="td_rc_event centered">REDCap Event</th>
                        <th class="td_push centered">Push Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?= $not_req_push ?>
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

#field_mapping .optional{
    display:none;
}

.map_dir{
    font-size:120%;
    margin-bottom:20px;
}
</style>
<script>
    $(document).ready(function () {
        var ajax_endpoint       = "<?=$ajax_endpoint?>";
        var oncore_fields       = <?=json_encode($oncore_fields)?>;
        var pushpull_pref       = <?=json_encode($pushpull_pref)?>;

        //THIS WILL QUEUE THE AJAX REQUESTS SO THEY DONT RACE CONDITION EACHOTHER
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

        //TAB BEHAVIOR
        $("#field_mapping ul.nav-tabs a").on("click", function(){
            $("li.active").removeClass("active");
            $(this).parent("li").addClass("active");
        });
        $(".show_optional a").on("click",function(e){
            e.preventDefault();
            if($("tr.notrequired td").is(":visible")){
                $(this).find("span").text("Show");
                $("tr.notrequired td").hide();
            }else{
                $(this).find("span").text("Hide");
                $("tr.notrequired td").show();
            }
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
                                "push_pull" : push_pull
                            },
                            dataType: 'json'
                        }).done(function (result) {
                            //done
                            console.log("deleteMapping done");
                        }).fail(function (e) {
                            console.log("deleteMapping failed to save", e);
                        });

                        return new Promise(function (resolve, reject) {
                            setTimeout(function () {
                                resolve(data);
                            }, 2000);
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
                        "pushpull_pref" : tab_state
                    },
                    dataType: 'json'
                }).done(function (result) {
                    //done
                    console.log("savePushPullPref done");
                }).fail(function (e) {
                    console.log("savePushPullPref failed to save", e);
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
        $("#oncore_mapping select.redcap_field").on("change",function(){
            if($(this).find("option:selected")){
                var _opt            = $(this).find("option:selected");
                var redcap_field    = _opt.val();
                var oncore_field    = $(this).attr("name");
                var event_name      = _opt.data("eventname");
                var ftype           = _opt.data("type");
                var vmaps           = _opt.data("val_mapping");

                if($(this).closest("tr").find(".rc_event").length){
                    $(this).closest("tr").find(".rc_event").empty();
                    $(this).closest("tr").find(".rc_event").html(event_name);
                }

                var value_mapping   = [];
                if(vmaps){
                    for(var oncore_value in vmaps){
                        var redcap_mapping = vmaps[oncore_value];
                        value_mapping.push({"oc" : oncore_value, "rc" : redcap_mapping});
                    }
                }
                var field_maps = {
                      "mapping" : "pull"
                    , "oncore_field": oncore_field
                    , "redcap_field": redcap_field
                    , "event": event_name
                    , "field_type" : ftype
                    , "value_mapping" : value_mapping
                };

                $(this).parent().addClass("loading");

                ajaxQueue.addRequest(function () {
                    // -- your ajax request goes here --
                    return $.ajax({
                        url: ajax_endpoint,
                        method: 'POST',
                        data: {
                            "action": "saveMapping",
                            "field_mappings": field_maps,
                        },
                        dataType: 'json'
                    }).done(function (result) {
                        //remove spinners
                        updatePushPullStatus(oncore_field, redcap_field);
                        makeValueMappingRow(oncore_field, redcap_field, 0);
                        updateOverAllStatus();
                        $("#field_mapping .loading").removeClass("loading");
                    }).fail(function (e) {
                        console.log("failed to save", e);
                    });

                    return new Promise(function (resolve, reject) {
                        setTimeout(function () {
                            resolve(data);
                        }, 2000);
                    });
                });
            }
        });
        $("#redcap_mapping select.oncore_field").on("change",function(){
            if($(this).find("option:selected")){
                var _opt            = $(this).find("option:selected");
                var oncore_field    = _opt.val();
                var redcap_field    = $(this).attr("name");
                var ftype           = $(this).data("type");
                var eventname       = $(this).data("eventname");

                var vmaps           = _opt.data("val_mapping");
                var value_mapping   = [];
                if(vmaps){
                    for(var redcap_mapping in vmaps){
                        var oncore_value = vmaps[redcap_mapping];
                        value_mapping.push({"oc" : oncore_value, "rc" : redcap_mapping});
                    }
                }

                var field_maps = {
                      "mapping" : "push"
                    , "oncore_field": oncore_field
                    , "redcap_field": redcap_field
                    , "event": eventname
                    , "field_type" : ftype
                    , "value_mapping" : value_mapping
                };

                $(this).parent().addClass("loading");

                ajaxQueue.addRequest(function () {
                    // -- your ajax request goes here --
                    return $.ajax({
                        url: ajax_endpoint,
                        method: 'POST',
                        data: {
                            "action": "saveMapping",
                            "field_mappings": field_maps,
                        },
                        dataType: 'json'
                    }).done(function (result) {
                        updatePushPullStatus(oncore_field, redcap_field);
                        makeValueMappingRow(oncore_field, redcap_field, 1);
                        updateOverAllStatus();
                        $("#field_mapping .loading").removeClass("loading");
                    }).fail(function (e) {
                        console.log("failed to save", e);
                    });

                    return new Promise(function (resolve, reject) {
                        setTimeout(function () {
                            resolve(data);
                        }, 2000);
                    });
                });
            }
        });


        //ON SELECT OF FIXED VALUE REDCAP/ONCORE MAPPING
        $("#oncore_mapping").on("change","select.redcap_value", function(){
            if($(this).find("option:selected")){
                var _opt            = $(this).find("option:selected");

                var temp            = $(this).attr("name").split("_");
                var oncore_field    = temp[0];
                var oncore_val_i    = temp[1];
                var redcap_val_i    = _opt.val();

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

                    $(this).parent().addClass("loading");
                    $("#oncore_mapping select[name='" + oncore_field + "']").trigger("change");
                }
            }
        });
        $("#redcap_mapping").on("change","select.oncore_value", function(){
            if($(this).find("option:selected").length){
                var _opt            = $(this).find("option:selected");
                var temp            = $(this).attr("name").split("_");
                var rc_field        = temp[0];
                var redcap_val_i    = temp[1];
                var oncore_val      = _opt.val();
                var oncore_field    = $(this).data("oc_field");

                if ($("#redcap_mapping select[name='" + rc_field + "']").find("option:selected").length) {
                    var val_mapping = $("#redcap_mapping select[name='" + rc_field + "']").find("option:selected").data("val_mapping");

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

                    $("#redcap_mapping select[name='" + rc_field + "']").find("option:selected").data("val_mapping", val_mapping);

                    $(this).parent().addClass("loading");
                    $("#redcap_mapping select[name='" + rc_field + "']").trigger("change");
                }
            }
        });


        //ONCORE SUBSET
        $("#oncore_fields").on("change", "input[name='oncore_field_subset']",function(e){
            e.preventDefault();
            var oncore_subset = $("#oncore_fields").find("input:checked").serializeArray();

            var os = [];
            $("#oncore_fields").find("input:checked").each(function(){
                os.push($(this).val());
            });

            ajaxQueue.addRequest(function () {
                // -- your ajax request goes here --
                return $.ajax({
                    url: ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action": "saveOncoreSubset",
                        "oncore_subset" : os
                    },
                    dataType: 'json'
                }).done(function (result) {
                    //done
                    console.log(result);
                }).fail(function (e) {
                    console.log("saveOncoreSubset failed to save", e);
                });

                return new Promise(function (resolve, reject) {
                    setTimeout(function () {
                        resolve(data);
                    }, 2000);
                });
            });
        });
    });

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

    function updateOverAllStatus(){
        $.ajax({
            url: ajax_endpoint,
            method: 'POST',
            data: {
                "action": "checkOverallStatus"
            },
            dataType: 'json'
        }).done(function (status) {
            var overallPull = status["overallPull"];
            var overallPush = status["overallPush"];

            console.log("overall status", status);
            $(".nav-tabs .pull_mapping").removeClass("ok");
            if(overallPull){
                $(".nav-tabs .pull_mapping").addClass("ok");
            }
            $(".nav-tabs .push_mapping").removeClass("ok");
            if(overallPush){
                $(".nav-tabs .push_mapping").addClass("ok");
            }
        }).fail(function (e) {
            console.log("failed to save", e);
        });
    }

    function updatePushPullStatus(oncore_field, redcap_field){
        var _el     = $("#oncore_mapping tr."+oncore_field);
        var _el2    = $("#redcap_mapping tr."+redcap_field);

        _el.find("td.status.pull").removeClass("ok");
        _el2.find("td.status.push").removeClass("ok");
        $.ajax({
            url: ajax_endpoint,
            method: 'POST',
            data: {
                "action": "checkPushPullStatus",
                "oncore_field": oncore_field,
            },
            dataType: 'json'
        }).done(function (result) {
            var pull_status = result["pull"] ? "ok" : "";
            var push_status = result["push"] ? "ok" : "";
            _el.find("td.status.pull").addClass(pull_status);
            _el2.find("td.status.push").addClass(push_status);
        }).fail(function (e) {
            console.log("failed to get push pull status?", e);
        });
    }

    function makeValueMappingRow(oncore_field, redcap_field, rc_mapping) {
        $.ajax({
            url: ajax_endpoint,
            method: 'POST',
            data: {
                "action": "getValueMappingUI",
                "oncore_field": oncore_field,
                "redcap_field": redcap_field,
                "rc_mapping"  : rc_mapping
            },
            dataType: 'json'
        }).done(function (result) {
            var parent_id = rc_mapping ? "#redcap_mapping" : "#oncore_mapping";
            if($(parent_id+ " tr."+oncore_field).length){
                //CLEAR EXISTING ROW BEFORE BUILDING NEW UI
                $(parent_id+ " tr.more."+ oncore_field).remove();
                $(result).insertAfter($(parent_id+ " tr."+oncore_field));
            }
        }).fail(function (e) {
            console.log("failed to get value mapping row", e);
        });
    }
</script>
