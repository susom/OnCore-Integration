<?php

namespace Stanford\OnCoreIntegration;

use \Exception;
use \GuzzleHttp;

require_once 'classes/Entities.php';
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

    /** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

    /**
     * The top functions can be used to query and verify MRNs in the home institution's Electronic
     * Medical Record system.
     *
     * The bottom functions can be used to query OnCore to determine if an MRN is already entered
     * into the system.
     * @param Users $user
     * @param $reset
     */
    public function __construct($user, $reset = false)
    {
        parent::__construct($reset);

        $this->setUser($user);

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
        foreach ($fields as $field) {
            if ($onCoreSubject[$field] != $redcapRecord[$field]) {
                return OnCoreIntegration::PARTIAL_MATCH;
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
                    return array('id' => $id, 'record' => $record[$redcapEventId]);
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
    public function setOnCoreProtocolSubjects($protocolId): void
    {
//        $this->onCoreProtocolSubjects = $onCoreProtocolSubjects;
        try {
            if (!$protocolId) {
                throw new \Exception("No Protocol Provided");
            }

            $jwt = $this->getUser()->getAccessToken();
            $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'protocolSubjects?protocolId=' . $protocolId, [
                'debug' => false,
                'headers' => [
                    'Authorization' => "Bearer {$jwt}",
                ]
            ]);

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
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            throw new \Exception($e->getMessage());
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
    public function setRedcapProjectRecords($redcapProjectId, $redcapEventId): void
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
            throw new \Exception(implode(',', $this->errors));
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
}
