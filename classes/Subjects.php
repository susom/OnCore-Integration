<?php
namespace Stanford\OnCoreIntegration;

use \Exception;
use \GuzzleHttp;

require_once 'classes/Entities.php';
require_once 'classes/SubjectDemographics.php';

class Subjects extends Entities
{
    /** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

    /**
     * The top functions can be used to query and verify MRNs in the home institution's Electronic
     * Medical Record system.
     *
     * The bottom functions can be used to query OnCore to determine if an MRN is already entered
     * into the system.
     */

    /**
     * Retrieves demographics data from the home institutions EMR system. If MRN validation is not implemented,
     * the URL in the EM system configuration file will not be set and this function will never get called.
     *
     * @param $mrns - list of MRNs to retrieve demographics data
     * @param $url - URL called to retrieve the demographics
     * @param bool $getDemographics - if true, demographics will be retrieved.  If false, only true/false will be retrieved for each MRN for validation.
     * @return array
     */
    public function getEMRDemographics($mrns, $module, $getDemographics=true) {

        $demographics = array();

        // Retrieve the URL to the MRN Verification API.  If one is not entered, just set all MRNs as valid.
        $url = $module->getSystemSetting('mrn-verification-url');
        if (!empty($url)) {

            // Call API Endpoint
            try {

                // Put togther the URL with the info needed to process the rrequest
                $action = ($getDemographics ? 'demographics' : 'validate');
                $url .= "&mrns=" . implode(',', $mrns) . "&action=" . $action;


                // Make the API call
                $client = new GuzzleHttp\Client;
                $resp = $client->request('GET', $url, [
                    GuzzleHttp\RequestOptions::SYNCHRONOUS => true,
                    GuzzleHttp\RequestOptions::FORM_PARAMS => array("redcap_csrf_token" => $module->getCSRFToken())
                ]);
            } catch (Exception $ex) {

                // TODO: What to do about exceptions
                $module->emError("Exception calling endpoint: " . $ex);
            }

            // Make the API call was successful.
            $returnStatus = $resp->getStatusCode();
            if ($returnStatus <> 200) {
                $module->emError("API call to $url, HTTP Return Code is: $returnStatus");
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
    public function emrMrnsValid($mrns, $module) {

        return $this->getEMRDemographics($mrns, $module, false);

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

}
