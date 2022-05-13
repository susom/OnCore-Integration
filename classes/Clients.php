<?php

namespace Stanford\OnCoreIntegration;

use ExternalModules\ExternalModules;
use GuzzleHttp\Exception\GuzzleException;
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
abstract class Clients
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
     * @var string
     */
    private $mrnVerificationURL;

    /**
     * @var string
     */
    private $redcapCSFRToken;

    /**
     * @var array
     */
    private $rolesAllowedToPush = [];

    /**
     * @var array
     */
    private $statusesAllowedToPush = [];

    /**
     * @var array
     */
    private $onCoreStudySites = [];

    /**
     * @var bool
     */
    private $disableVerification = false;

    /**
     * @param $PREFIX
     */
    public function __construct($PREFIX, $redcapCSFRToken)
    {
        $this->setPREFIX($PREFIX);


        $this->setDisableVerification(ExternalModules::getSystemSetting($this->getPrefix(), 'disable-ssl-verify') ? false : true);


        $this->setGuzzleClient(new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 5,
                'verify' => $this->isDisableVerification(),
            ]
        ));

        $this->setApiURL(ExternalModules::getSystemSetting($this->getPrefix(), 'oncore-api-url'));

        $this->setApiAuthURN(ExternalModules::getSystemSetting($this->getPrefix(), 'oncore-api-auth-urn'));

        $this->setApiURN(ExternalModules::getSystemSetting($this->getPrefix(), 'oncore-api-urn'));

        $this->setMrnVerificationURL(ExternalModules::getSystemSetting($this->getPrefix(), 'mrn-verification-url'));

        $this->setGlobalClientId(ExternalModules::getSystemSetting($this->getPrefix(), 'global-client-id'));

        $this->setGlobalClientSecret(ExternalModules::getSystemSetting($this->getPrefix(), 'global-client-secret'));

        $this->setRolesAllowedToPush(ExternalModules::getSystemSetting($this->getPrefix(), 'staff-role') ?: []);

        $this->setStatusesAllowedToPush(ExternalModules::getSystemSetting($this->getPrefix(), 'protocol-status') ?: []);

        $this->setOnCoreStudySites(ExternalModules::getSystemSetting($this->getPrefix(), 'study-site') ?: []);

        $this->setRedcapCSFRToken($redcapCSFRToken);

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
     * @param $path
     * @param $customOptions
     * @return \Psr\Http\Message\ResponseInterface|void
     * @throws \Exception
     */
    public function get($path, $customOptions = [])
    {

        $jwt = $this->getAccessToken();
        $options = [
            'debug' => false,
            'headers' => [
                'Authorization' => "Bearer {$jwt}",
            ]
        ];

        if (!empty($customOptions)) {
            $options = array_merge($options, $customOptions);
        }
        $response = $this->getGuzzleClient()->get($this->getApiURL() . $this->getApiURN() . $path, $options);
        return $response;
    }

    /**
     * @param string $path
     * @param array $data
     * @return \Psr\Http\Message\ResponseInterface|void
     * @throws \Exception
     */
    public function post(string $path, array $data)
    {
        $jwt = $this->getAccessToken();
        $response = $this->getGuzzleClient()->post($this->getApiURL() . $this->getApiURN() . $path, [
            'debug' => false,
            'body' => json_encode($data),
            'headers' => ['Authorization' => "Bearer {$jwt}", 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]);
        return $response;
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
    public function getAccessToken()
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

    /**
     * @return string
     */
    public function getMrnVerificationURL(): string
    {
        return $this->mrnVerificationURL;
    }

    /**
     * @param string $mrnVerificationURL
     */
    public function setMrnVerificationURL($mrnVerificationURL): void
    {
        $this->mrnVerificationURL = $mrnVerificationURL;
    }

    /**
     * @return string
     */
    public function getRedcapCSFRToken(): string
    {
        return $this->redcapCSFRToken;
    }

    /**
     * @param string|null $redcapCSFRToken
     */
    public function setRedcapCSFRToken($redcapCSFRToken): void
    {
        $this->redcapCSFRToken = $redcapCSFRToken;
    }

    /**
     * @return array
     */
    public function getRolesAllowedToPush(): array
    {
        return $this->rolesAllowedToPush;
    }

    /**
     * @param array $rolesAllowedToPush
     */
    public function setRolesAllowedToPush(array $rolesAllowedToPush): void
    {
        $this->rolesAllowedToPush = OnCoreIntegration::nestedLowercase($rolesAllowedToPush);
    }

    /**
     * @return array
     */
    public function getStatusesAllowedToPush(): array
    {
        return $this->statusesAllowedToPush;
    }

    /**
     * @param array $statusesAllowedToPush
     */
    public function setStatusesAllowedToPush(array $statusesAllowedToPush): void
    {
        $this->statusesAllowedToPush = OnCoreIntegration::nestedLowercase($statusesAllowedToPush);
    }

    /**
     * @return array
     */
    public function getOnCoreStudySites(): array
    {
        return $this->onCoreStudySites;
    }

    /**
     * @param array $onCoreStudySites
     */
    public function setOnCoreStudySites(array $onCoreStudySites): void
    {
        $this->onCoreStudySites = $onCoreStudySites;
    }

    /**
     * @return bool
     */
    public function isDisableVerification(): bool
    {
        return $this->disableVerification;
    }

    /**
     * @param bool $disableVerification
     */
    public function setDisableVerification(bool $disableVerification): void
    {
        $this->disableVerification = $disableVerification;
    }


}
