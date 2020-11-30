<?php

namespace Commerce\Payments;

use Exception;

class TinkoffPayment extends Payment implements \Commerce\Interfaces\Payment
{
    protected $_error;
    protected $_response;
    protected $_paymentUrl;
    protected $debug = false;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('tinkoff');
        $this->debug = !empty($this->getSetting('debug'));
    }

    public function getMarkup()
    {
        $out = [];

        if (empty($this->getSetting('terminal'))) {
            $out[] = $this->lang['tinkoff.error_empty_shop_id'];
        }

        if (empty($this->getSetting('password'))) {
            $out[] = $this->lang['tinkoff.error_empty_secret'];
        }

        $out = implode('<br>', $out);

        if (!empty($out)) {
            $out = '<span class="error" style="color: red;">' . $out . '</span>';
        }

        return $out;
    }

    public function getPaymentLink() {
        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->getOrder();
        $currency = ci()->currency->getCurrency($order['currency']);
        $payment = $this->createPayment($order['id'], ci()->currency->convertToDefault($order['amount'], $currency['code']));
        $cart = $processor->getCart();

        $data = [
            'Amount'        =>  $payment['amount']*100,
            'OrderId'       =>  $order['id'],
            'IP'            =>  \APIhelpers::getUserIP(),
            'Description'   =>  ci()->tpl->parseChunk($this->lang['payments.payment_description'], [ 'order_id'  => $order['id'], 'site_name' => $this->modx->getConfig('site_name') ]), 
            'NotificationURL'   => $this->modx->getConfig('site_url') . 'commerce/tinkoff/payment-process/?PaymentId='.$payment['id'],
            'SuccessURL'    => $this->modx->getConfig('site_url') . 'commerce/tinkoff/payment-success/',
            'FailURL'       => $this->modx->getConfig('site_url') . 'commerce/tinkoff/payment-failed/'
        ];
     
        $data['DATA'] = [
            'Email' => $order['email'],
            'Phone' => substr(preg_replace('/[^\d]+/', '', $order['phone']), 0, 15),
        ];

        if (!empty($order['phone']) || !empty($order['email'])) {
            $receipt = ['Items' => []];
            $items = $this->prepareItems($cart);

            $isPartialPayment = $payment['amount'] < $order['amount'];

            if ($isPartialPayment) {
                $items = $this->decreaseItemsAmount($items, $order['amount'], $payment['amount']);
            }

            foreach ($items as $item) {
                $receipt['Items'][] = [
                    'Name' => mb_substr($item['name'], 0, 64),
                    'Tax'    => $this->getSetting('vat_code'),
                    'Quantity'    => $item['count'],
                    'Amount'      => $item['price']*100*$item['count'],
                    'Price'       => $item['price']*100,
                    'PaymentMethod' => $isPartialPayment ? 'prepayment' : 'full_payment'
                ];
            }

            if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
                $receipt['Email'] = $order['email'];
            }
            if (!empty($order['phone'])) {
                $receipt['Phone'] = substr(preg_replace('/[^\d]+/', '', $order['phone']), 0, 15);
            }
            $receipt['Taxation'] = $this->getSetting('tax_system_code');
            $data['Receipt'] = $receipt;
        }

        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'Request data: <pre>' . print_r($data, true) . '</pre>',
                'Commerce Tinkoff Payment Debug: payment start');
        }

        $result = $this->request('Init', $data);
        if (isset($this->_error)) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, $result, 'Commerce Tinkoff Payment Error');
            }
            $docid = $this->modx->commerce->getSetting('payment_failed_page_id', $this->modx->getConfig('site_start'));
            $url = $this->modx->makeUrl($docid);

            return $url;
        }

        if ($this->debug) {
            $this->modx->logEvent(0, 1, $this->_paymentUrl, 'Commerce Tinkoff PaymentURL');
        }

        return $this->_paymentUrl;
    }

    private function _genToken($args) {
        if(isset($args['DATA'])) {
            unset($args['DATA']);
        }
        if(isset($args['Receipt'])) {
            unset($args['Receipt']);
        }
        if(isset($args['Token'])) {
            unset($args['Token']);
        }
        $token = '';
        $args['Password'] = $this->getSetting('password');
        ksort($args);

        foreach ($args as $arg) {
            if (!is_array($arg)) {
                $token .= $arg;
            }
        }
        $token = hash('sha256', $token);

        return $token;
    }

    public function handleCallback() {
        $processing_sid = !empty($this->getSetting('processing_status_id')) ? $this->getSetting('processing_status_id') : 2;
        $canceled_sid = !empty($this->getSetting('canceled_status_id')) ? $this->getSetting('canceled_status_id') : 5;

        if ($_SERVER["REQUEST_METHOD"] != "POST") {
            $this->modx->logEvent(0, 3, 'Invalid HTTP method (not POST)', 'Commerce Tinkoff Callback');
            echo 'NOTOK';
            return false;
        }


        $source = file_get_contents("php://input");
        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'Callback data: <pre>' . $source . '</pre>',
                'Commerce Tinkoff Payment Debug: callback start');
        }

        if (empty($source)) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Empty data', 'Commerce Tinkoff Payment');
            }
            return false;
        }

        $data = json_decode($source, true);

        if (empty($data)) {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Invalid json', 'Commerce Tinkoff Payment');
            }
            return false;
        }

        if ($data["TerminalKey"] != $this->getSetting('terminal')) {
            $this->modx->logEvent(0, 3, 'Invalid TerminalKey <pre>'.print_r($data, true), 'Commerce Tinkoff Callback');
            echo 'NOTOK';
            return false;
        }

        //Токен, который пришел в ответе    
        $original_token = $data['Token'];
        $data['Success'] = $data['Success'] ? 'true' : 'false';
        //Генерируем свой токен на основе пришедших данных
        $token = $this->_genToken($data);


        if ($token != $original_token) {
            $this->modx->logEvent(0, 3, 'Invalid TerminalKey <pre>'.print_r($data, true), 'Commerce Tinkoff Callback');
            echo 'NOTOK';
            return false;
        }

        $order_id = $data['OrderId'];
        $processor = $this->modx->commerce->loadProcessor();

        switch ($data['Status']) {
            case 'CONFIRMED':
            { 
                try {
                    $processor->processPayment($_REQUEST['PaymentId'], floatval($data['Amount']*0.01));
                    if ($this->debug) {
                        $this->modx->logEvent(0, 1, 'OdrerID: '.$order_id, 'Commerce tinkoff Payment CONFIRMED');
                    }
                    // $this->modx->invokeEvent('OnPageNotFound', ['callback' => &$payment]); // если необходимо обработать возвращаемые данные, н-р, отправить API-запрос в CRM
                } catch (Exception $e) {
                    if ($this->debug) {
                        $this->modx->logEvent(0, 3, 'JSON processing failed: ' . $e->getMessage(), 'Commerce tinkoff Payment');
                    }
                    return false;
                }
                break;
            }
            case 'AUTHORIZED':
            {
                try {
                    $processor->changeStatus($order_id, $processing_sid, 'Платеж в обработке', true);
                    if ($this->debug) {
                        $this->modx->logEvent(0, 1, 'OdrerID: '.$order_id, 'Commerce tinkoff Payment AUTHORIZED');
                    }
                } catch (\Exception $e) {
                    if ($this->debug) {
                        $this->modx->logEvent(0, 3, 'JSON processing failed: ' . $e->getMessage(), 'Commerce Tinkoff Payment (payment AUTHORIZED)');
                    }
                    return false;
                }
                break;
            }
            case 'CANCELED':
            case 'REFUNDED':
            case 'REVERSED':
            {
                try {
                    $processor->changeStatus($order_id, $canceled_sid, 'Платеж отменён', true);
                    if ($this->debug) {
                        $this->modx->logEvent(0, 1, 'OdrerID: '.$order_id, 'Commerce tinkoff Payment CANCELED');
                    }
                } catch (\Exception $e) {
                    if ($this->debug) {
                        $this->modx->logEvent(0, 3, 'JSON processing failed: ' . $e->getMessage(), 'Commerce Tinkoff Payment (payment canceled error)');
                    }
                    return false;
                }
                break;
            }
            default:
            {
                echo "OK";
                return false;
                break;
            }
        }
        
        
        echo "OK";
        return true;
    }


    protected function request($method, $data) {
        $url = 'https://securepay.tinkoff.ru/v2/';

        $data['TerminalKey'] = $this->getSetting('terminal');
        $data['Token'] = $this->_genToken($data);


        if ($curl = curl_init()) {
            curl_setopt($curl, CURLOPT_URL, $url . $method);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
            ));

            $result = curl_exec($curl);

            if ($this->debug) {
                $this->modx->logEvent(0, 1, print_r($result, true), 'Commerce Tinkoff Response');
            }

            $json = json_decode($result);
            if ($json) {
                if (@$json->ErrorCode !== "0") {
                    $this->_error = @$json->Details;
                } else {
                    $this->_paymentUrl = @$json->PaymentURL;
                    $this->_paymentId = @$json->PaymentId;
                    $this->_status = @$json->Status;
                }
            }

            curl_close($curl);

            return $result;

        } else {
            throw new HttpException(
                'Can not create connection to ' . $url . $method . ' with args '
                . $data, 404
            );
        }
    }
}
