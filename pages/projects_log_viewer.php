<?php


namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {
    $right = $module->framework->getRights(USERID);
    if (!$right['design'] && !$module->isSuperUser()) {
        throw new \Exception($module::getActionExceptionText(''));
    }
    ?>

    <script src="https://code.jquery.com/jquery-3.5.1.js" crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js" crossorigin="anonymous"></script>
    <style>
        #project-logs_wrapper{
            padding-right:20px;
        }
    </style>
    <?php

    ob_start();
    ?>
    <table id="project-logs" class="display" style="width:100%">
        <thead>
        <tr>
            <th>id</th>
            <th>message</th>
            <th>type</th>
            <th>created</th>
            <th>updated</th>
        </tr>
        </thead>
        <tbody>
        <?php
        foreach (Entities::getREDCapProjectLogs($module->getProjectId()) as $record) {
            ?>
            <tr>
                <td><?php echo $record['id'] ?></td>
                <td><?php echo $record['message'] ?></td>
                <td><?php echo Entities::getTypeText($record['type']) ?></td>
                <td><?php echo $record['created'] ?></td>
                <td><?php echo $record['updated'] ?></td>
            </tr>
            <?php
        }
        ?>
        </tbody>
    </table>
    <?php
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
    <script>
        $(document).ready(function () {
            $('#project-logs').DataTable({
                "pageLength": 100,
                order: [[0, "desc"]]
            });
        });
    </script>

    <?php
} catch (\Exception $e) {
    $page_html = '<div class="alert-danger alert">' . $module->escape($e->getMessage()) . '</div>';
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
