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
                /*
                 *expected structure
    //                 "oncore_field": {
//                      "redcap_field": "",
//                      "event":"",
//                      "field_type" : ""
//                      value_mapping : [{"oc" :  oncore_value , "rc" : redcap_value }]
//               }
                */

                $result = !empty($_POST["field_mappings"]) ? filter_var_array($_POST["field_mappings"], FILTER_SANITIZE_STRING) : null;
                $module->getMapping()->setProjectFieldMappings($result);
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
                $result = !empty($_POST["approved_ids"]) ? filter_var_array($_POST["approved_ids"], FILTER_SANITIZE_NUMBER_INT) : null;
                $module->getMapping()->setProjectFieldMappings($result);
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

                if(1){
                    $result         = $module->getMapping()->makeValueMappingUI($oncore_field, $redcap_field);
                }else{
                    $result         = $module->getMapping()->makeValueMappingUI_RC($oncore_field, $redcap_field);
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
    $result['data'] = [];
    echo json_encode($result);
} catch (\Exception $e) {
    Entities::createException($e->getMessage());
    header("Content-type: application/json");
    http_response_code(404);
    echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
}


