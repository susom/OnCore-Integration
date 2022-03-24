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
                //expected format = array("oncore_field1" => ["redcap_field" => "redcap_field1" , "event" => "baseline_arm_1"]);
                $result = !empty($_POST["field_mappings"]) ? filter_var_array($_POST["field_mappings"], FILTER_SANITIZE_STRING) : null;
                $module->setProjectFieldMappings($result);
                break;

            case "integrateOnCore":
                //integrate oncore project(s)!!
                $result = $module->integrateOnCoreProject();
                break;

            case "syncDiff":
                //returns sync summary
                $result = $module->pullSync();
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
        }
        echo json_encode($result);
    }
} catch (\LogicException|ClientException|GuzzleException $e) {
    Entities::createException($e->getMessage());
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


