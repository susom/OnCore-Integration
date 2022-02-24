<?php

namespace Stanford\OnCoreIntegration;

class Entities extends \REDCapEntity\EntityFactory
{
    public static function createLog($message, $url = '', $response = '', $type = 0)
    {
        $data = array(
            'message' => date('m/d/Y H:i:s') . ' :' . $message,
            'url' => $url,
            'response' => $response,
            'type' => $type
        );
        $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_API_ACTIONS_LOG, $data);

        if (!$entity) {
            \REDCap::logEvent('Could not create log');
        }
    }

    public static function createException($message)
    {
        self::createException('EXCEPTION: ' . $message);
    }
}
