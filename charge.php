<?php

// This file is part of Moodle - http://moodle.org/
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Razorpay enrolment plugin version specification.
 *
 * @package    enrol_razorpaypayment
 * @copyright  2020 Hitesh kumar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// Disable moodle specific debug messages and any errors in output,
// comment out when debugging or better look into error log!
define('NO_DEBUG_DISPLAY', true);

require('../../config.php');
require_once('lib.php');
if ($CFG->version < 2018101900) {
    require_once($CFG->libdir . '/eventslib.php');
}
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/filelib.php');

require_login();

require('razorpay/razorpay-php/Razorpay.php');

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

$plugin = enrol_get_plugin('razorpaypayment');


// Razorpay does not like when we return error messages here,
// the custom handler just logs exceptions and stops.
set_exception_handler('enrol_razorpaypayment_charge_exception_handler');



$success = true;
$error = "Payment Failed";
$razorpay_payment_id = required_param('razorpay_payment_id', PARAM_RAW);
$razorpay_signature = required_param('razorpay_signature', PARAM_RAW);
$shopping_order_id = required_param('shopping_order_id', PARAM_RAW);
if (empty($razorpay_payment_id) === false) {
    $api = new Api($plugin->get_config('publishablekey'), $plugin->get_config('secretkey'));
    try {
        // Please note that the razorpay order ID must
        // come from a trusted source (session here, but
        // could be database or something else)
        $attributes = array(
            'razorpay_order_id' => $_SESSION['razorpay_order_id'.$shopping_order_id],
            'razorpay_payment_id' => $razorpay_payment_id,
            'razorpay_signature' => $razorpay_signature
        );

        $order = $api->utility->verifyPaymentSignature($attributes);
        $payment = $api->payment->fetch($razorpay_payment_id);

        $custom = explode('-', $payment['notes']['shopping_order_id']);


        //fetch the data from the database 

        $getStatusForOrder = $DB->get_record('enrol_razorpaypayment', array('txn_id' => $payment['order_id']));
        if (!empty($getStatusForOrder->id)) {
            $getStatusForOrder->payment_status = $payment['status'];
            $getStatusForOrder->timeupdated = $payment['created_at'];
            $getStatusForOrder->data = json_encode((array)$payment);
            $DB->update_record('enrol_razorpaypayment', $getStatusForOrder);
        }


        $data->userid = (int) str_replace('U', '', $custom[0]);
        $data->courseid = (int) str_replace('C', '', $custom[1]);
        $data->instanceid = (int) str_replace('I', '', $custom[2]);
    } catch (SignatureVerificationError $e) {
        $success = false;
        $error = 'Razorpay Error : ' . $e->getMessage();
    }
}


// Get the user and course records.

if (!$user = $DB->get_record("user", array("id" => $data->userid))) {
    message_razorpaypayment_error_to_admin("Not a valid user id", $data);
    redirect($CFG->wwwroot);
}

if (!$course = $DB->get_record("course", array("id" => $data->courseid))) {
    message_razorpaypayment_error_to_admin("Not a valid course id", $data);
    redirect($CFG->wwwroot);
}

if (!$context = context_course::instance($course->id, IGNORE_MISSING)) {
    message_razorpaypayment_error_to_admin("Not a valid context id", $data);
    redirect($CFG->wwwroot);
}

$PAGE->set_context($context);

if (!$plugininstance = $DB->get_record("enrol", array("id" => $data->instanceid, "status" => 0))) {
    message_razorpaypayment_error_to_admin("Not a valid instance id", $data);
    redirect($CFG->wwwroot);
}

// If currency is incorrectly set then someone maybe trying to cheat the system.

if ($data->courseid != $plugininstance->courseid) {
    message_razorpaypayment_error_to_admin("Course Id does not match to the course settings, received: " . $data->courseid, $data);
    redirect($CFG->wwwroot);
}


// Let's say each article costs 15.00 bucks.

try {

    if ($plugininstance->enrolperiod) {
        $timestart = time();
        $timeend = $timestart + $plugininstance->enrolperiod;
    } else {
        $timestart = 0;
        $timeend = 0;
    }

    // Enrol user.
    $plugin->enrol_user($plugininstance, $user->id, $plugininstance->roleid, $timestart, $timeend);

    // Pass $view=true to filter hidden caps if the user cannot see them.
    if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
            '', '', '', '', false, true)) {
        $users = sort_by_roleassignment_authority($users, $context);
        $teacher = array_shift($users);
    } else {
        $teacher = false;
    }

    $mailstudents = $plugin->get_config('mailstudents');
    $mailteachers = $plugin->get_config('mailteachers');
    $mailadmins = $plugin->get_config('mailadmins');
    $shortname = format_string($course->shortname, true, array('context' => $context));


    if (!empty($mailstudents)) {
        $a = new stdClass();
        $a->coursename = format_string($course->fullname, true, array('context' => $coursecontext));
        $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";

        $eventdata = new stdClass();
        $eventdata->modulename = 'moodle';
        $eventdata->component = 'enrol_razorpaypayment';
        $eventdata->name = 'razorpaypayment_enrolment';
        $eventdata->userfrom = empty($teacher) ? core_user::get_support_user() : $teacher;
        $eventdata->userto = $user;
        $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
        $eventdata->fullmessage = get_string('welcometocoursetext', '', $a);
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->smallmessage = '';
        message_send($eventdata);
    }

    if (!empty($mailteachers) && !empty($teacher)) {
        $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
        $a->user = fullname($user);

        $eventdata = new stdClass();
        $eventdata->modulename = 'moodle';
        $eventdata->component = 'enrol_razorpaypayment';
        $eventdata->name = 'razorpaypayment_enrolment';
        $eventdata->userfrom = $user;
        $eventdata->userto = $teacher;
        $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
        $eventdata->fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->smallmessage = '';
        message_send($eventdata);
    }

    if (!empty($mailadmins)) {
        $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
        $a->user = fullname($user);
        $admins = get_admins();
        foreach ($admins as $admin) {
            
            $eventdata = new stdClass();
            $eventdata->modulename = 'moodle';
            $eventdata->component = 'enrol_razorpaypayment';
            $eventdata->name = 'razorpaypayment_enrolment';
            $eventdata->userfrom = $user;
            $eventdata->userto = $admin;
            $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
            $eventdata->fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml = '';
            $eventdata->smallmessage = '';
            message_send($eventdata);
        }
    }

    $destination = "$CFG->wwwroot/course/view.php?id=$course->id";

    $fullname = format_string($course->fullname, true, array('context' => $context));

    if (is_enrolled($context, null, '', true)) { // TODO: use real Razorpay check.
        redirect($destination, get_string('paymentthanks', '', $fullname));
    } else {   // Somehow they aren't enrolled yet!
        $PAGE->set_url($destination);
        echo $OUTPUT->header();
        $a = new stdClass();
        $a->teacher = get_string('defaultcourseteacher');
        $a->fullname = $fullname;
        notice(get_string('paymentsorry', '', $a), $destination);
    }
} catch (Exception $e) {

    // Something else happened, completely unrelated to Stripe.
    echo 'Something else happened, completely unrelated to Stripe';
}


// --- HELPER FUNCTIONS --------------------------------------------------------------------------------------!

/**
 * Send payment error message to the admin.
 *
 * @param string $subject
 * @param stdClass $data
 */
function message_razorpaypayment_error_to_admin($subject, $data) {
    $admin = get_admin();
    $site = get_site();

    $message = "$site->fullname:  Transaction failed.\n\n$subject\n\n";

    foreach ($data as $key => $value) {
        $message .= s($key) . " => " . s($value) . "\n";
    }

    $eventdata = new stdClass();
    $eventdata->modulename = 'moodle';
    $eventdata->component = 'enrol_razorpaypayment';
    $eventdata->name = 'razorpaypayment_enrolment';
    $eventdata->userfrom = $admin;
    $eventdata->userto = $admin;
    $eventdata->subject = "Razior PAYMENT ERROR: " . $subject;
    $eventdata->fullmessage = $message;
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';
    $eventdata->smallmessage = '';
    message_send($eventdata);
}

