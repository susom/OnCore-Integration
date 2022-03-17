<?php


namespace Stanford\OnCoreIntegration;

use REDCapEntity\EntityList;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {
    if (!defined('SUPER_USER') || SUPER_USER != '1') {
        Entities::createException(USERID . ' is trying to access OnCore logs');
        throw new \Exception('Access Denied');
    }
    $list = new EntityList(OnCoreIntegration::ONCORE_REDCAP_API_ACTIONS_LOG, $module);
    $list->render('project'); // Context: project.

} catch (\Exception $e) {
    echo '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
}
