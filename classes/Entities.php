<?php

namespace Stanford\OnCoreIntegration;

class Entities extends \REDCapEntity\EntityFactory
{
    use emLoggerTrait;

    /**
     * Create log record in Entity OnCore Actions log table
     * @param $message
     * @param $url
     * @param $response
     * @param $type
     * @return void
     */
    public static function createLog($message, $url = '', $response = '', $type = 0)
    {
        $data = array(
            'message' => $message,
            'url' => $url,
            'response' => $response,
            'type' => $type
        );
        // use this to reduce Mysql Server Gone error.
        $sql = sprintf("INSERT INTO %s (message, url, response, type, created, updated) VALUES ('%s', '%s', '%s', '%s', '%s', '%s')", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_API_ACTIONS_LOG), db_escape($message), db_escape($url), db_escape($response), db_escape($type), db_escape(time()), db_escape(time()));
        //$entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_API_ACTIONS_LOG, $data);
        $entity = db_query($sql);
        if (!$entity) {
            \REDCap::logEvent('Could not create log');
            (new Entities)->emError('Could not create log');
            (new Entities)->emLog($data);
        }
//        else {
//            (new Entities)->emLog($data);
//        }

    }

    /**
     * Create an Exception message in Entity OnCore Actions log table
     * @param $message
     * @return void
     */
    public static function createException($message)
    {
        (new Entities)->emError('Could not create log');
        self::createLog('EXCEPTION: ' . $message);
    }
}
