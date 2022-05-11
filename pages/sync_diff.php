<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$oncore_css         = $module->getUrl("assets/styles/oncore.css");
$oncore_js          = $module->getUrl("assets/scripts/oncore.js");
$icon_ajax          = $module->getUrl("assets/images/icon_ajax.gif");
$ajax_endpoint      = $module->getUrl("ajax/handler.php");
$sync_diff          = $module->getSyncDiff();
$sync_summ          = $module->getSyncDiffSummary();

$total_subjects     = $sync_summ["total_count"];
$full_match_count   = $sync_summ["full_match_count"];
$partial_match_count= $sync_summ["partial_match_count"];
$oncore_count       = $sync_summ["oncore_only_count"];
$redcap_count       = $sync_summ["redcap_only_count"];

$sample_ts          = null;
if($full_match_count || $partial_match_count || $oncore_count){
    if($full_match_count || $partial_match_count){
        $sample_ts = current(current($sync_diff["match"]["included"]));
    }elseif($oncore_count){
        $sample_ts = current(current($sync_diff["oncore"]["included"]));
    }

    if(!empty($sample_ts) ){
        $sample_ts = $sample_ts["ts_last_scan"];
    }
}

function makeSyncTableHTML($records, $noredcap=null, $disabled=null, $excluded=null){
    global $module;

    $show_all_btn = !$noredcap && !$disabled ? "<button class='btn btn-info show_all_matched'>Show All</button>" : "";
    $excludes_cls = $excluded ? "excludes" : "includes";
    $html = "$show_all_btn<table class='table table-striped $disabled $excludes_cls'>";
    $html .= "<thead>";
    $html .= "<tr>";
    $html .= "<th style='width: 6%' class='import'><input type='checkbox' name='check_all' class='check_all' value='1' checked/> All</th>";
    $html .= "<th style='width: 25%'>Subject Details</th>";
    $html .= "<th style='width: 25%'>OnCore Property</th>";
    $html .= "<th style='width: 22%'>OnCore Data</th>";
    $html .= "<th style='width: 22%'>REDCap Data</th>";
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

            $oc_alias       = $module->getMapping()->getOncoreAlias($oc_field);
            $oc_description = $module->getMapping()->getOncoreDesc($oc_field);
            $oc_type        = $module->getMapping()->getOncoreType($oc_field);

            $ts_last_scan   = $row["ts_last_scan"];

            $rc = !empty($rc_field) ? $rc_data : "";
            $oc = !empty($oc_field) ? $oc_data : "";

            if ($oc_type == "array") {
                if (!is_array($oc)) {
                    $oc = json_decode($oc, 1);
                }

                // race values are array for redcap and oncore need to decode oncore and compare the arrays.
                $diff = array_diff($oc, $rc_data);
                $diffmatch = empty($diff) ? "match" : "diff";

                $oc = implode(", ", array_filter($oc));

            } else {
                $diffmatch = $oc_data == $rc_data ? "match" : "diff";
            }
            $showit = $diffmatch == 'diff' ? 'showit' : '';
            if(is_array($rc)){
                $rc = implode(", ", array_filter($rc) );

            }

            $html .= "<tr class='$diffmatch $showit'>";
            if(!$print_rowspan){
                $print_rowspan  = true;
                $id_info        = array();
                if(!empty($mrn)) {
                    $id_info[] = "MRN : $mrn";
                }
                if(!empty($rc_id)) {
                    $id_info[] = "REDCap ID : $rc_id";
                }
                if (!empty($oc_pr_id)) {
                    $id_info[] = "OnCore Subject ID : $oc_pr_id";
                }
                $exclude_class = $excluded ? "include_subject" : "exclude_subject";
                $exclude_text = $excluded ? "Re-Include" : "Exclude";
                $id_info[] = "<button class='btn btn-sm btn-danger $exclude_class' data-entity_id='$entity_id' data-subject_mrn='$mrn'>$exclude_text</button>";
                $id_info = implode("<br>", $id_info);
                $html .= "<td class='import' rowspan=$rowspan><input type='checkbox' class='accept_diff' name='approved_ids' data-rc_id='$rc_id' value='$oc_pr_id' checked/></td>";
                $html .= "<td class='rc_id' rowspan=$rowspan>$id_info</td>";
            }
            $html .= "<td class='oc_data oc_field $showit'>$oc_alias</td>";
            $html .= "<td class='oc_data data $showit'>$oc</td>";
            $html .= "<td class='rc_data data $showit'>$rc</td>";
            $html .= "</tr>";
        }
        $html .= "</tbody>";
    }

    $html .= "<tfoot>";
    $html .= "<tr>";
    $html .= "<td colspan=6 align='right'>";

    if(!$excluded){
        if ($disabled) {
//            $html .= "<button type='submit' class='btn btn-warning download_partial_redcap_csv'>Download CSV</button>";
        } else {
//            $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button> <button type='submit' class='btn btn-warning download_partial_oncore_csv'>Download CSV</button>";
            $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button>";
        }
    }

    $html .= "</td>";
    $html .= "</tr>";
    $html .= "</tfoot>";
    $html .= "</table>";
    return $html;
}
function makeOncoreTableHTML($records, $noredcap=null, $disabled=null, $excluded=null){
    global $module;

    $show_all_btn =  !$disabled ? "<button class='btn btn-info show_all_matched'>Show All</button>" : "";
    $excludes_cls = $excluded ? "excludes" : "includes";
    $html = "$show_all_btn<table class='table table-striped $disabled $excludes_cls'>";
    $html .= "<thead>";
    $html .= "<tr>";
    $html .= "<th style='width: 6%' class='import'><input type='checkbox' name='check_all' class='check_all' value='1' checked/> All</th>";
    $html .= "<th style='width: 32%'>Subject Details</th>";
    $html .= "<th style='width: 32%'>OnCore Property</th>";
    $html .= "<th style='width: 30%'>OnCore Data</th>";
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

            $oc_alias       = $module->getMapping()->getOncoreAlias($oc_field);
            $oc_description = $module->getMapping()->getOncoreDesc($oc_field);
            $oc_type        = $module->getMapping()->getOncoreType($oc_field);

            $ts_last_scan   = $row["ts_last_scan"];

            $diffmatch      = $oc_data == $rc_data ? "match" : "diff";

            $rc = !empty($rc_field) ? $rc_data : "";
            $oc = !empty($oc_field) ? $oc_data : "";

            if($oc_type == "array"){
                if (!is_array($oc)) {
                    $oc = json_decode($oc, 1);
                }
                $oc = implode(", ", array_filter($oc));
            }

            if(is_array($rc)){
                $rc = implode(", ", array_filter($rc) );

            }

            $html .= "<tr class='$diffmatch'>";
            if(!$print_rowspan){
                $print_rowspan  = true;
                $id_info        = array();
                if(!empty($mrn)) {
                    $id_info[] = "MRN : $mrn";
                }
                if(!empty($rc_id)) {
                    $id_info[] = "REDCap ID : $rc_id";
                }
                if(!empty($oc_pr_id)) {
                    $id_info[] = "OnCore Subject ID : $oc_pr_id";
                }
                $exclude_class  = $excluded ? "include_subject" : "exclude_subject";
                $exclude_text   = $excluded ? "Re-Include" : "Exclude";
                $id_info[]      = "<button class='btn btn-sm btn-danger $exclude_class' data-entity_id='$entity_id' data-subject_mrn='$mrn'>$exclude_text</button>";
                $id_info        = implode("<br>", $id_info);
                $html .= "<td class='import' rowspan=$rowspan><input type='checkbox' class='accept_diff' name='approved_ids' data-rc_id='$rc_id' value='$oc_pr_id' checked/></td>";
                $html .= "<td class='rc_id' rowspan=$rowspan>$id_info</td>";
            }
            $html .= "<td class='oc_data oc_field'>$oc_alias</td>";
            $html .= "<td class='oc_data data'>$oc</td>";
            $html .= "</tr>";
        }
        $html .= "</tbody>";
    }

    $html .= "<tfoot>";
    $html .= "<tr>";
    $html .= "<td colspan=6 align='right'>";

    if(!$excluded){
        if ($disabled) {
//            $html .= "<button type='submit' class='btn btn-warning download_partial_redcap_csv'>Download CSV</button>";
        } else {
//            $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button> <button type='submit' class='btn btn-warning download_partial_oncore_csv'>Download CSV</button>";
            $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button>";
        }
    }

    $html .= "</td>";
    $html .= "</tr>";
    $html .= "</tfoot>";
    $html .= "</table>";
    return $html;
}
function makeRedcapTableHTML($records, $noredcap=null, $disabled=null, $excluded=null){
    global $module;

    $show_all_btn = !$noredcap && !$disabled ? "<button class='btn btn-info show_all_matched'>Show All</button>" : "";
    $excludes_cls = $excluded ? "excludes" : "includes";
    $html = "$show_all_btn<table class='table table-striped $disabled $excludes_cls'>";
    $html .= "<thead>";
    $html .= "<tr>";
    $html .= "<th style='width: 6%' class='import'><input type='checkbox' name='check_all' class='check_all' value='1' checked/> All</th>";
    $html .= "<th style='width: 22%'>Subject Details</th>";
    $html .= "<th style='width: 25%'>Study Site</th>";
    $html .= "<th style='width: 22%'>OnCore Property</th>";
    $html .= "<th style='width: 25%'>REDCap Data</th>";
    $html .= "</tr>";
    $html .= "</thead>";

    $project_study_sites    = $module->getMapping()->getProjectSiteStudies();
//    $study_select           = implode(", ", $project_study_sites);

    foreach($records as $mrn => $rows){
        if($noredcap){
            $rc_id = "";
        }
        $rowspan        = count($rows);
        $print_rowspan  = false;

        $ts_last_scan           = null;



        $html           .= "<tbody class='$mrn' data-subject_mrn='$mrn'>";

        foreach($rows as $row){
            $entity_id      = $row["entity_id"];
            $module->emDebug($mrn, $row);
            $rc_id          = $row["rc_id"];
            $oc_field       = $row["oc_field"];
            $rc_field       = $row["rc_field"];
            $rc_data        = $row["rc_data"];

            $oc_alias       = $module->getMapping()->getOncoreAlias($oc_field);
            $oc_description = $module->getMapping()->getOncoreDesc($oc_field);
            $oc_type        = $module->getMapping()->getOncoreType($oc_field);
            $ts_last_scan   = $row["ts_last_scan"];

            $diffmatch      = "diff";

            $rc = !empty($rc_field) ? $rc_data : "";

            if(is_array($rc)){
                $rc = implode(", ", array_filter($rc) );
            }

            $html .= "<tr class='$diffmatch'>";
            if(!$print_rowspan){
                $print_rowspan  = true;
                $id_info        = array();
                if(!empty($mrn)) {
                    $id_info[] = "MRN : $mrn";
                }
                if(!empty($rc_id)) {
                    $id_info[] = "REDCap ID : $rc_id";
                }

                $study_select = "<select name='studysite_$rc_id' class='studysite' data-rc_id='$rc_id'>\r\n";
                $study_select .= "<option value=''>Select Study Site</option>\r\n";
                foreach($project_study_sites as $studysite){
                    $study_select .= "<option value='$studysite'>$studysite</option>\r\n";
                }
                $study_select .= "</select>";

                $exclude_class  = $excluded ? "include_subject" : "exclude_subject";
                $exclude_text   = $excluded ? "Re-Include" : "Exclude";
                $id_info[]      = "<button class='btn btn-sm btn-danger $exclude_class' data-entity_id='$entity_id' data-subject_mrn='$mrn'>$exclude_text</button>";
                $id_info        = implode("<br>", $id_info);
                $html .= "<td class='import' rowspan=$rowspan><input type='checkbox' class='accept_diff' name='approved_ids' value='$rc_id' checked/></td>";
                $html .= "<td class='rc_id' rowspan=$rowspan>$id_info</td>";
                $html .= "<td class='rc_study' rowspan=$rowspan>$study_select</td>";
            }
            $html .= "<td class='oc_data oc_field'>$oc_alias</td>";
            $html .= "<td class='rc_data data'>$rc</td>";
            $html .= "</tr>";
        }
        $html .= "</tbody>";
    }

    $html .= "<tfoot>";
    $html .= "<tr>";
    $html .= "<td colspan=6 align='right'>";

    if(!$excluded){
        if ($disabled) {
//            $html .= "<button type='submit' class='btn btn-warning download_partial_redcap_csv'>Download CSV</button>";
        } else {
//            $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button> <button type='submit' class='btn btn-warning download_partial_oncore_csv'>Download CSV</button>";
            $html .= "<button type='submit' class='btn btn-success'>Push to OnCore</button>";
        }
    }

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
<link rel="stylesheet" href="<?=$oncore_css?>">

<div id="oncore_mapping" class="container">
    <h3>REDCap/OnCore Interaction</h3>
    <p class="lead">Data Stored in OnCore must be synced and adjudicated periodically.  The data will be pulled into an entity table and then matched against this projects REDCap data on the mapped fields.</p>

    <?php
    $html  = "<table class='table table-striped'>";
    $html .= "<thead>";
    $html .= "<tr>";
    $html .= "<th style='width: 20%'>Last Scanned</th>";
    $html .= "<th style='width: 20%'>Total Subjects</th>";
    $html .= "<th style='width: 20%'>Full Matches</th>";
    $html .= "<th style='width: 20%'>Partial Matches</th>";
    $html .= "<th style='width: 20%'></th>";
    $html .= "</tr>";
    $html .= "</thead>";
    $html .= "<tbody>";
    $html .= "<tr>";
    $html .= "<td id='summ_last_ts'>N/A</td>";
    $html .= "<td id='summ_total_count'>$total_subjects</td>";
    $html .= "<td id='summ_full_match'>$full_match_count</td>";
    $html .= "<td id='summ_partial_match'>$partial_match_count</td>";
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
            <p>This REDCap project has <?=$total_subjects?> Subjects linked to the OnCore Protocol ID</p>

            <form id="syncFromOncore" class="oncore_match">
                <input type="hidden" name="matchtype" value="fullmatch"/>
                <?=makeSyncTableHTML($sync_diff["match"]["included"]);?>

                <br>

                <h2>Excluded Subjects</h2>
                <p>The following subjects have been excluded from syncing with Oncore</p>
                <?=makeSyncTableHTML($sync_diff["match"]["excluded"], null, "disabled", true);?>
            </form>
        </div>

        <div class="tab-pane" id="oncore">
            <h2>Unlinked Oncore Subjects not linked in this REDCap Project</h2>
            <p>The following Subjects were found in the OnCore Protocol.  Click "Import Subjects" to create the following subjects in this project.</p>

            <form id="pullFromOncore" class="oncore_match">
                <input type="hidden" name="matchtype" value="oncoreonly"/>
                <?=makeOncoreTableHTML($sync_diff["oncore"]["included"], true);?>

                <br>

                <h2>Excluded Subjects</h2>
                <p>The following subjects have been excluded from syncing with REDCap</p>
                <?=makeOncoreTableHTML($sync_diff["oncore"]["excluded"], true, "disabled", true);?>
            </form>
        </div>

        <div class="tab-pane" id="redcap">
            <h2>Unlinked REDCap Subjects not found in OnCore Protocol</h2>
            <p>The following REDCap records do not have a matching MRN with subjects in the OnCore Protocol</p>
            <p>In order to import these records into OnCore , download the CSV and submit to your OnCore administrator</p>
            <em>This functionality is not available for Phase 1</em>

            <form id="pushToOncore" class="oncore_match">
                <input type="hidden" name="matchtype" value="redcaponly"/>
                <?=makeRedcapTableHTML($sync_diff["redcap"]["included"], false);?>

                <br>

                <h2>Excluded Subjects</h2>
                <p>The following subjects have been excluded from syncing with Oncore</p>
                <?=makeRedcapTableHTML($sync_diff["redcap"]["excluded"], false,"disabled", true);?>
            </form>
        </div>
    </div>
</div>
<script>
    $(document).ready(function () {
        var ajax_endpoint   = "<?=$ajax_endpoint?>";
        var last_scan_ts    = "<?=$sample_ts?>";
        if(last_scan_ts){
            $("#summ_last_ts").html(last_scan_ts);
        }

        //TAB BEHAVIOR
        $("#oncore_mapping ul.nav-tabs a").on("click", function () {
            $("li.active").removeClass("active");
            $(this).parent("li").addClass("active");
        });

        //SHOW "no diff" MATCHES
        $(".show_all_matched").on("click", function (e) {
            e.preventDefault();

            console.log($(this).hasClass('expanded'))
            if (!$(this).hasClass('expanded')) {
                $(".show_all_matched").html("Show Less");
                $("tr td.rc_data, tr td.oc_data").show();
                $(this).addClass('expanded')
            } else {
                $(".show_all_matched").html("Show All");
                $("tr td.rc_data, tr td.oc_data").hide();
                $(this).removeClass('expanded')
            }
        });

        //CHECKBOX BEHAVIOR
        $(".check_all").on("change", function () {
            if ($(this).is(":checked")) {
                //check all
                $(this).closest("table").find(".accept_diff").prop("checked", true);
            } else {
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
        $(".oncore_match").on("click",".exclude_subject, .include_subject", function(e){
            e.preventDefault();
            var subject_mrn         = $(this).data("subject_mrn");
            var entity_record_id    = $(this).data("entity_id");

            var _parent_tbody       = $("tbody."+subject_mrn);
            var _parent_form        = _parent_tbody.closest(".oncore_match");

            var exclude_include     = $(this).hasClass("exclude_subject") ? "excludeSubject" : "includeSubject";

            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": exclude_include,
                    "entity_record_id": entity_record_id,
                },
                dataType: 'json'
            }).done(function (result) {
                //fade the row
                if(exclude_include == "excludeSubject"){
                    var _parent_clone = _parent_tbody.clone();
                    _parent_tbody.fadeOut("fast", function(){
                        //clone the row to exluded table
                        _parent_form.find("table.excludes thead").after(_parent_clone);

                        //disable clone
                        _parent_clone.find(".exclude_subject").text("Re-include").addClass("include_subject").removeClass("exclude_subject");
                    });
                }else{
                    var _parent_clone = _parent_tbody.clone();
                    _parent_tbody.fadeOut("fast", function(){
                        //clone the row to exluded table
                        _parent_form.find("table.includes thead").after(_parent_clone);

                        //disable clone
                        _parent_clone.find(".include_subject").text("Exclude").addClass("exclude_subject").removeClass("include_subject");
                    });
                }
            }).fail(function (e) {
                console.log("failed to save", e);
            });
        });

        //do fresh data manual pull
        $("#refresh_sync_diff").on("click", function(e){
            e.preventDefault();
            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": "syncDiff"
                },
                dataType: 'json'
            }).done(function (syncsummary) {
                $("#summ_total_count").html(syncsummary["total_count"]);
                $("#summ_full_match").html(syncsummary["full_match_count"]);
                $("#summ_partial_match").html(syncsummary["partial_match_count"]);

                //USE "fake now" TS for this... then when page refresh get the exact one from new syn diff
                var ts  = new Date();
                var day     = ('0' + ts.getDate()).slice(-2);
                var month   = ('0' + (ts.getMonth() + 1)).slice(-2);
                var year    = ts.getFullYear();

                var fake_date = year+"-"+month+"-"+day;
                var fake_time = ts.toLocaleTimeString('en-US', { hour12: false , hour: '2-digit', minute:'2-digit'});
                $("#summ_last_ts").html(fake_date + " " + fake_time);

                location.reload();
            }).fail(function (e) {
                console.log("failed to save", e);
            });
        });

        $("#syncFromOncore, #pullFromOncore").submit(function(e){
            e.preventDefault();

            var approved_ids    = [];
            var inputs          = $(this).find(".includes input[name='approved_ids']").each(function(){
                var _el         = $(this);
                var oncore_id   = _el.val();
                var rc_id       = _el.data("rc_id");
                approved_ids.push({"oncore" : oncore_id , "redcap" : rc_id});
            });

            showPageBlockerSpinner();
            $.ajax({
                url: ajax_endpoint,
                method: 'POST',
                data: {
                    "action": "approveSync",
                    "approved_ids": approved_ids,
                },
                dataType: 'json'
            }).done(function (result) {
                location.reload();
            }).fail(function (e) {
                console.log("failed to save", e);
                hidePageBlockerSpinner();
            });
        });

        $("#pushToOncore").submit(function(e){
            e.preventDefault();

            var inputs          = $(this).find(".includes input[name='approved_ids']").serializeArray();
            var studysites      = $(this).find(".includes .studysite").serializeArray();

            // inputs      = [{value:1}, {value:2}, {value:3}, {value:4}];
            // studysites  = [{value: "shc"}, {value: "lpch"}, {value:"sutter"}, {value:"kaiser"}];

            if(inputs.length){
                //will have same index
                showPushModal(inputs,studysites);
                var total_uploads       = inputs.length;
                var finished_uploads    = 0;
                for(var i in inputs){
                    var rc_id       = inputs[i]["value"];
                    var site_temp   = studysites[i]["name"];
                    var site_val    = studysites[i]["value"];
                    var temp        = {"redcap_id" : rc_id, "study_site" : site_val}

                    $.ajax({
                        url: ajax_endpoint,
                        method: 'POST',
                        data: {
                            "action": "pushToOncore",
                            "approved_ids": temp
                        },
                        dataType: 'json'
                    }).done(function (rc_id) {
                        const rndInt = randomIntFromInterval(1000, 5000);
                        setTimeout(function () {
                            finished_uploads++;
                            drawPBAR(finished_uploads, total_uploads);
                            $(".pushTBL td[data-pbrcid='" + rc_id + "']").addClass('alert alert-success').text("ok");
                        }, rndInt);
                    }).fail(function (e) {
                        const rndInt = randomIntFromInterval(1000, 5000);
                        setTimeout(function () {
                            finished_uploads++;
                            drawPBAR(finished_uploads, total_uploads);
                            var result = e.responseJSON
                            $(".pushTBL td[data-pbrcid='" + result['rc_id'] + "']").addClass('alert alert-danger').text(result['message']);
                        }, rndInt);

                    });
                }

                function hidePageBlockerSpinner() {
                    $("#blockingOverlay").remove();
                }
            }
        })
    });

    function showPushModal(inputs, studysites){
        var opaque  = $("<div>").attr("id","blockingOverlay");

        var modal   = $("<div>").attr("id", "pushModal");
        var hdr     = $("<h2>").addClass("pushHDR").text("Pushing REDCap to OnCore");
        var close   = $('<button type="button" class="close" data-dismiss="modal">×</button>');
        hdr.append(close);

        var bdy     = $("<div>").addClass("pushBDY");
        var lead    = $('<p class="lead">Please leave this modal open while we push the following REDCap records to OnCore</p>');
        bdy.append(lead);

        var pbar    = $('<div id="pbar_box"><span id="pbar"></span></div>');
        bdy.append(pbar);

        var tbl     = $("<table>").addClass("pushTBL");
        bdy.append(tbl);

        var row = $("<tr><th>REDCap ID</th><th>Study Site</th><th>Push Status</th></tr>");
        tbl.append(row);
        for(var i in inputs){
            var row = $("<tr><td>"+inputs[i]["value"]+"</td><td>"+studysites[i]["value"]+"</td><td data-pbrcid='"+inputs[i]["value"]+"'></td></tr>");
            tbl.append(row);
        }

        var ftr     = $("<div>").addClass("pushFTR").text("ftr");
        modal.append(hdr);
        modal.append(bdy);
        // modal.append(ftr);

        close.on("click",function(){
            hidePushModal();
        });

        opaque.appendTo("body");
        modal.appendTo(opaque);
    }

    function hidePushModal(){
        $("#blockingOverlay").remove();
    }

    function drawPBAR(finished, total){
        var perc = finished/total;
        var pbar_width = Math.round(perc * 100)+ "%";
        $("#pbar").width(pbar_width);
    }

    function showMessageModal(){
        var opaque = $("<div>").attr("id","blockingOverlay");
        opaque.appendTo("body");
    }

    function hideMessageModal(){

    }

    function randomIntFromInterval(min, max) { // min and max included
        return Math.floor(Math.random() * (max - min + 1) + min)
    }
</script>
<style>
#blockingOverlay{
    position: fixed; /* Sit on top of the page content */
    width: 100%; /* Full width (cover the whole page) */
    height: 100%; /* Full height (cover the whole page) */
    top: 0; left: 0; right: 0; bottom: 0;
    border:1px transparent #fff;
}
#blockingOverlay:before{
    content:"";
    border:1px transparent #fff;
    background-color: #000;
    position: absolute; /* Sit on top of the page content */
    width: 100%; /* Full width (cover the whole page) */
    height: 100%; /* Full height (cover the whole page) */
    top: 0; left: 0; right: 0; bottom: 0;
    /*background-image:url(*/<?//=$icon_ajax?> /*);*/
    background-repeat: no-repeat;
    background-position: 50% 40%;
    background-size: 10%;
    background-color: rgba(0, 0, 0, 0.5); /* Black background with opacity */
    z-index: 20; /* Specify a stack order in case you're using a different order for other elements */
    cursor: pointer; /* Add a pointer on hover */
}

#pushModal {
    position: absolute;
    width: 850px;
    height: auto;
    border: 1px solid #999;
    border-radius: 5px;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    z-index: 21;
}

.pushHDR,
.pushBDY,
.pushFTR{

}

.pushHDR{
    padding:15px;
    box-shadow: 0px 0px 5px 0px #ccc;
}
.pushBDY{
}

.pushBDY .lead{
    padding:5px 15px;
}

#pbar_box{
    border:1px solid #666;
    border-radius:5px;
    margin:0 15px;
}

#pbar{
    background:#63A4FC;
    height:20px; width:0;
    display:block;
    border-radius:3px;
}

.pushTBL {
    width: calc(100% - 30px);
    margin: 15px;
    height: 100%;
    max-height: 240px;
    overflow: scroll;
}
.pushTBL th{
    color:#666;
    border-bottom:1px solid #ccc;
}
.pushTBL td {
    padding:5px;
}
</style>
<?php
