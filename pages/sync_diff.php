<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$sync_diff = $module->getSyncDiff();
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Crimson+Text:400,400italic,600,600italic">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Oswald">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:600i,700,700i">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,300,300italic,400italic">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit.css">
<link rel="stylesheet" href="https://uit.stanford.edu/sites/all/themes/stanford_uit/css/stanford_uit_custom.css">

<div class="container">
    <ul class="nav nav-tabs">
        <li class="active"><a data-toggle="tab" href="#match_diff">OnCore <-> REDCap Matched Diff</a></li>
        <li><a data-toggle="tab" href="#oncore_redcap">OnCore != REDCap</a></li>
        <li><a ata-toggle="tab" href="#redcap_oncore">REDCap != OnCore <span class="caret"></span></a></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane active" id="match_diff">
            <h2>Instructions</h2>
            <p>Cras dui lectus, efficitur quis mi sit amet, lobortis lobortis metus.</p>
        </div>
        <div class="tab-pane" id="oncore_redcap">
            <h2>User Guide: Step 1</h2>
            <p>Sed in massa facilisis, aliquam elit ac, lobortis elit.</p>
        </div>
        <div class="tab-pane" id="redcap_oncore">
            <h2>User Guide: Step 2</h2>
            <p>Donec felis urna, pulvinar a erat et, posuere hendrerit elit.</p>
        </div>
    </div>
</div>

<script>
    $(document).ready(function () {

    });
</script>
