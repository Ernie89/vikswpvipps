<?php
/**
 * @package     VikVippsMobilePay
 * @subpackage  core
 * @author      Nuna Media Designs
 * @copyright   Copyright (C) 2018 VikWP All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://vikwp.com
 */
defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('adapter.payment.payment');

abstract class AbstractVippsMobilePayPayment extends JPayment
{
    protected function buildAdminParameters()
    {
        return array(
            'merchant_serial_number' => array(
                'type'  => 'text',
                'label' => 'Vipps MobilePay Merchant Serial Number (MSN):',
            ),
            'client_id' => array(
                'type'  => 'text',
                'label' => 'Vipps MobilePay Client ID:',
            ),
            'client_secret' => array(
                'type'  => 'password',
                'label' => 'Vipps MobilePay Client Secret:',
            ),
            'subscription_key' => array(
                'type'  => 'password',
                'label' => 'Vipps MobilePay Ocp-Apim-Subscription-Key:',
            ),
            'sandbox' => array(
                'type'      => 'select',
                'label'     => 'Environment:',
                'options'   => array('production', 'test'),
            ),
        );
    }

    public function __construct($alias, $order, $params = [])
    {
        parent::__construct($alias, $order, $params);
    }

    protected function beginTransaction()
    {
        if (isset($_GET['ver'])) {
            $this->handleReturnFlow();
            return;
        }

        $reference = $this->buildVippsReference();
        $amountMinorUnits = (int) round(((float) $this->get('total_to_pay')) * 100);
        $currency = strtoupper((string) $this->get('transaction_currency'));

        try {
            $token = $this->fetchAccessToken();
            $returnUrl = $this->appendQueryParam($this->get('return_url'), 'ver', '1');
            $returnUrl = $this->appendQueryParam($returnUrl, 'reference', $reference);

            $payload = array(
                'amount' => array(
                    'value' => $amountMinorUnits,
                    'currency' => $currency,
                ),
                'paymentMethod' => array(
                    'type' => 'WALLET',
                ),
                'reference' => $reference,
                'userFlow' => 'WEB_REDIRECT',
                'returnUrl' => $returnUrl,
                'paymentDescription' => (string) $this->get('transaction_name'),
            );

            $response = $this->vippsRequest('POST', '/epayment/v1/payments', $token, $payload, true);

            if (!empty($response['body']['redirectUrl'])) {
                $redirectUrl = $response['body']['redirectUrl'];
                echo '<button onclick="window.location.href=\'' . esc_attr($redirectUrl) . '\'">Pay with Vipps MobilePay</button>';
                return;
            }

            echo 'Unable to start Vipps MobilePay payment. Please try again later.';
        } catch (\Throwable $e) {
            echo 'Unable to connect to Vipps MobilePay: ' . esc_html($e->getMessage());
        }
    }

    protected function validateTransaction(JPaymentStatus &$status)
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!is_array($data)) {
            $status->appendLog('Vipps MobilePay: callback payload missing or invalid.');
            return true;
        }

        $reference = $this->extractReference($data);
        if (empty($reference)) {
            $status->appendLog('Vipps MobilePay: callback without reference.');
            return true;
        }

        try {
            $token = $this->fetchAccessToken();
            $payment = $this->vippsRequest('GET', '/epayment/v1/payments/' . rawurlencode($reference), $token);

            $isAuthorized = $this->hasPaymentState($payment['body'], array('AUTHORIZED', 'CAPTURED'));

            if (!$isAuthorized) {
                $status->appendLog('Vipps MobilePay: payment not authorized yet.');
                return true;
            }

            $amount = isset($payment['body']['amount']['value']) ? (int) $payment['body']['amount']['value'] : 0;
            $captured = isset($payment['body']['aggregate']['capturedAmount']['value']) ? (int) $payment['body']['aggregate']['capturedAmount']['value'] : 0;
            $currency = isset($payment['body']['amount']['currency']) ? $payment['body']['amount']['currency'] : $this->get('transaction_currency');

            $captureResult = $this->captureIfNeeded($reference, $token, $amount, $captured, $currency);
            if (isset($captureResult['error'])) {
                $status->appendLog($captureResult['error']);
                return true;
            }

            $paidAmount = $amount / 100;
            $transaction = array(
                'driver' => 'vippsmobilepay.php',
                'payment_id' => $reference,
                'amount' => $paidAmount,
                'reference' => $reference,
                'type' => 'CAPTURE',
                'transaction' => isset($payment['body']['pspReference']) ? $payment['body']['pspReference'] : '',
            );

            $status->setData('transaction', $transaction);
            $status->paid($paidAmount);
            $status->verified();
            $status->appendLog('Vipps MobilePay: payment captured for reference ' . $reference . '.');
            return true;
        } catch (\Throwable $e) {
            $status->appendLog('Vipps MobilePay: payment verification failed. ' . $e->getMessage());
            return true;
        }
    }

    protected function complete($res = 0)
    {
        $app = JFactory::getApplication();

        if ($res) {
            $url = $this->get('return_url');
            $app->enqueueMessage(__('Thank you! Payment successfully received.', 'vikvippsmobilepay'));
        } else {
            $url = $this->get('error_url');
            $app->enqueueMessage(__('It was not possible to verify the payment. Please, try again.', 'vikvippsmobilepay'));
        }

        JFactory::getApplication()->redirect($url);
        exit;
    }

    public function isRefundSupported()
    {
        return true;
    }

    protected function doRefund(JPaymentStatus &$status)
    {
        $transaction = $this->get('transaction');
        $amount = (float) $this->get('total_to_refund');
        $currency = strtoupper((string) $this->get('transaction_currency'));

        if (!isset($transaction[0]->payment_id)) {
            $status->appendLog('Vipps MobilePay: invalid payment reference for refund.');
            return false;
        }

        if ($amount <= 0 || $amount > (float) $transaction[0]->amount) {
            $status->appendLog('Vipps MobilePay: invalid refund amount.');
            return false;
        }

        $reference = $transaction[0]->payment_id;
        $minorAmount = (int) round($amount * 100);

        try {
            $token = $this->fetchAccessToken();
            $payload = array(
                'modificationAmount' => array(
                    'currency' => $currency,
                    'value' => $minorAmount,
                ),
            );

            $refund = $this->vippsRequest(
                'POST',
                '/epayment/v1/payments/' . rawurlencode($reference) . '/refund',
                $token,
                $payload,
                true
            );

            if (isset($refund['body']['aggregate']['refundedAmount']['value'])) {
                $status->verified();
                $status->paid($amount);
                $status->appendLog('Vipps MobilePay: refund completed for ' . $reference . '.');
            } else {
                $status->appendLog('Vipps MobilePay: refund response missing aggregate data.');
            }
        } catch (\Throwable $e) {
            $status->appendLog('Vipps MobilePay: refund failed. ' . $e->getMessage());
        }

        return true;
    }

    protected function handleReturnFlow()
    {
        $reference = isset($_GET['reference']) ? sanitize_text_field(wp_unslash($_GET['reference'])) : '';

        if (empty($reference)) {
            $this->complete(0);
        }

        try {
            $token = $this->fetchAccessToken();
            $payment = $this->vippsRequest('GET', '/epayment/v1/payments/' . rawurlencode($reference), $token);
            $isAuthorized = $this->hasPaymentState($payment['body'], array('AUTHORIZED', 'CAPTURED'));

            if ($isAuthorized) {
                $amount = isset($payment['body']['amount']['value']) ? (int) $payment['body']['amount']['value'] : 0;
                $captured = isset($payment['body']['aggregate']['capturedAmount']['value']) ? (int) $payment['body']['aggregate']['capturedAmount']['value'] : 0;
                $currency = isset($payment['body']['amount']['currency']) ? $payment['body']['amount']['currency'] : $this->get('transaction_currency');
                $this->captureIfNeeded($reference, $token, $amount, $captured, $currency);
            }

            $verificationPayment = $this->vippsRequest('GET', '/epayment/v1/payments/' . rawurlencode($reference), $token);
            $result = $this->hasPaymentState($verificationPayment['body'], array('CAPTURED', 'AUTHORIZED'));
            $this->complete($result ? 1 : 0);
        } catch (\Throwable $e) {
            $this->complete(0);
        }
    }

    protected function captureIfNeeded($reference, $token, $amount, $captured, $currency)
    {
        if ($amount <= 0 || $captured >= $amount) {
            return array('skipped' => true);
        }

        $capturePayload = array(
            'modificationAmount' => array(
                'currency' => $currency,
                'value' => $amount,
            ),
        );

        $capture = $this->vippsRequest(
            'POST',
            '/epayment/v1/payments/' . rawurlencode($reference) . '/capture',
            $token,
            $capturePayload,
            true
        );

        if (!isset($capture['body']['aggregate']['capturedAmount']['value'])) {
            return array('error' => 'Vipps MobilePay: capture response missing aggregate data.');
        }

        return $capture;
    }

    protected function hasPaymentState(array $payment, array $acceptedStates)
    {
        if (!isset($payment['state'])) {
            return false;
        }

        $normalizedAccepted = array_map('strtoupper', $acceptedStates);
        $state = $payment['state'];

        if (is_string($state)) {
            return in_array(strtoupper($state), $normalizedAccepted, true);
        }

        if (is_array($state)) {
            foreach ($state as $value) {
                if (is_string($value) && in_array(strtoupper($value), $normalizedAccepted, true)) {
                    return true;
                }
                if (is_array($value)) {
                    foreach ($value as $nestedValue) {
                        if (is_string($nestedValue) && in_array(strtoupper($nestedValue), $normalizedAccepted, true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    protected function extractReference(array $data)
    {
        if (!empty($data['reference'])) {
            return (string) $data['reference'];
        }

        if (!empty($data['payment']['reference'])) {
            return (string) $data['payment']['reference'];
        }

        if (!empty($data['data']['reference'])) {
            return (string) $data['data']['reference'];
        }

        if (!empty($data['url']) && is_string($data['url'])) {
            $parts = parse_url($data['url']);
            if (!empty($parts['path']) && preg_match('#/payments/([a-zA-Z0-9\-]{8,64})#', $parts['path'], $matches)) {
                return $matches[1];
            }
        }

        return '';
    }

    protected function fetchAccessToken()
    {
        $headers = array(
            'client_id: ' . $this->getParam('client_id'),
            'client_secret: ' . $this->getParam('client_secret'),
            'Ocp-Apim-Subscription-Key: ' . $this->getParam('subscription_key'),
            'Merchant-Serial-Number: ' . $this->getParam('merchant_serial_number'),
            'Vipps-System-Name: vikbooking',
            'Vipps-System-Version: 1.0.0',
            'Vipps-System-Plugin-Name: vikvippsmobilepay',
            'Vipps-System-Plugin-Version: 1.0.0',
            'Content-Type: application/json',
        );

        $response = $this->rawHttpRequest('POST', $this->getApiBaseUrl() . '/accesstoken/get', $headers, '');

        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            throw new RuntimeException('Auth failed with HTTP ' . $response['http_code'] . ': ' . $response['body']);
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body) || empty($body['access_token'])) {
            throw new RuntimeException('Auth response does not contain access_token.');
        }

        return $body['access_token'];
    }

    protected function vippsRequest($method, $path, $token, array $payload = array(), $idempotent = false)
    {
        $headers = array(
            'Authorization: Bearer ' . $token,
            'Ocp-Apim-Subscription-Key: ' . $this->getParam('subscription_key'),
            'Merchant-Serial-Number: ' . $this->getParam('merchant_serial_number'),
            'Vipps-System-Name: vikbooking',
            'Vipps-System-Version: 1.0.0',
            'Vipps-System-Plugin-Name: vikvippsmobilepay',
            'Vipps-System-Plugin-Version: 1.0.0',
            'Content-Type: application/json',
        );

        if ($idempotent) {
            $headers[] = 'Idempotency-Key: ' . $this->generateIdempotencyKey();
        }

        $body = '';
        if (!empty($payload)) {
            $body = wp_json_encode($payload);
        }

        $response = $this->rawHttpRequest($method, $this->getApiBaseUrl() . $path, $headers, $body);
        $decoded = json_decode($response['body'], true);

        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            $message = is_array($decoded) && !empty($decoded['detail']) ? $decoded['detail'] : $response['body'];
            throw new RuntimeException('Vipps request failed [' . $method . ' ' . $path . '] HTTP ' . $response['http_code'] . ': ' . $message);
        }

        return array(
            'http_code' => $response['http_code'],
            'body' => is_array($decoded) ? $decoded : array(),
        );
    }

    protected function rawHttpRequest($method, $url, array $headers, $body = '')
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return array(
            'http_code' => $httpCode,
            'body' => $responseBody,
        );
    }

    protected function appendQueryParam($url, $key, $value)
    {
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }

    protected function buildVippsReference()
    {
        $base = sprintf('vb-%s-%s-%s', $this->get('sid'), $this->get('ts'), wp_rand(100000, 999999));
        $ref = preg_replace('/[^a-zA-Z0-9\-]/', '-', $base);
        $ref = trim((string) $ref, '-');

        if (strlen($ref) < 8) {
            $ref .= '-payment';
        }

        return substr($ref, 0, 64);
    }

    protected function generateIdempotencyKey()
    {
        return sprintf(
            '%s-%s-%s',
            gmdate('YmdHis'),
            wp_rand(100000, 999999),
            substr(md5(uniqid((string) wp_rand(), true)), 0, 12)
        );
    }

    protected function getApiBaseUrl()
    {
        return $this->getParam('sandbox') === 'test' ? 'https://apitest.vipps.no' : 'https://api.vipps.no';
    }
}
