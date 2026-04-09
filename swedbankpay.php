<?php
/**
 * @package     VikSwedBankPay
 * @subpackage  core
 * @author      Khalil Fareh.
 * @copyright   Copyright (C) 2018 VikWP All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://vikwp.com
 */
defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('adapter.payment.payment');
$dir = dirname(__FILE__);
$autoload = $dir . '/swedbankpay/lib/vendor/autoload.php';
require $autoload;
use SwedbankPay\Api\Service\Creditcard\Request\Purchase;
use SwedbankPay\Api\Service\Creditcard\Resource\Request\PaymentPurchaseCreditcard;
use SwedbankPay\Api\Service\Creditcard\Resource\Request\PaymentPurchaseObject;
use SwedbankPay\Api\Service\Creditcard\Resource\Request\PaymentUrl;
use SwedbankPay\Api\Service\Creditcard\Resource\Request\PaymentPurchase;
use SwedbankPay\Api\Service\Payment\Resource\Collection\PricesCollection;
use SwedbankPay\Api\Service\Payment\Resource\Collection\Item\PriceItem;
use SwedbankPay\Api\Service\Payment\Resource\Request\Metadata;
use SwedbankPay\Api\Service\Creditcard\Resource\Request\PaymentPayeeInfo;
use SwedbankPay\Api\Client\Client;
use SwedbankPay\Api\Service\Data\ResponseInterface as ResponseServiceInterface;

use SwedbankPay\Api\Service\Creditcard\Transaction\Request\CreateCapture;
use SwedbankPay\Api\Service\Creditcard\Transaction\Request\CreateReversal;
use SwedbankPay\Api\Service\Creditcard\Transaction\Request\CreateCancellation;
use SwedbankPay\Api\Service\Creditcard\Transaction\Resource\Request\TransactionCapture;
use SwedbankPay\Api\Service\Creditcard\Transaction\Resource\Request\TransactionReversal;
use SwedbankPay\Api\Service\Creditcard\Transaction\Resource\Request\TransactionCancellation;
use SwedbankPay\Api\Service\Payment\Transaction\Resource\Response\TransactionObject;
use SwedbankPay\Api\Service\Payment\Transaction\Resource\Response\AuthorizationObject;
use SwedbankPay\Api\Service\Payment\Transaction\Resource\Response\CaptureObject;
use SwedbankPay\Api\Service\Payment\Transaction\Resource\Response\ReversalObject;

abstract class AbstractSwedbankpayPayment extends JPayment
{
    protected function buildAdminParameters()
    {
        $logo_img = VIKSWEDBANKPAY_URI.'swedbankpay/swedbankpay.svg';
		return array(
			'logo' => array(
				'type'  => 'custom',
				'label' => '',
				'html'  => '<img src="' . $logo_img . '"/>', // Replace with your SwedBankPay logo URL
			),
			'shop_id' => array(
				'type'  => 'text',
				'label' => 'SwedBankPay Shop ID:',
			),
			'payee_id' => array(
				'type'  => 'text',
				'label' => 'SwedBankPay Payee Id:',
			),
			'access_token' => array(
				'type'  => 'password',
				'label' => 'SwedBankPay Access Token:',
			),
			'sandbox' => array(
				'type'      => 'select',
				'label'     => 'Test Mode: // If True, SwedBankPay Sandbox will be used',
				'options'   => array('production', 'test'),
			)
		);

    }

    public function __construct($alias, $order, $params = [])
    {
        parent::__construct($alias, $order, $params);
    }

    protected function beginTransaction()
    {  
    	$details = $this->get('details');
        
		$notify_url = $this->get('notify_url');
        $return_url = $this->get('return_url').'&ver=0';
        $completed_url = $this->get('return_url').'&ver=1';

		$store_id = $this->getParam('store_id');
		$cart_name 	= htmlspecialchars($this->get('transaction_name'));
        $transaction_amount = number_format( (float) $this->get('total_to_pay'), 2, '', '' );
		$currency = $this->get('transaction_currency');
        
        $language= $details['lang'];
        
		$user_agent = JFactory::getApplication()->input->server->getString('HTTP_USER_AGENT', '');


		$sandbox = $this->getParam('sandbox');
        $payee_id = $this->getParam('payee_id');
        $shop_id = $this->getParam('shop_id'); 
		$access_token = $this->getParam('access_token');
        
		$reference = $this->get('sid').'_'.$this->get('ts').'_'.rand(10000,99999);
        $order_id = $this->get('sid').'_'.$this->get('ts');
        
        if(isset($_GET['ver'])){
        	$order_confirmed = $_GET['ver'];
            if($order_confirmed){
                $this->complete(1);
            }else{
                $this->complete(0);
            }        
        }


		$url = new PaymentUrl();
        $url->setCompleteUrl($completed_url)
            ->setCancelUrl($return_url)
            ->setCallbackUrl($notify_url)
            ->setHostUrls([$return_url]);

        $payeeInfo = new PaymentPayeeInfo();
        $payeeInfo->setPayeeId($payee_id)
            ->setPayeeReference($reference)
            ->setOrderReference($order_id);

        $price = new PriceItem();
        $price->setType('Creditcard')
            ->setAmount($transaction_amount)
            ->setVatAmount(0);

        $prices = new PricesCollection();
        $prices->addItem($price);

        $creditCard = new PaymentPurchaseCreditcard();
        $creditCard->setNo3DSecure(true);

        $metadata = new Metadata();
        $metadata->setData('order_id',$order_id);

        $payment = new PaymentPurchase();
        $payment->setOperation('Purchase')
            ->setIntent('Authorization')
            ->setCurrency($currency)
            ->setGeneratePaymentToken(true)
            ->setDescription($cart_name)
            ->setPayerReference($reference)
			->setUserAgent($user_agent)
            ->setLanguage($language)
            ->setUrls($url)
            ->setPayeeInfo($payeeInfo)
            ->setPrices($prices)
            ->setMetadata($metadata);

        $paymentObject = new PaymentPurchaseObject();
        $paymentObject->setPayment($payment);
        $paymentObject->setCreditCard($creditCard);
		$client = new Client();
		$client->setAccessToken($access_token)
				->setPayeeId($payee_id)
				->setMode($sandbox);
        $purchaseRequest = new Purchase($paymentObject);
        $purchaseRequest->setClient($client);
		try {
					
			$responseService = $purchaseRequest->send();
			$responseData = $responseService->getResponseData();
			$redirectUrl = $responseService->getOperationByRel('redirect-authorization', 'href');
				
		} catch (\Exception $ex) {
			$error = $ex->getMessage();
            
	        echo 'Sorry We having problem connecting to SwedBankPay Payments.';
		}	
		

        if(!empty($redirectUrl)){
           $form = '<button onclick="window.location.href=\'' . $redirectUrl . '\'">Click To Pay Now</button>';

        }else{
       	   $form = 'We Have a problem connecting to Tap Payment , please try again Later';
        }

		//output form
		echo $form;
	
    }

    protected function validateTransaction(JPaymentStatus &$status)
    {
        $details = $this->get('details');
        
		$sandbox = $this->getParam('sandbox');
        $payee_id = $this->getParam('payee_id');
        $shop_id = $this->getParam('shop_id'); 
		$pba_id =  $this->getParam('pba_id');
		$access_token = $this->getParam('access_token');
        
        $real_order_id = $this->get('sid').'_'.$this->get('ts');
        
		$client = new Client();
		$client->setAccessToken($access_token)
				->setPayeeId($payee_id)
				->setMode($sandbox);

		// Get the callback request body
		$request_body = file_get_contents('php://input');

		// Decode the JSON request body
		$request_data = json_decode($request_body, true);

		// Check if the JSON decoding was successful
		if ($request_data !== null) {
			// Extract the payment ID from the decoded JSON data
			if (isset($request_data['payment']['id'])) {
				$payment_id = $request_data['payment']['id'];
			} else {
				return true;
			}
		} else {
			return true;
		}

        
		try{
			$client = $client->request('GET', $payment_id);
			$responseBody = $client->getResponseBody();
			$responseBody = json_decode($responseBody,true);
			$paid = $this->getOperationByRel($responseBody, 'paid-payment', false);
		} catch (\Throwable $th) {
			$status->appendLog("SwedBankPay:the payment failed .");
			return true;
		}



		if (!empty($paid)) {
			try {
				$url = $paid['href'];
				if (filter_var($url, FILTER_VALIDATE_URL)) {
					$parsed = parse_url($url); // phpcs: ignore
					$url = $parsed['path'];
					if (!empty($parsed['query'])) {
						$url .= '?' . $parsed['query'];
					}
				}
				
				$result = $client->request($paid['method'],$url);
				$result = $client->getResponseBody();
				$result = json_decode($result,true);

				if(isset($result['paid']['payeeReference'])){
                    $reference = $result['paid']['payeeReference'];
                    $order_id = $result['paid']['orderReference'];
                    if($real_order_id != $order_id){
                    	$status->appendLog("SwedBankPay:Wrong Order Id.".$order_id);
                        $status->appendLog("SwedBankPay:Order Id.".$real_order_id);
                        return false;
                    }
                    
					$status->appendLog("SwedBankPay:Payment Authorized .".$order_id);
					$charge_amount =  $result['paid']['amount'];
                    $cpature_reference = $reference.rand(1000,9999);
                    
                    try{
                       $capture = $this->capture($payment_id,$client,$cpature_reference,$charge_amount);
                       if (isset($capture['capture']['transaction'])) {
                            
                            if($capture['capture']['transaction']['state'] == 'Completed'){
                                
								$charge_amount = $charge_amount / 100;
								$transaction = [];
								$transaction['driver']= 'swedbankpay.php';
								$transaction['payment_id'] = $payment_id;
								$transaction['amount'] = $charge_amount;
								$transaction['reference'] = $reference;
								$transaction['type'] = $capture['capture']['transaction']['type'];
								$transaction['transaction'] = $result['paid']['transaction'];
								$status->setData('transaction', $transaction);
								$status->paid($charge_amount);
								$status->verified();
								$status->appendLog("Transaction Captured:!\n".$cpature_reference);
                                return true;
                            }

                        } else {
                            $status->appendLog("SwedBankPay:we coudln't capture the amount .".serialize($capture));

                        }
                    } catch (\Throwable $th) {
						   $status->appendLog("SwedBankPay:we have error in capturing the amount: ".serialize($th));

					}

				}else{
					$status->appendLog("SwedBankPay:the payment failed .".serialize($result));
				}

			} catch (\Throwable $th) {
				    $status->appendLog("SwedBankPay:we have error veryfing the payment: ".serialize($th));
                
			}
		}
        
		return true;

    }

	protected function getOperationByRel(array $data, $rel, $single = true)
	{
		if (!isset($data['operations'])) {
			return false;
		}

		$operations = $data['operations'];
		$operation = array_filter($operations, function ($value) use ($rel) {
			return (is_array($value) && $value['rel'] === $rel);
		}, ARRAY_FILTER_USE_BOTH);

		if (count($operation) > 0) {
			$operation = array_shift($operation);

			return $single ? $operation['href'] : $operation;
		}

		return false;
	}


    protected function complete($res = 0)
    {
        $app = JFactory::getApplication();

        if ($res) {
            $url = $this->get('return_url');

            // display successful message
            $app->enqueueMessage(__('Thank you! Payment successfully received.', 'vikswedbankpay'));
        } else {
            $url = $this->get('error_url');

            // display error message
            $app->enqueueMessage(__('It was not possible to verify the payment. Please, try again.', 'vikswedbankpay'));
        }

        JFactory::getApplication()->redirect($url);
        exit;
    }
    
    /**
	 * @override
	 *
	 * This Stripe integration does support refunds.
	 *
	 * @return 	boolean
	 */
	public function isRefundSupported()
	{
		return true;
	}
    
    /**
	 * @override
	 *
	 * Executes the refund transaction by collecting the passed data.
	 *
	 * @return 	boolean
	 */
	protected function doRefund(JPaymentStatus &$status) 
	{
		$transaction = $this->get('transaction');
		$amount 	 = $this->get('total_to_refund');
		$currency    = $this->get('transaction_currency');
        $reason = $this->get('refund_reason');

		$sandbox = $this->getParam('sandbox');
        $payee_id = $this->getParam('payee_id');
        $shop_id = $this->getParam('shop_id'); 
		$pba_id =  $this->getParam('pba_id');
		$access_token = $this->getParam('access_token');
        

		if(!isset($transaction[0]->payment_id)){
		   	$status->appendLog('Invalid Payment Id');
		    return false;
		}else{
		   	$payment_id = $transaction[0]->payment_id;
		}

		if (($amount <= 0) && ($amount > $transaction[0]->amount)) {
			$status->appendLog('Invalid transaction amount');
			return false;
		}


		$status->appendLog('Start To Refund for :'.$payment_id);
    	$status->appendLog('Amount : '.$amount);
    	$status->appendLog('Currency :'.$currency);

		$reference = $this->get('sid').'_'.$this->get('ts').'_'.rand(100000,999999);
		$amount = $amount * 100;

		try{
			$client = new Client();
			$client->setAccessToken($access_token)
					->setPayeeId($payee_id)
					->setMode($sandbox);
			$refund = $this->reversal($payment_id,$client,$reference,$amount,$reason);
			$status->appendLog("SwedBankPay:Refund situation: ");
			if($refund['reversal']['transaction']['state'] == 'Completed'){
				$amount = $amount / 100;
				$status->appendLog("Refund Completed :!\n".$currency.$amount);
				$status->verified();
				$status->paid($amount);
			}else{
				$status->appendLog("SwedBankPay:Refund Failed");
			}

		} catch (\Throwable $th) {

			$status->appendLog("SwedBankPay:we have error in capturing the amount: ".serialize($th));
			 
		}

        

        return true;
	}
    
    
    protected function capture($payment_id,$client,$reference,$amount)
    {
        if (!$payment_id) {
			return false;
        }

        $transactionData = new TransactionCapture();
        $transactionData->setAmount($amount)
            ->setVatAmount(0)
            ->setDescription('Capture the amount')
            ->setPayeeReference($reference);

        $transaction = new TransactionObject();
        $transaction->setTransaction($transactionData);

        $requestService = new CreateCapture($transaction);
        $requestService->setClient($client);
        $requestService->setPaymentId($payment_id);

        $responseService = $requestService->send();

        $responseResource = $responseService->getResponseResource();

        $result = $responseService->getResponseData();

        return $result;
    }

    /**
     * @depends SwedbankPayTest\Test\CardPaymentTest::testPurchaseRequest
     * @param string $paymentId
     * @throws Exception
     */
    protected function reversal($payment_id,$client,$reference,$amount,$reason)
    {
        if (!$payment_id) {
			return false;
        }

        $transactionData = new TransactionReversal();
        $transactionData->setAmount($amount)
            ->setVatAmount(0)
            ->setDescription($reason)
            ->setPayeeReference($reference);

        $transaction = new TransactionObject();
        $transaction->setTransaction($transactionData);

        $requestService = new CreateReversal($transaction);
        $requestService->setClient($client);
        $requestService->setPaymentId($payment_id);

        $responseService = $requestService->send();

        $responseResource = $responseService->getResponseResource();

        $result = $responseService->getResponseData();
        return $result;
    }
}