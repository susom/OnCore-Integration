<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

class Mapping
{
    private $module;
    private $oncore_fields;
    private $redcap_fields;
    private $project_mapping;

    public function __construct($module)
    {
        $this->module = $module;
        $this->oncore_fields = $this->getOnCoreFieldDefinitions();
        $this->redcap_fields = $this->getProjectFieldDictionary();
        $this->project_mapping = $this->getProjectFieldMappings();
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
        $alias = array_key_exists("alias", $field) ? $field["alias"] : "";
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
        $pull_status = false;
        $push_status = false;
        $oc_field_map = $this->getMappedField($oncore_field);

        //has mapping
        if (!empty($oc_field_map)) {
            $redcap_field = $oc_field_map["redcap_field"];
            $oncore_vset = $this->getOncoreValueSet($oncore_field);
            $vmap_format = $this->getMappedRedcapValueSet($oncore_field);
            $rc_type = $this->getMappedRedcapType($oncore_field);
            $rc_vset = $this->getRedcapValueSet($redcap_field);

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


    //DRAW UI

    /**
     * @return array
     */
    public function makeFieldMappingUI()
    {
        $project_fields = $this->getRedcapFields();
        $oncore_fields = $this->getOnCoreFields();
        $project_mappings = $this->getProjectMapping();
        $redcap_fields = array();

        //REDCap Data Dictionary Fields w/ generic 'xxx'name
        $select = "<select class='form-select form-select-sm mrn_field redcap_field' name='[ONCORE_FIELD]'>\r\n";
        $select .= "<option value=-99>-Map REDCap Field-</option>";
        foreach ($project_fields as $event_name => $fields) {
            $select .= "<optgroup label='$event_name'>\r\n";
            foreach ($fields as $field) {
                $redcap_fields[$field["name"]] = $field;
                $field_choices = array();
                if (!empty($field["select_choices"])) {
                    $temp = explode("|", $field["select_choices"]);
                    foreach ($temp as $temp2) {
                        $temp3 = explode(",", $temp2);
                        $rc_i = trim($temp3[0]);
                        $rc_v = trim($temp3[1]);
                        $field_choices[$rc_i] = $rc_v;
                    }
                }

                $select .= "<option data-eventname='$event_name' data-value_set='" . json_encode($field_choices) . "' data-type='{$field["field_type"]}' value='{$field["name"]}'>{$field["name"]}</option>\r\n";
            }
            $select .= "</optgroup>\r\n";
        }
        $select .= "</select>\r\n";

        //OnCore Static Field names need mapping to REDCap fields
        $required_html = "";
        $not_required = "";
        $not_shown = 0;
        foreach ($oncore_fields as $field => $field_details) {
            //each select will have different input['name']
            $map_select = str_replace("[ONCORE_FIELD]", $field, $select);
            $pull_status = "";
            $push_status = "";

            $required = null;
            $event_name = null;

            $rc_field = $project_mappings[$field];
            $ftype = $rc_field["field_type"];
            $oncore_type = current($field_details["oncore_field_type"]);
            $oncore_vset = $field_details["oncore_valid_values"];

            if ($field_details["required"] == "true") {
                $required = "required";
            }

            $special_oncore = $oncore_type != "text" || !empty($oncore_vset);

            $value_map_html = "";

            if (array_key_exists($field, $project_mappings)) {
                $selected = "selected";
                $rc = $rc_field["redcap_field"];
                $event_name = $rc_field["event"];
                $redcap_vset = explode(" | ", $redcap_fields[$rc]["select_choices"]);

                if ($ftype == "text") {
                    $pull_icon = "fa-check-circle";
                }
                if ($oncore_type == "text") {
                    $push_icon = "fa-check-circle";
                }

                //MAP REDCAP VALUES
                $v_select = "";
                $rc_vset = array();
                if (!empty($redcap_vset)) {
                    $v_select = "<select class='form-select form-select-sm redcap_value' name='[ONCORE_FIELD_VALUE]'>\r\n";
                    $v_select .= "<option value=-99>-Map REDCap Value-</option>";
                    foreach ($redcap_vset as $rc_value) {
                        $temp = explode(",", $rc_value);
                        $rc_idx = trim($temp[0]);
                        $v_select .= "<option value='$rc_idx'>$rc_value</option>\r\n";
                        $rc_vset[$rc_idx] = $rc_value;
                    }
                    $v_select .= "</select>\r\n";
                }

                $vmapping = array();
                if ($special_oncore) {
                    $vmaps = $rc_field["value_mapping"];
                    if (!empty($vmaps)) {
                        foreach ($vmaps as $map) {
                            $vmapping[$map["oc"]] = $map["rc"];
                        }
                    }

                    // IF OVER LAPPING MAPPING OF VALUES THEN PULL AND PUSH IS POSSIBLE
                    $push_icon = empty(array_diff($oncore_vset, array_keys($vmapping))) ? "fa-check-circle" : "fa-times-circle";
                    $pull_icon = empty(array_diff(array_keys($rc_vset), array_values($vmapping))) ? "fa-check-circle" : "fa-times-circle";

                    $value_map_html .= "<tr class='$field more'><td colspan='4'>\r\n<table class='value_map'>";
                    $value_map_html .= "<tr><th class='td_oc_vset'>Oncore Value Set</th><th class='td_rc_vset'>Redcap Value Set</th><th class='centered td_map_status'>Map Status</th></tr>\r\n";
                    foreach ($oncore_vset as $idx => $oc_value) {
                        $value_map_status = "fa-times-circle"; //"fa-check-circle";

                        $value_select = str_replace("[ONCORE_FIELD_VALUE]", $field . "_$idx", $v_select);
                        if (array_key_exists($oc_value, $vmapping)) {
                            $rc_val = $vmapping[$oc_value];
                            $value_select = str_replace("'$rc_val'", "'$rc_val' selected", $value_select);
                            $value_map_status = "fa-check-circle";
                        }
                        $value_map_html .= "<tr>\r\n";
                        $value_map_html .= "<td>$oc_value</td>\r\n";
                        $value_map_html .= "<td>$value_select</td>\r\n";
                        $value_map_html .= "<td class='centered'><i class='fa $value_map_status'></i></td>\r\n";
                        $value_map_html .= "</tr>\r\n";
                    }
                    $value_map_html .= "</table>\r\n</td><td colspan='2'></td></tr>\r\n";
                }
                $map_select = str_replace("'$rc'", "'$rc' $selected", $map_select);
            }

            if (!$required) {
                $not_shown++;
                $not_required .= "<tr class='$field notrequired'>\r\n";
                $not_required .= "<td class='oc_field'>$field</td>";
                $not_required .= "<td class='oc_type'>$oncore_type</td>";
                $not_required .= "<td class='rc_selects'>$map_select</td>";
                $not_required .= "<td class='rc_event'>$event_name</td>";
                $not_required .= "<td class='centered status pull $pull_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
                $not_required .= "<td class='centered status push $push_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
                $not_required .= "</tr>\r\n";
                if (!empty($value_map_html)) {
                    $not_required .= $value_map_html;
                }
            } else {
                $required_html .= "<tr class='$field $required'>\r\n";
                $required_html .= "<td class='oc_field'>$field</td>";
                $required_html .= "<td class='oc_type'>$oncore_type</td>";
                $required_html .= "<td class='rc_selects'>$map_select</td>";
                $required_html .= "<td class='rc_event'>$event_name</td>";
                $required_html .= "<td class='centered status pull $pull_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
                $required_html .= "<td class='centered status push $push_status'><i class='fa fa-times-circle'></i><i class='fa fa-check-circle'></i></td>";
                $required_html .= "</tr>\r\n";
                if (!empty($value_map_html)) {
                    $required_html .= $value_map_html;
                }
            }
        }

        return array("required" => $required_html, "not_required" => $not_required, "project_mappings" => $project_mappings, "oncore_fields" => $oncore_fields);
    }
}
