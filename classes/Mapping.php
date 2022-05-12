<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

use ExternalModules\ExternalModules;

class Mapping
{
    /**
     * @var \Stanford\OnCoreIntegration\OnCoreIntegration
     */
    private $module;
    private $oncore_fields;
    private $redcap_fields;
    private $project_mapping;
    private $oncore_subset;
    private $pushpull_pref;

    public function __construct($module)
    {
        $this->module           = $module;
        $this->oncore_fields    = $this->getOnCoreFieldDefinitions();
        $this->redcap_fields    = $this->getProjectFieldDictionary();
        $this->project_mapping  = !empty($this->getProjectFieldMappings()) ? $this->getProjectFieldMappings() : array("pull"=>array(),"push"=>array());
    }

    //GATHER THE REQUISITE DATAs INTO ARRAYS (Oncore, Redcap, FieldMappings)
    /**
     * @return array
     */
    public function getOnCoreFieldDefinitions()
    {
        $field_list     = json_decode(trim($this->module->getSystemSetting("oncore-field-definition")), true);
        $study_sites    = $this->module->getUsers()->getOnCoreStudySites();
        $field_list["studySites"] = array("oncore_valid_values" => $study_sites , "oncore_field_type" => array("text"));
        return $field_list;
    }

    /**
     * @return array
     */
    public function getProjectFieldDictionary()
    {
        global $Proj;

        $event_fields   = array();
        $events         = \REDCap::getEventNames(true);
        $dict           = \REDCap::getDataDictionary(PROJECT_ID, "array");

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

        $temp = array();
        foreach ($event_fields as $event_name => $field) {
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
        if ($this->project_mapping) {
            return $this->project_mapping;
        } else {
            $this->project_mapping = json_decode(ExternalModules::getProjectSetting($this->module->PREFIX, $this->module->getProjectId(), OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME), true);
            return $this->project_mapping ?: [];
        }
    }

    /**
     * @return null
     */
    public function setProjectFieldMappings($mappings = array())
    {
        $mappings = is_null($mappings) ? array() : $mappings;
        ExternalModules::setProjectSetting($this->module->PREFIX, $this->module->getProjectId(), OnCoreIntegration::REDCAP_ONCORE_FIELDS_MAPPING_NAME, json_encode($mappings));
        $this->project_mapping = $mappings;
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
        $field_set  = $this->getOncoreFields();
        $field      = array_key_exists($oncore_field, $field_set) ? $field_set[$oncore_field] : array();
        return $field;
    }

    /**
     * @return array
     */
    public function getOncoreValueSet($oncore_field)
    {
        $field      = $this->getOncoreField($oncore_field);
        $value_set  = array_key_exists("oncore_valid_values", $field) ? $field["oncore_valid_values"] : array();
        return $value_set;
    }

    /**
     * @return string
     */
    public function getOncoreType($oncore_field)
    {
        $field  = $this->getOncoreField($oncore_field);
        $type   = array_key_exists("oncore_field_type", $field) ? current($field["oncore_field_type"]) : "";
        return $type;
    }

    /**
     * @return bool
     */
    public function getOncoreRequired($oncore_field)
    {
        $field  = $this->getOncoreField($oncore_field);
        $req    = array_key_exists("required", $field) ? $field["required"] : false;
        return $req;
    }

    /**
     * @return string
     */
    public function getOncoreAlias($oncore_field)
    {
        $field  = $this->getOncoreField($oncore_field);
        $alias  = array_key_exists("alias", $field) && !empty($field["alias"]) ? $field["alias"] : $oncore_field;
        return $alias;
    }

    /**
     * @return string
     */
    public function getOncoreDesc($oncore_field)
    {
        $field  = $this->getOncoreField($oncore_field);
        $desc   = array_key_exists("description", $field) ? $field["description"] : "";
        return $desc;
    }


    /**
     * @return array
     */
    public function getMappedField($field_key, $push=false)
    {
        $mapping    = $this->getProjectMapping();
        $field_set  = $push ? $mapping["push"] : $mapping["pull"];

        $field      = array_key_exists($field_key, $field_set) ? $field_set[$field_key] : array();
        return $field;
    }

    /**
     * @return string
     */
    public function getMappedRedcapField($field_key, $push=false)
    {
        $field  = $this->getMappedField($field_key, $push);
        $name   = array_key_exists("redcap_field", $field) ? $field["redcap_field"] : "";
        return $name;
    }

    /**
     * @return array
     */
    public function getMappedRedcapValueSet($field_key, $push=false)
    {
        $field      = $this->getMappedField($field_key, $push);
        $value_set  = array_key_exists("value_mapping", $field) ? $field["value_mapping"] : array();

        $temp = array();
        if (!empty($value_set)) {
            if($push){
                foreach ($value_set as $set) {
                    $temp[$set["rc"]] = $set["oc"];
                }
            }else{
                foreach ($value_set as $set) {
                    $temp[$set["oc"]] = $set["rc"];
                }
            }
        }

        // RETURN FORMATTED ARRAY
        return $temp;
    }

    /**
     * @return string
     */
    public function getMappedOncoreField($redcap_field, $push=false)
    {
        $temp               = $this->getProjectMapping();
        $project_mapping    = $push ? $temp["push"] : $temp["pull"];
        foreach($project_mapping as $field_key => $mapping){
            if($mapping["redcap_field"] == $redcap_field){
                return $field_key;
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
        $fields = $this->getRedcapFields();
        $field  = array_key_exists($redcap_field, $fields) ? $fields[$redcap_field] : array();
        return $field;
    }

    /**
     * @return string
     */
    public function getRedcapType($redcap_field)
    {
        $field  = $this->getRedcapField($redcap_field);
        $type   = array_key_exists("redcap_field_type", $field) ? $field["redcap_field_type"] : "";
        return $type;
    }

    /**
     * @return string
     */
    public function getRedcapEventName($redcap_field)
    {
        $field  = $this->getRedcapField($redcap_field);
        $event  = array_key_exists("event_name", $field) ? $field["event_name"] : "";
        return $event;
    }

    /**
     * @return array
     */
    public function getRedcapValueSet($redcap_field)
    {
        $field      = $this->getRedcapField($redcap_field);
        $value_set  = array_key_exists("select_choices", $field) ? $field["select_choices"] : array();
        return $value_set;
    }


    //GET PULL PUSH STATUS
    /**
     * @return array
     */
    public function calculatePushPullStatus($field_key)
    {
        $pull_status        = false;
        $push_status        = false;
        $oc_field_map_pull  = $this->getMappedField($field_key);
        $oc_field_map_push  = $this->getMappedField($field_key, 1);



        //has pull mapping
        if (!empty($oc_field_map_pull)) {
            $redcap_field   = $this->getMappedRedcapField($field_key);
            $rc_type        = $this->getRedcapType($redcap_field);
            $oncore_vset    = $this->getOncoreValueSet($field_key);
            $vmap_format    = $this->getMappedRedcapValueSet($field_key);

            if (!empty($vmap_format)) {
                //has value set mapping
                $oncore_coverage = array_diff($oncore_vset, array_keys($vmap_format));

                if (empty($oncore_coverage)) {
                    //this means all the valid oncore values have been mapped to a redcap value
                    $pull_status = true;
                }
            } elseif ($rc_type == "text") {
                //if redcap field type is text, then it can accept anything always
                $pull_status = true;
            }
        }

        if (!empty($oc_field_map_push)) {
            $redcap_field   = $this->getMappedRedcapField($field_key,1);
            $rc_type        = $this->getRedcapType($redcap_field);
            $oncore_vset    = $this->getOncoreValueSet($field_key);
            $vmap_format    = $this->getMappedRedcapValueSet($field_key,1);
            $rc_vset        = $this->getRedcapValueSet($redcap_field);

            if (!empty($vmap_format)) {
                //has value set mapping
                $rc_coded_values = array_keys($rc_vset);
                $redcap_coverage = array_diff($rc_coded_values, array_keys($vmap_format));
                if (empty($redcap_coverage)) {
                    //this means all valid redcap values have been mapped to an oncore value
                    $push_status = true;
                }
            } elseif ($rc_type == "text") {
                //if redcap field type is text, then it can accept anything always
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
    public function getPullStatus($field_key)
    {
        $status = $this->calculatePushPullStatus($field_key);
        return $status["pull"];
    }

    /**
     * @return bool
     */
    public function getPushStatus($field_key)
    {
        $status = $this->calculatePushPullStatus($field_key, 1);
        return $status["push"];
    }

    /**
     * @return bool
     */
    public function getOverallPullStatus()
    {
        $all_pull_ok = true;
        foreach($this->getProjectOncoreSubset() as $oncore_field){
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
        $all_push_ok = true;
        foreach($this->getOncoreRequiredFields() as $oncore_field){
            if(!$this->getPushStatus($oncore_field)){
                $all_push_ok = false;
                break;
            }
        }
        return $all_push_ok;
    }


    //ONCORE SUBSET
    public function setProjectOncoreSubset(array $oncore_subset): void
    {
        ExternalModules::setProjectSetting($this->module->getProtocols()->getUser()->getPREFIX(), $this->module->getProtocols()->getEntityRecord()['redcap_project_id'], OnCoreIntegration::REDCAP_ONCORE_PROJECT_ONCORE_SUBSET, json_encode($oncore_subset));
        $this->oncore_subset = $oncore_subset;
    }

    public function getProjectOncoreSubset()
    {
        if(empty($this->oncore_subset)){
            $arr = json_decode(ExternalModules::getProjectSetting($this->module->getProtocols()->getUser()->getPREFIX(), $this->module->getProtocols()->getEntityRecord()['redcap_project_id'], OnCoreIntegration::REDCAP_ONCORE_PROJECT_ONCORE_SUBSET), true);
            $this->oncore_subset = $arr ?: [];
        }
        return $this->oncore_subset;
    }

    //Porject PUSH PULL PREF
    public function setProjectPushPullPref(array $pushpull_state): void
    {
        ExternalModules::setProjectSetting($this->module->getProtocols()->getUser()->getPREFIX(), $this->module->getProtocols()->getEntityRecord()['redcap_project_id'], OnCoreIntegration::REDCAP_ONCORE_PROJECT_PUSHPULL_PREF, json_encode($pushpull_state));
        $this->pushpull_pref = $pushpull_state;
    }

    public function getProjectPushPullPref()
    {
        if(empty($this->pushpull_pref)){
            $arr = json_decode(ExternalModules::getProjectSetting($this->module->getProtocols()->getUser()->getPREFIX(), $this->module->getProtocols()->getEntityRecord()['redcap_project_id'], OnCoreIntegration::REDCAP_ONCORE_PROJECT_PUSHPULL_PREF), true);
            $this->pushpull_pref = $arr ?: [];
        }
        return $this->pushpull_pref;
    }


    //DRAW UI
    /**
     * @return array
     */
    public function makeFieldMappingUI()
    {
        $project_oncore_sub = $this->getProjectOncoreSubset();
        $project_fields     = $this->getRedcapFields();
        $oncore_fields      = $this->getOnCoreFields();
        $project_mappings   = $this->getProjectMapping();

        $pull_html = "";
        $push_html  = "";

        //REDCap Data Dictionary Fields w/ generic 'xxx'name
        $event_fields = array();
        foreach ($project_fields as $rc_field_name => $field) {
            $event_name = $field["event_name"];
            if(!array_key_exists($event_name, $event_fields)){
                $event_fields[$event_name] = array();
            }
            $field_choices                  = !empty($field["select_choices"]) ? $field["select_choices"] : array();
            $event_fields[$event_name][]    = "<option data-eventname='$event_name' data-value_set='" . json_encode($field_choices) . "' data-type='{$field["redcap_field_type"]}' value='$rc_field_name' 'vmap-{$rc_field_name}'>$rc_field_name</option>\r\n";
        }
        $rc_select = "<select class='form-select form-select-sm mrn_field redcap_field' name='[ONCORE_FIELD]' data-mapdir='[MAP_DIRECTION]'>\r\n";
        $rc_select .= "<option value=-99>-Map REDCap Field-</option>\r\n";
        foreach($event_fields as $event_name => $fields){
            $rc_select .= "<optgroup label='$event_name'>\r\n";
            $rc_select .= implode("", $fields);
            $rc_select .= "</optgroup>\r\n";
        }
        $rc_select .= "</select>\r\n";

        //OnCore Static Field names need mapping to REDCap fields
        foreach($oncore_fields as $field => $field_details) {
            //ONLY SHOW THOSE IN THE CHOSEN SUBSET


            //each select will have different input['name']
            $rc_map_select     = str_replace("[ONCORE_FIELD]", $field, $rc_select);

            if(in_array($field, $project_oncore_sub)){
                $pull_status            = "";
                $pull_value_map_html    = "";
                $event_name             = "";
                $pull_rc_map_select     = $rc_map_select;
                if (array_key_exists($field, $project_mappings["pull"])) {
                    $rc_field               = $project_mappings["pull"][$field];
                    $rc_field_name          = $rc_field["redcap_field"];
                    $event_name             = $this->getRedcapEventName($rc_field_name);
                    $json_vmapping          = json_encode($this->getMappedRedcapValueSet($field));
                    $data_value_mapping     = "data-val_mapping='{$json_vmapping}'";
                    $pull_rc_map_select     = str_replace("'$rc_field_name'", "'$rc_field_name' selected ", $pull_rc_map_select);
                    $pull_rc_map_select     = str_replace("'vmap-$rc_field_name'", $data_value_mapping, $pull_rc_map_select);
                    $pull_value_map_html    = $this->makeValueMappingUI($field, $rc_field_name);
                    $pull_status            = $this->getPullStatus($field) ? "ok" : "";
                }
                $pull_html .= "<tr class='$field'>\r\n";
                $pull_html .= "<td class='oc_field'>$field</td>";
                $pull_html .= "<td class='rc_selects'>$pull_rc_map_select</td>";
                $pull_html .= "<td class='rc_event centered'>$event_name</td>";
                $pull_html .= "<td class='centered status pull $pull_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
                $pull_html .= "</tr>\r\n";
                if (!empty($pull_value_map_html["html"])) {
                    $pull_html .= $pull_value_map_html["html"];
                }
            }

            $push_status            = "";
            $push_value_map_html    = "";
            $event_name             = "";
            $push_rc_map_select     = $rc_map_select;
            if (array_key_exists($field, $project_mappings["push"])) {
                $rc_field_name          = $this->getMappedRedcapField($field,1 );
                $oncore_field           = $field;
                $event_name             = $this->getRedcapEventName($rc_field_name);
                $rc_type                = $this->getRedcapType($rc_field_name);

                $push_value_map_html    = $this->makeValueMappingUI_RC($oncore_field, $rc_field_name);
                $json_vmapping          = json_encode($this->getMappedRedcapValueSet($oncore_field, 1));
                $data_value_mapping     = "data-val_mapping='{$json_vmapping}'";

                $push_rc_map_select     = str_replace("'$rc_field_name'", "'$rc_field_name' selected ", $push_rc_map_select);
                $push_rc_map_select     = str_replace("'vmap-$rc_field_name'", $data_value_mapping, $push_rc_map_select);

                $push_status            = $this->getPushStatus($oncore_field) ? "ok" : "";
            }
            $push_rc_map_select = str_replace("[MAP_DIRECTION]", "push", $push_rc_map_select);
            $push_html .= "<tr class='$field'>\r\n";
            $push_html .= "<td class='oc_field'>$field</td>";
            $push_html .= "<td class='rc_selects'>$push_rc_map_select</td>";
            $push_html .= "<td class='rc_event centered'>$event_name</td>";
            $push_html .= "<td class='centered status push $push_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
            $push_html .= "</tr>\r\n";
            if (!empty($push_value_map_html["html"])) {
                $push_html .= $push_value_map_html["html"];
            }
        }

        return array(   "project_mappings"  => $project_mappings,
                        "oncore_fields"     => $oncore_fields,
                        "pull"  => $pull_html,
                        "push"  => $push_html,
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
                $value_select       = str_replace("'[ONCORE_FIELD_VALUE]'", "'$oncore_field"."_"."$idx' data-oc_field='$oncore_field' data-rc_field='$redcap_field'", $v_select);
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
        $mapped_field   = $this->getMappedField($oncore_field,1);
        $value_mapping  = $this->getMappedRedcapValueSet($oncore_field,1);

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
            $value_map_html .= "<tr class='$oncore_field more'><td colspan='4'>\r\n<table class='value_map'>\r\n";
            $value_map_html .= "<tr><th class='td_oc_vset'>Oncore Valid Values</th><th class='td_rc_vset'>Redcap Valid Values</th><th class='centered td_map_status'>Map Status</th><th class='td_vset_spacer'></th></tr>\r\n";

            foreach ($rc_values as $idx => $rc_value) {
                $value_map_status   = "";
                $value_select       = str_replace("'[REDCAP_FIELD_VALUE]'", "'$redcap_field"."_"."$idx' data-oc_field='$oncore_field' data-rc_field='$redcap_field'", $v_select);

                if (array_key_exists($idx, $value_mapping)) {
                    $oc_val         = $value_mapping[$idx];
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
    public function getOnCoreMappedValue($OnCoreValue, $mappedValues)
    {
        //$mappedValues = $mappedValues['value_mapping_pull'];
        $mappedValues = $mappedValues['value_mapping'];
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
    public function getREDCapMappedValue($REDCapValue, $mappedValues)
    {
        //$mappedValues = $mappedValues['value_mapping_push'];
        $mappedValues = $mappedValues['value_mapping'];
        foreach ($mappedValues as $mappedValue) {
            if ($REDCapValue == $mappedValue['rc']) {
                return $mappedValue;
            }
        }
        return [];
    }
}
