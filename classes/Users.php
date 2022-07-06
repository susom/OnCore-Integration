<?php

namespace Stanford\OnCoreIntegration;
use ExternalModules\User;
use GuzzleHttp\Exception\GuzzleException;

require_once 'Clients.php';

/**
 * Class Users
 * @package Stanford\OnCoreIntegration
 * @property array $onCoreAdmin
 * @property array $onCoreContact
 * @property User $redcapUser
 */
class Users extends Clients
{

    /**
     * @var array
     */
    private $onCoreContact = [];

    /**
     * @var User
     */
    private $redcapUser = null;

    /**
     * @var array
     */
    private $protocolStaff = [];

    /**
     * @var int
     */
    private $redcapEntityProtocolRecordId;

    /**
     * @param $prefix
     */
    public function __construct($prefix, $user, $redcapCSFRToken)
    {
        parent::__construct($prefix, $redcapCSFRToken);

        $this->setRedcapUser($user);
//
//        $this->setClientId($clientId);
//
//        $this->setClientSecret($clientSecret);
//
//        if ($this->getClientSecret() != '' && $this->getClientSecret() != '') {
//            $result = $this->generateToken($this->getClientId(), $this->getClientSecret());
//            $this->setAccessToken($result->access_token);
//            $this->setTokenTime($result->expires_in + time());
//        }
    }

    /**
     * @return bool
     */
    public function isOnCoreContactAllowedToPush()
    {
        if (empty($this->getRolesAllowedToPush()) || empty($this->getOnCoreContact())) {
            return false;
        }
        $result = in_array(strtolower($this->getOnCoreContact()['role']), $this->getRolesAllowedToPush());
        if (!$result) {
            Entities::createLog($this->getOnCoreContact()['additionalIdentifiers'][0]['id'] . ' does not have correct Role. Current role' . $this->getOnCoreContact()['role']);
        }
        return in_array(strtolower($this->getOnCoreContact()['role']), $this->getRolesAllowedToPush());
    }

    /**
     * Match REDCap user to a OnCore Protocol Staff (Contact).
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function prepareUser($redcapEntityProtocolRecordId, $protocolId)
    {
        $this->setProtocolStaff($protocolId);

        $this->setOnCoreContact($this->searchProtocolStaff($this->getRedcapUser()->getUsername()));

    }

    /**
     * Match REDCap username with Contact additionalIdentifier field.
     * @param $redcapUsername
     * @return array|mixed
     */
    public function searchProtocolStaff($redcapUsername)
    {
        foreach ($this->getProtocolStaff() as $staff) {
            if (!empty($staff['contact']['additionalIdentifiers'])) {
                foreach ($staff['contact']['additionalIdentifiers'] as $identifier) {
                    if ($redcapUsername == $identifier['id']) {
                        return $staff;
                    }
                }
            }
        }
        return [];
    }


    /**
     * @return array
     */
    public function getOnCoreContact(): array
    {
        return $this->onCoreContact;
    }

    /**
     * @param array $onCoreContact
     */
    public function setOnCoreContact(array $onCoreContact): void
    {
        $this->onCoreContact = $onCoreContact;
    }

    /**
     * @return User
     */
    public function getRedcapUser()
    {
        return $this->redcapUser;
    }

    /**
     * @param $redcapUser
     */
    public function setRedcapUser($redcapUser): void
    {
        $this->redcapUser = $redcapUser;
    }


    /**
     * If this is an onCoreAdmin, allow them to perform MRN Verification.
     *
     * @param string $username
     * @return string
     */
    public function getMrnValidationUrl(): string
    {
        if ($this->onCoreAdmin) {
            return $this->getMrnVerificationURL();
        } else {
            return '';
        }
    }

    /**
     * @return array
     */
    public function getProtocolStaff(): array
    {
        return $this->protocolStaff;
    }

    /**
     * @param int $protocolId
     */
    public function setProtocolStaff(int $protocolId): void
    {
        try {
            $response = $this->get('protocolStaff?protocolId=' . $protocolId);

            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody(), true);
                if (empty($data)) {
                    $this->protocolStaff = [];
                } else {
                    foreach ($data as $staff) {
                        $contact = $this->getContactDetails($staff['contactId']);
//                        if (isset($contact['errorType'])) {
//                            Entities::createLog($contact['message']);
//                            \REDCap::logEvent($contact['message']);
//                            // TODO send an email to redcap project admins notify them about this
//                        } else
                        if (empty($contact)) {
                            $message = 'System did not find demographic information for contact ID: ' . $staff['contactId'];
                            Entities::createLog($message);
                            \REDCap::logEvent($message);
                        } else {
                            $staff['contact'] = $contact;
                            $this->protocolStaff[] = $staff;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
        } catch (GuzzleException $e) {
            Entities::createException($e->getMessage());
        }
    }

    /**
     * @param $contactId
     * @return array|mixed|void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getContactDetails($contactId)
    {
        try {
            $response = $this->get('contacts/' . $contactId);

            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody(), true);
                if (empty($data)) {
                    return [];
                } else {
                    return $data;
                }
            }
        } catch (\Exception $e) {
            $data['errorType'] = 'exception';
            $data['message'] = $e->getMessage();
            return $data;
        }
    }

}
