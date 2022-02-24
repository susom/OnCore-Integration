<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$ajax_endpoint = $module->getUrl("ajax/handler.php");

//GET FIELDNAMES for Oncore and Current Project
$project_fields = $module->getProjectFields();
$oncore_fields = $module->getOnCoreFields();
$project_mappings = $module->getProjectFieldMappings();

//REDCap Data Dictionary Fields w/ generic 'xxx'name
$select = "<select class='form-select form-select-sm mrn_field' name='xxx'>\r\n";
$select .= "<option value=-99>-Map REDCap Field-</option>";
foreach ($project_fields as $field) {
    $select .= "<option $selected_val value='$field'>$field</option>\r\n";
}
$select .= "</select>\r\n";


//OnCore Static Field names need mapping to REDCap fields
$html = "";
foreach ($oncore_fields as $field) {
    //each select will have different input['name']
    $map_select = str_replace("xxx", $field, $select);
    $icon_status = "fa-times-circle";
    if (array_key_exists($field, $project_mappings)) {
        $rc_field = $project_mappings[$field];
        $icon_status = "fa-check-circle";
        $map_select = str_replace("'$rc_field'", "'$rc_field' selected", $map_select);
    }
    $html .= "<tr class='$field'>\r\n";
    $html .= "<td>$field</td>";
    $html .= "<td>$map_select</td>";
    $html .= "<td><i class='fa $icon_status fa-2x'></i></td>";
    $html .= "</tr>\r\n";
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
            <th style="width: 55%">REDCap Field</th>
            <th style="width: 10%">Icons</th>
        </tr>
        </thead>
        <tbody>
        <?= $html ?>
        </tbody>
        <tfoot>
        <tr>
            <td colspan="3" align="right">
                <button type="submit" href="#" class="more-button">Save Mappings</button>
            </td>
        </tr>
        </tfoot>
    </table>
    </div>

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

                    if (val != "-99") {
                        field_maps[name] = val;
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
        });
    </script>
