<?php

namespace Stanford\OnCoreIntegration;

/** @var OnCoreIntegration $module */

$oncore_css = $module->getUrl("assets/styles/oncore.css");
$batch_css = $module->getUrl("assets/styles/batch_modal.css");
$oncore_js = $module->getUrl("assets/scripts/oncore.js");
$batch_js = $module->getUrl("assets/scripts/batch_modal.js");
$icon_ajax = $module->getUrl("assets/images/icon_ajax.gif");
$ajax_endpoint = $module->getUrl("ajax/handler.php");
$sync_diff = $module->getSyncDiff();
$sync_summ = $module->getSyncDiffSummary();

$total_subjects = $sync_summ["total_count"];
$full_match_count = $sync_summ["full_match_count"];
$partial_match_count = $sync_summ["partial_match_count"];
$oncore_count = $sync_summ["oncore_only_count"];
$redcap_count = $sync_summ["redcap_only_count"];

$sample_ts = null;
if ($full_match_count || $partial_match_count || $oncore_count) {
    if ($full_match_count || $partial_match_count) {
        $sample_ts = current(current($sync_diff["match"]["included"]));
    } elseif ($oncore_count) {
        $sample_ts = current(current($sync_diff["oncore"]["included"]));
    }

    if (!empty($sample_ts)) {
        $sample_ts = $sample_ts["ts_last_scan"];
    }
}

function makeSyncTableHTML($records, $noredcap = null, $disabled = null, $excluded = null)
{
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

    foreach ($records as $mrn => $rows) {
        if ($noredcap) {
            $rc_id = "";
        }
        $rowspan = count($rows);
        $print_rowspan = false;

        $ts_last_scan = null;

        $html .= "<tbody class='$mrn' data-subject_mrn='$mrn'>";
        foreach ($rows as $row) {
            $entity_id = $row["entity_id"];

            $oc_id = $row["oc_id"];
            $oc_pr_id = $row["oc_pr_id"];
            $rc_id = $row["rc_id"];

            $oc_field = $row["oc_field"];
            $rc_field = $row["rc_field"];

            $rc_data = $row["rc_data"];
            $oc_data = $row["oc_data"];

            $oc_status = $row['oc_status'];

            $oc_alias = $module->getMapping()->getOncoreAlias($oc_field);
            $oc_description = $module->getMapping()->getOncoreDesc($oc_field);
            $oc_type = $module->getMapping()->getOncoreType($oc_field);

            $ts_last_scan = $row["ts_last_scan"];

            $rc = !empty($rc_field) ? $rc_data : "";
            $oc = !empty($oc_field) ? $oc_data : "";

            if ($oc_type == "array") {
                if (!is_array($oc)) {
                    $oc = json_decode($oc, 1);
                }

                // race values are array for redcap and oncore need to decode oncore and compare the arrays.
                $diff = array_diff($oc ?: [], $rc_data ?: []);
                $diffmatch = empty($diff) ? "match" : "diff";

                $oc = implode(", ", array_filter($oc));

            } else {
                $diffmatch = $oc_data == $rc_data ? "match" : "diff";
            }
            $showit = $diffmatch == 'diff' ? 'showit' : '';
            if (is_array($rc)) {
                $rc = implode(", ", array_filter($rc));

            }

            $html .= "<tr class='$diffmatch $showit'>";
            if (!$print_rowspan) {
                $print_rowspan = true;
                $id_info = array();
                if (!empty($mrn)) {
                    $id_info[] = "MRN : $mrn";
                }
                if (!empty($rc_id)) {
                    $id_info[] = "REDCap ID : $rc_id";
                }
                if (!empty($oc_pr_id)) {
                    $id_info[] = "OnCore Subject ID : $oc_pr_id";
                }
                if (!empty($oc_status)) {
                    $id_info[] = "OnCore Subject Status : $oc_status";
                    $oc_status_class = '';
                } else {
                    $id_info[] = "<strong style='color: #e74c3c'>OnCore Subject Status : NULL(Assign status from OnCore UI)</strong>";
                    $oc_status_class = 'missing-status';
                }

                $exclude_class = $excluded ? "include_subject" : "exclude_subject";
                $exclude_text = $excluded ? "Re-Include" : "Exclude";
                $id_info[] = "<button class='btn btn-sm btn-danger $exclude_class' data-entity_id='$entity_id' data-subject_mrn='$mrn'>$exclude_text</button>";
                $id_info = implode("<br>", $id_info);
                $html .= "<td class='import' rowspan=$rowspan><input type='checkbox' class='accept_diff' name='approved_ids' data-rc_id='$rc_id'  data-mrn='$mrn'  value='$oc_pr_id' checked/></td>";
                $html .= "<td class='rc_id $oc_status_class' rowspan=$rowspan>$id_info</td>";
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

    if (!$excluded) {
        if ($disabled) {
//            $html .= "<button type='submit' class='btn btn-warning download_partial_redcap_csv'>Download CSV</button>";
        } else {
//            $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button> <button type='submit' class='btn btn-warning download_partial_oncore_csv'>Download CSV</button>";
            if (!empty($module->getMapping()->getProjectFieldMappings()['pull'])) {
                $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button>";
            } else {
                $html .= "<div class='alert alert-warning'>You can`t pull OnCore Subjects. To pull you must define pull fields on <a href='" . $module->getUrl('pages/field_map.php') . "'>mapping page</a>.</div>";
            }
        }
    }

    $html .= "</td>";
    $html .= "</tr>";
    $html .= "</tfoot>";
    $html .= "</table>";
    return $html;
}

function makeOncoreTableHTML($records, $noredcap = null, $disabled = null, $excluded = null)
{
    global $module;

    $show_all_btn = !$disabled ? "<button class='btn btn-info show_all_matched'>Show All</button>" : "";
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

    foreach ($records as $mrn => $rows) {
        if ($noredcap) {
            $rc_id = "";
        }
        $rowspan = count($rows);
        $print_rowspan = false;

        $ts_last_scan = null;

        $html .= "<tbody class='$mrn' data-subject_mrn='$mrn'>";
        foreach ($rows as $row) {
            $entity_id = $row["entity_id"];

            $oc_id = $row["oc_id"];
            $oc_pr_id = $row["oc_pr_id"];
            $rc_id = $row["rc_id"];

            $oc_field = $row["oc_field"];
            $rc_field = $row["rc_field"];

            $rc_data = $row["rc_data"];
            $oc_data = $row["oc_data"];

            $oc_alias = $module->getMapping()->getOncoreAlias($oc_field);
            $oc_description = $module->getMapping()->getOncoreDesc($oc_field);
            $oc_type = $module->getMapping()->getOncoreType($oc_field);

            $ts_last_scan = $row["ts_last_scan"];

            $diffmatch = $oc_data == $rc_data ? "match" : "diff";

            $rc = !empty($rc_field) ? $rc_data : "";
            $oc = !empty($oc_field) ? $oc_data : "";

            if ($oc_type == "array") {
                if (!is_array($oc)) {
                    $oc = json_decode($oc, 1);
                }
                $oc = implode(", ", array_filter($oc));
            }

            if (is_array($rc)) {
                $rc = implode(", ", array_filter($rc));

            }

            $html .= "<tr class='$diffmatch'>";
            if (!$print_rowspan) {
                $print_rowspan = true;
                $id_info = array();
                if (!empty($mrn)) {
                    $id_info[] = "MRN : $mrn";
                }
                if (!empty($rc_id)) {
                    $id_info[] = "REDCap ID : $rc_id";
                }
                if (!empty($oc_pr_id)) {
                    $id_info[] = "OnCore Subject ID : $oc_pr_id";
                }
                if (!empty($oc_status)) {
                    $id_info[] = "OnCore Subject Status : $oc_status";
                    $oc_status_class = '';
                } else {
                    $id_info[] = "<strong style='color: #e74c3c'>OnCore Subject Status : NULL(Assign status from OnCore UI)</strong>";
                    $oc_status_class = 'missing-status';
                }
                $exclude_class = $excluded ? "include_subject" : "exclude_subject";
                $exclude_text = $excluded ? "Re-Include" : "Exclude";
                $id_info[] = "<button class='btn btn-sm btn-danger $exclude_class' data-entity_id='$entity_id' data-subject_mrn='$mrn'>$exclude_text</button>";
                $id_info = implode("<br>", $id_info);
                $html .= "<td class='import' rowspan=$rowspan><input type='checkbox' class='accept_diff' name='approved_ids' data-rc_id='$rc_id' data-mrn='$mrn' value='$oc_pr_id' checked/></td>";
                $html .= "<td class='rc_id $oc_status_class' rowspan=$rowspan>$id_info</td>";
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

    if (!$excluded) {
        if ($disabled) {
//            $html .= "<button type='submit' class='btn btn-warning download_partial_redcap_csv'>Download CSV</button>";
        } else {
//            $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button> <button type='submit' class='btn btn-warning download_partial_oncore_csv'>Download CSV</button>";
            if (!empty($module->getMapping()->getProjectFieldMappings()['pull'])) {
                $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button>";
            } else {
                $html .= "<div class='alert alert-warning'>You can`t pull OnCore Subjects. To pull you must define pull fields on <a href='" . $module->getUrl('pages/field_map.php') . "'>mapping page</a>.</div>";
            }
        }
    }

    $html .= "</td>";
    $html .= "</tr>";
    $html .= "</tfoot>";
    $html .= "</table>";
    return $html;
}

function makeRedcapTableHTML($records, $noredcap = null, $disabled = null, $excluded = null)
{
    global $module;

    $show_all_btn = !$noredcap && !$disabled ? "<button class='btn btn-info show_all_matched'>Show All</button>" : "";
    $excludes_cls = $excluded ? "excludes" : "includes";
    $html = "$show_all_btn<table class='table table-striped $disabled $excludes_cls'>";
    $html .= "<thead>";
    $html .= "<tr>";
    $html .= "<th style='width: 6%' class='import'><input type='checkbox' name='check_all' class='check_all' value='1' checked/> All</th>";
    $html .= "<th style='width: 22%'>Subject Details</th>";
//    $html .= "<th style='width: 25%'>Study Site</th>";
    $html .= "<th style='width: 22%'>OnCore Property</th>";
    $html .= "<th style='width: 25%'>REDCap Data</th>";
    $html .= "</tr>";
    $html .= "</thead>";

    foreach ($records as $mrn => $rows) {
        if ($noredcap) {
            $rc_id = "";
        }
        $rowspan = count($rows);
        $print_rowspan = false;

        $ts_last_scan = null;


        $html .= "<tbody class='$mrn' data-subject_mrn='$mrn'>";

        foreach ($rows as $row) {
            $entity_id = $row["entity_id"];
            $module->emDebug($mrn, $row);
            $rc_id = $row["rc_id"];
            $oc_field = $row["oc_field"];
            $rc_field = $row["rc_field"];
            $rc_data = $row["rc_data"];

            $oc_alias = $module->getMapping()->getOncoreAlias($oc_field);
            $oc_description = $module->getMapping()->getOncoreDesc($oc_field);
            $oc_type = $module->getMapping()->getOncoreType($oc_field);
            $ts_last_scan = $row["ts_last_scan"];

            $diffmatch = "diff";

            $rc = !empty($rc_field) ? $rc_data : "";

            if (is_array($rc)) {
                $rc = implode(", ", array_filter($rc));
            }

            $html .= "<tr class='$diffmatch'>";
            if (!$print_rowspan) {
                $print_rowspan = true;
                $id_info = array();
                if (!empty($mrn)) {
                    $id_info[] = "MRN : $mrn";
                }
                if (!empty($rc_id)) {
                    $id_info[] = "REDCap ID : $rc_id";
                }


                $exclude_class = $excluded ? "include_subject" : "exclude_subject";
                $exclude_text = $excluded ? "Re-Include" : "Exclude";
                $id_info[] = "<button class='btn btn-sm btn-danger $exclude_class' data-entity_id='$entity_id' data-subject_mrn='$mrn'>$exclude_text</button>";
                $id_info = implode("<br>", $id_info);
                $html .= "<td class='import' rowspan=$rowspan><input type='checkbox' class='accept_diff' name='approved_ids' value='$rc_id' data-redcap='$rc_id' data-oncore='' data-mrn='$mrn' checked/></td>";
                $html .= "<td class='rc_id' rowspan=$rowspan>$id_info</td>";
//                $html .= "<td class='rc_study' rowspan=$rowspan></td>";
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

    if (!$excluded) {
        if ($disabled) {
//            $html .= "<button type='submit' class='btn btn-warning download_partial_redcap_csv'>Download CSV</button>";
        } else {
//            $html .= "<button type='submit' class='btn btn-success'>Accept Oncore Data</button> <button type='submit' class='btn btn-warning download_partial_oncore_csv'>Download CSV</button>";

            if ($module->getProtocols()->getSubjects()->isCanPush() && !empty($module->getMapping()->getProjectFieldMappings()['push'])) {
                $html .= "<button type='submit' class='btn btn-success'>Push to OnCore</button>";
            } elseif (empty($module->getMapping()->getProjectFieldMappings()['push'])) {
                $html .= "<div class='alert alert-warning'>You can`t push REDCap records Subjects. To push you must define push fields on <a href='" . $module->getUrl('pages/field_map.php') . "'>mapping page</a>.</div>";
            } else {
                $html .= "<div class='alert alert-warning'>You cant push REDCap records to OnCore Protocol. Because Protocol is not approved or its status is not valid.</div>";
            }

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
    <link rel="stylesheet"
          href="https://uit.stanford.edu/sites/all/themes/open_framework/packages/bootstrap-2.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
    <link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
    <link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">
    <link rel="stylesheet" href="<?= $oncore_css ?>">
    <link rel="stylesheet" href="<?= $batch_css ?>">

    <div id="oncore_mapping" class="container">
        <h3>REDCap/OnCore Interaction</h3>
        <p class="lead">Data Stored in OnCore must be synced and adjudicated periodically. The data will be pulled into
            an entity table and then matched against this projects REDCap data on the mapped fields.</p>


        <?php
        $html = "<table class='table table-striped'>";
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

        <ul>
            <li class="list-group-item  ">
                Total number of REDCap Records:
                <span class="badge badge-primary badge-pill"><?php echo $sync_summ['total_redcap_count'] ?></span>
                <ul class=" ">
                    <li class=" ">
                        REDCap Records not linked to OnCore Subjects:
                        <span
                            class="badge badge-primary badge-pill"><?php echo $sync_summ['redcap_only_count'] ?></span>
                    </li>
                </ul>
            </li>
            <li class="list-group-item ">
                Total number of OnCore Subjects:
                <span class="badge badge-primary badge-pill"><?php echo $sync_summ['total_oncore_count'] ?></span>
                <ul>
                    <li>
                        OnCore Records not present in REDCap:
                        <span
                            class="badge badge-primary badge-pill"><?php echo $sync_summ['oncore_only_count'] ?></span>
                    </li>
                </ul>
            </li>
        </ul>

        <ul>
            <li class="list-group-item  ">
                Number of Linked Records:
                <span class="badge badge-primary badge-pill"><?php echo $sync_summ['match_count'] ?></span>.
                <?php
                if ($sync_summ['missing_oncore_status_count'] > 0) {
                    ?>
                    <span class="alert alert-warning"><i
                            class="fas fa-exclamation-circle"></i> (<?php echo $sync_summ['missing_oncore_status_count'] ?>) OnCore Subject is missing Protocol Subject Status. Please update manually from OnCore UI.</span>
                    <?php
                }
                ?>
                <ul class="mt-2 ">
                    <li class=" ">
                        Number of Fully Matched Records:
                        <span
                            class="badge badge-primary badge-pill"><?php echo $sync_summ['full_match_count'] ?></span>
                    </li>
                    <li class=" ">
                        Number of Partially Matched Records:
                        <span
                            class="badge badge-primary badge-pill"><?php echo $sync_summ['partial_match_count'] ?></span>

                    </li>
                </ul>
            </li>
        </ul>

        <ul class="mt-2 ">
            <li class="list-group-item  ">Number of Records available for adjudication: <span
                    class="badge badge-primary badge-pill"><?php echo $sync_summ['total_count'] - $sync_summ['excluded_count'] ?></span>
            </li>
            <li class="list-group-item  ">Number of Records in excluded list: <span
                    class="badge badge-primary badge-pill"><?php echo $sync_summ['excluded_count'] ?></span></li>
        </ul>

        <h3>Adjudication required for following Subjects</h3>
        <ul class="nav nav-tabs">
            <li class="active"><a data-toggle="tab" href="#fullmatch">Linked Subjects <span
                        class="badge badge-light"><?= $full_match_count ?></span></a></li>
            <li><a data-toggle="tab" href="#oncore">Unlinked Oncore Subjects <span
                        class="badge badge-light"><?= $oncore_count ?></span></a></li>
            <li><a data-toggle="tab" href="#redcap">Unlinked REDCap Subjects <span
                        class="badge badge-light"><?= $redcap_count ?></span></a></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane active" id="fullmatch">
                <h2>Linked Subjects</h2>
                <?php
                if (empty($module->getMapping()->getProjectFieldMappings()['pull'])) {
                    ?>
                    <div class="alert alert-danger">You can`t pull OnCore Data because Pull Mapping is not defined.
                        Please define Pull Mapping from <a href="<?php echo $module->getUrl('pages/field_map.php') ?>">mapping
                            page</a>.
                    </div>
                    <?php
                } else {
                    ?>
                    <p>This REDCap project has <?= $total_subjects ?> Subjects linked to the OnCore Protocol ID</p>

                    <form id="syncFromOncore" class="oncore_match">
                        <input type="hidden" name="matchtype" value="fullmatch"/>
                        <?= makeSyncTableHTML($sync_diff["match"]["included"]); ?>

                        <br>

                        <h2>Excluded Subjects</h2>
                        <p>The following subjects have been excluded from syncing with Oncore</p>
                        <?= makeSyncTableHTML($sync_diff["match"]["excluded"], null, "disabled", true); ?>
                    </form>
                    <?php
                }
                ?>
            </div>

            <div class="tab-pane" id="oncore">
                <h2>Unlinked Oncore Subjects not linked in this REDCap Project</h2>
                <?php
                if (empty($module->getMapping()->getProjectFieldMappings()['pull'])) {
                    ?>
                    <div class="alert alert-danger">You can`t pull OnCore Data because Pull Mapping is not defined.
                        Please define Pull Mapping from <a href="<?php echo $module->getUrl('pages/field_map.php') ?>">mapping
                            page</a>.
                    </div>
                    <?php
                } else {
                    ?>
                    <p>The following Subjects were found in the OnCore Protocol. Click "Import Subjects" to create the
                        following subjects in this project.</p>

                    <form id="pullFromOncore" class="oncore_match">
                        <input type="hidden" name="matchtype" value="oncoreonly"/>
                        <?= makeOncoreTableHTML($sync_diff["oncore"]["included"], true); ?>

                        <br>

                        <h2>Excluded Subjects</h2>
                        <p>The following subjects have been excluded from syncing with REDCap</p>
                        <?= makeOncoreTableHTML($sync_diff["oncore"]["excluded"], true, "disabled", true); ?>
                    </form>
                    <?php
                }
                ?>
            </div>

            <div class="tab-pane" id="redcap">
                <h2>Unlinked REDCap Subjects not found in OnCore Protocol</h2>
                <?php
                if (empty($module->getMapping()->getProjectFieldMappings()['push'])) {
                    ?>
                    <div class="alert alert-danger">You can`t push REDCap Records because Push Mapping is not defined.
                        Please define Push Mapping from <a href="<?php echo $module->getUrl('pages/field_map.php') ?>">mapping
                            page</a>.
                    </div>
                    <?php
                } else {
                    ?>
                    <p>The following REDCap records do not have a matching MRN with subjects in the OnCore Protocol</p>
                    <p>In order to import these records into OnCore , download the CSV and submit to your OnCore
                        administrator</p>
                    <em>This functionality is not available for Phase 1</em>

                    <form id="pushToOncore" class="oncore_match">
                        <input type="hidden" name="matchtype" value="redcaponly"/>
                        <?= makeRedcapTableHTML($sync_diff["redcap"]["included"], false); ?>

                        <br>

                        <h2>Excluded Subjects</h2>
                        <p>The following subjects have been excluded from syncing with Oncore</p>
                        <?= makeRedcapTableHTML($sync_diff["redcap"]["excluded"], false, "disabled", true); ?>
                    </form>
                    <?php
                }
                ?>
            </div>
        </div>
    </div>
    <script src="<?= $batch_js ?>" type="text/javascript"></script>
    <script>
        $(document).ready(function () {
            var ajax_endpoint = "<?=$ajax_endpoint?>";
            var last_scan_ts = "<?=$sample_ts?>";
            if (last_scan_ts) {
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
                    $(this).closest("table").find(".accept_diff").prop("checked", false);
                }
            });
            $(".accept_diff").on("change", function () {
                if (!$(this).is(":checked")) {
                    //uncheck "check_all"
                    $(this).closest("table").find(".check_all").prop("checked", false);
                }
            });

            //DOWNLOAD CSV
            $(".download_csv").on("click", function (e) {
                e.preventDefault();
                var whichbin = $(this).data("bin");

                //TODO NEED TO FIGURE THIS OUT
                var actual_link = "<?php echo "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]" ?>";
                var csv_link = actual_link + "&download_csv=1&bin=" + whichbin;
                location.href = csv_link;
            });

            //EXCLUDE SUBJECT FROM DIFF OVERWRITE
            $(".oncore_match").on("click", ".exclude_subject, .include_subject", function (e) {
                e.preventDefault();
                var subject_mrn = $(this).data("subject_mrn");
                var entity_record_id = $(this).data("entity_id");

                var _parent_tbody = $("tbody." + subject_mrn);
                var _parent_form = _parent_tbody.closest(".oncore_match");

                var exclude_include = $(this).hasClass("exclude_subject") ? "excludeSubject" : "includeSubject";

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
                    if (exclude_include == "excludeSubject") {
                        var _parent_clone = _parent_tbody.clone();
                        _parent_tbody.fadeOut("fast", function () {
                            //clone the row to exluded table
                            _parent_form.find("table.excludes thead").after(_parent_clone);

                            //disable clone
                            _parent_clone.find(".exclude_subject").text("Re-include").addClass("include_subject").removeClass("exclude_subject");
                        });
                    } else {
                        var _parent_clone = _parent_tbody.clone();
                        _parent_tbody.fadeOut("fast", function () {
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
            $("#refresh_sync_diff").on("click", function (e) {
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
                    var ts = new Date();
                    var day = ('0' + ts.getDate()).slice(-2);
                    var month = ('0' + (ts.getMonth() + 1)).slice(-2);
                    var year = ts.getFullYear();

                    var fake_date = year + "-" + month + "-" + day;
                    var fake_time = ts.toLocaleTimeString('en-US', {hour12: false, hour: '2-digit', minute: '2-digit'});
                    $("#summ_last_ts").html(fake_date + " " + fake_time);

                    location.reload();
                }).fail(function (e) {
                    console.log("failed to save", e);
                });
            });

            $("#syncFromOncore, #pullFromOncore").submit(function (e) {
                e.preventDefault();

                var approved_ids = [];
                var inputs = $(this).find(".includes input[name='approved_ids']").each(function () {
                    if (this.checked) {
                        var _el = $(this);
                        var oncore_id = _el.val();
                        var rc_id = _el.data("rc_id");
                        var mrn = _el.data("mrn");
                        approved_ids.push({
                            "name": 'approved_ids',
                            'mrn': mrn,
                            "oncore": oncore_id,
                            "value": oncore_id,
                            "redcap": rc_id
                        });
                    }
                });

                var temp = $(this).find(".includes input[name='approved_ids']").serializeArray();

                if (approved_ids.length) {
                    //will have same index
                    var pullModal = new batchModal(approved_ids);
                    pullModal.show();
                    for (var i in approved_ids) {
                        console.log(approved_ids[i])
                        var rc_id = approved_ids[i]["redcap"];
                        var oncore = approved_ids[i]["oncore"];
                        var temp = {"redcap": rc_id, "oncore": oncore}
                        $.ajax({
                            url: ajax_endpoint,
                            method: 'POST',
                            data: {
                                "action": "approveSync",
                                "approved_ids": temp,
                            },
                            dataType: 'json'
                        }).done(function (result) {
                            const rndInt = randomIntFromInterval(1000, 5000);
                            setTimeout(function () {
                                //some showman ship
                                pullModal.setRowStatus(result.id, true);
                            }, rndInt);
                        }).fail(function (e) {
                            console.log("pushToOncore failed", e);
                            pullModal.setRowStatus(e.responseJSON.id, false, e.responseJSON.message);
                        });
                    }
                }

                // $.ajax({
                //     url: ajax_endpoint,
                //     method: 'POST',
                //     data: {
                //         "action": "approveSync",
                //         "approved_ids": approved_ids,
                //     },
                //     dataType: 'json'
                // }).done(function (result) {
                //     location.reload();
                // }).fail(function (e) {
                //     console.log("failed to save", e);
                //     hidePageBlockerSpinner();
                // });
            });

            $("#pushToOncore").submit(function (e) {
                e.preventDefault();

                // var inputs      = $(this).find(".includes input[name='approved_ids']").serializeArray();
                // var inputs      = [{value:1, mrn:12345}, {value:2, mrn:23456}, {value:3, mrn:34567}, {value:4, mrn:45678}];
                var approved_ids = []
                var inputs = $(this).find(".includes input[name='approved_ids']").each(function () {
                    if (this.checked) {
                        var _el = $(this);
                        var oncore_id = _el.data("oncore");
                        var rc_id = _el.val();
                        var mrn = _el.data("mrn");
                        approved_ids.push({
                            "name": 'approved_ids',
                            'mrn': mrn,
                            "oncore": oncore_id,
                            "value": rc_id,
                            "redcap": rc_id
                        });
                    }

                });

                if (inputs.length) {
                    //will have same index
                    var pushModal = new batchModal(approved_ids);
                    pushModal.show();

                    for (var i in approved_ids) {
                        var rc_id = approved_ids[i]["value"];
                        var mrn = approved_ids[i]["mrn"];
                        var temp = {"value": rc_id, "mrn": mrn}
                        $.ajax({
                            url: ajax_endpoint,
                            method: 'POST',
                            data: {
                                "action": "pushToOncore",
                                "record": temp
                            },
                            dataType: 'json'
                        }).done(function (result) {
                            const rndInt = randomIntFromInterval(1000, 5000);
                            setTimeout(function () {
                                //some showman ship
                                pushModal.setRowStatus(result.id, true, result.responseJSON.message);
                            }, rndInt);
                        }).fail(function (e) {
                            console.log("pushToOncore faile", e);
                            pushModal.setRowStatus(e.responseJSON.id, false, e.responseJSON.message);
                        });
                    }
                }
            })
        });

        function randomIntFromInterval(min, max) { // min and max included
            return Math.floor(Math.random() * (max - min + 1) + min)
        }
    </script>
<?php
