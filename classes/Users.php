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
    private $clientId;

    private $clientSecret;

    private $accessToken;

    private $tokenTime;

    /**
     * @param $prefix
     * @param $projectId
     * @param $clientId
     * @param $clientSecret
     */
    public function __construct($prefix, $projectId, $clientId = '', $clientSecret = '')
    {
        parent::__construct($prefix, $projectId);
        $this->setClientId($clientId);
        $this->setClientSecret($clientSecret);

        if ($this->getClientSecret() != '' && $this->getClientSecret() != '') {
            $result = $this->generateToken($this->getClientId(), $this->getClientSecret());
            $this->setAccessToken($result->access_token);
            $this->setTokenTime($result->expires_in + time());
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

}
