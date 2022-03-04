<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

use REDCapEntity\EntityList;
use REDCapEntity\EntityDB;

define ('DB_NAME', 'oncore_demo_options');

// Check to see if the entity exists
$entity = new EntityDB();
$db_exists = $entity->checkEntityDBTable(DB_NAME);
if ($db_exists) {

    $list = new EntityList('oncore_demo_options', $module);

    // Enabling all operations in the Control Center
    $list->setOperations(['create', 'update', 'delete'])
        ->setCols(['oncore_demo_field', 'oncore_demo_option'])
        ->setSortableCols(['oncore_demo_field', 'oncore_demo_option'])
        ->setExposedFilters(['created_by', ''])
        ->render('control_center');

} else {
    $module->emError("Table does not exist.  Please go create it first");
    $url = 'http://localhost/redcap_v12.2.2/ExternalModules/?prefix=redcap_entity&page=manager%2Fschema';
}


/*
// Enabling bulk operations
$list->setBulkDelete()
    ->setBulkOperation('approve', 'Approve Demographic Options', 'The demographics options have been added.', 'green')
    ->render('project');
*/

?>
<html>
<header>
</header>
<body>
<div style="font-size:large"> The table <i><b>oncore_demo_options</b></i> has not been created yet. Please create it first <a style="color: red; font-size:large" href="<?php echo $url; ?>">here</a> </div>
</body>
</html>
