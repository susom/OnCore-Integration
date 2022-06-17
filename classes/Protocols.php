<?php

namespace Stanford\OnCoreIntegration;

use ExternalModules\ExternalModules;
use GuzzleHttp\Exception\GuzzleException;

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
     * @var Mapping
     */
    private $mapping;

    /**
     * @param $user
     * @param $mapping
     * @param $reset
     */
    public function __construct($user, $mapping, $redcapProjectId = '', $reset = false)
    {
//        parent::__construct($reset);

        $this->setUser($user);

        $this->setMapping($mapping);
        // if protocol is initiated for specific REDCap project. then check if this ONCORE_PROTOCOL entity record exists and pull OnCore Protocol via  API
        if ($redcapProjectId) {
            $this->prepareProtocol($redcapProjectId);

        }
    }

    public function syncIndividualRecord($redcapId)
    {
        if (!$this->getEntityRecord()) {
            throw new \Exception('No REDCap Project linked to OnCore Protocol found.');
        }
        $fields = $this->getMapping()->getProjectFieldMappings();
        $record = array('id' => $redcapId, 'record' => $this->getSubjects()->getRedcapProjectRecords()[$redcapId]);;

        $redcapMRN = $record['record'][OnCoreIntegration::getEventNameUniqueId($fields['pull']['mrn']['event'])][$fields['pull']['mrn']['redcap_field']];

        // TODO what to do if no MRN
        if ($redcapMRN) {
            // reset oncore protocol subjects to get the latest subjects
            $this->getSubjects()->setOnCoreProtocolSubjects(null, true);

            $subject = $this->getSubjects()->searchOnCoreProtocolSubjectViaMRN($this->getEntityRecord()['oncore_protocol_id'], $redcapMRN);

            if (!empty($subject)) {
                $record = $this->matchREDCapRecordWithOnCoreSubject($record, $subject, $fields);
            } else {
                $record = $this->processREDCapOnlyRecord($redcapId);
            }
            Entities::createLog('REDCap Record ' . $redcapId . ' got synced');
        }
        return true;
    }

    public function syncREDCapRecords()
    {
        $redcapRecords = $this->getSubjects()->getRedcapProjectRecords();

        foreach ($redcapRecords as $id => $redcapRecord) {
            $record = $this->processREDCapOnlyRecord($id);
        }
    }

    private function matchREDCapRecordWithOnCoreSubject($redcapRecord, $subject, $fields)
    {
        $data = array(
            'redcap_project_id' => $this->getEntityRecord()['redcap_project_id'],
            'oncore_protocol_id' => $this->getEntityRecord()['oncore_protocol_id'],
            'redcap_record_id' => $redcapRecord['id'],
            'oncore_protocol_subject_id' => $subject['protocolSubjectId'],
            'oncore_protocol_subject_status' => $subject['status'] == 'ON STUDY' ? OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY : OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY,
            'excluded' => OnCoreIntegration::NO,
            'status' => $this->getSubjects()->determineSyncedRecordMatch($subject, $redcapRecord['record'], $fields['pull'])
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
                $record = $entity->getData();
            }
        }
        return $record;
    }

    private function processREDCapOnlyRecord($id)
    {
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
            $record = $entity->getData();
        }
        return $record;
    }

    private function processOnCoreOnlyRecord($subject)
    {
        $record = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], '', $subject['protocolSubjectId']);
        // only insert if no record found
        if (!$record) {
            // here OnCore subject does not exists on redcap
            $data = array(
                'redcap_project_id' => $this->getEntityRecord()['redcap_project_id'],
                'oncore_protocol_id' => $this->getEntityRecord()['oncore_protocol_id'],
                'redcap_record_id' => '',
                'oncore_protocol_subject_id' => $subject['protocolSubjectId'],
                'oncore_protocol_subject_status' => $subject['status'] == 'ON STUDY' ? OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY : OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY,
                'excluded' => OnCoreIntegration::NO,
                'status' => OnCoreIntegration::ONCORE_ONLY
            );

            $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
            if (!$entity) {
                throw new \Exception(implode(',', $entity->errors));
            }
            $record = $entity->getData();
        }
        return $record;
    }

    /**
     * This method will match redcap records to oncore protocol subjects. and save the result in entity table
     * @return void
     * @throws \Exception
     */
    public function syncRecords()
    {
        if (!$this->getEntityRecord()) {
            throw new \Exception('No REDCap Project linked to OnCore Protocol found.');
        }

        if (!$this->getUser()->isOnCoreContactAllowedToPush()) {
            throw new \Exception('You do not have permissions to pull/push data from this protocol.');
        }

        $redcapRecords = $this->getSubjects()->getRedcapProjectRecords();
//        if(!$redcapRecords){
//            throw new \Exception('Cant find recap records');
//        }


        $oncoreProtocolSubjects = $this->getSubjects()->getOnCoreProtocolSubjects($this->getEntityRecord()['oncore_protocol_id']);
//        if(!$oncoreProtocolSubjects){
//            throw new \Exception('Cant find oncore subjects');
//        }

        $fields = $this->getMapping()->getProjectFieldMappings();

        if (!$fields['pull']) {
            throw new \Exception('Fields Pulling Map is not defined.');
        }

        foreach ($oncoreProtocolSubjects as $subject) {
            $onCoreMrn = $subject['demographics']['mrn'];
//            $redcapRecord = $this->getSubjects()->getREDCapRecordIdViaMRN($onCoreMrn, $this->getEntityRecord()['redcap_event_id'], $fields['mrn']);
            $redcapRecord = $this->getSubjects()->getREDCapRecordIdViaMRN($onCoreMrn, OnCoreIntegration::getEventNameUniqueId($fields['pull']['mrn']['event']), $fields['pull']['mrn']['redcap_field']);
            if ($redcapRecord) {
                $this->matchREDCapRecordWithOnCoreSubject($redcapRecord, $subject, $fields);
                // now remove redcap record from array
                unset($redcapRecords[$redcapRecord['id']]);
            } else {
                $record = $this->processOnCoreOnlyRecord($subject);
            }
        }

        // left redcap records on redcap but not on oncore
        foreach ($redcapRecords as $id => $redcapRecord) {
            $record = $this->processREDCapOnlyRecord($id);
        }
        //TODO update entity table when redcap record or Oncore protocol subject is deleted.
    }


    public function pushREDCapRecordToOnCore($redcapRecordId, $oncoreFieldsDef)
    {
        $linkage = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], $redcapRecordId);
        // check if excluded
        if ($linkage['excluded']) {
            throw new \Exception('This Record is excluded and you cant sync it.');
        }


        $result = $this->getSubjects()->pushToOnCore($this->getEntityRecord()['oncore_protocol_id'], $redcapRecordId, $this->getMapping()->getProjectFieldMappings()['push'], $oncoreFieldsDef);
        // reset loaded subjects for protocol so we can pull them after creating new one.
//        $this->getSubjects()->setOnCoreProtocolSubjects(null, true);
//
//        // now sync redcap with oncore
//        $this->syncIndividualRecord($redcapRecordId);

        Entities::createLog("REDCap Record Id#$redcapRecordId was pushed succesfully to OnCore Protocol .");
        return $result;
    }

    /**
     * gather protocol related object objects and data. Entity record, onCore subjects, redcap records.
     * @param $redcapProjectId
     * @return void
     * @throws \Exception
     */
    public function prepareProtocol($redcapProjectId)
    {
        try {
            $protocol = self::getProtocolEntityRecord($redcapProjectId);
            if (!empty($protocol)) {
                $this->setEntityRecord($protocol);


                // get all protocol staff and find current user OnCore contact. do not prepare for cron because no user will be found.
                if ($this->getEntityRecord() && $this->getUser()->getRedcapUser()) {
                    $this->getUser()->prepareUser($this->getEntityRecord()['id'], $this->getEntityRecord()['oncore_protocol_id']);
                }

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
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
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
            $this->setSubjects(new Subjects($this->getUser(), $this->getMapping(), $this->canPushToProtocol()));
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
            // get oncore protocol object test
            $protocol = $this->getOnCoreProtocol();
            $a = in_array(strtolower($protocol['protocolStatus']), $this->getUser()->getStatusesAllowedToPush());
            Entities::createLog('current status:' . implode(',', $this->getUser()->getStatusesAllowedToPush()));
            Entities::createLog('Protocol status: ' . $protocol['protocolStatus']);
            Entities::createLog('Protocol status in array ' . $a ? 1 : 0);
            Entities::createLog('final :' . in_array(strtolower($protocol['protocolStatus']), $this->getUser()->getStatusesAllowedToPush()) && $this->getEntityRecord()['status'] == OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES && $this->getUser()->isOnCoreContactAllowedToPush());
            // now check if protocol status in statuses allowed to push
            return in_array(strtolower($protocol['protocolStatus']), $this->getUser()->getStatusesAllowedToPush()) && $this->getEntityRecord()['status'] == OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES && $this->getUser()->isOnCoreContactAllowedToPush();
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
            $results['total_redcap_count'] = 0;
            $results['total_oncore_count'] = 0;
            $results['match_count'] = 0;
            $results['excluded_count'] = 0;
            $results['missing_oncore_status_count'] = 0;
            foreach ($records as $record) {
                if (isset($record['redcap']) && !isset($record['oncore'])) {
                    $results['redcap_only_count'] += 1;
                    $results['total_redcap_count'] += 1;
                } elseif (!isset($record['redcap']) && isset($record['oncore'])) {
                    $results['oncore_only_count'] += 1;
                    $results['total_oncore_count'] += 1;
                } elseif (isset($record['redcap']) && isset($record['oncore']) && $record['status'] == OnCoreIntegration::FULL_MATCH) {
                    $results['full_match_count'] += 1;
                    $results['total_oncore_count'] += 1;
                    $results['total_redcap_count'] += 1;
                    $results['match_count'] += 1;
                } elseif (isset($record['redcap']) && isset($record['oncore']) && $record['status'] == OnCoreIntegration::PARTIAL_MATCH) {
                    $results['partial_match_count'] += 1;
                    $results['total_oncore_count'] += 1;
                    $results['total_redcap_count'] += 1;
                    $results['match_count'] += 1;
                }
                if (isset($record['excluded']) && $record['excluded'] == '1') {
                    $results['excluded_count'] += 1;
                }
                if (isset($record['oncore']) && $record['oncore']['status'] == null) {
                    $results['missing_oncore_status_count'] += 1;
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

            $response = $this->getUser()->get('protocolStaff?protocolId=' . $this->getOnCoreProtocol()['protocolId']);

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
        } catch (GuzzleException $e) {
            if (method_exists($e, 'getResponse')) {
                $response = $e->getResponse();
                $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
                throw new \Exception($responseBodyAsString['message']);
            } else {
                echo($e->getMessage());
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            echo $e->getMessage();
        }
    }


    public function processCron($id, $irb)
    {
        $protocols = $this->searchOnCoreProtocolsViaIRB($irb);

        if (!empty($protocols)) {
            $entity_oncore_protocol = self::getProtocolEntityRecord($id, $irb);
            if (empty($entity_oncore_protocol)) {
                foreach ($protocols as $protocol) {
                    $data = array(
                        'redcap_project_id' => $id,
                        'irb_number' => $irb,
                        'oncore_protocol_id' => $protocol['protocolId'],
                        // cron will save the first event. and when connect is approved the redcap user has to confirm the event id.
                        'redcap_event_id' => 0,
                        'status' => '0',
                        'last_date_scanned' => time()
                    );

                    $entity = (new Entities)->create(OnCoreIntegration::ONCORE_PROTOCOLS, $data);

                    if ($entity) {
                        Entities::createLog(' : OnCore Protocol record created for IRB: ' . $irb . '.');
                        $this->setEntityRecord($data);
                        $this->prepareProtocolSubjects();
                        //$this->syncRecords();
                    } else {
                        throw new \Exception(implode(',', $entity->errors));
                    }
                }
            } else {
                $this->updateProtocolEntityRecordTimestamp($entity_oncore_protocol['id']);
                Entities::createLog('OnCore Protocol record updated for IRB: ' . $irb . '.');
            }
        } else {
            Entities::createLog('IRB ' . $irb . ' has no OnCore Protocol.');
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
            $response = $this->getUser()->get('protocols/' . $protocolID);

            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody(), true);
                if (!empty($data)) {
                    $this->setOnCoreProtocol($data);
                    return $data;
                }
            }
        } catch (GuzzleException $e) {
            if (method_exists($e, 'getResponse')) {
                $response = $e->getResponse();
                $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
                throw new \Exception($responseBodyAsString['message']);
            } else {
                echo($e->getMessage());
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            echo $e->getMessage();
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
            $response = $this->getUser()->get('protocolManagementDetails?irbNo=' . $irbNum);

            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody(), true);
                if (empty($data)) {
                    return [];
                } else {
                    return $data;
                }
            }
        } catch (GuzzleException $e) {
            if (method_exists($e, 'getResponse')) {
                $response = $e->getResponse();
                $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
                throw new \Exception($responseBodyAsString['message']);
            } else {
                echo($e->getMessage());
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            echo $e->getMessage();
        }
    }

    /**
     * @throws \Exception
     */
    public function pullOnCoreRecordsIntoREDCap($record)
    {
        if ($redcapId = $this->getSubjects()->pullOnCoreRecordsIntoREDCap($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], $record, $this->getMapping()->getProjectFieldMappings()['pull'])) {
            // update linkage entity table with redcap record and new status
//            $this->getSubjects()->setRedcapProjectRecords($this->getEntityRecord()['redcap_project_id']);
//            $this->syncIndividualRecord($redcapId);
            return array('message' => 'REDCap Record ' . $redcapId . ' got synced', 'status' => 'success', 'id' => $record['oncore']);
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
    public static function getProtocolEntityRecord($redcapProjectId, $irbNum = '', $status = 2)
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
            return self::getProtocolEntityRecord($redcapProjectId, $irbNum, 0);
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

    /**
     * @return Mapping
     */
    public function getMapping(): Mapping
    {
        return $this->mapping;
    }

    /**
     * @param Mapping $mapping
     */
    public function setMapping(Mapping $mapping): void
    {
        $this->mapping = $mapping;
    }


}
