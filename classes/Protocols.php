<?php

namespace Stanford\OnCoreIntegration;


/**
 * Class Protocols
 * @package Stanford\OnCoreIntegration
 * @property Users $user
 */
class Protocols extends Entities
{
    /**
     * @var Users
     */
    private $user;

    /**
     * @param $user
     * @param $reset
     */
    public function __construct($user, $reset = false)
    {
        parent::__construct($reset);

        $this->setUser($user);
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

    public function getProtocolEntityRecord($irbNum, $redcapProjectId)
    {
        $record = db_query("select * from " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " where irb_number = " . $irbNum . " AND redcap_project_id = " . $redcapProjectId . " ");
        if ($record->num_rows == 0) {
            return [];
        } else {
            return db_fetch_assoc($record);
        }
    }

    public function updateProtocolEntityRecordTimestamp($entityId)
    {
        db_query("UPDATE " . OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS . " set last_date_scanned = '" . time() . "' WHERE id = " . $entityId . "");
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


}
