<?php

namespace Stanford\OnCoreIntegration;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {
    global $Proj;
    $action = htmlspecialchars($_GET['action']);
    if (!$module->users) {
        $module->setUsers(new Users($module->getProjectId(), $module->PREFIX, null, $module->getCSRFToken()));
    }
    if ($action == 'protocols') {
        $module->getProtocols()->processCron($module->getProjectId(), $Proj->project['project_irb_number'], $module->getDefinedLibraries());
        header('Content-Type: application/json');
        $result = json_encode(array('status' => 'success', 'message' => 'Protocols Cron completed for pid' . $module->getProjectId()), JSON_THROW_ON_ERROR);
    } elseif ($action == 'subjects') {
        $module->getProtocols()->syncRecords();
        header('Content-Type: application/json');
        $result = json_encode(array('status' => 'success', 'message' => 'Subjects Cron completed for pid' . $module->getProjectId()), JSON_THROW_ON_ERROR);
    } elseif ($action == 'redcap_only') {
        $module->getProtocols()->syncREDCapRecords();
        header('Content-Type: application/json');
        $result = json_encode(array('status' => 'success', 'message' => 'REDCap Records Cron completed for pid' . $module->getProjectId()), JSON_THROW_ON_ERROR);
    } else {
        throw new \Exception('Unknown Action');
    }
    echo htmlentities($result, ENT_QUOTES);;
} catch (\LogicException|ClientException|GuzzleException $e) {
    header("Content-type: application/json");
//    http_response_code(404);
    Entities::createException($e->getMessage());
    $result['data'] = [];
    $result = json_encode($result);
    echo htmlentities($result, ENT_QUOTES);;
} catch (\Exception $e) {
    header("Content-type: application/json");
    Entities::createException($e->getMessage());
    http_response_code(404);
    $result = json_encode(array('status' => 'error', 'message' => $e->getMessage()));
    echo htmlentities($result, ENT_QUOTES);;
}
?>
