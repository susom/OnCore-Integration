<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

use REDCap;


// Retrieve data from entity table where OnCore fields are located.  We will only save the data that is mapped
// $save_data = Protocol->getRedcapUpdates();
// All records that have the ONCORE_ONLY and PARTIAL_MATCH statuses and do not have the EXCLUDE flag set.
/*
$onCoreData = array(
    array("record_id" => "1", "firstName" => "Abe", "lastName" => "Lincoln", "mrn" => "12345678", "gender" => "female", "ethnicity" => "Unknown", "race" => "Unknown"),
    array("record_id" => "2", "gender" => "female")
);
*/
// Retrieve OnCore data for this project
$onCoreData = $module->getProtocols()->getSubjects()->getSyncedRecords();


// Even if the data saved are REDCap field names, we need to find which event to save it in
// Assuming field mapping is something like this
//$field_mapping = $module->getProjectSettings('name');
//          oncore_field_name    redcap_field_name                  redcap_event_name
// for longitudinal projects
/*
$field_mapping = array(
        "firstName"    => array("redcap_field"  => "sub_first_name",    "event" => "baseline_arm_1"),
        "lastName"     => array("redcap_field"  => "sub_last_name",     "event" => "baseline_arm_1"),
        "ethnicity"    => array("redcap_field"  => "sub_ethnicity",     "event" => "enrollment_arm_1"),
        "race"         => array("redcap_field"  => "sub_race",          "event" => "enrollment_arm_1"),
        "gender"       => array("redcap_field"  => "sub_gender",        "event" => "enrollment_arm_1"),
        "mrn"          => array("redcap_field"  => "sub_mrn",           "event" => "baseline_arm_1")
    );
*/

//$field_mapping = $module->getProjectSetting('redcap-oncore-fields-mapping');
$field_mapping = $this->getProtocols()->getFieldsMap();

// Translate whatever data we are update to redcap event/fields
$saveData = mapOncoreToRedcapFields($onCoreData, $field_mapping);

// Save the data into the REDCap project
$status = saveOnCoreDataInRedcap($saveData);

// Update the status in the OnCore entity for REDCap records that were updated
if ($status) {
    $status = updateEntityStatus();
}


/**
 * Map the OnCore data to fields/events in the REDCap project using the field mapping selected by users.
 *
 * @param $onCoreData
 * @param $field_mapping
 * @return array
 */
function mapOncoreToRedcapFields($onCoreData, $field_mapping) {

    global $module;

    // Retrieve the primary key for this project
    $pk_field = REDCap::getRecordIdField();

    // Map the OnCore data to REDCap fields
    $status = true;
    $saveData = array();
    foreach($onCoreData as $oneRecord) {
        $record_id = '';
        $this_record = array();
        foreach($oneRecord as $field_name => $field_value) {

            if ($field_name == "record_id") {
                $record_id = $field_value;
            } else {

                // Find the REDCap field name and event to store this data
                $redcap_field = $field_mapping[$field_name];
                if (!empty($redcap_field)) {
                    if (REDCap::isLongitudinal()) {
                        $this_record[$redcap_field["event"]][$redcap_field['redcap_field']] = $field_value;
                    } else {
                        $this_record[$redcap_field['redcap_field']] = $field_value;
                    }
                }
            }
        }

        // If this is a longitudinal project, put the event id in the array
        if (!empty($record_id)) {
            if (REDCap::isLongitudinal()) {
                $json_format = array();
                $events = array_keys($this_record);
                foreach ($events as $event_key => $event_name) {
                    $record_info = array("$pk_field" => $record_id, "redcap_event_name" => $event_name);
                    $json_format = array_merge($record_info, $this_record[$event_name]);
                    $saveData[] = $json_format;
                }
            } else {
                $this_record[$pk_field] = $record_id;
                $saveData[] = $this_record;
            }
        }
    }

    $module->emDebug("Data to Save: " . json_encode($saveData));
    return $saveData;
}

/**
 * Save the updates to the REDCap project
 *
 * @param $saveData
 * @return bool
 */
function saveOnCoreDataInRedcap($saveData) {

    global $module;

    // If there is data to save, save it now
    if (!empty($saveData)) {
        $return = REDCap::saveData('json', json_encode($saveData));
        if (empty($return['errors'])) {
            $module->emDebug("Saved OnCore demographics data: " . json_encode($return['ids']));
            $status = true;
        } else {
            $module->emError("Could not save OnCore demographics data: " . json_encode($return['errors']));
            $status = false;
        }
    }

    return $status;

}

/**
 * Update the status in the OnCore entity table for the records that we've updated
 *
 * @return bool
 */
function updateEntityStatus() {
    $status = true;
    return $status;
}
