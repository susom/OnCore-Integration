<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$current_mapping        = $module->getMapping()->getProjectMapping();
//$module->emDebug($current_mapping);

$oncore_css             = $module->getUrl("assets/styles/field_mapping.css");
$ajax_endpoint          = $module->getUrl("ajax/handler.php");
$icon_ajax              = $module->getUrl("assets/images/icon_ajax.gif");
$mapping                = $module->getMapping();

$pushpull_pref          = $mapping->getProjectPushPullPref();
$overall_pull_status    = $mapping->getOverallPullStatus() ? "ok" : "";
$overall_push_status    = $mapping->getOverallPushStatus() ? "ok" : "";

$field_map_ui           = $mapping->makeFieldMappingUI();
$oncore_fields          = $field_map_ui["oncore_fields"];
$pull_html              = $field_map_ui["pull"];
$push_html              = $field_map_ui["push"];

//ONCORE FIELD SUB SET SELECTION
$oncore_props           = $mapping->getOnCoreFieldDefinitions();
$project_oncore_subset  = $mapping->getProjectOncoreSubset();
$field_selection        = array();
$field_selection[]      = "<ul>\r\n";
foreach($oncore_props as $field => $props){
    $checked            = in_array($field, $project_oncore_subset) ? "checked" : "";
    $field_selection[]   = "<li><label><input type='checkbox' $checked name='oncore_field_subset' value='$field'><span>$field</span></label></li>\r\n";
}
$field_selection[]      = "</ul>\r\n";
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
                <h2>Map OnCore properties to REDCap variables to PULL</h2>
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
                    <?= $push_html ?>
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
        $("select.redcap_field").on("change",function(){
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
                $("select.redcap_field[name='"+oncore_field+"']").each(function(){
                    var temp_map_direction   = $(this).data("mapdir") == "push" ? "push" : "pull";

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

                        if(!no_refresh || true){
                            makeValueMappingRow(oncore_field, redcap_field, 0);
                            makeValueMappingRow(oncore_field, redcap_field, 1);
                            _select.removeClass("second_level_trigger");
                        }
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
                    $("#oncore_mapping tbody").empty().append(result["pull"]);
                    updateOverAllStatus();
                }).fail(function (e) {
                    console.log("saveOncoreSubset failed to save", e);
                });

                return new Promise(function (resolve, reject) {
                    setTimeout(function () {
                        resolve(data);
                    }, 1000);
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

            // console.log("overall status", status);
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
        // console.log("makeValueMappingRow",oncore_field, redcap_field);
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
                $(parent_id + " tr.more." + oncore_field).remove();
                $(result).insertAfter($(parent_id + " tr." + oncore_field));
            }
        }).fail(function (e) {
            console.log("failed to get value mapping row", e);
        });
    }
</script>
