<?php


namespace Stanford\OnCoreIntegration;

use REDCapEntity\EntityList;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {
    if (!$module->isSuperUser()) {
        Entities::createException(USERID . ' is trying to access OnCore logs');
        throw new \Exception('Access Denied');
    }

    ob_start();
    $list = new EntityList(OnCoreIntegration::ONCORE_PROTOCOLS, $module);
    $list->setOperations(['update', 'delete'])->render('control_center'); // Context: project.
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
