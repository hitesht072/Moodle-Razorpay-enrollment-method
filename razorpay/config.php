<?php

require('../../../config.php');
require_once($CFG->libdir . '/enrollib.php');
$plugin = enrol_get_plugin('razorpaypayment');

$keyId = $plugin->get_config('publishablekey');
$keySecret = $plugin->get_config('secretkey');
$displayCurrency = $plugin->get_config('currency');

//These should be commented out in production
// This is for error reporting
// Add it to config.php to report any errors
error_reporting(E_ALL);
ini_set('display_errors', 1);
