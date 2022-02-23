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
     * @var Subjects
     */
    private $subjects;

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
            /**
             * if OnCore protocol found then prepare its subjects
             */
            $this->prepareProtocolSubjects();

        }
    }

    public function prepareProtocolSubjects()
    {
        try {
            $this->setSubjects(new Subjects($this->getUser()));
            $this->getSubjects()->setOnCoreProtocolSubjects($this->getEntityRecord()['oncore_protocol_id']);
        } catch (\Exception $e) {
            // TODO exception handler
        }
    }

    public function isContactPartOfOnCoreProtocol($contactId)
    {
        try {
            //TODO can redcap user who is a contact can other redcap users?
            if (empty($this->getUser()->getOnCoreAdmin())) {

                throw new \Exception("Can not find a OnCore Admin");
            }
            if (empty($this->getOnCoreProtocol())) {
                throw new \Exception("No protocol found for current REDCap project.");
            }
            $jwt = $this->getUser()->getAccessToken();
            $response = $this->getUser()->getGuzzleClient()->get($this->getUser()->getApiURL() . $this->getUser()->getApiURN() . 'protocolStaff?protocolId=' . $this->getOnCoreProtocol()['protocolId'], [
                'debug' => false,
                'headers' => [
                    'Authorization' => "Bearer {$jwt}",
                ]
            ]);

            if ($response->getStatusCode() < 300) {
                $staffs = json_decode($response->getBody(), true);
                foreach ($staffs as $staff) {
                    if ($contactId == $staff['contactId']) {
                        return $staff;
                    }
                }
                return false;
            }
            return false;
        } catch (\Exception $e) {
            Entities::createLog($e->getMessage());
            throw new \Exception($e->getMessage());

        }
    }

    public function searchOnCoreProtocolsViaID($protocolID)
    {
        try {
            $jwt = $this->getUser()->getAccessToken();
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
            Entities::createLog($e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    public function searchOnCoreProtocolsViaIRB($irbNum)
    {
        try {
            $jwt = $this->getUser()->getAccessToken();
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
                    return $data;
                }
            }
        } catch (\Exception $e) {
            Entities::createLog($e->getMessage());
            throw new \Exception($e->getMessage());
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

    /**
     * @return Subjects
     */
    public function getSubjects(): Subjects
    {
        return $this->subjects;
    }

    /**
     * @param Subjects $subjects
     */
    public function setSubjects(Subjects $subjects): void
    {
        $this->subjects = $subjects;
    }


}
