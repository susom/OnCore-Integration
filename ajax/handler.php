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
                //expected format = array("oncore_field1" => ["redcap_field" => "redcap_field1" , "event" => "baseline_arm_1"]);
                $field_mappings = !empty($_POST["field_mappings"]) ? filter_var_array($_POST["field_mappings"], FILTER_SANITIZE_STRING) : null;

                //TODO THIS STILL BROKEN?
                $result = $module->setProjectFieldMappings($field_mappings);
//                $module->getProtocols()->setFieldsMap($field_mappings);
                $result = $field_mappings;
                break;

            case "integrateOnCore":
                $result = $module->integrateOnCoreProject();
                break;

            case "syncDiff":
                $result = $module->getSyncDiff();
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


