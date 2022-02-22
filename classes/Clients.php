<?php

namespace Stanford\OnCoreIntegration;

use ExternalModules\ExternalModules;
use stdClass;

/**
 * Abstract class can be accessed only via Users class
 * Class Clients
 * @package Stanford\OnCoreIntegration
 * @property string $PREFIX
 * @property int $projectId
 * @property string $globalClientId
 * @property string $globalClientSecret
 * @property string $globalAccessToken
 * @property string $apiURL
 * @property int $globalTokenTime
 * @property \GuzzleHttp\Client $guzzleClient
 */
abstract class Clients extends \REDCapEntity\EntityFactory
{
    /**
     * @var string
     */
    private $PREFIX;

    /**
     * @var int
     */
    private $projectId;

    /**
     * @var string
     */
    private $globalClientId;

    /**
     * @var string
     */
    private $globalClientSecret;

    /**
     * @var \GuzzleHttp\Client
     */
    private $guzzleClient;

    /**
     * @var string
     */
    private $globalAccessToken;

    /**
     * @var int
     */
    private $globalTokenTime;

    /**
     * @var string
     */
    private $apiURL;

    /**
     * @var string
     */
    private $apiAuthURN;

    /**
     * @var string
     */
    private $apiURN;

    /**
     * @param $PREFIX
     */
    public function __construct($PREFIX)
    {
        $this->setPREFIX($PREFIX);


        $this->setGuzzleClient(new \GuzzleHttp\Client());

        $this->setApiURL(ExternalModules::getSystemSetting($this->getPrefix(), 'oncore-api-url'));

        $this->setApiAuthURN(ExternalModules::getSystemSetting($this->getPrefix(), 'oncore-api-auth-urn'));

        $this->setApiURN(ExternalModules::getSystemSetting($this->getPrefix(), 'oncore-api-urn'));

        $this->setGlobalClientId(ExternalModules::getSystemSetting($this->getPrefix(), 'global-client-id'));

        $this->setGlobalClientSecret(ExternalModules::getSystemSetting($this->getPrefix(), 'global-client-secret'));

        if (ExternalModules::getSystemSetting($this->getPrefix(), 'global-token-timestamp') > time()) {
            $this->setGlobalAccessToken(ExternalModules::getSystemSetting($this->getPrefix(), 'global-access-token'));

            $this->setGlobalTokenTime(ExternalModules::getSystemSetting($this->getPrefix(), 'global-token-timestamp'));
        } else {
            $result = $this->generateToken($this->getGlobalClientId(), $this->getGlobalClientSecret());
            $this->setGlobalAccessToken((string)$result->access_token);
            ExternalModules::setSystemSetting($this->getPrefix(), 'global-access-token', $result->access_token);

            $this->setGlobalTokenTime((string)($result->expires_in + time()));
            ExternalModules::setSystemSetting($this->getPrefix(), 'global-token-timestamp', (string)($result->expires_in + time()));

        }


    }

    /**
     * function will be used to generate tokens globally and for specific user.
     * @param string $clientId
     * @param string $clientSecret
     * @return stdClass|string
     */
    public function generateToken(string $clientId, string $clientSecret)
    {
        try {
            $response = $this->getGuzzleClient()->post($this->getApiURL() . $this->getApiAuthURN(), [
                'debug' => false,
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json'
                ]
            ]);
            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody());
                if (property_exists($data, 'access_token')) {
                    return $data;
                } else {
                    throw new \Exception("Could not find access token.");
                }
            }
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    /**
     * @return mixed
     */
    public function getPREFIX()
    {
        return $this->PREFIX;
    }

    /**
     * @param mixed $PREFIX
     */
    public function setPREFIX($PREFIX): void
    {
        $this->PREFIX = $PREFIX;
    }

    /**
     * @return mixed
     */
    public function getProjectId()
    {
        return $this->projectId;
    }

    /**
     * @param mixed $projectId
     */
    public function setProjectId($projectId): void
    {
        $this->projectId = $projectId;
    }

    /**
     * @return mixed
     */
    public function getGlobalClientId()
    {
        return $this->globalClientId;
    }

    /**
     * @param mixed $globalClientId
     */
    public function setGlobalClientId($globalClientId): void
    {
        $this->globalClientId = $globalClientId;
    }

    /**
     * @return mixed
     */
    public function getGlobalClientSecret()
    {
        return $this->globalClientSecret;
    }

    /**
     * @param mixed $globalClientSecret
     */
    public function setGlobalClientSecret($globalClientSecret): void
    {
        $this->globalClientSecret = $globalClientSecret;
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getGuzzleClient()
    {
        return $this->guzzleClient;
    }

    /**
     * @param $guzzleClient
     * @return void
     */
    public function setGuzzleClient($guzzleClient): void
    {
        $this->guzzleClient = $guzzleClient;
    }

    /**
     * @return mixed
     */
    public function getGlobalAccessToken()
    {
        if ($this->getGlobalTokenTime() > time() && $this->globalAccessToken) {
            return $this->globalAccessToken;
        } else {
            $result = $this->generateToken($this->getGlobalClientId(), $this->getGlobalClientSecret());
            $this->setGlobalAccessToken((string)$result->access_token);

            $this->setGlobalTokenTime(($result->expires_in + time()));
        }

        return $this->globalAccessToken;
    }

    /**
     * @param mixed $globalAccessToken
     */
    public function setGlobalAccessToken($globalAccessToken): void
    {
        $this->globalAccessToken = $globalAccessToken;
    }

    /**
     * @return mixed
     */
    public function getGlobalTokenTime()
    {
        return $this->globalTokenTime;
    }

    /**
     * @param mixed $globalTokenTime
     */
    public function setGlobalTokenTime($globalTokenTime): void
    {
        $this->globalTokenTime = $globalTokenTime;
    }

    /**
     * @return mixed
     */
    public function getApiURL()
    {
        return ltrim($this->apiURL, '/') . '/';
    }

    /**
     * @param mixed $apiURL
     */
    public function setApiURL($apiURL): void
    {
        $this->apiURL = $apiURL;
    }

    /**
     * @return mixed
     */
    public function getApiAuthURN()
    {
        return $this->apiAuthURN;
    }

    /**
     * @param mixed $apiAuthURN
     */
    public function setApiAuthURN($apiAuthURN): void
    {
        $this->apiAuthURN = $apiAuthURN;
    }

    /**
     * @return mixed
     */
    public function getApiURN()
    {
        return ltrim($this->apiURN, '/');
    }

    /**
     * @param mixed $apiURN
     */
    public function setApiURN($apiURN): void
    {
        $this->apiURN = $apiURN;
    }
}
