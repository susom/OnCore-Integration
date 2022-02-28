<?php
namespace Stanford\OnCoreIntegration;

use \Exception;
use \GuzzleHttp;

require_once 'classes/Entities.php';
require_once 'classes/SubjectDemographics.php';

class Subjects extends Entities
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
     * @return array
     */
    public function getEMRDemographics($mrns) {

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
            }

            // Make the API call was successful.
            $returnStatus = $resp->getStatusCode();
            if ($returnStatus <> 200) {
                $this->emError("API call to $url, HTTP Return Code is: $returnStatus");
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
