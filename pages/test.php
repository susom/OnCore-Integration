<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

//// test contact functionality.
//$user = $module->framework->getUser();
//$admin = $module->getUsers()->getOnCoreAdmin($user->getUsername());
//if (!$admin) {
//    $contact = $module->getUsers()->searchOnCoreContactsViaEmail($user->getEmail());
//
//    echo '<pre>';Lee Ann Yasukawa <yasukawa@stanford.edu>
//    print_r($contact);
//    echo '</pre>';
//    $module->getUsers()->createOnCoreAdminEntityRecord($contact['contactId'], $user->getUsername());
//} else {
//    echo '<pre>';
//    print_r($admin);
//    echo '</pre>';
//}

//try {
//    $module->initiateProtocol();
////    $demographics = $module->getProtocols()->getSubjects()->prepareREDCapRecordForOnCorePush(6, $module->getProtocols()->getFieldsMap(), $module->getMapping()->getOnCoreFieldDefinitions());
////    $records = $module->getProtocols()->getSubjects()->createOnCoreProtocolSubject( $module->getProtocols()->getEntityRecord()['oncore_protocol_id'], 'SHC Main Hosp, Welch Rd & campus/nearby clinics',  null, $demographics);
//    $module->getProtocols()->processSyncedRecords();
//    $records = $module->getProtocols()->getSyncedRecords();
//    echo '<pre>';
//    print_r($records);
//    echo '</pre>';
//} catch (\Exception $e) {
//    echo $e->getMessage();
//}
try {
    $module->initiateProtocol();
    $records = $module->getProtocols()->getUser()->getOnCoreAdmin();
    echo '<pre>';
    print_r($records);
    echo '</pre>';
} catch (\Exception $e) {
    echo $e->getMessage();
}
