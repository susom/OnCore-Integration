<?php

namespace Stanford\OnCoreIntegration;

require_once "emLoggerTrait.php";
require_once 'classes/Users.php';
require_once 'classes/Entities.php';
require_once 'classes/Protocols.php';
require_once 'classes/Subjects.php';
require_once 'classes/Mapping.php';

/**
 * Class OnCoreIntegration
 * @package Stanford\OnCoreIntegration
 * @property \Stanford\OnCoreIntegration\Users $users;
 * @property \Stanford\OnCoreIntegration\Protocols $protocols;
 */
class OnCoreIntegration extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    const EXCLUDE_SUBJECTS = 'excluded_subjects';
    const FIELD_MAPPINGS = 'oncore_field_mappings';
    const ONCORE_PROTOCOLS = 'oncore_protocols';
    const REDCAP_ENTITY_ONCORE_PROTOCOLS = 'redcap_entity_oncore_protocols';
    const ONCORE_REDCAP_API_ACTIONS_LOG = 'oncore_redcap_api_actions_log';
    const REDCAP_ENTITY_ONCORE_REDCAP_API_ACTIONS_LOG = 'redcap_entity_oncore_redcap_api_actions_log';
    const ONCORE_ADMINS = 'oncore_admins';
    const REDCAP_ENTITY_ONCORE_ADMINS = 'redcap_entity_oncore_admins';
    const ONCORE_SUBJECTS = 'oncore_subjects';
    const REDCAP_ENTITY_ONCORE_SUBJECTS = 'redcap_entity_oncore_subjects';
    const ONCORE_REDCAP_RECORD_LINKAGE = 'oncore_redcap_records_linkage';
    const REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE = 'redcap_entity_oncore_redcap_records_linkage';

    const REDCAP_ONLY = 0;

    const ONCORE_ONLY = 1;

    const FULL_MATCH = 2;

    const PARTIAL_MATCH = 3;

    const REDCAP_ONCORE_FIELDS_MAPPING_NAME = 'redcap-oncore-fields-mapping';
    const REDCAP_ONCORE_PROJECT_SITE_STUDIES = 'redcap-oncore-project-site-studies';
    const REDCAP_ONCORE_PROJECT_ONCORE_SUBSET = 'redcap-oncore-project-oncore-subset';
    const REDCAP_ONCORE_PROJECT_PUSHPULL_PREF = 'redcap-oncore-project-pushpull-pref';

    const ONCORE_PROTOCOL_STATUS_NO = 0;

    const ONCORE_PROTOCOL_STATUS_PENDING = 1;

    const ONCORE_PROTOCOL_STATUS_YES = 2;

    const ONCORE_CONSENT_FILTER_LOGIC = 'redcap-oncore-consent-filter-logic';

    const ONCORE_BIRTHDATE_FIELD = 'birthDate';
    const ONCORE_BIRTHDATE_NOT_REQUIRED_FIELD = 'birthDateNotAvailable';

    const ONCORE_STUDY_SITE = 'studySites';
    const YES = 1;

    const NO = 0;

    const ONCORE_SUBJECT_ON_STUDY = 1;


    const ONCORE_SUBJECT_OFF_STUDY = 0;


    const ONCORE_SUBJECT_SOURCE_TYPE_ONCORE = 'OnCore';

    const ONCORE_SUBJECT_SOURCE_TYPE_ONSTAGE = 'Onstage';

    const SUBJECTS_MYSQL_LOCK = 'SUBJECTS_LOCK';

    public static $ONCORE_DEMOGRAPHICS_FIELDS = array(
        "subjectDemographicsId",
        "mrn",
        "gender",
        "ethnicity",
        "race",
        "birthDate",
        "lastName",
        "firstName",
        "middleName",
        "suffix",
        "approximateBirthDate",
        "birthDateNotAvailable",
        "expiredDate",
        "approximateExpiredDate",
        "lastDateKnownAlive",
        "ssn",
        "subjectComments",
        "additionalSubjectIds",
        "streetAddress",
        "addressLine2",
        "city",
        "state",
        "zip",
        "county",
        "country",
        "phoneNo",
        "alternatePhoneNo",
        "email");


    public static $ONCORE_DEMOGRAPHICS_REQUIRED_FIELDS = array(
        "mrn",
        "gender",
        "ethnicity",
        "race",
        "birthDate",
        "lastName",
        "firstName",
    );
    /**
     * @var Users
     */
    private $users;

    /**
     * @var Protocols
     */
    private $protocols;

    /**
     * @var Mapping
     */
    private $mapping;

    private $oncore_protocols;
    private $oncore_integrations;
    private $has_oncore_integrations;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        try {
            $protocol = Protocols::getOnCoreProtocolEntityRecord($project_id);
            if (!empty($protocol)) {
                if ($protocol['status'] == OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES) {
                    $this->initiateProtocol();
                    $this->getProtocols()->syncIndividualRecord($record);
                }
            }
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
//            $this->emError($e->getMessage());
        }
    }

    public function redcap_module_project_enable($version, $project_id)
    {
        // disabled because user has to pick protocol from project setup page.
//        global $Proj;
//        if ($Proj->project['project_irb_number']) {
//            $this->getProtocols()->processCron($this->getProjectId(), $Proj->project['project_irb_number'], $this->getDefinedLibraries());
//        }
    }

    public function redcap_module_system_enable($version)
    {
        $enabled = $this->isModuleEnabled('redcap_entity');
        if (!$enabled) {
            // TODO: what to do when redcap_entity is not enabled in the system
            Entities::createException("Cannot use this module OncoreIntegration because it is dependent on the REDCap Entities EM");
//            $this->emError("Cannot use this module OncoreIntegration because it is dependent on the REDCap Entities EM");
        } else {
            \REDCapEntity\EntityDB::buildSchema($this->PREFIX);
            Entities::createLog("Created all OnCore Entity tables");
//            $this->emDebug("Created all OnCore Entity tables");
        }
    }

    public function initiateProtocol()
    {
        try {
            if (!$this->users) {
                $this->setUsers(new Users($this->getProjectId(), $this->PREFIX, $this->framework->getUser() ?: null, $this->getCSRFToken()));
            }
        } catch (\Exception $e) {
            // this is a special case for cron no redcap user.
            $this->setUsers(new Users($this->getProjectId(), $this->PREFIX, null, $this->getCSRFToken()));
        }

        if (!$this->protocols) {
            $this->setProtocols(new Protocols($this->getUsers(), $this->getMapping(), $this->getProjectId()));

            // after protocol is init find its OnCore library and load it.
            $this->setProtocolLibrary();
        }
    }

    public function redcap_every_page_top($project_id)
    {
        try {
            // in case we are loading record homepage load its the record children if existed
            preg_match('/redcap_v[\d\.].*\/index\.php/m', $_SERVER['SCRIPT_NAME'], $matches, PREG_OFFSET_CAPTURE);
            if (strpos($_SERVER['SCRIPT_NAME'], 'ProjectSetup') !== false || !empty($matches)) {
                //TODO MAY NEED TO MOVE PROTOCOL INITIATION TO __construct
                $this->initiateProtocol();

                //TODO ANY MORE PERFORMANT WAY TO DO THIS THAN HITTING IT EVERY ProjectSetup page?
                $sql    = sprintf("SELECT project_irb_number from redcap_projects WHERE project_id = %s ", db_escape($project_id));
                $record = db_query($sql);
                if ($record->num_rows) {
                   $r           = db_fetch_assoc($record);
                   $project_irb = $r["project_irb_number"];
                   $this->injectIntegrationUI($project_irb);
                }
            }
        } catch (\Exception $e) {
            \REDCap::logEvent($e->getMessage());
        }
    }

    public static function nestedLowercase($array)
    {
        $result = array();
        foreach ($array as $key => $item) {
            if (is_array($item)) {
                return self::nestedLowercase($item);
            } else {
                $result[$key] = strtolower($item);
            }
        }
        return $result;
    }

    public function redcap_entity_types()
    {
        $types = [];

        $types[self::ONCORE_PROTOCOLS] = [
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
                    'type' => 'text',
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
                'redcap_event_id' => [
                    'name' => 'REDCap Event Id',
                    'type' => 'integer',
                    'required' => true,
                ],
                'oncore_library' => [
                    'name' => 'OnCore Protocol Library',
                    'type' => 'integer',
                    'default' => 0,
                    'required' => true,
                ],
                'status' => [
                    'name' => 'Linkage Status',
                    'type' => 'integer',
                    'required' => true,
                    'default' => 0,
                    'choices' => [
                        self::ONCORE_PROTOCOL_STATUS_NO => 'No',
                        self::ONCORE_PROTOCOL_STATUS_PENDING => 'Pending',
                        self::ONCORE_PROTOCOL_STATUS_YES => 'Yes',
                    ],
                ],
            ],
            'special_keys' => [
                'label' => 'redcap_project_id', // "name" represents the entity label.
            ],
        ];

        $types[self::ONCORE_REDCAP_API_ACTIONS_LOG] = [
            'label' => 'OnCore protocols',
            'label_plural' => 'OnCore Protocols',
            'icon' => 'home_pencil',
            'properties' => [
                'message' => [
                    'name' => 'Action Body',
                    'type' => 'text',
                    'required' => false,
                ],
                'redcap_project_id' => [
                    'name' => 'Project',
                    'type' => 'project',
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
                ],
            ],
            'special_keys' => [
                'label' => 'message', // "name" represents the entity label.
            ],
        ];;

        $types[self::ONCORE_ADMINS] = [
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
                'redcap_entity_oncore_protocol_id' => [
                    'name' => 'REDCap Entity OnCore Protocol Record Id(Foreign Key)',
                    'type' => 'integer',
                    'required' => true,
                ],
                'oncore_role' => [
                    'name' => 'OnCore Role',
                    'type' => 'text',
                    'required' => true,
                ],
            ],
            'special_keys' => [
                'label' => 'redcap_username', // "name" represents the entity label.
            ],
        ];

        $types[self::ONCORE_REDCAP_RECORD_LINKAGE] = [
            'label' => 'OnCore REDCap records Linkage',
            'label_plural' => 'OnCore REDCap records Linkage',
            'icon' => 'home_pencil',
            'properties' => [
                'redcap_project_id' => [
                    'name' => 'REDCap project Id',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_protocol_id' => [
                    'name' => 'OnCore Protocol Id',
                    'type' => 'text',
                    'required' => false,
                ],
                'redcap_record_id' => [
                    'name' => 'REDCap record Id',
                    'type' => 'text',
                    'required' => false,
                ],
                'oncore_protocol_subject_id' => [
                    'name' => 'OnCore Protocol Subject Id(NOT Demographics)',
                    'type' => 'integer',
                    'required' => false,
                ],
                'oncore_protocol_subject_status' => [
                    'name' => 'OnCore Protocol Subject Status (On/Off Study)',
                    'type' => 'integer',
                    'required' => false,
                    'default' => self::ONCORE_SUBJECT_ON_STUDY,
                    'choices' => [
                        self::ONCORE_SUBJECT_ON_STUDY => 'ON STUDY',
                        self::ONCORE_SUBJECT_OFF_STUDY => 'OFF STUDY'
                    ],
                ],
                'status' => [
                    'name' => 'Linkage Status',
                    'type' => 'integer',
                    'required' => true,
                    'default' => self::REDCAP_ONLY,
                    'choices' => [
                        self::REDCAP_ONLY => 'REDCAP_ONLY',
                        self::ONCORE_ONLY => 'ONCORE_ONLY',
                        self::FULL_MATCH => 'FULL_MATCH',
                        self::PARTIAL_MATCH => 'PARTIAL_MATCH',
                    ],
                ],
                'excluded' => [
                    'name' => 'Is Record Excluded?',
                    'type' => 'integer',
                    'required' => true,
                    'default' => self::NO,
                    'choices' => [
                        self::NO => 'No',
                        self::YES => 'Yes',
                    ],
                ],
            ],
            'special_keys' => [
                'label' => 'redcap_project_id', // "name" represents the entity label.
            ],
        ];;

        $types[self::ONCORE_SUBJECTS] = [
            'label' => 'OnCore Subject',
            'label_plural' => 'OnCore Subjects',
            'icon' => 'home_pencil',
            'properties' => [
                'subjectDemographicsId' => [
                    'name' => 'OnCore Subject Demographics Id',
                    'type' => 'integer',
                    'required' => false,
                ],
                'subjectSource' => [
                    'name' => 'Subject Source',
                    'type' => 'text',
                    'required' => false,
                ],
                'mrn' => [
                    'name' => 'OnCore MRN',
                    'type' => 'text',
                    'required' => false,
                ],
                'lastName' => [
                    'name' => 'OnCore Lastname',
                    'type' => 'text',
                    'required' => false,
                ],
                'firstName' => [
                    'name' => 'OnCore Firstname',
                    'type' => 'text',
                    'required' => false,
                ],
                'middleName' => [
                    'name' => 'OnCore middle Name',
                    'type' => 'text',
                    'required' => false,
                ],
                'suffix' => [
                    'name' => 'OnCore suffix',
                    'type' => 'text',
                    'required' => false,
                ],
                'birthDate' => [
                    'name' => 'OnCore Date of Birth',
                    'type' => 'text',
                    'required' => false,
                ],
                'approximateBirthDate' => [
                    'name' => 'OnCore Approximate Date of Birth',
                    'type' => 'boolean',
                    'required' => false,
                ],
                'birthDateNotAvailable' => [
                    'name' => 'OnCore Date of Birth not available',
                    'type' => 'boolean',
                    'required' => false,
                ],
                'expiredDate' => [
                    'name' => 'OnCore Expired Date',
                    'type' => 'text',
                    'required' => false,
                ],
                'approximateExpiredDate' => [
                    'name' => 'OnCore Approximate Expired Date',
                    'type' => 'boolean',
                    'required' => false,
                ],
                'lastDateKnownAlive' => [
                    'name' => 'OnCore Last date known alive',
                    'type' => 'text',
                    'required' => false,
                ],
                'ssn' => [
                    'name' => 'OnCore SSN',
                    'type' => 'text',
                    'required' => false,
                ],
                'gender' => [
                    'name' => 'OnCore Gender',
                    'type' => 'text',
                    'required' => false,
                ],
                'ethnicity' => [
                    'name' => 'OnCore Ethnicity',
                    'type' => 'text',
                    'required' => false,
                ],
                'race' => [
                    'name' => 'OnCore Race',
                    'type' => 'json',
                    'required' => false,
                ],
                'subjectComments' => [
                    'name' => 'OnCore SubjectComments',
                    'type' => 'text',
                    'required' => false,
                ],
                'additionalSubjectIds' => [
                    'name' => 'OnCore Additional Subject Ids',
                    'type' => 'json',
                    'required' => false,
                ],
                'streetAddress' => [
                    'name' => 'OnCore street Address',
                    'type' => 'text',
                    'required' => false,
                ],
                'addressLine2' => [
                    'name' => 'OnCore address Line2',
                    'type' => 'text',
                    'required' => false,
                ],
                'city' => [
                    'name' => 'OnCore City',
                    'type' => 'text',
                    'required' => false,
                ],
                'state' => [
                    'name' => 'OnCore State',
                    'type' => 'text',
                    'required' => false,
                ],
                'zip' => [
                    'name' => 'OnCore Zip Code',
                    'type' => 'text',
                    'required' => false,
                ],
                'county' => [
                    'name' => 'OnCore County',
                    'type' => 'text',
                    'required' => false,
                ],
                'country' => [
                    'name' => 'OnCore Country',
                    'type' => 'text',
                    'required' => false,
                ],
                'phoneNo' => [
                    'name' => 'OnCore Phone No',
                    'type' => 'text',
                    'required' => false,
                ],
                'alternatePhoneNo' => [
                    'name' => 'OnCore Alternate Phone No',
                    'type' => 'text',
                    'required' => false,
                ],
                'email' => [
                    'name' => 'OnCore Email',
                    'type' => 'text',
                    'required' => false,
                ]
            ],
            'special_keys' => [
                'label' => 'subject_demographics_id', // "name" represents the entity label.
            ],
        ];;

        // TODO redcap entity for redcap records not in OnCore

        // TODO redcap entity for OnCore records not in REDCap

        // TODO redcap entity to save the linkage between redcap and OnCore records

        return $types;
    }

    //ONCORE INTEGRATION/STATUS METHODS
    public function injectIntegrationUI($project_irb)
    {
        $field_map_url              = $this->getUrl("pages/field_map.php");
        $integration_jsmo           = $this->getUrl("assets/scripts/integration_jsmo.js");
        $notif_js                   = $this->getUrl("assets/scripts/notif_modal.js");
        $oncore_js                  = $this->getUrl("assets/scripts/oncore.js");
        $notif_css                  = $this->getUrl("assets/styles/notif_modal.css");
        $oncore_css                 = $this->getUrl("assets/styles/oncore.css");

        $protocols                  = $this->getProtocols()->getOnCoreProtocolsViaIRB($project_irb);
        $integrations               = $this->getOnCoreIntegrations();
        $last_adjudication          = $this->getSyncDiffSummary();

        //if no integrations, and only one protocol, pre-pull the protocol into the entity table
        $matching_library = false;
        if(count($protocols) == 1 && empty($integrations)) {
            $protocol           = current($protocols);
            $protocolId         = $protocol["protocolId"];
            $library            = $protocol["protocol"]["library"];

            $lib_names = array();
            foreach($this->getDefinedLibraries() as $lib){
                array_push($lib_names, $lib["library-name"]);
            }
//            $this->emDebug($protocol);
            if(in_array($library, $lib_names)){
                $matching_library   = true;
                $new_entity_record  = $this->getProtocols()->processCron($this->getProjectId(), $project_irb, $protocolId, $this->getDefinedLibraries());
                $integrations[$protocolId]  = $new_entity_record;
            }
        }

        //DATA TO INIT JSMO module
        $notifs_config = array(
            "field_map_url"             => $field_map_url,
            "oncore_protocols"          => $protocols,
            "oncore_integrations"       => $integrations,
            "has_oncore_integration"    => $this->hasOnCoreIntegration() ,
            "has_field_mappings"        => !empty($this->getMapping()->getProjectFieldMappings()['pull']) && !empty($this->getMapping()->getProjectFieldMappings()['push']) ? true : false ,
            "last_adjudication" => $last_adjudication,
            "matching_library" => $matching_library
        );

        $aa = ($this->escape(json_encode($notifs_config)));
        //Initialize JSMO
        $this->initializeJavascriptModuleObject();
        ?>
        <script src="<?= $oncore_js ?>" type="text/javascript"></script>
        <script src="<?= $notif_js ?>" type="text/javascript"></script>
        <script src="<?= $integration_jsmo ?>" type="text/javascript"></script>
        <script>
            $(function () {
                const module = <?=$this->getJavascriptModuleObjectName()?>;
                module.config = decode_object("<?=$this->escape(json_encode($notifs_config))?>");
                console.log(module.config)
                module.afterRender(<?=$this->getJavascriptModuleObjectName()?>.InitFunction);
            })
        </script>
        <link rel="stylesheet" href="<?= $oncore_css ?>">
        <link rel="stylesheet" href="<?= $notif_css ?>">
        <?php
    }

    /**
     * @return null
     */
    public function integrateOnCoreProject($entityId, $integrate = false)
    {
        $this->initiateProtocol();
        $setStatus = $integrate ? self::ONCORE_PROTOCOL_STATUS_YES : self::ONCORE_PROTOCOL_STATUS_NO;
        $this->getProtocols()->updateProtocolEntityRecordStatus($entityId, $setStatus);
        // if user is unlinking protocol delete all linkage records.
        if ($setStatus == self::ONCORE_PROTOCOL_STATUS_NO) {
            $this->deleteLinkageRecords($this->getProtocols()->getEntityRecord()['redcap_project_id'], $this->getProtocols()->getEntityRecord()['oncore_protocol_id']);
        }
        return $integrate;
    }

    /**
     * @return array
     *
     */
    public function getOnCoreProtocols(): array
    {
        if (!$this->oncore_protocols) {
            $this->initiateProtocol();
            $available_oncore_protocols = $this->getProtocols()->getOnCoreProtocol();
            $this->oncore_protocols = array();
            if (!empty($available_oncore_protocols)) {
                if ($this->isAssoc($available_oncore_protocols)) {
                    $available_oncore_protocols = array($available_oncore_protocols);
                }
            }
            foreach ($available_oncore_protocols as $oncore_project) {
                $this->oncore_protocols[$oncore_project["protocolId"]] = $oncore_project;
            }
        }
        return $this->oncore_protocols;
    }

    /**
     * @return array
     */
    public function getOnCoreIntegrations()
    {
        if (!$this->oncore_integrations) {
            $this->initiateProtocol();
            $entity_records = $this->getProtocols()->getEntityRecord();
            $this->oncore_integrations = array();
            if (!empty($entity_records)) {
                if ($this->isAssoc($entity_records)) {
                    $entity_records = array($entity_records);
                }
            }
            foreach ($entity_records as $oncore_integration) {
                $this->oncore_integrations[$oncore_integration["oncore_protocol_id"]] = $oncore_integration;
            }

            //Instantiate Mapping Class
            $this->setMapping(new Mapping($this));
        }

        return $this->oncore_integrations;
    }

    /**
     * @return bool
     */
    public function hasOnCoreIntegration()
    {
        if (!$this->has_oncore_integrations) {
            $this->has_oncore_integrations = false;
            $current_oncore_integrations = $this->getOnCoreIntegrations();
            foreach ($current_oncore_integrations as $protocol_id => $oncore_integration) {
                if ($oncore_integration["status"] == 2) {
                    $this->has_oncore_integrations = true;
                    break;
                }
            }
        }

        return $this->has_oncore_integrations;
    }

    /**
     * @return protocol array
     */
    public function getIntegratedProtocol(){
        $protocol = null;
        if($this->hasOnCoreIntegration()){
            $integrations = $this->getOnCoreIntegrations();
            foreach($integrations as $protocol_id => $integration){
                if($integration["status"] == 2){
                    $project_irb    = $integration["irb_number"];
                    $protocol_id    = $integration["oncore_protocol_id"];
                    $protocols      = $this->getProtocols()->getOnCoreProtocolsViaIRB($project_irb);
                    foreach($protocols as $p) {
                        if ($p["protocolId"] == $protocol_id) {
                            $protocol = $p;
                            break;
                        }
                    }
                    break;
                }
            }
        }

        return $protocol;
    }

    /**
     * @return date time Y-m-d H:i
     */
    public function formatTS($ts)
    {
        return date("Y-m-d H:i", $ts);
    }


    //DATA SYNC METHODS

    /**
     * @return fields_event array of redcap project fields and thier respective event id
     */
    public function redcapFieldEventIDMap()
    {
        $fields_event = array();
        if (\REDCap::isLongitudinal()) {
            $events = \REDCap::getEventNames(true);
        } else {
            $events = array($this->getFirstEventId() => $this->getFirstEventId());
        }
        if (!empty($events)) {
            foreach ($events as $event_id => $event) {
                $temp = \REDCap::getValidFieldsByEvents(PROJECT_ID, array($event));
                foreach ($temp as $field_name) {
                    $fields_event[$field_name] = $event_id;
                }
            }
        }
        return $fields_event;
    }

    /**
     * @return sync_diff
     */
    public function getSyncDiff($use_filter = null)
    {
        $this->initiateProtocol();
        //$this->getProtocols()->getSubjects()->setSyncedRecords($this->getProtocols()->getEntityRecord()['redcap_project_id'], $this->getProtocols()->getEntityRecord()['oncore_protocol_id']);

        //THIS MA
        $fields_event = $this->redcapFieldEventIDMap();


        $records = $this->getProtocols()->getSyncedRecords($use_filter);
        $mapped_fields = $this->getMapping()->getProjectFieldMappings();

        $sync_diff = array();
        $bin_match = array("excluded" => array(), "included" => array());
        $bin_partial = array("excluded" => array(), "included" => array());
        $bin_oncore = array("excluded" => array(), "included" => array());
        $bin_redcap = array("excluded" => array(), "included" => array());
        $bin_array = array("bin_redcap", "bin_oncore", "bin_match", "bin_partial");
        $exclude = array("mrn");

        foreach ($records as $record) {
            $link_status = $record["status"];
            $entity_id = $record["entity_id"];
            $excluded = $record["excluded"] ?? 0;


            $oncore = null;
            $redcap = null;

            $last_scan = null;
            $full = false;

            $oc_id = null;
            $oc_pr_id = null;
            $rc_id = null;
            $oc_data = null;
            $rc_data = null;
            $rc_field = null;
            $rc_event = null;
            $oc_status = null;

            switch ($link_status) {
                case OnCoreIntegration::FULL_MATCH:
                    //full
                    $full = true;

                case OnCoreIntegration::PARTIAL_MATCH:

                case OnCoreIntegration::ONCORE_ONLY:
                    //oncore only
                    $oncore = $record["oncore"];
                    $oc_id = $oncore["protocolId"];
                    $oc_pr_id = $oncore["protocolSubjectId"];
                    $oc_status = $oncore['status'];
                    $mrn = $oncore["demographics"]["mrn"];
                    $last_scan = date("Y-m-d H:i", $oncore["demographics"]["updated"]);

                case OnCoreIntegration::REDCAP_ONLY:
                    //redcap only
                    if (array_key_exists("redcap", $record)) {
                        // set the keys for redcap array
                        $arr = $record["redcap"];

                        $mrn_event_id = $fields_event[$this->getMapping()->getProjectFieldMappings()['pull']['mrn']['redcap_field']];
                        $mrn = $arr[$mrn_event_id][$this->getMapping()->getProjectFieldMappings()['pull']['mrn']['redcap_field']];

                        $primary_field_event_id = $fields_event[\REDCap::getRecordIdField()];
                        $rc_id = $arr[$primary_field_event_id][\REDCap::getRecordIdField()];

                        // edge case if redcap record is missing try pull it from main array.
                        if (!$rc_id or $rc_id == '') {
                            $rc_id = $record[\REDCap::getRecordIdField()];
                        }
                        foreach ($record['redcap'] as $key => $value) {
                            Entities::createLog('---');
                            Entities::createLog('Key: ' . $key);
                            if (is_array($value)) {
                                Entities::createLog(implode(',', $value));
                            } else {
                                Entities::createLog($value);
                            }

                            Entities::createLog('---');
                        }


                        // we are using pull fields to map redcap data
                        $temp = $this->getProtocols()->getSubjects()->prepareREDCapRecordForSync($rc_id, $this->getMapping()->getProjectFieldMappings()['push'], $this->getMapping()->getOnCoreFieldDefinitions());

                        // handle data scattered over multiple events
                        $redcap = [];
                        foreach ($temp as $onCoreField => $value) {
                            // Use redcap fields name instead of oncore to work with Irvin UI.
                            $redcapField = $this->getMapping()->getMappedRedcapField($onCoreField, true);

                            if ($redcapField) {
                                $redcap[$redcapField] = $value;
                            }
                        }
                    }

                default:
                    //partial
                    $mrn = $mrn . '_' . $entity_id ?: rand(1000, 9999);
                    $bin_var = $bin_array[$link_status];
                    $bin = $excluded ? $$bin_var["excluded"] : $$bin_var["included"];
                    if (!array_key_exists($mrn, $bin)) {
                        if ($excluded) {
                            $$bin_var["excluded"][$mrn] = array();
                        } else {
                            $$bin_var["included"][$mrn] = array();
                        }
                    }
                    $fields = $link_status == OnCoreIntegration::REDCAP_ONLY ? $mapped_fields["push"] : $mapped_fields["pull"];

                    foreach ($fields as $oncore_field => $redcap_details) {
                        $rc_field = $redcap_details["redcap_field"];
                        $rc_event = $redcap_details["event"];

                        $rc_data = $redcap && isset($redcap[$redcap_details["redcap_field"]]) ? $redcap[$redcap_details["redcap_field"]] : null;
                        $oc_data = $oncore && isset($oncore["demographics"][$oncore_field]) ? $oncore["demographics"][$oncore_field] : (isset($oncore[$oncore_field]) ? $oncore[$oncore_field] : null);

                        if (empty($rc_field) && !empty($redcap_details["default_value"])) {
                            $rc_data = $redcap_details["default_value"];
                        }
                        $temp = array(
                            "entity_id" => $entity_id
                        , "ts_last_scan" => $last_scan
                        , "oc_id" => $oc_id
                        , "oc_status" => $oc_status
                        , "oc_pr_id" => $oc_pr_id
                        , "rc_id" => $rc_id
                        , "oc_data" => $oc_data
                        , "rc_data" => $rc_data
                        , "oc_field" => $oncore_field
                        , "rc_field" => $rc_field
                        , "rc_event" => $rc_event
                        , "full" => $full
                        );
                        if ($excluded) {
                            array_push($$bin_var["excluded"][$mrn], $temp);
                        } else {
                            array_push($$bin_var["included"][$mrn], $temp);
                        }
                    }
            }

            if (!empty($bin_match) || !empty($bin_oncore) || !empty($bin_redcap) || !empty($bin_partial)) {
                $sync_diff = array(
                    "match" => $bin_match,
                    "oncore" => $bin_oncore,
                    "redcap" => $bin_redcap,
                    "partial" => $bin_partial
                );
            }
        }
//        $this->emDebug("sync diff redcap", $bin_redcap);
        return $sync_diff;

    }

    /**
     * @return array
     */
    public function getSyncDiffSummary()
    {
        $last_adjudication = $this->getProtocols()->getSyncedRecordsSummaries();
        return $last_adjudication;
    }

    /**
     * @return array
     */
    public function pullSync()
    {
        $this->initiateProtocol();
        $this->getProtocols()->syncRecords();
        return $this->getSyncDiffSummary();
    }

    /**
     * @return null
     */
    public function updateLinkage($entity_record_id, $data)
    {
        $this->initiateProtocol();
        $this->getProtocols()->getSubjects()->updateLinkageRecord($entity_record_id, $data);
        return;
    }




    //REFERENCES TO HELPER CLASSES

    /**
     * @param Mapping $mapping
     */
    public function setMapping(Mapping $mapping): void
    {
        $this->mapping = $mapping;
    }

    /**
     * @return Protocols
     */
    public function getMapping(): Mapping
    {
        if (!$this->mapping) {
            $this->setMapping(new Mapping($this));
        }
        return $this->mapping;
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
        if (!$this->protocols) {
            $this->initiateProtocol();
        }
        return $this->protocols;
    }

    /**
     * @param Protocols $protocols
     */
    public function setProtocols(Protocols $protocols): void
    {
        $this->protocols = $protocols;
    }


    //MISCELLANEOUS
    public static function getEventNameUniqueId($name)
    {
        $id = \REDCap::getEventIdFromUniqueEvent($name);
        // false means this project is not longitudinal
        if (!$id) {
            global $Proj;
            return $Proj->firstEventId;
        }
        return $id;
    }

    public function enableExternalModuleForREDCapProject($pid)
    {
        $prefix = $this->PREFIX;
        $sql = sprintf("SELECT * from redcap_external_module_settings WHERE project_id = %s AND `key` = 'enabled' AND external_module_id = (SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = '%s')", db_escape($pid), db_escape($prefix));
        $record = db_query($sql);
        if ($record->num_rows == 0) {
            //$sql = db_query("SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = '$prefix'");
            $sql = sprintf("SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = '%s'", db_escape($prefix));
            $q = db_query($sql);
            $em = db_fetch_assoc($q);
            $in_sql = sprintf("INSERT INTO redcap_external_module_settings VALUES (%s, %s, 'enabled', 'boolean', 'true')", db_escape($em['external_module_id']), db_escape($pid));
            db_query($in_sql);
            return true;
        } else {
            // either EM is enabled or disabled and no action needed.
            return false;
        }
    }

    public function onCoreProtocolsScanCron()
    {
        try {
            $projects = self::query("select project_id, project_irb_number from redcap_projects where project_irb_number is NOT NULL AND project_id NOT IN (select redcap_project_id from redcap_entity_oncore_protocols)", []);

            // manually set users to make guzzle calls.
            if (!$this->users) {
                $this->setUsers(new Users($this->getProjectId(), $this->PREFIX, $this->framework->getUser(), $this->getCSRFToken()));
            }

            while ($project = $projects->fetch_assoc()) {
                $id = $project['project_id'];
                $irb = $project['project_irb_number'];

                if (!$irb) {
                    continue;
                }

                // check if irb has a protocol in oncore.
                $response = $this->getUsers()->get('protocolManagementDetails?irbNo=' . $irb);

                if ($response->getStatusCode() < 300) {
                    $data = json_decode($response->getBody(), true);
                    if (empty($data)) {
                        // if no protocol do nothing
                        continue;
                    } else {
                        // enable oncore EM for that project. then run the cron.
                        $this->enableExternalModuleForREDCapProject($id);
                        $url = $this->getUrl("ajax/cron.php", true, true) . '&pid=' . $id . '&action=protocols';
                        $this->getUsers()->getGuzzleClient()->get($url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
                        $this->emDebug("running cron for $url on project " . $project['app_title']);
                    }
                }
            }

        } catch (\Exception $e) {
//            $this->emError($e->getMessage());
            \REDCap::logEvent('CRON JOB ERROR: ' . $e->getMessage());
            Entities::createException('CRON JOB ERROR: ' . $e->getMessage());

        }
    }

    /**
     * this cron will pull OnCore subjects for REDCap project with enabled auto-pull
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function onCoreAutoPullCron()
    {
        $projects = self::query("select project_id from redcap_external_module_settings where `key` = 'enable-auto-pull' AND `value` = 'true'", []);

        // manually set users to make guzzle calls.
        if (!$this->users) {
            $this->setUsers(new Users($this->getProjectId(), $this->PREFIX, $this->framework->getUser(), $this->getCSRFToken()));
        }

        while ($project = $projects->fetch_assoc()) {
            $id = $project['project_id'];
            $url = $this->getUrl("ajax/cron.php", true, true) . '&pid=' . $id . '&action=auto_pull';
            $this->getUsers()->getGuzzleClient()->get($url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
            $this->emDebug("running cron for $url on project " . $project['app_title']);
        }
    }

    public function onCoreProtocolsSubjectsScanCron()
    {
        try {
            $projects = self::query("select project_id, project_irb_number from redcap_projects where project_irb_number is NOT NULL", []);

            // manually set users to make guzzle calls.
            if (!$this->users) {
                $this->setUsers(new Users($this->getProjectId(), $this->PREFIX, $this->framework->getUser(), $this->getCSRFToken()));
            }

            while ($project = $projects->fetch_assoc()) {
                $id = $project['project_id'];
                $irb = $project['project_irb_number'];

                if (!$irb) {
                    continue;
                }

                // only link protocols
                $protocol = Protocols::getOnCoreProtocolEntityRecord($id);
                if (!empty($protocol) && $protocol['status'] == self::ONCORE_PROTOCOL_STATUS_YES) {
                    $url = $this->getUrl("ajax/cron.php", true) . '&pid=' . $id . '&action=redcap_only';
                    $this->getUsers()->getGuzzleClient()->get($url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
                    $this->emDebug("running cron for $url on project " . $project['app_title']);
                }

            }

        } catch (\Exception $e) {
//            $this->emError($e->getMessage());
            \REDCap::logEvent('CRON JOB ERROR: ' . $e->getMessage());
            Entities::createException('CRON JOB ERROR: ' . $e->getMessage());

        }
    }


    public function onCoreREDCapRecordsScanCron()
    {
        try {
            $projects = self::query("select project_id, project_irb_number from redcap_projects where project_irb_number is NOT NULL ", []);

            // manually set users to make guzzle calls.
            if (!$this->users) {
                $this->setUsers(new Users($this->getProjectId(), $this->PREFIX, $this->framework->getUser(), $this->getCSRFToken()));
            }

            while ($project = $projects->fetch_assoc()) {
                $id = $project['project_id'];
                $irb = $project['project_irb_number'];

                if (!$irb) {
                    continue;
                }
                $url = $this->getUrl("ajax/cron.php", true) . '&pid=' . $id . '&action=subjects';
                $this->getUsers()->getGuzzleClient()->get($url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
//                $this->emDebug("running cron for $url on project " . $project['app_title']);
                Entities::createLog("Cron URL $url for project " . $project['app_title']);
            }

        } catch (\Exception $e) {
//            $this->emError($e->getMessage());
            \REDCap::logEvent('CRON JOB ERROR: ' . $e->getMessage());
            Entities::createException('CRON JOB ERROR: ' . $e->getMessage());

        }
    }

    public function isAssoc(array $arr)
    {
        //check if array is associative or sequential
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * this method will get defined configuration libraries and set protocol corresponding library
     * @return void
     * @throws \Exception
     */
    private function setProtocolLibrary()
    {
        if ($this->getProtocols()->getEntityRecord()['status'] == OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES) {
            $libraries = $this->getDefinedLibraries();
            if (!isset($this->getProtocols()->getEntityRecord()['oncore_library'])) {
                throw new \Exception('No Library was found for this protocol');
            } elseif (empty($libraries)) {
                throw new \Exception('No Libraries defined for this REDCap Instance. Please Contact REDCap Admin.');
            } else {
                foreach ($libraries as $key => $library) {
                    if ($this->getProtocols()->getEntityRecord()['oncore_library'] == $key) {
                        $this->getUsers()->setOnCoreStudySites(self::getSubSettingsValuesAsArray($library['library-oncore-study-sites'], 'library-study-site'));
                        $this->getUsers()->setStatusesAllowedToPush(self::getSubSettingsValuesAsArray($library['library-oncore-protocol-statuses'], 'library-protocol-status'));
                        $this->getUsers()->setRolesAllowedToPush(self::getSubSettingsValuesAsArray($library['library-oncore-staff-roles'], 'library-staff-role'));
                        $this->getUsers()->setFieldsDefinition(json_decode($library['library-oncore-field-definition'], true));
                        break;
                    }
                }
                if (empty($this->getUsers()->getOnCoreStudySites())) {
                    throw new \Exception('No Study Sites defined for selected library');
                }
                if (empty($this->getUsers()->getStatusesAllowedToPush())) {
                    throw new \Exception('No Protocol statuses defined for selected library');
                }
                if (empty($this->getUsers()->getRolesAllowedToPush())) {
                    throw new \Exception('No Protocol Staff roles defined for selected library');
                }
            }
        }
    }

    public static function getSubSettingsValuesAsArray($subSettings, $key)
    {
        $result = [];
        foreach ($subSettings as $subSetting) {
            $result[] = $subSetting[$key];
        }
        return $result;
    }

    public function getProtocolsSummary()
    {
        $sql = sprintf("SELECT status, COUNT(id) as c from %s GROUP BY status;", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS));
        $q = db_query($sql);
        $result = [];
        $total = 0;
        while ($record = db_fetch_assoc($q)) {
            $result[$record['status']] = $record['c'];
            $total += $record['c'];
        }
        $result['total'] = $total;
        return $result;
    }

    public function getLogsSummary()
    {
        $sql = sprintf("SELECT type, COUNT(id) as c from %s GROUP BY type;", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_API_ACTIONS_LOG));
        $q = db_query($sql);
        $result = [];
        $total = 0;
        while ($record = db_fetch_assoc($q)) {
            // ignore general logs
            if (!$record['type']) {
                continue;
            }
            $result[$record['type']] = $record['c'];
            $total += $record['c'];
        }
        $result['total'] = $total;
        return $result;
    }

    /**
     * @return array
     */
    public function getDefinedLibraries()
    {
        return $this->getSubSettings('libraries', $this->getProjectId());
    }

    public function checkCustomErrorMessages($message)
    {
        $customErrorMessages = $this->getSubSettings('custom-error-messages', $this->getProjectId());
        foreach ($customErrorMessages as $customErrorMessage) {
            $aa = $customErrorMessage['oncore-error-message'];
            $bb = $message;
            $cc = $customErrorMessage['oncore-error-message'] == $message;
            $dd = strpos($customErrorMessage['oncore-error-message'], $message) !== false;
            if ($customErrorMessages['oncore-error-message'] == $message or strpos($customErrorMessage['oncore-error-message'], $message) !== false) {
                return $message . '<br>' . $customErrorMessage['extra-error-message'];
            }
        }
        return $message;
    }


    public function deleteLinkageRecords($redcapProjectId, $onCoreProtocolId)
    {
        if (!$redcapProjectId) {
            throw new \Exception('REDCap Project Id is missing');
        }

        if (!$onCoreProtocolId) {
            throw new \Exception('REDCap Project Id is missing');
        }

        $sql = sprintf("DELETE FROM %s WHERE redcap_project_id = %s AND oncore_protocol_id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE), db_escape($redcapProjectId), db_escape($onCoreProtocolId));
        $result = db_query($sql);
        if (!$result) {
            throw new \Exception(db_error());
        }
        return $result;
    }

    public function redcapCleanupEntityRecords()
    {
        $sql = sprintf("select  project_id, oncore_protocol_id from redcap_entity_oncore_protocols LEFT OUTER JOIN redcap_projects ON project_id = redcap_entity_oncore_protocols.redcap_project_id where redcap_projects.date_deleted is not null");
        $q = db_query($sql);


        while ($project = db_fetch_assoc($q)) {
            $redcap_project_id = $project['project_id'];
            $oncore_protocol_id = $project['oncore_protocol_id'];
            $this->deleteLinkageRecords($redcap_project_id, $oncore_protocol_id);

//            $url = $this->getUrl("ajax/cron.php", true) . '&pid=' . $id . '&action=clean_up';
//            $this->getUsers()->getGuzzleClient()->get($url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
//            Entities::createLog("running cron for $url on project " . $project['project_id']);
        }
    }

    /* AJAX HANDLING IN HERE INSTEAD OF A STAND ALONE AjaxHandler PAGE? */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        //        $this->emDebug(func_get_args());
        //        $this->emDebug("is redcap_module_ajax a reserved name?",
        //            $action,
        //            $payload,
        //            $project_id,
        //            $page,
        //            $page_full,
        //            $user_id
        //        );

        $return_o = array("success" => 0) ;

        try {
            if (isset($action)) {
                $action = htmlspecialchars($action);
                $result = null;
                $this->initiateProtocol();

                // actions exempt from allow to push
                $exemptActions = array('triggerIRBSweep');

                if (!$this->getProtocols()->getUser()->isOnCoreContactAllowedToPush() && !in_array($action, $exemptActions)) {
                    throw new \Exception('You do not have permissions to pull/push data from this protocol.');
                }

                switch ($action) {
                    case "getMappingHTML":
                        $result = $this->getMapping()->makeFieldMappingUI();
                        break;
                    case "saveSiteStudies":
                        $result = !empty($payload["site_studies_subset"]) ? filter_var_array($payload["site_studies_subset"], FILTER_SANITIZE_STRING) : null;
                        $this->getMapping()->setProjectSiteStudies($result);
                        break;
                    case "saveFilterLogic":
                        $result = !empty($payload["filter_logic_str"]) ? filter_var($payload["filter_logic_str"], FILTER_SANITIZE_STRING) : null;
                        $this->getMapping()->setOncoreConsentFilterLogic($result);
                        break;
                    case "saveMapping":
                        //Saves to em project settings
                        //MAKE THIS A MORE GRANULAR SAVE.  GET
                        $project_oncore_subset  = $this->getMapping()->getProjectOncoreSubset();
                        $current_mapping        = $this->getMapping()->getProjectMapping();
                        $result                 = !empty($payload["field_mappings"]) ? filter_var_array($payload["field_mappings"], FILTER_SANITIZE_STRING) : null;
                        $update_oppo            = !empty($payload["update_oppo"]) ? filter_var($payload["update_oppo"], FILTER_VALIDATE_BOOLEAN) : null;

                        $pull_mapping           = !empty($result["mapping"]) ? $result["mapping"] : null;
                        $oncore_field           = !empty($result["oncore_field"]) && $result["oncore_field"] !== "-99" ? $result["oncore_field"] : null;
                        $redcap_field           = !empty($result["redcap_field"]) && $result["redcap_field"] !== "-99" ? $result["redcap_field"] : null;
                        $eventname              = !empty($result["event"]) ? $result["event"] : null;
                        $ftype                  = !empty($result["field_type"]) ? $result["field_type"] : null;
                        $vmap                   = !empty($result["value_mapping"]) ? $result["value_mapping"] : null;
                        $use_default            = !empty($result["use_default"]);
                        $default_value          = !empty($result["default_value"]) ? $result["default_value"] : null;
                        $birthDateNotAvailable  = false;

                        //$pull_mapping tells me the actual click (pull or push side)... doing the opposite side is more just a convenience..
                        if($pull_mapping == "pull"){
                            $rc_mapping = 0;
                            //pull side
                            if(!$redcap_field){
                                unset($current_mapping[$pull_mapping][$oncore_field]);
                            }else{
                                if(!$vmap && $update_oppo){
                                    //if its just a one to one mapping, then just go ahead and map the other direction
                                    $current_mapping["push"][$oncore_field] = array(
                                        "redcap_field" => $redcap_field,
                                        "event" => $eventname,
                                        "field_type" => $ftype,
                                        "default_value" => $default_value,
                                        "value_mapping" => $vmap
                                    );
                                }

                                $current_mapping[$pull_mapping][$oncore_field] = array(
                                    "redcap_field" => $redcap_field,
                                    "event" => $eventname,
                                    "field_type" => $ftype,
                                    "default_value" => $default_value,
                                    "value_mapping" => $vmap
                                );
                            }
                        }else{
                            $rc_mapping = 1;
                            //push side
                            if(!$redcap_field){
                                unset($current_mapping[$pull_mapping][$oncore_field]);
                                if($use_default){
                                    if($oncore_field == "birthDate"){
                                        $birthDateNotAvailable = true;
                                        $default_value = "birthDateNotAvailable";
                                    }

                                    $current_mapping[$pull_mapping][$oncore_field] = array(
                                        "redcap_field"  => $redcap_field,
                                        "event"         => $eventname,
                                        "field_type"    => $ftype,
                                        "value_mapping" => $vmap,
                                        "default_value" => $default_value
                                    );

                                    if($birthDateNotAvailable){
                                        $current_mapping[$pull_mapping][$oncore_field]["birthDateNotAvailable"] = true;
                                    }
                                }
                            }else{
                                if(!$vmap && in_array($oncore_field, $project_oncore_subset) && $update_oppo){
                                    //if its just a one to one mapping, then just go ahead and map the other direction
                                    $current_mapping["pull"][$oncore_field] = array(
                                        "redcap_field"  => $redcap_field,
                                        "event"         => $eventname,
                                        "field_type"    => $ftype,
                                        "value_mapping" => $vmap
                                    );
                                }

                                $current_mapping[$pull_mapping][$oncore_field] = array(
                                    "redcap_field"  => $redcap_field,
                                    "event"         => $eventname,
                                    "field_type"    => $ftype,
                                    "value_mapping" => $vmap
                                );
                            }
                        }
                        $this->emDebug("current mapping", $current_mapping[$pull_mapping]);
                        $this->getMapping()->setProjectFieldMappings($current_mapping);
                    case "checkPushPullS tatus":
                        if(!isset($oncore_field)){
                            $oncore_field   = filter_var($payload["oncore_field"], FILTER_SANITIZE_STRING) ;
                        }
                        $oncore_field   = htmlspecialchars($oncore_field);
                        $oncore_field   = $oncore_field ?: null;
                        $indy_push_pull = $this->getMapping()->calculatePushPullStatus($oncore_field);
                    case "checkOverallStatus":
                        if(!isset($indy_push_pull)){
                            $indy_push_pull = array("pull"=>null,"push"=>null);
                        }
                        $pull           = $this->getMapping()->getOverallPullStatus();
                        $push           = $this->getMapping()->getOverallPushStatus();
                        $pp_result      = array_merge(array("overallPull" => $pull, "overallPush" => $push), $indy_push_pull);
                    case "getValueMappingUI":
                        if(!isset($redcap_field)){
                            $redcap_field   = filter_var($payload["redcap_field"], FILTER_SANITIZE_STRING) ;
                        }
                        $redcap_field = htmlspecialchars($redcap_field);
                        $redcap_field = $redcap_field ?: null;

                        if(!isset($oncore_field)){
                            $oncore_field   = filter_var($payload["oncore_field"], FILTER_SANITIZE_STRING) ;
                        }
                        $oncore_field = htmlspecialchars($oncore_field);
                        $oncore_field = $oncore_field ?: null;

                        if(!isset($rc_mapping)){
                            $rc_mapping     = filter_var($payload["rc_mapping"], FILTER_SANITIZE_NUMBER_INT) ;
                        }
                        $rc_mapping = htmlspecialchars($rc_mapping);
                        $rc_mapping = $rc_mapping ?: null;

                        $rc_obj     = $this->getMapping()->getRedcapValueSet($redcap_field);
                        $oc_obj     = $this->getMapping()->getOncoreValueSet($oncore_field);

                        if($use_default) {
                            $res = $this->getMapping()->makeValueMappingUI_UseDefault($oncore_field, $default_value);
                        }elseif(!empty($rc_obj) || !empty($oc_obj)){
                            if ($rc_mapping) {
                                $res = $this->getMapping()->makeValueMappingUI_RC($oncore_field, $redcap_field);
                            } else {
                                $res = $this->getMapping()->makeValueMappingUI($oncore_field, $redcap_field);
                            }
                        }else{
                            $res = array("html" => null);
                        }

                        $result = array_merge( array("html" => $res["html"]), $pp_result) ;
                        break;
                    case "deleteMapping":
                        //DELETE ENTIRE MAPPING FOR PUSH Or PULL
                        $current_mapping    = $this->getMapping()->getProjectMapping();
                        $push_pull          = !empty($payload["push_pull"]) ? filter_var($payload["push_pull"], FILTER_SANITIZE_STRING) : null;

                        if($push_pull){
                            $current_mapping[$push_pull] = array();

                            if($push_pull == "pull"){
                                //empty it
                                $this->getMapping()->setProjectOncoreSubset(array());
                            }
                        }

                        $result = $this->getMapping()->setProjectFieldMappings($current_mapping);
                        break;
                    case "deletePullField":
                        //DELETE ENTIRE MAPPING FOR PUSH Or PULL
                        $current_mapping        = $this->getMapping()->getProjectMapping();
                        $pull_field             = !empty($payload["oncore_prop"]) ? filter_var($payload["oncore_prop"], FILTER_SANITIZE_STRING) : null;

                        //REMOVE FROM PULL SUBSET
                        $project_oncore_subset  = $this->getMapping()->getProjectOncoreSubset();
                        $unset_idx = array_search($pull_field, $project_oncore_subset);
                        unset($project_oncore_subset[$unset_idx]);
                        $this->getMapping()->setProjectOncoreSubset($project_oncore_subset);

                        if(array_key_exists($pull_field, $current_mapping["pull"]) ){
                            //REMOVE FROM MAPPING
                            unset($current_mapping["pull"][$pull_field]);
                        }

//                $this->emDebug("new mapping less $pull_field", $current_mapping["pull"], $project_oncore_subset);
                        $result = $this->getMapping()->setProjectFieldMappings($current_mapping);

                        $pull           = $this->getMapping()->getOverallPullStatus();
                        $push           = $this->getMapping()->getOverallPushStatus();
                        $result         = array("overallPull" => $pull, "overallPush" => $push);
                        break;
                    case "saveOncoreSubset":
                        $oncore_prop    = !empty($payload["oncore_prop"]) ? filter_var($payload["oncore_prop"], FILTER_SANITIZE_STRING) : array();
                        $subtract       = !empty($payload["subtract"]) ? filter_var($payload["subtract"], FILTER_SANITIZE_NUMBER_INT) : 0;

                        $project_oncore_subset  = $this->getMapping()->getProjectOncoreSubset();

                        if($subtract){
                            $unset_idx = array_search($oncore_prop, $project_oncore_subset);
                            unset($project_oncore_subset[$unset_idx]);
                        }else{
                            if(!in_array($oncore_prop,$project_oncore_subset)) {
                                array_push($project_oncore_subset, $oncore_prop);
                            }
                        }
                        $this->getMapping()->setProjectOncoreSubset($project_oncore_subset);

                        $result = $this->getMapping()->makeFieldMappingUI();
                        break;
                    case "savePushPullPref":
                        $result = !empty($payload["pushpull_pref"]) ? filter_var_array($payload["pushpull_pref"], FILTER_SANITIZE_STRING) : array();
                        $this->getMapping()->setProjectPushPullPref($result);
                        break;
                    case "syncDiff":
                        //returns sync summary
                        $result = $this->pullSync();
                        break;
                    case "getSyncDiff":
                        $bin = htmlspecialchars($payload["bin"]);
                        $use_filter = htmlspecialchars($payload["filter"]);

                        $bin = $bin ?: null;
                        $sync_diff = $this->getSyncDiff($use_filter);

                        $result = array("included" => "", "excluded" => "", "footer_action" => "", "show_all" => "");
                        if ($bin == "partial") {
                            $included = $this->getMapping()->makeSyncTableHTML($sync_diff["partial"]["included"]);
                            $excluded = $this->getMapping()->makeSyncTableHTML($sync_diff["partial"]["excluded"], null, "disabled", true);
                        } elseif ($bin == "redcap") {
                            $included = $this->getMapping()->makeRedcapTableHTML($sync_diff["redcap"]["included"]);
                            $excluded = $this->getMapping()->makeRedcapTableHTML($sync_diff["redcap"]["excluded"], null, "disabled", true);
                        } elseif ($bin == "oncore") {
                            $included = $this->getMapping()->makeOncoreTableHTML($sync_diff["oncore"]["included"], false);
                            $excluded = $this->getMapping()->makeOncoreTableHTML($sync_diff["oncore"]["excluded"], false, "disabled", true);
                        }

                        $result["included"]         = $included["html"] ?: "";
                        $result["excluded"]         = $excluded["html"] ?: "";
                        $result["footer_action"]    = $included["footer_action"] ?: "";
                        $result["show_all"]         = $included["show_all"] ?: "";
                        break;
                    case "approveSync":
                        $temp = !empty($payload["record"]) ? filter_var_array($payload["record"], FILTER_SANITIZE_STRING) : null;
                        $mrn = $temp['mrn'];
                        unset($temp["mrn"]);
                        $id     = $temp["oncore"];
                        $res    = $this->getProtocols()->pullOnCoreRecordsIntoREDCap($temp);
                        if(is_array($res)){
                            $result = array("mrn" => $mrn, "id" => $res["id"], 'message' => 'Record synced successfully!');
                        }
                        break;
                    case "pushToOncore":
                        $record = filter_var_array($payload["record"]);
                        $record = $record ?: null;
                        $this->emDebug("push to oncore approved ids(redcap?)", $record);
                        if (!$record["value"] || $record["value"] == '') {
                            throw new \Exception('REDCap Record ID is missing.');
                        }

                        $rc_id  = $id = $record["value"];
                        $temp   = $this->getProtocols()->pushREDCapRecordToOnCore($rc_id, $this->getMapping()->getOnCoreFieldDefinitions());
                        if (is_array($temp)) {
                            $result = array('id' => $rc_id, 'status' => 'success', 'message' => $temp['message']);
                        }
                        break;
                    case "excludeSubject":
                        //flips excludes flag on entitry record
                        $entity_record_id = htmlentities($payload["entity_record_id"], ENT_QUOTES);
                        $result = $entity_record_id ?: null;
                        if ($result) {
                            $this->updateLinkage($result, array("excluded" => 1));
                        }
                        break;
                    case "includeSubject":
                        //flips excludes flag on entitry record
                        $entity_record_id = htmlentities($payload["entity_record_id"], ENT_QUOTES);
                        $result = $entity_record_id ?: null;
                        if ($result) {
                            $this->updateLinkage($result, array("excluded" => 0));
                        }
                        break;

                    case "approveIntegrateOncore":
                        //integrate oncore project(s)!!
                        $entity_record_id   = !empty($payload["entity_record_id"]) ? filter_var($payload["entity_record_id"], FILTER_SANITIZE_NUMBER_INT) : null;
                        $integrate          = !empty($payload["integrate"]) ? filter_var($payload["integrate"], FILTER_SANITIZE_NUMBER_INT) : null;
                        $result             = $this->integrateOnCoreProject($entity_record_id, $integrate);
                        break;

                    case "integrateOnCore":
                        if (isset($payload['irb']) && $payload['irb'] != '' && isset($payload['oncore_protocol_id']) && $payload['oncore_protocol_id'] != '') {
                            $irb                = htmlspecialchars($payload['irb']);
                            $oncoreProtocolId   = htmlspecialchars($payload['oncore_protocol_id']);
                            $new_entity_record  = $this->getProtocols()->processCron($this->getProjectId(), $irb, $oncoreProtocolId, $this->getDefinedLibraries());
                            $result             = $new_entity_record;
                        }
                        break;
                }
                $return_o["success"] = 1;
                $result     = json_encode($result, JSON_THROW_ON_ERROR);
            }
        } catch (\LogicException|ClientException|GuzzleException $e) {
            if (method_exists($e, 'getResponse')) {
                $response = $e->getResponse();
                $responseBodyAsString = json_decode($response->getBody()->getContents(), true);
                $responseBodyAsString['message'] = $responseBodyAsString['field'] . ': ' . $responseBodyAsString['message'];
            } else {
                $responseBodyAsString = array();
                $responseBodyAsString['message'] = $e->getMessage();
            }

            Entities::createException($responseBodyAsString['message']);
            // add redcap record id!
            if ($id) {
                $responseBodyAsString['id'] = $id;
            }
            $result     = json_encode($responseBodyAsString, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            Entities::createException($e->getMessage());
            $result     = json_encode(array('status' => 'error', 'message' => $e->getMessage(), 'id' => $id), JSON_THROW_ON_ERROR);
        }

        $return_o["result"] = $result;

        // Return is left as php object, is converted automatically
        return $return_o;
    }
}
