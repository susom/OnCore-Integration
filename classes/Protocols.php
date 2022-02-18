<?php

namespace Stanford\OnCoreIntegration;


/**
 * Class Protocols
 * @package Stanford\OnCoreIntegration
 * @property Users $user
 * @property array $onCoreProtocol
 */
class Protocols extends Entities
{
    /**
     * @var Users
     */
    private $user;

    /**
     * @var array
     */
    private $onCoreProtocol;

    /**
     * @var array
     */
    private $entityRecord;

    /**
     * @param $user
     * @param $reset
     */
    public function __construct($user, $redcapProjectId = '', $reset = false)
    {
        parent::__construct($reset);

        $this->setUser($user);

        // if protocol is initiated for specific REDCap project. then check if this ONCORE_PROTOCOL entity record exists and pull OnCore Protocol via  API
        if ($redcapProjectId) {
            $this->prepareProtocol($redcapProjectId);
        }
    }

    public function prepareProtocol($redcapProjectId)
    {
        $protocol = $this->getProtocolEntityRecord($redcapProjectId);
        if (!empty($protocol)) {
            $this->setEntityRecord($protocol);
            $this->setOnCoreProtocol($this->searchOnCoreProtocolsViaID($this->getEntityRecord()['oncore_protocol_id']));
        }
    }

    public function searchOnCoreProtocolsViaID($protocolID)
    {
        try {
            //TODO attempt to get user token before using global one.
            $jwt = $this->getUser()->getGlobalAccessToken();
            $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'protocols/' . $protocolID, [
                'debug' => false,
                'headers' => [
                    'Authorization' => "Bearer {$jwt}",
                ]
            ]);

            if ($response->getStatusCode() < 300) {
                $data = json_decode($response->getBody(), true);
                if (!empty($data)) {
                    $this->setOnCoreProtocol($data);
                    return $data;
                }
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function searchOnCoreProtocolsViaIRB($irbNum)
    {
        try {
            $jwt = $this->getUser()->getGlobalAccessToken();
            $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'protocolManagementDetails?irbNo=' . $irbNum, [
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
     * @param $redcapProjectId
     * @param $irbNum
     * @return array|false|mixed|string[]|null
     * @throws \Exception
     */
    public function getProtocolEntityRecord($redcapProjectId, $irbNum = '')
    {
        if ($redcapProjectId == '') {
            throw new \Exception('REDCap project ID can not be null');
        }
        if ($irbNum != '') {
            $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " where irb_number = " . $irbNum . " AND redcap_project_id = " . $redcapProjectId . " ");
        } else {
            $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " where redcap_project_id = " . $redcapProjectId . " ");
        }
        if ($record->num_rows == 0) {
            return [];
        } else {
            return db_fetch_assoc($record);
        }
    }

    public function updateProtocolEntityRecordTimestamp($entityId)
    {
        db_query("UPDATE " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " set last_date_scanned = '" . time() . "', updated = '" . time() . "' WHERE id = " . $entityId . "");
    }

    /**
     * @return Users
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param $user
     * @return void
     */
    public function setUser($user): void
    {
        $this->user = $user;
    }

    /**
     * @return array
     */
    public function getOnCoreProtocol(): array
    {
        return $this->onCoreProtocol;
    }

    /**
     * @param array $onCoreProtocol
     */
    public function setOnCoreProtocol(array $onCoreProtocol): void
    {
        $this->onCoreProtocol = $onCoreProtocol;
    }

    /**
     * @return array
     */
    public function getEntityRecord(): array
    {
        return $this->entityRecord;
    }

    /**
     * @param array $entityRecord
     */
    public function setEntityRecord(array $entityRecord): void
    {
        $this->entityRecord = $entityRecord;
    }

}
