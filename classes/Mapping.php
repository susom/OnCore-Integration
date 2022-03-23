<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

class Mapping
{
    private $module;

    public function __construct($module) {
        $this->module = $module;
    }

    /**
     * @return null
     */
    public function setFieldMapping($map_array=array()) {
        global $Proj;

        return;
    }

    /**
     * @return array
     */
    public function getFieldMapping() {
        global $module, $Proj;

        return $fieldMapping;
    }
}
