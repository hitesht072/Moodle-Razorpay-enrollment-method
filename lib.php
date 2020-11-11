<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Razorpay enrolment plugin version specification.
 *
 * @package    enrol_razorpaypayment
 * @copyright  2020 Hitesh kumar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
/**
 * Razorpay enrolment plugin version specification.
 *
 * @package    enrol_razorpaypayment
 * @copyright  2020 Hitesh kumar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require('razorpay/razorpay-php/Razorpay.php');
require('razorpay/razorpay-php/src/Api.php');

use Razorpay\Api\Api;

class enrol_razorpaypayment_plugin extends enrol_plugin {

    /**
     * Lists all currencies available for plugin.
     * @return $currencies
     */
    public function get_currencies() {
//        $codes = array(
//            'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY',
//            'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RUB', 'SEK', 'SGD', 'THB', 'TRY', 'TWD', 'USD');
        $codes = array(
            'INR');
        $currencies = array();
        foreach ($codes as $c) {
            $currencies[$c] = new lang_string($c, 'core_currencies');
        }
        return $currencies;
    }

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances) {
        $found = false;
        foreach ($instances as $instance) {
            if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
                continue;
            }
            if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
                continue;
            }
            $found = true;
            break;
        }
        if ($found) {
            return array(new pix_icon('icon', get_string('pluginname', 'enrol_razorpaypayment'), 'enrol_razorpaypayment'));
        }
        return array();
    }

    /**
     * Lists all protected user roles.
     * @return bool(true or false)
     */
    public function roles_protected() {
        // Users with role assign cap may tweak the roles later.
        return false;
    }

    /**
     * Defines if user can be unenrolled.
     * @param stdClass $instance of the plugin
     * @return bool(true or false)
     */
    public function allow_unenrol(stdClass $instance) {
        // Users with unenrol cap may unenrol other users manually - requires enrol/razorpay:unenrol.
        return true;
    }

    /**
     * Defines if user can be managed from admin.
     * @param stdClass $instance of the plugin
     * @return bool(true or false)
     */
    public function allow_manage(stdClass $instance) {
        // Users with manage cap may tweak period and status - requires enrol/razorpay:manage.
        return true;
    }

    /**
     * Defines if 'enrol me' link will be shown on course page.
     * @param stdClass $instance of the plugin
     * @return bool(true or false)
     */
    public function show_enrolme_link(stdClass $instance) {
        return ($instance->status == ENROL_INSTANCE_ENABLED);
    }

    /**
     * Sets up navigation entries.
     *
     * @param navigation_node $instancesnode
     * @param stdClass $instance
     * @return void
     */
    public function add_course_navigation($instancesnode, stdClass $instance) {
        if ($instance->enrol !== 'razorpaypayment') {
            throw new coding_exception('Invalid enrol instance type!');
        }
        $context = context_course::instance($instance->courseid);
        if (has_capability('enrol/razorpaypayment:config', $context)) {
            $managelink = new moodle_url('/enrol/razorpaypayment/edit.php', array('courseid' => $instance->courseid, 'id' => $instance->id));
            $instancesnode->add($this->get_instance_name($instance), $managelink, navigation_node::TYPE_SETTING);
        }
    }

    /**
     * Returns edit icons for the page with list of instances
     * @param stdClass $instance
     * @return array
     */
    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;
        if ($instance->enrol !== 'razorpaypayment') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = context_course::instance($instance->courseid);
        $icons = array();
        if (has_capability('enrol/razorpaypayment:config', $context)) {
            $editlink = new moodle_url("/enrol/razorpaypayment/edit.php", array('courseid' => $instance->courseid, 'id' => $instance->id));
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon('t/edit', get_string('edit'), 'core',
                            array('class' => 'iconsmall')));
        }
        return $icons;
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);
        if (!has_capability('moodle/course:enrolconfig', $context) or!has_capability('enrol/razorpaypayment:config', $context)) {
            return null;
        }
        // Multiple instances supported - different cost for different roles.
        return new moodle_url('/enrol/razorpaypayment/edit.php', array('courseid' => $courseid));
    }

    /**
     * Creates course enrol form, checks if form submitted
     * and enrols user if necessary. It can also redirect.
     *
     * @param stdClass $instance
     * @return string html text, usually a form in a text box
     */
    public function enrol_page_hook(stdClass $instance) {
        global $CFG, $USER, $OUTPUT, $PAGE, $DB;
        $enrolstatus = $this->can_razorpaypayment_enrol($instance);
        if (!$enrolstatus) {
            return get_string('maxenrolledreached', 'enrol_razorpaypayment');
        }
        ob_start();
        if ($DB->record_exists('user_enrolments', array('userid' => $USER->id, 'enrolid' => $instance->id))) {
            return ob_get_clean();
        }
        if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
            return ob_get_clean();
        }
        if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
            return ob_get_clean();
        }
        $course = $DB->get_record('course', array('id' => $instance->courseid));
        $context = context_course::instance($course->id);
        $shortname = format_string($course->shortname, true, array('context' => $context));
        $strloginto = get_string("loginto", "", $shortname);
        $strcourses = get_string("courses");
        // Pass $view=true to filter hidden caps if the user cannot see them.
        if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
                '', '', '', '', false, true)) {
            $users = sort_by_roleassignment_authority($users, $context);
            $teacher = array_shift($users);
        } else {
            $teacher = false;
        }
        $publishablekey = $this->get_config('publishablekey');
        if ((float) $instance->cost <= 0) {
            $cost = (int) $this->get_config('cost');
        } else {
            $cost = (int) $instance->cost;
        }
        if (abs($cost) < 0.01) { // No cost, other enrolment methods (instances) should be used.
            echo '<p>' . get_string('nocost', 'enrol_razorpaypayment') . '</p>';
        } else {
            // Calculate localised and "." cost, make sure we send Razorpay the same value,
            // please note Razorpay expects amount with 2 decimal places and "." separator.
//            $localisedcost = format_float($cost, 2, true);
//            $cost = format_float($cost, 2, false);
            $localisedcost = $cost;
            $cost = $cost;
            if (isguestuser()) { // Force login only for guest user, not real users with guest role.
                if (empty($CFG->loginhttps)) {
                    $wwwroot = $CFG->wwwroot;
                } else {
                    // This actually is not so secure ;-), 'cause we're
                    // in unencrypted connection...
                    $wwwroot = str_replace("http://", "https://", $CFG->wwwroot);
                }
                echo '<div class="mdl-align"><p>' . get_string('paymentrequired') . '</p>';
                echo '<p><b>' . get_string('cost') . ": $instance->currency $localisedcost" . '</b></p>';
                echo '<p><a href="' . $wwwroot . '/login/">' . get_string('loginsite') . '</a></p>';
                echo '</div>';
            } else {
                // Sanitise some fields before building the Razorpay form.
                $coursefullname = format_string($course->fullname, true, array('context' => $context));
                $courseshortname = $shortname;
                $userfullname = fullname($USER);
                $userfirstname = $USER->firstname;
                $userlastname = $USER->lastname;
                $useraddress = $USER->address;
                $usercity = $USER->city;
                $useremail = $USER->email;
                $instancename = $this->get_instance_name($instance);
                if (!empty($instance->customtext1)) {
                    $message = format_string($instance->customtext1);
                } else {
                    $message = get_string("paymentrequired");
                }
                $validatezipcode = $this->get_config('validatezipcode');
                $billingaddress = $this->get_config('billingaddress');
                //require('razorpay/config.php');


                $api = new Api($this->get_config('publishablekey'), $this->get_config('secretkey'));

//
// We create an razorpay order using orders api
// Docs: https://docs.razorpay.com/docs/orders
//
                $shopping_order_id = 'U' . $USER->id . '-C' . $course->id . '-I' . $instance->id . '-Time' . time();
                $orderData = [
                    'receipt' => $shopping_order_id,
                    'amount' => $cost * 100, // 2000 rupees in paise
                    'currency' => 'INR',
                    'payment_capture' => 1 // auto capture
                ];

                $razorpayOrder = $api->order->create($orderData);
                $razorpayOrderId = $razorpayOrder['id'];

                //insert into the database 
                $paymtTrx = new stdclass();
                $paymtTrx->courseid = $course->id;
                $paymtTrx->userid = $USER->id;
                $paymtTrx->item_name = $course->fullname;
                $paymtTrx->instanceid = $instance->id;
                $paymtTrx->payment_status = $razorpayOrder['status'];
                $paymtTrx->txn_id = $razorpayOrderId;
                $paymtTrx->receipt = $razorpayOrder['receipt'];
                $paymtTrx->amount = $cost;
                $paymtTrx->timeupdated = $razorpayOrder['created_at'];
                $DB->insert_record('enrol_razorpaypayment', $paymtTrx);


                $_SESSION['razorpay_order_id'.$shopping_order_id] = $razorpayOrderId;

                $displayAmount = $amount = $orderData['amount'];
                $displayCurrency = $this->get_config('currency');
                if ($displayCurrency !== 'INR') {
                    $url = "https://api.fixer.io/latest?symbols=$displayCurrency&base=INR";
                    $exchange = json_decode(file_get_contents($url), true);

                    $displayAmount = $exchange['rates'][$displayCurrency] * $amount / 100;
                }

                $checkout = 'automatic';

                if (isset($_GET['checkout']) and in_array($_GET['checkout'], ['automatic', 'manual'], true)) {
                    $checkout = $_GET['checkout'];
                }
                
                $course_categorie = $DB->get_record('course_categories',array('id'=>$course->category));
                $path = explode('/',$course_categorie->path);
                $root_category_id = $path[1];
                $root_category = $DB->get_record('course_categories',array('id'=>$root_category_id));
 
                $data = [
                    "key" => $this->get_config('publishablekey'),
                    "amount" => $amount,
                    "name" => $coursefullname,
                    "description" => $root_category->name . ':=>' . $courseshortname . ' : ' . $coursefullname,
                    "image" => "",
                    "prefill" => [
                        "name" => $userfullname,
                        "email" => $useremail,
                        "contact" => $USER->username,
                    ],
                    "notes" => [
                        "address" => "",
                        "merchant_order_id" => "",
                    ],
                    "theme" => [
                        "color" => "#F37254"
                    ],
                    "order_id" => $razorpayOrderId,
                    "shopping_order_id" => $shopping_order_id,
                ];

                if ($displayCurrency !== 'INR') {
                    $data['display_currency'] = $displayCurrency;
                    $data['display_amount'] = $displayAmount;
                }

                $json = json_encode($data);

                include($CFG->dirroot . '/enrol/razorpaypayment/razorpay/checkout/automatic.php');
            }
        }
        return $OUTPUT->box(ob_get_clean());
    }

    public function can_razorpaypayment_enrol(stdClass $instance) {
        global $CFG, $DB, $OUTPUT, $USER;
        if ($instance->customint3 > 0) {
            // Max enrol limit specified.
            $count = $DB->count_records('user_enrolments', array('enrolid' => $instance->id));
            if ($count >= $instance->customint3) {
                // Bad luck, no more razorpaypayment enrolments here.
                return false;
            }
        }
        return true;
    }

    /**
     * Returns localised name of enrol instance
     *
     * @param stdClass $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;
        if (empty($instance->name)) {
            if (!empty($instance->roleid) and $role = $DB->get_record('role', array('id' => $instance->roleid))) {
                $role = ' (' . role_get_name($role, context_course::instance($instance->courseid, IGNORE_MISSING)) . ')';
            } else {
                $role = '';
            }
            $enrol = $this->get_name();
            return '';
        } else {
            return format_string($instance->name);
        }
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        if ($step->get_task()->get_target() == backup::TARGET_NEW_COURSE) {
            $merge = false;
        } else {
            $merge = array(
                'courseid' => $data->courseid,
                'enrol' => $this->get_name(),
                'roleid' => $data->roleid,
                'cost' => $data->cost,
                'currency' => $data->currency,
            );
        }
        if ($merge and $instances = $DB->get_records('enrol', $merge, 'id')) {
            $instance = reset($instances);
            $instanceid = $instance->id;
        } else {
            $instanceid = $this->add_instance($course, (array) $data);
        }
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $userid
     * @param int $oldinstancestatus
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
    }

    /**
     * Gets an array of the user enrolment actions
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol($instance) && has_capability("enrol/razorpaypayment:unenrol", $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''),
                    get_string('unenrol', 'enrol'), $url, array('class' => 'unenrollink', 'rel' => $ue->id));
        }
        if ($this->allow_manage($instance) && has_capability("enrol/razorpaypayment:manage", $context)) {
            $url = new moodle_url('/enrol/editenrolment.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/edit', ''),
                    get_string('edit'), $url, array('class' => 'editenrollink', 'rel' => $ue->id));
        }
        return $actions;
    }

    /**
     * Set up cron for the plugin (if any).
     *
     */
    public function cron() {
        $trace = new text_progress_trace();
        $this->process_expirations($trace);
    }

    /**
     * Execute synchronisation.
     * @param progress_trace $trace
     * @return int exit code, 0 means ok
     */
    public function sync(progress_trace $trace) {
        $this->process_expirations($trace);
        return 0;
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/razorpaypayment:config', $context);
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/razorpaypayment:config', $context);
    }

}


