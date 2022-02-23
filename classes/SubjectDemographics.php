<?php

namespace Stanford\OnCoreIntegration;

require_once 'Subjects.php';

/**
 * This class can be used as a helper class when trying to verify if the SubjectDemographics values
 * are valid to send to OnCore.  This class performs checking for each field based on the format
 * information that OnCore will accept.
 *
 */
class SubjectDemographics extends Entities
{

    private $subjectDemographicsid;
    private $subjectSource;
    private $mrn;
    private $lastName;
    private $firstName;
    private $middleName;
    private $suffix;
    private $birthDate;
    private $approximateBirthDate;
    private $birthDateNotAvailable;
    private $expiredDate;
    private $approximateExpiredDate;
    private $lastDateKnownAlive;
    private $ssn;
    private $gender;
    private $ethnicity;
    private $race;
    private $subjectComments;
    private $additionalSubjectids = array();
    private $streetAddress;
    private $addressLine2;
    private $city;
    private $state;
    private $zip;
    private $county;
    private $country;
    private $phoneNo;
    private $alternatePhoneNo;
    private $email;
    private $mrnValid;
    private $validateFields = true;

    public function __construct($validateFields)
    {
        $this->$validateFields = $validateFields;
    }

    public function getOnCoreSubjectDemographicsEntityRecord($subjectDemographicsId)
    {
        if ($subjectDemographicsId == '') {
            throw new \Exception('REDCap subject demographics ID can not be null');
        }
        $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_SUBJECTS . " where  subjectDemographicsId = '" . $subjectDemographicsId . "' ");
        if ($record->num_rows == 0) {
            return [];
        } else {
            return db_fetch_assoc($record);
        }
    }

    /**
     * @param $mrn
     * @param $subjectSource
     * @return array|mixed|void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getOnCoreSubjectDemographics($subjectDemographicsId)
    {
        try {
            // check if entity table already has that subject otherwise go to API to get info
            $demo = $this->getOnCoreSubjectDemographicsEntityRecord($subjectDemographicsId);
            if (empty($demo)) {
                $jwt = $this->getUser()->getAccessToken();
                $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'subjectDemographics/' . $subjectDemographicsId, [
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
                        // create entity table record before return.
                        $this->create(OnCoreIntegration::ONCORE_SUBJECTS, $data);
                        return $data;
                    }
                }
            } else {
                return $demo;
            }
        } catch (\Exception $e) {
            Entities::createLog($e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * @param $mrn
     * @param $subjectSource
     * @return array|mixed|void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function searchOnCoreSubjectViaMRN($mrn, $subjectSource = 'OnCore')
    {
        try {
            $jwt = $this->getUser()->getAccessToken();
            $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'subjectDemographics?mrn=' . $mrn . '&subjectSource=' . $subjectSource, [
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
                    return $data[0];
                }
            }
        } catch (\Exception $e) {
            Entities::createLog($e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    /*
     * Setter functions
     */
    function setSubjectDemographicsid($subjectDemographicsid)
    {
        if ($this->validateFields) {
            $this->subjectDemographicsid = (is_integer($subjectDemographicsid) ? $subjectDemographicsid : null);
        } else {
            $this->subjectDemographicsid = $subjectDemographicsid;
        }
    }

    function setSubjectSource($subjectSource)
    {
        if ($this->validateFields) {
            $this->subjectSource = ((($subjectSource == "OnCore") or ($subjectSource == "Onstage")) ? $subjectSource : null);
        } else {
            $this->subjectSource = $subjectSource;
        }
    }

    public function setMrn($mrn)
    {
        if ($this->validateFields) {
            $this->mrn = (strlen($mrn) <= 40 ? $mrn : null);
        } else {
            $this->mrn = $mrn;
        }
    }

    function setLastName($lastName)
    {
        if ($this->validateFields) {
            $this->lastName = (strlen($lastName) <= 80 ? $lastName : null);
        } else {
            $this->lastName = $lastName;
        }
    }

    function setFirstName($firstName)
    {
        if ($this->validateFields) {
            $this->firstName = (strlen($firstName) <= 55 ? $firstName : null);
        } else {
            $this->firstName = $firstName;
        }
    }

    function setMiddleName($middleName)
    {
        if ($this->validateFields) {
            $this->middleName = (strlen($middleName) <= 65 ? $middleName : null);
        } else {
            $this->middleName = $middleName;
        }
    }

    function setSuffix($suffix)
    {
        if ($this->validateFields) {
            $this->suffix = (strlen($suffix) <= 20 ? $suffix : null);
        } else {
            $this->suffix = $suffix;
        }
    }

    function setBirthDate($birthDate)
    {
        if ($this->validateFields) {
            $date = date('Y-m-d', strtotime($birthDate));
            $this->birthDate = ($date <> false ? $date : null);
        } else {
            $this->birthDate = $birthDate;
        }
    }

    function setApproximateBirthDate($approximateBirthDate)
    {
        if ($this->validateFields) {
            $this->approximateBirthDate = (is_bool($approximateBirthDate) ? $approximateBirthDate : null);
        } else {
            $this->approximateBirthDate = $approximateBirthDate;
        }
    }

    function setBirthDateNotAvailable($birthDateNotAvailable)
    {
        if ($this->validateFields) {
            $this->birthDateNotAvailable = (is_bool($birthDateNotAvailable) ? $birthDateNotAvailable : null);
        } else {
            $this->birthDateNotAvailable = $birthDateNotAvailable;
        }
    }

    function setExpiredDate($expiredDate)
    {
        if ($this->validateFields) {
            $date = date('Y-m-d', strtotime($expiredDate));
            $this->expiredDate = ($date <> false ? $date : null);
        } else {
            $this->expiredDate = $expiredDate;
        }
    }

    function setApproximateExpiredDate($approximateExpiredDate)
    {
        if ($this->validateFields) {
            $this->approximateExpiredDate = (is_bool($approximateExpiredDate) ? $approximateExpiredDate : null);
        } else {
            $this->approximateExpiredDate = $approximateExpiredDate;
        }
    }

    function setLastDateKnownAlive($lastDateKnownAlive)
    {
        if ($this->validateFields) {
            $date = date('Y-m-d', strtotime($lastDateKnownAlive));
            $this->lastDateKnownAlive = ($date <> false ? $date : null);
        } else {
            $this->lastDateKnownAlive = $lastDateKnownAlive;
        }
    }

    function setSsn($ssn)
    {
        if ($this->validateFields) {
            // regex to make sure it is either in xxx-xx-xxxx or xxxxxxxxx format
            $this->ssn = ((strlen($ssn) == 11 or strlen($ssn) == 9) ? $ssn : null);
        } else {
            $this->ssn = $ssn;
        }
    }

    function setGender($gender)
    {
        if ($this->validateFields) {
            $allowed_genders = array('Male', 'Female', 'Unknown');
            $valid = in_array($gender, $allowed_genders);
            $this->gender = ($valid ? $gender : null);
        } else {
            $this->gender = $gender;
        }
    }

    function setEthnicity($ethnicity)
    {
        if ($this->validateFields) {
            // TODO: Need Ethnicity List
            $allowed_ethnicity = array('Unknown', 'Non-Hispanic', 'Hispanic');
            $valid = in_array($ethnicity, $allowed_ethnicity);
            $this->ethnicity = ($valid ? $ethnicity : null);
        } else {
            $this->ethnicity = $ethnicity;
        }
    }

    function setRace($race)
    {
        if ($this->validateFields) {
            // TODO: Need Race List
            $allowed_race = array('Unknown');
            $valid = in_array($race, $allowed_race);
            $this->race = ($valid ? $race : null);
        } else {
            $this->race = $race;
        }
    }

    function setSubjectComments($subjectComments)
    {
        if ($this->validateFields) {
            $this->subjectComments = (strlen($subjectComments) <= 4000 ? $subjectComments : null);
        } else {
            $this->subjectComments = $subjectComments;
        }
    }

    function setAdditionalSubjectids($additionalSubjectids)
    {
        if ($this->validateFields) {
            foreach ($additionalSubjectids as $subjectIds) {
                $this->$additionalSubjectids[] = (is_integer($subjectIds) ? $subjectIds : null);
            }
        } else {
            $this->$additionalSubjectids = $additionalSubjectids;
        }
    }

    function setStreetAddress($streetAddress)
    {
        if ($this->validateFields) {
            $this->streetAddress = (strlen($streetAddress) <= 100 ? $streetAddress : null);
        } else {
            $this->streetAddress = $streetAddress;
        }
    }

    function setAddressLine2($addressLine2)
    {
        if ($this->validateFields) {
            $this->addressLine2 = (strlen($addressLine2) <= 100 ? $addressLine2 : null);
        } else {
            $this->addressLine2 = $addressLine2;
        }
    }

    function setCity($city)
    {
        if ($this->validateFields) {
            $this->city = (strlen($city) <= 20 ? $city : null);
        } else {
            $this->city = $city;
        }
    }

    function setState($state)
    {
        if ($this->validateFields) {
            // TODO: Need allowed states
            $allowed_states = array();
            $this->state = in_array($state, $allowed_states);
        } else {
            $this->state = $state;
        }
    }

    function setZip($zip)
    {
        if ($this->validateFields) {
            $this->zip = (strlen($zip) <= 10 ? $zip : null);
        } else {
            $this->zip = $zip;
        }
    }

    function setCounty($county)
    {
        if ($this->validateFields) {
            // TODO: Need allowed counties
            $allowed_county = array();
            $this->county = in_array($county, $allowed_county);
        } else {
            $this->county = $county;
        }
    }

    function setCountry($country)
    {
        if ($this->validateFields) {
            // TODO: Need allowed countries
            $allowed_country = array();
            $this->country = in_array($country, $allowed_country);
        } else {
            $this->country = $country;
        }
    }

    function setPhoneNo($phoneNo)
    {
        if ($this->validateFields) {
            $this->phoneNo = (strlen($phoneNo) <= 20 ? $phoneNo : null);
        } else {
            $this->phoneNo = $phoneNo;
        }
    }

    function setAlternatePhoneNo($alternatePhoneNo)
    {
        if ($this->validateFields) {
            $this->alternatePhoneNo = (strlen($alternatePhoneNo) <= 20 ? $alternatePhoneNo : null);
        } else {
            $this->alternatePhoneNo = $alternatePhoneNo;
        }
    }

    function setEmail($email)
    {
        if ($this->validateFields) {
            // Make sure it is in the correct email format
            if (strlen($email) <= 55) {
                $this->email = (filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null);
            }
        } else {
            $this->email = $email;
        }
    }

    public function setMRNValid($valid)
    {
        $this->mrnValid = ($valid ? 'true' : 'false');
    }

    /*
    * Getter functions
    */
    function getSubjectDemographicsid()
    {
        return $this->subjectDemographicsid;
    }

    function getSubjectSource()
    {
        return $this->subjectSource;
    }

    public function getMrn()
    {
        return $this->mrn;
    }

    function getLastName()
    {
        return $this->lastName;
    }

    function getFirstName()
    {
        return $this->firstName;
    }

    function getMiddleName()
    {
        return $this->middleName;
    }

    function getSuffix($suffix)
    {
        return $this->suffix;
    }

    function getBirthDate()
    {
        return $this->birthDate;
    }

    function getApproximateBirthDate()
    {
        return $this->approximateBirthDate;
    }

    function getBirthDateNotAvailable()
    {
        return $this->birthDateNotAvailable;
    }

    function getExpiredDate()
    {
        return $this->expiredDate;
    }

    function getApproximateExpiredDate()
    {
        return $this->approximateExpiredDate;
    }

    function getLastDateKnownAlive()
    {
        return $this->lastDateKnownAlive;
    }

    function getSsn()
    {
        return $this->ssn;
    }

    function getGender()
    {
        return $this->gender;
    }

    function getEthnicity()
    {
        return $this->ethnicity;
    }

    function getRace()
    {
        return $this->race;
    }

    function getSubjectComments()
    {
        return $this->subjectComments;
    }

    function getAdditionalSubjectids()
    {
        return $this->additionalSubjectids;
    }

    function getStreetAddress()
    {
        return $this->streetAddress;
    }

    function getAddressLine2()
    {
        return $this->addressLine2;
    }

    function getCity()
    {
        return $this->city;
    }

    function getState()
    {
        return $this->state;
    }

    function getZip()
    {
        return $this->zip;
    }

    function getCounty()
    {
        return $this->county;
    }

    function getCountry()
    {
        return $this->country;
    }

    public function getPhoneNo()
    {
        return $this->phoneNo;
    }

    function getAlternatePhoneNo()
    {
        return $this->alternatePhoneNo;
    }

    function getEmail()
    {
        return $this->email;
    }

    public function isMrnValid()
    {
        return $this->mrnValid;
    }

    /*
     * Generic functions to pull out stored data.
     */
    public function isValidForOnCore()
    {

        // All these fields are required
        if ($this->mrnValid and !empty($this->mrn) and !empty($this->lastName) and !empty($this->firstName) and
            !empty($this->gender) and !empty($this->ethnicity) and !empty($this->race)) {

            // If the birthdate is not present, then the birthDateNotAvailable flag must be sent
            if (empty($this->birthDate) and $this->birthDateNotAvailable <> true) {
                return false;
            }

            // if the birthdate is not present, then the approximateBirthDate flag must be false
            if (empty($this->birthDate) and $this->approximateBirthDate <> false) {
                return false;
            }

            // If the expiredDate is not present, then the approximateExpiredDate flag must be false
            if (empty($this->expiredDate) and $this->approximateExpiredDate <> false) {
                return false;
            }

            return true;

        } else {
            return false;
        }
    }

    private function getResults()
    {
        $demographics = get_object_vars($this);
        unset($demographics["validateFields"]);
        unset($demographics["1"]);
        return $demographics;
    }

    public function getJson()
    {
        $demographics = $this->getResults();
        return json_encode($demographics);
    }

    public function getFilteredJson()
    {
        $demographics = $this->getResults();
        return json_encode(array_filter($demographics));
    }

    public function getArray()
    {
        $demographics = $this->getResults();
        return $demographics;
    }

    public function getFilteredArray()
    {
        $demographics = $this->getResults();
        return array_filter($demographics);
    }

}
