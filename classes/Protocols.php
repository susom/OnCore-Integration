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

    private function matchREDCapRecordWithOnCoreSubject($redcapRecord, $subject, $fields, $count = 1)
    {

        // select oncore subject without redcap record
        $record = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], '', $subject['protocolSubjectId']);

        $data = array(
            'redcap_project_id' => (string)$this->getEntityRecord()['redcap_project_id'],
            'oncore_protocol_id' => (string)$this->getEntityRecord()['oncore_protocol_id'],
            'redcap_record_id' => (string)$redcapRecord['id'],
            'oncore_protocol_subject_id' => $subject['protocolSubjectId'],
            'oncore_protocol_subject_status' => $subject['status'] == 'ON STUDY' ? OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY : OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY,
            #'excluded' => OnCoreIntegration::NO,
            'status' => $this->getSubjects()->determineSyncedRecordMatch($subject, $redcapRecord['record'], $fields['pull'])
        );
        // if redcap_record_id is empty then update linkage record. otherwise its a duplicate record and we need to create new linkage record.
        if ($record && $record['redcap_record_id'] == $redcapRecord['id']) {
            $this->getSubjects()->updateLinkageRecord($record['id'], $data);
        } else {
            //select redcap record without oncore subject
            $record = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], $redcapRecord['id'], '');
            if ($record) {
                $this->getSubjects()->updateLinkageRecord($record['id'], $data);
            } else {
                // manually override status ONLY if there is a posi
                if ($count > 1) {
                    $data['status'] = OnCoreIntegration::REDCAP_ONLY;
                    $data['oncore_protocol_subject_id'] = null;
                }

                //entity = (new Entities)->getFactory()->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
                $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
                if (!$entity) {
                    throw new \Exception(implode(',', $entity->errors));
                }
                $record = $entity;
            }
        }
        return $record;
    }

    private function processREDCapOnlyRecord($id)
    {
        $record = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], $id, '');

        if (!$record) {
            $data = array(
                'redcap_project_id' => (string)$this->getEntityRecord()['redcap_project_id'],
                'oncore_protocol_id' => (string)$this->getEntityRecord()['oncore_protocol_id'],
                'redcap_record_id' => (string)$id,
                'oncore_protocol_subject_id' => '',
                #'excluded' => OnCoreIntegration::NO,
                'status' => OnCoreIntegration::REDCAP_ONLY
            );
            //$e = (new Entities)->getFactory();
            $e = (new Entities);
            $entity = $e->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
            if (!$entity) {
                throw new \Exception(implode(',', $e->errors));
            }
            $record = $entity;
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
                'redcap_project_id' => (string)$this->getEntityRecord()['redcap_project_id'],
                'oncore_protocol_id' => (string)$this->getEntityRecord()['oncore_protocol_id'],
                'redcap_record_id' => '',
                'oncore_protocol_subject_id' => $subject['protocolSubjectId'],
                'oncore_protocol_subject_status' => $subject['status'] == 'ON STUDY' ? OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY : OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY,
                #'excluded' => OnCoreIntegration::NO,
                'status' => OnCoreIntegration::ONCORE_ONLY
            );

            # $entity = (new Entities)->getFactory()->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
            $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
            if (!$entity) {
                throw new \Exception(implode(',', $entity->errors));
            }
            $record = $entity;
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

        if (!$this->getEntityRecord()['status']) {
            throw new \Exception('Linkage between this REDCap project and OnCore protocol is not approved yet. Please approve the linkage then try again.');
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

        // make sure nothing else is running.
        if ($this->getSubjects()->getSubjectsLock($this->getEntityRecord()['redcap_project_id'])) {
            foreach ($oncoreProtocolSubjects as $subject) {
                $onCoreMrn = $subject['demographics']['mrn'];
//            $redcapRecord = $this->getSubjects()->getREDCapRecordIdViaMRN($onCoreMrn, $this->getEntityRecord()['redcap_event_id'], $fields['mrn']);
                $redcapRecord = $this->getSubjects()->getREDCapRecordIdViaMRN($onCoreMrn, OnCoreIntegration::getEventNameUniqueId($fields['pull']['mrn']['event']), $fields['pull']['mrn']['redcap_field']);
                if (!empty($redcapRecord)) {
                    if (count($redcapRecord) == 1) {
                        // if no duplicated MRN just use first one.
                        $id = $redcapRecord[0]['id'];
                        $this->matchREDCapRecordWithOnCoreSubject($redcapRecord[0], $subject, $fields, count($redcapRecord));

                        // id to be removed
                        $id = $redcapRecord[0]['id'];
                    } else {
                        // this protocol allows duplicate enrollment we need to find matching subject using protocolSubjectId.
                        if (!isset($fields['pull']['protocolSubjectId'])) {
                            throw new \Exception("MRN $onCoreMrn enrolled multiple times. In order to correctly match records you <strong>Must</strong> map `protocolSubjectid` in the <string>Pull</string> fields mapping page.");
                        } else {
                            // we need to match record using protocol Subject Id.
                            foreach ($redcapRecord as $item) {
                                $redcapProtocolSubjectId = $item['record'][OnCoreIntegration::getEventNameUniqueId($fields['pull']['protocolSubjectId']['event'])][$fields['pull']['protocolSubjectId']['redcap_field']];
                                if (!$redcapProtocolSubjectId) {
                                    // for new records update redcap and add new subject protocol id. then sync it with the subject.
                                    $data = [];
                                    $data[\REDCap::getRecordIdField()] = $item['id'];
                                    $data[$fields['pull']['protocolSubjectId']['redcap_field']] = $subject['protocolSubjectId'];
                                    $data['redcap_event_name'] = OnCoreIntegration::getEventNameUniqueId($fields['pull']['protocolSubjectId']['event']);
                                    $response = \REDCap::saveData($this->getEntityRecord()['redcap_project_id'], 'json', json_encode(array($data)));
                                    if (!empty($response['errors'])) {
                                        {
                                            if (is_array($response['errors'])) {
                                                throw new \Exception(implode(",", $response['errors']));
                                            } else {
                                                throw new \Exception($response['errors']);
                                            }

                                        }
                                    }
                                    $redcapProtocolSubjectId = $subject['protocolSubjectId'];
                                    // set the value to make sure match is full
                                    $item['record'][OnCoreIntegration::getEventNameUniqueId($fields['pull']['protocolSubjectId']['event'])][$fields['pull']['protocolSubjectId']['redcap_field']] = $subject['protocolSubjectId'];
                                    // get redcap data after saving protocolSUbjectid
                                    $this->prepareProjectRecords();
                                }
                                if ($redcapProtocolSubjectId == $subject['protocolSubjectId']) {
                                    $this->matchREDCapRecordWithOnCoreSubject($item, $subject, $fields);
                                    $id = $item['id'];
                                    break;
                                }
                            }
                        }

                    }
                    // now remove redcap record from array
                    unset($redcapRecords[$id]);
                } else {
                    $record = $this->processOnCoreOnlyRecord($subject);
                }
                unset($redcapRecord);
                unset($id);
            }


            // left redcap records on redcap but not on oncore
            foreach ($redcapRecords as $id => $redcapRecord) {
                $record = $this->processREDCapOnlyRecord($id);
            }


            $this->getSubjects()->releaseSubjectsLock($this->getEntityRecord()['redcap_project_id']);
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
     * gather protocol related objects and data. Entity record, onCore subjects, redcap records.
     * @param $redcapProjectId
     * @return void
     * @throws \Exception
     */
    public function prepareProtocol($redcapProjectId)
    {
        try {
            $protocol = self::getOnCoreProtocolEntityRecord($redcapProjectId);
            if (!empty($protocol)) {
                $this->setEntityRecord($protocol);


                // get protocols staff and match logged in redcap user with OnCore contact. do not prepare for cron because no redcap user will be found.
                if ($this->getEntityRecord() && $this->getUser()->getRedcapUser()) {
                    $this->getUser()->prepareUser($this->getEntityRecord()['id'], $this->getEntityRecord()['oncore_protocol_id']);
                }

                $this->setOnCoreProtocol($this->getOnCoreProtocolsViaID($this->getEntityRecord()['oncore_protocol_id']));


                /**
                 * init subject class. wont be used till mappign is defined.
                 */
                $this->setSubjects(new Subjects($this->getUser(), $this->getMapping(), $this->getUser()->getRedcapUser() && $this->canPushToProtocol()));

                if (!empty($this->getMapping()->getProjectMapping()['pull']) or !empty($this->getMapping()->getProjectMapping()['push'])) {
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
        } catch (\Throwable $e) {
            // API is not working for a reason or another.
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * this function will gather records for linked redcap project.
     * @return void
     */
    public function prepareProjectRecords()
    {
        $this->getSubjects()->setRedcapProjectRecords($this->getEntityRecord()['redcap_project_id']);
    }

    /**
     * gather and save subjects for linked OnCore Protocol
     * @return void
     */
    public function prepareProtocolSubjects()
    {
        try {
            $this->getSubjects()->setOnCoreProtocolSubjects($this->getEntityRecord()['oncore_protocol_id']);
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
        }
    }

    /**
     * determine if redcap user can push REDCap records and create protocol subjects
     * @return bool
     */
    public function canPushToProtocol()
    {
        if (!$this->getEntityRecord()) {
            return false;
        } else {
            // get oncore protocol object test
            $protocol = $this->getOnCoreProtocol();
            // now check if protocol status in statuses allowed to push
            $status = in_array(strtolower($protocol['protocolStatus']), $this->getUser()->getStatusesAllowedToPush());
            if (!$status) {
                Entities::createLog("" . $protocol['protocolNo'] . " status " . $protocol['protocolStatus'] . " is not part of allowed statuses");
            }
            $linked = $this->getEntityRecord()['status'] == OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES;
            if (!$linked) {
                Entities::createLog("" . $protocol['protocolNo'] . " is not approved. Current status is " . $this->getEntityRecord()['status'] . "");
            }
            $contactRole = $this->getUser()->isOnCoreContactAllowedToPush();
            if (!$contactRole) {
                Entities::createLog($this->getUser()->getRedcapUser()->getUsername() . " has OnCore role (" . $this->getUser()->getOnCoreContact()['role'] . ") which is not allowed to push records to OnCore");
            }
            return $status && $linked && $contactRole;
        }

    }

    /**
     * @return array|void
     */
    public function getSyncedRecords($use_filter = null)
    {
        if ($this->getEntityRecord()) {
            // reset redcap records array and apply saved filter.
            if ($use_filter) {
                $this->getSubjects()->setRedcapProjectRecords($this->getEntityRecord()['redcap_project_id'], true);
            }

            $this->getSubjects()->setSyncedRecords($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id']);
            return $this->getSubjects()->getSyncedRecords();
        } else {
            throw new \Exception('No Protocol entity record found for this Protocol');
        }
    }

    /**
     * get OnCore Protocol records summaries.
     * @return array
     * @throws \Exception
     */
    public function getSyncedRecordsSummaries()
    {
        if ($this->getEntityRecord()) {
            $records = $this->getSyncedRecords();
            return $this->getSubjects()->prepareSyncedRecordsSummaries($records);
        } else {
            return [];
        }
    }


    /**
     * @param $id
     * @param $irb
     * @return void
     * @throws GuzzleException
     */
    public function processCron($redcapProjectId, $irb, $oncoreProtocolId, $libraries = [])
    {
        $protocols = $this->searchOnCoreProtocolsViaIRB($irb);

        if (!empty($protocols)) {
            $entity_oncore_protocol = self::getOnCoreProtocolEntityRecord($redcapProjectId, $irb);
            if (empty($entity_oncore_protocol)) {
                foreach ($protocols as $protocol) {
                    if ($oncoreProtocolId == $protocol['protocolId']) {
                        $data = array(
                            'redcap_project_id' => $redcapProjectId,
                            'irb_number' => $irb,
                            'oncore_protocol_id' => $protocol['protocolId'],
                            // cron will save the first event. and when connect is approved the redcap user has to confirm the event id.
                            'redcap_event_id' => 0,
                            'status' => '0',
                            'last_date_scanned' => time(),
                            'oncore_library' => $this->getProtocolDefinedLibraryId($protocol['protocolId'], $libraries)
                        );

                        $entity = (new Entities)->create(OnCoreIntegration::ONCORE_PROTOCOLS, $data);

                        if ($entity) {
                            Entities::createLog('OnCore Protocol Entity table record created for IRB: ' . $irb . '.');
                            $this->setEntityRecord($data);

                            // do not pull any protocol data till user approve the redcap oncore linkage.
                            //$this->prepareProtocolSubjects();
                            //$this->syncRecords();

                            return $entity;
                        } else {
                            throw new \Exception(implode(',', $entity->errors));
                        }
                    }
                }
            } else {
                // if entity record exists but user wants to link to a different protocol.
                if ($oncoreProtocolId != $entity_oncore_protocol['oncore_protocol_id']) {
                    $this->updateProtocolEntityRecordProtocolId($entity_oncore_protocol['id'], $oncoreProtocolId);
                    Entities::createLog('OnCore Protocol Id changed from ' . $entity_oncore_protocol['oncore_protocol_id'] . ' to ' . $oncoreProtocolId);
                    // get new entity record.
                    return self::getOnCoreProtocolEntityRecord($redcapProjectId, $irb);
                } else {
                    // update last_date_scanned with current time().
                    $this->updateProtocolEntityRecordTimestamp($entity_oncore_protocol['id']);
                    Entities::createLog('OnCore Protocol record updated for IRB: ' . $irb . '.');
                    return $entity_oncore_protocol;
                }

            }
        } else {
            Entities::createLog('IRB ' . $irb . ' has no OnCore Protocols.');
        }

    }

    public function getProtocolDefinedLibraryId($protocol, $libraries)
    {
        $library = $this->getProtocolDefinedLibrary($protocol, $libraries);
        return array_key_first($library);
    }

    public function getProtocolDefinedLibrary($protocolId, $libraries)
    {
        $protocol = $this->getOnCoreProtocolsViaID($protocolId);
        foreach ($libraries as $key => $library) {

            if ($library['library-name'] == $protocol['library']) {
                return array($key => $library);
            }
        }
        throw new \Exception("Protocol Library `" . $protocol['library'] . "` is not defined. Please Contact REDCap Admin. ");
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
                $temp = $response->getBody()->getContents();
                $temp = str_replace('\"', '', $temp);
                $data = json_decode($temp, true);
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

    public function getOnCoreProtocolsViaIRB($irb)
    {
        $protocols = $this->searchOnCoreProtocolsViaIRB($irb);
        if (!empty($protocols)) {
            foreach ($protocols as $index => $protocol) {
                $protocols[$index]['protocol'] = $this->getOnCoreProtocolsViaID($protocol['protocolId']);
            }
        }
        return $protocols;
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
     * @param $record array('redcap' => [REDCAP_RECORD_ID] OR NULL , 'oncore' => [ONCORE_PROTOCOL_SUBJECT_ID])
     * @return array|void
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
     * pull protocol entity record.
     * @param $redcapProjectId
     * @param $irbNum
     * @param int $status default YES
     * @return array|false|mixed|string[]|null
     * @throws \Exception
     */
    public static function getOnCoreProtocolEntityRecord($redcapProjectId, $irbNum = '', $status = 2)
    {
        if ($redcapProjectId == '') {
            throw new \Exception('REDCap project ID can not be null');
        }
        if ($irbNum != '') {
            $sql = sprintf("select * from %s where irb_number = %s AND redcap_project_id = %s AND status = %s ", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS), db_escape($irbNum), db_escape($redcapProjectId), db_escape($status));
            $record = db_query($sql);
        } else {
            $sql = sprintf("select * from %s where redcap_project_id = %s AND status = %s ", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS), db_escape($redcapProjectId), db_escape($status));
            $record = db_query($sql);
        }
        if ($record->num_rows == 0 && $status == 0) {
            return [];
        } elseif ($record->num_rows == 0 && $status == 2) {
            return self::getOnCoreProtocolEntityRecord($redcapProjectId, $irbNum, 0);
        } else {
            return db_fetch_assoc($record);
        }
    }

    /**
     * update oncore_protocol entity record timestampts
     * @param $entityId
     * @return void
     */
    public function updateProtocolEntityRecordTimestamp($entityId): void
    {
        $sql = sprintf("UPDATE %s set last_date_scanned = '%s', updated = '%s' WHERE id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS), db_escape(time()), db_escape(time()), db_escape($entityId));
        db_query($sql);
    }

    /**
     * update oncore_protocol entity record protocol id
     * @param $entityId
     * @param $protocolId
     * @return void
     * @throws \Exception
     */
    public function updateProtocolEntityRecordProtocolId($entityId, $protocolId): void
    {
        $sql = sprintf("UPDATE %s set oncore_protocol_id = '%s', updated = '%s' ,last_date_scanned = '%s' WHERE id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS), db_escape($protocolId), db_escape(time()), db_escape(time()), db_escape($entityId));
        db_query($sql);
    }

    /**
     * update oncore_protocol entity record status
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
        $sql = sprintf("UPDATE %s set status = '%s', updated = '%s' WHERE id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS), db_escape($status), db_escape(time()), db_escape($entityId));
        db_query($sql);
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

    public function deleteProtocolRecord($redcapProjectId)
    {
        if (!$redcapProjectId) {
            throw new \Exception('REDCap Project Id is missing');
        }


        $sql = sprintf("DELETE FROM %s WHERE redcap_project_id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS), db_escape($redcapProjectId));
        $result = db_query($sql);
        if (!$result) {
            throw new \Exception(db_error());
        }
        return $result;
    }

    public function autoPullFromOnCore()
    {
        $pullFields = $this->getMapping()->getProjectFieldMappings()['pull'];
        # if not pull fields are defined then skip
        if (!empty($pullFields)) {
            $subjects = $this->getSubjects()->getOnCoreProtocolSubjects($this->getEntityRecord()['oncore_protocol_id']);
            foreach ($subjects as $subject) {
                $linkage = $this->getSubjects()->getLinkageRecord($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], '', $subject['protocolSubjectId']);
                // if linkage record exists ignore it
                if ($linkage) {
                    continue;
                } else {
                    $data = array(
                        'redcap_project_id' => (string)$this->getEntityRecord()['redcap_project_id'],
                        'oncore_protocol_id' => (string)$this->getEntityRecord()['oncore_protocol_id'],
                        'redcap_record_id' => '',
                        'oncore_protocol_subject_id' => $subject['protocolSubjectId'],
                        'oncore_protocol_subject_status' => $subject['status'] == 'ON STUDY' ? OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY : OnCoreIntegration::ONCORE_SUBJECT_ON_STUDY,
                        #'excluded' => OnCoreIntegration::NO,
                        'status' => OnCoreIntegration::ONCORE_ONLY
                    );
                    $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $data);
                    $this->getSubjects()->pullOnCoreRecordsIntoREDCap($this->getEntityRecord()['redcap_project_id'], $this->getEntityRecord()['oncore_protocol_id'], array('oncore' => $entity['oncore_protocol_subject_id']), $pullFields);

                }
            }
        }

    }
}
