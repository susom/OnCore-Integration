<?php

namespace Stanford\OnCoreIntegration;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {
    global $Proj;
    if (!$module->users) {
        $module->setUsers(new Users($module->PREFIX, null, $module->getCSRFToken()));
    }
    $module->getProtocols()->processCron($module->getProjectId(), $Proj->project['project_irb_number']);
    header('Content-Type: application/json');
    echo json_encode(array('status' => 'success', 'message' => 'Cron Processed for pid' . $module->getProjectId()));
} catch (\LogicException|ClientException|GuzzleException $e) {
    header("Content-type: application/json");
//    http_response_code(404);
    $result['data'] = [];
    echo json_encode($result);
} catch (\Exception $e) {
    header("Content-type: application/json");
    http_response_code(404);
    echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
}
?>
