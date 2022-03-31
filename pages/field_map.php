<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$ajax_endpoint  = $module->getUrl("ajax/handler.php");

$field_map_ui   = $module->makeFieldMappingUI();
$required_html  = $field_map_ui["required"];
$not_required   = $field_map_ui["not_required"];
$oncore_fields  = $field_map_ui["oncore_fields"];
$project_mappings = $field_map_ui["project_mappings"];

$module->emDebug("project_mappings", $project_mappings);
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
        <tfoot>
        <tr>
            <td colspan="6" align="right">
                <button type="submit" href="#" class="more-button">Save Mappings</button>
            </td>
        </tr>
        </tfoot>
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

    td.status.ok .fa-times-circle,
    td.status .fa-check-circle{
        display:none;
    }

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
        var mrn_fields          = <?=json_encode(array_keys($oncore_fields) )?>;
        var oncore_fields       = <?=json_encode($oncore_fields)?>;
        var project_mappings    = <?=json_encode($project_mappings)?>;

        //SET UP SOME DATA ELEMENTS FOR EXISTING value Mapped Fields
        for(var oc_field in project_mappings){
            var rc_props    = project_mappings[oc_field];
            var rc_field    = rc_props["redcap_field"];
            var rc_event    = rc_props["event"];
            var rc_type     = rc_props["field_type"];
            var oc_vset     = oncore_fields[oc_field]["oncore_valid_values"];

            var _el = $("select[name='"+oc_field+"']");
            if(_el.length){
                _el.find("option[value='"+rc_field+"']").prop("selected", "selected");
                _el.closest("tr").find("td.rc_event").text(rc_event);

                var rc_vset = _el.find("option[value='"+rc_field+"']").data("value_set");
                if(rc_props.hasOwnProperty("value_mapping") && rc_props["value_mapping"].length){
                    var value_mapping = rc_props["value_mapping"];
                    makeValueMappingRow(oc_field, oc_vset, rc_vset, value_mapping);

                    //fuck this is redundant
                    $("tr.more."+oc_field).find("select.redcap_value").each(function(){
                        if($(this).find("option:selected")){
                            var _opt    = $(this).find("option:selected");
                            var rc_val  = _opt.val();
                            if(rc_val == -99){
                                return;
                            }

                            var temp    = $(this).attr("name").split("_");
                            var oc_field    = temp[0];
                            var oc_val_i    = temp[1];

                            if($("select[name='"+oc_field+"']").find("option:selected").length){
                                var val_mapping = $("select[name='"+oc_field+"']").find("option:selected").data("val_mapping");
                                if(!val_mapping){
                                    val_mapping = {};
                                }
                                var oset = oncore_fields[oc_field]["oncore_valid_values"];
                                val_mapping[oset[oc_val_i]] = rc_val;

                                $("select[name='"+oc_field+"']").find("option:selected").data("val_mapping", val_mapping);
                            }
                        }
                    });
                }

                var pull_status = "";
                var push_status = "";

                var vmap_format = formatVMAP(value_mapping);
                if(Object.keys(vmap_format).length){
                    let redcap_coverage = Object.keys(rc_vset).filter(x => !Object.values(vmap_format).includes(x));
                    let oncore_coverage = oc_vset.filter(x => !Object.keys(vmap_format).includes(x));

                    if(!redcap_coverage.length){
                        push_status = "ok";
                    }

                    if(!oncore_coverage.length){
                        pull_status = "ok";
                    }
                }else if(rc_type == "text"){
                    pull_status = "ok";
                    push_status = "ok";
                }

                _el.closest("tr").find("td.status.pull").addClass(pull_status);
                _el.closest("tr").find("td.status.push").addClass(push_status);
            }
        }

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

            // console.log("posting", field_maps);

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
                location.href = location.href;
                return;

                var update_keys = Object.keys(field_maps);
                var make_x      = $(mrn_fields).not(update_keys).get();

                //UPDATE UI STATUS
                for (var i in make_x) {
                    var key = make_x[i];
                    if ($("#oncore_mapping tr." + key + " i").hasClass("fa-check-circle")) {
                        $("#oncore_mapping tr." + key + " i").removeClass("fa-check-circle").addClass("fa-times-circle");
                    }
                }
                for (var i in update_keys) {
                    var key = update_keys[i];
                    $("#oncore_mapping tr." + key + " i").removeClass("fa-times-circle").addClass("fa-check-circle");
                }
            }).fail(function (e) {
                console.log("failed to save", e);
            });
        });

        $("#oncore_mapping select.redcap_field").on("change",function(){
            if($(this).find("option:selected")){
                var _opt    = $(this).find("option:selected");
                var ocfield = $(this).attr("name");
                var en      = _opt.data("eventname");
                var ftype   = _opt.data("type");
                var valset  = _opt.data("value_set");
                $(this).closest("td").next().text("").text(en);

                if(_opt.val() == "-99"){
                    $("tr.more."+ ocfield).remove();
                    return;
                }

                //SHOW value mapping row
                var valid_oncore_fields = oncore_fields[ocfield]["oncore_valid_values"];
                if(Object.keys(valid_oncore_fields).length > 0 || valid_oncore_fields.length > 0){
                    //FIXED VALUE SET , NEED EXTRA UI FOR MAPPING INDIVIDUAL VALUES
                    var field_value_mapping = project_mappings.hasOwnProperty(ocfield) && project_mappings[ocfield].hasOwnProperty("value_mapping") ? project_mappings[ocfield]["value_mapping"] : {};
                    makeValueMappingRow(ocfield, valid_oncore_fields, valset, field_value_mapping);
                }
            }
        });

        $("#oncore_mapping").on("change","select.redcap_value", function(){
            if($(this).find("option:selected")){
                var _opt    = $(this).find("option:selected");
                var rc_val  = _opt.val();

                var temp    = $(this).attr("name").split("_");
                var oc_field    = temp[0];
                var oc_val_i    = temp[1];

                if($("select[name='"+oc_field+"']").find("option:selected").length){
                    var val_mapping = $("select[name='"+oc_field+"']").find("option:selected").data("val_mapping");
                    if(!val_mapping){
                        val_mapping = {};
                    }
                    var oset = oncore_fields[oc_field]["oncore_valid_values"];
                    if(rc_val == -99 && val_mapping.hasOwnProperty(oset[oc_val_i])){
                        delete val_mapping[oset[oc_val_i]];
                    }else{
                        val_mapping[oset[oc_val_i]] = rc_val;
                    }
                    $("select[name='"+oc_field+"']").find("option:selected").data("val_mapping", val_mapping);
                }
            }
        });
    });

    function changeStatus(_el){

    }

    function formatVMAP(value_mapping){
        var val_mapping = {};
        for(var i in value_mapping){
            var map = value_mapping[i];
            val_mapping[map["oc"]] = map["rc"];
        }
        return val_mapping;
    }

    function makeValueMappingRow(oncore_field, oncore_vset, redcap_vset, value_mapping){
        var val_mapping = formatVMAP(value_mapping);
        var main_tr     = $("<tr>").addClass("more").addClass(oncore_field);
        var main_td     = $("<td>").attr("colspan",4);
        var junk_td     = $("<td>").attr("colspan",2);
        var row_table   = $("<table>").addClass("value_map");
        var table_bdy   = $("<tbody>");

        var row_hder    = $('<tr><th width="49%">Oncore Value Set</th><th width="37%">Redcap Value Set</th><th wdith="14%" class="centered">Map Status</th></tr>');

        main_tr.append(main_td);
        main_tr.append(junk_td);
        main_td.append(row_table);
        row_table.append(table_bdy);
        table_bdy.append(row_hder);

        for(var ocidx in oncore_vset){
            var oc_value = oncore_vset[ocidx];
            var selected    = val_mapping[oc_value];
            var rc_selects  = makeRCValueSelect(redcap_vset, oncore_field+"_"+ocidx, selected);
            var oc_tr       = $("<tr>");
            var oc_td_val   = $("<td>").addClass("oc_value").html(oc_value);
            var rc_sel      = $("<td>").addClass("rc_val_selects").append(rc_selects);
            var map_status  = $("<td>").addClass("centered").addClass("status").html('<i class="fa fa-times-circle"></i><i class="fa fa-check-circle"></i>');

            oc_tr.append(oc_td_val);
            oc_tr.append(rc_sel);
            oc_tr.append(map_status);

            table_bdy.append(oc_tr);

            if(selected){
                map_status.addClass("ok");
            }
        }

        //remove existing "more" row
        if($("tr.more."+oncore_field).length){
            $("tr.more."+oncore_field).remove();
        }

        //append new "more" row
        main_tr.insertAfter($("tr."+oncore_field));
    }

    function makeRCValueSelect(redcap_vset, value_name, hotone){
        var select_html = $("<select>").addClass("form-select form-select-sm redcap_value").attr("name", value_name);
        var null_opt    = $("<option>").val("-99").text("-Map REDCap Value-");
        select_html.append(null_opt);

        for(var rcidx in redcap_vset){
            var rc_val  = redcap_vset[rcidx];
            var opt     = $("<option>").val(rcidx).text(rc_val);
            if(hotone && hotone == rcidx){
                opt.prop("selected", "selected");
            }
            select_html.append(opt);
        }

        return select_html;
    }
</script>
