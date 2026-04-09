<?php
/*
Plugin Name:  VikSwedBankPay
Description:  SwedBankPay integration to collect payments through the Vik plugins
Version:      1.0.0
Author:       Khalil Farah.
Author URI:   https://www.fiverr.com/khalilfareh
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  vikswedbankpay
Domain Path:  /languages
*/

// No direct access
defined('ABSPATH') or die('No script kiddies please!');

// require utils functions
require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'utils.php';
define('VIKSWEDBANKPAY_LANG', basename(dirname(__FILE__)).DIRECTORY_SEPARATOR.'languages');

define('VIKSWEDBANKPAYVERSION', '1.0.0');

add_action('init', function () {
    JFactory::getLanguage()->load('vikswedbankpay', VIKSWEDBANKPAY_LANG);
});

/*
 * Returns the current plugin version for being used by
 * VikUpdater, in order to check for new updates.
 *
 * @return 	string 	The plugin version.
 */
add_filter('vikwp_vikupdater_swedbankpay_version', function () {
    return VIKSWEDBANKPAYVERSION;
});

/*
 * Returns the base path of this plugin for being used by
 * VikUpdater, in order to move the files of a newer version.
 *
 * @return 	string 	The plugin base path.
 */
add_filter('vikwp_vikupdater_swedbankpay_path', function () {
    return plugin_dir_path(__FILE__).'..';
});

/*
 * VIKBOOKING HOOKS
 */

/*
 * Pushes the swedbankpay gateway within the supported payments of VikBooking plugin.
 *
 * @param 	array 	$drivers  The current list of supported drivers.
 *
 * @return 	array 	The updated drivers list.
 */
add_filter('get_supported_payments_vikbooking', function ($drivers) {
    $driver = vikswedbankpay_get_payment_path('vikbooking');

    // make sure the driver exists
    if ($driver) {
        $drivers[] = $driver;
    }

    return $drivers;
});

/*
 * Loads swedbankpay payment handler when dispatched by VikBooking.
 *
 * @param 	array 	&$drivers 	A list of payment instances.
 * @param 	string 	$payment 	The name of the invoked payment.
 *
 * @return 	void
 *
 * @see 	JPayment
 */
add_action('load_payment_gateway_vikbooking', function (&$drivers, $payment) {
    // make sure the classname hasn't been generated yet by a different hook
    // and the request payment matches 'swedbankpay' string
    if ($payment == 'swedbankpay') {
        $classname = vikswedbankpay_load_payment('vikbooking');

        if ($classname) {
            $drivers[] = $classname;
        }
    }
}, 10, 2);

/*
 * Filters the array containing the logo details to let VikBooking
 * retrieves the correct image.
 *
 * In order to change the image logo, it is needed to inject the
 * image path and URI within the $logo argument.
 *
 * @param 	array 	$logo 	An array containing the following information:
 * 							- name  The payment name;
 * 							- path  The payment logo base path;
 * 							- uri 	The payment logo base URI.
 *
 * @return 	array 	The updated logo information.
 */
add_filter('vikbooking_oconfirm_payment_logo', function ($logo) {
    if ($logo['name'] == 'swedbankpay') {
        $logo['path'] = VIKSWEDBANKPAY_DIR.DIRECTORY_SEPARATOR.'vikbooking'.DIRECTORY_SEPARATOR.'swedbankpay.svg';
        $logo['uri'] = VIKSWEDBANKPAY_URI.'vikbooking/swedbankpay.svg';
    }

    return $logo;
});
