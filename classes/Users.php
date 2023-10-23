<?php

namespace Stanford\OnCoreIntegration;

use ExternalModules\User;
use ExternalModules\ExternalModules;
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
     * @var int
     */
    public $redcapProjectId;
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

    private $onCoreSkippedStaff = [];

    /**
     * @param $prefix
     */
    public function __construct($redcapProjectId, $prefix, $user, $redcapCSFRToken)
    {
        parent::__construct($prefix, $redcapCSFRToken);

        $this->redcapProjectId = $redcapProjectId;
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
        // debug option for redcap super users.
        if (ExternalModules::getSystemSetting($this->getPrefix(), 'remove-super-users-roles-restriction')) {
            if (!is_null($this->getRedcapUser()) && $this->getRedcapUser()->isSuperUser()) {
                return true;
            }
        }

        // edge case for auto pull we need to skip this.
        if (defined("CRON")) {
            return true;
        }


        if (empty($this->getRolesAllowedToPush()) || empty($this->getOnCoreContact())) {
            return false;
        }

        /**
         * if contact has stopDate check if expired
         */
        if ($this->getOnCoreContact()['stopDate']) {
            $stopDate = strtotime($this->getOnCoreContact()['stopDate']);
            if (time() > $stopDate) {
                Entities::createLog($this->getOnCoreContact()['additionalIdentifiers'][0]['id'] . ' stop date ' . $this->getOnCoreContact()['stopDate'] . ' is expired. Permission denied.');
                return false;
            }
        }

        $result = in_array(strtolower($this->getOnCoreContact()['role']), $this->getRolesAllowedToPush());
        if (!$result) {
            Entities::createLog($this->getOnCoreContact()['additionalIdentifiers'][0]['id'] . ' does not have correct Role. Current role' . $this->getOnCoreContact()['role']);
            return false;
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

        $this->setOnCoreContact($this->searchProtocolStaff($this->getRedcapUser()->getUsername(), $this->getRedcapUser()->getEmail()));

    }

    /**
     * Match REDCap username with Contact additionalIdentifier field.
     * @param $redcapUsername
     * @return array|mixed
     */
    public function searchProtocolStaff($redcapUsername, $redcapUserEmail)
    {
        Entities::createLog(__LINE__ . ' --' . $redcapUsername);
        Entities::createLog(__LINE__ . ' --' . $redcapUserEmail);
        foreach ($this->getProtocolStaff() as $staff) {
            Entities::createLog(__LINE__ . ' --' . implode(',' , $staff));
            if (!empty($staff['contact']['additionalIdentifiers'])) {
                foreach ($staff['contact']['additionalIdentifiers'] as $identifier) {
                    Entities::createLog(__LINE__ . ' --' . implode(',' , $identifier));
                    if ($redcapUsername == $identifier['id']) {
//                        Entities::createLog('REDCap Username: ' . $redcapUsername);
//                        Entities::createLog(implode(',', $staff));
                        return $staff;
                    }
                }
            }
            Entities::createLog(__LINE__ . ' --' . implode(',' , $staff['contact']));
            if ($staff['contact']['email'] == $redcapUserEmail) {
                Entities::createLog("OnCore Contact found using $redcapUserEmail.");
                return $staff;
            }
        }
        Entities::createLog('EM could not find a Protocol Staff for ' . $redcapUsername . '. User must confirm "Staff ID" is configured for his/her OnCore Contact.  ');
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
                        if (in_array($staff['contactId'], $this->getOnCoreSkippedStaff())) {
                            continue;
                        }
                        $contact = $this->getContactDetails($staff['contactId'], $staff['role']);
//                        if (isset($contact['errorType'])) {
//                            Entities::createLog($contact['message']);
//                            \REDCap::logEvent($contact['message']);
//                            // TODO send an email to redcap project admins notify them about this
//                        } else
                        if (empty($contact)) {
                            $message = 'System did not find demographic information for contact ID: ' . $staff['contactId'];
                            Entities::createLog($message);
                            \REDCap::logEvent('OnCore API Error.', $message);
                        } elseif (isset($contact['errorType'])) {
//                            Entities::createLog($contact['message']);
//                            \REDCap::logEvent($contact['message']);
                            $this->setOnCoreSkippedStaff($staff['contactId']);
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

    public function getEntityContactRecord($contactId)
    {

        $sql = sprintf("SELECT * from %s WHERE oncore_contact_id = %s ", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_ADMINS), db_escape($contactId));
        $q = db_query($sql);
        if (db_num_rows($q) == 0) {
            return [];
        } else {
            $record = db_fetch_assoc($q);
            $contact = array(
                'email' => $record['oncore_email'],
                'role' => $record['oncore_role'],
                'stopDate' => $record['oncore_stop_date'],
                'additionalIdentifiers' => array(
                    array(
                        'id' => $record['oncore_additional_identifier'],
                        'idType' => 'Staff ID'
                    )
                ),
            );
            return $contact;
        }
    }

    /**
     * @param $contactId
     * @return array|mixed|void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getContactDetails($contactId, $oncoreRole)
    {
        try {
            $contact = $this->getEntityContactRecord($contactId);
            if (empty($contact)) {
                $response = $this->get('contacts/' . $contactId);

                if ($response->getStatusCode() < 300) {
                    $data = json_decode($response->getBody(), true);
                    if (empty($data)) {
                        return [];
                    } else {
                        $temp = array(
                            'oncore_contact_id' => $contactId,
                            'oncore_email' => $data['email'],
                            'oncore_additional_identifier' => isset($data['additionalIdentifiers']) ? $data['additionalIdentifiers'][0]['id'] : '',
                            'oncore_role' => $oncoreRole,
                            'oncore_stop_date' => $data['stopDate']
                        );
                        (new Entities())->create(OnCoreIntegration::ONCORE_ADMINS, $temp);
                        return $data;
                    }
                }
            }
            return $contact;

        } catch (\Exception $e) {
            $data['errorType'] = 'exception';
            $data['message'] = $e->getMessage();
            return $data;
        }
    }

    /**
     * @return array
     */
    public function getOnCoreSkippedStaff(): array
    {
        if (!$this->onCoreSkippedStaff) {
            $list = ExternalModules::getProjectSetting($this->getPREFIX(), $this->redcapProjectId, 'oncore-skipped-contacts');
            if ($list) {
                $temp = explode(',', $list);
                if ($temp) {
                    $this->onCoreSkippedStaff = $temp;
                }
            }
        }
        return $this->onCoreSkippedStaff;
    }

    /**
     * @param string $onCoreSkippedStaff
     */
    public function setOnCoreSkippedStaff(string $onCoreSkippedStaff): void
    {
        $temp = implode(',', $this->onCoreSkippedStaff) . ',' . $onCoreSkippedStaff;
        ExternalModules::setProjectSetting($this->getPREFIX(), $this->redcapProjectId, 'oncore-skipped-contacts', $temp);
        $this->onCoreSkippedStaff[] = $onCoreSkippedStaff;
    }


}
