<?php

namespace Stanford\OnCoreIntegration;
require_once 'Clients.php';

/**
 * Class Users
 * @package Stanford\OnCoreIntegration
 * @property string $clientId
 * @property string $clientSecret
 * @property string $accessToken
 * @property int $tokenTime
 */
class Users extends Clients
{
    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var int
     */
    private $tokenTime;

    /**
     * @var array
     */
    private $onCoreAdmin;


    /**
     * @param $prefix
     * @param $clientId
     * @param $clientSecret
     */
    public function __construct($prefix)
    {
        parent::__construct($prefix);
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

    public function getOnCoreAdminEntityRecord($username)
    {
        if ($username == '') {
            throw new \Exception('REDCap username ID can not be null');
        }
        $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_ADMINS . " where  redcap_username = " . $username . " ");
        if ($record->num_rows == 0) {
            return [];
        } else {
            return db_fetch_assoc($record);
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
                Entities::createLog(date('m/d/Y H:i:s') . ' : OnCore Admin Entity record created for redcap username: ' . $redcapUsername . '.');
                return $entity->getData();
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * set client id and secret for entity record.
     * @param $username
     * @param $clientId
     * @param $clientSecret
     * @return void
     * @throws \Exception
     */
    public function updateOnCoreAdminEntityRecord($username, $clientId, $clientSecret)
    {
        if (!$username) {
            throw new \Exception("REDCap username is not provided.");
        }
        if ($clientSecret && $clientId) {
            $record = db_query("UPDATE " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_ADMINS . " set  oncore_client_id = '" . $clientId . "',  oncore_client_secret = '" . $clientSecret . "' WHERE redcap_username = '" . $username . "' ");
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
            $jwt = $this->getGlobalAccessToken();
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
     * @return mixed
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * @param mixed $clientId
     */
    public function setClientId($clientId): void
    {
        $this->clientId = $clientId;
    }

    /**
     * @return mixed
     */
    public function getClientSecret()
    {
        return $this->clientSecret;
    }

    /**
     * @param mixed $clientSecret
     */
    public function setClientSecret($clientSecret): void
    {
        $this->clientSecret = $clientSecret;
    }

    /**
     * @return mixed
     */
    public function getAccessToken()
    {
        if ($this->getTokenTime() > time() && $this->accessToken) {
            return $this->accessToken;
        } else {
            $result = $this->generateToken($this->getClientId(), $this->getClientSecret());
            $this->setAccessToken($result->access_token);
            $this->setTokenTime($result->expires_in + time());
        }
        return $this->accessToken;
    }

    /**
     * @param mixed $accessToken
     */
    public function setAccessToken($accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    /**
     * @return mixed
     */
    public function getTokenTime()
    {
        return $this->tokenTime;
    }

    /**
     * @param mixed $tokenTime
     */
    public function setTokenTime($tokenTime): void
    {
        $this->tokenTime = $tokenTime;
    }

    /**
     * @param string $onCoreAdmin
     * @return array
     */
    public function getOnCoreAdmin(string $username): array
    {
        if (!$this->onCoreAdmin) {
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

}
