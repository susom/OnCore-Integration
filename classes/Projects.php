<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

class Projects
{
    private $module;

    public function __construct($module) {
        $this->module = $module;
    }

    public function isAutoNumberingRecords() {
        global $Proj;

        return $Proj->project["auto_inc_set"];
    }

    /**
     * @param $validationType - either 'text' or 'date'
     * @return array
     */
    public function getValidFields($validationType) {
        global $module, $Proj;

        $validFields = array();
        $surveyList =  $Proj->surveys;
        $fieldProp = $Proj->metadata;

        // This is a classical project - loop over the forms and see what should be included
        foreach($Proj->eventsForms as $event_id => $formList) {

            foreach($formList as $index => $formName) {

                // skip repeating forms and events
                if (!$Proj->isRepeatingFormOrEvent($event_id, $formName)) {

                    // Loop over forms and events
                    foreach ($Proj->forms[$formName]['fields'] as $fieldName => $fieldLabel) {

                        // See if this field is validated as a date
                        $valid = self::checkValidation($fieldProp[$fieldName], $validationType);
                        if ($valid) {

                            // See if this field is on a survey so it can be flagged
                            $onSurvey = self::onSurvey($surveyList, $formName);
                            if ($Proj->longitudinal) {
                                // Convert event_id to event_name
                                $event_name = $Proj->getUniqueEventNames($event_id);
                            } else {
                                $event_name = '';
                            }

                            // Save this field for display
                            $validFields[] = array("fieldName" => $fieldName, "eventName" => $event_name, "onSurvey" => $onSurvey);
                        }
                    }
                }
            }
        }

        return $validFields;
    }

    /**
     * This function will determine if the REDCap field is of the type specified by validationType.
     * The 2 options are date and text.
     *
     * @param $fieldProp
     * @param $validationType
     * @return bool - true/false
     */
    private function checkValidation($fieldProp, $validationType) {

        // If we are looking for a dates, make sure the field is being validated as a 'date'
        if ($validationType == 'date') {
            $valid = str_contains($fieldProp['element_validation_type'], $validationType);
        } else if ($validationType == 'text') {
            $valid = (($fieldProp['element_type'] == $validationType) and empty($fieldProp['element_validation_type']) ? true : false);
        }

        return $valid;
    }

    /**
     * This function will determine if the REDCap field is on a survey.
     *
     * @param $surveyList
     * @param $formName
     * @return bool - true/false
     */
    private function onSurvey($surveyList, $formName) {

        if (is_null($surveyList)) {
            // No surveys in this project
            return false;
        } else {
            foreach($surveyList as $survey_id => $surveyInfo) {

                // Field is on a survey and the survey is enabled
                if (($surveyInfo['form_name'] == $formName) and ($surveyInfo['survey_enabled'] == "1")) {
                    return true;
                }
            }

            // Field not on a survey
            return false;
        }
    }

}
