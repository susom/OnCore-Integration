<?php

namespace Stanford\OnCoreIntegration;

require_once "emLoggerTrait.php";
require_once 'classes/Users.php';
if (class_exists('\REDCapEntity\EntityFactory')) {
    require_once 'classes/Entities.php';
}

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

    const REDCAP_ONCORE_FIELDS_MAPPING_NAME     = 'redcap-oncore-fields-mapping';
    const REDCAP_ONCORE_PROJECT_SITE_STUDIES    = 'redcap-oncore-project-site-studies';
    const REDCAP_ONCORE_PROJECT_ONCORE_SUBSET   = 'redcap-oncore-project-oncore-subset';
    const REDCAP_ONCORE_PROJECT_PUSHPULL_PREF   = 'redcap-oncore-project-pushpull-pref';

    const ONCORE_PROTOCOL_STATUS_NO = 0;

    const ONCORE_PROTOCOL_STATUS_PENDING = 1;

    const ONCORE_PROTOCOL_STATUS_YES = 2;

    const YES = 1;

    const NO = 0;

    const ONCORE_SUBJECT_ON_STUDY = 1;


    const ONCORE_SUBJECT_OFF_STUDY = 0;


    const ONCORE_SUBJECT_SOURCE_TYPE_ONCORE = 'OnCore';

    const ONCORE_SUBJECT_SOURCE_TYPE_ONSTAGE = 'Onstage';

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
        "subjectSource",
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
        $protocol = Protocols::getProtocolEntityRecord($project_id);
        if (!empty($protocol)) {
            if ($protocol['status'] == OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES) {
                $this->initiateProtocol();
                $this->getProtocols()->syncIndividualRecord($record);
            }
        }
    }

    public function redcap_module_system_enable($version)
    {
        $enabled = $this->isModuleEnabled('redcap_entity');
        if (!$enabled) {
            // TODO: what to do when redcap_entity is not enabled in the system
            $this->emError("Cannot use this module OncoreIntegration because it is dependent on the REDCap Entities EM");
        } else {
            \REDCapEntity\EntityDB::buildSchema($this->PREFIX);
            $this->emDebug("Created all OnCore Entity tables");
        }
    }

    public function initiateProtocol()
    {
        if (!$this->users) {
            $this->setUsers(new Users($this->PREFIX, $this->framework->getUser() ?: null, $this->getCSRFToken()));
        }
        if (!$this->protocols) {
            $this->setProtocols(new Protocols($this->getUsers(), $this->getMapping(), $this->getProjectId()));
        }
    }

    public function redcap_every_page_top()
    {
        try {
            // in case we are loading record homepage load its the record children if existed
            preg_match('/redcap_v[\d\.].*\/index\.php/m', $_SERVER['SCRIPT_NAME'], $matches, PREG_OFFSET_CAPTURE);
            if (strpos($_SERVER['SCRIPT_NAME'], 'ProjectSetup') !== false || !empty($matches)) {
                //TODO MAY NEED TO MOVE PROTOCOL INITIATION TO __construct
                $this->initiateProtocol();
                $this->injectIntegrationUI();
            }
        } catch (\Exception $e) {
            // TODO routine to handle exception for not finding OnCore protocol
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
                    'type' => 'text',
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

    public function redcap_module_link_check_display($project_id, $link)
    {
        global $Proj;
        // only project context
        if ($Proj) {
            //RACE CONDITIONs THIS FIRES BEFORE redcap_every_page_top
            $entity_record = $this->hasOnCoreIntegration();
            if (!empty($entity_record)) {
                return $link;
            }
        }
        //for control center to display CP links.
        if (\ExternalModules\ExternalModules::isSuperUser()) {
            // Super users can see all pages
            return $link;
        }
    }


    //ONCORE INTEGRATION/STATUS METHODS
    public function injectIntegrationUI()
    {
        $field_map_url              = $this->getUrl("pages/field_map.php");
        $ajax_endpoint              = $this->getUrl("ajax/handler.php");

        $available_oncore_protocols = $this->getOnCoreProtocols();
        $oncore_integrations        = $this->getOnCoreIntegrations();
        $has_oncore_integration     = $this->hasOnCoreIntegration();
        ?>
        <script>
            var ajax_endpoint           = "<?=$ajax_endpoint?>";
            var field_map_url           = "<?=$field_map_url?>";

            var oncore_protocols        = <?=json_encode($available_oncore_protocols); ?>;
            var oncore_integrations     = <?=json_encode($oncore_integrations); ?>;
            var has_oncore_integration  = <?=json_encode($has_oncore_integration); ?>;

            var has_field_mappings      = <?=json_encode(!empty($this->getMapping()->getProjectFieldMappings())); ?>;
            var last_adjudication       = <?=json_encode($this->getSyncDiffSummary()); ?>;

            var make_oncore_module = function () {
                if ($("#setupChklist-modify_project").length) {
                    //BORROW HTML FROM EXISTING UI ON SET UP PAGE
                    var new_section = $("#setupChklist-modules").clone();
                    new_section.attr("id", "integrateOnCore-modules");
                    new_section.find(".chklist_comp").remove();
                    new_section.find(".chklisthdr span").text("OnCore Project Integration");
                    new_section.find(".chklisttext").empty();

                    if(new_section.find("img#img-modules").length){
                        new_section.find("img#img-modules").attr("id", "img-oncore");
                        var img_src = new_section.find("img#img-oncore").attr("src");
                        var src_tmp = img_src.split("/");
                        src_tmp.pop();
                        src_tmp.push("checkbox_gear.png");
                        src_tmp = src_tmp.join("/");
                        new_section.find("img#img-oncore").attr("src", src_tmp);
                    }

                    $("#setupChklist-modify_project").after(new_section);
                    var content_bdy = new_section.find(".chklisttext");
                    var lead        = $("<span>");
                    content_bdy.append(lead);

                    //IF ONCORE HAS BEEN INTEGATED WITH THIS PROJECT, THEN DISPLAY SUMMARY OF LAST ADJUDICATION
                    if(has_field_mappings){
                        var lead_class  = "oncore_results";
                        var lead_text   = "Results summary from last adjudication : ";
                        lead_text += "<ul class='summary_oncore_adjudication'>";
                        lead_text += "<li>Total Subjects : "+last_adjudication["total_count"] +"</li>";
                        lead_text += "<li>Full Match : "+last_adjudication["full_match_count"] +"</li>";
                        lead_text += "<li>Partial Match : "+last_adjudication["partial_match_count"] +"</li>";
                        lead_text += "<li>Oncore Only : "+last_adjudication["oncore_only_count"] +"</li>";
                        lead_text += "<li>REDCap Only : "+last_adjudication["redcap_only_count"] +"</li>";
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
                if(has_oncore_integration){
                    //BORROW UI FROM OTHER ELEMENT TO ADD A NEW MODULE TO PROJECT SETUP
                    make_oncore_module();
                }

                if(Object.keys(oncore_integrations).length){
                    if($("#setupChklist-modify_project button:contains('Modify project title, purpose, etc.')").length){
                        //ADD LINE TO MAIN PROJECT SEETTINGS IF THERE IS POSSIBLE ONCORE INTEGRATION
                        for(var protocolId in oncore_integrations){
                            let integration         = oncore_integrations[protocolId];
                            let protocol            = oncore_protocols[protocolId];

                            let projectIntegrated   = integration["status"] == 2;
                            let integration_entity  = integration["id"];
                            let protocol_status     = protocol["protocolStatus"];
                            let protocol_title      = protocol["shortTitle"];

                            let btn_text            = projectIntegrated ? "Unlink Project&nbsp;" : "Link Project&nbsp;";
                            let integrated_class    = projectIntegrated ? "integrated" : "not_integrated";
                            var line_text           = "with OnCore Protocol "+protocolId+" : "+protocol_title+" [<i>" + protocol_status.toLowerCase() + "</i>]";
                            line_text               = projectIntegrated ?  "Linked " + line_text : "Link " + line_text;

                            let integrate_text      = $("<span>").addClass("enable_oncore").html(line_text);
                            let new_line            = $("<div>").addClass(integrated_class).attr("style","text-indent:-75px;margin-left:75px;padding:2px 0;font-size:13px;");
                            let button              = $("<button>").data("entity_record_id", integration_entity).addClass("integrate_oncore").addClass("btn btn-defaultrc btn-xs fs11").html(btn_text);
                            new_line.append(button);
                            button.after(integrate_text);

                            // if(integrated_class == "integrated"){
                            //     button.attr("disabled","disabled");
                            // }

                            $("#setupChklist-modify_project button:contains('Modify project title, purpose, etc.')").before(new_line);
                        }
                    }
                }

                //INTEGRATE AJAX
                $(".integrate_oncore").on("click",function(e){
                    e.preventDefault();

                    var _par                = $(this).parent("div");
                    var need_to_integrate   = _par.hasClass("not_integrated") ? 1 : 0;
                    var entity_record_id    = $(this).data("entity_record_id");

                    //LINKAGE AJAX
                    $.ajax({
                        url : ajax_endpoint,
                        method: 'POST',
                        data: {
                            "action" : "integrateOnCore",
                            "integrate" : need_to_integrate,
                            "entity_record_id" : entity_record_id
                        },
                        dataType: 'json'
                    }).done(function (oncore_integrated) {
                        // console.log(oncore_integrated);
                        document.location.reload();
                    }).fail(function (e) {
                        console.log("failed to integrate", e);
                    });
                });

                //TRIGGER CRON ON NEW IRB  INPUT
                $("#project_irb_number").on("blur", function(){
                    console.log("an IRB was input!");
                    var irb = $(this).val();

                    $.ajax({
                        url : ajax_endpoint,
                        method: 'POST',
                        data: {
                            "action" : "triggerIRBSweep"
                        },
                        dataType: 'json'
                    }).done(function (e) {
                        console.log("triggerIRBSweep done");
                        document.location.reload();
                    }).fail(function (e) {
                        console.log("triggerIRBSweep failed", e);
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
     * @return null
     */
    public function integrateOnCoreProject($entityId, $integrate = false)
    {
        $this->initiateProtocol();
        $setStatus  = $integrate ? self::ONCORE_PROTOCOL_STATUS_YES : self::ONCORE_PROTOCOL_STATUS_NO;
        $entity     = $this->getProtocols()->updateProtocolEntityRecordStatus($entityId, $setStatus);
        return $entity;
    }

    /**
     * @return array
     *
     */
    public function getOnCoreProtocols(): array
    {
        if(!$this->oncore_protocols){
            $this->initiateProtocol();
            $available_oncore_protocols = $this->getProtocols()->getOnCoreProtocol();
            $this->oncore_protocols     = array();
            if (!empty($available_oncore_protocols)) {
                if($this->isAssoc($available_oncore_protocols)){
                    $available_oncore_protocols = array($available_oncore_protocols);
                }
            }
            foreach($available_oncore_protocols as $oncore_project){
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
        if(!$this->oncore_integrations){
            $this->initiateProtocol();
            $entity_records             = $this->getProtocols()->getEntityRecord();
            $this->oncore_integrations  = array();
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
        if(!$this->has_oncore_integrations){
            $this->has_oncore_integrations  = false;
            $current_oncore_integrations    = $this->getOnCoreIntegrations();
            foreach($current_oncore_integrations as $protocol_id => $oncore_integration){
                if($oncore_integration["status"] == 2){
                    $this->has_oncore_integrations = true;
                    break;
                }
            }
        }

        return $this->has_oncore_integrations;
    }

    /**
     * @return date time Y-m-d H:i
     */
    public function formatTS($ts){
        return date("Y-m-d H:i", $ts);
    }


    //DATA SYNC METHODS
    /**
     * @return sync_diff
     */
    public function getSyncDiff()
    {
        $this->initiateProtocol();
        $this->getProtocols()->getSubjects()->setSyncedRecords($this->getProtocols()->getEntityRecord()['redcap_project_id'], $this->getProtocols()->getEntityRecord()['oncore_protocol_id']);

        $records        = $this->getProtocols()->getSyncedRecords();
        $mapped_fields  = $this->getMapping()->getProjectFieldMappings();

        $sync_diff  = array();
        $bin_match  = array("excluded" => array(), "included" => array());
        $bin_oncore = array("excluded" => array(), "included" => array());
        $bin_redcap = array("excluded" => array(), "included" => array());
        $bin_array  = array("bin_redcap", "bin_oncore", "bin_match", "bin_match");
        $exclude    = array("mrn");

        foreach($records as $record){
            $link_status    = $record["status"];
            $entity_id      = $record["entity_id"];

            $excluded       = $record["excluded"] ?? 0;
            $oncore         = null;
            $redcap         = null;

            $last_scan      = null;
            $full           = false;

            $oc_id          = null;
            $oc_pr_id       = null;
            $rc_id          = null;
            $oc_data        = null;
            $rc_data        = null;
            $rc_field       = null;
            $rc_event       = null;

            switch($link_status){
                case 2:
                    //full
                    $full = true;

                case 3:

                case 1:
                    //oncore only
                    $oncore     = $record["oncore"];
                    $oc_id      = $oncore["protocolId"];
                    $oc_pr_id   = $oncore["protocolSubjectId"];
                    $mrn        = $oncore["demographics"]["mrn"];
                    $last_scan  = date("Y-m-d H:i", $oncore["demographics"]["updated"]);

                case 0:
                    //redcap only
                    if(array_key_exists("redcap",$record)){
                        // set the keys for redcap array
                        $arr = current($record["redcap"]);
                        // this just to prepare the array to be displayed.
                        $temp = $this->getProtocols()->getSubjects()->prepareREDCapRecordForOnCorePush($arr["record_id"], $this->getMapping()->getProjectFieldMappings()['push'], $this->getMapping()->getOnCoreFieldDefinitions());
                        // handle data scattered over multiple events
                        $redcap = [];
                        foreach ($temp as $onCoreField => $value) {
                            // Use redcap fields name instead of oncore to work with Irvin UI.
                            $redcapField = $this->getMapping()->getMappedRedcapField($onCoreField);
                            $redcap[$redcapField ?: $onCoreField] = $value;
                        }
                        $rc_id = $arr["record_id"];
                        $mrn = $redcap["mrn"];
                    }

                default:
                    //partial
                    $bin_var    = $bin_array[$link_status];
                    $bin        = $excluded ? $$bin_var["excluded"] : $$bin_var["included"];
                    if(!array_key_exists($mrn, $bin)){
                        if($excluded){
                            $$bin_var["excluded"][$mrn] = array();
                        }else{
                            $$bin_var["included"][$mrn] = array();
                        }
                    }
                    foreach($mapped_fields["pull"] as $oncore_field => $redcap_details){
                        if(in_array($oncore_field, $exclude)){
                            continue;
                        }
                        $rc_field   = $redcap_details["redcap_field"];
                        $rc_event   = $redcap_details["event"];

                        $rc_data    = $redcap && isset($redcap[$redcap_details["redcap_field"]]) ? $redcap[$redcap_details["redcap_field"]] : null;
                        $oc_data    = $oncore && isset($oncore["demographics"][$oncore_field]) ? $oncore["demographics"][$oncore_field] : (isset($oncore[$oncore_field]) ? $oncore[$oncore_field] : null);
                        $temp       = array(
                             "entity_id"    => $entity_id
                            ,"ts_last_scan" => $last_scan
                            ,"oc_id"        => $oc_id
                            ,"oc_pr_id"     => $oc_pr_id
                            ,"rc_id"        => $rc_id
                            ,"oc_data"      => $oc_data
                            ,"rc_data"      => $rc_data
                            ,"oc_field"     => $oncore_field
                            ,"rc_field"     => $rc_field
                            ,"rc_event"     => $rc_event
                            ,"full"         => $full
                        );
                        if($excluded){
                            array_push($$bin_var["excluded"][$mrn], $temp);
                        }else{
                            array_push($$bin_var["included"][$mrn], $temp);
                        }
                    }
                    break;
            }
        }

        if(!empty($bin_match) || !empty($bin_oncore) || !empty($bin_redcap)){
            $sync_diff = array(
                "match"     => $bin_match,
                "oncore"    => $bin_oncore,
                "redcap"    => $bin_redcap
            );
        }

        return $sync_diff;
    }

    /**
     * @return array
     */
    public function getSyncDiffSummary(){
        $last_adjudication = $this->getProtocols()->getSyncedRecordsSummaries();
        return $last_adjudication;
    }

    /**
     * @return array
     */
    public function pullSync(){
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

    public function onCoreProtocolsScanCron()
    {
        try {
            $projects = self::query("select project_id, project_irb_number from redcap_projects where project_irb_number is NOT NULL ", []);

            // manually set users to make guzzle calls.
            if (!$this->users) {
                $this->setUsers(new Users($this->PREFIX, $this->framework->getUser(), $this->getCSRFToken()));
            }

            while ($project = $projects->fetch_assoc()) {
                $id = $project['project_id'];
                $irb = $project['project_irb_number'];

                if (!$irb) {
                    continue;
                }
                $url = $this->getUrl("ajax/cron.php", true) . '&pid=' . $id . '&action=protocols';
                $this->getUsers()->getGuzzleClient()->get($url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
                $this->emDebug("running cron for $url on project " . $project['app_title']);
            }

        } catch (\Exception $e) {
            $this->emError($e->getMessage());
            \REDCap::logEvent('CRON JOB ERROR: ' . $e->getMessage());
            Entities::createException('CRON JOB ERROR: ' . $e->getMessage());

        }
    }


    public function onCoreProtocolsSubjectsScanCron()
    {
        try {
            $projects = self::query("select project_id, project_irb_number from redcap_projects where project_irb_number is NOT NULL ", []);

            // manually set users to make guzzle calls.
            if (!$this->users) {
                $this->setUsers(new Users($this->PREFIX, $this->framework->getUser(), $this->getCSRFToken()));
            }

            while ($project = $projects->fetch_assoc()) {
                $id = $project['project_id'];
                $irb = $project['project_irb_number'];

                if (!$irb) {
                    continue;
                }

                // only link protocols
                $protocol = Protocols::getProtocolEntityRecord($id);
                if (!empty($protocol) && $protocol['status'] == self::ONCORE_PROTOCOL_STATUS_YES) {
                    $url = $this->getUrl("ajax/cron.php", true) . '&pid=' . $id . '&action=redcap_only';
                    $this->getUsers()->getGuzzleClient()->get($url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
                    $this->emDebug("running cron for $url on project " . $project['app_title']);
                }

            }

        } catch (\Exception $e) {
            $this->emError($e->getMessage());
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
                $this->setUsers(new Users($this->PREFIX, $this->framework->getUser(), $this->getCSRFToken()));
            }

            while ($project = $projects->fetch_assoc()) {
                $id = $project['project_id'];
                $irb = $project['project_irb_number'];

                if (!$irb) {
                    continue;
                }
                $url = $this->getUrl("ajax/cron.php", true) . '&pid=' . $id . '&action=subjects';
                $this->getUsers()->getGuzzleClient()->get($url, array(\GuzzleHttp\RequestOptions::SYNCHRONOUS => true));
                $this->emDebug("running cron for $url on project " . $project['app_title']);
            }

        } catch (\Exception $e) {
            $this->emError($e->getMessage());
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
}
