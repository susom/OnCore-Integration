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

        if (!$module->getProtocols()->getUser()->isOnCoreContactAllowedToPush()) {
            throw new \Exception('You do not have permissions to pull/push data from this protocol.');
        }
        switch ($action) {
            case "getMappingHTML":
                $result = $module->getMapping()->makeFieldMappingUI();
                break;

            case "saveSiteStudies":
                $result = !empty($_POST["site_studies_subset"]) ? filter_var_array($_POST["site_studies_subset"], FILTER_SANITIZE_STRING) : null;

                $module->getMapping()->setProjectSiteStudies($result);


                break;

            case "saveMapping":
                //Saves to em project settings
                //MAKE THIS A MORE GRANULAR SAVE.  GET
                $project_oncore_subset  = $module->getMapping()->getProjectOncoreSubset();
                $current_mapping        = $module->getMapping()->getProjectMapping();
                $result                 = !empty($_POST["field_mappings"]) ? filter_var_array($_POST["field_mappings"], FILTER_SANITIZE_STRING) : null;
                $update_oppo            = !empty($_POST["update_oppo"]) ? filter_var($_POST["update_oppo"], FILTER_VALIDATE_BOOLEAN) : null;

                $pull_mapping       = !empty($result["mapping"]) ? $result["mapping"] : null;
                $oncore_field       = !empty($result["oncore_field"]) && $result["oncore_field"] !== "-99" ? $result["oncore_field"] : null;
                $redcap_field       = !empty($result["redcap_field"]) && $result["redcap_field"] !== "-99" ? $result["redcap_field"] : null;
                $eventname          = !empty($result["event"]) ? $result["event"] : null;
                $ftype              = !empty($result["field_type"]) ? $result["field_type"] : null;
                $vmap               = !empty($result["value_mapping"]) ? $result["value_mapping"] : null;

                //$pull_mapping tells me the actual click (pull or push side)... doing the opposite side is more just a convenience..
                if($pull_mapping == "pull"){
                    $rc_mapping = 0;
                    //pull side
                    if(!$redcap_field){
                        unset($current_mapping[$pull_mapping][$oncore_field]);
                    }else{
                        if(!$vmap && $update_oppo){
                            //if its just a one to one mapping, then just go ahead and map the other direction
                            $current_mapping["push"][$oncore_field] = array(
                                "redcap_field"  => $redcap_field,
                                "event"         => $eventname,
                                "field_type"    => $ftype,
                                "value_mapping" => $vmap
                            );
                        }

                        $current_mapping[$pull_mapping][$oncore_field] = array(
                            "redcap_field"  => $redcap_field,
                            "event"         => $eventname,
                            "field_type"    => $ftype,
                            "value_mapping" => $vmap
                        );
                    }
                }else{
                    $rc_mapping = 1;
                    //push side
                    if(!$redcap_field){
                        unset($current_mapping[$pull_mapping][$oncore_field]);
                    }else{
                        if(!$vmap && in_array($oncore_field, $project_oncore_subset) && $update_oppo){
                            //if its just a one to one mapping, then just go ahead and map the other direction
                            $current_mapping["pull"][$oncore_field] = array(
                                "redcap_field"  => $redcap_field,
                                "event"         => $eventname,
                                "field_type"    => $ftype,
                                "value_mapping" => $vmap
                            );
                        }

                        $current_mapping[$pull_mapping][$oncore_field] = array(
                            "redcap_field"  => $redcap_field,
                            "event"         => $eventname,
                            "field_type"    => $ftype,
                            "value_mapping" => $vmap
                        );
                    }
                }

                $module->getMapping()->setProjectFieldMappings($current_mapping);
            case "checkPushPullStatus":
                if(!isset($oncore_field)){
                    $oncore_field   = filter_var($_POST["oncore_field"], FILTER_SANITIZE_STRING) ;
                }
                $oncore_field   = htmlspecialchars($oncore_field);
                $oncore_field   = $oncore_field ?: null;
                $indy_push_pull = $module->getMapping()->calculatePushPullStatus($oncore_field);
            case "checkOverallStatus":
                if(!isset($indy_push_pull)){
                    $indy_push_pull = array("pull"=>null,"push"=>null);
                }
                $pull           = $module->getMapping()->getOverallPullStatus();
                $push           = $module->getMapping()->getOverallPushStatus();
                $pp_result      = array_merge(array("overallPull" => $pull, "overallPush" => $push), $indy_push_pull);
            case "getValueMappingUI":
                if(!isset($redcap_field)){
                    $redcap_field   = filter_var($_POST["redcap_field"], FILTER_SANITIZE_STRING) ;
                }
                $redcap_field = htmlspecialchars($redcap_field);
                $redcap_field = $redcap_field ?: null;

                if(!isset($oncore_field)){
                    $oncore_field   = filter_var($_POST["oncore_field"], FILTER_SANITIZE_STRING) ;
                }
                $oncore_field = htmlspecialchars($oncore_field);
                $oncore_field = $oncore_field ?: null;

                if(!isset($rc_mapping)){
                    $rc_mapping     = filter_var($_POST["rc_mapping"], FILTER_SANITIZE_NUMBER_INT) ;
                }
                $rc_mapping = htmlspecialchars($rc_mapping);
                $rc_mapping = $rc_mapping ?: null;

                $rc_obj     = $module->getMapping()->getRedcapValueSet($redcap_field);
                $oc_obj     = $module->getMapping()->getOncoreValueSet($oncore_field);

                if(!empty($rc_obj) || !empty($oc_obj)){
                    if ($rc_mapping) {
                        $res = $module->getMapping()->makeValueMappingUI_RC($oncore_field, $redcap_field);
                    } else {
                        $res = $module->getMapping()->makeValueMappingUI($oncore_field, $redcap_field);
                    }
                }else{
                    $res = array("html" => null);
                }

                $result = array_merge( array("html" => $res["html"]), $pp_result) ;
                break;

            case "deleteMapping":
                //DELETE ENTIRE MAPPING FOR PUSH Or PULL
                $current_mapping    = $module->getMapping()->getProjectMapping();
                $push_pull          = !empty($_POST["push_pull"]) ? filter_var($_POST["push_pull"], FILTER_SANITIZE_STRING) : null;

                if($push_pull){
                    $current_mapping[$push_pull] = array();

                    if($push_pull == "pull"){
                        //empty it
                        $module->getMapping()->setProjectOncoreSubset(array());
                    }
                }

                $result = $module->getMapping()->setProjectFieldMappings($current_mapping);
                break;

            case "deletePullField":
                //DELETE ENTIRE MAPPING FOR PUSH Or PULL
                $current_mapping        = $module->getMapping()->getProjectMapping();
                $pull_field             = !empty($_POST["oncore_prop"]) ? filter_var($_POST["oncore_prop"], FILTER_SANITIZE_STRING) : null;

                //REMOVE FROM PULL SUBSET
                $project_oncore_subset  = $module->getMapping()->getProjectOncoreSubset();
                $unset_idx = array_search($pull_field, $project_oncore_subset);
                unset($project_oncore_subset[$unset_idx]);
                $module->getMapping()->setProjectOncoreSubset($project_oncore_subset);

                if(array_key_exists($pull_field, $current_mapping["pull"]) ){
                    //REMOVE FROM MAPPING
                    unset($current_mapping["pull"][$pull_field]);
                }

//                $module->emDebug("new mapping less $pull_field", $current_mapping["pull"], $project_oncore_subset);
                $result = $module->getMapping()->setProjectFieldMappings($current_mapping);

                $pull           = $module->getMapping()->getOverallPullStatus();
                $push           = $module->getMapping()->getOverallPushStatus();
                $result         = array("overallPull" => $pull, "overallPush" => $push);
                break;

            case "saveOncoreSubset":
                $oncore_prop    = !empty($_POST["oncore_prop"]) ? filter_var($_POST["oncore_prop"], FILTER_SANITIZE_STRING) : array();
                $subtract       = !empty($_POST["subtract"]) ? filter_var($_POST["subtract"], FILTER_SANITIZE_NUMBER_INT) : 0;

                $project_oncore_subset  = $module->getMapping()->getProjectOncoreSubset();

                if($subtract){
                    $unset_idx = array_search($oncore_prop, $project_oncore_subset);
                    unset($project_oncore_subset[$unset_idx]);
                }else{
                    if(!in_array($oncore_prop,$project_oncore_subset)) {
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
                $entity_record_id   = !empty($_POST["entity_record_id"]) ? filter_var($_POST["entity_record_id"], FILTER_SANITIZE_NUMBER_INT) : null;
                $integrate          = !empty($_POST["integrate"]) ? filter_var($_POST["integrate"], FILTER_SANITIZE_NUMBER_INT) : null;
                $result             = $module->integrateOnCoreProject($entity_record_id, $integrate);
                break;

            case "syncDiff":
                //returns sync summary
                $result = $module->pullSync();
                break;

            case "getSyncDiff":
                $bin = htmlspecialchars($_POST["bin"]);
                $bin = $bin ?: null;
                $sync_diff = $module->getSyncDiff();

                $result = array("included" => "", "excluded" => "", "footer_action" => "", "show_all" => "");
                if ($bin == "partial"){
                    $included = $module->getMapping()->makeSyncTableHTML($sync_diff["partial"]["included"]);
                    $excluded = $module->getMapping()->makeSyncTableHTML($sync_diff["partial"]["excluded"], null, "disabled", true);
                }elseif($bin == "redcap"){
                    $included = $module->getMapping()->makeRedcapTableHTML($sync_diff["redcap"]["included"]);
                    $excluded = $module->getMapping()->makeRedcapTableHTML($sync_diff["redcap"]["excluded"], null, "disabled", true);
                } elseif ($bin == "oncore") {
                    $included = $module->getMapping()->makeOncoreTableHTML($sync_diff["oncore"]["included"], false);
                    $excluded = $module->getMapping()->makeOncoreTableHTML($sync_diff["oncore"]["excluded"], false, "disabled", true);
                }

                $result["included"]         = $included["html"] ?: "";
                $result["excluded"]         = $excluded["html"] ?: "";
                $result["footer_action"]    = $included["footer_action"] ?: "";
                $result["show_all"]         = $included["show_all"] ?: "";
                break;

            case "approveSync":
                $temp = !empty($_POST["record"]) ? filter_var_array($_POST["record"], FILTER_SANITIZE_STRING) : null;
                $mrn = $temp['mrn'];
                unset($temp["mrn"]);
                $id = $temp["oncore"];
                $res = $module->getProtocols()->pullOnCoreRecordsIntoREDCap($temp);
                $result = array("mrn" => $mrn, "id" => $res["id"], 'message' => 'Record synced successfully!');

                break;

            case "pushToOncore":
                $record = filter_var_array($_POST["record"]);
                $record = $record ?: null;
                $module->emDebug("push to oncore approved ids(redcap?)", $record);
                if (!$record["value"] || $record["value"] == '') {
                    throw new \Exception('REDCap Record ID is missing.');
                }

                $rc_id  = $id = $record["value"];
                $temp   = $module->getProtocols()->pushREDCapRecordToOnCore($rc_id, $module->getMapping()->getOnCoreFieldDefinitions());
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



            case "triggerIRBSweep":
                if (isset($_POST['irb']) && $_POST['irb'] != '') {
                    $irb = htmlspecialchars($_POST['irb']);
                    $module->getProtocols()->processCron($module->getProjectId(), $irb);
                }

                break;
        }
//        echo htmlentities(json_encode($result, JSON_THROW_ON_ERROR), ENT_NOQUOTES);
        echo json_encode($result, JSON_THROW_ON_ERROR);
    }
} catch (\LogicException|ClientException|GuzzleException $e) {
    $response = $e->getResponse();
    $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
    $responseBodyAsString['message'] = $responseBodyAsString['field'] . ': ' . $responseBodyAsString['message'];
    Entities::createException($responseBodyAsString['message']);
    header("Content-type: application/json");
    http_response_code(404);
    // add redcap record id!
    if ($id) {
        $responseBodyAsString['id'] = $id;
    }
//    echo(json_encode($responseBodyAsString, JSON_THROW_ON_ERROR));
    $result = json_encode($responseBodyAsString, JSON_THROW_ON_ERROR);
    echo htmlentities($result, ENT_NOQUOTES);;
} catch (\Exception $e) {
    Entities::createException($e->getMessage());
    header("Content-type: application/json");
    http_response_code(404);
    $result = json_encode(array('status' => 'error', 'message' => $e->getMessage(), 'id' => $id), JSON_THROW_ON_ERROR);
//    echo(json_encode($result, JSON_THROW_ON_ERROR));
    echo htmlentities($result, ENT_NOQUOTES);;
}


