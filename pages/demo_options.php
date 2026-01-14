<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

use REDCapEntity\EntityList;
use REDCapEntity\EntityDB;

define ('DB_NAME', 'oncore_demo_options');

try {
    $entity = new EntityDB();
    $db_exists = $entity->checkEntityDBTable(DB_NAME);

    ob_start();
    if ($db_exists) {
        $list = new EntityList('oncore_demo_options', $module);
        $list->setOperations(['create', 'update', 'delete'])
            ->setCols(['oncore_demo_field', 'oncore_demo_option'])
            ->setSortableCols(['oncore_demo_field', 'oncore_demo_option'])
            ->setExposedFilters(['created_by', ''])
            ->render('control_center');
    } else {
        Entities::createException("Table does not exist.  Please go create it first");
        $url = 'http://localhost/redcap_v12.2.2/ExternalModules/?prefix=redcap_entity&page=manager%2Fschema';
        echo '<div style="font-size:large">The table <i><b>oncore_demo_options</b></i> has not been created yet. Please create it first <a style="color: red; font-size:large" href="' . $url . '">here</a></div>';
    }
    $page_html = ob_get_clean();
    ?>
    <div id="app"></div>
    <script>
        window.oncoreBootHtml = <?php echo json_encode(
            $page_html,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="<?php echo $module->getUrl('frontend_3/public/js/bundle.js'); ?>"></script>
    <?php
} catch (\Exception $e) {
    $page_html = '<div class="alert alert-danger">' . $module->escape($e->getMessage()) . '</div>';
    ?>
    <div id="app"></div>
    <script>
        window.oncoreBootHtml = <?php echo json_encode(
            $page_html,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="<?php echo $module->getUrl('frontend_3/public/js/bundle.js'); ?>"></script>
    <?php
}
