<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$sync_diff          = $module->getSyncDiff();
$project_mappings   = $module->getProjectFieldMappings();

function makeSyncTableHTML($records, $noredcap=null, $disabled=null){
    $html = "<table class='table table-striped $disabled'>";
    $html .= "<thead>";
    $html .= "<tr>";
    $html .= "<th style='width: 15%'>REDCap record Id</th>";
    $html .= "<th style='width: 15%'>OnCore Protocol Id</th>";
    $html .= "<th style='width: 5%'>Import</th>";
    $html .= "<th style='width: 25%'>OnCore Protocol Subject Id</th>";
    $html .= "<th style='width: 10%'>OnCore Data</th>";
    $html .= "<th style='width: 10%'>REDCap Data</th>";
    $html .= "<th style='width: 15%'>Linkage Status</th>";
    $html .= "<th style='width: 15%'>Timestamp of last scan</th>";
    $html .= "</tr>";

    $html .= "</thead>";
    $html .= "<tbody>";

    foreach($records as $rc_id => $rows){
        if($noredcap){
            $rc_id = "";
        }
        $rowspan        = count($rows);
        $print_rowspan  = false;
        foreach($rows as $row){
            $oc_id          = $row["oc_id"];
            $entity_id      = $row["entity_id"];
            $oc_pr_id       = $row["oc_pr_id"];
            $oc_data        = $row["oc_data"];
            $rc_data        = $row["rc_data"];
            $link_status    = $row["link_status"];
            $ts_last_scan   = $row["ts_last_scan"];
            $diffmatch      = $oc_data == $rc_data ? "match" : "diff";

            $html .= "<tr class='$diffmatch'>";
            if(!$print_rowspan){
                $print_rowspan = true;
                $html .= "<td class='rc_id' rowspan=$rowspan>$rc_id</td>";
            }
            $html .= "<td class='oc_id'>$oc_id</td>";
            $html .= "<td class='import'><input type='checkbox' name='entityid_$entity_id' value='1' checked/></td>";
            $html .= "<td class='oc_pr_id'>$oc_pr_id</td>";
            $html .= "<td class='oc_data data'>$oc_data</td>";
            $html .= "<td class='rc_data data'>$rc_data</td>";
            $html .= "<td class='link_status'>$link_status</td>";
            $html .= "<td class='ts_last_scan'>$ts_last_scan</td>";
            $html .= "</tr>";
        }
    }

    $html .= "</tbody>";
    $html .= "<tfoot>";
    $html .= "<tr>";
    $html .= "<td colspan=8 align='right'>";
    $html .= "<button type='submit' class='more-button $disabled'>Accept Oncore Data</button>";
    $html .= "</td>";
    $html .= "</tr>";
    $html .= "</tfoot>";
    $html .= "</table>";
    return $html;
}
?>
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/open_framework/packages/bootstrap-2.3.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">

<form id="oncore_mapping" class="container">
    <h2>OnCore Adjudication - Sync Diff</h2>
    <p class="lead">Data Stored in OnCore must be synced and adjudicated periodically.  The data will be pulled into an entity table and then matched against this projects REDCap data on the mapped fields.</p>


    <ul class="nav nav-tabs">
        <li class="active"><a data-toggle="tab" href="#fullmatch">Full Match</a></li>
        <li><a data-toggle="tab" href="#oncore">Partial Oncore Match</a></li>
        <li><a data-toggle="tab" href="#redcap">Partial REDCap Match</a></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane active" id="fullmatch">
            <h2>Full Match OnCore - REDCap</h2>
            <p>Records were found in both OnCore and this REDCap project</p>

            <form class="oncore_match">
                <input type="hidden" name="matchtype" value="fullmatch"/>

                <?=makeSyncTableHTML($sync_diff["match"]);?>
            </form>
        </div>
        <div class="tab-pane" id="oncore">
            <h2>Partial Match OnCore - Not in REDCap</h2>
            <p>Records were found in OnCore but not in REDCap</p>

            <form class="oncore_match">
                <input type="hidden" name="matchtype" value="fullmatch"/>

                <?=makeSyncTableHTML($sync_diff["oncore"], true);?>
            </form>
        </div>
        <div class="tab-pane" id="redcap">
            <h2>Partial Match REDCap - Not in OnCore</h2>
            <p>Records were found in this REDCap project but not in OnCore</p>
            <em>This functionality is not available for Phase 1</em>

            <form class="oncore_match">
                <input type="hidden" name="matchtype" value="fullmatch"/>

                <?=makeSyncTableHTML($sync_diff["redcap"], false,"disabled");?>

            </form>
        </div>
    </div>

    <style>
        td.rc_id,
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
        tr.diff td:not(.rc_id){
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
    </style>
</form>
<script>
    $(document).ready(function () {
        var ajax_endpoint = "<?=$ajax_endpoint?>";
        var mrn_fields = <?=json_encode($oncore_fields)?>;

    });
</script>
<?php
