<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

// test cron
#$module->onCoreProtocolsScanCron();

// test contact functionality.
//$user = $module->framework->getUser();
//$admin = $module->getUsers()->getOnCoreAdmin($user->getUsername());
//if (!$admin) {
//    $contact = $module->getUsers()->searchOnCoreContactsViaEmail($user->getEmail());
//
//    echo '<pre>';
//    print_r($contact);
//    echo '</pre>';
//    $module->getUsers()->createOnCoreAdminEntityRecord($contact['contactId'], $user->getUsername());
//} else {
//    echo '<pre>';
//    print_r($admin);
//    echo '</pre>';
//}

try {
    $module->setProtocols(new Protocols($module->getUsers(), $module->getProjectId()));
    $module->getProtocols()->processSyncedRecords();
    $module->getProtocols()->getSubjects()->setSyncedRecords($module->getProtocols()->getEntityRecord()['redcap_project_id'], $module->getProtocols()->getEntityRecord()['oncore_protocol_id']);
    $records = $module->getProtocols()->getSubjects()->getSyncedRecords();
    echo '<pre>';
    print_r($records);
    echo '</pre>';
} catch (\Exception $e) {
    echo $e->getMessage();
}
