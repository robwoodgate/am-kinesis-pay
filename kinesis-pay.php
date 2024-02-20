<?php
/**
 * Kinesis Pay
 * Copyright R Woodgate, Cogmentis Ltd.
 *
 * @desc Kinesis Pay lets you accept one-off payments in Gold and Silver
 */
/**
 * ============================================================================
 * Revision History:
 * ----------------
 * 2024-02-19   v1.0    R Woodgate  Plugin Created
 * ============================================================================.
 *
 * @am_payment_api 6.0
 */
class Am_Paysystem_KinesisPay extends Am_Paysystem_Abstract
{
    public const PLUGIN_STATUS = self::STATUS_BETA;
    public const PLUGIN_REVISION = '1.0';
    public const PAYMENT_ID = 'kinesis-pay-payment_id';
    public const API_BASE_URL = 'https://apip.kinesis.money';
    public const KMS_BASE_URL = 'https://kms.kinesis.money';

    protected $defaultTitle = 'Kinesis Pay';
    protected $defaultDescription = 'Pay with Gold or Silver with Kinesis Pay';

    public function init(): void
    {
        // We shouldn't *really* need this, but if the customer sneakily navigates
        // to the thanks page, we can at least continue to poll for payment status
        $this->getDi()->blocks->add(
            'thanks/notstarted',
            new Am_Block_Base('KPay Check Payment', 'kpay-payment-status', $this, [$this, 'checkPaymentStatus'])
        );
    }

    public function checkPaymentStatus(Am_View $v)
    {
        if (isset($v->invoice) && $v->invoice->paysys_id == $this->getId()) {
            $this->invoice = $v->invoice; // for the urls
            $cancel_url = $this->getCancelUrl();
            $status_url = $this->getPluginUrl('status', [
                'id' => $v->invoice->getSecureId($this->getId()),
            ]);

            return <<<CUT
                <script>
                jQuery.ajax({
                    type : "GET",
                    url : "{$status_url}",
                    success: function(data, textStatus, request){
                        console.log(data.status);
                        if ('rejected' === data.status){
                            alert('Payment has been rejected. Please try again.');
                            window.location.href = "{$cancel_url}";
                        } else if ('expired' === data.status){
                            alert('Payment request has expired. Please try again.');
                            window.location.href = "{$cancel_url}";
                        }
                        return false;
                      },
                    error: function( error ) {
                        console.log('ajax error: ', error);
                    }
                });
                </script>
                CUT;
        }
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'CHF', 'CNH', 'HKD', 'IDR', 'JPY', 'NZD', 'SGD', 'AED'];
    }

    public function _initSetupForm(Am_Form_Setup $form): void
    {
        $form->addText('merchant_id', ['class' => 'am-el-wide'])
            ->setLabel("Merchant ID\n".'From the Merchant Profile Box in the <a href="https://kms.kinesis.money/merchant/dashboard" target="_blank">Merchant menu</a> menu of your Kinesis account.')
            ->addRule('required')
        ;

        $form->addText('access_token', ['class' => 'am-el-wide'])
            ->setLabel("Merchant Access Token\n".'Via the "Merchant API Keys" link in the <a href="https://kms.kinesis.money/merchant/dashboard" target="_blank">Merchant menu</a> menu of your Kinesis account.')
            ->addRule('required')
        ;

        $form->addSecretText('secret_token', ['class' => 'am-el-wide'])
            ->setLabel("Merchant Secret Token\n".'Via the "Merchant API Keys" link in the <a href="https://kms.kinesis.money/merchant/dashboard" target="_blank">Merchant menu</a> menu of your Kinesis account.')
            ->addRule('required')
        ;
    }

    public function isConfigured()
    {
        return $this->getConfig('merchant_id') && $this->getConfig('access_token') && $this->getConfig('secret_token');
    }

    public function _process($invoice, $request, $result): void
    {
        // Get KAU and KAG amounts
        $kau_rate = $this->getExchangeRate('KAU', $invoice->currency);
        $kag_rate = $this->getExchangeRate('KAG', $invoice->currency);
        $total = Am_Currency::moneyRound($invoice->first_total, $invoice->currency);

        // Send request for a payment ID
        $params = [
            'globalMerchantId' => $this->getConfig('merchant_id'),
            'paymentKauAmount' => number_format($total / $kau_rate, 5, '.', ''),
            'paymentKagAmount' => number_format($total / $kag_rate, 5, '.', ''),
            'amount' => $total,
            'amountCurrency' => $invoice->currency,
        ];
        $resp = $this->_sendRequest('/api/merchants/payment', $params, 'GET PAYMENT ID');
        if (!in_array($resp->getStatus(), [200, 201])) {
            throw new Am_Exception_InputError($resp->getBody());
        }

        // Decode, check and save payment ID
        $body = json_decode($resp->getBody(), true);
        $gpid = $body['globalPaymentId'] ?? null;
        if (!$gpid) {
            throw new Am_Exception_InputError('Failed to create Kinesis payment id');
        }
        $invoice->data()->set(static::PAYMENT_ID, $gpid)->update();

        // Open pay page
        $kms_url = static::KMS_BASE_URL.'?paymentId='.$gpid;
        $assets_url = $this->getDi()->url(
            'application/default/plugins/payment/'.$this->getId().'/assets'
        );
        $img_base = $assets_url.'/images/';
        $css_url = $assets_url.'/css/style.css';
        $success_url = $this->getReturnUrl();
        $cancel_url = $this->getCancelUrl();
        $status_url = $this->getPluginUrl('status', [
            'id' => $invoice->getSecureId($this->getId()),
        ]);
        $a = new Am_Paysystem_Action_HtmlTemplate('pay.phtml');
        $a->invoice = $invoice;
        $a->form = <<<CUT
            <div id="kinesis-pay-content">
                <div class="kinesis-pay-logo-wrapper">
                    <img src="{$img_base}Kinesis-Pay-logo.png" style="width: auto; height: 48px;">
                    <span class="kinesis-pay-logo-title">Pay with K-Pay</span>
                </div>
                <span class="kinesis-pay-instructions">Scan the QR code with the Kinesis mobile app to complete the payment
                    <img style="display: inline-block; position: relative; top: 3px; width: 16px; height: 16px;"
                        src="{$img_base}Scan-QRCode.png">
                </span>
                <img style="display: block; width: 200px;max-height: 200px;"
                    src="https://chart.googleapis.com/chart?chs=150x150&amp;cht=qr&amp;chl={$kms_url}&amp;choe=UTF-8">
                <a style="display: block; white-space: nowrap; text-decoration: none; color: #017DE8;"
                    href="{$kms_url}" target="_blank">OR make the payment using the KMS</a>
                <div style="display: flex; justify-content: space-between; gap: 8px; flex-direction: column; align-items: center; margin-top: 24px;">
                    <span>Payment ID</span>
                    <div class="kinesis-pay-payment-info">
                        <input id="payment-id-text" type="text" value="{$gpid}" id="payment_id_value" readonly>
                        <button id="copy-button" onclick="copyPaymentId()">Copy</button>
                    </div>
                    <span class="kinesis-pay-expires">Payment ID expires in <span id="kinesis-pay-expires">10 minutes</span></span>
                    <a style="display: block; white-space: nowrap; text-decoration: none; color: #017DE8;" href="{$cancel_url}">Cancel</a>
                </div>
            </div>
            <script>
                function copyPaymentId() {
                    var copyText = document.getElementById("payment-id-text");
                    copyText.select();
                    document.execCommand("Copy");
                    alert("Payment ID has been copied.");
                }

                let pollCount = 0;
                const checkStatusTimer = setInterval(function () {
                    pollCount++;
                    jQuery.ajax({
                        type : "GET",
                        url : "{$status_url}&poll="+pollCount,
                        success: function(data, textStatus, request){
                            console.log(data.status);
                            if ('processed' === data.status){
                                window.location.href = "{$success_url}";
                            } else if ('rejected' === data.status){
                                clearInterval(checkStatusTimer);
                                alert('Payment has been rejected. Please try again.');
                                window.location.href = "{$cancel_url}";
                            } else if ('expired' === data.status){
                                clearInterval(checkStatusTimer);
                                alert('Payment request has expired. Please try again.');
                                window.location.href = "{$cancel_url}";
                            }

                            // Show expiry time
                            let expires = new Date(data.expiryAt);
                            let diffTime = expires - new Date();
                            let minutes = Math.ceil(diffTime / (60*1000));
                            let minLabel = (1 == minutes) ? "minute" : "minutes";
                            jQuery('#kinesis-pay-expires').html('less than '+minutes+' '+minLabel+'<br>'+expires.toLocaleString());
                            return false;
                          },
                        error: function( error ) {
                            console.log('ajax error: ', error);
                        }
                    });
                }, 10000);
                // Failsafe: quit after 10.5 mins
                setTimeout(function() {
                    clearInterval(checkStatusTimer);
                    window.location.href = "{$cancel_url}";
                }, 630000);
            </script>
            CUT;

        // Add in CSS
        $v = new Am_View();
        $v->headLink()->appendStylesheet($css_url);

        $result->setAction($a);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        // crickets... KPay doesn't notify, so we poll for status and handle
        // successful transactions separately. @see directAction()
    }

    public function directAction($request, $response, $invokeArgs)
    {
        // Check payment status
        if ('status' == $request->getActionName()) {
            // Get vars
            $inv_id = $request->getFiltered('id');
            $pcount = $request->getFiltered('poll');
            $this->invoice = $this->getDi()->invoiceTable->findBySecureId(
                $inv_id,
                $this->getId()
            );
            $txn_id = $this->invoice->data()->get(static::PAYMENT_ID);
            if (!$this->invoice) {
                throw new Am_Exception_InputError('Invalid link');
            }

            // Make request for status, log every 10th request
            $logTitle = (0 == $pcount % 10) ? "POLL STATUS #{$pcount}: {$txn_id}" : '';
            $resp = $this->_sendRequest(
                "/api/merchants/payment/id/sdk/{$txn_id}",
                null,
                $logTitle,
                Am_HttpRequest::METHOD_GET
            );

            // Check response
            if (!in_array($resp->getStatus(), [200, 201])) {
                throw new Am_Exception_InternalError(
                    'Unable to get payment status: '.$resp->getBody()
                );
            }

            // Handle processed payment
            // We are effectively mimicking an "IPN" call here and
            // performing the standard action handling via transactions
            $body = json_decode($resp->getBody(), true);
            if ('processed' === $body['status']) {
                $invoiceLog = $this->_logDirectAction($request, $resp, $invokeArgs);
                $transaction = new Am_Paysystem_KinesisPay_Transaction(
                    $this,
                    $request,
                    $response,
                    $invokeArgs
                );
                $transaction->setInvoiceLog($invoiceLog);

                try {
                    $transaction->process();
                    $this->approvePayment($txn_id, $this->invoice);
                } catch (Exception $e) {
                    if ($invoiceLog) {
                        $invoiceLog->add($e);
                    }

                    throw $e;
                }
                if ($invoiceLog) {
                    $invoiceLog->setProcessed();
                }
            }

            // Re-encode for return
            $response->ajaxResponse($body);

            return;
        }

        // Let parent process it
        return parent::directAction($request, $response, $invokeArgs);
    }

    public function getReadme()
    {
        $version = self::PLUGIN_REVISION;

        return <<<README
            <strong>Kinesis Pay Plugin v{$version}</strong>
            Kinesis Pay lets your customers pay for one-time purchases using Gold and Silver.

            If you do not already have a Kinesis Money account, <a href="https://kms.kinesis.money/signup/robertw866">register here</a>.

            <strong>Instructions</strong>

            1. Upload this plugin's folder and files to the <strong>amember/application/default/plugins/payment/</strong> folder of your aMember installatiion.

            2. Enable the plugin at <strong>aMember Admin -&gt; Setup/Configuration -&gt; Plugins</strong>

            3. Configure the plugin at <strong>aMember Admin -&gt; Setup/Configuration -&gt; Kinesis Pay.</strong>

            -------------------------------------------------------------------------------

            Copyright 2024 (c) Rob Woodgate, Cogmentis Ltd. All Rights Reserved

            This file may not be distributed unless permission is given by author.

            This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
            WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.

            For support (to report bugs and request new features) visit: <a href="https://www.cogmentis.com/">www.cogmentis.com</a>
            <img src="https://www.cogmentis.com/lcimg/kinesis-pay.jpg" />
            -------------------------------------------------------------------------------
            README;
    }

    /**
     * Convenience method to send authenticated KPay API requests.
     *
     * @param string       $url      Endpoint url
     * @param null|array   $params   Payload KVPs
     * @param null|string  $logTitle Invoice Log Title
     * @param string       $method   HTTP Method (GET, POST etc)
     * @param null|Invoice $invoice  aMember Invoice (for logging)
     *
     * @return HTTP_Request2_Response KPay server response
     */
    public function _sendRequest(
        $url,
        ?array $params = null,
        ?string $logTitle = null,
        $method = Am_HttpRequest::METHOD_POST,
        ?Invoice $invoice = null
    ): HTTP_Request2_Response {
        $req = $this->createHttpRequest();
        $req->setUrl(static::API_BASE_URL.$url);
        $req->setMethod($method);

        // Calculate signature
        $nonce = time() * 1000;
        $message = $nonce.$method.$url.(empty($params) ? '{}' : json_encode($params));
        $xsig = strtoupper(hash_hmac('SHA256', $message, $this->getConfig('secret_token')));

        // Add headers
        $headers = [
            'X-Nonce' => $nonce,
            'X-Api-Key' => $this->getConfig('access_token'),
            'X-Signature' => $xsig,
            'Accept' => 'application/json',
        ];
        if ('DELETE' !== $method) {
            $headers['Content-Type'] = 'application/json';
        }
        $req->setHeader($headers);

        // Add params and send
        if (!is_null($params)) {
            $req->setBody(json_encode($params));
        }
        $resp = $req->send();

        // Log it?
        if ($logTitle) {
            $log = $this->getDi()->invoiceLogTable->createRecord();
            if ($this->getConfig('disable_postback_log')) {
                $log->toggleDisablePostbackLog(true);
            }
            $invoice ??= $this->invoice;
            if ($invoice) {
                $log->setInvoice($invoice);
            }
            $log->paysys_id = $this->getId();
            $log->remote_addr = $_SERVER['REMOTE_ADDR'];
            $log->type = self::LOG_REQUEST;
            $log->title = $logTitle;
            $log->mask($this->getConfig('secret_token'), '***secret_token***');
            $log->add($req);
            $log->add($resp);
        }

        // Return response
        return $resp;
    }

    protected function getExchangeRate($crypto_currency = 'KAU', $base_currency = 'USD')
    {
        $pair = $crypto_currency.'_'.$base_currency;
        $resp = $this->_sendRequest(
            '/api/v1/exchange/coin-market-cap/orderbook/'.$pair.'?level=1',
            null,
            "GET {$pair} XRATE",
            Am_HttpRequest::METHOD_GET
        );
        if (!in_array($resp->getStatus(), [200, 201])) {
            throw new Am_Exception_InternalError(
                "Failed to get the {$pair} exchange rate: ".$resp->getBody()
            );
        }
        $body = json_decode($resp->getBody(), true);

        return $body['bids'][0];
    }

    protected function approvePayment($payment_id, $invoice)
    {
        // Mark payment as confirmed
        $params = [
            'globalPaymentId' => $payment_id,
            'orderId' => $invoice->public_id,
        ];
        $resp = $this->_sendRequest('/api/merchants/payment/confirm', $params, 'CONFIRM PAYMENT');
        if (!in_array($resp->getStatus(), [200, 201])) {
            throw new Am_Exception_InternalError('Unable to confirm payment. '.$resp->getBody());
        }

        // Decode, check and save payment ID
        $body = json_decode($resp->getBody(), true);
        $status = $body['status'] ?? null;
        if ('processed' != $status) {
            throw new Am_Exception_InternalError('Payment not approved. '.$resp->getBody());
        }
    }
}

class Am_Paysystem_KinesisPay_Transaction extends Am_Paysystem_Transaction_Incoming
{
    public function getUniqId()
    {
        return $this->invoice->data()->get(Am_Paysystem_KinesisPay::PAYMENT_ID);
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        $body = json_decode($this->request->getRawBody(), true);

        return 'processed' === $body['status'];
    }

    public function validateTerms()
    {
        return true;
    }

    public function findInvoiceId()
    {
        $inv_id = $this->request->getFiltered('id');
        $invoice = Am_Di::getInstance()->invoiceTable->findBySecureId(
            $inv_id,
            $this->getPlugin()->getId()
        );

        return $invoice ? $invoice->public_id : null;
    }
}
