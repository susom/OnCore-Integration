<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$ajax_endpoint      = $module->getUrl("ajax/handler.php");
$mapping            = $module->getMapping();

$field_map_ui       = $mapping->makeFieldMappingUI();
$field_map_ui_pull  = $field_map_ui["pull"];
$field_map_ui_push  = $field_map_ui["push"];

$project_mappings   = $field_map_ui["project_mappings"];
$oncore_fields      = $field_map_ui["oncore_fields"];

$req_pull           = $field_map_ui_pull["required"];
$not_req_pull       = $field_map_ui_pull["not_required"];

$req_push           = $field_map_ui_push["required"];
$not_req_push       = $field_map_ui_push["not_required"];
$overall_pull_status    = $module->getMapping()->getOverallPullStatus() ? "ok" : "";
$overall_push_status    = $module->getMapping()->getOverallPushStatus() ? "ok" : "";

$study_sites            = $module->getUsers()->getOnCoreStudySites();
$project_study_sites    = $module->getMapping()->getProjectSiteStudies();
$site_selection         = array();
$site_selection[]       = "<ul>\r\n";
foreach($study_sites as $site){
    $checked            = in_array($site, $project_study_sites) ? "checked" : "";
    $site_selection[]   = "<li><label><input type='checkbox' $checked name='site_study_subset' value='$site'><span>$site</span></label></li>\r\n";
}
$site_selection[]       = "</ul>\r\n";
?>
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/open_framework/packages/bootstrap-2.3.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">

<div id="field_mapping">
    <h1>REDCap/OnCore Field Mapping</h1>
    <p class="lead">Map REDCap Variables to OnCore Properties and vice versa to ensure that data can be pulled and pushed between projects.  Once the requisite mapping coverage is acheived the icon in the tabs will show a "green checkmark"</p>
    <ul class="nav nav-tabs">
        <li class=""><a data-toggle="tab" href="#pull_mapping" class="pull_mapping <?=$overall_pull_status?>">Pull Data From OnCore <i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></a></li>
        <li class="active"><a data-toggle="tab" href="#push_mapping" class="push_mapping <?=$overall_push_status?>">Push Data To OnCore <i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></a></li>
    </ul>
    <div class="tab-content">
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

        <div class="tab-pane active" id="push_mapping">
            <form id="study_sites" class="container">
                <h2>Select subset of study sites for this project</h2>
                <p class="lead">Data pushed from REDCap to OnCore will require a matching study site. Click to choose which study site will be available to this project.</p>
                <?=implode("", $site_selection) ?>
            </form>

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
    #study_sites{
        margin-bottom:50px;
    }
    #study_sites ul {
        margin:0;
        padding:10px;
        list-style:none;
        box-shadow: 0 0 3px 1px #ccc;
        border-radius: 3px;
    }

    #study_sites li {
        display:inline-block;
        width:33%;
        position:relative;
        padding:0;
        margin:0 0 10px 0;
        vertical-align: top;
    }

    #study_sites li label{}

    #study_sites li input{
        position:absolute;
        top:50%; left:15px;
        transform: translateY(-30%)
    }

    #study_sites li span{
        display: flex;
        justify-content:center;
        flex-direction: column;

        width:95%;
        min-height:54px;
        line-height:100%;
        border-radius: 3px;
        padding:10px 10px 10px 35px;
        font-size:120%;
        color:#aaa;
        background:#F7F7FA;
    }

    #study_sites input:checked + span{
        background:#E9F4F4;
        color:#1B2E2E;
    }

    .nav-tabs i.fa-check-circle {
        color: #5cb85c;
        padding: 5px;
    }

    .nav-tabs i.fa-times-circle {
        color: #da4f49;
        padding: 5px;
    }

    .td_oc_field{ width:35% }
    .td_rc_field{ width:35% }
    .td_rc_event{ width:15% }
    .td_pull{ width:15% }
    .td_push{ width:15% }

    .td_oc_vset{ width:35% }
    .td_rc_vset{ width:35% }
    .td_map_status{ width:15% }
    .td_vset_spacer {width:15%}

    tr.required td{
        color:initial;
    }

    tbody tr.required td:first-child:after{
        content:"*";
    }
    tbody tr.notrequired td{
        display:none;
    }
    tr.show_optional td {
        text-align:left;
    }
    .table .centered { text-align:center; }

    tr.more td {
        border-top:initial;
        padding-top: 0;
        padding-bottom: 1.25rem;
    }

    .nav-tabs .ok i.fa-times-circle,
    .nav-tabs i.fa-check-circle,
    td.value_map_status.ok .fa-times-circle,
    td.value_map_status .fa-check-circle,
    td.status.ok .fa-times-circle,
    td.status .fa-check-circle{
        display:none;
    }

    .nav-tabs .ok i.fa-check-circle,
    td.value_map_status.ok .fa-check-circle,
    td.status.ok .fa-check-circle{
        display:inline-block;
    }

    table.value_map{
        width:100%;
    }

    table.value_map th,
    table.value_map td{
        padding:0 0 .5em 0;
    }

    table.value_map th {
        background: #fff !important;
        border: initial !important;
    }
</style>
<script>
    $(document).ready(function () {
        var ajax_endpoint       = "<?=$ajax_endpoint?>";
        var oncore_fields       = <?=json_encode($oncore_fields)?>;
        var project_mappings    = <?=json_encode($project_mappings)?>;

        //SUPERFICIAL UI
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

        //SOME RESPONSIVE UI TO SEE
        //ON SELECT OF TOP LEVEL REDCAP FIELD
        $("#oncore_mapping select.redcap_field").on("change",function(){
            if($(this).find("option:selected")){
                var _opt            = $(this).find("option:selected");
                var redcap_field    = _opt.val();
                var oncore_field    = $(this).attr("name");
                var event_name      = _opt.data("eventname");

                if($(this).closest("tr").find(".rc_event").length){
                    $(this).closest("tr").find(".rc_event").empty();
                    $(this).closest("tr").find(".rc_event").html(event_name);
                }

                //SAVE ENTIRE STATE
                $("#oncore_mapping").submit();

                //TODO HOW TO PASS CALLBACK INTO A BOUND SUBMIT EVENT? DO SHORT TIMEOUT THEN FOR NOW
                setTimeout(function(){
                    //UPDATE PUSH PULL STATUS
                    updatePushPullStatus(oncore_field);
                    makeValueMappingRow(oncore_field, redcap_field);
                }, 150);
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

                    $("select[name='" + oncore_field + "']").find("option:selected").data("val_mapping", val_mapping);
                }

                //SAVE ENTIRE STATE
                console.log("wtf is changing the pull status?");
                $("#oncore_mapping").submit();

                var _el = $(this).closest("tr").find(".value_map_status");

                //TODO HOW TO PASS CALLBACK INTO A BOUND SUBMIT EVENT? DO SHORT TIMEOUT THEN FOR NOW
                setTimeout(function(){
                    //UPDATE PUSH PULL STATUS
                    updatePushPullStatus(oncore_field);

                    _el.removeClass("ok");

                    //IF -99, THEN CLEAR VALUES ADN THATS IT
                    if(redcap_val_i == "-99"){
                        return;
                    }

                    _el.addClass("ok");
                }, 150);
            }
        });

        $("#redcap_mapping").on("change","select.oncore_value", function(){
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

                    $("select[name='" + oncore_field + "']").find("option:selected").data("val_mapping", val_mapping);
                }

                //SAVE ENTIRE STATE
                $("#oncore_mapping").submit();

                var _el = $(this).closest("tr").find(".value_map_status");
                //TODO HOW TO PASS CALLBACK INTO A BOUND SUBMIT EVENT? DO SHORT TIMEOUT THEN FOR NOW
                setTimeout(function(){
                    //UPDATE PUSH PULL STATUS
                    updatePushPullStatus(oncore_field);

                    _el.removeClass("ok");

                    //IF -99, THEN CLEAR VALUES ADN THATS IT
                    if(redcap_val_i == "-99"){
                        return;
                    }

                    _el.addClass("ok");
                }, 250);
            }
        });






        //SAVING THE ACTUAL MAPPINGS - DO ON EVERY CHANGE?
        $("#oncore_mapping").submit(function (e) {
            e.preventDefault();
            var field_maps = {};
            var all_fields = $(this).find("select.redcap_field"); //loop all the select fields

            all_fields.each(function (idx) {
                var el      = $(this);
                var name    = el.attr("name");
                var val     = el.val();
                var opt     = el.find("option:selected");
                var ev      = opt.data("eventname");
                var ftype   = opt.data("type");
                var vmaps   = opt.data("val_mapping");
                var value_mapping = [];
                if(vmaps){
                    for(var oncore_value in vmaps){
                        var redcap_mapping = vmaps[oncore_value];
                        value_mapping.push({"oc" : oncore_value, "rc" : redcap_mapping});
                    }
                }

                if (val != "-99") {
                    field_maps[name] = {
                        "redcap_field": val
                        , "event": ev
                        , "field_type" : ftype
                        , "value_mapping" : value_mapping
                    };
                }
            });

            console.log("save field mapping", field_maps);

            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": "saveMapping",
                    "field_mappings": field_maps,
                },
                dataType: 'json'
            }).done(function (result) {
                updateOverAllStatus();
            }).fail(function (e) {
                console.log("failed to save", e);
            });
        });

        $("#redcap_mapping").submit(function (e) {
            e.preventDefault();

            var field_maps = {};
            var all_fields = $(this).find("select.oncore_field"); //loop all the select fields

            all_fields.each(function (idx) {
                var el      = $(this);
                var name    = el.attr("name");
                var val     = el.val();
                var opt     = el.find("option:selected");
                var ev      = opt.data("eventname");
                var ftype   = opt.data("type");
                var vmaps   = opt.data("val_mapping");
                var value_mapping = [];
                if(vmaps){
                    for(var oncore_value in vmaps){
                        var redcap_mapping = vmaps[oncore_value];
                        value_mapping.push({"oc" : oncore_value, "rc" : redcap_mapping});
                    }
                }

                if (val != "-99") {
                    field_maps[name] = {
                        "redcap_field": val
                        , "event": ev
                        , "field_type" : ftype
                        , "value_mapping" : value_mapping
                    };
                }
            });

            console.log("save field mapping", field_maps);

            return;
            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": "saveMapping",
                    "field_mappings": field_maps,
                },
                dataType: 'json'
            }).done(function (result) {
                //do all the tedious crap, or just refresh page
                // location.href = location.href;
            }).fail(function (e) {
                console.log("failed to save", e);
            });
        });

        //SAVE SITE STUDIES SUB SET
        $("#study_sites").on("change", "input[name='site_study_subset']",function(){
            $("#study_sites").submit();
        });

        $("#study_sites").submit(function(e){
            e.preventDefault();
            var site_study_subset = $(this).find("input:checked").serializeArray();

            var sss = [];
            $(this).find("input:checked").each(function(){
                sss.push($(this).val());
            });

            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": "saveSiteStudies",
                    "site_studies_subset" : sss
                },
                dataType: 'json'
            }).done(function (result) {
                //done
            }).fail(function (e) {
                console.log("failed to save", e);
            });
        });
    });

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

    function updatePushPullStatus(oncore_field){
        console.log("updatePushPullStatus",oncore_field)
        var _el = $("tr."+oncore_field);
        _el.find("td.status.pull").removeClass("ok");
        _el.find("td.status.push").removeClass("ok");

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
            _el.find("td.status.push").addClass(push_status);
        }).fail(function (e) {
            console.log("failed to get push pull status?", e);
        });
    }

    function makeValueMappingRow(oncore_field, redcap_field) {
        $.ajax({
            url: ajax_endpoint,
            method: 'POST',
            data: {
                "action": "getValueMappingUI",
                "oncore_field": oncore_field,
                "redcap_field": redcap_field,
            },
            dataType: 'json'
        }).done(function (result) {
            if($("tr."+oncore_field).length){
                //CLEAR EXISTING ROW BEFORE BUILDING NEW UI
                $("tr.more."+ oncore_field).remove();
                $(result).insertAfter($("tr."+oncore_field));
            }
        }).fail(function (e) {
            console.log("failed to get value mapping row", e);
        });
    }
</script>
