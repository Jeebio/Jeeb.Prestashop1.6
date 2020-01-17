<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function jeeblog($contents)
{
    if (isset($contents)) {
        if (is_resource($contents)) {
            return error_log(serialize($contents));
        } else {
            return error_log(var_dump($contents, true));
        }

    } else {
        return false;
    }
}

class jeeb extends PaymentModule
{
    const PLUGIN_NAME = 'prestashop1.6';
    const PLUGIN_VERSION = '3.0';
    const BASE_URL = 'https://core.jeeb.io/api/';

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

        parent::__construct();

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
        $result = array();
        $check = array();
        $result = $db->ExecuteS('SELECT * FROM `' . _DB_PREFIX_ . 'order_state_lang` WHERE `name` = "Pending transaction on Jeeb";');
        
        if ($result == $check) {

            $order_pending_transaction = new OrderState();
            $order_pending_transaction->name = array_fill(0, 10, 'Pending transaction on Jeeb');
            $order_pending_transaction->send_email = 0;
            $order_pending_transaction->invoice = 0;
            $order_pending_transaction->color = '#fff870';
            $order_pending_transaction->unremovable = true;
            $order_pending_transaction->logable = 0;

            $order_pending_confirmation = new OrderState();
            $order_pending_confirmation->name = array_fill(0, 10, 'Pending confirmation on Jeeb');
            $order_pending_confirmation->send_email = 0;
            $order_pending_confirmation->invoice = 0;
            $order_pending_confirmation->color = '#82ff93';
            $order_pending_confirmation->unremovable = true;
            $order_pending_confirmation->logable = 0;

            $order_completed = new OrderState();
            $order_completed->name = array_fill(0, 10, 'Payment is completed on Jeeb');
            $order_completed->send_email = 1;
            $order_completed->invoice = 0;
            $order_completed->color = '#6164ff';
            $order_completed->unremovable = true;
            $order_completed->logable = 0;

            $order_expired = new OrderState();
            $order_expired->name = array_fill(0, 10, 'Payment is expired/canceled on Jeeb');
            $order_expired->send_email = 0;
            $order_expired->invoice = 0;
            $order_expired->color = '#ff6383';
            $order_expired->unremovable = true;
            $order_expired->logable = 0;

            $order_refunded = new OrderState();
            $order_refunded->name = array_fill(0, 10, 'Payment is refunded on Jeeb');
            $order_refunded->send_email = 0;
            $order_refunded->invoice = 0;
            $order_refunded->color = '#ffb463';
            $order_refunded->unremovable = true;
            $order_refunded->logable = 0;

            if ($order_pending_transaction->add()) {
                copy(
                    _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                    _PS_ROOT_DIR_ . '/img/os/' . (int) $order_pending_transaction->id . '.png'
                );
            }
            if ($order_pending_confirmation->add()) {
                copy(
                    _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                    _PS_ROOT_DIR_ . '/img/os/' . (int) $order_pending_confirmation->id . '.png'
                );
            }
            if ($order_completed->add()) {
                copy(
                    _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                    _PS_ROOT_DIR_ . '/img/os/' . (int) $order_completed->id . '.png'
                );
            }
            if ($order_expired->add()) {
                copy(
                    _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                    _PS_ROOT_DIR_ . '/img/os/' . (int) $order_expired->id . '.png'
                );
            }
            if ($order_refunded->add()) {
                copy(
                    _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                    _PS_ROOT_DIR_ . '/img/os/' . (int) $order_refunded->id . '.png'
                );
            }

            Configuration::updateValue('JEEB_PENDING_TRANSACTION', $order_pending_transaction->id);
            Configuration::updateValue('JEEB_PENDING_CONFIRMATION', $order_pending_confirmation->id);
            Configuration::updateValue('JEEB_COMPLETED', $order_completed->id);
            Configuration::updateValue('JEEB_EXPIRED', $order_expired->id);
            Configuration::updateValue('JEEB_REFUNDED', $order_refunded->id);
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

        Configuration::deleteByName('jeeb_signature');
        Configuration::deleteByName('jeeb_allow_testnet');
        Configuration::deleteByName('jeeb_allow_reject');
        Configuration::deleteByName('jeeb_base_currency');
        Configuration::deleteByName('jeeb_target_currency_btc');
        Configuration::deleteByName('jeeb_target_currency_bch');
        Configuration::deleteByName('jeeb_target_currency_ltc');
        Configuration::deleteByName('jeeb_target_currency_eth');
        Configuration::deleteByName('jeeb_target_currency_tbtc');
        Configuration::deleteByName('jeeb_target_currency_tltc');
        Configuration::deleteByName('jeeb_language');

        return parent::uninstall();
    }

    public function getContent()
    {
        $this->_html .= '<h2>' . $this->l('jeeb') . '</h2>';

        $this->_postProcess();
        // $this->_setjeebSubscription();
        $this->_setConfigurationForm();

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

    private function _setConfigurationForm()
    {
        $this->_html .= '<form method="post" action="' . htmlentities($_SERVER['REQUEST_URI']) . '">
                       <script type="text/javascript">
                       var pos_select = ' . (($tab = (int) Tools::getValue('tabs')) ? $tab : '0') . ';
                       </script>';

        if (_PS_VERSION_ <= '1.5') {
            $this->_html .= '<script type="text/javascript" src="' . _PS_BASE_URL_ . _PS_JS_DIR_ . 'tabpane.js"></script>
                         <link type="text/css" rel="stylesheet" href="' . _PS_BASE_URL_ . _PS_CSS_DIR_ . 'tabpane.css" />';
        } else {
            $this->_html .= '<script type="text/javascript" src="' . _PS_BASE_URL_ . _PS_JS_DIR_ . 'jquery/plugins/tabpane/jquery.tabpane.js"></script>
                         <link type="text/css" rel="stylesheet" href="' . _PS_BASE_URL_ . _PS_JS_DIR_ . 'jquery/plugins/tabpane/jquery.tabpane.css" />';
        }

        $this->_html .= '<input type="hidden" name="tabs" id="tabs" value="0" />
                       <div class="tab-pane" id="tab-pane-1" style="width:100%;">
                       <div class="tab-page" id="step1">
                       <h4 class="tab">' . $this->l('Settings') . '</h2>
                       ' . $this->_getSettingsTabHtml() . '
                       </div>
                       </div>
                       <div class="clear"></div>
                       <script type="text/javascript">
                       function loadTab(id){}
                       setupAllTabs();
                       </script>
                       </form>';
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

        $btc = $irr = $irt = $usd = $eur = $gbp = $cad = $aud = $aed = $try = $cny = $jpy = "";
        Configuration::get('jeeb_base_currency') == "btc" ? $btc = "selected" : $btc = "";
        Configuration::get('jeeb_base_currency') == "irr" ? $irr = "selected" : $irr = "";
        Configuration::get('jeeb_base_currency') == "irt" ? $irt = "selected" : $irt = "";
        Configuration::get('jeeb_base_currency') == "usd" ? $usd = "selected" : $usd = "";
        Configuration::get('jeeb_base_currency') == "eur" ? $eur = "selected" : $eur = "";
        Configuration::get('jeeb_base_currency') == "gbp" ? $gbp = "selected" : $gbp = "";
        Configuration::get('jeeb_base_currency') == "cad" ? $cad = "selected" : $cad = "";
        Configuration::get('jeeb_base_currency') == "aud" ? $aud = "selected" : $aud = "";
        Configuration::get('jeeb_base_currency') == "aed" ? $aed = "selected" : $aed = "";
        Configuration::get('jeeb_base_currency') == "try" ? $try = "selected" : $try = "";
        Configuration::get('jeeb_base_currency') == "cny" ? $cny = "selected" : $cny = "";
        Configuration::get('jeeb_base_currency') == "jpy" ? $jpy = "selected" : $jpy = "";

        $target_btc = $target_ltc = $target_eth = $target_bch = $target_tbtc = $target_tltc = "";
        Configuration::get("jeeb_target_currency_btc") == "btc" ? $target_btc = "checked" : $target_btc = "";
        Configuration::get("jeeb_target_currency_ltc") == "ltc" ? $target_ltc = "checked" : $target_ltc = "";
        Configuration::get("jeeb_target_currency_eth") == "eth" ? $target_eth = "checked" : $target_eth = "";
        Configuration::get("jeeb_target_currency_bch") == "bch" ? $target_bch = "checked" : $target_bch = "";

        Configuration::get("jeeb_target_currency_tbtc") == "tbtc" ? $target_tbtc = "checked" : $target_tbtc = "";
        Configuration::get("jeeb_target_currency_tltc") == "tltc" ? $target_tltc = "checked" : $target_tltc = "";

        $auto_select = $en = $fa = "";
        Configuration::get("jeeb_language") == "none" ? $auto_select = "selected" : $auto_select = "";
        Configuration::get("jeeb_language") == "en" ? $en = "selected" : $en = "";
        Configuration::get("jeeb_language") == "fa" ? $fa = "selected" : $fa = "";

        $html = '<h2>' . $this->l('Jeeb Payment Gateway Settings') . '</h2>
               <div style="clear:both;margin-bottom:30px;">
               <h3 style="clear:both;">' . $this->l('Signature') . '</h3>
               <input type="text" style="width:400px;" name="jeeb_signature" value="' . htmlentities(Tools::getValue('jeeb_signature', Configuration::get('jeeb_signature')), ENT_COMPAT, 'UTF-8') . '" />
               </div>

               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Base Currency') . '</h3>
               <label style="width:auto;">
               <select name="jeeb_base_currency">
               <option value="btc" ' . $btc . '>BTC (Bitcoin)</option>
               <option value="irr" ' . $irr . '>IRR (Iranian Rial)</option>
               <option value="irt" ' . $irt . '>IRT (Iranian Toman)</option>
               <option value="usd" ' . $usd . '>USD (US Dollar)</option>
               <option value="eur" ' . $eur . '>EUR (Euro)</option>
               <option value="gbp" ' . $gbp . '>GBP (British Pound)</option>
               <option value="cad" ' . $cad . '>CAD (Canadian Dollar)</option>
               <option value="aud" ' . $aud . '>AUD (Australian Dollar)</option>
               <option value="aed" ' . $aed . '>AED (Dirham)</option>
               <option value="try" ' . $try . '>TRY (Turkish Lira)</option>
               <option value="cny" ' . $cny . '>CNY (Chinese Yuan)</option>
               <option value="jpy" ' . $jpy . '>JPY (Japanese Yen)</option>

               </select> ' . $this->l('The base currency of your website.') . '</label>
               </div>

               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Payable Currencies') . '</h3>
               <input type="checkbox" name="jeeb_target_currency_btc" value="btc" ' . $target_btc . '>BTC (Bitcoin)<br>
               <input type="checkbox" name="jeeb_target_currency_ltc" value="ltc" ' . $target_ltc . '>LTC (Litecoin)<br>
               <input type="checkbox" name="jeeb_target_currency_eth" value="eth" ' . $target_eth . '>ETH (Ethereum)<br>
               <input type="checkbox" name="jeeb_target_currency_bch" value="bch" ' . $target_bch . '>BCH (Bitcoin Cash)<br>
               <input type="checkbox" name="jeeb_target_currency_tbtc" value="tbtc" ' . $target_tbtc . '>TBTC (Bitcoin Test)<br>
               <input type="checkbox" name="jeeb_target_currency_tltc" value="tltc" ' . $target_tltc . '>TLTC (Bitcoin Test)<br>

               <label style="width:auto;">' . $this->l('The currencies which users can use for payments (Multi-Select).') . '</label>
               </div>

               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Allow Testnet') . '</h3>
               <label style="width:auto;"><input type="checkbox" name="jeeb_allow_testnet" value="1" ' . $allowTestnet . '> ' . $this->l('Allow TestNets') . '</label>
               <br><label style="width:auto;">' . $this->l('Allows testnets such as TBTC (Bitcoin Test) to get processed. Disable it in production environment.') . '</label>
               </div>

               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">' . $this->l('Allow Refund') . '</h3>
               <label style="width:auto;"><input type="checkbox" name="jeeb_allow_refund" value="1" ' . $allowRefund . '> ' . $this->l('Allow Refund') . '</label>
               <br><label style="width:auto;">' . $this->l('Allows payments to be refunded when values don\'t match.') . '</label>
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

        if (Tools::isSubmit('submitjeeb')) {
            $template_available = array('A', 'B', 'C');
            $this->_errors = array();

            if (Tools::getValue('jeeb_signature') == null) {
                $this->_errors[] = $this->l('Missing API Key');
            }

            if (count($this->_errors) > 0) {
                $error_msg = '';

                foreach ($this->_errors as $error) {
                    $error_msg .= $error . '<br />';
                }

                $this->_html = $this->displayError($error_msg);
            } else {
                Configuration::updateValue('jeeb_signature', trim(Tools::getValue('jeeb_signature')));

                Configuration::updateValue('jeeb_base_currency', trim(Tools::getValue('jeeb_base_currency')));
                Configuration::updateValue('jeeb_target_currency_btc', trim(Tools::getValue('jeeb_target_currency_btc')));
                Configuration::updateValue('jeeb_target_currency_ltc', trim(Tools::getValue('jeeb_target_currency_ltc')));
                Configuration::updateValue('jeeb_target_currency_eth', trim(Tools::getValue('jeeb_target_currency_eth')));
                Configuration::updateValue('jeeb_target_currency_bch', trim(Tools::getValue('jeeb_target_currency_bch')));
                Configuration::updateValue('jeeb_target_currency_tbtc', trim(Tools::getValue('jeeb_target_currency_tbtc')));
                Configuration::updateValue('jeeb_target_currency_tltc', trim(Tools::getValue('jeeb_target_currency_tltc')));

                Configuration::updateValue('jeeb_allow_testnet', trim(Tools::getValue('jeeb_allow_testnet')));
                Configuration::updateValue('jeeb_allow_refund', trim(Tools::getValue('jeeb_allow_refund')));

                Configuration::updateValue('jeeb_language', trim(Tools::getValue('jeeb_language')));

                $this->_html = $this->displayConfirmation($this->l('Settings updated'));
            }

        }

    }

    private function validateOrderBeforePaymentExecution() {
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
        if (_PS_VERSION_ <= '1.5') {
            $callBack = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'order-confirmation.php?id_cart=' . $cart->id . '&id_module=' . $this->id . '&id_order=' . $this->currentOrder;
        } else {
            $callBack = Context::getContext()->link->getModuleLink('jeeb', 'validation');
        }

        // preparation
        $signature = Configuration::get('jeeb_signature'); // Signature
        $baseCur = Configuration::get('jeeb_base_currency');
        $lang = Configuration::get("jeeb_language") == "none" ? null : Configuration::get("jeeb_language"); //
        $notification = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://') . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8') . __PS_BASE_URI__ . 'modules/' . $this->name . '/ipn.php'; // Notification Url
        $order_total = $cart->getOrderTotal(true);
        $target_cur = '';
        $params = array(
            'btc',
            'ltc',
            'eth',
            'bch',
            'tbtc',
            'tltc',
        );

        foreach ($params as $p) {
            Configuration::get("jeeb_target_currency_" . $p) != null ? $target_cur .= Configuration::get("jeeb_target_currency_" . $p) . "/" : $target_cur .= "";
        }

        if ($baseCur == 'irt') {
            $baseCur = 'irr';
            $order_total *= 10;
        }

        // creating order
        $this->validateOrderBeforePaymentExecution();
        $orderId = (int) Order::getOrderByCartId($cart->id);
        $order = new Order($orderId);

        // converting total payable amount
        $amount = $this->convert_base_to_bitcoin($baseCur, $order_total);

        // creating payment
        $params = array(
            'orderNo' => strval($orderId),
            "coins" => $target_cur,
            'value' => (float) $amount,

            "allowTestNet" => Configuration::get("jeeb_allow_testnet") == "1" ? true : false,
            'allowReject' => Configuration::get('jeeb_allow_refund') == "1" ? true : false,
            "language" => $lang,

            'webhookUrl' => $notification,
            'callBackUrl' => $callBack,
        );

        $token = $this->create_payment($signature, $params);

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

    public function convert_base_to_bitcoin($baseCur, $payableAmount)
    {
        $ch = curl_init(self::BASE_URL . 'currency?&value=' . $payableAmount . '&base=' . $baseCur . '&target=btc');
        curl_setopt($ch, CURLOPT_USERAGENT, self::PLUGIN_NAME . '/' . self::PLUGIN_VERSION);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json')
        );

        $result = curl_exec($ch);
        $data = json_decode($result, true);

        return (float) $data["result"];
    }

    public function create_payment($signature, $payload = array())
    {
        $post = json_encode($payload);

        $ch = curl_init(self::BASE_URL . 'payments/' . $signature . '/issue/');
        curl_setopt($ch, CURLOPT_USERAGENT, self::PLUGIN_NAME . '/' . self::PLUGIN_VERSION);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($post))
        );

        $result = curl_exec($ch);
        $data = json_decode($result, true);

        return $data['result']['token'];
    }

    public function confirm_payment($signature, $token)
    {
        $payload = array(
            "token" => $token,
        );

        $post = json_encode($payload);

        $ch = curl_init(self::BASE_URL . 'payments/' . $signature . '/confirm');
        curl_setopt($ch, CURLOPT_USERAGENT, self::PLUGIN_NAME . '/' . self::PLUGIN_VERSION);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($post))
        );

        $result = curl_exec($ch);
        $data = json_decode($result, true);

        return (bool) $data['result']['isConfirmed'];
    }

    public function redirect_payment($token)
    {
        $redirect_url = self::BASE_URL . "payments/invoice?token=" . $token;
        header('Location: ' . $redirect_url);
    }

}
