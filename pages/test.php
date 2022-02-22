<?php

namespace Stanford\OnCoreIntegration;

/** @var \Stanford\OnCoreIntegration\OnCoreIntegration $module */

// test cron
#$module->onCoreProtocolsScanCron();

// test contact functionality.
$user = $module->framework->getUser();
$admin = $module->getUsers()->getOnCoreAdmin($user->getUsername());
if (!$admin) {
    $contact = $module->getUsers()->searchOnCoreContactsViaEmail($user->getEmail());

    echo '<pre>';
    print_r($contact);
    echo '</pre>';
    $module->getUsers()->createOnCoreAdminEntityRecord($contact['contactId'], $user->getUsername());
} else {
    echo '<pre>';
    print_r($admin);
    //$module->getUsers()->updateOnCoreAdminEntityRecord($user->getUsername(), time(), time());
    echo '</pre>';
}

