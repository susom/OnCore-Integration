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

    const FIELD_MAPPINGS = 'oncore_field_mappings';
    const ONCORE_PROTOCOLS = 'oncore_protocols';
    const REDCAP_ENTITY_ONCORE_PROTOCOLS = 'redcap_entity_oncore_protocols';
    const ONCORE_REDCAP_API_ACTIONS_LOG = 'oncore_redcap_api_actions_log';
    const REDCAP_ENTITY_ONCORE_REDCAP_API_ACTIONS_LOG = 'redcap_entity_oncore_redcap_api_actions_log';
    const ONCORE_ADMINS = 'oncore_admins';
    const REDCAP_ENTITY_ONCORE_ADMINS = 'redcap_entity_oncore_admins';

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

        // TODO redcap entity for redcap records not in OnCore

        // TODO redcap entity for OnCore records not in REDCap

        // TODO redcap entity to save the linkage between redcap and OnCore records

        return $types;
    }

    public function redcap_module_link_check_display($project_id, $link)
    {
        //TODO WILL WE NEED CONDITIONAL DISPLAY OF EM LINKS ONCE INTEGRATION HAS BEEN COMPLETE
        if($this->hasOnCoreIntegration()){
            return $link;
        }
    }

    public function injectIntegrationUI()
    {
        $field_map_url = $this->getUrl("pages/field_map.php");
        $ajax_endpoint = $this->getUrl("ajax/handler.php");
        ?>
        <script>
        var has_oncore_project  = <?=json_encode($this->hasOnCoreProject()); ?>;
        var oncore_integrated   = <?=json_encode($this->hasOnCoreIntegration()); ?>;
        var ajax_endpoint       = "<?=$ajax_endpoint?>";
        var field_map_url       = "<?=$field_map_url?>";
		//  this over document.ready because we need this last!
		$(window).on('load', function () {
            //BORROW CONTAINER UI TO ADD A NEW SECTION TO PROJECT SETUP
			if($("#setupChklist-modify_project").length){
                var new_section = $("#setupChklist-modules").clone();
                new_section.attr("id","integrateOnCore-modules");
                new_section.find(".chklist_comp").remove();
                new_section.find(".chklisthdr span").text("OnCore Project Integration");
                new_section.find(".chklisttext").empty();

                $("#setupChklist-modify_project").after(new_section);
                var content_bdy = new_section.find(".chklisttext");
                var lead = $("<span>");
                content_bdy.append(lead);

                //TODO FIGURE UX
                var integration_ui  = $("<form>").attr("id", "integrate_oncore");
                var iu_label        = $("<label>");
                var iu_check        = $("<input>").attr("type", "checkbox").addClass("integrate").attr("name", "integrate").attr("value","1");
                var iu_text         = $("<span>").text("Check this box to link the OnCore Project");

                iu_label.append(iu_check);
                iu_label.append(iu_text);
                integration_ui.append(iu_label);
                if(oncore_integrated){
                    //TODO IF ENCORE HAS BEEN INTEGRATED THEN SHOW THAT IT HAS BEEN
                    var lead_text = "This project has been integrated with OnCore Project #" + oncore_integrated;
                    lead.addClass("integrated");
                    iu_check.attr("disabled","disabled").attr("checked","checked");
                    content_bdy.append(integration_ui);
                }else{
                    //TODO IF NOT, THEN CHECK IF THERE IS AN ONCORE PROJECT BASED ON IRB?
                    var lead_text = "<i class='ml-1 fas fa-minus-circle' style='text-indent:0;'></i> No available OnCore projects were found with this project's IRB.";

                    if(has_oncore_project){
                        lead_text = "An OnCore project for IRB #" + has_oncore_project + " was found.";
                        lead.addClass("notintegrated");
                        content_bdy.append(integration_ui);
                    }else{
                        lead.addClass("noproject");
                    }

                }
                lead.html(lead_text);
			}

            //INTEGRATE AJAX
            $("#integrate_oncore .integrate").on("change",function(){
                if($(this).is(':checked')){
                    $("#integrate_oncore").submit();
                }
            });
            $("#integrate_oncore").submit(function(){
                //LINKAGE AJAX
                $.ajax({
                    url : ajax_endpoint,
                    method: 'POST',
                    data: {
                        "action" : "integrateOnCore"
                    },
                    dataType: 'json'
                }).done(function (oncore_integrated) {
                    //TODO update lead text class AND DISABLE integration checkbox
                    var lead_text = "This project has been integrated with OnCore Project #" + oncore_integrated;
                    $("span.notintegrated").addClass("integrated").removeClass("notintegrated").text(lead_text);
                    $("#integrate_oncore .integrate").attr("disabled","disabled");

                    //TODO REDIRECT TO field map?
                    setTimeout(function(){
                        location.href=field_map_url;
                    },500);
                }).fail(function (e) {
                    console.log("failed to integrate", e);
                });
                return false;
            });
        });
		</script>
		<style>
            span.noproject{
                color:#800000;
            }
            span.integrated{
                color:#009b76;
            }
            span.notintegrated{
                color:#0098db;
            }
            #integrate_oncore{
                padding:5px 0 0;
            }
            #integrate_oncore span{
                display:inline-block;
                margin-left:5px;
            }
		</style>
		<?php
    }

    /**
     * @return int ?
     */
    public function integrateOnCoreProject()
    {
        //TODO PROBABLY ALREADY EXISTS IN IHAB's CLASSES
        $irb = $this->hasOnCoreProject();

//        return false;
        return 654321;
    }

    /**
     * @return int
     */
    public function hasOnCoreProject()
    {
        //TODO use IRB to check if there is an OnCore Project?
        //TODO return IRB # to signify existence?
//        return null;
        return 123456;
    }

    /**
     * @return int
     */
    public function hasOnCoreIntegration()
    {
        //TODO what represents an OnCore Integration? an Oncore project ID?
        //TODO return THAT to signfy an integration?
//        return null;
        return 654321;
    }

    /**
     * @return array
     */
    public function getProjectFieldMappings()
    {
        $results    = array();
        $mappings   = $this->getProjectSetting(self::FIELD_MAPPINGS);
        if(!empty($mappings)){
            $results = json_decode($mappings, true);
        }
        return $results;
    }

    /**
     * @return null
     */
    public function setProjectFieldMappings($mappings=array())
    {
        return $this->setProjectSetting(self::FIELD_MAPPINGS, json_encode($mappings));
    }

    /**
     * @return field_list
     */
    public function getOnCoreFields()
    {
        $field_list = array("MRN","race", "birthdate", "sex", "fname", "lname");
        return $field_list;
    }

    /**
     * @return field_list
     */
    public function getProjectFields()
    {
        $dict       = \REDCap::getDataDictionary(PROJECT_ID, "array");
        $field_list = array_keys($dict);
        return $field_list;
    }

    /**
     * @return sync_diff
     */
    public function getSyncDiff()
    {
        $sync_diff  = array();

        //TODO FILL IN BINS FOR 3 Scenarios,
        //TODO MRN MATCH BETWEEN ONCORE/REDCAP , Manually Opt out of ONcore -> REDCAp overwrite
        //TODO ONCORE ONLY DATA , COPY INTO RC Entitys and UPDATE RC Data
        //TODO REDCAP ONLY DATA , Not for Phase 1
        $bin_match  = array();
        $bin_oncore = array();
        $bin_redcap = array();

        if(!empty($bin_match) || !empty($bin_oncore) || !empty($bin_redcap) || 1){
            $sync_diff = array(
                "match"     => $bin_match,
                "oncore"    => $bin_oncor,
                "redcap"    => $bin_redcap
            );
        }

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
                            Entities::createLog(date('m/d/Y H:i:s') . ' : OnCore Protocol record created for IRB: ' . $irb . '.');
                        } else {
                            throw new \Exception(implode(',', $this->getProtocols()->errors));
                        }
                    } else {
                        $this->getProtocols()->updateProtocolEntityRecordTimestamp($entity_oncore_protocol['id']);
                        Entities::createLog(date('m/d/Y H:i:s') . ' : OnCore Protocol record updated for IRB: ' . $irb . '.');
                    }
                } else {
                    $this->emLog(date('m/d/Y H:i:s') . ' : IRB ' . $irb . ' has no OnCore Protocol.');
                    Entities::createLog(date('m/d/Y H:i:s') . ' : IRB ' . $irb . ' has no OnCore Protocol.');
                }
            }
        } catch (\Exception $e) {
            $this->emError($e->getMessage());
            \REDCap::logEvent('CRON JOB ERROR: ' . $e->getMessage());
            Entities::createLog('CRON JOB ERROR: ' . $e->getMessage());

        }
    }

}
