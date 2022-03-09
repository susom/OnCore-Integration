<?php
namespace Stanford\OnCoreIntegration;
/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

$url = APP_PATH_WEBROOT_FULL."redcap_v".REDCAP_VERSION.'/ExternalModules/?prefix=redcap_entity&page=manager%2Fschema';

?>
<html>
    <header>

    </header>
    <body>
        <h6>Please setup the required Entity Tables before this module can be used on a project.</h6>
        <div style="margin-left:20px">
            <ui>
                <li><?php echo OnCoreIntegration::ONCORE_PROTOCOLS; ?></li>
                <li><?php echo OnCoreIntegration::ONCORE_REDCAP_API_ACTIONS_LOG; ?></li>
                <li><?php echo OnCoreIntegration::ONCORE_ADMINS; ?></li>
                <li><?php echo OnCoreIntegration::ONCORE_REDCAP_RECORD_LINKAGE; ?></li>
                <li><?php echo OnCoreIntegration::ONCORE_SUBJECTS; ?></li>
                <li><?php echo OnCoreIntegration::ONCORE_DEMOGRAPHICS_OPTIONS; ?></li>
            </ui>
        </div>
        <div style="margin-top: 10px">
            If you are a <b>REDCap System Administrator</b>, go to the Control Panel <a style="color:red;" href="<?php echo $url; ?>">here</a> to create the tables. Otherwise, contact your System Administrator,
        </div>
        <div>
            <p>Once these tables are created, return to this project to complete the OnCore Setup.</p>
        </div>
    </body>
</html>
