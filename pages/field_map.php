<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$ajax_endpoint      = $module->getUrl("ajax/handler.php");
$mapping            = $module->getMapping();

$field_map_ui       = $mapping->makeFieldMappingUI();
$required_html      = $field_map_ui["required"];
$not_required       = $field_map_ui["not_required"];
$oncore_fields      = $field_map_ui["oncore_fields"];
$project_mappings   = $field_map_ui["project_mappings"];
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">

<form id="oncore_mapping" class="container">
    <h2>Map OnCore Fields to REDCap project variables</h2>
    <p class="lead">Data stored in OnCore will have a fixed nomenclature. When linking an OnCore project to a REDCap
        project the analogous REDCap field name will need to be manually mapped and stored in the project's EM
        Settings.</p>
    <table class="table table-striped">
        <thead>
        <tr>
            <th class="td_oc_field">OnCore Field</th>
            <th class="td_oc_type">Expected Data Type</th>
            <th class="td_rc_field">REDCap Field</th>
            <th class="td_rc_event">REDCap Event</th>
            <th class="td_pull">Pull Status</th>
            <th class="td_push">Push Status</th>
        </tr>
        </thead>
        <tbody>
        <?= $required_html ?>

        <tr class="show_optional required">
            <td colspan="6" ><a href="#" ><span>Show</span> Optional Fields +</a></td>
        </tr>

        <?= $not_required ?>
        </tbody>
    </table>
</form>
<style>
    .td_oc_field{ width:31% }
    .td_oc_type{ width:10% }
    .td_rc_field{ width:30% }
    .td_rc_event{ width:15% }
    .td_pull{ width:7% }
    .td_push{ width:7% }
    .td_oc_vset{ width:49% }
    .td_rc_vset{ width:37% }
    .td_map_status{ width:14% }

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
    td.centered { text-align:center; }

    tr.more td {
        border-top:initial;
        padding-top: 0;
        padding-bottom: 1.25rem;
    }

    td.value_map_status.ok .fa-times-circle,
    td.value_map_status .fa-check-circle,
    td.status.ok .fa-times-circle,
    td.status .fa-check-circle{
        display:none;
    }

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

        //SOME INIT DATA WRANGLING
        for(var oncore_field in project_mappings){
            var mapped_field = project_mappings[oncore_field];
            if(mapped_field.hasOwnProperty("value_mapping")){
                var val_mapping = {};
                for(var i in mapped_field["value_mapping"]){
                    var mapped_value = mapped_field["value_mapping"][i];
                    var oncore_value = mapped_value["oc"];
                    var redcap_value = mapped_value["rc"];
                    val_mapping[oncore_value] = redcap_value;
                }
                $("select[name='" + oncore_field + "']").find("option:selected").data("val_mapping", val_mapping);
            }
        }

        //SUPERFICIAL UI
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

                    //CLEAR EXISTING ROW BEFORE BUILDING NEW UI
                    $("tr.more."+ oncore_field).remove();

                    //IF -99, THEN CLEAR VALUES ADN THATS IT
                    if(redcap_field == "-99"){
                        return;
                    }

                    makeValueMappingRow(oncore_field, redcap_field);
                }, 250);
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

                if ($("select[name='" + oncore_field + "']").find("option:selected").length) {
                    var val_mapping = $("select[name='" + oncore_field + "']").find("option:selected").data("val_mapping");
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

            // console.log("save field mapping", field_maps);

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
    });

    function updatePushPullStatus(oncore_field){
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
                $(result).insertAfter($("tr."+oncore_field));
            }
        }).fail(function (e) {
            console.log("failed to get value mapping row", e);
        });
    }
</script>
