<?php

namespace Stanford\OnCoreIntegration;

use GuzzleHttp\Exception\GuzzleException;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {
    $module->onCoreAutoPullCron();
} catch (GuzzleException $e) {
    $response = $e->getResponse();
    $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
    echo($responseBodyAsString['message']);
} catch (\Exception $e) {
    Entities::createException($e->getMessage());
    echo $e->getMessage();
}
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
////    $demographics = $module->getProtocols()->getSubjects()->prepareREDCapRecordForSync(6, $module->getProtocols()->getFieldsMap(), $module->getMapping()->getOnCoreFieldDefinitions());
////    $records = $module->getProtocols()->getSubjects()->createOnCoreProtocolSubject( $module->getProtocols()->getEntityRecord()['oncore_protocol_id'], 'SHC Main Hosp, Welch Rd & campus/nearby clinics',  null, $demographics);
//    $records = $module->getProtocols()->pushREDCapRecordToOnCore(11, 'SHC Main Hosp, Pasteur, Welch & campus/nearby clinics', $module->getMapping()->getOnCoreFieldDefinitions());
//
//    echo '<pre>';
//    print_r($records);
//    echo '</pre>';
//} catch (GuzzleException $e) {
//    $response = $e->getResponse();
//    $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
//    echo($responseBodyAsString['message']);
//} catch (\Exception $e) {
//    Entities::createException($e->getMessage());
//    echo $e->getMessage();
//}
//try {
//    $module->initiateProtocol();
//    $records = $module->getProtocols()->getUser()->getOnCoreAdmin();
//    echo '<pre>';
//    print_r($records);
//    echo '</pre>';
//} catch (\Exception $e) {
//    echo $e->getMessage();
//}
//try {
//    $module->initiateProtocol();
//    $records = $module->getProtocols()->getSubjects()->getOnCoreProtocolSubject($module->getProtocols()->getEntityRecord()['oncore_protocol_id'], 138991);
//    echo '<pre>';
//    print_r($records);
//    echo '</pre>';
//} catch (\Exception $e) {
//    echo $e->getMessage();
//}

// pull oncore to redcap
//try {
//    $module->initiateProtocol();
//    $oncore = array(array('oncore' => 82020, 'redcap' => ''),
//        array('oncore' => 82011, 'redcap' => ''),
//        array('oncore' => 82026, 'redcap' => ''),
//        array('oncore' => 82028, 'redcap' => ''),
//        array('oncore' => 82030, 'redcap' => ''),
//        array('oncore' => 82023, 'redcap' => ''),
//        array('oncore' => 82016, 'redcap' => ''),
//        array('oncore' => 82038, 'redcap' => ''),
//        array('oncore' => 82031, 'redcap' => ''),
//        array('oncore' => 82014, 'redcap' => ''),
//        array('oncore' => 82019, 'redcap' => ''),
//        array('oncore' => 82018, 'redcap' => ''),
//        array('oncore' => 82022, 'redcap' => ''),
//        array('oncore' => 82015, 'redcap' => ''),
//        array('oncore' => 82039, 'redcap' => ''),
//        array('oncore' => 82035, 'redcap' => ''),
//        array('oncore' => 82017, 'redcap' => ''),
//        array('oncore' => 82013, 'redcap' => ''),
//        array('oncore' => 82032, 'redcap' => ''),
//        array('oncore' => 82033, 'redcap' => ''),
//        array('oncore' => 82012, 'redcap' => ''),
//        array('oncore' => 82021, 'redcap' => ''),
//        array('oncore' => 82041, 'redcap' => ''),
//        array('oncore' => 82025, 'redcap' => ''));
//    $module->getProtocols()->pullOnCoreRecordsIntoREDCap($oncore);
//    echo '<pre>';
//    print_r($records);
//    echo '</pre>';
//} catch (\Exception $e) {
//    echo $e->getMessage();
//}
// pull oncore record into redcap
//try {
//    $module->initiateProtocol();
//    $records = $module->getProtocols()->getSyncedRecords();
//    $data = $module->getProtocols()->getSubjects()->prepareOnCoreRecordForSync($records[5]['oncore']['demographics'], $module->getProtocols()->getFieldsMap());
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
