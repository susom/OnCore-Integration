<?php

namespace Stanford\OnCoreIntegration;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {
    if (isset($_POST["action"])) {
        $action = filter_var($_POST["action"], FILTER_SANITIZE_STRING);
        $result = null;

        switch ($action) {
            case "saveMapping":
                //Saves to em project settings
                //MAKE THIS A MORE GRANULAR SAVE.  GET

                $current_mapping    = $module->getMapping()->getProjectMapping();
                $result             = !empty($_POST["field_mappings"]) ? filter_var_array($_POST["field_mappings"], FILTER_SANITIZE_STRING) : null;

                $pull_mapping       = !empty($result["mapping"]) ? $result["mapping"] : null;
                $oncore_field       = !empty($result["oncore_field"]) && $result["oncore_field"] !== "-99" ? $result["oncore_field"] : null;
                $redcap_field       = !empty($result["redcap_field"]) && $result["redcap_field"] !== "-99" ? $result["redcap_field"] : null;
                $eventname          = !empty($result["event"]) ? $result["event"] : null;
                $ftype              = !empty($result["field_type"]) ? $result["field_type"] : null;
                $vmap               = !empty($result["value_mapping"]) ? $result["value_mapping"] : null;

                if($pull_mapping == "pull"){
                    //pull side
                    if(!$redcap_field){
                        unset($current_mapping["pull"][$oncore_field]);
                    }else{
                        $current_mapping[$pull_mapping][$oncore_field] = array(
                            "redcap_field"  => $redcap_field,
                            "event"         => $eventname,
                            "field_type"    => $ftype,
                            "value_mapping" => $vmap
                        );
                    }
                }else{
                    //push side
                    if(!$oncore_field){
                        foreach($current_mapping["push"] as $oc_field =>  $item){
                            if($item["redcap_field"] == $redcap_field){
                                unset($current_mapping["push"][$oc_field]);
                                break;
                            }
                        }
                    }else{
                        $current_mapping[$pull_mapping][$oncore_field] = array(
                            "redcap_field"  => $redcap_field,
                            "event"         => $eventname,
                            "field_type"    => $ftype,
                            "value_mapping" => $vmap
                        );
                    }
                }

                $module->getMapping()->setProjectFieldMappings($current_mapping);
                break;

            case "saveSiteStudies":
                $result = !empty($_POST["site_studies_subset"]) ? filter_var_array($_POST["site_studies_subset"], FILTER_SANITIZE_STRING) : array();
                $module->getMapping()->setProjectSiteStudies($result);
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

            case "approveSync":
                $result = !empty($_POST["approved_ids"]) ? filter_var_array($_POST["approved_ids"], FILTER_SANITIZE_STRING) : null;
                $module->getProtocols()->pullOnCoreRecordsIntoREDCap($result);
                break;

            case "pushToOncore":
                $result = !empty($_POST["approved_ids"]) ? filter_var_array($_POST["approved_ids"], FILTER_SANITIZE_STRING) : null;
                $module->emDebug("push to oncore approved ids(redcap?)", $result);
                foreach($result as $record){
                    if (!$record["redcap_id"] || $record["redcap_id"] == '') {
                        throw new \Exception('REDCap Record ID is missing.');
                    }

                    if (!$record["study_site"] || $record["study_site"] == '') {
                        throw new \Exception('Study Site is missing for REDCap Recprd ID: ' . $record["redcap_id"]);
                    }
                    $rc_id = $record["redcap_id"];
                    $study_site = $record["study_site"];
                    $records = $module->getProtocols()->pushREDCapRecordToOnCore($rc_id, $study_site, $module->getMapping()->getOnCoreFieldDefinitions());
                }
                break;

            case "excludeSubject":
                //flips excludes flag on entitry record
                $result = !empty($_POST["entity_record_id"]) ? filter_var($_POST["entity_record_id"], FILTER_SANITIZE_NUMBER_INT) : null;
                if($result){
                    $module->updateLinkage($result, array("excluded" => 1));
                }
                break;

            case "includeSubject":
                //flips excludes flag on entitry record
                $result = !empty($_POST["entity_record_id"]) ? filter_var($_POST["entity_record_id"], FILTER_SANITIZE_NUMBER_INT) : null;
                if($result){
                    $module->updateLinkage($result, array("excluded" => 0));
                }
                break;

            case "checkOverallStatus":
                $pull   = $module->getMapping()->getOverallPullStatus();
                $push   = $module->getMapping()->getOverallPushStatus();
                $result = array("overallPull" => $pull, "overallPush" => $push);
                break;

            case "checkPushPullStatus":
                $oncore_field   = !empty($_POST["oncore_field"]) ? filter_var($_POST["oncore_field"], FILTER_SANITIZE_STRING) : null;
                $result         = $module->getMapping()->calculatePushPullStatus($oncore_field);
                break;

            case "getValueMappingUI":
                $redcap_field   = !empty($_POST["redcap_field"]) ? filter_var($_POST["redcap_field"], FILTER_SANITIZE_STRING) : null;
                $oncore_field   = !empty($_POST["oncore_field"]) ? filter_var($_POST["oncore_field"], FILTER_SANITIZE_STRING) : null;
                $rc_mapping     = !empty($_POST["rc_mapping"]) ? filter_var($_POST["rc_mapping"], FILTER_SANITIZE_NUMBER_INT) : null;
                if($rc_mapping){
                    $result         = $module->getMapping()->makeValueMappingUI_RC($oncore_field, $redcap_field);
                }else{
                    $result         = $module->getMapping()->makeValueMappingUI($oncore_field, $redcap_field);
                }

                $result         = $result["html"];
                break;
        }
        echo json_encode($result);
    }
} catch (\LogicException|ClientException|GuzzleException $e) {
    $response = $e->getResponse();
    $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
    Entities::createException($responseBodyAsString['message']);
    header("Content-type: application/json");
    http_response_code(404);
    echo json_encode($responseBodyAsString);
} catch (\Exception $e) {
    Entities::createException($e->getMessage());
    header("Content-type: application/json");
    http_response_code(404);
    echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
}


