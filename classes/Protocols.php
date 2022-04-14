<?php

namespace Stanford\OnCoreIntegration;

use ExternalModules\ExternalModules;

/**
 * Class Protocols
 * @package Stanford\OnCoreIntegration
 * @property Users $user
 * @property array $onCoreProtocol
 */
class Protocols
{
    /**
     * @var Users
     */
    private $user;

    /**
     * @var array
     */
    private $onCoreProtocol = [];

    /**
     * @var array
     */
    private $entityRecord = [];

    /**
     * @var Subjects
     */
    private $subjects;


    /**
     * @param $user
     * @param $reset
     */
    public function __construct($user, $redcapProjectId = '', $reset = false)
    {
//        parent::__construct($reset);

        $this->setUser($user);

        // if protocol is initiated for specific REDCap project. then check if this ONCORE_PROTOCOL entity record exists and pull OnCore Protocol via  API
        if ($redcapProjectId) {
            $this->prepareProtocol($redcapProjectId);

            // get all protocol staff and find current user OnCore contact
            if ($this->getEntityRecord()) {
                $this->getUser()->prepareUser($this->getEntityRecord()['id'], $this->getEntityRecord()['oncore_protocol_id']);
            }
        }
    }

    /**
     * This method will match redcap records to oncore protocol subjects. and save the result in entity table
     * @return void
     * @throws \Exception
     */
    public function processSyncedRecords()
    {
        if (!$this->getEntityRecord()) {
            throw new \Exception('No REDCap Project linked to OnCore Protocol found.');
        }

        $redcapRecords = $this->getSubjects()->getRedcapProjectRecords();
//        if(!$redcapRecords){
//            throw new \Exception('Cant find recap records');
//        }



        $oncoreProtocolSubjects = $this->getSubjects()->getOnCoreProtocolSubjects($this->getEntityRecord()['oncore_protocol_id']);
//        if(!$oncoreProtocolSubjects){
//            throw new \Exception('Cant find oncore subjects');
//        }

        $fields = $this->getFieldsMap();

        if (!$fields) {
            throw new \Exception('Fields map is not defined.');
        }

        foreach ($oncoreProtocolSubjects as $subject) {
            $onCoreMrn = $subject['demographics']['mrn'];
//            $redcapRecord = $this->getSubjects()->getREDCapRecordIdViaMRN($onCoreMrn, $this->getEntityRecord()['redcap_event_id'], $fields['mrn']);
            $redcapRecord = $this->getSubjects()->getREDCapRecordIdViaMRN($onCoreMrn, OnCoreIntegration::getEventNameUniqueId($fields['mrn']['event']), $fields['mrn']['redcap_field']);
            if ($redcapRecord) {
                $data = array(
                    'redcap_project_id' => $this->getEntityRecord()['redcap_project_id'],
                    'oncore_protocol_id' => $this->getEntityRecord()['oncore_protocol_id'],
                    'redcap_record_id' => $redcapRecord['id'],
                    'oncore_protocol_subject_id' => $subject['protocolSubjectId'],
                    'excluded' => OnCoreIntegration::NO,
                    'status' => $this->getSubjects()->determineSyncedRecordMatch($subject, $redcapRecord['record'], $fields)
                );
                // select oncore subject without redcap record
                $record = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], '', $subject['protocolSubjectId']);
                if ($record) {
                    $this->getSubjects()->updateLinkageRecord($record['id'], $data);
                } else {
                    //select redcap record without oncore subject
                    $record = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], $redcapRecord['id'], '');
                    if ($record) {
                        $this->getSubjects()->updateLinkageRecord($record['id'], $data);
                    } else {
                        $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
                        if (!$entity) {
                            throw new \Exception(implode(',', $entity->errors));
                        }
                    }
                }
                // now remove redcap record from array
                unset($redcapRecords[$redcapRecord['id']]);
            } else {
                $record = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], '', $subject['protocolSubjectId']);
                // only insert if no record found
                if (!$record) {
                    // here OnCore subject does not exists on redcap
                    $data = array(
                        'redcap_project_id' => $this->getEntityRecord()['redcap_project_id'],
                        'oncore_protocol_id' => $this->getEntityRecord()['oncore_protocol_id'],
                        'redcap_record_id' => '',
                        'oncore_protocol_subject_id' => $subject['protocolSubjectId'],
                        'excluded' => OnCoreIntegration::NO,
                        'status' => OnCoreIntegration::ONCORE_ONLY
                    );

                    $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
                    if (!$entity) {
                        throw new \Exception(implode(',', $entity->errors));
                    }
                }
            }
        }

        // left redcap records on redcap but not on oncore
        foreach ($redcapRecords as $id => $redcapRecord) {
            $record = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], $id, '');

            if (!$record) {
                $data = array(
                    'redcap_project_id' => $this->getEntityRecord()['redcap_project_id'],
                    'oncore_protocol_id' => $this->getEntityRecord()['oncore_protocol_id'],
                    'redcap_record_id' => $id,
                    'oncore_protocol_subject_id' => '',
                    'excluded' => OnCoreIntegration::NO,
                    'status' => OnCoreIntegration::REDCAP_ONLY
                );
                $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
                if (!$entity) {
                    throw new \Exception(implode(',', $entity->errors));
                }
            }
        }
        //TODO update entity table when redcap record or Oncore protocol subject is deleted.
    }


    public function pushREDCapRecordToOnCore($redcapRecordId, $studySite, $oncoreFieldsDef)
    {
        $result = $this->getSubjects()->pushToOnCore($this->getEntityRecord()['oncore_protocol_id'], $studySite, $redcapRecordId, $this->getFieldsMap(), $oncoreFieldsDef);
        // reset loaded subjects for protocol so we can pull them after creating new one.
        $this->getSubjects()->setOnCoreProtocolSubjects(null, true);

        // now sync redcap with oncore
        $this->processSyncedRecords();

        Entities::createLog("REDCap Record Id#$redcapRecordId was pushed succesfully to OnCore Protocol .");
        return $result;
    }

    /**
     * @return array
     */
    public function getFieldsMap()
    {
        if ($this->getEntityRecord()) {
            $arr = json_decode(ExternalModules::getProjectSetting($this->getUser()->getPREFIX(), $this->getEntityRecord()['redcap_project_id'], OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME), true);
            return $arr ?: [];
        } else {
            return [];
        }

    }

    /**
     * this method will save fields map array into EM project settings.
     * @param array $fieldsMap
     * @return void
     */
    public function setFieldsMap(array $fieldsMap): void
    {
        // TODO
//        $test = array("subjectDemographicsId" => "subjectDemographicsId",
//            "subjectSource" => "subjectSource",
//            "mrn" => "mrn",
//            "lastName" => "lastName",
//            "firstName" => "firstName",
//            "middleName" => "middleName",
//            "suffix" => "suffix",
//            "birthDate" => "birthDate",
//            "approximateBirthDate" => "approximateBirthDate",
//            "birthDateNotAvailable" => "birthDateNotAvailable",
//            "expiredDate" => "expiredDate",
//            "approximateExpiredDate" => "approximateExpiredDate",
//            "lastDateKnownAlive" => "lastDateKnownAlive",
//            "ssn" => "ssn",
//            "gender" => "gender",
//            "ethnicity" => "ethnicity",
//            "race" => "race",
//            "subjectComments",
//            "additionalSubjectIds",
//            "streetAddress",
//            "addressLine2",
//            "city",
//            "state",
//            "zip",
//            "county",
//            "country",
//            "phoneNo",
//            "alternatePhoneNo",
//            "email");

        ExternalModules::setProjectSetting($this->getUser()->getPREFIX(), $this->getEntityRecord()['redcap_project_id'], OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME, json_encode($fieldsMap));
        $this->fieldsMap = $fieldsMap;
    }

    /**
     * gather protocol related object objects and data. Entity record, onCore subjects, redcap records.
     * @param $redcapProjectId
     * @return void
     * @throws \Exception
     */
    public function prepareProtocol($redcapProjectId)
    {
        $protocol = $this->getProtocolEntityRecord($redcapProjectId);
        if (!empty($protocol)) {
            $this->setEntityRecord($protocol);
            $this->setOnCoreProtocol($this->getOnCoreProtocolsViaID($this->getEntityRecord()['oncore_protocol_id']));
            /**
             * if OnCore protocol found then prepare its subjects
             */
            $this->prepareProtocolSubjects();

            /**
             * get REDCap records for linked protocol.
             */
            $this->prepareProjectRecords();

        }
    }

    /**
     * this function will gather records for linked redcap project.
     * @return void
     */
    public function prepareProjectRecords()
    {
        $this->getSubjects()->setRedcapProjectRecords($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['redcap_project_id']);
    }

    /**
     * gather and save subjects for linked OnCore Protocol
     * @return void
     */
    public function prepareProtocolSubjects()
    {
        try {
            $this->setSubjects(new Subjects($this->getUser(), $this->canPushToProtocol()));
            $this->getSubjects()->setOnCoreProtocolSubjects($this->getEntityRecord()['oncore_protocol_id']);
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
        }
    }

    /**
     * determine if we can push from REDCap to create protocol subjects
     * @return bool
     */
    private function canPushToProtocol()
    {
        if (!$this->getEntityRecord()) {
            return false;
        } else {
            // get oncore protocol object
            $protocol = $this->getOnCoreProtocol();

            // now check if protocol status in statuses allowed to push
            return in_array(strtolower($protocol['protocolStatus']), $this->getUser()->getStatusesAllowedToPush()) && $this->getEntityRecord()['status'] == OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES;
        }

    }

    /**
     * @return array|void
     */
    public function getSyncedRecords()
    {
        if ($this->getEntityRecord()) {
            $this->getSubjects()->setSyncedRecords($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id']);
            return $this->getSubjects()->getSyncedRecords();
        } else {
            throw new \Exception('No Protocol entity record found for this Protocol');
        }
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getSyncedRecordsSummaries()
    {
        $results = array();
        if ($this->getEntityRecord()) {
            $records = $this->getSyncedRecords();
            $results['total_count'] = count($records);
            $results['redcap_only_count'] = 0;
            $results['oncore_only_count'] = 0;
            $results['full_match_count'] = 0;
            $results['partial_match_count'] = 0;
            foreach ($records as $record) {
                if (isset($record['redcap']) && !isset($record['oncore'])) {
                    $results['redcap_only_count'] += 1;
                } elseif (!isset($record['redcap']) && isset($record['oncore'])) {
                    $results['oncore_only_count'] += 1;
                } elseif (isset($record['redcap']) && isset($record['oncore']) && $record['status'] == OnCoreIntegration::FULL_MATCH) {
                    $results['full_match_count'] += 1;
                } elseif (isset($record['redcap']) && isset($record['oncore']) && $record['status'] == OnCoreIntegration::PARTIAL_MATCH) {
                    $results['partial_match_count'] += 1;
                }
            }
            return $results;
        } else {
            return [];
        }
    }

    /**
     * confirm contact is part of integrated protocol.
     * @param $contactId
     * @return false|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function isContactPartOfOnCoreProtocol($contactId)
    {
        try {
            //TODO can redcap user who is a contact can other redcap users?
            if (empty($this->getUser()->getOnCoreAdmin())) {

                throw new \Exception("Can not find a OnCore Admin");
            }
            if (empty($this->getOnCoreProtocol())) {
                throw new \Exception("No protocol found for current REDCap project.");
            }
            $jwt = $this->getUser()->getAccessToken();
            $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'protocolStaff?protocolId=' . $this->getOnCoreProtocol()['protocolId'], [
                'debug' => false,
                'headers' => [
                    'Authorization' => "Bearer {$jwt}",
                ]
            ]);

            if ($response->getStatusCode() < 300) {
                $staffs = json_decode($response->getBody(), true);
                foreach ($staffs as $staff) {
                    if ($contactId == $staff['contactId']) {
                        return $staff;
                    }
                }
                return false;
            }
            return false;
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            throw new \Exception($e->getMessage());

        }
    }

    /**
     * search OnCore API for a protocol via ID
     * @param $protocolID
     * @return mixed|void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getOnCoreProtocolsViaID($protocolID)
    {
        try {
            $jwt = $this->getUser()->getAccessToken();
            $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'protocols/' . $protocolID, [
                'debug' => false,
                'headers' => [
                    'Authorization' => "Bearer {$jwt}",
                ]
            ]);

            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody(), true);
                if (!empty($data)) {
                    $this->setOnCoreProtocol($data);
                    return $data;
                }
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * search OnCore API for a protocol via IRB
     * @param $irbNum
     * @return array|mixed|void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function searchOnCoreProtocolsViaIRB($irbNum)
    {
        try {
            $jwt = $this->getUser()->getAccessToken();
            $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'protocolManagementDetails?irbNo=' . $irbNum, [
                'debug' => false,
                'headers' => [
                    'Authorization' => "Bearer {$jwt}",
                ]
            ]);

            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody(), true);
                if (empty($data)) {
                    return [];
                } else {
                    return $data;
                }
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    public function pullOnCoreRecordsIntoREDCap($records)
    {
        try {
            if ($this->getSubjects()->pullOnCoreRecordsIntoREDCap($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], $records, $this->getFieldsMap())) {
                // update linkage entity table with redcap record and new status
                $this->getSubjects()->setRedcapProjectRecords($this->getEntityRecord()['redcap_project_id']);
                $this->processSyncedRecords();
            } else {
                throw new \Exception('Cound not pull OnCore record into REDCap');
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
        }
    }

    /**
     * pull redcap entity record.
     * @param $redcapProjectId
     * @param $irbNum
     * @param int $status default YES
     * @return array|false|mixed|string[]|null
     * @throws \Exception
     */
    public function getProtocolEntityRecord($redcapProjectId, $irbNum = '', $status = 2)
    {
        if ($redcapProjectId == '') {
            throw new \Exception('REDCap project ID can not be null');
        }
        if ($irbNum != '') {
            $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " where irb_number = " . $irbNum . " AND redcap_project_id = " . $redcapProjectId . " AND status = " . $status . " ");
        } else {
            $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " where redcap_project_id = " . $redcapProjectId . "  AND status = " . $status . "  ");
        }
        if ($record->num_rows == 0 && $status == 0) {
            return [];
        } elseif ($record->num_rows == 0 && $status == 2) {
            $this->getProtocolEntityRecord($redcapProjectId, $irbNum, 0);
        } else {
            return db_fetch_assoc($record);
        }
    }

    /**
     * @param $entityId
     * @return void
     */
    public function updateProtocolEntityRecordTimestamp($entityId): void
    {
        db_query("UPDATE " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " set last_date_scanned = '" . time() . "', updated = '" . time() . "' WHERE id = " . $entityId . "");
    }


    /**
     * @param $entityId
     * @param $status
     * @return void
     * @throws \Exception
     */
    public function updateProtocolEntityRecordStatus($entityId, $status): void
    {
        if (!in_array((int)$status, array(OnCoreIntegration::ONCORE_PROTOCOL_STATUS_NO, OnCoreIntegration::ONCORE_PROTOCOL_STATUS_PENDING, OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES))) {
            throw new \Exception("Status not found");
        }
        db_query("UPDATE " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " set status = '" . $status . "', updated = '" . time() . "' WHERE id = " . $entityId . "");
    }

    /**
     * @return Users
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param $user
     * @return void
     */
    public function setUser($user): void
    {
        $this->user = $user;
    }

    /**
     * @return array
     */
    public function getOnCoreProtocol(): array
    {
        return $this->onCoreProtocol;
    }

    /**
     * @param array $onCoreProtocol
     */
    public function setOnCoreProtocol(array $onCoreProtocol): void
    {
        $this->onCoreProtocol = $onCoreProtocol;
    }

    /**
     * @return array
     */
    public function getEntityRecord(): array
    {
        return $this->entityRecord;
    }

    /**
     * @param array $entityRecord
     */
    public function setEntityRecord(array $entityRecord): void
    {
        $this->entityRecord = $entityRecord;
    }

    /**
     * @return Subjects
     */
    public function getSubjects(): Subjects
    {
        return $this->subjects;
    }

    /**
     * @param Subjects $subjects
     */
    public function setSubjects(Subjects $subjects): void
    {
        $this->subjects = $subjects;
    }


}
