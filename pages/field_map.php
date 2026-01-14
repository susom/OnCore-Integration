<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {
    $oncore_css = $module->getUrl("assets/styles/field_mapping.css");
    $notif_css = $module->getUrl("assets/styles/notif_modal.css");
    $stanford_uit_custom_css = $module->getUrl("assets/styles/stanford_uit_custom.css");
    $ajax_endpoint = $module->getUrl("ajax/handler.php");

    echo '<link rel="stylesheet" href="' . APP_PATH_CSS . 'bootstrap.min.css">';
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
    <link rel="stylesheet" href="<?= $stanford_uit_custom_css ?>">
    <link rel="stylesheet" href="<?= $oncore_css ?>">
    <link rel="stylesheet" href="<?= $notif_css ?>">
    <style>
        #field_mapping .optional {
            display: inline-block;
        }
    </style>

    <div id="app"></div>
    <script>
        window.oncorePage = 'field_map';
        window.oncoreBootData = <?php echo json_encode([
            'ajaxEndpoint' => $ajax_endpoint,
            'csrfToken' => $module->getCSRFToken(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="<?php echo $module->getUrl('frontend_3/public/js/bundle.js'); ?>"></script>
    <?php
} catch (\Exception $e) {
    $message = $module->escape($e->getMessage());
    $support_url = $module->getProjectSetting('oncore-support-page-url');
    if ($support_url != '') {
        $message .= ' <a target="_blank" href="' . $support_url . '">For more information check Oncore Support Page</a>';
    }
    $page_html = '<div class="alert alert-danger">' . $message . '</div>';
    ?>
    <div id="app"></div>
    <script>
        window.oncorePage = 'field_map';
        window.oncoreBootData = <?php echo json_encode([
            'error' => $page_html,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="<?php echo $module->getUrl('frontend_3/public/js/bundle.js'); ?>"></script>
<?php
}
