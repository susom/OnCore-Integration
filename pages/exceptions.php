<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $this */
?>
<script>
    window.onload = function () {
        console.log($(document).find('#portal-linkage-container'))
        $(document).find('#portal-linkage-container').before('<div class="rounded alert alert-danger"><div class="row"><div class="col-2"><i style="font-size: 30px; margin-left: 20%;" class="fas fa-times"></i></div><div class="col-8">OnCOre Integration Error: <?php echo $this->message; ?></div></div>')
        //$('#setupChklist-modify_project').before('<div class="rounded alert alert-danger"><div class="row"><div class="col-2"><i style="font-size: 30px; margin-left: 20%;" class="fas fa-times"></i></div><div class="col-8"><?php echo $this->message; ?></div></div>')
    }
    //alert("<?php //echo $this->message; ?>//")
</script>
