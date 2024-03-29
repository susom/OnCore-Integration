<?php

namespace Stanford\OnCoreIntegration;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */


try {
    if (isset($_POST["action"])) {
        $action = htmlspecialchars($_POST["action"]);
        $result = null;
        $module->initiateProtocol();

        // actions exempt from allow to push
        $exemptActions = array('triggerIRBSweep');

        if (!$module->getProtocols()->getUser()->isOnCoreContactAllowedToPush() && !in_array($action, $exemptActions)) {

            throw new \Exception($module::getActionExceptionText($action));
        }
        switch ($action) {
            case "getMappingHTML":
                $result = $module->getMapping()->makeFieldMappingUI();
                break;

            case "saveSiteStudies":
                $result = !empty($_POST["site_studies_subset"]) ? filter_var_array($_POST["site_studies_subset"], FILTER_SANITIZE_STRING) : [];
                $module->getMapping()->setProjectSiteStudies($result);
                break;

            case "saveFilterLogic":
                $result = !empty($_POST["filter_logic_str"]) ? filter_var($_POST["filter_logic_str"], FILTER_SANITIZE_STRING) : '';
                $module->getMapping()->setOncoreConsentFilterLogic($result);
                break;

            case "saveMapping":

                $protocol_status = $module->getProtocols()->getOnCoreProtocol()['protocolStatus'];
                $protocol_num = $module->getProtocols()->getOnCoreProtocol()['protocolNo'];
                $status = in_array(strtolower($protocol_status), $module->getUsers()->getStatusesAllowedToPush());
                if (!$status) {
                    throw new \Exception("" . $protocol_num . " status '" . $protocol_status . "' is not part of allowed statuses");
                }

                //Saves to em project settings
                //MAKE THIS A MORE GRANULAR SAVE.  GET
                $project_oncore_subset = $module->getMapping()->getProjectOncoreSubset();
                $current_mapping = $module->getMapping()->getProjectMapping();
                $result = !empty($_POST["field_mappings"]) ? filter_var_array($_POST["field_mappings"], FILTER_SANITIZE_STRING) : null;
                $update_oppo = !empty($_POST["update_oppo"]) ? filter_var($_POST["update_oppo"], FILTER_VALIDATE_BOOLEAN) : null;

                $pull_mapping = !empty($result["mapping"]) ? $result["mapping"] : null;
                $oncore_field = !empty($result["oncore_field"]) && $result["oncore_field"] !== "-99" ? $result["oncore_field"] : null;
                $redcap_field = !empty($result["redcap_field"]) && $result["redcap_field"] !== "-99" ? $result["redcap_field"] : null;
                $eventname = !empty($result["event"]) ? $result["event"] : null;
                $ftype = !empty($result["field_type"]) ? $result["field_type"] : null;
                $vmap = !empty($result["value_mapping"]) ? $result["value_mapping"] : null;
                $use_default = !empty($result["use_default"]);
                $default_value = !empty($result["default_value"]) ? $result["default_value"] : null;
                $birthDateNotAvailable = false;

                //$pull_mapping tells me the actual click (pull or push side)... doing the opposite side is more just a convenience..
                if ($pull_mapping == "pull") {
                    $rc_mapping = 0;
                    //pull side
                    if (!$redcap_field) {
                        unset($current_mapping[$pull_mapping][$oncore_field]);
                    } else {
                        if (!$vmap && $update_oppo) {
                            //if its just a one to one mapping, then just go ahead and map the other direction
                            $current_mapping["push"][$oncore_field] = array(
                                "redcap_field" => $redcap_field,
                                "event" => $eventname,
                                "field_type" => $ftype,
                                "default_value" => $default_value,
                                "value_mapping" => $vmap
                            );
                        }

                        $current_mapping[$pull_mapping][$oncore_field] = array(
                            "redcap_field" => $redcap_field,
                            "event" => $eventname,
                            "field_type" => $ftype,
                            "default_value" => $default_value,
                            "value_mapping" => $vmap
                        );
                    }
                } else {
                    $rc_mapping = 1;
                    //push side
                    if (!$redcap_field) {
                        unset($current_mapping[$pull_mapping][$oncore_field]);
                        if ($use_default) {
                            if ($oncore_field == "birthDate") {
                                $birthDateNotAvailable = true;
                                $default_value = "birthDateNotAvailable";
                            }

                            $current_mapping[$pull_mapping][$oncore_field] = array(
                                "redcap_field" => $redcap_field,
                                "event" => $eventname,
                                "field_type" => $ftype,
                                "value_mapping" => $vmap,
                                "default_value" => $default_value
                            );

                            if ($birthDateNotAvailable) {
                                $current_mapping[$pull_mapping][$oncore_field]["birthDateNotAvailable"] = true;
                            }
                        }
                    } else {
                        if (!$vmap && in_array($oncore_field, $project_oncore_subset) && $update_oppo) {
                            //if its just a one to one mapping, then just go ahead and map the other direction
                            $current_mapping["pull"][$oncore_field] = array(
                                "redcap_field" => $redcap_field,
                                "event" => $eventname,
                                "field_type" => $ftype,
                                "value_mapping" => $vmap
                            );
                        }

                        $current_mapping[$pull_mapping][$oncore_field] = array(
                            "redcap_field" => $redcap_field,
                            "event" => $eventname,
                            "field_type" => $ftype,
                            "value_mapping" => $vmap
                        );
                    }
                }
//                $module->emDebug("current mapping", $current_mapping[$pull_mapping]);
                $module->getMapping()->setProjectFieldMappings($current_mapping);
            case "checkPushPullStatus":
                if (!isset($oncore_field)) {
                    $oncore_field = filter_var($_POST["oncore_field"], FILTER_SANITIZE_STRING);
                }
                $oncore_field = htmlspecialchars($oncore_field);
                $oncore_field = $oncore_field ?: null;
                $indy_push_pull = $module->getMapping()->calculatePushPullStatus($oncore_field);
            case "checkOverallStatus":
                if (!isset($indy_push_pull)) {
                    $indy_push_pull = array("pull" => null, "push" => null);
                }
                $pull = $module->getMapping()->getOverallPullStatus();
                $push = $module->getMapping()->getOverallPushStatus();
                $pp_result = array_merge(array("overallPull" => $pull, "overallPush" => $push), $indy_push_pull);
            case "getValueMappingUI":
                if (!isset($redcap_field)) {
                    $redcap_field = filter_var($_POST["redcap_field"], FILTER_SANITIZE_STRING);
                }
                $redcap_field = htmlspecialchars($redcap_field);
                $redcap_field = $redcap_field ?: null;

                if (!isset($oncore_field)) {
                    $oncore_field = filter_var($_POST["oncore_field"], FILTER_SANITIZE_STRING);
                }
                $oncore_field = htmlspecialchars($oncore_field);
                $oncore_field = $oncore_field ?: null;

                if (!isset($rc_mapping)) {
                    $rc_mapping = filter_var($_POST["rc_mapping"], FILTER_SANITIZE_NUMBER_INT);
                }
                $rc_mapping = htmlspecialchars($rc_mapping);
                $rc_mapping = $rc_mapping ?: null;

                $rc_obj = $module->getMapping()->getRedcapValueSet($redcap_field);
                $oc_obj = $module->getMapping()->getOncoreValueSet($oncore_field);

                if ($use_default) {
                    $res = $module->getMapping()->makeValueMappingUI_UseDefault($oncore_field, $default_value);
                    $res["html_oppo"] = null;
                } elseif (!empty($rc_obj) || !empty($oc_obj)) {
                    if ($rc_mapping) {
                        $res = $module->getMapping()->makeValueMappingUI_RC($oncore_field, $redcap_field);
                    } else {
                        $res = $module->getMapping()->makeValueMappingUI($oncore_field, $redcap_field);
                    }
                } else {
                    $res = array("html" => null, "html_oppo" => null);
                }

                $result = array_merge(array("html" => $res["html"], "html_oppo" => $res["html_oppo"]), $pp_result);
                break;

            case "deleteMapping":
                //DELETE ENTIRE MAPPING FOR PUSH Or PULL
                $current_mapping = $module->getMapping()->getProjectMapping();
                $push_pull = !empty($_POST["push_pull"]) ? filter_var($_POST["push_pull"], FILTER_SANITIZE_STRING) : null;

                if ($push_pull) {
                    $current_mapping[$push_pull] = array();

                    if ($push_pull == "pull") {
                        //empty it
                        $module->getMapping()->setProjectOncoreSubset(array());
                    }
                }

                $result = $module->getMapping()->setProjectFieldMappings($current_mapping);
                break;

            case "deletePullField":
                //DELETE ENTIRE MAPPING FOR PUSH Or PULL
                $current_mapping = $module->getMapping()->getProjectMapping();
                $pull_field = !empty($_POST["oncore_prop"]) ? filter_var($_POST["oncore_prop"], FILTER_SANITIZE_STRING) : null;

                //REMOVE FROM PULL SUBSET
                $project_oncore_subset = $module->getMapping()->getProjectOncoreSubset();
                $unset_idx = array_search($pull_field, $project_oncore_subset);
                unset($project_oncore_subset[$unset_idx]);
                $module->getMapping()->setProjectOncoreSubset($project_oncore_subset);

                if (array_key_exists($pull_field, $current_mapping["pull"])) {
                    //REMOVE FROM MAPPING
                    unset($current_mapping["pull"][$pull_field]);
                }

//                $module->emDebug("new mapping less $pull_field", $current_mapping["pull"], $project_oncore_subset);
                $result = $module->getMapping()->setProjectFieldMappings($current_mapping);

                $pull = $module->getMapping()->getOverallPullStatus();
                $push = $module->getMapping()->getOverallPushStatus();
                $result = array("overallPull" => $pull, "overallPush" => $push);
                break;

            case "saveOncoreSubset":
                $oncore_prop = !empty($_POST["oncore_prop"]) ? filter_var($_POST["oncore_prop"], FILTER_SANITIZE_STRING) : array();
                $subtract = !empty($_POST["subtract"]) ? filter_var($_POST["subtract"], FILTER_SANITIZE_NUMBER_INT) : 0;

                $project_oncore_subset = $module->getMapping()->getProjectOncoreSubset();

                if ($subtract) {
                    $unset_idx = array_search($oncore_prop, $project_oncore_subset);
                    unset($project_oncore_subset[$unset_idx]);
                } else {
                    if (!in_array($oncore_prop, $project_oncore_subset)) {
                        array_push($project_oncore_subset, $oncore_prop);
                    }
                }
                $module->getMapping()->setProjectOncoreSubset($project_oncore_subset);

                $result = $module->getMapping()->makeFieldMappingUI();
                break;

            case "savePushPullPref":
                $result = !empty($_POST["pushpull_pref"]) ? filter_var_array($_POST["pushpull_pref"], FILTER_SANITIZE_STRING) : array();
                $module->getMapping()->setProjectPushPullPref($result);
                break;

            case "integrateOnCore":
                //integrate oncore project(s)!!
                $entity_record_id = !empty($_POST["entity_record_id"]) ? filter_var($_POST["entity_record_id"], FILTER_SANITIZE_NUMBER_INT) : null;
                $integrate = !empty($_POST["integrate"]) ? filter_var($_POST["integrate"], FILTER_SANITIZE_NUMBER_INT) : null;
                $result = $module->integrateOnCoreProject($entity_record_id, $integrate);
                break;

            case "syncDiff":
                //returns sync summary
                $result = $module->pullSync();
                break;

            case "getSyncDiff":
                $bin = htmlspecialchars($_POST["bin"]);
                $use_filter = htmlspecialchars($_POST["filter"]);

                $bin = $bin ?: null;
                $sync_diff = $module->getSyncDiff($use_filter);

                $result = array("included" => "", "excluded" => "", "footer_action" => "", "show_all" => "");
                if ($bin == "partial") {
                    $included = $module->getMapping()->makeSyncTableHTML($sync_diff["partial"]["included"]);
                    $excluded = $module->getMapping()->makeSyncTableHTML($sync_diff["partial"]["excluded"], null, "disabled", true);
                } elseif ($bin == "redcap") {
                    $included = $module->getMapping()->makeRedcapTableHTML($sync_diff["redcap"]["included"]);
                    $excluded = $module->getMapping()->makeRedcapTableHTML($sync_diff["redcap"]["excluded"], null, "disabled", true);
                } elseif ($bin == "oncore") {
                    $included = $module->getMapping()->makeOncoreTableHTML($sync_diff["oncore"]["included"], false);
                    $excluded = $module->getMapping()->makeOncoreTableHTML($sync_diff["oncore"]["excluded"], false, "disabled", true);
                }

                $result["included"] = $included["html"] ?: "";
                $result["excluded"] = $excluded["html"] ?: "";
                $result["footer_action"] = $included["footer_action"] ?: "";
                $result["show_all"] = $included["show_all"] ?: "";
                break;

            case "approveSync":
                $temp = !empty($_POST["record"]) ? filter_var_array($_POST["record"], FILTER_SANITIZE_STRING) : null;
                $mrn = $temp['mrn'];
                unset($temp["mrn"]);
                $id = $temp["oncore"];
                $res = $module->getProtocols()->pullOnCoreRecordsIntoREDCap($temp);
                if (is_array($res)) {
                    $result = array("mrn" => $mrn, "id" => $res["id"], 'message' => 'Record synced successfully!');
                }
                break;

            case "pushToOncore":
                $record = filter_var_array($_POST["record"]);
                $record = $record ?: null;
                $module->emDebug("push to oncore approved ids(redcap?)", $record);
                if (!$record["value"] || $record["value"] == '') {
                    throw new \Exception('REDCap Record ID is missing.');
                }

                $rc_id = $id = $record["value"];
                $temp = $module->getProtocols()->pushREDCapRecordToOnCore($rc_id, $module->getMapping()->getOnCoreFieldDefinitions());
                if (is_array($temp)) {
                    $result = array('id' => $rc_id, 'status' => 'success', 'message' => $temp['message']);
                }
                break;

            case "excludeSubject":
                //flips excludes flag on entitry record
                $entity_record_id = htmlentities($_POST["entity_record_id"], ENT_QUOTES);
                $result = $entity_record_id ?: null;
                if ($result) {
                    $module->updateLinkage($result, array("excluded" => 1));
                }
                break;

            case "includeSubject":
                //flips excludes flag on entitry record
                $entity_record_id = htmlentities($_POST["entity_record_id"], ENT_QUOTES);
                $result = $entity_record_id ?: null;
                if ($result) {
                    $module->updateLinkage($result, array("excluded" => 0));
                }
                break;

            case "schedulePull":
                if (isset($_POST['flag'])) {
                    $auto_pull_flag = json_decode($_POST['flag']) ? '1' : '0';
                    $result = $module->setProjectSetting("enable-auto-pull", $auto_pull_flag);
                }
                break;

            case "triggerIRBSweep":
                if (isset($_POST['irb']) && $_POST['irb'] != '' && isset($_POST['oncore_protocol_id']) && $_POST['oncore_protocol_id'] != '') {
                    $irb = htmlspecialchars($_POST['irb']);
                    $oncoreProtocolId = htmlspecialchars($_POST['oncore_protocol_id']);
                    $module->getProtocols()->processCron($module->getProjectId(), $irb, $oncoreProtocolId, $module->getDefinedLibraries());
                }

                break;
            case "projectLogs":
                $result = Entities::getREDCapProjectLogs($module->getProjectId());
                break;
            case "projectProtocols":
                global $Proj;
                $irb = $Proj->project['project_irb_number'];
                $result = $module->getProtocols()->searchOnCoreProtocolsViaIRB($irb);
                break;
        }
//        echo htmlentities(json_encode($result, JSON_THROW_ON_ERROR), ENT_QUOTES);
        $result = json_encode($result, JSON_THROW_ON_ERROR);
        echo htmlentities($result, ENT_QUOTES);;
    }
} catch (\LogicException|ClientException|GuzzleException $e) {
    if (method_exists($e, 'getResponse')) {
        $response = $e->getResponse();
        $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
        $responseBodyAsString['message'] = ($responseBodyAsString['field'] ? $responseBodyAsString['field'] . ': ' : '') . $responseBodyAsString['message'];
    } else {
        $responseBodyAsString = array();
        $responseBodyAsString['message'] = $e->getMessage();
    }
    $responseBodyAsString['message'] = $module->checkCustomErrorMessages($responseBodyAsString['message']);
    Entities::createException($responseBodyAsString['message']);
    header("Content-type: application/json");
    http_response_code(404);
    // add redcap record id!
    if ($id) {
        $responseBodyAsString['id'] = $id;
    }
//    echo(json_encode($responseBodyAsString, JSON_THROW_ON_ERROR));
    $result = json_encode($responseBodyAsString, JSON_THROW_ON_ERROR);
    echo htmlentities($result, ENT_QUOTES);;
} catch (\Exception $e) {
    Entities::createException($e->getMessage());
    header("Content-type: application/json");
    http_response_code(404);
    $message = $module->checkCustomErrorMessages($e->getMessage());
    $result = json_encode(array('status' => 'error', 'message' => $message, 'id' => $id), JSON_THROW_ON_ERROR);
//    echo(json_encode($result, JSON_THROW_ON_ERROR));
    echo htmlentities($result, ENT_QUOTES);;
}


