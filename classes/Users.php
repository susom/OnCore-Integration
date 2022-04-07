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
    private $onCoreAdmin;

    /**
     * @var array
     */
    private $onCoreContact;

    /**
     * @var User
     */
    private $redcapUser;

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
        return in_array($this->getOnCoreContact()['role'], $this->getRolesAllowedToPush());
    }

    /**
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function prepareUser($redcapEntityProtocolRecordId, $protocolId)
    {
        $this->setRedcapEntityProtocolRecordId($redcapEntityProtocolRecordId);
        $this->setProtocolStaff($protocolId);
        $admin = $this->getOnCoreAdmin();
        $this->setOnCoreContact($this->searchProtocolStaff($this->getRedcapUser()->getUsername()));
        if (!$admin && $this->isOnCoreContactAllowedToPush()) {
            $this->createOnCoreAdminEntityRecord();
            $this->setOnCoreAdmin();
        }
    }

    /**
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
     * @param $oncoreContactId
     * @param $redcapUsername
     * @param $clientId
     * @param $clientSecret
     * @return string|void|array
     */
    public function createOnCoreAdminEntityRecord()
    {
        try {
            $data = array(
                'oncore_contact_id' => (string)$this->getOnCoreContact()['contactId'],
                'redcap_username' => (string)$this->getRedcapUser()->getUsername(),
                'redcap_entity_oncore_protocol_id' => (int)$this->getRedcapEntityProtocolRecordId(),
                'oncore_role' => (string)$this->getOnCoreContact()['role']
            );
            $entity = (new Entities)->create(OnCoreIntegration::ONCORE_ADMINS, $data);
            if ($entity) {
                Entities::createLog(' : OnCore Admin Entity record created for redcap username: ' . $this->getRedcapUser()->getUsername() . '.');
                return $entity->getData();
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }


    /**
     * @param string $onCoreAdmin
     * @return array
     */
    public function getOnCoreAdmin(): array
    {
        if (!$this->onCoreAdmin) {
            $this->setOnCoreAdmin();
        }
        return $this->onCoreAdmin;
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function setOnCoreAdmin(): void
    {
        $username = $this->getRedcapUser()->getUsername();
        if ($username == '') {
            throw new \Exception('REDCap username ID can not be null');
        }
        $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_ADMINS . " where  redcap_username = '" . $username . "' AND redcap_entity_oncore_protocol_id = '" . $this->getRedcapEntityProtocolRecordId() . "' ");
        if ($record->num_rows == 0) {
            $this->onCoreAdmin = [];
        } else {
            $this->onCoreAdmin = db_fetch_assoc($record);
        }
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
            $jwt = $this->getAccessToken();
            $response = $this->getGuzzleClient()->get($this->getApiURL() . $this->getApiURN() . 'protocolStaff?protocolId=' . $protocolId, [
                'debug' => false,
                'headers' => [
                    'Authorization' => "Bearer {$jwt}",
                ]
            ]);

            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody(), true);
                if (empty($data)) {
                    $this->protocolStaff = [];
                } else {
                    foreach ($data as $staff) {
                        $staff['contact'] = $this->getContactDetails($staff['contactId']);
                        $this->protocolStaff[] = $staff;
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
            $jwt = $this->getAccessToken();
            $response = $this->getGuzzleClient()->get($this->getApiURL() . $this->getApiURN() . 'contacts/' . $contactId, [
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
                    return $data;
                }
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
        }
    }

    /**
     * @return int
     */
    public function getRedcapEntityProtocolRecordId(): int
    {
        return $this->redcapEntityProtocolRecordId;
    }

    /**
     * @param int $redcapEntityProtocolRecordId
     */
    public function setRedcapEntityProtocolRecordId(int $redcapEntityProtocolRecordId): void
    {
        $this->redcapEntityProtocolRecordId = $redcapEntityProtocolRecordId;
    }


}
