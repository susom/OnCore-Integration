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
    const REDCAP_ENTITY_ONCORE_PROTOCOLS = 'redcap_entity_oncore_protocols';
    const ONCORE_REDCAP_API_ACTIONS_LOG = 'oncore_redcap_api_actions_log';
    const REDCAP_ENTITY_ONCORE_REDCAP_API_ACTIONS_LOG = 'redcap_entity_oncore_redcap_api_actions_log';
    const ONCORE_ADMINS = 'oncore_admins';
    const REDCAP_ENTITY_ONCORE_ADMINS = 'redcap_entity_oncore_admins';
    const ONCORE_SUBJECTS = 'oncore_subjects';
    const REDCAP_ENTITY_ONCORE_SUBJECTS = 'redcap_entity_oncore_subjects';

    /**
     * @var Users
     */
    private $users;

    /**
     * @var Protocols
     */
    private $protocols;

    public function __construct()
    {
        parent::__construct();

        if (isset($_GET['pid'])) {
            $this->setUsers(new Users($this->PREFIX, $this->framework->getUser(), $this->getCSRFToken()));

            $this->setProtocols(new Protocols($this->getUsers(), filter_var($_GET['pid'], FILTER_SANITIZE_NUMBER_INT)));
        }
        // Other code to run when object is instantiated
    }

    public function redcap_every_page_top()
    {
        try {
            // in case we are loading record homepage load its the record children if existed
            preg_match('/redcap_v[\d\.].*\/index\.php/m', $_SERVER['SCRIPT_NAME'], $matches, PREG_OFFSET_CAPTURE);
            if (strpos($_SERVER['SCRIPT_NAME'], 'ProjectSetup') !== false || !empty($matches)) {
                $this->setProtocols(new Protocols($this->getUsers(), $this->getProjectId()));
            }
        } catch (\Exception $e) {
            // TODO routine to handle exception for not finding OnCore protocol
        }
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

        $types['oncore_redcap_api_actions_log'] = [
            'label' => 'OnCore protocols',
            'label_plural' => 'OnCore Protocols',
            'icon' => 'home_pencil',
            'properties' => [
                'message' => [
                    'name' => 'Action Body',
                    'type' => 'long_text',
                    'required' => false,
                ],
                'url' => [
                    'name' => 'Called URL',
                    'type' => 'text',
                    'required' => false,
                ],
                'response' => [
                    'name' => 'OnCore API Response',
                    'type' => 'text',
                    'required' => false,
                ],
                'type' => [
                    'name' => 'Action Type',
                    'type' => 'integer',
                    'required' => true,
                    'default' => 0,
                    'choices' => [
                        0 => 'OnCore API Call ',
                        1 => 'REDCap API Call',
                    ],
                ],
            ],
            'special_keys' => [
                'label' => 'message', // "name" represents the entity label.
            ],
        ];;

        $types['oncore_admins'] = [
            'label' => 'OnCore Admin',
            'label_plural' => 'OnCore Admins',
            'icon' => 'home_pencil',
            'properties' => [
                'oncore_contact_id' => [
                    'name' => 'OnCore Contact Id',
                    'type' => 'text',
                    'required' => true,
                ],
                'redcap_username' => [
                    'name' => 'REDCap username',
                    'type' => 'text',
                    'required' => true,
                ],
                'oncore_client_id' => [
                    'name' => 'OnCore API Client id',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_client_secret' => [
                    'name' => 'OnCore API Client Secret',
                    'type' => 'text',
                    'required' => false,
                ],
            ],
            'special_keys' => [
                'label' => 'redcap_username', // "name" represents the entity label.
            ],
        ];;

        $types['oncore_subjects'] = [
            'label' => 'OnCore Subject',
            'label_plural' => 'OnCore Subjects',
            'icon' => 'home_pencil',
            'properties' => [
                'oncore_subject_demographics_id' => [
                    'name' => 'OnCore Subject Demographics Id',
                    'type' => 'text',
                    'required' => true,
                ],
                'oncore_subject_source' => [
                    'name' => 'Subject Source',
                    'type' => 'text',
                    'required' => true,
                ],
                'oncore_mrn' => [
                    'name' => 'OnCore MRN',
                    'type' => 'text',
                    'required' => true,
                ],
                'oncore_last_name' => [
                    'name' => 'OnCore Lastname',
                    'type' => 'text',
                    'required' => true,
                ],
                'oncore_first_name' => [
                    'name' => 'OnCore Firstname',
                    'type' => 'text',
                    'required' => true,
                ],
                'oncore_suffix' => [
                    'name' => 'OnCore suffix',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_birth_date' => [
                    'name' => 'OnCore Date of Birth',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_approximate_birth_date' => [
                    'name' => 'OnCore Approximate Date of Birth',
                    'type' => 'boolean',
                    'required' => false,
                ],
                'oncore_birth_date_not_available' => [
                    'name' => 'OnCore Date of Birth not available',
                    'type' => 'boolean',
                    'required' => false,
                ],
                'oncore_expired_date' => [
                    'name' => 'OnCore Expired Date',
                    'type' => 'date',
                    'required' => false,
                ],
                'oncore_approximate_expired_date' => [
                    'name' => 'OnCore Approximate Expired Date',
                    'type' => 'boolean',
                    'required' => false,
                ],
                'oncore_last_date_known_alive' => [
                    'name' => 'OnCore Last date known alive',
                    'type' => 'date',
                    'required' => false,
                ],
                'oncore_ssn' => [
                    'name' => 'OnCore SSN',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_gender' => [
                    'name' => 'OnCore Gender',
                    'type' => 'text',
                    'required' => true,
                ],
                'oncore_ethnicity' => [
                    'name' => 'OnCore Ethnicity',
                    'type' => 'text',
                    'required' => true,
                ],
                'oncore_race' => [
                    'name' => 'OnCore Race',
                    'type' => 'json',
                    'required' => true,
                ],
                'oncore_subject_comments' => [
                    'name' => 'OnCore SubjectComments',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_additional_subject_ids' => [
                    'name' => 'OnCore Additional Subject Ids',
                    'type' => 'json',
                    'required' => false,
                ],
                'oncore_street_address' => [
                    'name' => 'OnCore street Address',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_address_line2' => [
                    'name' => 'OnCore address Line2',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_city' => [
                    'name' => 'OnCore City',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_state' => [
                    'name' => 'OnCore State',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_zip' => [
                    'name' => 'OnCore Zip Code',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_county' => [
                    'name' => 'OnCore County',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_country' => [
                    'name' => 'OnCore Country',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_phone_no' => [
                    'name' => 'OnCore Phone No',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_alternate_phone_no' => [
                    'name' => 'OnCore Alternate Phone No',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_email' => [
                    'name' => 'OnCore Email',
                    'type' => 'text',
                    'required' => false,
                ]
            ],
            'special_keys' => [
                'label' => 'oncore_subject_demographics_id', // "name" represents the entity label.
            ],
        ];;

        // TODO redcap entity for redcap records not in OnCore

        // TODO redcap entity for OnCore records not in REDCap

        // TODO redcap entity to save the linkage between redcap and OnCore records

        return $types;
    }

    /**
     * @return Users
     */
    public function getUsers(): Users
    {
        return $this->users;
    }

    /**
     * @param Users $users
     */
    public function setUsers(Users $users): void
    {
        $this->users = $users;
    }

    /**
     * @return Protocols
     */
    public function getProtocols(): Protocols
    {
        return $this->protocols;
    }

    /**
     * @param Protocols $protocols
     */
    public function setProtocols(Protocols $protocols): void
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
                    $entity_oncore_protocol = $this->getProtocols()->getProtocolEntityRecord($id, $irb);
                    if (empty($entity_oncore_protocol)) {
                        $data = array(
                            'redcap_project_id' => $id,
                            'irb_number' => $irb,
                            'oncore_protocol_id' => $protocol['protocolId'],
                            'status' => '0',
                            'last_date_scanned' => time()
                        );

                        $entity = $this->getProtocols()->create(self::ONCORE_PROTOCOLS, $data);

                        if ($entity) {
                            Entities::createLog(' : OnCore Protocol record created for IRB: ' . $irb . '.');
                        } else {
                            throw new \Exception(implode(',', $this->getProtocols()->errors));
                        }
                    } else {
                        $this->getProtocols()->updateProtocolEntityRecordTimestamp($entity_oncore_protocol['id']);
                        Entities::createLog('OnCore Protocol record updated for IRB: ' . $irb . '.');
                    }
                } else {
                    $this->emLog('IRB ' . $irb . ' has no OnCore Protocol.');
                    Entities::createLog('IRB ' . $irb . ' has no OnCore Protocol.');
                }
            }
        } catch (\Exception $e) {
            $this->emError($e->getMessage());
            \REDCap::logEvent('CRON JOB ERROR: ' . $e->getMessage());
            Entities::createLog('CRON JOB ERROR: ' . $e->getMessage());

        }
    }
}
