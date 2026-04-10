<?php
/*
Plugin Name:  VikVippsMobilePay
Description:  Vipps MobilePay integration to collect payments through the Vik plugins
Version:      1.0.0
Author:       Khalil Farah.
Author URI:   https://www.fiverr.com/khalilfareh
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  vikvippsmobilepay
Domain Path:  /languages
*/

// No direct access
defined('ABSPATH') or die('No script kiddies please!');

// require utils functions
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'utils.php';
define('VIKVIPPSMOBILEPAY_LANG', basename(dirname(__FILE__)).DIRECTORY_SEPARATOR.'languages');

define('VIKVIPPSMOBILEPAY_VERSION', '1.0.0');

add_action('init', function () {
    JFactory::getLanguage()->load('vikvippsmobilepay', VIKVIPPSMOBILEPAY_LANG);
});

/*
 * Returns the current plugin version for being used by
 * VikUpdater, in order to check for new updates.
 */
add_filter('vikwp_vikupdater_vippsmobilepay_version', function () {
    return VIKVIPPSMOBILEPAY_VERSION;
});

/*
 * Returns the base path of this plugin for being used by
 * VikUpdater, in order to move the files of a newer version.
 */
add_filter('vikwp_vikupdater_vippsmobilepay_path', function () {
    return plugin_dir_path(__FILE__).'..';
});

/*
 * VIKBOOKING HOOKS
 */

/*
 * Pushes the Vipps MobilePay gateway within the supported payments of VikBooking plugin.
 */
add_filter('get_supported_payments_vikbooking', function ($drivers) {
    $driver = vikvippsmobilepay_get_payment_path('vikbooking');

    // make sure the driver exists
    if ($driver) {
        $drivers[] = $driver;
    }

    return $drivers;
});

/*
 * Loads Vipps MobilePay payment handler when dispatched by VikBooking.
 */
add_action('load_payment_gateway_vikbooking', function (&$drivers, $payment) {
    if ($payment == 'vippsmobilepay') {
        $classname = vikvippsmobilepay_load_payment('vikbooking');

        if ($classname) {
            $drivers[] = $classname;
        }
    }
}, 10, 2);

/*
 * Filters the array containing the logo details to let VikBooking retrieve the correct image.
 */
add_filter('vikbooking_oconfirm_payment_logo', function ($logo) {
    if ($logo['name'] == 'vippsmobilepay') {
        $logo['path'] = VIKVIPPSMOBILEPAY_DIR.DIRECTORY_SEPARATOR.'vikbooking'.DIRECTORY_SEPARATOR.'vippsmobilepay.svg';
        $logo['uri'] = VIKVIPPSMOBILEPAY_URI.'vikbooking/vippsmobilepay.svg';
    }

    return $logo;
});
