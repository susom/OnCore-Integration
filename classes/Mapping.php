<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

use ExternalModules\ExternalModules;

class Mapping
{
    private $module;
    private $oncore_fields;
    private $redcap_fields;
    private $project_mapping;
    private $site_studies_subset;

    public function __construct($module)
    {
        $this->module           = $module;
        $this->oncore_fields    = $this->getOnCoreFieldDefinitions();
        $this->redcap_fields    = $this->getProjectFieldDictionary();
        $this->project_mapping  = $this->getProjectFieldMappings();
    }

    //GATHER THE REQUISITE DATAs INTO ARRAYS (Oncore, Redcap, FieldMappings)
    /**
     * @return array
     */
    public function getOnCoreFieldDefinitions()
    {
        $field_list = json_decode(trim($this->module->getSystemSetting("oncore-field-definition")), true);
        return $field_list;
    }

    /**
     * @return array
     */
    public function getProjectFieldDictionary()
    {
        global $Proj;

        $event_fields = array();
        $events = \REDCap::getEventNames(true);
        $dict = \REDCap::getDataDictionary(PROJECT_ID, "array");

        if (!empty($events)) {
            foreach ($events as $event_id => $event) {
                $temp = \REDCap::getValidFieldsByEvents(PROJECT_ID, array($event));
                $temp = array_filter($temp, function ($v) {
                    return !strpos($v, "_complete");
                });

                foreach ($temp as $field_name) {
                    $event_fields[$event][] = array(
                        "name" => $field_name
                    , "field_type" => $dict[$field_name]["field_type"]
                    , "select_choices" => $dict[$field_name]["select_choices_or_calculations"]
                    );
                }
            }
        } else {
            $temp = array();
            foreach ($dict as $field_name => $field_details) {
                $temp[] = array(
                    "name" => $field_name
                , "field_type" => $field_details["field_type"]
                , "select_choices" => $field_details["select_choices_or_calculations"]
                );
            }
            $event_fields[$Proj->getUniqueEventNames($this->module->getFirstEventId())] = $temp;
        }
        return $event_fields;
    }

    //TODO USE THIS ONE INSTEAD OF MY DEFAULT ONE FORMAT getProjectFieldDictionary

    /**
     * @return array
     */
    public function getFormattedRedcapFields()
    {
        $rc_fields = $this->getRedcapFields();

        $temp = array();
        foreach ($rc_fields as $event_name => $field) {
            foreach ($field as $item) {
                $select_choices = array();
                if (!empty($item["select_choices"])) {
                    $split = explode("|", $item["select_choices"]);
                    foreach ($split as $str) {
                        $split_2 = explode(",", $str);
                        $select_choices[trim($split_2[0])] = trim($split_2[1]);
                    }
                }

                $temp[$item["name"]] = array(
                    "redcap_field_type" => $item["field_type"]
                    , "select_choices" => $select_choices
                    , "event_name" => $event_name
                );
            }
        }
        return $temp;
    }

    /**
     * @return array
     */
    public function getProjectFieldMappings()
    {
        $this->module->initiateProtocol();
        $results = $this->module->getProtocols()->getFieldsMap();
        return $results;
    }

    /**
     * @return null
     */
    public function setProjectFieldMappings($mappings = array())
    {
        $this->module->initiateProtocol();
        $mappings = is_null($mappings) ? array() : $mappings;
        return $this->module->getProtocols()->setFieldsMap($mappings);
    }


    //GET METHODS
    /**
     * @return array
     */
    public function getProjectMapping()
    {
        return $this->project_mapping;
    }

    /**
     * @return array
     */
    public function getOncoreFields()
    {
        return $this->oncore_fields;
    }

    /**
     * @return array
     */
    public function getRedcapFields()
    {
        return $this->redcap_fields;
    }

    /**
     * @return array
     */
    public function getOncoreRequiredFields()
    {
        $oncore_fields  = $this->getOncoreFields();
        $req_fields     = array();
        foreach($oncore_fields as $oncore_field => $field){
            if($field["required"] == "true"){
                $req_fields[] = $oncore_field;
            }
        }
        return $req_fields;
    }


    //GET INDIVIDUAL FIELD/PROPERTIES
    /**
     * @return array
     */
    public function getOncoreField($oncore_field)
    {
        $field_set = $this->getOncoreFields();
        $field = array_key_exists($oncore_field, $field_set) ? $field_set[$oncore_field] : array();
        return $field;
    }

    /**
     * @return array
     */
    public function getOncoreValueSet($oncore_field)
    {
        $field = $this->getOncoreField($oncore_field);
        $value_set = array_key_exists("oncore_valid_values", $field) ? $field["oncore_valid_values"] : array();
        return $value_set;
    }

    /**
     * @return string
     */
    public function getOncoreType($oncore_field)
    {
        $field = $this->getOncoreField($oncore_field);
        $type = array_key_exists("oncore_field_type", $field) ? current($field["oncore_field_type"]) : "";
        return $type;
    }

    /**
     * @return bool
     */
    public function getOncoreRequired($oncore_field)
    {
        $field = $this->getOncoreField($oncore_field);
        $req = array_key_exists("required", $field) ? $field["required"] : false;
        return $req;
    }

    /**
     * @return string
     */
    public function getOncoreAlias($oncore_field)
    {
        $field = $this->getOncoreField($oncore_field);
        $alias = array_key_exists("alias", $field) && !empty($field["alias"]) ? $field["alias"] : $oncore_field;
        return $alias;
    }

    /**
     * @return string
     */
    public function getOncoreDesc($oncore_field)
    {
        $field = $this->getOncoreField($oncore_field);
        $desc = array_key_exists("description", $field) ? $field["description"] : "";
        return $desc;
    }


    /**
     * @return array
     */
    public function getMappedField($oncore_field)
    {
        $field_set = $this->getProjectMapping();
        $field = array_key_exists($oncore_field, $field_set) ? $field_set[$oncore_field] : array();
        return $field;
    }

    /**
     * @return string
     */
    public function getMappedRedcapField($oncore_field)
    {
        $field = $this->getMappedField($oncore_field);
        $name = array_key_exists("redcap_field", $field) ? $field["redcap_field"] : "";
        return $name;
    }

    /**
     * @return string
     */
    public function getMappedRedcapEvent($oncore_field)
    {
        $field = $this->getMappedField($oncore_field);
        $event = array_key_exists("event", $field) ? $field["event"] : "";
        return $event;
    }

    /**
     * @return string
     */
    public function getMappedRedcapType($oncore_field)
    {
        $field = $this->getMappedField($oncore_field);
        $type = array_key_exists("field_type", $field) ? $field["field_type"] : "";
        return $type;
    }

    /**
     * @return array
     */
    public function getMappedRedcapValueSet($oncore_field)
    {
        $field = $this->getMappedField($oncore_field);
        $value_set = array_key_exists("value_mapping", $field) ? $field["value_mapping"] : array();
//        [value_mapping] => Array
//        (
//            [0] => Array
//            (
//                [oc] => Male
//                [rc] => 1
//                        )
//                )
        $temp = array();
        if (!empty($value_set)) {
            foreach ($value_set as $set) {
                $temp[$set["oc"]] = $set["rc"];
            }
        }

        // RETURN FORMATTED ARRAY
        return $temp;
    }

    /**
     * @return string
     */
    public function getMappedOncoreField($redcap_field)
    {
        $project_mapping = $this->getProjectMapping();
        foreach($project_mapping as $oncore_field => $mapping){
            if($mapping["redcap_field"] == $redcap_field){
                return $oncore_field;
                break;
            }
        }
        return null;
    }

    /**
     * @return array
     */
    public function getRedcapField($redcap_field)
    {
        $fields = $this->getFormattedRedcapFields();
        $field = array_key_exists($redcap_field, $fields) ? $fields[$redcap_field] : array();
        return $field;
    }

    /**
     * @return string
     */
    public function getRedcapType($redcap_field)
    {
        $field = $this->getRedcapField($redcap_field);
        $type = array_key_exists("redcap_field_type", $field) ? $field["redcap_field_type"] : "";
        return $type;
    }

    /**
     * @return string
     */
    public function getRedcapEventName($redcap_field)
    {
        $field = $this->getRedcapField($redcap_field);
        $event = array_key_exists("event_name", $field) ? $field["event_name"] : "";
        return $event;
    }

    /**
     * @return array
     */
    public function getRedcapValueSet($redcap_field)
    {
        $field = $this->getRedcapField($redcap_field);
        $value_set = array_key_exists("select_choices", $field) ? $field["select_choices"] : array();
        return $value_set;
    }


    //GET PULL PUSH STATUS
    /**
     * @return array
     */
    public function calculatePushPullStatus($oncore_field)
    {
        $pull_status    = false;
        $push_status    = false;
        $oc_field_map   = $this->getMappedField($oncore_field);

        //has mapping
        if (!empty($oc_field_map)) {
            $redcap_field   = $oc_field_map["redcap_field"];
            $oncore_vset    = $this->getOncoreValueSet($oncore_field);
            $vmap_format    = $this->getMappedRedcapValueSet($oncore_field);
            $rc_type        = $this->getMappedRedcapType($oncore_field);
            $rc_vset        = $this->getRedcapValueSet($redcap_field);

            if (!empty($vmap_format)) {
                //has value set mapping
                $rc_coded_values = array_keys($rc_vset);
                $oncore_coverage = array_diff($oncore_vset, array_keys($vmap_format));
                $redcap_coverage = array_diff($rc_coded_values, array_values($vmap_format));

                if (empty($redcap_coverage)) {
                    //this means all valid redcap values have been mapped to an oncore value
                    $push_status = true;
                }

                if (empty($oncore_coverage)) {
                    //this means all the valid oncore values have been mapped to a redcap value
                    $pull_status = true;
                }
            } elseif ($rc_type == "text") {
                //if redcap field type is text, then it can accept anything always
                $pull_status = true;
                if (empty($oncore_vset)) {
                    //if redcap field is text, but oncore only has fixed value set, then push is not always true
                    $push_status = true;
                }
            }
        }

        return array("pull" => $pull_status, "push" => $push_status);
    }

    /**
     * @return bool
     */
    public function getPullStatus($oncore_field)
    {
        $status = $this->calculatePushPullStatus($oncore_field);
        return $status["pull"];
    }

    /**
     * @return bool
     */
    public function getPushStatus($oncore_field)
    {
        $status = $this->calculatePushPullStatus($oncore_field);
        return $status["push"];
    }

    /**
     * @return bool
     */
    public function getOverallPullStatus()
    {
        $all_pull_ok = true;
        foreach($this->getOncoreRequiredFields() as $oncore_field){
            if(!$this->getPullStatus($oncore_field)){
                $all_pull_ok = false;
                break;
            }
        }
        return $all_pull_ok;
    }

    /**
     * @return bool
     */
    public function getOverallPushStatus()
    {
        $oncore_field   = "mrn";
        $status         = $this->getPushStatus($oncore_field);
        return $status;
    }


    //SITE STUDIES
    public function setProjectSiteStudies(array $site_studies_subset): void
    {
        ExternalModules::setProjectSetting($this->module->getProtocols()->getUser()->getPREFIX(), $this->module->getProtocols()->getEntityRecord()['redcap_project_id'], OnCoreIntegration::REDCAP_ONCORE_PROJECT_SITE_STUDIES, json_encode($site_studies_subset));
        $this->site_studies_subset = $site_studies_subset;
    }

    public function getProjectSiteStudies()
    {
        if(empty($this->site_studies_subset)){
            $arr = json_decode(ExternalModules::getProjectSetting($this->module->getProtocols()->getUser()->getPREFIX(), $this->module->getProtocols()->getEntityRecord()['redcap_project_id'], OnCoreIntegration::REDCAP_ONCORE_PROJECT_SITE_STUDIES), true);
            $this->site_studies_subset = $arr ?: [];
        }
        return $this->site_studies_subset;
    }






    //DRAW UI
    /**
     * @return array
     */
    public function makeFieldMappingUI()
    {
        $project_fields     = $this->getRedcapFields();
        $oncore_fields      = $this->getOnCoreFields();
        $project_mappings   = $this->getProjectMapping();
        $redcap_fields      = array();

        $pull_required_html = "";
        $pull_not_required  = "";
        $push_required_html = "";
        $push_not_required  = "";

        //REDCap Data Dictionary Fields w/ generic 'xxx'name
        $rc_select = "<select class='form-select form-select-sm mrn_field redcap_field' name='[ONCORE_FIELD]'>\r\n";
        $rc_select .= "<option value=-99>-Map REDCap Field-</option>\r\n";
        foreach ($project_fields as $event_name => $fields) {
            $rc_select .= "<optgroup label='$event_name'>\r\n";
            foreach ($fields as $field) {
                $field_choices = !empty($field["select_choices"]) ? $this->getRedcapValueSet($field["name"]) : array();
                $field["select_choices"]        = $field_choices;
                $redcap_fields[$field["name"]]  = $field;
                $rc_select .= "<option data-eventname='$event_name' data-value_set='" . json_encode($field_choices) . "' data-type='{$field["field_type"]}' value='{$field["name"]}' 'vmap-{$field["name"]}'>{$field["name"]}</option>\r\n";
            }
            $rc_select .= "</optgroup>\r\n";
        }
        $rc_select .= "</select>\r\n";

        //OnCore Static Field names need mapping to REDCap fields
        $req_options    = array();
        $no_req_options = array();
        $oc_select      = "<select class='form-select form-select-sm mrn_field oncore_field' name='[REDCAP_FIELD]'>\r\n";
        $oc_select      .= "<option value=-99>-Map Oncore Property-</option>\r\n";
        foreach($oncore_fields as $field => $field_details) {
            //CREATE ONCORE MAPPING FOR REDCAP TO ONCORE MAPPING
            $required       = $field_details["required"] == "true" ? "required" : null;
            $field_choices  = !empty($field_details["oncore_valid_values"]) ? $field_details["oncore_valid_values"] : array();
            $temp_option    = "<option data-value_set='" . json_encode($field_choices) . "' value='$field' 'vmap-$field'>$field</option>\r\n";
            if($required){
                $req_options[]      = $temp_option;
            }else{
                $no_req_options[]   = $temp_option;
            }

            //each select will have different input['name']
            $map_select     = str_replace("[ONCORE_FIELD]", $field, $rc_select);
            $rc_field       = $project_mappings[$field];
            $rc_field_name  = $rc_field["redcap_field"];
            $event_name     = $this->getRedcapEventName($rc_field_name);
            $oncore_type    = current($field_details["oncore_field_type"]);

            $pull_status    = "";
            $value_map_html = "";
            $value_map_html = $this->makeValueMappingUI($field, $rc_field_name);

            if (array_key_exists($field, $project_mappings)) {
                $json_vmapping      = json_encode($this->getMappedRedcapValueSet($field));
                $data_value_mapping = "data-val_mapping='{$json_vmapping}'";
                $map_select         = str_replace("'$rc_field_name'", "'$rc_field_name' selected ", $map_select);
                $map_select         = str_replace("'vmap-$rc_field_name'", $data_value_mapping, $map_select);

                $value_map_html = $this->makeValueMappingUI($field, $rc_field_name);
                $pull_status    = $this->getPullStatus($field) ? "ok" : "";
            }

            if (!$required) {
                $pull_not_required .= "<tr class='$field notrequired'>\r\n";
                $pull_not_required .= "<td class='oc_field'>$field</td>";
                $pull_not_required .= "<td class='rc_selects'>$map_select</td>";
                $pull_not_required .= "<td class='rc_event centered'>$event_name</td>";
                $pull_not_required .= "<td class='centered status pull $pull_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
                $pull_not_required .= "</tr>\r\n";
                if (!empty($value_map_html["html"])) {
                    $pull_not_required .= $value_map_html["html"];
                }
            } else {
                $pull_required_html .= "<tr class='$field $required'>\r\n";
                $pull_required_html .= "<td class='oc_field'>$field</td>";
                $pull_required_html .= "<td class='rc_selects'>$map_select</td>";
                $pull_required_html .= "<td class='rc_event centered'>$event_name</td>";
                $pull_required_html .= "<td class='centered status pull $pull_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
                $pull_required_html .= "</tr>\r\n";
                if (!empty($value_map_html["html"])) {
                    $pull_required_html .= $value_map_html["html"];
                }
            }
        }
        $oc_select .= "<optgroup label='Required'>\r\n";
        $oc_select .= implode("", $req_options);
        $oc_select .= "</optgroup>\r\n";
        $oc_select .= implode("", $no_req_options);
        $oc_select .= "</select>\r\n";


        foreach($redcap_fields as $rc_field_name => $rc_field){
            //each select will have different input['name']
            $map_select     = str_replace("[REDCAP_FIELD]", $rc_field_name, $oc_select);
            $oncore_field   = $this->getMappedOncoreField($rc_field_name);
            $event_name     = $this->getRedcapEventName($rc_field_name);
            $rc_type        = $this->getRedcapType($rc_field_name);
            $push_status    = "";

            $value_map_html = $this->makeValueMappingUI_RC($oncore_field, $rc_field_name);
            $map_select     = str_replace("'$rc_field_name'", "'$rc_field_name' data-eventname='$event_name' data-type='$rc_type' ", $map_select);
            if (array_key_exists($oncore_field, $project_mappings)) {
                $json_vmapping      = json_encode($this->getMappedRedcapValueSet($oncore_field));
                $data_value_mapping = "data-val_mapping='{$json_vmapping}'";

                $map_select     = str_replace("'$oncore_field'", "'$oncore_field' selected ", $map_select);
                $map_select     = str_replace("'vmap-$rc_field_name'", $data_value_mapping, $map_select);
                $push_status    = $this->getPushStatus($oncore_field) ? "ok" : "";
            }

            $push_not_required .= "<tr class='$rc_field_name'>\r\n";
            $push_not_required .= "<td class='oc_field'>$map_select</td>";
            $push_not_required .= "<td class='rc_selects'>$rc_field_name</td>";
            $push_not_required .= "<td class='rc_event'>$event_name</td>";
            $push_not_required .= "<td class='centered status push $push_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
            $push_not_required .= "</tr>\r\n";
            if (!empty($value_map_html["html"])) {
                $push_not_required .= $value_map_html["html"];
            }
        }

        return array(   "project_mappings" => $project_mappings,
                        "oncore_fields" => $oncore_fields,
                        "pull"  => array(
                            "required"      => $pull_required_html,
                            "not_required"  => $pull_not_required
                        ),
                        "push"  => array(
                            "required"      => null,
                            "not_required"  => $push_not_required
                        ),

        );
    }

    /**
     * @return array
     */
    public function makeValueMappingUI($oncore_field, $redcap_field){
        $mapped_field   = $this->getMappedField($oncore_field);
        $value_mapping  = $this->getMappedRedcapValueSet($oncore_field);


        $value_map_html = "";
        $rc_values      = $this->getRedcapValueSet($redcap_field);
        $oc_values      = $this->getOncoreValueSet($oncore_field);
        $special_oncore = !empty($oc_values);

        //MAKE GENERIC REDCAP VALUE DROP DOWN
        $v_select   = "";
        if (!empty($rc_values)) {
            $v_select = "<select class='form-select form-select-sm redcap_value' name='[ONCORE_FIELD_VALUE]'>\r\n";
            $v_select .= "<option value=-99>-Map REDCap Value-</option>\r\n";
            foreach ($rc_values as $rc_idx => $rc_value) {
                $v_select .= "<option value='$rc_idx'>$rc_value</option>\r\n";
            }
            $v_select .= "</select>\r\n";
        }

        //IF ONCORE FIELD IS NOT TEXT or HAS FIXED VALUE SET THEN NEED TO MAP
        if ($special_oncore) {
            // IF OVER LAPPING MAPPING OF VALUES THEN PULL AND PUSH IS POSSIBLE
            $value_map_html .= "<tr class='$oncore_field more'><td colspan='4'>\r\n<table class='value_map'>\r\n";
            $value_map_html .= "<tr><th class='td_oc_vset'>Oncore Valid Values</th><th class='td_rc_vset'>Redcap Valid Values</th><th class='centered td_map_status'>Map Status</th><th class='td_vset_spacer'></th></tr>\r\n";

            foreach ($oc_values as $idx => $oc_value) {
                $value_map_status   = "";
                $value_select       = str_replace("[ONCORE_FIELD_VALUE]", $oncore_field . "_$idx", $v_select);
                if (array_key_exists($oc_value, $value_mapping)) {
                    $rc_val             = $value_mapping[$oc_value];
                    $value_select       = str_replace("'$rc_val'", "'$rc_val' selected", $value_select);
                    $value_map_status   = "ok";
                }
                $value_map_html .= "<tr>\r\n";
                $value_map_html .= "<td>$oc_value</td>\r\n";
                $value_map_html .= "<td>$value_select</td>\r\n";
                $value_map_html .= "<td class='centered value_map_status $value_map_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>\r\n";
                $value_map_html .= "<td></td>\r\n";
                $value_map_html .= "</tr>\r\n";
            }
            $value_map_html .= "</table>\r\n</td><td colspan='2'></td></tr>\r\n";
        }
        return array("html" => $value_map_html);
    }

    /**
     * @return array
     */
    public function makeValueMappingUI_RC($oncore_field, $redcap_field){
        $mapped_field   = $this->getMappedField($oncore_field);
        $value_mapping  = $this->getMappedRedcapValueSet($oncore_field);

        $value_map_html = "";
        $rc_values      = $this->getRedcapValueSet($redcap_field);
        $oc_values      = $this->getOncoreValueSet($oncore_field);
        $special_redcap = !empty($rc_values);

        //MAKE GENERIC REDCAP VALUE DROP DOWN
        $v_select   = "";
        if(!empty($oc_values)){
            $v_select = "<select class='form-select form-select-sm oncore_value' name='[REDCAP_FIELD_VALUE]'>\r\n";
            $v_select .= "<option value=-99>-Map OnCore Value-</option>\r\n";
            foreach ($oc_values as $oc_idx => $oc_value) {
                $v_select .= "<option value='$oc_idx'>$oc_value</option>\r\n";
            }
            $v_select .= "</select>\r\n";
        }

        if ($special_redcap) {
            // IF OVER LAPPING MAPPING OF VALUES THEN PULL AND PUSH IS POSSIBLE
            $value_map_html .= "<tr class='$redcap_field more'><td colspan='4'>\r\n<table class='value_map'>\r\n";
            $value_map_html .= "<tr><th class='td_oc_vset'>Oncore Valid Values</th><th class='td_rc_vset'>Redcap Valid Values</th><th class='centered td_map_status'>Map Status</th><th class='td_vset_spacer'></th></tr>\r\n";

            foreach ($rc_values as $idx => $rc_value) {
                $value_map_status   = "";
                $value_select       = str_replace("'[REDCAP_FIELD_VALUE]'", "'$redcap_field"."_"."$idx' data-oc_field='$oncore_field'", $v_select);

                if (array_search($idx, $value_mapping)) {
                    $oc_val         = array_search($idx, $value_mapping);
                    $value_select   = str_replace(">$oc_val", " selected>$oc_val", $value_select);
                    $value_map_status = "ok";
                }
                $value_map_html .= "<tr>\r\n";
                $value_map_html .= "<td>$value_select</td>\r\n";
                $value_map_html .= "<td>$rc_value</td>\r\n";
                $value_map_html .= "<td class='centered value_map_status $value_map_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>\r\n";
                $value_map_html .= "<td></td>\r\n";
                $value_map_html .= "</tr>\r\n";
            }
            $value_map_html .= "</table>\r\n</td><td colspan='2'></td></tr>\r\n";
        }
        return array("html" => $value_map_html);
    }



    /**
     * @param $OnCoreValue
     * @param $mappedValues
     * @return array|mixed
     */
    public static function getOnCoreMappedValue($OnCoreValue, $mappedValues)
    {
        foreach ($mappedValues as $mappedValue) {
            if ($OnCoreValue == $mappedValue['oc']) {
                return $mappedValue;
            }
        }
        return [];
    }

    /**
     * @param $REDCapValue
     * @param $mappedValues
     * @return array|mixed
     */
    public static function getREDCapMappedValue($REDCapValue, $mappedValues)
    {
        foreach ($mappedValues as $mappedValue) {
            if ($REDCapValue == $mappedValue['rc']) {
                return $mappedValue;
            }
        }
        return [];
    }
}
