<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$ajax_endpoint      = $module->getUrl("ajax/handler.php");
$sync_diff          = $module->getSyncDiff();

$full_match_count   = count($sync_diff["match"]);
$oncore_count       = count($sync_diff["oncore"]);
$redcap_count       = count($sync_diff["redcap"]);

if(isset($_GET["download_csv"])){
    if(!empty($_GET["bin"])){
        $bin = filter_var($_GET["bin"], FILTER_SANITIZE_STRING);
        switch($bin){
            case "fullmatch":
                makeSyncTableCSV($sync_diff["match"], $bin);
                break;

            case "oncore_only":
                makeSyncTableCSV($sync_diff["oncore"], $bin);
                break;

            case "redcap_only":
                makeSyncTableCSV($sync_diff["redcap"], $bin);
                break;
        }
    }
    exit;
}

function makeSyncTableCSV($records, $bin){
    global $module;

    $headers        = array("MRN","REDCap ID", "OnCore Subject ID", "OnCore Field" , "OnCore Data", "REDCap Field", "REDCap Data", "REDCap Event" );
    $output_dest    = 'php://output';
    $output         = fopen($output_dest, 'w') or die('Can\'t create .csv file, try again later.');

    //Add the headers
    fputcsv($output, $headers);

    // write each row at a time to a file
    foreach($records as $mrn => $rows){
        foreach($rows as $row){
            $row_array = array();
            $row_array[]    = $mrn;
            $row_array[]    = $row["rc_id"];
            $row_array[]    = $row["oc_pr_id"];
            $row_array[]    = $row["oc_field"];
            $row_array[]    = $row["oc_data"];
            $row_array[]    = $row["rc_field"];
            $row_array[]    = $row["rc_data"];
            $row_array[]    = $row["rc_event"];

            fputcsv($output, $row_array);
        }
    }

    // output headers so that the file is downloaded rather than displayed
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename='.$bin.'.csv');
}
function makeSyncTableHTML($records, $noredcap=null, $disabled=null){
    global $module;

    $show_all_btn = !$noredcap && !$disabled ? "<button class='btn btn-info show_all_matched'>Show All</button>" : "";

    $html = "<table class='table table-striped $disabled'>";
    $html .= "<thead>";
    $html .= "<tr>";
    $html .= "<th style='width: 7%'><input type='checkbox' name='check_all' class='check_all' value='1' checked/> All</th>";
    $html .= "<th style='width: 23%'>Subject Details</th>";
    $html .= "<th style='width: 20%'>OnCore Field</th>";
    $html .= "<th style='width: 25%'>OnCore Data</th>";
    $html .= "<th style='width: 25%'>REDCap Data $show_all_btn</th>";
    $html .= "</tr>";
    $html .= "</thead>";

    foreach($records as $mrn => $rows){
        if($noredcap){
            $rc_id = "";
        }
        $rowspan        = count($rows);
        $print_rowspan  = false;

        $ts_last_scan   = null;

        $html           .= "<tbody class='$mrn' data-subject_mrn='$mrn'>";
        foreach($rows as $row){
            $entity_id      = $row["entity_id"];

            $oc_id          = $row["oc_id"];
            $oc_pr_id       = $row["oc_pr_id"];
            $rc_id          = $row["rc_id"];

            $oc_field       = $row["oc_field"];
            $rc_field       = $row["rc_field"];

            $rc_data        = $row["rc_data"];
            $oc_data        = $row["oc_data"];

            $link_status    = $row["link_status"];
            $ts_last_scan   = $row["ts_last_scan"];
            $diffmatch      = $oc_data == $rc_data ? "match" : "diff";

            $rc = !empty($rc_field) ? "<b>$rc_field</b> : $rc_data" : "";
            $oc = !empty($oc_field) ? "$oc_data" : "";

            $html .= "<tr class='$diffmatch'>";
            if(!$print_rowspan){
                $print_rowspan = true;

                $id_info = array();
                if(!empty($rc_id)) {
                    $id_info[] = "REDCap ID : $rc_id";
                }
                if(!empty($oc_pr_id)) {
                    $id_info[] = "OnCore Subject ID : $oc_pr_id";
                }
                if(!empty($mrn)) {
                    $id_info[] = "MRN : $mrn";
                }
                $id_info = implode("<br>", $id_info);

                $html .= "<td class='import' rowspan=$rowspan><input type='checkbox' class='accept_diff' name='entityid_$entity_id' value='1' checked/> <button class='btn btn-sm btn-danger exclude_subject' data-subject_mrn='$mrn'>Exclude</button></td>";
                $html .= "<td class='rc_id' rowspan=$rowspan>$id_info</td>";
            }
            $html .= "<td class='oc_data oc_field'>$oc_field</td>";
            $html .= "<td class='oc_data data'>$oc</td>";
            $html .= "<td class='rc_data data'>$rc</td>";
            $html .= "</tr>";
        }
        $html .= "</tbody>";
    }


    $html .= "<tfoot>";
    $html .= "<tr>";
    $html .= "<td colspan=6 align='right'>";

    if($disabled){
        $which_bin = "redcap_only";
    }else{
        $which_bin = "fullmatch";
        if($noredcap){
            $which_bin = "oncore_only";
        }
        $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button>";
    }

    $html .= " <a href='#' class='btn btn-warning download_csv' data-bin='$which_bin'>Download CSV</a>";

    $html .= "</td>";
    $html .= "</tr>";
    $html .= "</tfoot>";
    $html .= "</table>";
    return $html;
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
?>
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/open_framework/packages/bootstrap-2.3.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">

<form id="oncore_mapping" class="container">
    <h3>OnCore Adjudication - Sync Diff</h3>
    <p class="lead">Data Stored in OnCore must be synced and adjudicated periodically.  The data will be pulled into an entity table and then matched against this projects REDCap data on the mapped fields.</p>

    <?php
    $html = "<table class='table table-striped'>";
    $html .= "<thead>";
    $html .= "<tr>";
    $html .= "<th style='width: 25%'>Timestamp of Last Scan</th>";
    $html .= "<th style='width: 25%'>Total Count</th>";
    $html .= "<th style='width: 25%'>New Count</th>";
    $html .= "<th style='width: 25%'></th>";
    $html .= "</tr>";
    $html .= "</thead>";
    $html .= "<tbody>";
    $html .= "<tr>";
    $html .= "<td>03/15/22 10:00</td>";
    $html .= "<td>128</td>";
    $html .= "<td>6</td>";
    $html .= "<td><button class='btn btn-danger' id='refresh_sync_diff'>Refresh Sync Data</button></td>";
    $html .= "</tr>";
    $html .= "</tbody>";
    $html .= "<tfoot>";
    $html .= "</tfoot>";
    $html .= "</table>";

    echo $html;
    ?>

    <h3>Adjudication required for following Subjects</h3>
    <ul class="nav nav-tabs">
        <li class="active"><a data-toggle="tab" href="#fullmatch">Linked Subjects <span class="badge badge-light"><?=$full_match_count?></span></a></li>
        <li><a data-toggle="tab" href="#oncore">Unlinked Oncore Subjects <span class="badge badge-light"><?=$oncore_count?></span></a></li>
        <li><a data-toggle="tab" href="#redcap">Unlinked REDCap Subjects <span class="badge badge-light"><?=$redcap_count?></span></a></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane active" id="fullmatch">
            <h2>Linked Subjects</h2>
            <p>This REDCap project has X records linked to OnCore Protocol ID </p>

            <form class="oncore_match">
                <input type="hidden" name="matchtype" value="fullmatch"/>
                <?=makeSyncTableHTML($sync_diff["match"]);?>


                <h2>Exlcuded Subjects</h2>
                <table class="table table-striped disabled excludes">
                    <thead>
                    <tr>
                        <th style="width: 7%"></th>
                        <th style="width: 23%">Subject Details</th>
                        <th style="width: 20%">OnCore Field</th>
                        <th style="width: 25%">OnCore Data</th>
                        <th style="width: 25%">REDCap Data </th>
                    </tr>
                    </thead>
                </table>
            </form>
        </div>

        <div class="tab-pane" id="oncore">
            <h2>Unlinked Oncore Subjects not linked in this REDCap Project</h2>
            <p>The following Subjects were found in the OnCore Protocol.  Click "Import Subjects" to create the following subjects in this project.  (TODO IF auto increment is disabled , get from PROJ object) The REDCap record will default to the OnCore Protocol Subject ID. </p>

            <form class="oncore_match">
                <input type="hidden" name="matchtype" value="oncoreonly"/>
                <?=makeSyncTableHTML($sync_diff["oncore"], true);?>

                <h2>Exlcuded Subjects</h2>
                <table class="table table-striped disabled excludes">
                    <thead>
                    <tr>
                        <th style="width: 7%"></th>
                        <th style="width: 23%">Subject Details</th>
                        <th style="width: 20%">OnCore Field</th>
                        <th style="width: 25%">OnCore Data</th>
                        <th style="width: 25%">REDCap Data </th>
                    </tr>
                    </thead>
                </table>
            </form>
        </div>

        <div class="tab-pane" id="redcap">
            <h2>Unlinked REDCap Subjects not found in OnCore Protocol</h2>
            <p>The following REDCap records do not have a matching MRN with subjects in the OnCore Protocol</p>
            <p>In order to import these records into OnCore , download the CSV and submit to your OnCore administrator</p>
            <em>This functionality is not available for Phase 1</em>

            <form class="oncore_match">
                <input type="hidden" name="matchtype" value="redcaponly"/>
                <?=makeSyncTableHTML($sync_diff["redcap"], false,"disabled");?>

                <h2>Exlcuded Subjects</h2>
                <table class="table table-striped disabled excludes">
                    <thead>
                    <tr>
                    <th style="width: 7%"></th>
                    <th style="width: 23%">Subject Details</th>
                    <th style="width: 20%">OnCore Field</th>
                    <th style="width: 25%">OnCore Data</th>
                    <th style="width: 25%">REDCap Data </th>
                    </tr>
                    </thead>
                </table>
            </form>
        </div>
    </div>

    <style>
        #oncore_mapping th {
            position:relative;
        }
        .show_all_matched {
            position:absolute;
            right:10px; bottom:5px;
        }

        #oncore_mapping ul.nav-tabs{
            position:relative;
        }
        #refresh_syncdiff{
            position:absolute;
            right:0;
        }
        #refresh_syncdiff i{
            display:inline-block;
            margin-right:5px;
            vertical-align: bottom;
        }

        td.import,
        td.link_status{
            text-align:center;
        }
        tr.match td{
            background:#F9F9F9;
        }
        tr.match td.data{
            color:#0f6b58;
        }

        tr.match td.rc_data,
        tr.match td.oc_data{
            display:none;
        }

        tr.match td.rc_data.showit,
        tr.match td.oc_data.showit{
            display:table-cell;
        }

        tr.diff td:not(.rc_id, .import, .ts_last_scan, .link_status){
            background:#f2dede !important;
        }
        tr.diff td.data{
            color:#e74c3c;
            font-weight:bold;
        }



        button.disabled,
        .disabled tr.match td,
        .disabled tr.diff td {
            background:#efefef !important;
            color:#b3b3b3 !important;
        }

        td.import,
        td.ts_last_scan,
        td.rc_id,
        td.oc_data,
        td.link_status{
            border-left:1px solid #B9B9B9;
        }

        .check_all {

        }

        table.excludes td.import *{
            display:none;
        }
    </style>
</form>
<script>
    $(document).ready(function () {
        var ajax_endpoint   = "<?=$ajax_endpoint?>";
        var mrn_fields      = <?=json_encode($oncore_fields)?>;

        //TAB BEHAVIOR
        $("#oncore_mapping ul.nav-tabs a").on("click", function(){
            $("li.active").removeClass("active");
            $(this).parent("li").addClass("active");
        });

        //SHOW "no diff" MATCHES
        $(".show_all_matched").on("click", function(e){
            e.preventDefault();

            if(!$("tr.match td.rc_data").is(":visible")){
                $("tr.match td.rc_data, tr.match td.oc_data").show();
            }else{
                $("tr.match td.rc_data, tr.match td.oc_data").hide();
            }
        });

        //CHECKBOX BEHAVIOR
        $(".check_all").on("change",function(){
            if($(this).is(":checked")){
                //check all
                $(this).closest("table").find(".accept_diff").prop("checked",true);
            }else{
                //uncheck all
                $(this).closest("table").find(".accept_diff").prop("checked",false);
            }
        });
        $(".accept_diff").on("change", function(){
            if(!$(this).is(":checked")){
                //uncheck "check_all"
                $(this).closest("table").find(".check_all").prop("checked",false);
            }
        });

        //DOWNLOAD CSV
        $(".download_csv").on("click",function(e){
            e.preventDefault();
            var whichbin    = $(this).data("bin");

            //TODO NEED TO FIGURE THIS OUT
            var actual_link = "<?php echo "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]" ?>";
            var csv_link    = actual_link + "&download_csv=1&bin=" + whichbin;
            location.href=csv_link;
        });

        //EXCLUDE SUBJECT FROM DIFF OVERWRITE
        $(".exclude_subject").on("click", function(e){
            e.preventDefault();
            var subject_mrn     = $(this).data("subject_mrn");

            var _parent_tbody   = $("tbody."+subject_mrn);
            var _parent_form   = _parent_tbody.closest(".oncore_match");

            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": "excludeSubject",
                    "subject_mrn": subject_mrn,
                },
                dataType: 'json'
            }).done(function (result) {
                console.log("saved?",result);

                //fade the row
                var _parent_clone = _parent_tbody.clone();
                _parent_tbody.fadeOut("fast", function(){
                    //clone the row to exluded table
                    _parent_form.find("table.excludes thead").after(_parent_clone);

                    //disable clone
                    console.log("why wont td.import empty?", _parent_clone.find("td.import button").length);
                    _parent_clone.find("td.import").empty();
                });
            }).fail(function (e) {
                console.log("failed to save", e);
            });
        });
    });
</script>
<?php
