<?php

namespace Stanford\OnCoreIntegration;

class Entities
{
    use emLoggerTrait;

    const GENERAL = 0;
    const PUSH_TO_ONCORE_FROM_ONCORE = 1;
    const PUSH_TO_ONCORE_FROM_ON_STAGE = 2;
    const PUSH_TO_ONCORE_FROM_REDCAP = 3;
    const PULL_FROM_ONCORE = 4;

    /**
     * @var \REDCapEntity\EntityFactory
     */
    private $factory;

    public $errors = '';

    /**
     * Create log record in Entity OnCore Actions log table
     * @param $message
     * @param $url
     * @param $response
     * @param $type
     * @return void
     */
    public static function createLog($message, $type = 0, $url = '', $response = '')
    {
        $data = array(
            'message' => $message,
            'url' => $url,
            'response' => $response,
            'type' => $type
        );
        // use this to reduce Mysql Server Gone error.
        if (defined('PROJECT_ID')) {
            $sql = sprintf("INSERT INTO %s (message, url, response, type, created, updated, redcap_project_id) VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s')", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_API_ACTIONS_LOG), db_escape($message), db_escape($url), db_escape($response), db_escape($type), db_escape(time()), db_escape(time()), db_escape(PROJECT_ID));
        } else {
            $sql = sprintf("INSERT INTO %s (message, url, response, type, created, updated) VALUES ('%s', '%s', '%s', '%s', '%s', '%s')", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_API_ACTIONS_LOG), db_escape($message), db_escape($url), db_escape($response), db_escape($type), db_escape(time()), db_escape(time()));
        }

        //$entity = (new Entities)->create(OnCoreIntegration::ONCORE_REDCAP_API_ACTIONS_LOG, $data);
        $entity = db_query($sql);
        if (!$entity) {
            \REDCap::logEvent('Could not create log');
            $e = (new Entities);
            $e->emError('Could not create log');
            $e->emLog($data);
        }
//        else {
//            (new Entities)->emLog($data);
//        }

    }


    public static function getTypeText($type)
    {
        switch ($type) {
            case self::GENERAL:
                return 'General';
            case self::PUSH_TO_ONCORE_FROM_ONCORE:
                return 'Push to OnCore from Existing OnCore Subject';
            case self::PUSH_TO_ONCORE_FROM_ON_STAGE:
                return 'Push to OnCore from Existing OnStage Subject';
            case self::PUSH_TO_ONCORE_FROM_REDCAP:
                return 'Push to OnCore from REDCap Record';
            case self::PULL_FROM_ONCORE:
                return 'Pull From OnCore';
        }
    }

    /**
     * Create an Exception message in Entity OnCore Actions log table
     * @param $message
     * @return void
     */
    public static function createException($message)
    {
        //(new Entities)->emError('Could not create log');
        // Test
        self::createLog('EXCEPTION: ' . $message);
    }

    public function create($table, $data)
    {
        $data['created'] = time();
        $data['updated'] = time();
        $keys_text = implode(',', array_keys($data));
        $fmt = trim(str_repeat("'%s',", count($data)), ',');
        switch ($table) {
            case OnCoreIntegration::ONCORE_PROTOCOLS:
                $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS;
                break;
            case OnCoreIntegration::ONCORE_SUBJECTS:
                $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SUBJECTS;
                break;
            case OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE:
                $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE;
                break;
            default:
                throw new \Exception($table . ' is not recognized!');
        }
        $temp = array();
        $temp[] = db_escape($table);
        foreach ($data as $item) {
            if (is_array($item)) {
                $t = json_encode($item, JSON_THROW_ON_ERROR);
                $temp[] = $t;
            } else {
                $temp[] = db_escape($item);
            }
        }
        $sql = vsprintf('INSERT INTO %s (' . $keys_text . ') VALUES (' . $fmt . ')', $temp);
        $result = db_query(str_replace("\\", '', db_escape($sql)));
        if (!$result) {
            \REDCap::logEvent('Could not create log');
            $e = (new Entities);
            $e->emError('Could not create log');
            $e->emLog($data);
            $this->errors = db_error();
        } else {
            $id = db_insert_id();
            $sql = sprintf("SELECT * FROM %s WHERE id = %s", db_escape($table), db_escape($id));
            $result = db_query($sql);
            return db_fetch_assoc($result);
        }
    }

    public function update($table, $id, $data)
    {
        $data['updated'] = time();
        $fmt = '';
        foreach ($data as $key => $value) {
            $fmt .= "$key = '%s',";
        }
        $fmt = rtrim($fmt, ',');
        switch ($table) {
            case OnCoreIntegration::ONCORE_PROTOCOLS:
                $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_PROTOCOLS;
                break;
            case OnCoreIntegration::ONCORE_SUBJECTS:
                $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_SUBJECTS;
                break;
            case OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE:
                $table = OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_RECORD_LINKAGE;
                break;
            default:
                throw new \Exception($table . ' is not recognized!');
        }
        $temp = array();
        $temp[] = db_escape($table);
        foreach ($data as $item) {
            if (is_array($item)) {
                $t = json_encode($item, JSON_THROW_ON_ERROR);
                $temp[] = $t;
            } else {
                $temp[] = db_escape($item);
            }
        }
        $temp[] = db_escape($id);
        $sql = vsprintf('UPDATE %s SET ' . $fmt . ' WHERE id = %s', $temp);
        $result = db_query(str_replace("\\", '', db_escape($sql)));
        return $result;
    }

    public static function getREDCapProjectLogs($pid)
    {
        $result = [];
        $sql = sprintf("SELECT * FROM %s WHERE redcap_project_id = %s", db_escape(OnCoreIntegration::REDCAP_ENTITY_ONCORE_REDCAP_API_ACTIONS_LOG), db_escape($pid));
        $records = db_query($sql);
        while ($row = db_fetch_assoc($records)) {
            unset($row['redcap_project_id']);
            $row['created'] = date('m-d-Y h:i:s', $row['created']);
            $row['updated'] = date('m-d-Y h:i:s', $row['updated']);
            $result[] = $row;
        }
        return $result;
    }

}
