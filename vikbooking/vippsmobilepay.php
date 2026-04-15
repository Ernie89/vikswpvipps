<?php
/**
 * @package     VikVippsMobilePay
 * @subpackage  vikbooking
 * @author      khalil fareh.
 * @copyright   Copyright (C) 2018 VikWP All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://vikwp.com
 */

defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('vippsmobilepay', VIKVIPPSMOBILEPAY_DIR);

// Prepend the deposit message before the payment form (only if specified).
add_action('payment_after_begin_transaction_vikbooking', function(&$payment, &$html)
{
	// make sure the driver is vippsmobilepay
	if (!$payment->isDriver('vippsmobilepay'))
	{
		return;
	}

	if ($payment->get('leave_deposit'))
	{
		$html = '<p class="vbo-leave-deposit">
			<span>' . JText::_('VBLEAVEDEPOSIT') . '</span>' . 
			$payment->get('currency_symb') . ' ' . number_format($payment->get('total_to_pay'), 2) . 
		'</p><br/>' . $html;
	}
/**
	 * Force the system to avoid using the cache for transient.
	 * The previous value will be reset after terminating the callback.
	 *
	 * 
	 */
	$was_using_cache = wp_using_ext_object_cache(false);


	// save the total to pay within a transient (should not work on a multisite, try using `set_site_transient`)
	$transient = set_transient('vikvippsmobilepay_vikbooking_' . $payment->get('oid') . '_' . $payment->get('sid'), $payment->get('total_to_pay'), 10 * MINUTE_IN_SECONDS);

// restore cache flag
	wp_using_ext_object_cache($was_using_cache);
	//if transient saving fails
	if(!$transient){
		$txname = $payment->get('sid') . '-' . $payment->get('oid') . '.tx';
		$fp = fopen(VIKVIPPSMOBILEPAY_DIR . DIRECTORY_SEPARATOR . 'vikbooking' . DIRECTORY_SEPARATOR . $txname , 'w+');
		fwrite($fp, $payment->get('total_to_pay'));
		fclose($fp);
	}
}, 10, 2);

/// Retrieve the total amount  from the static transaction file.
add_action('payment_before_validate_transaction_vikbooking', function($payment)
{
	// make sure the driver is Vipps MobilePay
	if (!$payment->isDriver('vippsmobilepay'))
	{
		return;
	}
	$txname = $payment->get('sid') . '-' . $payment->get('oid') . '.tx';
	$txdata = '';

	$path = VIKVIPPSMOBILEPAY_DIR . DIRECTORY_SEPARATOR . 'vikbooking' . DIRECTORY_SEPARATOR . $txname;
	/**
	 * Force the system to avoid using the cache for transient.
	 * The previous value will be reset after terminating the callback.
	 *
	 */
	$was_using_cache = wp_using_ext_object_cache(false);

	$transient = 'vikvippsmobilepay_vikbooking_' . $payment->get('oid') . '_' . $payment->get('sid');

	// get session ID from transient (should not work on a multisite, try using `get_site_transient`)
	$data = get_transient($transient);
	/**
	 *	Check if transient exists: if it doesn't exist, then attempt to recover the needed data from the file.
 	 *	
 	 *
 	 */

	if ($data) {

		
		// set total to pay as it is probably missing
		$payment->set('total_to_pay', $data);

		// always attempt to delete transient
		delete_transient($transient);
		// restore cache flag
		wp_using_ext_object_cache($was_using_cache);
		
	} else if (is_file($path)) {
		$fp = fopen($path, 'rb');
		$txdata = fread($fp, filesize($path));
		fclose($fp);

		if (!empty($txdata))
		{
			$payment->set('total_to_pay', $txdata);
		}
		else
		{
			// if not set, specify an empty value to pay
			$payment->set('total_to_pay', $payment->get('total_to_pay', 0));
		}
		// remove transaction file
		unlink($path);
	}
		
	
});

// VikBooking doesn't have a return_url to use within the afterValidation method.
// Use this hook to construct it and route it following the shortcodes standards.
add_action('payment_on_after_validation_vikbooking', function(&$payment, $res)
{
	// make sure the driver is vippsmobilepay
	if (!$payment->isDriver('vippsmobilepay'))
	{
		return;
	}

	$url = 'index.php?option=com_vikbooking&task=vieworder&sid=' . $payment->get('sid') . '&ts=' . $payment->get('ts');

	$model 		= JModel::getInstance('vikbooking', 'shortcodes', 'admin');
	$itemid 	= $model->all('post_id');
	
	if (count($itemid))
	{
		$url = JRoute::_($url . '&Itemid=' . $itemid[0]->post_id, false);
	}

	JFactory::getApplication()->redirect($url);
	exit;
}, 10, 2);

/**
 * This class is used to collect payments in VikBooking plugin
 * by using the Vipps MobilePay gateway.
 *
 * @since 1.0
 */
class VikBookingVippsMobilePayPayment extends AbstractVippsMobilePayPayment
{
	/**
	 * @override
	 * Class constructor.
	 *
	 * @param 	string 	$alias 	 The name of the plugin that requested the payment.
	 * @param 	mixed 	$order 	 The order details to start the transaction.
	 * @param 	mixed 	$params  The configuration of the payment.
	 */
	public function __construct($alias, $order, $params = array())
	{
		parent::__construct($alias, $order, $params);

		$details = $this->get('details', array());

		$this->set('oid', $this->get('id', null));
		
		if (!$this->get('oid'))
		{
			$this->set('oid', isset($details['id']) ? $details['id'] : 0);
		}

		if (!$this->get('sid'))
		{
			$this->set('sid', isset($details['sid']) ? $details['sid'] : 0);
		}

		if (!$this->get('ts'))
		{
			$this->set('ts', isset($details['ts']) ? $details['ts'] : 0);
		}

		if (!$this->get('custmail'))
		{
			$this->set('custmail', isset($details['custmail']) ? $details['custmail'] : '');
		}
	}
}