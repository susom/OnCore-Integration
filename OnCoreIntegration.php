<?php

namespace Stanford\OnCoreIntegration;

require_once "emLoggerTrait.php";
require_once 'classes/Users.php';
require_once 'classes/Entities.php';
require_once 'classes/Protocols.php';
require_once 'classes/Subjects.php';

/**
 * Class OnCoreIntegration
 * @package Stanford\OnCoreIntegration
 * @property \Stanford\OnCoreIntegration\Users $users;
 * @property \Stanford\OnCoreIntegration\Protocols $protocols;
 */
class OnCoreIntegration extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

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

    const RECORD_ON_REDCAP_BUT_NOT_ON_ONCORE = 0;

    const RECORD_NOT_ON_REDCAP_BUT_ON_ONCORE = 1;

    const RECORD_ON_REDCAP_ON_ONCORE_FULL_MATCH = 2;

    const RECORD_ON_REDCAP_ON_ONCORE_PARTIAL_MATCH = 3;

    const REDCAP_ONCORE_FIELDS_MAPPING_NAME = 'redcap-oncore-fields-mapping';

    public static $ONCORE_DEMOGRAPHICS_FIELDS = array("subjectDemographicsId",
        "subjectSource",
        "mrn",
        "lastName",
        "firstName",
        "middleName",
        "suffix",
        "birthDate",
        "approximateBirthDate",
        "birthDateNotAvailable",
        "expiredDate",
        "approximateExpiredDate",
        "lastDateKnownAlive",
        "ssn",
        "gender",
        "ethnicity",
        "race",
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
            //TODO THIS BREAKS
//            $this->setUsers(new Users($this->PREFIX));
        }
        // Other code to run when object is instantiated
    }

    public function redcap_every_page_top()
    {
        try {
            // in case we are loading record homepage load its the record children if existed
            preg_match('/redcap_v[\d\.].*\/index\.php/m', $_SERVER['SCRIPT_NAME'], $matches, PREG_OFFSET_CAPTURE);
            if (strpos($_SERVER['SCRIPT_NAME'], 'ProjectSetup') !== false || !empty($matches)) {
                $this->injectIntegrationUI();
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
                'redcap_event_id' => [
                    'name' => 'REDCap Event Id',
                    'type' => 'integer',
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

        $types['oncore_redcap_records_linkage'] = [
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
                    'type' => 'text',
                    'required' => false,
                ],
                'status' => [
                    'name' => 'Linkage Status',
                    'type' => 'integer',
                    'required' => true,
                    'default' => 0,
                    'choices' => [
                        self::RECORD_ON_REDCAP_BUT_NOT_ON_ONCORE => 'RECORD_ON_REDCAP_BUT_NOT_ON_ONCORE',
                        self::RECORD_NOT_ON_REDCAP_BUT_ON_ONCORE => 'RECORD_NOT_ON_REDCAP_BUT_ON_ONCORE',
                        self::RECORD_ON_REDCAP_ON_ONCORE_FULL_MATCH => 'RECORD_ON_REDCAP_ON_ONCORE_FULL_MATCH',
                        self::RECORD_ON_REDCAP_ON_ONCORE_PARTIAL_MATCH => 'RECORD_ON_REDCAP_ON_ONCORE_PARTIAL_MATCH',
                    ],
                ],
            ],
            'special_keys' => [
                'label' => 'redcap_project_id', // "name" represents the entity label.
            ],
        ];;

        $types['oncore_subjects'] = [
            'label' => 'OnCore Subject',
            'label_plural' => 'OnCore Subjects',
            'icon' => 'home_pencil',
            'properties' => [
                'subjectDemographicsId' => [
                    'name' => 'OnCore Subject Demographics Id',
                    'type' => 'integer',
                    'required' => true,
                ],
                'subjectSource' => [
                    'name' => 'Subject Source',
                    'type' => 'text',
                    'required' => true,
                ],
                'mrn' => [
                    'name' => 'OnCore MRN',
                    'type' => 'text',
                    'required' => true,
                ],
                'lastName' => [
                    'name' => 'OnCore Lastname',
                    'type' => 'text',
                    'required' => true,
                ],
                'firstName' => [
                    'name' => 'OnCore Firstname',
                    'type' => 'text',
                    'required' => true,
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
                    'type' => 'date',
                    'required' => false,
                ],
                'approximateExpiredDate' => [
                    'name' => 'OnCore Approximate Expired Date',
                    'type' => 'boolean',
                    'required' => false,
                ],
                'lastDateKnownAlive' => [
                    'name' => 'OnCore Last date known alive',
                    'type' => 'date',
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
                    'required' => true,
                ],
                'ethnicity' => [
                    'name' => 'OnCore Ethnicity',
                    'type' => 'text',
                    'required' => true,
                ],
                'race' => [
                    'name' => 'OnCore Race',
                    'type' => 'json',
                    'required' => true,
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

    public function injectIntegrationUI()
    {
        ?>
        <script>
            //  this over document.ready because we need this last!
            $(window).on('load', function () {
                // need to check for the redcapRandomizeBtn, already done ones wont have it
                if ($("#setupChklist-modify_project").length) {
                    var new_section = $("#setupChklist-modules").clone();
                    new_section.find(".chklist_comp").remove();
                    new_section.find(".chklisthdr span").text("OnCore Integration");
                    new_section.find(".chklisttext").empty();

                    $("#setupChklist-modify_project").after(new_section);

                    var modify_button = $("#setupChklist-modify_project").find("button:contains('Modify')");
                    var integration_ui = $("<div>");
                    var iu_label = $("<label>");

                    var iu_check = $("<input>").attr("name", "");
                    var iu_text = $("<span>").text();

                    iu_label.append(iu_check);
                    iu_label.append(iu_text);
                    integration_ui.append(iu_label);

                    new_section.find(".chklisttext").append(integration_ui);


                    return;

                    // ADD NEW BUTTON OR ENTIRELY NEW UI
                    var clone_or_show = $("#randomizationFieldHtml");
                    clone_or_show.addClass("custom_override")

                    // EXISTING UI ALREADY AVAILABLE, REVEAL AND AUGMENT
                    var custom_label = $("<h6>").addClass("custom_label").addClass("mt-2").text("Manually override and set randomization variable as:");
                    clone_or_show.prepend(custom_label);


                    var custom_hidden = $("<input>").attr("type", "hidden").prop("name", "randomizer_overide").val(true);
                    clone_or_show.prepend(custom_hidden);

                    var custom_reason = $("<input>").attr("type", "text").attr("name", "custom_override_reason").prop("placeholder", "reason for using overide?").addClass("custom_reason");
                    clone_or_show.append(custom_reason);

                    // var custom_or 		= $("<small>").addClass("custom_or").text("*Manually override and set randomization variable as:");
                    // clone_or_show.prepend(custom_or);

                    var custom_note = $("<small>").addClass("custom_note").text("*Press save to continue");
                    clone_or_show.append(custom_note);


                    //ONLY ENABLE MANUAL IF STRATA ARE ALL FILLED
                    var source_fields = <?= json_encode($this->source_fields) ?>;
                    var show_overide = $("<button>").addClass("jqbuttonmed ui-button ui-corner-all ui-widget btn-danger custom_btn").text("Manual Selection").click(function (e) {
                        e.preventDefault();

                        if (clone_or_show.is(":visible")) {
                            $("#redcapRandomizeBtn").prop("disabled", false);
                        } else {
                            $("#redcapRandomizeBtn").prop("disabled", true);
                        }

                        clone_or_show.toggle();


                        checkStrataComplete(source_fields, clone_or_show);

                        // $(this).prop("disabled",true);
                    });

                    $("#redcapRandomizeBtn").after(clone_or_show);
                    $("#redcapRandomizeBtn").after(show_overide);
                }
            });
        </script>
        <style>

        </style>
        <?php
    }

    public function redcap_module_link_check_display($project_id, $link)
    {
        //field_map , sync_diff
        //$link_url = $link["url"];

        //TODO EM WILL BE GLOBAL, AND ONLY PROJECTS THAT CHOOSE TO INTEGRATE
        //TODO WILL WE NEED CONDITIONAL DISPLAY OF EM LINKS
        if ($this->hasOnCoreIntegration()) {
            return $link;
        }
    }

    /**
     * @return bool
     */
    public function hasOnCoreIntegration()
    {
        return true;
    }

    public function getProjectFieldMappings()
    {
        $results = array();
        $mappings = $this->getProjectSetting(self::FIELD_MAPPINGS);
        if (!empty($mappings)) {
            $results = json_decode($mappings, true);
        }
        return $results;
    }


    /**
     * @return field_list
     */
    public function getOnCoreFields()
    {
        $field_list = array("MRN", "race", "birthdate", "sex", "fname", "lname");
        return $field_list;
    }

    /**
     * @return field_list
     */
    public function getProjectFields()
    {
        $field_list = array();
        $dict = \REDCap::getDataDictionary(PROJECT_ID, "array");
        $field_list = array_keys($dict);

        return $field_list;
    }

    /**
     * @return sync_diff
     */
    public function getSyncDiff()
    {
        $sync_diff = array();

        return $sync_diff;
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

                $protocols = $this->getProtocols()->searchOnCoreProtocolsViaIRB($irb);

                if (!empty($protocols)) {
                    $entity_oncore_protocol = $this->getProtocols()->getProtocolEntityRecord($id, $irb);
                    if (empty($entity_oncore_protocol)) {
                        foreach ($protocols as $protocol) {
                            $data = array(
                                'redcap_project_id' => $id,
                                'irb_number' => $irb,
                                'oncore_protocol_id' => $protocol['protocolId'],
                                // cron will save the first event. and when connect is approved the redcap user has to confirm the event id.
                                'redcap_event_id' => $this->getFirstEventId(),
                                'status' => '0',
                                'last_date_scanned' => time()
                            );

                            $entity = $this->getProtocols()->create(self::ONCORE_PROTOCOLS, $data);

                            if ($entity) {
                                Entities::createLog(' : OnCore Protocol record created for IRB: ' . $irb . '.');
                            } else {
                                throw new \Exception(implode(',', $this->getProtocols()->errors));
                            }
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
