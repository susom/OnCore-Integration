<?php


namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

?>

<script src="https://code.jquery.com/jquery-3.5.1.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js" crossorigin="anonymous"></script>

<table id="project-logs" class="display" style="width:100%">
    <thead>
    <tr>
        <th>id</th>
        <th>message</th>
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
            <td><?php echo $record['created'] ?></td>
            <td><?php echo $record['updated'] ?></td>
        </tr>
        <?php
    }
    ?>
    </tbody>
</table>
<script>
    $(document).ready(function () {
        $('#project-logs').DataTable();
    });
</script>