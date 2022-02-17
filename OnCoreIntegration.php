<?php
namespace Stanford\OnCoreIntegration;

require_once "emLoggerTrait.php";
require_once 'classes/Users.php';

/**
 * Class OnCoreIntegration
 * @package Stanford\OnCoreIntegration
 * @property \Stanford\OnCoreIntegration\Users $users;
 */
class OnCoreIntegration extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    private $users;

    public function __construct()
    {
        parent::__construct();

        if (isset($_GET['pid'])) {
            $this->setUsers(new Users($this->PREFIX, $this->getProjectId()));
        }
        // Other code to run when object is instantiated
    }

	public function redcap_module_system_enable( $version ) {

	}


    public function redcap_module_project_enable($version, $project_id)
    {

    }


    public function redcap_module_save_configuration($project_id)
    {

    }

    /**
     * @return mixed
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * @param mixed $users
     */
    public function setUsers($users): void
    {
        $this->users = $users;
    }


}
