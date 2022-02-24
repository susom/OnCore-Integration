<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

if(isset($_POST["action"])){
    $action = filter_var($_POST["action"], FILTER_SANITIZE_STRING);
    $result = null;

    switch($action){
        case "saveMapping":
            //expected format = array("oncore_field1" => "redcap_field1", "oncore_field2" => "redcap_field2");
            $field_mappings = !empty($_POST["field_mappings"]) ? filter_var_array($_POST["field_mappings"], FILTER_SANITIZE_STRING): null;
            $result = $module->setProjectFieldMappings($field_mappings);
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


