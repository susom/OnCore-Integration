<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$ajax_endpoint = $module->getUrl("ajax/handler.php");

//GET FIELDNAMES for Oncore and Current Project
$project_fields     = $module->getProjectFields();
$oncore_fields      = $module->getOnCoreFields();
$project_mappings   = $module->getProjectFieldMappings();

$required_fields    = $module->getRequiredOncoreFields();
$module->emDebug($required_fields);

//REDCap Data Dictionary Fields w/ generic 'xxx'name
$select = "<select class='form-select form-select-sm mrn_field' name='[ONCORE_FIELD]'>\r\n";
$select .= "<option value=-99>-Map REDCap Field-</option>";
foreach ($project_fields as $event_name => $fields) {
    $select .= "<optgroup label='$event_name'>\r\n";
    foreach ($fields as $field) {
        $select .= "<option $selected_val data-eventname='$event_name' value='$field'>$field</option>\r\n";
    }
    $select .= "</optgroup>\r\n";
}
$select .= "</select>\r\n";

//OnCore Static Field names need mapping to REDCap fields
$required_html  = "";
$not_required   = "";
$not_shown      = 0;
foreach ($oncore_fields as $field) {
    //each select will have different input['name']
    $map_select     = str_replace("[ONCORE_FIELD]", $field, $select);
    $icon_status    = "fa-times-circle";
    $required       = null;
    $event_name     = null;

    if( in_array($field, $required_fields) ){
        $required = "required";
    }

    if (array_key_exists($field, $project_mappings)) {
        $rc_field       = $project_mappings[$field];
        $icon_status    = "fa-check-circle";

        $rc             = $rc_field["redcap_field"];
        $event_name     = $rc_field["event"];

        $map_select = str_replace("'$rc'", "'$rc' selected", $map_select);
        $required = "required";
    }

    if(!$required){
        $not_shown++;
        $not_required .= "<tr class='$field notrequired'>\r\n";
        $not_required .= "<td>$field</td>";
        $not_required .= "<td>$map_select</td>";
        $not_required .= "<td>$event_name</td>";
        $not_required .= "<td class='centered'><i class='fa $icon_status'></i></td>";
        $not_required .= "</tr>\r\n";
    }else{
        $required_html .= "<tr class='$field $required'>\r\n";
        $required_html .= "<td>$field</td>";
        $required_html .= "<td>$map_select</td>";
        $required_html .= "<td>$event_name</td>";
        $required_html .= "<td class='centered'><i class='fa $icon_status'></i></td>";
        $required_html .= "</tr>\r\n";
    }
}
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
            <th style="width: 35%">OnCore Field</th>
            <th style="width: 35%">REDCap Field</th>
            <th style="width: 15%">REDCap Event</th>
            <th style="width: 15%">Map Status</th>
        </tr>
        </thead>
        <tbody>
        <?= $required_html ?>

        <tr class="show_optional required">
            <td colspan="4" ><a href="#" ><span>Show</span> Optional Fields +</a></td>
        </tr>

        <?= $not_required ?>
        </tbody>
        <tfoot>
        <tr>
            <td colspan="4" align="right">
                <button type="submit" href="#" class="more-button">Save Mappings</button>
            </td>
        </tr>
        </tfoot>
    </table>
</form>
<style>
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
</style>
<script>
    $(document).ready(function () {
        var ajax_endpoint = "<?=$ajax_endpoint?>";
        var mrn_fields = <?=json_encode($oncore_fields)?>;

        $("#oncore_mapping").submit(function (e) {
            e.preventDefault();

            var field_maps = {};
            var all_fields = $(this).find("select.mrn_field");

            all_fields.each(function (idx) {
                var el = $(this);
                var name = el.attr("name");
                var val = el.val();
                var opt = el.find("option:selected");
                var ev = opt.data("eventname");

                if (val != "-99") {
                    field_maps[name] = {"redcap_field": val, "event": ev};
                }
            });

            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": "saveMapping",
                    "field_mappings": field_maps,
                },
                dataType: 'json'
            }).done(function (result) {
                var update_keys = Object.keys(field_maps);
                var make_x = $(mrn_fields).not(update_keys).get();

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

        $("#oncore_mapping select").on("change",function(){
            if($(this).find("option:selected")){
                var _opt    = $(this).find("option:selected");
                var en      = _opt.data("eventname");
                $(this).closest("td").next().text("").text(en);
            }
        });
    });
</script>
