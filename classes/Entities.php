<?php

namespace Stanford\OnCoreIntegration;

class Entities extends \REDCapEntity\EntityFactory
{
    use emLoggerTrait;

    public static function createLog($message, $url = '', $response = '', $type = 0)
    {
        $data = array(
            'message' => $message,
            'url' => $url,
            'response' => $response,
            'type' => $type
        );
        $entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_API_ACTIONS_LOG, $data);

        if (!$entity) {
            \REDCap::logEvent('Could not create log');
            (new Entities)->emError('Could not create log');
        } else {
            (new Entities)->emLog($data);
        }

    }

    public static function createException($message)
    {
        self::createLog('EXCEPTION: ' . $message);
    }
}
