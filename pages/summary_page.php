<?php


namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

try {

    $protocols = $module->getProtocolsSummary();
    $logs = $module->getLogsSummary();
    ?>

    <div id="accordion">
    <div class="card">
        <div class="card-header" id="headingOne">
            <h5 class="mb-0">
                <button class="btn btn-link" data-toggle="collapse" data-target="#collapseOne" aria-expanded="true"
                        aria-controls="collapseOne">
                    Total number of linked Protocols: <?php echo $protocols['total'] ?>
                </button>
            </h5>
        </div>

        <div id="collapseOne" class="collapse show" aria-labelledby="headingOne" data-parent="#accordion">
            <div class="card-body">
                <p>Number of Approved Protocols:
                    <strong><?php echo $protocols[OnCoreIntegration::ONCORE_PROTOCOL_STATUS_YES] ?></strong></p>
                <p>Number of Pending Protocols:
                    <strong><?php echo $protocols[OnCoreIntegration::ONCORE_PROTOCOL_STATUS_NO] ?></strong></p>
                <p><a href="<?php echo $module->getUrl('pages/protocols_viewer.php') ?>">View All Protocols</a></p>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header" id="headingTwo">
            <h5 class="mb-0">
                <button class="btn btn-link collapsed" data-toggle="collapse" data-target="#collapseTwo"
                        aria-expanded="false" aria-controls="collapseTwo">
                    Total number of Records Pushed to
                    OnCore: <?php echo $logs['total'] - $logs[Entities::PULL_FROM_ONCORE] ?>
                </button>
            </h5>
        </div>
        <div id="collapseTwo" class="collapse" aria-labelledby="headingTwo" data-parent="#accordion">
            <div class="card-body">
                <p>Number of Records Pushed that existed in OnCore:
                    <strong><?php echo $logs[Entities::PUSH_TO_ONCORE_FROM_ONCORE] ?: 0 ?></strong></p>
                <p>Number of Records Pushed that existed in OnStage:
                    <strong><?php echo $logs[Entities::PUSH_TO_ONCORE_FROM_ON_STAGE] ?: 0 ?></strong></p>
                <p>Number of Records Pushed Directly from REDCap:
                    <strong><?php echo $logs[Entities::PUSH_TO_ONCORE_FROM_REDCAP] ?: 0 ?></strong></p>
                <p><a href="<?php echo $module->getUrl('pages/linkage_viewer.php') ?>">View Linked Records </a></p>

            </div>
        </div>
        <div class="card">
            <div class="card-header" id="headingThree">
                <h5 class="mb-0">
                    <button class="btn btn-link collapsed" data-toggle="collapse" data-target="#collapseThree"
                            aria-expanded="false" aria-controls="collapseThree">
                        Total number of Records Pulled from OnCore: <?php echo $logs[Entities::PULL_FROM_ONCORE] ?>
                    </button>
                </h5>
            </div>
            <div id="collapseThree" class="collapse" aria-labelledby="headingThree" data-parent="#accordion">
                <div class="card-body">
                                <p><a href="<?php echo $module->getUrl('pages/linkage_viewer.php') ?>">View Linked Records </a></p>

                </div>
            </div>
        </div>
    </div>

    <?php
} catch (\Exception $e){
    ?>
    <div class="alert-danger alert"><?php echo $e->getMessage(); ?></div>
<?php
}