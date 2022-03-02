<?php

namespace Stanford\OnCoreIntegration;
use ExternalModules\User;

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
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function prepareUser()
    {

        $admin = $this->getOnCoreAdmin($this->getRedcapUser()->getUsername());
        $this->setOnCoreContact($this->searchOnCoreContactsViaEmail($this->getRedcapUser()->getEmail()));
        if (!$admin) {
            $this->createOnCoreAdminEntityRecord($this->getOnCoreContact()['contactId'], $this->getRedcapUser()->getUsername());
            $this->setOnCoreAdmin($this->getRedcapUser()->getUsername());
        }
    }

    /**
     * @param $oncoreContactId
     * @param $redcapUsername
     * @param $clientId
     * @param $clientSecret
     * @return string|void|array
     */
    public function createOnCoreAdminEntityRecord($oncoreContactId, $redcapUsername, $clientId = '', $clientSecret = '')
    {
        try {
            $data = array(
                'oncore_contact_id' => (string)$oncoreContactId,
                'redcap_username' => (string)$redcapUsername,
                'oncore_client_id' => (string)$clientId,
                'oncore_client_secret' => (string)$clientSecret
            );
            $entity = $this->create(OnCoreIntegration::ONCORE_ADMINS, $data);
            if ($entity) {
                Entities::createLog(' : OnCore Admin Entity record created for redcap username: ' . $redcapUsername . '.');
                return $entity->getData();
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }


    /**
     * search oncore API for a contact using email address.
     * @param $email
     * @return array|mixed|string|void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function searchOnCoreContactsViaEmail($email)
    {
        try {
            $jwt = $this->getAccessToken();
            $response = $this->getGuzzleClient()->get($this->getApiURL() . $this->getApiURN() . 'contacts?email=' . $email, [
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
            return $e->getMessage();
        }
    }

    /**
     * @param string $onCoreAdmin
     * @return array
     */
    public function getOnCoreAdmin(string $username = ''): array
    {
        if (!$this->onCoreAdmin && $username) {
            $this->setOnCoreAdmin($username);
        }
        return $this->onCoreAdmin;
    }

    /**
     * @param string $onCoreAdmin
     */
    public function setOnCoreAdmin(string $username): void
    {
        if ($username == '') {
            throw new \Exception('REDCap username ID can not be null');
        }
        $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_ADMINS . " where  redcap_username = '" . $username . "' ");
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
    public function getMrnValidationUrl(string $username): string {
        if ($this->onCoreAdmin) {
            return $this->getSystemSetting('mrn-verification-url');
        } else {
            return '';
        }
    }

}
