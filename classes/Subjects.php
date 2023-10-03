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


    /**
     * @var array
     */
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

    public $forceDemographicsPull = false;

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
                $resp = $this->getUser()->getGuzzleClient()->request('GET', $url, [
                    GuzzleHttp\RequestOptions::SYNCHRONOUS => true
                ]);
            } catch (Exception $ex) {

//                $this->emError("Exception calling endpoint: " . $ex);
                Entities::createException("Exception calling endpoint: " . $ex);
            }

            // Make the API call was successful.
            $returnStatus = $resp->getStatusCode();
            if ($returnStatus <> 200) {
//                $this->emError("API call to $url, HTTP Return Code is: $returnStatus");
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
            $onCoreValue = isset($onCoreSubject['demographics'][$key]) ? $onCoreSubject['demographics'][$key] : $onCoreSubject[$key];
            if (isset($field['value_mapping'])) {
                // oncore is array of text ie. race
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
                    // if redcap field is checkbox
                    if ($field['field_type'] == 'checkbox') {
                        // get array of 0/1 from redcap for checkboxes
                        $temp = $redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];
                        $rc = [];
                        foreach ($temp as $index => $item) {
                            if ($item) {
                                $rc[$index] = $item;
                            }
                        }
                        // if  number of fields from oncore and redcap are not equal then it partial match.
                        if (count($rc) != count($parsed)) {
                            return OnCoreIntegration::PARTIAL_MATCH;
                        }

                        foreach ($parsed as $index => $item) {
                            $map = $this->getMapping()->getOnCoreMappedValue($item, $field);
                            // if the oncore value mapped to redcap checkbox is not checked then partial match
                            if (!$rc[$map['rc']]) {
                                return OnCoreIntegration::PARTIAL_MATCH;
                            }
                        }
                    } else {
                        // if redcap is text or radio then every race must match the redcap value
                        foreach ($onCoreValue as $item) {
                            $map = $this->getMapping()->getOnCoreMappedValue($item, $field);
                            // if the oncore value mapped redcap checkbox is not check then partial match
                            if ($redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']] != $map['rc']) {
                                return OnCoreIntegration::PARTIAL_MATCH;
                            }
                        }
                    }
                } else {
                    $map = $this->getMapping()->getOnCoreMappedValue($onCoreValue, $field);
                    if ($redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']] != $map['rc']) {
                        return OnCoreIntegration::PARTIAL_MATCH;
                    }
                    // no map defined
                    if (empty($map)) {
                        Entities::createLog("$field: Cant Find mapping for $onCoreValue");
                        return OnCoreIntegration::PARTIAL_MATCH;
                    }
                }
            } else {
                if ($onCoreValue != $redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']]) {
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
                    $record[\REDCap::getRecordIdField()] = $id;
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
    public function getREDCapRecordIdViaMRN($mrn, $redcapEventId, $redcapMRNField, $protocolSubjectIdField = null, $protocolSubjectId = null)
    {
        $result = [];
        if ($this->getRedcapProjectRecords()) {
            foreach ($this->getRedcapProjectRecords() as $id => $record) {
                if ($record[$redcapEventId][$redcapMRNField] == $mrn) {
                    if ($protocolSubjectIdField && $protocolSubjectId) {

                        // if protocol allows duplicate MRN then try to match MRN AND protocolSubjectId to get the right REDCap record. OR for new REDCap records that do not have protocolSubjectId YET. use the first record that match the MRN but does not have protocolSubjectId
                        if ($record[$redcapEventId][$protocolSubjectIdField] == $protocolSubjectId || $record[$redcapEventId][$protocolSubjectIdField] == '' || !$record[$redcapEventId][$protocolSubjectIdField]) {
                            $result[] = array('id' => $id, 'record' => $record);
                        }
                    } else {
                        $result[] = array('id' => $id, 'record' => $record);
                    }

                }
            }
        }
        return $result;
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
                                // pull protocol subject demographics. If subject demographics saved in oncore_subjects entity table it will be pulled from there. otherwise will be pulled via API then saved in entity table.
                                $subjects[$key]['demographics'] = $this->getOnCoreSubjectDemographics($subject['subjectDemographicsId'], $this->forceDemographicsPull);
                                // make it easy to prepare for push/pull
                                $subjects[$key]['demographics']['studySites'] = $subject['studySite'];
                            } catch (\Exception $e) {
                                Entities::createException($e->getMessage());
                            }
                        }
                        $this->onCoreProtocolSubjects = $subjects;
                    }
                } else {
                    throw new \Exception('Cant pull Protocol data');
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
     * @param $pid
     * @param $recordId
     * @return string
     */
    public function getREDCapRecordURL($pid, $recordId)
    {
        return APP_PATH_WEBROOT_FULL . ltrim(APP_PATH_WEBROOT, '/') . "DataEntry/record_home.php?pid=$pid&id=$recordId";
    }

    /**
     * @param $subjectId
     * @return string
     */
    public function getOnCoreSubjectURL($subjectId)
    {
        return $this->getUser()->getApiURL() . "smrs/SubjectStudyDataControlServlet?hdn_function=SUBJECT_INQUIRY&hdn_function_type=VIEW_ALL&protocol_subject_id=$subjectId&console=SUBJECT-CONSOLE";
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
    public function setRedcapProjectRecords($redcapProjectId, $useFilter = false): void
    {
        $fields = $this->getMapping()->getAllMappedREDCapFields();

        $param = array(
            'project_id' => $redcapProjectId,
            'fields' => $fields,
            'return_format' => 'array',
            'filterLogic' => $useFilter ? ($this->getMapping()->getOncoreConsentFilterLogic() ?: '') : '',
        );
        $this->redcapProjectRecords = \REDCap::getData($param);
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
            //            $sql = "SELECT * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE . " WHERE redcap_record_id = $redcapRecordId and oncore_protocol_subject_id = $onCoreProtocolSubjectId and redcap_project_id = $redcapProjectId AND oncore_protocol_id = $onCoreProtocolId";
            $sql = sprintf("SELECT * from %s WHERE redcap_record_id = '%s' and oncore_protocol_subject_id = %s and redcap_project_id = %s AND oncore_protocol_id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE), db_escape($redcapRecordId), db_escape($onCoreProtocolSubjectId), db_escape($redcapProjectId), db_escape($onCoreProtocolId));
        } elseif ($redcapRecordId) {
            //$sql = "SELECT * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE . " WHERE redcap_record_id = $redcapRecordId and redcap_project_id = $redcapProjectId AND oncore_protocol_id = $onCoreProtocolId";
            $sql = sprintf("SELECT * from %s WHERE redcap_record_id = '%s'  and redcap_project_id = %s AND oncore_protocol_id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE), db_escape($redcapRecordId), db_escape($redcapProjectId), db_escape($onCoreProtocolId));

        } elseif ($onCoreProtocolSubjectId) {
            //$sql = "SELECT * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE . " WHERE oncore_protocol_subject_id = $onCoreProtocolSubjectId and redcap_project_id = $redcapProjectId AND oncore_protocol_id = $onCoreProtocolId";
            $sql = sprintf("SELECT * from %s WHERE  oncore_protocol_subject_id = %s and redcap_project_id = %s AND oncore_protocol_id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE), db_escape($onCoreProtocolSubjectId), db_escape($redcapProjectId), db_escape($onCoreProtocolId));

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
        #$entity = (new Entities)->getFactory()->getInstance(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $id);
        $entity = (new Entities)->update(OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE, $id, $data);
        if (!$entity) {
            throw new \Exception(implode(',', $entity->errors));
        }
    }

    /**
     * update oncore_protocol entity record timestampts
     * @param $entityId
     * @param $status
     * @return void
     */
    public function updateLinkageEntityStatus($entityId, $status): void
    {
        $sql = sprintf("UPDATE %s set `status` = '%s' WHERE id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE), db_escape($status), db_escape($entityId));
        db_query($sql);
    }


    /**
     * update oncore_protocol entity record timestampts
     * @param $entityId
     * @param $status
     * @return void
     */
    public function updateLinkageREDCapRecordId($entityId, $recordId): void
    {
        $sql = sprintf("UPDATE %s set `redcap_record_id` = '%s' WHERE id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE), db_escape($recordId), db_escape($entityId));
        db_query($sql);
    }

    /**
     * @return array
     */
    public function getSyncedRecords(): array
    {
        return $this->syncedRecords;
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
            // $sql = "SELECT * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE . " WHERE redcap_project_id = $redcapProjectId AND oncore_protocol_id = $onCoreProtocolId";
            $sql = sprintf("SELECT * from %s WHERE  redcap_project_id = %s AND oncore_protocol_id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE), db_escape($redcapProjectId), db_escape($onCoreProtocolId));

        } else {
            throw new \Exception("No REDCap pid and OnCore Protocol Id provided");
        }
        $result = array();
        $q = db_query($sql);
        $fields = $this->getMapping()->getProjectMapping()['push'];
        if (db_num_rows($q) > 0) {
            while ($row = db_fetch_assoc($q)) {
                $record = array();
                // if redcap logic filter is defined make sure redcap_record_id satisfies the logic.
                if ($row['redcap_record_id'] && $record = $this->getREDCapRecord($row['redcap_record_id'])) {

                    $redcapMRN = $record[OnCoreIntegration::getEventNameUniqueId($fields['mrn']['event'])][$fields['mrn']['redcap_field']];
                    // if redcap record has no MRN ignore it
                    if (!$redcapMRN) {
                        continue;
                    }
                    $record['redcap'] = $this->getREDCapRecord($row['redcap_record_id']);
                } elseif ($row['redcap_record_id']) {
                    // if record exists in linkage entity table but in redcap records array that means record did not satisfy filter logic. add empty array for it.
                    $record['redcap'] = array();
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
     * create record in oncore_redcap_records_linkage entity table.
     * @param string $protocolId
     * @param string $studySite
     * @param int $subjectDemographicsId
     * @param array $subjectDemographics
     * @return array
     * @throws Exception
     */
    public function createOnCoreProtocolSubject($protocolId, $studySite, $subjectDemographicsId = null, $subjectDemographics = null): array
    {
        $errors = [];
        if (!$protocolId) {
//            throw new \Exception('Protocol is missing');
            $errors[] = 'Protocol is missing';
        }

        if (!$studySite) {
//            throw new \Exception('Study site is missing');
            $errors[] = 'Study site is missing';
        }
        if (!$subjectDemographicsId && !$subjectDemographics) {
//            throw new \Exception('You must have either Subject demographic ID or Subject Demographics Object');
            $errors[] = 'You must have either Subject demographic ID or Subject Demographics Object';
        }

        if (!$this->isCanPush()) {
//            throw new \Exception('You cant push REDCap records to OnCore Protocol. Because Protocol is not approved or its status is not valid.');
            $errors[] = 'You cant push REDCap records to OnCore Protocol. Because Protocol is not approved or its status is not valid.';
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
//                throw new \Exception("Following field/s are missing: " . implode(',', $diff));
                // special case for birthdate field. if no value provided for DOB and birthDateNotAvailable is set to true
                if ($this->getMapping()->compareIsEqualArray($diff, array(OnCoreIntegration::ONCORE_BIRTHDATE_FIELD))) {
                    if (!$this->getMapping()->excludeBirthDate($keys)) {
                        $errors[] = "Following field/s are not filled: " . implode(', ', $diff);
                    }
                } else {
                    $errors[] = "Following field/s are not filled: " . implode(', ', $diff);
                }
            }

            /**
             * make sure all required fields have values
             */
            $e = [];
            //make sure to check the default OnCore required fields and other non-required by default but are set as required in the EM system settings
            $fields = array_unique(array_merge($this->getMapping()->getOncoreRequiredFields(), OnCoreIntegration::$ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS));
            // if birthdate is not provided and not required make sure to exclude it from empty values.
            if ($this->getMapping()->excludeBirthDate($keys)) {
                if (($key = array_search(OnCoreIntegration::ONCORE_BIRTHDATE_FIELD, $fields)) !== false) {
                    unset($fields[$key]);
                }

            }
            foreach ($fields as $field) {
                if ($subjectDemographics[$field] == '' || (is_string($subjectDemographics[$field]) && trim($subjectDemographics[$field]) == '')) {
                    $e[] = $field;
                }
            }

            if (!is_array($subjectDemographics['race'])) {
                $errors[] = 'Race field is not formatted correctly.';
            }

            if (!empty($e)) {
//                throw new \Exception("Following field/s are missing values: " . implode(',', $errors));
                $errors[] = "Following field/s are empty: " . implode(', ', $e);
            }
        }

        if (!empty($errors)) {
            throw new \Exception(implode("\n", $errors));
        }

        // when creating new subject. the EM must pass subjectSource OnCore
        if (!$subjectDemographicsId && !empty($subjectDemographics)) {
            $subjectDemographics = $this->addSubjectSource($subjectDemographics);
            $subjectDemographics['studySites'] = $studySite;
        }

        $response = $this->getUser()->post('protocolSubjects', ['protocolId' => $protocolId, 'studySite' => $studySite, 'subjectDemographics' => $subjectDemographics, 'subjectDemographicsId' => $subjectDemographicsId]);


        if ($response->getStatusCode() == 201) {
            $location = $response->getHeader('location');
            $parts = explode('/', end($location));
            $id = end($parts);
            return array('status' => 'success', 'protocol_subject_id' => $id);
        } else {
            $data = json_decode($response->getBody(), true);
            return $data;
        }
    }

    /**
     * hardcode subjectSource because all subject source MUST be OnCore
     * @param $subjectDemographics
     * @return mixed
     */
    private function addSubjectSource($subjectDemographics)
    {
        $subjectDemographics['subjectSource'] = OnCoreIntegration::ONCORE_SUBJECT_SOURCE_TYPE_ONCORE;
        return $subjectDemographics;
    }

    /**
     * Pull OnCore Protocol subjects and search via MRN
     * @param $protocolId
     * @param $redcapMRN
     * @return mixed|void
     * @throws Exception
     */
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

    /**
     * get the mapped OnCore value for REDCap value.
     * @param $redcapRecord
     * @param $field
     * @return mixed
     */
    private function getOnCoreStudySite($redcapRecord, $field, $oncoreFieldsDef)
    {
        $key = 'studySites';
        $studySite = $redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];
        if ($this->getMapping()->canUseDefaultValue($key, $oncoreFieldsDef[$key]['allow_default'], $field['default_value']) && empty($studySite)) {
            return $field['default_value'];
        } else {
            $map = $this->getMapping()->getREDCapMappedValue($studySite, $field);
            return $map['oc'];
        }
    }

    /**
     * push redcap record into OnCore
     * @param $protocolId
     * @param $redcapId
     * @param $fields
     * @param $oncoreFieldsDef
     * @return string[]
     * @throws GuzzleException
     */
    public function pushToOnCore($protocolId, $redcapId, $fields, $oncoreFieldsDef)
    {
        if (!$this->getUser()->isOnCoreContactAllowedToPush()) {
            throw new \Exception('You do not have permissions to push data from this protocol.');
        }

        $record = $this->getRedcapProjectRecords()[$redcapId];
        $redcapMRN = $record[OnCoreIntegration::getEventNameUniqueId($fields['mrn']['event'])][$fields['mrn']['redcap_field']];
        $studySite = $this->getOnCoreStudySite($record, $fields['studySites'], $oncoreFieldsDef);
        $onCoreRecord = $this->searchOnCoreSubjectUsingMRN($redcapMRN);
        if (empty($onCoreRecord)) {
            $demographics = $this->prepareREDCapRecordForSync($redcapId, $fields, $oncoreFieldsDef);
            $message = "No subject found for MRN $redcapMRN. Using REDCap data to create new Subject.";
            Entities::createLog($message, Entities::PUSH_TO_ONCORE_FROM_ONCORE);
            $result = $this->createOnCoreProtocolSubject($protocolId, $studySite, null, $demographics);
            $result['message'] = $message;
            return $result;
        } else {
            // if subject is in different protocol then just add subject to protocol
            if ($onCoreRecord['subjectSource'] == OnCoreIntegration::ONCORE_SUBJECT_SOURCE_TYPE_ONCORE) {
                $message = "OnCore Subject " . $onCoreRecord['subjectDemographicsId'] . " found for MRN $redcapMRN. REDCap data will be ignored and OnCore subject will be used.";
                Entities::createLog($message, Entities::PUSH_TO_ONCORE_FROM_ON_STAGE);
                $result = $this->createOnCoreProtocolSubject($protocolId, $studySite, $onCoreRecord['subjectDemographicsId'], null);
                $result['message'] = $message;
                return $result;
            }
            // if subject is in Onstage this mean not part of any protocol
            // Onstage data has priority except null
            elseif ($onCoreRecord['subjectSource'] == OnCoreIntegration::ONCORE_SUBJECT_SOURCE_TYPE_ONSTAGE) {
                // fill Onstage missing data from redcap record.
                $demographics = $this->fillMissingData($record, $onCoreRecord, $fields);
                $message = "No OnCore Subject found for MRN $redcapMRN but a Record found on OnStage table. REDCap data will be used ONLY for missing data from OnStage.";
                Entities::createLog($message, Entities::PUSH_TO_ONCORE_FROM_REDCAP);
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
                } else {
                    $map = $this->getMapping()->getREDCapMappedValue($redcapValue, $field);
                    if (!$map) {
                        //throw new \Exception('cant find map for redcap value ' . $redcapValue);
                        continue;
                    }
                    $onCoreRecord[$key] = array($map['oc']);
                }
            } elseif ($onCoreRecord[$key] == '' || is_null($onCoreRecord[$key])) {
                $onCoreRecord[$key] = $redcapRecord[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];
            }
        }
        return $onCoreRecord;
    }

    /**
     *  Using mapped fields prepare array of redcap data to be pushed to OnCore
     * @param $redcapId
     * @param $fields
     * @return array
     * @throws Exception
     */
    public function prepareREDCapRecordForSync($redcapId, $fields, $oncoreFieldsDef)
    {
        $record = $this->getRedcapProjectRecords()[$redcapId];
        $data = [];
        foreach ($fields as $key => $field) {
            unset($map);
            // check if default values are allowed for the field and a default value already defined on current redcap project.
            if ($this->getMapping()->canUseDefaultValue($key, $oncoreFieldsDef[$key]['allow_default'], $field['default_value']) && empty($field["redcap_field"])) {
                // special case only for birthdate to allow empty default value
                if ($key == OnCoreIntegration::ONCORE_BIRTHDATE_FIELD) {
                    $data[OnCoreIntegration::ONCORE_BIRTHDATE_NOT_REQUIRED_FIELD] = true;
                } else {
                    if (in_array('array', $oncoreFieldsDef[$key]['oncore_field_type'])) {
                        $data[$key] = array($field['default_value']);
                    } else {
                        $data[$key] = $field['default_value'];
                    }

                }
            } else {
                $redcapValue = $record[OnCoreIntegration::getEventNameUniqueId($field['event'])][$field['redcap_field']];


                if (!isset($field['value_mapping'])) {

                    $a = gettype($redcapValue);
                    if (!in_array($a, $oncoreFieldsDef[$key]['oncore_field_type'])) {
                        //throw new \Exception('datatype does not match');
                        continue;
                    }

                    // if no value and its not required then do not add it. because onCore API will consider some field required if key is presented.
                    if ($redcapValue == '' && !($oncoreFieldsDef[$key]['required'] == 'true' ? true : false)) {
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
//                            throw new \Exception('cant find map for redcap value ' . $redcapValue);
                                continue;
                            }
                            $data[$key] = array($map['oc']);
                        }
                    } else {
                        $map = $this->getMapping()->getREDCapMappedValue($redcapValue, $field);
                        if (!$map) {
//                        throw new \Exception('cant find map for redcap value ' . $redcapValue);
                            continue;
                        }
                        $data[$key] = $map['oc'];
                    }
                }
            }

        }
        return $data;
    }

    /**
     * Using mapped fields prepare array of oncore data to be pull into Redcap
     * @param $protocolId
     * @param $onCoreSubjectId
     * @param $fields
     * @return array
     */
    public function prepareOnCoreRecordForSync($OnCoreSubject, $fields)
    {
        $data = [];
        foreach ($fields as $key => $field) {
            $onCoreValue = isset($OnCoreSubject['demographics'][$key]) ? $OnCoreSubject['demographics'][$key] : $OnCoreSubject[$key];;
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
                    // for checkbox only mark other checkboxes to false.
                    if ($field['field_type'] == 'checkbox') {
                        // set other checkboxes to false.
                        foreach ($field['value_mapping'] as $item) {
                            // if checkboxes is check in oncore then skip
                            if (in_array($item['oc'], $parsed)) {
                                continue;
                            }else{
                                $map = $this->getMapping()->getOnCoreMappedValue($item, $field);
                                $data[$field['event']][$field['redcap_field'] . '___' . $item['rc']] = false;
                            }
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
     * pull OnCore record into REDCap
     * @param $projectId
     * @param $protocolId
     * @param array $records array('redcap' => [REDCAP_RECORD_ID] OR NULL , 'oncore' => [ONCORE_PROTOCOL_SUBJECT_ID])
     * @param $fields
     * @return bool
     * @throws Exception
     */
    public function pullOnCoreRecordsIntoREDCap($projectId, $protocolId, $record, $fields)
    {
        if (!$this->getUser()->isOnCoreContactAllowedToPush()) {
            throw new \Exception('You do not have permissions to pull data from this protocol.');
        }

        if (!is_array($record)) {
            throw new \Exception('Records array is not correct');
        }
        if (!isset($record['oncore'])) {
            throw new \Exception('No OnCore Protocol Subject is passed');
        }

        $linkage = $this->getLinkageRecord($projectId, $protocolId, '', $record['oncore']);
        // check if record is excluded.
        if ($linkage['excluded']) {
            throw new \Exception('This Record is excluded and you cant sync it.');
        }

        $subject = $this->getOnCoreProtocolSubject($protocolId, $record['oncore']);
        if (empty($subject)) {
            throw new \Exception('No Subject record found for ' . $record['oncore']);
        }


        $id = $record['redcap'];
        $data = $this->prepareOnCoreRecordForSync($subject, $fields);
        // loop over every event defined in the field mapping.
        if ($this->getSubjectsLock($projectId)) {
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
                    Entities::createLog('OnCore Subject ' . $record['oncore'] . ' was synced into REDCap record ' . $id, Entities::PULL_FROM_ONCORE);
                    $this->updateLinkageEntityStatus($linkage['id'], OnCoreIntegration::FULL_MATCH);
                    $this->updateLinkageREDCapRecordId($linkage['id'], $id);
                }
            }
            unset($data);
            $this->releaseSubjectsLock($projectId);
        }


        return $id;
    }

    /**
     * @param array $records
     * @return array
     */
    public function prepareSyncedRecordsSummaries($records)
    {
        $results = array();
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

    public function getSubjectsLock($projectId)
    {
        $sql = sprintf("SELECT GET_LOCK('%s', 3) as subject_lock", db_escape(OnCoreIntegration::SUBJECTS_MYSQL_LOCK . '_' . $projectId));
        $q = db_query($sql);
        $record = db_fetch_assoc($q);
        if (!$record['subject_lock']) {
            Entities::createLog('Subject lock is in place.');
            throw new \Exception('Another Action is currently running on this project. Please try again later!');
        }
        return $record['subject_lock'];
    }

    public function releaseSubjectsLock($projectId)
    {
        $sql = sprintf("SELECT RELEASE_LOCK('%s') as subject_lock", db_escape(OnCoreIntegration::SUBJECTS_MYSQL_LOCK . '_' . $projectId));
        $q = db_query($sql);
        $record = db_fetch_assoc($q);
        return $record['subject_lock'];
    }


}
