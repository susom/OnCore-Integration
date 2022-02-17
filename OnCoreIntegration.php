<?php
namespace Stanford\OnCoreIntegration;

require_once "emLoggerTrait.php";
require_once 'classes/Users.php';
require_once 'classes/Entities.php';
require_once 'classes/Protocols.php';

/**
 * Class OnCoreIntegration
 * @package Stanford\OnCoreIntegration
 * @property \Stanford\OnCoreIntegration\Users $users;
 * @property \Stanford\OnCoreIntegration\Protocols $protocols;
 */
class OnCoreIntegration extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    const ONCORE_PROTOCOLS = 'oncore_protocols';

    private $users;

    private $protocols;

    public function __construct()
    {
        parent::__construct();

        if (isset($_GET['pid'])) {
            $this->setUsers(new Users($this->PREFIX));
        }
        // Other code to run when object is instantiated
    }

    public function redcap_entity_types()
    {
        $types = [];

        $types['oncore_protocols'] = [
            'label' => 'OnCore protocols',
            'label_plural' => 'OnCore Protocols',
            'icon' => 'home_pencil',
            'properties' => [
                'redcap_project_id' => [
                    'name' => 'REDCap Project',
                    'type' => 'project',
                    'required' => true,
                ],
                'irb_number' => [
                    'name' => 'IRB Number',
                    'type' => 'integer',
                    'required' => false,
                ],
                'oncore_protocol_id' => [
                    'name' => 'OnCore Protocol ID',
                    'type' => 'integer',
                    'required' => true,
                ],
                'last_date_scanned' => [
                    'name' => 'Timestamp of last scan',
                    'type' => 'date',
                    'required' => true,
                ],
                'status' => [
                    'name' => 'Linkage Status',
                    'type' => 'text',
                    'required' => true,
                    'default' => '0',
                    'choices' => [
                        '0' => 'No',
                        '1' => 'Pending',
                        '2' => 'Yes',
                    ],
                ],
            ],
            'special_keys' => [
                'label' => 'redcap_project_id', // "name" represents the entity label.
            ],
        ];

        // TODO redcap entity for redcap records not in OnCore

        // TODO redcap entity for OnCore records not in REDCap

        // TODO redcap entity to save the linkage between redcap and OnCore records

        return $types;
    }

    /**
     * @return mixed
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * @param mixed $users
     */
    public function setUsers($users): void
    {
        $this->users = $users;
    }

    /**
     * @return Protocols
     */
    public function getProtocols()
    {
        return $this->protocols;
    }

    /**
     * @param mixed $protocols
     */
    public function setProtocols($protocols): void
    {
        $this->protocols = $protocols;
    }


    public function onCoreProtocolsScanCron()
    {
        try {
            $projects = self::query("select project_id, project_irb_number from redcap_projects where project_irb_number is NOT NULL ", []);

            $this->setProtocols(new Protocols($this->getUsers()));

            while ($project = $projects->fetch_assoc()) {
                $id = $project['project_id'];
                $irb = $project['project_irb_number'];

                $protocol = $this->getProtocols()->searchOnCoreProtocolsViaIRB($irb);

                if (!empty($protocol)) {
                    $entity_oncore_protocol = self::query("select * from redcap_entity_oncore_protocols where irb_number = ? AND redcap_project_id = ? ", [$irb, $id]);
                    if ($entity_oncore_protocol->num_rows == 0) {
                        $data = array(
                            'redcap_project_id' => $id,
                            'irb_number' => $irb,
                            'oncore_protocol_id' => $protocol['protocolId'],
                            'status' => '0',
                            'last_date_scanned' => time()
                        );

                        $entity = $this->getProtocols()->create(self::ONCORE_PROTOCOLS, $data);

                        if ($entity) {
                            echo $entity->getId() . '<br>';
                        } else {
                            throw new \Exception(implode(',', $this->getProtocols()->errors));
                        }
                    } else {
                        $record = $entity_oncore_protocol->fetch_assoc();
                        self::query("UPDATE redcap_entity_oncore_protocols set last_date_scanned = ? WHERE id = ?", [time(), $record['id']]);
                    }
                } else {
                    $this->emLog(date('m/d/Y H:i:s') . ' : IRB' . $irb . ' has no OnCore Protocol.');
                }
            }
        } catch (\Exception $e) {
            $this->emError($e->getMessage());
            \REDCap::logEvent('CRON JOB ERROR: ' . $e->getMessage());
        }
    }
}
