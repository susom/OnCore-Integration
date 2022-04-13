<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */


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

try {
    $module->initiateProtocol();
//    $demographics = $module->getProtocols()->getSubjects()->prepareREDCapRecordForOnCorePush(6, $module->getProtocols()->getFieldsMap(), $module->getMapping()->getOnCoreFieldDefinitions());
//    $records = $module->getProtocols()->getSubjects()->createOnCoreProtocolSubject( $module->getProtocols()->getEntityRecord()['oncore_protocol_id'], 'SHC Main Hosp, Welch Rd & campus/nearby clinics',  null, $demographics);
    $module->getProtocols()->processSyncedRecords();
    $records = $module->getProtocols()->getSyncedRecords();
    echo '<pre>';
    print_r($records);
    echo '</pre>';
} catch (\Exception $e) {
    echo $e->getMessage();
}
//try {
//    $module->initiateProtocol();
//    $records = $module->getProtocols()->getUser()->getOnCoreAdmin();
//    echo '<pre>';
//    print_r($records);
//    echo '</pre>';
//} catch (\Exception $e) {
//    echo $e->getMessage();
//}
try {
    $module->initiateProtocol();
    $records = $module->getProtocols()->getSubjects()->getOnCoreProtocolSubject($module->getProtocols()->getEntityRecord()['oncore_protocol_id'], 138991);
    echo '<pre>';
    print_r($records);
    echo '</pre>';
} catch (\Exception $e) {
    echo $e->getMessage();
}
// pull oncore record into redcap
//try {
//    $module->initiateProtocol();
//    $records = $module->getProtocols()->getSyncedRecords();
//    $data = $module->getProtocols()->getSubjects()->prepareOnCoreSubjectForREDCapPull($records[5]['oncore']['demographics'], $module->getProtocols()->getFieldsMap());
//    $id = 7;
//    foreach ($data as $event => $array){
//        if(is_null($id)){
//            $array[\REDCap::getRecordIdField()] = \REDCap::reserveNewRecordId($module->getProjectId());
//        }else{
//            $array[\REDCap::getRecordIdField()] = $id;
//        }
//        $array['redcap_event_name'] = $event;
//        $response = \REDCap::saveData($module->getProjectId(), 'json', json_encode(array($array)), 'overwrite');
//        if (!empty($response['errors'])) {
//            if (is_array($response['errors'])) {
//                throw new \Exception(implode(",", $response['errors']));
//            } else {
//                throw new \Exception($response['errors']);
//            }
//        }else{
//            $id = end($response['ids']);
//        }
//    }
//    echo '<pre>';
//    print_r($data);
//    echo '</pre>';
//} catch (\Exception $e) {
//    echo $e->getMessage();
//}
