<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class jeeb extends PaymentModule
{
    const PLUGIN_NAME = 'prestashop1.6';
    const PLUGIN_VERSION = '4.0';
    const BASE_URL = 'https://core.jeeb.io/api/v3/';

    private $_html = '';
    private $_postErrors = array();
    private $key;

    public function __construct()
    {
        include dirname(__FILE__) . '/config.php';
        $this->name = 'jeeb';
        $this->version = '1.6';
        $this->author = 'Jeeb';
        $this->className = 'jeeb';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->tab = 'payments_gateways';

        if (_PS_VERSION_ > '1.5') {
            $this->controllers = array('payment', 'validation');
        }

        $this->bootstrap = true;
        parent::__construct();

        $this->field_prefix = $this->name . '_';
        $this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Jeeb Payment Gateway');
        $this->description = $this->l('Accept BTC and other famous cryptocurrencies.');
        $this->confirmUninstall = $this->l('Are you sure you want to delete Jeeb Payment Gateway?');

        // Backward compatibility
        require _PS_MODULE_DIR_ . 'jeeb/backward_compatibility/backward.php';

        $this->context->smarty->assign('base_dir', __PS_BASE_URI__);

    }

    public function install()
    {
        if (!function_exists('curl_version')) {
            $this->_errors[] = $this->l('Sorry, this module requires the cURL PHP extension but it is not enabled on your server.  Please ask your web hosting provider for assistance.');
            return false;
        }

        $db = Db::getInstance();

        $state_options = $this->_stateOptions();

        $result = $db->ExecuteS('SELECT * FROM `' . _DB_PREFIX_ . 'order_state_lang` WHERE `name` = "' . array_values($state_options)[0]['name'] . '";');

        if (empty($result)) {
            foreach ($state_options as $key => $status) {
                $order_status = new OrderState();
                $order_status->module_name = $this->name;
                $order_status->name = array_fill(0, 10, $status['name']);
                $order_status->send_email = $status['send_email'];
                $order_status->invoice = 0; // all are same
                $order_status->color = $status['color'];
                $order_status->unremovable = true; // all are same
                $order_status->logable = 0; // all are same

                if ($order_status->add()) {
                    copy(
                        _PS_ROOT_DIR_ . '/modules/jeeb/logo.gif',
                        _PS_ROOT_DIR_ . '/img/os/' . (int) $order_status->id . '.gif'
                    );

                    Configuration::updateValue('JEEB_' . $key, $order_status->id);
                }
            }
        }
        if (!parent::install() || !$this->registerHook('invoice') || !$this->registerHook('payment') || !$this->registerHook('paymentReturn')) {
            return false;
        }

        $db = Db::getInstance();

        $query = "CREATE TABLE `" . _DB_PREFIX_ . "jeeb_order` (
                `id_payment` int(11) NOT NULL AUTO_INCREMENT,
                `id_order` varchar(255) NOT NULL,
                `key` varchar(255) NOT NULL,
                `cart_id` int(11) NOT NULL,
                `token` varchar(255) NOT NULL,
                `status` varchar(255) NOT NULL,
                PRIMARY KEY (`id_payment`),
                UNIQUE KEY `token` (`token`)
                ) ENGINE=" . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';

        $db->Execute($query);
        $query = "INSERT IGNORE INTO `ps_configuration` (`name`, `value`, `date_add`, `date_upd`) VALUES ('PS_OS_JEEB', '13', NOW(), NOW());";
        $db->Execute($query);

        return true;
    }

    public function uninstall()
    {
        $db = Db::getInstance();

        $query = "DROP TABLE `" . _DB_PREFIX_ . "jeeb_order`";

        $db->Execute($query);

        Configuration::deleteByName($this->_fieldName('apiKey'));
        Configuration::deleteByName($this->_fieldName('baseCurrency'));
        Configuration::deleteByName($this->_fieldName('payableCoins'));
        Configuration::deleteByName($this->_fieldName('allowRefund'));
        Configuration::deleteByName($this->_fieldName('allowTestnets'));
        Configuration::deleteByName($this->_fieldName('language'));
        Configuration::deleteByName($this->_fieldName('expiration'));
        // Configuration::deleteByName($this->_fieldName('webhookDebugUrl'));

        return parent::uninstall();
    }

    private function _stateOptions()
    {
        $options = array(
            'PENDING_TRANSACTION' => array(
                'name' => 'Pending transaction on Jeeb',
                'send_email' => 0,
                'color' => '#fff870',
            ),
            'PENDING_CONFIRMATION' => array(
                'name' => 'Pending confirmation on Jeeb',
                'send_email' => 0,
                'color' => '#82ff93',
            ),
            'COMPLETED' => array(
                'name' => 'Payment is completed on Jeeb',
                'send_email' => 1,
                'color' => '#6164ff',
            ),
            'EXPIRED' => array(
                'name' => 'Payment is expired/canceled on Jeeb',
                'send_email' => 0,
                'color' => '#ff6383',
            ),
            'REFUNDED' => array(
                'name' => 'Payment is rejected on Jeeb',
                'send_email' => 0,
                'color' => '#ffb463',
            ),
        );

        return $options;
    }

    public function getContent()
    {
        $this->_html = '';

        $this->_postProcess();

        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPayment($params)
    {
        global $smarty;

        $smarty->assign(array(
            'this_path' => $this->_path,
            'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL') . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__ . "modules/{$this->name}/")
        );

        return $this->display(__FILE__, 'payment.tpl');
    }

    private function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('General'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Key'),
                        'desc' => $this->l('The API key provided by Jeeb for you merchant.'),
                        'name' => $this->_fieldName('apiKey'),
                        'class' => 'fixed-width-xxl',
                        'required' => true,
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Base Currency'),
                        'desc' => $this->l('The base currency of your website.'),
                        'name' => $this->_fieldName('baseCurrency'),
                        'options' => array(
                            'query' => $this->_convertToSelectOptions($this->_getJeebAvailableCurrencies()),
                            'id' => 'id',
                            'name' => 'name',
                            'default' => array(
                                'value' => '',
                                'label' => $this->l('Choose a currency'),
                            ),
                        ),
                        'required' => true,
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Payable Coins'),
                        'desc' => $this->l('The coins which users can use for payments (Multi-Select).'),
                        'name' => $this->_fieldName('payableCoins'),
                        'class' => 'chosen',
                        'multiple' => true,
                        'options' => array(
                            'query' => $this->_convertToSelectOptions($this->_getJeebAvailableCoins()),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'radio',
                        'label' => $this->l('Allow Reject'),
                        'desc' => $this->l('Allows payments to be rejected.'),
                        'name' => $this->_fieldName('allowRefund'),
                        'class' => 't',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ),
                        ),
                    ),
                    array(
                        'type' => 'radio',
                        'label' => $this->l('Allow Testnets'),
                        'desc' => $this->l('Allows testnets such as TEST-BTC to get processed.'),
                        'name' => $this->_fieldName('allowTestnets'),
                        'class' => 't',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ),
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Language'),
                        'desc' => $this->l('Language of Jeeb gateway'),
                        'name' => $this->_fieldName('language'),
                        'options' => array(
                            'query' => [
                                ['id' => 'fa', 'name' => 'Persian'],
                                ['id' => 'en', 'name' => 'English'],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                            'default' => array(
                                'value' => '',
                                'label' => $this->l('Auto'),
                            ),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Expiration Time'),
                        'desc' => $this->l('Expands default payments expiration time. It should be between 15 to 2880 (mins).'),
                        'name' => $this->_fieldName('expiration'),
                        'class' => 'fixed-width-xxl',
                    ),

                    array(
                        'type' => 'text',
                        'label' => $this->l('Webhook.site URL'),
                        'desc' => $this->l('With Webhook.site, you instantly get a unique, random URL that you can use to test and debug Webhooks and HTTP requests.'),
                        'name' => $this->_fieldName('webhookDebugUrl'),
                        'class' => 'fixed-width-xxl',
                    ),
                ),

                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->id = (int) Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            $this->_fieldName('apiKey') => Tools::getValue($this->_fieldName('apiKey'), Configuration::get($this->_fieldName('apiKey'))),
            $this->_fieldName('baseCurrency') => Tools::getValue($this->_fieldName('baseCurrency'), Configuration::get($this->_fieldName('baseCurrency'))),
            $this->_fieldName('payableCoins[]') => Tools::getValue($this->_fieldName('payableCoins'), explode(',', Configuration::get($this->_fieldName('payableCoins')))),
            $this->_fieldName('allowRefund') => Tools::getValue($this->_fieldName('allowRefund'), Configuration::get($this->_fieldName('allowRefund'))),
            $this->_fieldName('allowTestnets') => Tools::getValue($this->_fieldName('allowTestnets'), Configuration::get($this->_fieldName('allowTestnets'))),
            $this->_fieldName('language') => Tools::getValue($this->_fieldName('language'), Configuration::get($this->_fieldName('language'))),
            $this->_fieldName('expiration') => Tools::getValue($this->_fieldName('expiration'), Configuration::get($this->_fieldName('expiration'))),
            $this->_fieldName('webhookDebugUrl') => Tools::getValue($this->_fieldName('webhookDebugUrl'), Configuration::get($this->_fieldName('webhookDebugUrl'))),
        );
    }

    private function _getSettingsTabHtml()
    {
        global $cookie;

        $lowSelected = '';
        $mediumSelected = '';
        $highSelected = '';
        $allowTestnet = '';
        $allowRefund = '';

        if (Configuration::get('jeeb_allow_testnet') == "1") {
            $allowTestnet = "checked";
        }

        if (Configuration::get('jeeb_allow_refund') == "1") {
            $allowRefund = "checked";
        }

        $target_btc = $target_doge = $target_ltc = $target_eth = $target_bch = $target_tbtc = $target_tdoge = $target_tltc = "";
        Configuration::get("jeeb_target_currency_btc") == "btc" ? $target_btc = "checked" : $target_btc = "";
        Configuration::get("jeeb_target_currency_doge") == "doge" ? $target_doge = "checked" : $target_doge = "";
        Configuration::get("jeeb_target_currency_ltc") == "ltc" ? $target_ltc = "checked" : $target_ltc = "";
        Configuration::get("jeeb_target_currency_eth") == "eth" ? $target_eth = "checked" : $target_eth = "";
        Configuration::get("jeeb_target_currency_bch") == "bch" ? $target_bch = "checked" : $target_bch = "";

        Configuration::get("jeeb_target_currency_tbtc") == "tbtc" ? $target_tbtc = "checked" : $target_tbtc = "";
        Configuration::get("jeeb_target_currency_tdoge") == "tdoge" ? $target_tdoge = "checked" : $target_tdoge = "";
        Configuration::get("jeeb_target_currency_tltc") == "tltc" ? $target_tltc = "checked" : $target_tltc = "";

        $auto_select = $en = $fa = "";
        Configuration::get("jeeb_language") == "none" ? $auto_select = "selected" : $auto_select = "";
        Configuration::get("jeeb_language") == "en" ? $en = "selected" : $en = "";
        Configuration::get("jeeb_language") == "fa" ? $fa = "selected" : $fa = "";

        $html = '<h2>' . $this->l('Jeeb Payment Gateway Settings') . '</h2>
               <div style="clear:both;margin-bottom:30px;">
               <h3 style="clear:both;">' . $this->l('API Key') . '</h3>
               <input type="text" style="width:400px;" name="jeeb_apiKey" value="' . htmlentities(Tools::getValue('jeeb_apiKey', Configuration::get('jeeb_apiKey')), ENT_COMPAT, 'UTF-8') . '" />
               </div>

               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Base Currency') . '</h3>
               <label style="width:auto;">
               <select name="jeeb_base_currency">';

        $jeeb_currencies = $this->_getJeebAvailableCurrencies();

        foreach ($jeeb_currencies as $currency => $title) {
            $is_selected = Configuration::get('jeeb_base_currency') === $currency;
            $html .= '<option value="' . $currency . '" ' . ($is_selected ? 'selected' : '') . '>' . $title . '</option>';
        }

        $html .= '</select> ' . $this->l('The base currency of your website.') . '</label>
               </div>

               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Payable Currencies') . '</h3>';

        $jeeb_coins = $this->_getJeebAvailableCoins();

        foreach ($jeeb_coins as $coin => $title) {
            echo $coin;
            $is_checked = Configuration::get('jeeb_target_currency_' . $coin) === $coin;
            $html .= '<input type="checkbox" name="jeeb_target_currency_' . $coin . '" value="' . $coin . '" ' . ($is_checked ? 'checked' : '') . '> ' . $title . '<br>';
        }

        $html .= '<label style="width:auto;">' . $this->l('The currencies which users can use for payments (Multi-Select).') . '</label>
               </div>

               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Allow Testnet') . '</h3>
               <label style="width:auto;"><input type="checkbox" name="jeeb_allow_testnet" value="1" ' . $allowTestnet . '> ' . $this->l('Allow TestNets') . '</label>
               <br><label style="width:auto;">' . $this->l('Allows testnets such as TBTC (Bitcoin Test) to get processed. Disable it in production environment.') . '</label>
               </div>

               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Allow Reject') . '</h3>
               <label style="width:auto;"><input type="checkbox" name="jeeb_allow_refund" value="1" ' . $allowRefund . '> ' . $this->l('Allow Refund') . '</label>
               <br><label style="width:auto;">' . $this->l('Allows payments to be rejected when amountsfew don\'t match.') . '</label>
               </div>



               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Language') . '</h3>
               <select name="jeeb_language">
               <option value="none" ' . $auto_select . '>Auto-Select</option>
               <option value="en" ' . $en . '>English</option>
               <option value="fa" ' . $fa . '>Farsi</option>
               </select>
               <br><label style="width:auto;">' . $this->l('Set the language of the payment page.') . '</label>
               </div>

               <p class="center"><input class="button" type="submit" name="submitjeeb" value="' . $this->l('Save Changes') . '" /></p>';

        return $html;
    }

    private function _postProcess()
    {
        global $currentIndex, $cookie;

        if (Tools::isSubmit('btnSubmit')) {
            $this->_errors = array();

            if (Tools::getValue($this->_fieldName('apiKey')) == null) {
                $this->_errors[] = $this->l('Missing API Key');
            }

            if (Tools::getValue($this->_fieldName('baseCurrency')) == null) {
                $this->_errors[] = $this->l('Choose a base currency');
            }

            if (count($this->_errors) > 0) {
                $error_msg = '';

                foreach ($this->_errors as $error) {
                    $error_msg .= '- ' . $error . '<br />';
                }

                $this->_html = $this->displayError($error_msg);
            } else {

                Configuration::updateValue($this->_fieldName('apiKey'), Tools::getValue($this->_fieldName('apiKey')));
                Configuration::updateValue($this->_fieldName('baseCurrency'), Tools::getValue($this->_fieldName('baseCurrency')));

                $payable_coins = implode(',', Tools::getValue($this->_fieldName('payableCoins')));
                Configuration::updateValue($this->_fieldName('payableCoins'), trim($payable_coins));

                Configuration::updateValue($this->_fieldName('allowRefund'), Tools::getValue($this->_fieldName('allowRefund')));
                Configuration::updateValue($this->_fieldName('allowTestnets'), Tools::getValue($this->_fieldName('allowTestnets')));
                Configuration::updateValue($this->_fieldName('language'), Tools::getValue($this->_fieldName('language')));
                Configuration::updateValue($this->_fieldName('expiration'), Tools::getValue($this->_fieldName('expiration')));
                Configuration::updateValue($this->_fieldName('webhookDebugUrl'), Tools::getValue($this->_fieldName('webhookDebugUrl')));

                $this->_html = $this->displayConfirmation($this->l('Settings updated'));
            }

        }

    }

    private function validateOrderBeforePaymentExecution()
    {
        $cart = Context::getContext()->cart;
        $new_cart_id = $cart->id;
        $new_status_jeeb = Configuration::get('JEEB_PENDING_TRANSACTION');
        $total = $cart->getOrderTotal(true);
        $new_displayName = Context::getContext()->controller->module->displayName;
        if (!empty($new_cart_id)) {
            $this->validateOrder($new_cart_id, $new_status_jeeb, $total, $new_displayName, null, array(), null, false, $this->context->cart->secure_key);
        }
    }

    public function execPayment($cart)
    {
        $order_total = round($cart->getOrderTotal(true), 8);
        $this->notify_log($order_total);

        $this->validateOrderBeforePaymentExecution();
        $orderId = (int) Order::getOrderByCartId($cart->id);
        $order = new Order($orderId);

        // preparation
        $api_key = Configuration::get($this->_fieldName('apiKey')); // API Key
        $base_currency = Configuration::get($this->_fieldName('baseCurrency'));

        $payable_coins = str_replace(',', '/', Configuration::get($this->_fieldName("payableCoins")));
        if ($payable_coins === '') {
            $payable_coins = null;
        }

        $allow_testnets = (bool) Configuration::get($this->_fieldName("allowTestnets"));
        $allow_refund = (bool) Configuration::get($this->_fieldName("allowRefund"));
        $language = Configuration::get($this->_fieldName("language"));
        $expiration = Configuration::get($this->_fieldName("expiration"));

        if (_PS_VERSION_ <= '1.5') {
            $callBack = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'order-confirmation.php?id_cart=' . $cart->id . '&id_module=' . $this->id . '&id_order=' . $this->currentOrder;
        } else {
            $callBack = Context::getContext()->link->getModuleLink('jeeb', 'validation');
        }

        $hash_key = md5($api_key . $orderId);
        $notification = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'modules/' . $this->name . '/ipn.php'; // Notification Url
        $notification .= '?hashKey=' . $hash_key;

        // creating payment
        $params = array(
            'orderNo' => strval($orderId),
            'baseCurrencyId' => $base_currency,
            "payableCoins" => $payable_coins,
            'baseAmount' => (float) $order_total,
            "allowTestNets" => $allow_testnets,
            'allowReject' => $allow_refund,
            'webhookUrl' => $notification,
            'callbackUrl' => $callBack,
            "language" => $language,
            "expiration" => $expiration,
        );

        $this->notify_log($params);

        $token = $this->create_payment($api_key, $params);

        // wrapping up
        $customerId = (int) $this->context->customer->id;

        $db = Db::getInstance();
        $result = array();
        $result = $db->ExecuteS('SELECT * FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . intval($customerId) . ';');
        $key = $result[0]["secure_key"];

        $status = Configuration::get('JEEB_PENDING_TRANSACTION');
        $result = $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'jeeb_order` (`id_order`, `key`, `cart_id`, `token`, `status`) VALUES("' . strval($orderId) . '", "' . $key . '", ' . intval($cart->id) . ', "' . $token . '", "' . $status . '") on duplicate key update `status`="' . $status . '"');

        $this->redirect_payment($token);
    }

    public function create_payment($api_key, $payload = array())
    {
        $post = json_encode($payload);

        $this->notify_log($payload);

        $ch = curl_init(self::BASE_URL . 'payments/issue/');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($post),
            'X-API-Key: ' . $api_key,
            'User-Agent:' . self::PLUGIN_NAME . '/' . self::PLUGIN_VERSION,
        )
        );

        $result = curl_exec($ch);
        $data = json_decode($result, true);

        return $data['result']['token'];
    }

    public function confirm_payment($api_key, $token)
    {
        $payload = array(
            "token" => $token,
        );

        $post = json_encode($payload);

        $ch = curl_init(self::BASE_URL . 'payments/seal');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($post),
            'X-API-Key: ' . $api_key,
            'User-Agent:' . self::PLUGIN_NAME . '/' . self::PLUGIN_VERSION,
        )
        );

        $result = curl_exec($ch);
        $data = json_decode($result, true);

        return (bool) $data['succeed'];
    }

    public function redirect_payment($token)
    {
        $redirect_url = self::BASE_URL . "payments/invoice?token=" . $token;
        header('Location: ' . $redirect_url);
    }

    public function _fieldName($key)
    {
        return $this->field_prefix . $key;
    }

    public function changeOrderStatus($orderId, $newStateId)
    {
        $new_history = new OrderHistory();
        $new_history->id_order = (int) $orderId;
        $new_history->changeIdOrderState((int) $newStateId, $orderId);

        if (!$new_history->save()) {
            $this->notify_log("Failed to change status of order#" . $orderId);
        }
    }

    public function addNoteToOrder($orderId, $note, $is_private = false)
    {
        $order = new Order((int) $orderId);

        $orderMessage = new Message();
        $orderMessage->id_order = $orderId;
        $orderMessage->id_customer = $order->customer_id;
        $orderMessage->message = $note;
        $orderMessage->private = 1;
        if ($orderMessage->save()) {
            $this->notify_log('saved message');
        }

    }

    private function _convertToSelectOptions($array)
    {
        $options = [];
        $keys = array_keys($array);
        $values = array_values($array);
        for ($i = 0; $i < count($array); $i++) {
            $options[] = ['id' => $keys[$i], 'name' => $values[$i]];
        }

        return $options;
    }
    /**
     * Return an array containing all available currencies in Jeeb Gateway
     *
     * @since 4.0
     * @return Array
     */
    private function _getJeebAvailableCurrencies()
    {
        $available_currencies = array(
            "IRT" => "IRT (Iranian Toman)",
            "IRR" => "IRR (Iranian Rial)",
            "BTC" => "BTC (Bitcoin)",
            "USD" => "USD (US Dollar)",
            "USDT" => "USDT (TetherUS)",
            "EUR" => "EUR (Euro)",
            "GBP" => "GBP (British Pound)",
            "CAD" => "CAD (Canadian Dollar)",
            "AUD" => "AUD (Australian Dollar)",
            "JPY" => "JPY (Japanese Yen)",
            "CNY" => "CNY (Chinese Yuan)",
            "AED" => "AED (Dirham)",
            "TRY" => "TRY (Turkish Lira)",
        );

        return $available_currencies;
    }

    /**
     * Return an array containing all available coins in Jeeb Gateway
     *
     * @since 4.0
     * @return Array
     */

    private function _getJeebAvailableCoins()
    {
        $available_coins = array(
            "BTC" => "BTC (Bitcoin)",
            "ETH" => "ETH (Ethereum)",
            "DOGE" => "DOGE (Dogecoin)",
            "LTC" => "LTC (Litecoin)",
            "USDT" => "USDT (TetherUS)",
            "BNB" => "BNB (BNB)",
            "USDC" => "USDC (USD Coin)",
            "ZRX" => "ZRX (0x)",
            "LINK" => "LINK (ChainLink)",
            "PAX" => "PAX (Paxos Standard)",
            "DAI" => "DAI (Dai)",
            "TBTC" => "TBTC (Bitcoin Testnet)",
            "TETH" => "TETH (Ethereum Testnet)",
        );

        return $available_coins;
    }

    /**
     * Push message to webhook.site endpoint
     *
     * @since       3.4.0
     * @access      private
     * @param       $message
     * @return      void
     */
    public function notify_log($message, $force = false)
    {
        $webhook_debug_url = Configuration::get($this->_fieldName('webhookDebugUrl'));

        if ($webhook_debug_url || true === $force) {
            $post = gettype($message) == 'array' ? json_encode($message) : $message;
            $ch = curl_init($webhook_debug_url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
            ));

            curl_exec($ch);
        }
    }
}
