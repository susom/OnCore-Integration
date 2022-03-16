<?php

namespace Stanford\OnCoreIntegration;

require_once "emLoggerTrait.php";
require_once 'classes/Users.php';
require_once 'classes/Entities.php';
require_once 'classes/Protocols.php';
require_once 'classes/Subjects.php';
require_once 'classes/Projects.php';
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

    const ONCORE_PROTOCOL_STATUS_NO = 0;

    const ONCORE_PROTOCOL_STATUS_PENDING = 1;

    const ONCORE_PROTOCOL_STATUS_YES = 2;

    const YES = 1;

    const NO = 0;

    public static $ONCORE_DEMOGRAPHICS_FIELDS = array(
        "subjectSource",
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
            $this->setUsers(new Users($this->PREFIX, $this->framework->getUser(), ''));
            $this->setProtocols(new Protocols($this->getUsers(), $this->getProjectId()));
        }
        // Other code to run when object is instantiated

    }

    public function redcap_module_system_enable($version) {
        $enabled = $this->isModuleEnabled('redcap_entity');
        if (!$enabled) {
            // TODO: what to do when redcap_entity is not enabled in the system
            $this->emError("Cannot use this module OncoreIntegration because it is dependent on the REDCap Entities EM");
        }
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
        ];

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
                    'type' => 'integer',
                    'required' => false,
                ],
                'oncore_protocol_subject_id' => [
                    'name' => 'OnCore Protocol Subject Id(NOT Demographics)',
                    'type' => 'integer',
                    'required' => false,
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

        // REDCap entity to store allowed subject demographics
        $types['oncore_demo_options'] = [
                'label' => 'OnCore Allowed Subject Demographics Options',
                'label_plural' => 'OnCore Allowed Subject Demographics Options',
                'icon' => 'home_pencil',
                'properties' => [
                        'oncore_demo_field' => [
                            'name' => 'OnCore Demographics Field',
                            'type' => 'text',
                            'required' => true,
                            'choices'   => [
                                'gender'    => 'Gender',
                                'race'      => 'Race',
                                'ethnicity' => 'Ethnicity',
                                'state'     => 'State',
                                'country'   => 'Country',
                                'id_type'   => 'Identifier Type'
                            ]
                        ],
                        'oncore_demo_option' => [
                            'name' => 'OnCore Allowed Value',
                            'type' => 'text',
                            'required' => true
                        ]
                ]
        ];


        return $types;
    }

    public function redcap_module_link_check_display($project_id, $link)
    {
        if($this->hasOnCoreIntegration()){
            return $link;
        }
    }

    public function injectIntegrationUI()
    {

//        $field_map_url = $this->getUrl("pages/field_map.php");
        $ajax_endpoint = $this->getUrl("ajax/handler.php");
        ?>
        <script>
        var has_oncore_project  = <?=json_encode($this->hasOnCoreProject()); ?>;
        var oncore_integrated   = <?=json_encode($this->hasOnCoreIntegration()); ?>;
        var has_field_mappings  = <?=json_encode(!empty($this->getProjectFieldMappings())); ?>;
        var last_adjudication   = <?=json_encode($this->getSyncDiff()); ?>;
        var ajax_endpoint       = "<?=$ajax_endpoint?>";
        var field_map_url       = "<?=$field_map_url?>";

        var make_oncore_module  = function(){
            if($("#setupChklist-modify_project").length){
                //BORROW HTML FROM EXISTING UI ON SET UP PAGE
                var new_section = $("#setupChklist-modules").clone();
                new_section.attr("id","integrateOnCore-modules");
                new_section.find(".chklist_comp").remove();
                new_section.find(".chklisthdr span").text("OnCore Project Integration");
                new_section.find(".chklisttext").empty();

                $("#setupChklist-modify_project").after(new_section);
                var content_bdy = new_section.find(".chklisttext");
                var lead        = $("<span>");
                content_bdy.append(lead);

                //IF ONCORE HAS BEEN INTEGATED WITH THIS PROJECT, THEN DISPLAY SUMMARY OF LAST ADJUDICATION
                if(has_field_mappings){
                    var lead_class  = "oncore_results";
                    var lead_text   = "Results summary from last adjudication : ";
                    lead_text += "<ul class='summary_oncore_adjudication'>";
                    lead_text += "<li>Full Match : "+last_adjudication["match"].length +" records</li>";
                    lead_text += "<li>Partial Oncore : "+last_adjudication["oncore"].length +" records</li>";
                    lead_text += "<li>Partial REDCap : "+last_adjudication["redcap"].length +" records</li>";
                    lead_text += "</ul>";
                }else{
                    var lead_class = "oncore_mapping";
                    var lead_text = "Please <a href='"+field_map_url+"'>Click Here</a> to map OnCore fields to this project.";
                }

                lead.addClass(lead_class);
                lead.html(lead_text);
            }
        };

        //  this over document.ready because we need this last!
		$(window).on('load', function () {
            var integrate_text      = $("<span>").addClass("enable_oncore").text("Integrate with OnCore Project #" + has_oncore_project);
            var enable_text         = "Enable&nbsp;";
            var integrated_class    = "not_integrated";

			if(oncore_integrated){
                enable_text         = "Enabled";
                integrated_class    = "integrated";

                //BORROW UI FROM OTHER ELEMENT TO ADD A NEW MODULE TO PROJECT SETUP
                make_oncore_module();
            }

            if(has_oncore_project){
                if($("#setupChklist-modify_project button:contains('Modify project title, purpose, etc.')").length){
                    //ADD LINE TO MAIN PROJECT SEETTINGS IF THERE IS POSSIBLE ONCORE INTEGRATION

                    var new_line    = $("<div>").addClass(integrated_class).attr("style","text-indent:-75px;margin-left:75px;padding:2px 0;font-size:13px;");
                    var button      = $("<button>").attr("id","integrate_oncore").addClass("btn btn-defaultrc btn-xs fs11").html(enable_text);
                    new_line.append(button);
                    button.after(integrate_text);

                    if(integrated_class == "integrated"){
                        button.attr("disabled","disabled");
                    }
                    $("#setupChklist-modify_project button:contains('Modify project title, purpose, etc.')").before(new_line);
                }
            }

            //INTEGRATE AJAX
            $("#integrate_oncore").on("click",function(e){
                e.preventDefault();

                //LINKAGE AJAX
                $.ajax({
                    url : ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action" : "integrateOnCore"
                    },
                    dataType: 'json'
                }).done(function (oncore_integrated) {
                    document.location.reload();
                }).fail(function (e) {
                    console.log("failed to integrate", e);
                });
            });
        });
		</script>
        <style>
            .not_integrated{
                color:#800000;
            }
            .integrated{
                color:green;
            }
            .enable_oncore {
                margin-left:5px;
            }
            .oncore_mapping{
                color:#9b5111
            }
            .oncore_results{
                color:#0098db
            }
            .summary_oncore_adjudication{
                list-style:none;
                margin:0; padding:0;
            }
            .summary_oncore_adjudication li { display:inline-block; }
            .summary_oncore_adjudication li:after{
                content:"|";
                margin:0 5px;
            }
            .summary_oncore_adjudication li:last-child:after{
                content:"";
                margin:initial;
            }
        </style>
		<?php
    }


    /**
     * @return int ?
     */
    public function integrateOnCoreProject()
    {
        //TODO THIS ISNT SETTING IT??
        //TODO PROBABLY ALREADY EXISTS IN IHAB's CLASSES
        $entity = $this->getProtocols()->getEntityRecord();
//        $this->getProtocolEntityRecord($redcapProjectId);
//        $oncore_project_id = $this->hasOnCoreProject();
        return $entity['oncore_protocol_id'];
    }

    /**
     * @return array
     */
    public function hasOnCoreProject(): array
    {
//        return array(654321);

        //TODO THIS IS STILL BREAKING?
        if ($this->getProtocols()->getOnCoreProtocol()) {
            return $this->getProtocols()->getOnCoreProtocol();
        } else {
            return [];
        }
    }

    /**
     * @return array
     */
    public function hasOnCoreIntegration()
    {
//        return 123456;

        //TODO THIS STILL NOT WORKING?
        return $this->getProtocols()->getEntityRecord();
    }

    /**
     * @return array
     */
    public function getProjectFieldMappings()
    {
        $results = array();
//        $mappings = $this->getProjectSetting(self::FIELD_MAPPINGS);
//        if (!empty($mappings)) {
//            $results = json_decode($mappings, true);
//        }

        //TODO THIS IS STILL BROKEN?
        $results = $this->getProtocols()->getFieldsMap();
        return $results;
    }

    /**
     * @return null
     */
    public function setProjectFieldMappings($mappings=array())
    {
        //TODO THIS STILL BROKEN?
        return $this->getProtocols()->setFieldsMap($mappings);
//        return $this->setProjectSetting(self::FIELD_MAPPINGS, json_encode($mappings));
    }

    /**
     * @return array
     */
    public function getOnCoreFields()
    {
        $field_list = self::$ONCORE_DEMOGRAPHICS_FIELDS;
        return $field_list;
    }

    /**
     * @return array
     */
    public function getRequiredOncoreFields(){
        //TODO WILL THIS BE A GLOBAL EM SETTING JSON ARRAY?
        return ["subjectSource",
            "subjectDemographicsId",
            "mrn",
            "gender",
            "ethnicity",
            "race",
            "birthDate",
            "lastName",
            "firstName",
            "middleName"];
    }

    /**
     * @return array
     */
    public function getProjectFields()
    {
        $event_fields = array();
        $events = \REDCap::getEventNames(true);
        if (!empty($events)) {
            foreach ($events as $event_id => $event) {
                $temp = \REDCap::getValidFieldsByEvents(PROJECT_ID, array($event));
                $temp = array_filter($temp, function ($v) {
                    return !strpos($v, "_complete");
                });
                $event_fields[$event] = $temp;
            }
        } else {
            $dict = \REDCap::getDataDictionary(PROJECT_ID, "array");
            $event_fields = array(array_keys($dict));
        }

        return $event_fields;
    }

    /**
     * @return sync_diff
     */
    public function getSyncDiff()
    {
        $sync_diff = array();
        $mapped_fields = $this->getProjectFieldMappings();

        //TODO FILL IN BINS FOR 3 Scenarios,
        //TODO MRN MATCH BETWEEN ONCORE/REDCAP , Manually Opt out of ONcore -> REDCAp overwrite
        //TODO ONCORE ONLY DATA , COPY INTO RC Entitys and UPDATE RC Data
        //TODO REDCAP ONLY DATA , Not for Phase 1

        $module->getProtocols()->getSubjects()->setSyncedRecords($module->getProtocols()->getEntityRecord()['redcap_project_id'], $module->getProtocols()->getEntityRecord()['oncore_protocol_id']);
        $records = $module->getProtocols()->getSubjects()->getSyncedRecords();

//        $records = array(
//            array(
//                "redcap" => array(
//                    181 => array(
//                        "birthdate" => "2000-01-01"
//                    , "ethnicity" => "Unknown"
//                    , "firstname" => "PROT-IZ firstname"
//                    , "form_1_complete" => 0
//                    , "gender" => "Male"
//                    , "lastname" => "PROT-IZ lastname"
//                    , "mrn" => "MRN23456"
//                    , "record_id" => 1
//                    , "subjectdemographicsid" => 87999
//                    , "subjectsource" => "OnCore"
//                    )
//
//                )
//            , "oncore" => array(
//                "protocolSubjectId" => 138811
//            , "protocolId" => 14071
//            , "studySite" => "SHC Main Hosp, Welch Rd & campus/nearby clinics"
//            , "subjectDemographicsId" => 87999
//            , "subjectDemographics" => null
//            , "status" => null
//            , "demographics" => array(
//                    "id" => 1
//                , "created" => 1645657226
//                , "updated" => 1645657226
//                , "subjectDemographicsId" => 87999
//                , "subjectSource" => "OnCore"
//                , "mrn" => "MRN23456"
//                , "lastName" => "PROT-IZ LastName"
//                , "firstName" => "PROT-IZ firstname"
//                , "suffix" => null
//                , "birthDate" => "2000-01-01"
//                , "approximateBirthDate" => 0
//                , "birthDateNotAvailable" => 0
//                , "expiredDate" => null
//                , "approximateExpiredDate" => 0
//                , "lastDateKnownAlive" => null
//                , "ssn" => null
//                , "gender" => "Male"
//                , "ethnicity" => "Unknown"
//                , "race" => "Unknown"
//                , "subjectComments" => null
//                , "additionalSubjectIds" => array()
//                , "streetAddress" => null
//                , "addressLine2" => null
//                , "city" => null
//                , "state" => null
//                , "zip" => null
//                , "county" => null
//                , "country" => null
//                , "phoneNo" => null
//                , "alternatePhoneNo" => null
//                , "email" => null
//                , "middleName" => null
//                )
//            )
//            , "status" => 3
//            )
//        , array(
//                "oncore" => array(
//                    "protocolSubjectId" => 138812
//                , "protocolId" => 14071
//                , "studySite" => "SHC Main Hosp, Welch Rd & campus/nearby clinics"
//                , "subjectDemographicsId" => 88000
//                , "subjectDemographics" => null
//                , "status" => null
//                , "demographics" => array(
//                        "id" => 2
//                    , "created" => 1645657226
//                    , "updated" => 1645657226
//                    , "subjectDemographicsId" => 88000
//                    , "subjectSource" => "OnCore"
//                    , "mrn" => "MRN23456-2"
//                    , "lastName" => "PROT-IZ LastName"
//                    , "firstName" => "PROT-IZ firstname"
//                    , "suffix" => null
//                    , "birthDate" => "2000-01-01"
//                    , "approximateBirthDate" => 0
//                    , "birthDateNotAvailable" => 0
//                    , "expiredDate" => null
//                    , "approximateExpiredDate" => 0
//                    , "lastDateKnownAlive" => null
//                    , "ssn" => null
//                    , "gender" => "Male"
//                    , "ethnicity" => "Unknown"
//                    , "race" => "Unknown"
//                    , "subjectComments" => null
//                    , "additionalSubjectIds" => array()
//                    , "streetAddress" => null
//                    , "addressLine2" => null
//                    , "city" => null
//                    , "state" => null
//                    , "zip" => null
//                    , "county" => null
//                    , "country" => null
//                    , "phoneNo" => null
//                    , "alternatePhoneNo" => null
//                    , "email" => null
//                    , "middleName" => null
//                    )
//                )
//            , "status" => 1
//            )
//        , array(
//                "oncore" => array(
//                    "protocolSubjectId" => 138813
//                , "protocolId" => 14071
//                , "studySite" => "SHC Main Hosp, Welch Rd & campus/nearby clinics"
//                , "subjectDemographicsId" => 88001
//                , "subjectDemographics" => null
//                , "status" => null
//                , "demographics" => array(
//                        "id" => 3
//                    , "created" => 1645657226
//                    , "updated" => 1645657226
//                    , "subjectDemographicsId" => 88001
//                    , "subjectSource" => "OnCore"
//                    , "mrn" => "MRN23456-3"
//                    , "lastName" => "PROT-IZ LastName"
//                    , "firstName" => "PROT-IZ firstname"
//                    , "suffix" => null
//                    , "birthDate" => "2000-01-01"
//                    , "approximateBirthDate" => 0
//                    , "birthDateNotAvailable" => 0
//                    , "expiredDate" => null
//                    , "approximateExpiredDate" => 0
//                    , "lastDateKnownAlive" => null
//                    , "ssn" => null
//                    , "gender" => "Male"
//                    , "ethnicity" => "Unknown"
//                    , "race" => "Unknown"
//                    , "subjectComments" => null
//                    , "additionalSubjectIds" => array()
//                    , "streetAddress" => null
//                    , "addressLine2" => null
//                    , "city" => null
//                    , "state" => null
//                    , "zip" => null
//                    , "county" => null
//                    , "country" => null
//                    , "phoneNo" => null
//                    , "alternatePhoneNo" => null
//                    , "email" => null
//                    , "middleName" => null
//                    )
//                )
//            , "status" => 1
//            )
//        );

        $exclude = array("mrn");
        $bin_match = array();
        $bin_oncore = array();
        $bin_redcap = array();
        foreach ($records as $record) {
            $link_status = $record["status"];
            $entity_id = 123;
            $last_scan = "01/01/22 12:00";

            if (array_key_exists("oncore", $record) && array_key_exists("redcap", $record)) {
                $oncore = $record["oncore"];
                $oc_pr_id = $oncore["protocolSubjectId"];
                $redcap = current($record["redcap"]);
                $rc_id = $redcap["record_id"];
                $mrn = $oncore["demographics"]["mrn"];

                if (!array_key_exists($mrn, $bin_match)) {
                    $bin_match[$mrn] = array();
                }

                foreach ($mapped_fields as $oncore_field => $redcap_details) {
                    if (in_array($oncore_field, $exclude)) {
                        continue;
                    }
                    $rc_data = isset($redcap[$redcap_details["redcap_field"]]) ? $redcap[$redcap_details["redcap_field"]] : null;
                    $oc_data = isset($oncore["demographics"][$oncore_field]) ? $oncore["demographics"][$oncore_field] : (isset($oncore[$oncore_field]) ? $oncore[$oncore_field] : null);
                    $temp = array(
                        "entity_id" => $entity_id
                    , "ts_last_scan" => $last_scan

                    , "oc_id" => $oncore["protocolId"]
                    , "oc_pr_id" => $oc_pr_id
                    , "rc_id" => $rc_id
                    , "oc_data" => $oc_data
                    , "rc_data" => $rc_data
                    , "link_status" => $link_status
                    , "oc_field" => $oncore_field
                    , "rc_field" => $redcap_details["redcap_field"]
                    , "rc_event" => $redcap_details["event"]
                    );
                    array_push($bin_match[$mrn], $temp);
                }
            } else if (array_key_exists("oncore", $record)) {
                $oncore = $record["oncore"];
                $oc_pr_id = $oncore["protocolSubjectId"];
                $mrn = $oncore["demographics"]["mrn"];

                if (!array_key_exists($mrn, $bin_oncore)) {
                    $bin_oncore[$mrn] = array();
                }
                foreach ($mapped_fields as $oncore_field => $redcap_details) {
                    if (in_array($oncore_field, $exclude)) {
                        continue;
                    }
                    $oc_data = isset($oncore["demographics"][$oncore_field]) ? $oncore["demographics"][$oncore_field] : (isset($oncore[$oncore_field]) ? $oncore[$oncore_field] : null);
                    $temp = array(
                        "entity_id" => $entity_id
                    , "ts_last_scan" => $last_scan

                    , "oc_id" => $oncore["protocolId"]
                    , "oc_pr_id" => $oncore["protocolSubjectId"]
                    , "rc_id" => null
                    , "oc_data" => $oc_data
                    , "rc_data" => null
                    , "link_status" => $link_status
                    , "oc_field" => $oncore_field
                    , "rc_field" => null
                    , "rc_event" => null
                    );
                    array_push($bin_oncore[$mrn], $temp);
                }
            } else if (array_key_exists("redcap", $record)) {
                $redcap = current($record["redcap"]);
                $rc_id = $redcap["record_id"];
                $mrn = $redcap["mrn"];
                $rc_id = $redcap["record_id"];

                if (!array_key_exists($mrn, $bin_redcap)) {
                    $bin_redcap[$mrn] = array();
                }
                foreach ($mapped_fields as $oncore_field => $redcap_details) {
                    if (in_array($oncore_field, $exclude)) {
                        continue;
                    }
                    $rc_data = isset($redcap[$redcap_details["redcap_field"]]) ? $redcap[$redcap_details["redcap_field"]] : null;
                    $temp = array(
                        "entity_id" => $entity_id
                    , "ts_last_scan" => $last_scan

                    , "oc_id" => $oncore["protocolId"]
                    , "oc_pr_id" => $oncore["protocolSubjectId"]
                    , "rc_id" => $rc_id
                    , "oc_data" => null
                    , "rc_data" => $rc_data
                    , "link_status" => $link_status
                    , "oc_field" => $oncore_field
                    , "rc_field" => $redcap_details["redcap_field"]
                    , "rc_event" => $redcap_details["event"]
                    );
                    array_push($bin_redcap[$mrn], $temp);
                }
            }
        }

        if (!empty($bin_match) || !empty($bin_oncore) || !empty($bin_redcap)) {
            $sync_diff = array(
                "match" => $bin_match,
                "oncore" => $bin_oncore,
                "redcap" => $bin_redcap
            );
        }

        return $sync_diff;
    }

    /**
     * @return array
     */
    public function getSyncDiffSummary(){
        //TODO FILL THIS WITH REAL DATA
        $linked_sub         = 125;
        $full_match_count   = 0;
        $oncore_count       = 0;
        $redcap_count       = 0;
        $last_scan_ts       = "01/01/22 10:00";

        return [
             "total_linked" => $linked_sub
            ,"full_match"   => $full_match_count
            ,"oncore_only"  => $oncore_count
            ,"redcap_only"  => $redcap_count
            ,"last_scan_ts" => $last_scan_ts
        ];
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
        if (!$this->protocols) {
            $this->protocols = $protocols;
        }
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
