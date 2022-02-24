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
     * @param $url - URL called to retrieve the demographics
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
                $action = ($getDemographics ? 'demographics' : 'validate');
                $url .= "&mrns=" . implode(',', $mrns) . "&action=" . $action;


                // Make the API call
                $client = new GuzzleHttp\Client;
                $resp = $this->getUser()->getGuzzleClient()->request('GET', $url, [
                    GuzzleHttp\RequestOptions::SYNCHRONOUS => true,
                    GuzzleHttp\RequestOptions::FORM_PARAMS => array("redcap_csrf_token" => $this->getUser()->getRedcapCSFRToken())
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
            foreach($mrns as $mrn) {
                $subject[$mrn] = array("mrn" => $mrn,
                                 "mrnValid"  => "true"
                                );

            }
            $demographics = json_encode($subject);
        }

        return $demographics;
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
     * Function to retrieve all OnCore subjects demographics for the entered MRN List.
     *
     * @param $mrn
     * @return array
     */
    public function getOnCoreDemographics($mrns) {

        $subjectsDemo = array();
        if (!empty($mrns)) {
            try {
                $demo = new onCoreDemographics();
                $subjectsDemo = $demo->getOnCoreDemographics($mrns);
                if (empty($subjectsDemo)) {
                    $this->errorMsg .= "<br>Errors while retrieving OnCore subject demographics: " . $demo->getErrorMessages();
                }
            } catch (Exception $ex) {
                $this->errorMsg = "<br>Could not retrieve OnCore demographics - exception occurred: " . $ex->getMessage();
            }
        }

        return $subjectsDemo;
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
    public function getRedcapProjectRecords($redcapProjectId, $redcapEventId)
    {
        if (!$this->redcapProjectRecords) {
            $this->setRedcapProjectRecords($redcapProjectId, $redcapEventId);
        }
        return $this->redcapProjectRecords;
    }

    /**
     * @param mixed $redcapProjectRecords
     */
    public function setRedcapProjectRecords($redcapProjectId, $redcapEventId): void
    {
        $param = array(
            'project_id' => $redcapProjectId,
            'return_format' => 'array',
            'events' => $redcapEventId
        );
        $this->redcapProjectRecords = \REDCap::getData($param);
    }


}
