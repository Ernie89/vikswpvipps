<?php
/**
 * @package     VikVippsMobilePay
 * @subpackage  core
 * @author      Nuna Media Designs
 * @copyright   Copyright (C) 2018 VikWP All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://vikwp.com
 */

// No direct access
defined('ABSPATH') or die('No script kiddies please!');

// Define plugin base path
define('VIKVIPPSMOBILEPAY_DIR', dirname(__FILE__));
// Define plugin base URI
define('VIKVIPPSMOBILEPAY_URI', plugin_dir_url(__FILE__));

/**
 * Imports the file of the gateway and returns the classname
 * of the file that will be instantiated by the caller.
 *
 * @param 	string 	$plugin  The name of the caller.
 *
 * @return 	mixed 	The classname of the payment if exists, otherwise false.
 */
function vikvippsmobilepay_load_payment($plugin)
{
	if (!JLoader::import("{$plugin}.vippsmobilepay", VIKVIPPSMOBILEPAY_DIR))
	{
		// there is not a version available for the given plugin
		return false;
	}

	return 'VikBookingVippsMobilePayPayment';
}

/**
 * Returns the path in which the payment is located.
 *
 * @param 	string 	$plugin  The name of the caller.
 *
 * @return 	mixed 	The path if exists, otherwise false.
 */
function vikvippsmobilepay_get_payment_path($plugin)
{
	$path = VIKVIPPSMOBILEPAY_DIR . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'vippsmobilepay.php';

	if (!is_file($path))
	{
		// there is not a version available for the given plugin
		return false;
	}

	return $path;
}
