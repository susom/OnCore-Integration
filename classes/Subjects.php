<?php

namespace Stanford\OnCoreIntegration;

use \Exception;
use \GuzzleHttp;
use GuzzleHttp\Exception\GuzzleException;
require_once 'classes/SubjectDemographics.php';

class Subjects extends SubjectDemographics
{

    use emLoggerTrait;

    /**
     * we defined prefix just to be able to emlog trait
     * @var string
     */
    public $PRIFIX;

    /**
     * @var Users
     */
    private $user;


    private $onCoreProtocolSubjects;

    /**
     * @var
     */
    private $redcapProjectRecords;


    /**
     * @var array
     */
    private $syncedRecords;


    /**
     * @var bool
     */
    private $canPush = false;


    /**
     * @var Mapping
     */
    private $mapping;

    /**
     * The top functions can be used to query and verify MRNs in the home institution's Electronic
     * Medical Record system.
     *
     * The bottom functions can be used to query OnCore to determine if an MRN is already entered
     * into the system.
     * @param Users $user
     * @param $canPush
     * @param $reset
     */
    public function __construct($user, $mapping, $canPush = false, $reset = false)
    {
        parent::__construct($reset);

        $this->setUser($user);

        $this->setCanPush($canPush);

        $this->setMapping($mapping);

        $this->PRIFIX = $this->getUser()->getPREFIX();
    }

    /**
     * Retrieves demographics data from the home institutions EMR system. If MRN validation is not implemented,
     * the URL in the EM system configuration file will not be set and this function will never get called.
     *
     * @param $mrns - list of MRNs to retrieve demographics data
     * @param bool $getDemographics - if true, demographics will be retrieved.  If false, only true/false will be
     *     retrieved for each MRN for validation.
     * @return array
     */
    public function getEMRDemographics($mrns, $getDemographics = true)
    {
        $demographics = array();

        // Retrieve the URL to the MRN Verification API.  If one is not entered, just set all MRNs as valid.
        $url = $this->getUser()->getMrnVerificationURL();
        if (!empty($url)) {

            // Call API Endpoint
            try {

                // Put togther the URL with the info needed to process the rrequest
                $url .= "&mrns=" . implode(',', $mrns);


                // Make the API call
                $client = new GuzzleHttp\Client;
                $resp = $client->request('GET', $url, [
                    GuzzleHttp\RequestOptions::SYNCHRONOUS => true
                ]);
            } catch (Exception $ex) {

                // TODO: What to do about exceptions
                $this->emError("Exception calling endpoint: " . $ex);
                Entities::createException("Exception calling endpoint: " . $ex);
            }

            // Make the API call was successful.
            $returnStatus = $resp->getStatusCode();
            if ($returnStatus <> 200) {
                $this->emError("API call to $url, HTTP Return Code is: $returnStatus");
                Entities::createException("API call to $url, HTTP Return Code is: $returnStatus");
            } else {
                // Everything worked so retrieve the demographics data
                $demographics = $resp->getBody()->getContents();
            }
        } else {

            // A URL was not entered so we will not verify the MRNs and retrieve demographic data.
            // Set all the MRNs as valid since we can't verify.
            foreach ($mrns as $mrn) {
                $subject[$mrn] = array("mrn" => $mrn,
                    "mrnValid" => "true"
                );

            }
            $demographics = json_encode($subject);

            /*
             * {
             * "12345678":{
             *      "mrn":"12345678",
             *      "lastName":"CADTEST",
             *      "firstName":"SANITY",
             *      "birthDate":"01/23/1986",
             *      "gender":"Female",
             *      "ethnicity":"Unknown",
             *      "race":"Unknown",
             *      "mrnValid":"true"
             *      },
             * "11111111":{
             *      "mrn":"11111111",
             *      "lastName":"PATIENT",
             *      "firstName":"HOLD",
             *      "birthDate":"01/01/1900",
             *      "gender":"Unknown",
             *      "ethnicity":"Non-Hispanic",
             *      "race":"Unknown",
             *      "mrnValid":"true"
             *      },
             *  "11111112":{
             *      "mrn":"11111112",
             *      "mrnValid":"false"
             *      }
             * }
             */

            return $demographics;
        }
    }

    /**
     * Retrieves MRN data from the home institutions EMR system.  If not implemented, the
     * default returned value is that the MRN is valid.
     *
     * @param $mrns
     * @return array
     */
    public function emrMrnsValid($mrns)
    {

        return $this->getEMRDemographics($mrns, false);

    }

    /**
     * this method will determine if oncore and redcap fully or partially matched.
     * @param $onCoreSubject
     * @param $redcapRecord
     * @param $fields
     * @return int
     */
    public function determineSyncedRecordMatch($onCoreSubject, $redcapRecord, $fields)
    {
        foreach ($fields as $key => $field) {
            // has mapped values
            if (isset($field['value_mapping'])) {
                // oncore is array of text ie. race
                $onCoreType = $this->getMapping()->getOncoreType($key);
                if ($onCoreType == 'array') {
                    if (is_array($onCoreSubject['demographics'][$key])) {
                        $parsed = $onCoreSubject['demographics'][$key];
                    } else {
                        $parsed = json_decode($onCoreSubject['demographics'][$key], true);
                    }
                } else {
                    $parsed = null;
                }
                if (is_array($parsed)) {
                    // if redcap field is checkbox
                    if ($field['field_type'] == 'checkbox') {
                        // get array of 0/1 from redcap for checkboxes
                        $rc = $redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];
                        foreach ($parsed as $item) {
                            $map = $this->getMapping()->getOnCoreMappedValue($item, $field);
                            // if the oncore value mapped redcap checkbox is not check then partial match
                            if (!$rc[$map['rc']]) {
                                return OnCoreIntegration::PARTIAL_MATCH;
                            }
                        }
                    } else {
                        // if redcap is text or radio then every race must match the redcap value
                        foreach ($onCoreSubject['demographics'][$key] as $item) {
                            $map = $this->getMapping()->getOnCoreMappedValue($item, $field);
                            // if the oncore value mapped redcap checkbox is not check then partial match
                            if ($redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']] != $map['rc']) {
                                return OnCoreIntegration::PARTIAL_MATCH;
                            }
                        }
                    }
                } else {
                    $map = $this->getMapping()->getOnCoreMappedValue($onCoreSubject['demographics'][$key], $field);
                    if ($redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']] != $map['rc']) {
                        return OnCoreIntegration::PARTIAL_MATCH;
                    }
                }
            } else {
                if ($onCoreSubject['demographics'][$key] != $redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']]) {
                    return OnCoreIntegration::PARTIAL_MATCH;
                }
            }
        }
        return OnCoreIntegration::FULL_MATCH;
    }


    /**
     * get record for current project
     * @param $recordId
     * @return array|mixed
     */
    public function getREDCapRecord($recordId)
    {
        if ($this->getRedcapProjectRecords()) {
            foreach ($this->getRedcapProjectRecords() as $id => $record) {
                if ($id == $recordId) {
                    return $record;
                }
            }
        }
        return [];
    }

    /**
     * search for redcap record via MRN based on mapped fields between OnCore and REDCap
     * @param $mrn
     * @param $redcapEventId
     * @param $redcapMRNField
     * @return array|false
     */
    public function getREDCapRecordIdViaMRN($mrn, $redcapEventId, $redcapMRNField)
    {
        if ($this->getRedcapProjectRecords()) {
            foreach ($this->getRedcapProjectRecords() as $id => $record) {
                if ($record[$redcapEventId][$redcapMRNField] == $mrn) {
                    return array('id' => $id, 'record' => $record);
                }
            }
        }
        return false;
    }

    /**
     * search protocol subject record.
     * @param $onCoreProtocolId
     * @param $protocolSubjectId
     * @return array|mixed
     */
    public function getOnCoreProtocolSubject($onCoreProtocolId, $protocolSubjectId)
    {
        if ($this->getOnCoreProtocolSubjects($onCoreProtocolId)) {
            foreach ($this->getOnCoreProtocolSubjects($onCoreProtocolId) as $subject) {
                if ($subject['protocolSubjectId'] == $protocolSubjectId) {
                    return $subject;
                }
            }
        }
        return [];
    }


    /**
     * @return Users
     */
    public function getUser(): Users
    {
        return $this->user;
    }

    /**
     * @param Users $user
     */
    public function setUser(Users $user): void
    {
        $this->user = $user;
    }

    /**
     * @return mixed
     */
    public function getOnCoreProtocolSubjects($protocolId)
    {
        if (!$this->onCoreProtocolSubjects) {
            $this->setOnCoreProtocolSubjects($protocolId);
        }
        return $this->onCoreProtocolSubjects;
    }

    /**
     * set each protocol subject along its demographics.
     * @param mixed $onCoreProtocolSubjects
     */
    public function setOnCoreProtocolSubjects($protocolId = '', $resetSubjects = false): void
    {
//        $this->onCoreProtocolSubjects = $onCoreProtocolSubjects;
        try {
            // if we want to reset subject
            if ($resetSubjects) {
                $this->onCoreProtocolSubjects = [];
            } else {
                if (!$protocolId || $protocolId == '') {
                    throw new \Exception("No Protocol Provided");
                }

                $response = $this->getUser()->get('protocolSubjects?protocolId=' . $protocolId);

                if ($response->getStatusCode() < 300) {
                    $subjects = json_decode($response->getBody(), true);
                    if (empty($subjects)) {
                        $this->onCoreProtocolSubjects = [];
                    } else {

                        foreach ($subjects as $key => $subject) {
                            try {
                                $subjects[$key]['demographics'] = $this->getOnCoreSubjectDemographics($subject['subjectDemographicsId']);
                            } catch (\Exception $e) {
                                Entities::createException($e->getMessage());
                            }
                        }
                        $this->onCoreProtocolSubjects = $subjects;
                    }
                }
            }
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
            throw new \Exception($responseBodyAsString['message']);
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            echo $e->getMessage();
        }
    }

    /**
     * @return mixed
     */
    public function getRedcapProjectRecords()
    {
        return $this->redcapProjectRecords;
    }

    /**
     * set all redcap records
     * @param mixed $redcapProjectRecords
     */
    public function setRedcapProjectRecords($redcapProjectId): void
    {
        $param = array(
            'project_id' => $redcapProjectId,
            'return_format' => 'array',
//            'events' => $redcapEventId
        );
        $this->redcapProjectRecords = \REDCap::getData($param);
    }

    /**
     * @return array
     */
    public function getSyncedRecords(): array
    {
        return $this->syncedRecords;
    }

    /**
     * get linkage records for pid and protocol id
     * @param $redcapProjectId
     * @param $onCoreProtocolId
     * @param $redcapRecordId
     * @param $onCoreProtocolSubjectId
     * @return array|false|mixed|string[]|null
     * @throws Exception
     */
    public function getLinkageRecord($redcapProjectId, $onCoreProtocolId, $redcapRecordId = '', $onCoreProtocolSubjectId = '')
    {
        if (!$redcapProjectId) {
            throw new \Exception("No REDCap project provided");
        }
        if (!$onCoreProtocolId) {
            throw new \Exception("No OnCore protocol provided");
        }

        if ($redcapRecordId && $onCoreProtocolSubjectId) {
            $sql = "SELECT * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE . " WHERE excluded = " . OnCoreIntegration::NO . " and redcap_record_id = $redcapRecordId and oncore_protocol_subject_id = $onCoreProtocolSubjectId and redcap_project_id = $redcapProjectId AND oncore_protocol_id = $onCoreProtocolId";
        } elseif ($redcapRecordId) {
            $sql = "SELECT * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE . " WHERE excluded = " . OnCoreIntegration::NO . " and  redcap_record_id = $redcapRecordId and redcap_project_id = $redcapProjectId AND oncore_protocol_id = $onCoreProtocolId";
        } elseif ($onCoreProtocolSubjectId) {
            $sql = "SELECT * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE . " WHERE excluded = " . OnCoreIntegration::NO . " and  oncore_protocol_subject_id = $onCoreProtocolSubjectId and redcap_project_id = $redcapProjectId AND oncore_protocol_id = $onCoreProtocolId";
        } else {
            throw new \Exception("REDCap record id or OnCore Protocol Subject Id is missing");
        }
        $record = db_query($sql);

        return db_fetch_assoc($record);

    }

    /**
     * update entity record with redcap or OnCore ids
     * @param $id
     * @param $data
     * @return void
     * @throws Exception
     */
    public function updateLinkageRecord($id, $data)
    {
        $entity = (new Entities)->getInstance(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $id);
        if ($entity->setData($data)) {
            $entity->save();
        } else {
            throw new \Exception(implode(',', $entity->errors));
        }
    }

    /**
     * build array of outer join between redcap records and OnCore protocols subjects
     * @param $redcapProjectId
     * @param $onCoreProtocolId
     * @return void
     * @throws Exception
     */
    public function setSyncedRecords($redcapProjectId = '', $onCoreProtocolId = '')
    {
        // matched records on both redcap and oncore
        if ($redcapProjectId && $onCoreProtocolId) {
            $sql = "SELECT * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE . " WHERE redcap_project_id = $redcapProjectId AND oncore_protocol_id = $onCoreProtocolId";
        } else {
            throw new \Exception("No REDCap pid and OnCore Protocol Id provided");
        }
        $result = array();
        $q = db_query($sql);
        if (db_num_rows($q) > 0) {
            while ($row = db_fetch_assoc($q)) {
                $record = array();
                if ($row['redcap_record_id']) {
                    $record['redcap'] = $this->getREDCapRecord($row['redcap_record_id']);
                }
                if ($row['oncore_protocol_subject_id']) {
                    $record['oncore'] = $this->getOnCoreProtocolSubject($onCoreProtocolId, $row['oncore_protocol_subject_id']);
                }
                $record['status'] = $row['status'];
                $record['excluded'] = $row['excluded'];
                $record['entity_id'] = $row['id'];
                $result[] = $record;
            }
        }
        $this->syncedRecords = $result;
    }

    /**
     * @param string $protocolId
     * @param string $studySite
     * @param int $subjectDemographicsId
     * @param array $subjectDemographics
     * @return array
     * @throws Exception
     */
    public function createOnCoreProtocolSubject($protocolId, $studySite, $subjectDemographicsId = null, $subjectDemographics = null): array
    {
        if (!$protocolId) {
            throw new \Exception('Protocol is missing');
        }

        if (!$studySite) {
            throw new \Exception('Study site is missing');
        }
        if (!$subjectDemographicsId && !$subjectDemographics) {
            throw new \Exception('You must have either Subject demographic ID or Subject Demographics Object');
        }

        if (!$this->isCanPush()) {
            throw new \Exception('You cant push REDCap records to this Protocol. Because Protocol is not approved or its status is not valid.');
        }

        if ($subjectDemographics) {
            $keys = array_keys($subjectDemographics);
            $intersect = array_values(array_intersect($keys, OnCoreIntegration::$ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS));
            /**
             * make sure all required fields exists
             */
            $required = OnCoreIntegration::$ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS;
            sort($required);
            sort($intersect);
            if ($intersect != $required) {
                if (count($intersect) > count(OnCoreIntegration::$ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS)) {
                    $diff = array_diff($intersect, OnCoreIntegration::$ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS);
                } else {
                    $diff = array_diff(OnCoreIntegration::$ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS, $intersect);
                }
                throw new \Exception("Following field/s are missing: " . implode(',', $diff));
            }

            /**
             * make sure all required fields have values
             */
            $errors = [];
            foreach (OnCoreIntegration::$ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS as $field) {
                if ($subjectDemographics[$field] == '') {
                    $errors[] = $field;
                }
            }

            if (!empty($errors)) {
                throw new \Exception("Following field/s are missing values: " . implode(',', $errors));
            }
        }

        $response = $this->getUser()->post('protocolSubjects', ['protocolId' => $protocolId, 'studySite' => $studySite, 'subjectDemographics' => $subjectDemographics, 'subjectDemographicsId' => $subjectDemographicsId]);


        if ($response->getStatusCode() == 201) {
            return array('status' => 'success');
        } else {
            $data = json_decode($response->getBody(), true);
            return $data;
        }
    }

    public function searchOnCoreProtocolSubjectViaMRN($protocolId, $redcapMRN)
    {
        // reset oncore protocol subjects to get latest subjects
        $this->setOnCoreProtocolSubjects(null, true);

        $oncoreProtocolSubjects = $this->getOnCoreProtocolSubjects($protocolId);

        foreach ($oncoreProtocolSubjects as $oncoreProtocolSubject) {
            if ($oncoreProtocolSubject['demographics']['mrn'] == $redcapMRN) {
                return $oncoreProtocolSubject;
            }
        }
    }

    public function pushToOnCore($protocolId, $studySite, $redcapId, $fields, $oncoreFieldsDef)
    {
        $record = $this->getRedcapProjectRecords()[$redcapId];
        $redcapMRN = $record[OnCoreIntegration::getEventNameUniqueId($fields['mrn']['event'])][$fields['mrn']['redcap_field']];
        $onCoreRecord = $this->searchOnCoreSubjectUsingMRN($redcapMRN);
        if (empty($onCoreRecord)) {
            $demographics = $this->prepareREDCapRecordForOnCorePush($redcapId, $fields, $oncoreFieldsDef);
            $message = "No subject found for $redcapMRN. Using REDCap data to create new Subject.";
            Entities::createLog($message);
            $result = $this->createOnCoreProtocolSubject($protocolId, $studySite, null, $demographics);
            $result['message'] = $message;
            return $result;
        } else {
            // if subject is in different protocol then just add subject to protocol
            if ($onCoreRecord['subjectSource'] == 'OnCore') {
                $message = "OnCore Subject " . $onCoreRecord['subjectDemographicsId'] . " found for $redcapMRN. REDCap data will be ignored and OnCore subject will be used.";
                Entities::createLog($message);
                $result = $this->createOnCoreProtocolSubject($protocolId, $studySite, $onCoreRecord['subjectDemographicsId'], null);
                $result['message'] = $message;
                return $result;
            }
            // if subject is in Onstage this mean not part of any protocol
            // Onstage data has priority except null
            elseif ($onCoreRecord['subjectSource'] == 'Onstage') {
                // fill Onstage missing data from redcap record.
                $demographics = $this->fillMissingData($record, $onCoreRecord, $fields);
                $message = "No OnCore Subject found for $redcapMRN but a Record found on OnStage table. REDCap data will be used ONLY for missing data from OnStage.";
                Entities::createLog($message);
                $result = $this->createOnCoreProtocolSubject($protocolId, $studySite, null, $demographics);
                $result['message'] = $message;
                return $result;
            } else {
                throw new \Exception('Source is unknown');
            }
        }
    }

    /**
     * this method will fill empty field pulled from onStage with redcap data.
     * @param $redcapRecord
     * @param $onCoreRecord
     * @param $fields
     * @return mixed
     * @throws Exception
     */
    public function fillMissingData($redcapRecord, $onCoreRecord, $fields)
    {
        foreach ($fields as $key => $field) {
            // if oncore race array is empty
            if (is_array($onCoreRecord[$key]) && empty($onCoreRecord[$key])) {
                $redcapValue = $redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];
                if (is_array($redcapValue)) {
                    foreach ($redcapValue as $id => $value) {
                        //checkbox not checked
                        if (!$value) {
                            continue;
                        }
                        $map = $this->getMapping()->getREDCapMappedValue($id, $field);
                        if (!$map) {
                            throw new \Exception('cant find map for redcap value ' . $id);
                        }
                        $options[] = $map['oc'];
                    }
                    $onCoreRecord[$key] = $options;
                }
            } elseif ($onCoreRecord[$key] == '' || is_null($onCoreRecord[$key])) {
                $onCoreRecord[$key] = $redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];
            }
        }
        return $onCoreRecord;
    }

    /**
     * @param $redcapId
     * @param $fields
     * @return array
     * @throws Exception
     */
    public function prepareREDCapRecordForOnCorePush($redcapId, $fields, $oncoreFieldsDef)
    {
        $record = $this->getRedcapProjectRecords()[$redcapId];
        $data = [];
        foreach ($fields as $key => $field) {
            $redcapValue = $record[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];
            if (!isset($field['value_mapping'])) {

                $a = gettype($redcapValue);
                if (!in_array($a, $oncoreFieldsDef[$key]['oncore_field_type'])) {
                    //throw new \Exception('datatype does not match');
                    continue;
                }
                $data[$key] = $redcapValue;
            } else {
                if (in_array('array', $oncoreFieldsDef[$key]['oncore_field_type'])) {
                    $redcapValue = $record[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];
                    //redcap is checkbox
                    $options = [];
                    if (is_array($redcapValue)) {
                        foreach ($redcapValue as $id => $value) {
                            //checkbox not checked
                            if (!$value) {
                                continue;
                            }
                            $map = $this->getMapping()->getREDCapMappedValue($id, $field);
                            if (!$map) {
                                throw new \Exception('cant find map for redcap value ' . $id);
                            }
                            $options[] = $map['oc'];
                        }
                        $data[$key] = $options;
                    } else {
                        $map = $this->getMapping()->getREDCapMappedValue($redcapValue, $field);
                        if (!$map) {
                            throw new \Exception('cant find map for redcap value ' . $redcapValue);
                        }
                        $data[$key] = $map['oc'];
                    }
                } else {
                    $map = $this->getMapping()->getREDCapMappedValue($redcapValue, $field);
                    if (!$map) {
                        throw new \Exception('cant find map for redcap value ' . $redcapValue);
                    }
                    $data[$key] = $map['oc'];
                }
            }

        }
        return $data;
    }

    /**
     * @param $protocolId
     * @param $onCoreSubjectId
     * @param $fields
     * @return array
     */
    public function prepareOnCoreSubjectForREDCapPull($OnCoreSubject, $fields)
    {
        $data = [];
        foreach ($fields as $key => $field) {
            $onCoreValue = $OnCoreSubject[$key];
            if (!isset($field['value_mapping'])) {
                $data[$field['event']][$field['redcap_field']] = $onCoreValue;
            } else {
                $onCoreType = $this->getMapping()->getOncoreType($key);
                if ($onCoreType == 'array') {
                    if (is_array($onCoreValue)) {
                        $parsed = $onCoreValue;
                    } else {
                        $parsed = json_decode($onCoreValue, true);
                    }
                } else {
                    $parsed = null;
                }
                if (is_array($parsed)) {
                    foreach ($parsed as $item) {
                        $map = $this->getMapping()->getOnCoreMappedValue($item, $field);
                        if ($field['field_type'] == 'checkbox') {
                            $data[$field['event']][$field['redcap_field'] . '___' . $map['rc']] = true;
                        } else {
                            $data[$field['event']][$field['redcap_field']] = $map['rc'];
                        }
                    }
                } else {
                    $map = $this->getMapping()->getOnCoreMappedValue($onCoreValue, $field);
                    $data[$field['event']][$field['redcap_field']] = $map['rc'];
                }
            }
        }
        return $data;
    }

    /**
     * @param $projectId
     * @param $protocolId
     * @param array $records will be array(array('oncore' => [ONCORE-PROTOCOL-SUBJECT-ID), 'redcap' =>[REDCAP-ID or can
     *     be empty])
     * @param $fields
     * @return bool
     * @throws Exception
     */
    public function pullOnCoreRecordsIntoREDCap($projectId, $protocolId, $record, $fields)
    {
        //foreach ($records as $record) {
        if (!is_array($record)) {
            throw new \Exception('Records array is not correct');
        }
        if (!isset($record['oncore'])) {
            throw new \Exception('No OnCore Protocol Subject is passed');
        }
        $subject = $this->getOnCoreProtocolSubject($protocolId, $record['oncore']);
        if (empty($subject)) {
                throw new \Exception('No Subject record found for ' . $record['oncore']);
            }
            $id = $record['redcap'];
            $data = $this->prepareOnCoreSubjectForREDCapPull($subject['demographics'], $fields);
            // loop over every event defined in the field mapping.
            foreach ($data as $event => $array) {
                if (!$id) {
                    $array[\REDCap::getRecordIdField()] = \REDCap::reserveNewRecordId($projectId);
                } else {
                    $array[\REDCap::getRecordIdField()] = $id;
                }
                $array['redcap_event_name'] = $event;
                // TODO uncheck current checkboxes.
                $response = \REDCap::saveData($projectId, 'json', json_encode(array($array)), 'overwrite');
                if (!empty($response['errors'])) {
                    if (is_array($response['errors'])) {
                        throw new \Exception(implode(",", $response['errors']));
                    } else {
                        throw new \Exception($response['errors']);
                    }
                } else {
                    $id = end($response['ids']);
                    Entities::createLog('OnCore Subject ' . $record['oncore'] . ' got pull into REDCap record ' . $id);
                }
            }
            unset($data);
        //}
        return $id;
    }

    /**
     * @return bool
     */
    public function isCanPush(): bool
    {
        return $this->canPush;
    }

    /**
     * @param bool $canPush
     */
    public function setCanPush(bool $canPush): void
    {
        $this->canPush = $canPush;
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
