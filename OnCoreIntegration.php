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
			if($("#setupChklist-modify_project").length){
                var new_section     = $("#setupChklist-modules").clone();
                new_section.find(".chklist_comp").remove();
                new_section.find(".chklisthdr span").text("OnCore Integration");
                new_section.find(".chklisttext").empty();

                $("#setupChklist-modify_project").after(new_section);

                var modify_button   = $("#setupChklist-modify_project").find("button:contains('Modify')");
                var integration_ui  = $("<div>");
                var iu_label        = $("<label>");

                var iu_check        = $("<input>").attr("name", "");
                var iu_text         = $("<span>").text();

                iu_label.append(iu_check);
                iu_label.append(iu_text);
                integration_ui.append(iu_label);

                new_section.find(".chklisttext").append(integration_ui);


                return;

				// ADD NEW BUTTON OR ENTIRELY NEW UI
				var clone_or_show = $("#randomizationFieldHtml");
				clone_or_show.addClass("custom_override")

				// EXISTING UI ALREADY AVAILABLE, REVEAL AND AUGMENT
				var custom_label 	= $("<h6>").addClass("custom_label").addClass("mt-2").text("Manually override and set randomization variable as:");
				clone_or_show.prepend(custom_label);


				var custom_hidden 	= $("<input>").attr("type","hidden").prop("name","randomizer_overide").val(true);
				clone_or_show.prepend(custom_hidden);

				var custom_reason 	= $("<input>").attr("type","text").attr("name","custom_override_reason").prop("placeholder" , "reason for using overide?").addClass("custom_reason");
				clone_or_show.append(custom_reason);

				// var custom_or 		= $("<small>").addClass("custom_or").text("*Manually override and set randomization variable as:");
				// clone_or_show.prepend(custom_or);

				var custom_note 	= $("<small>").addClass("custom_note").text("*Press save to continue");
				clone_or_show.append(custom_note);



				//ONLY ENABLE MANUAL IF STRATA ARE ALL FILLED
				var source_fields  	= <?= json_encode($this->source_fields) ?>;
				var show_overide 	= $("<button>").addClass("jqbuttonmed ui-button ui-corner-all ui-widget btn-danger custom_btn").text("Manual Selection").click(function(e){
					e.preventDefault();

					if(clone_or_show.is(":visible")){
						$("#redcapRandomizeBtn").prop("disabled",false);
					}else{
						$("#redcapRandomizeBtn").prop("disabled",true);
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
        if($this->hasOnCoreIntegration()){
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
        $results    = array();
        $mappings   = $this->getProjectSetting(self::FIELD_MAPPINGS);
        if(!empty($mappings)){
            $results = json_decode($mappings, true);
        }
        return $results;
    }

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
        $field_list = array();
        $dict       = \REDCap::getDataDictionary(PROJECT_ID, "array");
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
