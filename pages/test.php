<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$module->initiateProtocol();




//
//
//// test contact functionality.
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
    $module->getProtocols()->processSyncedRecords();
    $module->emDebug("sync records?");
    $records = $module->getProtocols()->getSyncedRecordsSummaries();
    echo '<pre>';
    print_r($records);
    echo '</pre>';
} catch (\Exception $e) {
    echo $e->getMessage();
}
