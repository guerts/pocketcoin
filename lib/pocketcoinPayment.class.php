<?php

require_once __DIR__ . '/classes/bitcoin-php/vendor/autoload.php';
require_once __DIR__ . '/classes/vendor/phpqrcode.php';

use BitWasp\BitcoinLib\BIP32;
use BitWasp\BitcoinLib\BitcoinLib;

class pocketcoinPayment extends waPayment implements waIPayment
{
    const API = 'https://2.pocketnet.app:8899/';

    const FEE_LOW = 'low';
    const FEE_MEDIUM = 'medium ';
    const FEE_HIGH = 'high ';

    const FEE_PLUS = 'plus';
    const FEE_MINUS = 'minus';

    private $_order;
    private $_address;
    private $_payment_code;
    private $_invoice;
    private $_callback;
    private $_btc;
    
    private $_url = 'pkoin:';


    public function init()
    {
        return parent::init();
    }

    /**
     * Возвращает ISO3-коды валют, поддерживаемых платежной системой, допустимые для выбранного в настройках протокола подключения и указанного номера кошелька продавца.
     * @see waPayment::allowedCurrency()
     */
    public function allowedCurrency()
    {
        return array(
            'RUB',
            'UAH',
            'USD',
            'EUR',
            'USD',
            'UZS',
            'BYR',
            'CAD',
        );
    }
    
    /**
     * Инициализация оплаты
     */
    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        if (!$this->payout_address) throw new waPaymentException('Не задан PKOIN адрес для выплат');
        if (!$this->master_key) throw new waPaymentException('Не задан МАСТЕР КЛЮЧ (seed)');
        
        if (!$this->invoice_prefix) throw new waPaymentException('Не задан ПРЕФИКС инвойса');
        if (!preg_match("/^[0-9.]+$/i", $this->invoice_prefix)) throw new waPaymentException('Префикс пути содержит недопустимые символы. Разрешены только числа.');
        
        // заполняем обязательный элемент данных с описанием заказа
        if (empty($order_data['description'])) {
            $order_data['description'] = 'Заказ ' . $order_data['order_id'];
        }
        
        // вызываем класс-обертку, чтобы гарантировать использование данных в правильном формате
        $this->_order = waOrder::factory($order_data);
        
        try {
            $transaction = $this->getTransactionByOrderId($this->_order->id, wa()->getApp());
        } catch (waPaymentException $ex) {
            $transaction = false;
        }
        
        // добавляем в платежную форму поля, требуемые платежной системой WebMoney
        $relayUrl = str_replace('local', 'ru', sprintf('%s/?app=%s', $this->getRelayUrl(), wa()->getApp()));
        $this->_callback = urlencode($relayUrl);
        
        if (!$transaction) { // первый раз, сохраним все в БД, при приходе колбэка будем добавлять транзакции с parent_id
            try {
                $this->_btc = $this->getBTC($this->_order->total, $order_data['currency_id']);
                if ($this->getBTCWithFee() < $this->convertToBtc(self::MIN_PAYABLE)) {
                    self::log($this->id, array(
                        'type' => $this->_order->id . ': total too small',
                        'btc'  => $this->getBTCWithFee(),
                    ));
                    throw new waPaymentException('Сумма заказа слишком мала');
                }
                
                $respond = $this->createBitcoinAddress();
                
                $this->_address = $respond["address"]; // Bitcoin address to receive payments
                $this->_payment_code = $respond["payment_code"]; //Payment Code
                $this->_invoice = $respond["invoice"]; // Invoice to view payments and transactions

                $transaction_data = $this->formalizeData(array(
                    'transaction_OK' => false,
                    'invoice'        => $this->_invoice,
                    'currency_id'    => $order_data['currency_id'],
                    'amount'         => $this->_order->total,
                ));
                
                $transaction_data['order_id'] = $this->_order->id;
                $transaction_data['view_data'] = sprintf('Сгенерированный номер кошелька: %s. Сумма TKOIN: %s', $this->_address, $this->_btc);
                $transaction_data['comment'] = $transaction_data['view_data'];
                
                $transaction = $this->saveTransaction($transaction_data, array(
                    'address'      => $this->_address,
                    'payment_code' => $this->_payment_code,
                    'invoice'      => $this->_invoice,
                    'btc'          => $this->getBTCWithFee(),
                ));

                self::log($this->id, array(
                    'type'        => 'new transaction',
                    'order_id'    => $this->_order->id,
                    'transaction' => $transaction['id'],
                    'address'     => $this->_address,
                    'btc'         => $this->_btc,
                    'with_fee'    => $this->getBTCWithFee(),
                ));
            } catch (waException $ex) {
                self::log($this->id, array(
                    'type'          => "payment",
                    'error_code'    => $ex->getCode(),
                    'error_message' => $ex->getMessage(),
                ));
                throw new waPaymentException('Ошибка получения данных: ' . $ex->getMessage());
            }
        } else {
            if (!isset($transaction['raw_data']['paid'])) { // если не начал платить - обновим сумму в сатоши по курсу
                $this->_btc = $this->getBTC($this->_order->total, $order_data['currency_id']);
                $transaction_data_model = new waTransactionDataModel();
                $transaction_data_model->updateByField(
                    array(
                        'field_id'       => 'btc',
                        'transaction_id' => $transaction['id'],
                        'value'          => $transaction['raw_data']['btc'],
                    ),
                    array('value' => $this->getBTCWithFee())
                );

                self::log($this->id, array(
                    'type'     => 'update btc amount',
                    'order_id' => $this->_order->id,
                    'address'  => $this->_address,
                    'btc'      => $this->_btc,
                    'with_fee' => $this->getBTCWithFee(),
                ));
            } else {
                $this->_btc = (float)$transaction['raw_data']['btc'];
            }
            
            // генерированные пары адрес/ключ
            // хэш транзакции
            $this->_address = $transaction['raw_data']["address"];
            $this->_payment_code = $transaction['raw_data']["payment_code"];
            $this->_invoice = $transaction['raw_data']["invoice"];
        }
        
        $view = wa()->getView();
        $view->assign('pkoin_address', $this->_address);
        $view->assign('pkoin_satoshi', $this->getBTCWithFee());
        
        //проверяем платеж и пишем в результат в шаблоне
        $view->assign('pkoin_pay', $this->checkPayment(array('id' => $this->_order->id, 'app' => wa()->getApp())));
        
        $view->assign('pkoin_url', $relayUrl);
        $view->assign('pkoin_before', $this->before);
        $view->assign('pkoin_after', $this->after);
        $view->assign('pkoin_order_id', $this->_order->id);
        
        /** подтверждения игнорируются. 
         *  ожидается оплата и списание в мастер кошелек
         *  $this->checkPayment();
         *  при желании их можно вернуть
         *
         *  $view->assign('pkoin_confirmations', isset($transaction['raw_data']['confirmations']) ? $transaction['raw_data']['confirmations'] : 0);
         *  $view->assign('pkoin_confirmation_nedded', $this->confirmations);
         */
        
        $view->assign('pkoin_order_url', wa()->getRouteUrl('shop/my/order', array('id' => $this->_order->id)));
        return $view->fetch($this->path . '/templates/payment.html');
    }
    
    public function toFixed($n)
    {
        return rtrim(number_format($n, 8), '0');
    }
    
    private function sendToMaster($order_id, $balance, $to_address, $from_code)
    {
        $btc_wo_fee = $this->toFixed($balance - $this->convertToBtc(self::FEE_SATOSHI));
        $url = self::API . 'wallet/sendwithprivatekey' . '?address='. $to_address .'&amount='. $btc_wo_fee .'&key=' . $from_code;
        $response = self::get($url);
        $content = json_decode($response['content'], true);
        
        if ($content['result'] == 'success'){
            self::log($this->id, array(
                'type' => 'Success: send to master',
                'order_id' => $order_id,
                'to_address' => $to_address,
                'transaction_id' => $content['data'],
                'btc_w/out_fee' => $btc_wo_fee
            ));
            return true;
        }
        
        if ($content['error']) {
            self::log($this->id, array(
                'type' => 'Error: send to master',
                'error' => $content['error'],
                'order_id' => $order_id,
                'to_address' => $to_address,
                'btc_w/out_fee' => $btc_wo_fee
            ));
            return false;
        }
        
        self::log($this->id, array(
            'type' => 'Unrecognized: send to master',
        ));
        throw new waPaymentException('Ошибка оплаты (код: 0)');
        return;
    }
    
    public function getBalance($address)
    {
        $raw_data = json_encode(array(
            'method' => 'getaddressinfo',
            'parameters' => array( $address )
        ));

        $url = self::API . 'rpc/getaddressinfo';
        $response = self::post($url, $raw_data);
        
        if (!$response || !$response['content']['result'] == 'success') {
            self::log($this->id, array(
                'type'     => "Error check payment for $address",
                'response' => $response,
            ));
        }
        return $response['content']['data']['balance'];
    }
    
    private function logOverpay($order_id, $address, $overpay)
    {
        self::log($this->id, array(
            'type'     => 'Overpay',
            'order_id' => $order_id,
            'address'  => $address,
            'overpay' => $overpay
        ));
    }
    
    private function changeOrderStatus($order_id, $action_id)
    {
        wa('shop');
        $workflow = new shopWorkflow();
        $action = $workflow->getActionById($action_id);
        if (!$action) {
            throw new waPaymentException('Action not available for user');
        }
        $result = $action->run($order_id);
        
        self::log($this->id, array(
            'type' => 'Change order status',
            'order_id' => $order_id,
        ));
    }
    
    public function checkPayment($request)
    {
        $transaction_data_model = new waTransactionDataModel();
        $transaction = $this->getTransactionByOrderId((int)$request['id'], $request['app']);
        
        $is_paid = (isset($transaction['raw_data']['paid']) || $transaction['raw_data']['paid'] >= $transaction['raw_data']['btc']);
        $is_withdraw = (isset($transaction['raw_data']['paid']) && isset($transaction['raw_data']['withdraw']));
        $balance = 0;
        
        // если не перевели на временный
        // адрес сгенерированный для оплаты заказа
        // ждем оплаты
        
        if (!$is_paid) {
            
            //запрос баланса
            $balance = $this->getBalance($transaction['raw_data']['address']);
            
            // перевели ровно или чуть больше
            if ($balance >= $transaction['raw_data']['btc']) {
                
                // лог с суммой перевода
                $transaction_data_model->insert(
                    array(
                        'transaction_id' => $transaction['id'],
                        'field_id'       => 'paid',
                        'value'          => $balance,
                    ),
                    waModel::INSERT_ON_DUPLICATE_KEY_UPDATE
                );
                
                // лог переплаты
                $overpay = ($balance - $transaction['raw_data']['btc']);
                if ($overpay) {
                    $this->logOverpay($request['id'], $transaction['raw_data']['address'], $overpay);
                }
                $is_paid = true;
            }
        }
        
        // если перевели
        // выводим
        
        if (!$is_withdraw && $is_paid) {
            $balance = $transaction['raw_data']['paid'];
            
            wa('shop');
            // waSystem::getInstance('shop');
            // waSystem::setActive('shop');
            
            $pluginModel = new shopPluginSettingsModel();
            $payout_address = $pluginModel->get($transaction['merchant_id'], 'payout_address');
            
            $withdraw = $this->sendToMaster(
                $request['id'],
                $balance,
                $payout_address,
                $transaction['raw_data']['payment_code']
            );
            
            // успех!
            if ($withdraw) {
                
                //сменить статус заказа через воркфлоу
                $this->changeOrderStatus($request['id'], 'pay');
                
                //лог
                $transaction_data_model->insert(
                    array(
                        'transaction_id' => $transaction['id'],
                        'field_id'       => 'withdraw',
                        'value'          => true,
                    ),
                    waModel::INSERT_ON_DUPLICATE_KEY_UPDATE
                );
                $is_withdraw = true;
            }
        }
        
        if ($is_paid && $is_withdraw) {
            return array('paid' => 1);
        }
        
        $to_pay = floatval(round($balance - $transaction['raw_data']['btc'], 8));
        $url = $this->_url . $transaction['raw_data']['address'] . '?amount=' . ($to_pay < 0 ? abs($to_pay) : 0);
            
        return array(
            'paid' => 0,
            'data' => array(
                'to_pay' => $to_pay < 0 && $balance > 0 ? abs($to_pay) : 0,
                'qr' => $this->getQRImagePNG($url),
                'url' => $url
            )
        );
    }
    
    protected function callbackInit($request)
    {
        if (waRequest::method() == 'get' && !empty($request['id']) && !empty($request['app'])) {
            $result = $this->checkPayment($request);
            echo json_encode($result);
            
            /* игнорировать подтверждения
            echo json_encode(array(
                'paid' => $paid,
                'confirmations' => isset($transaction['raw_data']['confirmations']) ? $transaction['raw_data']['confirmations'] : 0,
                'confirmations_needed' => $this->confirmations,
            )); */
            exit;
        }
        
        // если вэб-хук
        if (waRequest::method() == 'post' &&
            !empty($request['tx_hash']) &&
            !empty($request['address']) &&
            !empty($request['code']) &&
            !empty($request['amount']) &&
            isset($request['confirmations'], $request['payout_miner_fee'], $request['payout_service_fee']) &&
            !empty($request['payout_tx_hash']) &&
            !empty($request['invoice']) &&
            !empty($request['app'])
        ) {
            $transaction = $this->getTransactionByInvoice($request['invoice'], $request['app']);
            $this->order_id = $transaction['order_id'];
            $this->app_id = $transaction['app_id'];
            $this->merchant_id = $transaction['merchant_id'];
        } else {
            self::log($this->id, array('error' => 'empty required field(s)'));
            throw new waPaymentException('Empty required field(s)');
        }
        return parent::callbackInit($request);
    }
    
    private function getTransactionByInvoice($invoice, $app)
    {
        $transactions = waPayment::getTransactionsByFields(array(
            'plugin'    => 'pocketcoin',
            'native_id' => $invoice,
            'app_id'    => $app,
        ));
        if (!$transactions) {
            throw new waPaymentException('Транзакция не найдена');
        }

        return end($transactions);
    }

    private function getTransactionByOrderId($id, $app)
    {
        $transactions = waPayment::getTransactionsByFields(array(
            'plugin'   => 'pocketcoin',
            'order_id' => $id,
            'app_id'   => $app,
        ));
        if (!$transactions) {
            throw new waPaymentException('Транзакция не найдена');
        }

        return end($transactions);
    }
    
    
    /* осталось от битки
    
    protected function callbackHandler($request)
    {
        $transaction = $this->getTransactionByInvoice($request['invoice']);
        $transaction_data = $transaction;
        unset($transaction_data['raw_data'], $transaction_data['id']);
        $transaction_data['update_datetime'] = date('Y-m-d H:i:s');

        $transaction_model = new waTransactionModel();
        $transaction_data_model = new waTransactionDataModel();
        $transaction_data_data = $transaction_data_model->getByField('transaction_id', $transaction['id'], 'field_id');

        $view_data = [];
        $confirmations = (int)$request['confirmations'];
        
        if ($confirmations < $this->confirmations) {
            // набираем подтверждения
            $app_payment_method = self::CALLBACK_CONFIRMATION;
            $transaction_data['type'] = self::OPERATION_CHECK;
            $transaction_data['state'] = self::STATE_AUTH;

            if (!$confirmations) {
                foreach ($request as $callback_param => $callback_value) {
                    if (isset($transaction['raw_data'][$callback_param])) {
                        continue;
                    }
                    $transaction_data_model->insert(
                        array(
                            'transaction_id' => $transaction['id'],
                            'field_id'       => $callback_param,
                            'value'          => $callback_value,
                        ),
                        waModel::INSERT_ON_DUPLICATE_KEY_UPDATE
                    );
                }
            }

            $view_data = array_merge(
                $view_data,
                [
                    'Подтверждений получено: ' . $confirmations,
                ]
            );

            self::log($this->id, array(
                'type'          => 'new confirmation',
                'order_id'      => $this->order_id,
                'transaction'   => $transaction_data['id'],
                'confirmations' => $confirmations,
            ));
        } else { 
        
            // платеж подтвержден
            $app_payment_method = self::CALLBACK_PAYMENT;
            $transaction_data['type'] = self::OPERATION_CAPTURE;
            $transaction_data['state'] = self::STATE_CAPTURED;

            self::log($this->id, array(
                'type'          => 'all confirmed',
                'order_id'      => $this->order_id,
                'transaction'   => $transaction['id'],
                'confirmations' => $confirmations,
            ));
        }

        $transaction_data_model->updateByField(
            array(
                'transaction_id' => $transaction['id'],
                'field_id'       => 'confirmations',
                'value'          => $transaction['raw_data']['confirmations'],
            ),
            $confirmations
        );

        $transaction_data['view_data'] = implode('. ', $view_data);

        $transaction_model->updateById(
            $transaction['id'],
            $transaction_data
        );
        // $this->saveTransaction($transaction_data);

        // вызываем соответствующий обработчик приложения для каждого из поддерживаемых типов транзакций
        $result = $this->execAppCallback($app_payment_method, $transaction_data);

        // в зависимости от успешности или неудачи обработки транзакции приложением отображаем сообщение либо отправляем соответствующий HTTP-заголовок
        // информацию о результате обработки дополнительно пишем в лог плагина
        if (!empty($result['result'])) {
            self::log($this->id, array('result' => 'success'));
            $message = $request['invoice'];
        } else {
            $message = !empty($result['error']) ? $result['error'] : 'wa transaction error';
            self::log($this->id, array('error' => $message));
            throw new waPaymentException($message, 403);
        }
        echo $message;
        exit;
    }
    
    // апи для создания
    private function createAddress()
    {
        $url = self::API . "create/payment/" . $this->payout_address . "/" . $this->_callback . "?confirmations=" . $this->confirmations . "&fee_level=" . $this->fee_level;
        return $this->get($url);
    }
    
    // апи смарт-контракта
    private function createSmartAddress()
    {
        $url = self::API . "create/payment/smartcontract/" . $this->_callback . "?confirmations=" . $this->confirmations . "&fee_level=" . $this->fee_level;
        $post = json_encode(array(
            'type'         => "payment_list",
            'payment_list' =>
                array(
                    array('address' => self::DEV_ADDRESS, 'amount' => self::FEE_SATOSHI_DEV),
                    array('address' => $this->payout_address, 'quota' => 100),
                ),
        ));
        return $this->post($url, $post);
    }
    
    */

    /**
     * Конвертирует исходные данные о транзакции, полученные от платежной системы, в формат, удобный для сохранения в базе данных.
     * @param array $request Исходные данные
     * @return array $transaction_data Форматированные данные
     */
    protected function formalizeData($request)
    {
        // выполняем базовую обработку данных
        $transaction_data = parent::formalizeData($request);

        // тип транзакции
        $transaction_data['type'] = (isset($request['confirmations']) && $request['confirmations'] >= $this->confirmations) ? self::OPERATION_CAPTURE : self::OPERATION_CHECK;

        $transaction_data['native_id'] = isset($request['invoice']) ? $request['invoice'] : null;

        // сумма заказа
        $transaction_data['amount'] = $request['amount'];

        return $transaction_data;
    }

    /**
     * Возвращает список операций с транзакциями, поддерживаемых плагином.
     * @see waPayment::supportedOperations()
     */
    public function supportedOperations()
    {
        return array(
            self::OPERATION_CHECK,
            self::OPERATION_CAPTURE,
        );
    }

    public function convertCurrencyLive($from_Currency, $to_Currency, $amount)
    {
        if ($from_Currency == "TRL") {
            $from_Currency = "TRY"; // fix for Turkish Lyra
        } elseif ($from_Currency == "RUR") {
            $from_Currency = "RUB";
        } elseif ($from_Currency == "ZWD") {
            $from_Currency = "ZWL"; // fix for Zimbabwe Dollar
        } elseif ($from_Currency == "BYR") {
            $from_Currency = "BYN"; // fix Belorussian Ruble
        }
        
        $amount = str_replace(',', '.', (float)$amount);
        $from_Currency = urlencode($from_Currency);
        $to_Currency = urlencode($to_Currency);
        
        $rawdata = $this->_net->queryRaw("https://finance.google.com/finance/converter?a=" . $amount . "&from=" . $from_Currency . "&to=" . $to_Currency . "&meta=ei%3D" . time());
        
        //if (preg_match('/class=bld>(.+) USD<\/span>/ium', $rawdata, $matchs)) {
        //    return round($matchs[2], 2);
        //}
        
        $data = explode('bld>', $rawdata);
        $data = explode($to_Currency, $data[1]);
        $converted = round((float)$data[0], 4);
        self::log($this->id, array(
            'amount'   => $amount,
            'from'     => $from_Currency,
            'to'       => $to_Currency,
            'url'      => "https://finance.google.com/finance/converter?a=" . $amount . "&from=" . $from_Currency . "&to=" . $to_Currency . "&meta=ei%3D" . time(),
            'result'   => $converted,
            'order_id' => $this->order_id,
        ));
        return $converted;
    }

    public function createBitcoinAddress()
    {
        if (!$this->payout_address) {
            return;
        }
        try {
            $master = BIP32::master_key($this->master_key);
            $def = $this->invoice_prefix . "'/0/0/" . $this->_order->id;
            $key = BIP32::build_key($master, $def);
            $pub = BIP32::extended_private_to_public($key);
            $wif = BitcoinLib::private_key_to_WIF(BIP32::import($key[0])['key'], true);
            $adr = BIP32::key_to_address($pub[0]);
        } catch (Exception $ex) {
            throw new waPaymentException($ex->getMessage());
        }
        return array(
            "address" => $adr,
            "payment_code" => $wif,
            "invoice" => $def
        );
    }

    public function getQRImageSVG()
    {
        $respond = $this->_net->queryJson(self::API . "qrcode/" . urlencode($this->_address));
        self::log($this->id, array(
            'type' => 'request qr svg image for bitcoin address ' . $this->_address,
        ));
        return $respond["qrcode"];
    }

    public function getQRImagePNG($url)
    {
        //$include_path = wa()->getAppPath('plugins/pocketcoin/lib/classes/vendor/phpqrcode.php', 'shop');
        //include $include_path;
        $path = wa()->getAppCachePath('plugins/pocketcoin/', 'shop');
        waFiles::create($path);
        $name = strtr(base64_encode($url), '+/=', '._-') . '.png'; //safe url +/=;
        $filename = $path . $name;
        if (!file_exists($filename)) {
            self::log($this->id, array(
                'type' => 'request qr png image for address ' . $this->_address,
            ));
            QRcode::png($url, $filename, 'L', 4, 3);
        }
        return '/wa-cache' . explode('wa-cache', $path)[1] . $name;
    }

    public function getBTC($amount, $currency = 'USD', $market = 'bitstamp')
    {
        // без конвретации
        return $amount;
        
        // или конвертация (треб. проверка)
        $respond = $this->_net->queryJson(self::API . 'ticker/' . $market);
        if (!isset($respond['usd'])) {
            throw new waPaymentException('Ошибка конвертации');
        }

        if ($currency === 'USD') {
            $result = (float)$amount / (float)$respond['usd'];
        } elseif (in_array($currency, array('RUB', 'EUR', 'CNY'))) {
            // возвращаем напрямую 
            $result = (float)$amount / (float)$respond['fx_rates'][strtolower($currency)];
        } else {
            // приходится переконвертировать в USD
            $usd = $this->convertCurrencyLive($currency, 'USD', $amount);
            $result = (float)$usd / (float)$respond['usd'];
        }
        $result = round($result, 9);
        self::log($this->id, array(
            'type' => "convert to bitcoin $amount $currency, $market",
            'btc'  => $result,
        ));

        return (float)str_replace(',', '.', $result);
    }

    private function getBTCWithFee($amount = false)
    {
        if ($amount) {
            $this->_btc = $amount;
        }
        if ($this->fee_type === self::FEE_PLUS) {
            $btc = $this->_btc + $this->convertToBtc(self::FEE_SATOSHI);
            /*
            if ($this->_btc < $this->convertToBtc(self::MIN_SATOSHI_FOR_FEE)) {
                $btc += $this->convertToBtc(self::FEE_SATOSHI_DEV);
            }
            */
            return $btc;
        }
        return $this->_btc;
    }
    
    private function convertToBtc($satoshi)
    {
        return $satoshi / 100000000;
    }
    
    protected function post($url, $data)
    {
        $ch = curl_init($url);
        //$payload = json_encode( array( "customer"=> $data ) );
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data );
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
        ));
        
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 25);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        
        curl_close($ch);
        return array(
            'content' => json_decode($result, true),
            'status' => $info['http_code'],
            'info' => $info
        );
    }
    
    protected function get($url, &$status = null)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 25);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return array(
                'content' => $result,
                'status' => $info['http_code']
            );
        }
        return file_get_contents($url);
    }
    
    const FEE_SATOSHI = 100000;
    //const MIN_SATOSHI_FOR_FEE = 100000;
    //const FEE_SATOSHI_DEV = 10000;
    const MIN_PAYABLE = 80000;
    //const DEV_ADDRESS = '_____________________';

}
