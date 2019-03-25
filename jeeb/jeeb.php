<?php

if (!defined('_PS_VERSION_'))
  exit;

function jeeblog($contents) {
  if(isset($contents)) {
    if(is_resource($contents))
      return error_log(serialize($contents));
    else
      return error_log(var_dump($contents, true));
  } else {
    return false;
  }
}

function convertIrrToBtc($url, $amount, $signature, $baseCur) {
    error_log("Entered into Convert Base To Target");

    // return Jeeb::convert_irr_to_btc($url, $amount, $signature);
    $ch = curl_init($url.'currency?'.$signature.'&value='.$amount.'&base='.$baseCur.'&target=btc');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json')
  );

  $result = curl_exec($ch);
  $data = json_decode( $result , true);
  error_log('Response =>'. var_export($data, TRUE));
  // Return the equivalent bitcoin value acquired from Jeeb server.
  return (float) $data["result"];

  }


  function createInvoice($url, $amount, $options = array(), $signature) {
      error_log("Entered into Create Invoice");

      $post = json_encode($options);

      $ch = curl_init($url.'payments/' . $signature . '/issue/');
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json',
          'Content-Length: ' . strlen($post))
      );

      $result = curl_exec($ch);
      $data = json_decode( $result , true);
      error_log('Response =>'. var_export($data, TRUE));

      return $data['result']['token'];

  }

  function redirectPayment($url, $token) {
    error_log("Entered into auto submit-form");
    // Using Auto-submit form to redirect user with the token
    echo "<form id='form' method='post' action='".$url."payments/invoice'>".
            "<input type='hidden' autocomplete='off' name='token' value='".$token."'/>".
           "</form>".
           "<script type='text/javascript'>".
                "document.getElementById('form').submit();".
           "</script>";
  }

class jeeb extends PaymentModule {
    private $_html       = '';
    private $_postErrors = array();
    private $key;

    public function __construct() {
      include(dirname(__FILE__).'/config.php');
      $this->name            = 'jeeb';
      $this->version         = '1.6';
      $this->author          = 'Jeeb';
      $this->className       = 'jeeb';
      $this->currencies      = true;
      $this->currencies_mode = 'checkbox';
      $this->tab             = 'payments_gateways';
      if (Configuration::get('jeeb_TESTMODE') == "1") {
        $this->jeeburl       = $testurl;
        $this->apiurl          = $testurl;
      } else {
        $this->jeeburl       = $jeeburl;
        $this->apiurl          = $jeeburl;
      }
      $this->sslport         = $sslport;
      $this->verifypeer      = $verifypeer;
      $this->verifyhost      = $verifyhost;
      if (_PS_VERSION_ > '1.5')
      $this->controllers = array('payment', 'validation');

      parent::__construct();

      $this->page = basename(__FILE__, '.php');
      $this->displayName      = $this->l('jeeb');
      $this->description      = $this->l('Accepts Bitcoin payments via Jeeb.');
      $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');

      // Backward compatibility
      require(_PS_MODULE_DIR_ . 'jeeb/backward_compatibility/backward.php');

      $this->context->smarty->assign('base_dir',__PS_BASE_URI__);

    }

    public function install() {

      if(!function_exists('curl_version')) {
        $this->_errors[] = $this->l('Sorry, this module requires the cURL PHP extension but it is not enabled on your server.  Please ask your web hosting provider for assistance.');
        return false;
      }

        $db = Db::getInstance();
        $result = array();
        $check = array();
        $result = $db->ExecuteS('SELECT * FROM `' . _DB_PREFIX_ . 'order_state_lang` WHERE `name` = "Awaiting Jeeb payment";');
        error_log("Result = ".print_r($result,true));
        if($result==$check){
          error_log("Entered install");
          $order_pending = new OrderState();
          $order_pending->name = array_fill(0, 10, 'Awaiting Jeeb payment');
          $order_pending->send_email = 0;
          $order_pending->invoice = 0;
          $order_pending->color = 'RoyalBlue';
          $order_pending->unremovable = false;
          $order_pending->logable = 0;
          $order_expired = new OrderState();
          $order_expired->name = array_fill(0, 10, 'Jeeb payment expired');
          $order_expired->send_email = 0;
          $order_expired->invoice = 0;
          $order_expired->color = '#DC143C';
          $order_expired->unremovable = false;
          $order_expired->logable = 0;
          $order_confirming = new OrderState();
          $order_confirming->name = array_fill(0, 10, 'Awaiting Jeeb payment confirmations');
          $order_confirming->send_email = 0;
          $order_confirming->invoice = 0;
          $order_confirming->color = '#d9ff94';
          $order_confirming->unremovable = false;
          $order_confirming->logable = 0;
          $order_invalid = new OrderState();
          $order_invalid->name = array_fill(0, 10, 'Jeeb invoice is invalid');
          $order_invalid->send_email = 0;
          $order_invalid->invoice = 0;
          $order_invalid->color = '#8f0621';
          $order_invalid->unremovable = false;
          $order_invalid->logable = 0;
          if ($order_pending->add()) {
            copy(
                _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                _PS_ROOT_DIR_ . '/img/os/' . (int)$order_pending->id . '.png'
            );
          }
          if ($order_expired->add()) {
              copy(
                  _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                  _PS_ROOT_DIR_ . '/img/os/' . (int)$order_expired->id . '.png'
              );
          }
          if ($order_confirming->add()) {
              copy(
                  _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                  _PS_ROOT_DIR_ . '/img/os/' . (int)$order_confirming->id . '.png'
              );
          }
          if ($order_invalid->add()) {
              copy(
                  _PS_ROOT_DIR_ . '/modules/jeeb/logo.png',
                  _PS_ROOT_DIR_ . '/img/os/' . (int)$order_invalid->id . '.png'
              );
          }
          Configuration::updateValue('JEEB_PENDING', $order_pending->id);
          Configuration::updateValue('JEEB_EXPIRED', $order_expired->id);
          Configuration::updateValue('JEEB_CONFIRMING', $order_confirming->id);
          Configuration::updateValue('JEEB_INVALID', $order_invalid->id);
      }
      if (!parent::install() || !$this->registerHook('invoice') || !$this->registerHook('payment') || !$this->registerHook('paymentReturn')) {
        return false;
      }

      $db = Db::getInstance();

      $query = "CREATE TABLE `"._DB_PREFIX_."order_bitcoin_jeeb` (
                `id_payment` int(11) NOT NULL AUTO_INCREMENT,
                `id_order` varchar(255) NOT NULL,
                `key` varchar(255) NOT NULL,
                `cart_id` int(11) NOT NULL,
                `token` varchar(255) NOT NULL,
                `status` varchar(255) NOT NULL,
                PRIMARY KEY (`id_payment`),
                UNIQUE KEY `token` (`token`)
                ) ENGINE="._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8';

      $db->Execute($query);
      $query = "INSERT IGNORE INTO `ps_configuration` (`name`, `value`, `date_add`, `date_upd`) VALUES ('PS_OS_JEEB', '13', NOW(), NOW());";
      $db->Execute($query);

      return true;
    }

    public function uninstall() {
      $db = Db::getInstance();

      $query = "DROP TABLE `"._DB_PREFIX_."order_bitcoin_jeeb`";

      $db->Execute($query);

        Configuration::deleteByName('jeeb_APIKEY');
        Configuration::deleteByName('jeeb_TESTMODE');
        Configuration::deleteByName('jeeb_BASECOIN');
        Configuration::deleteByName('jeeb_BTC');
        Configuration::deleteByName('jeeb_BCH');
        Configuration::deleteByName('jeeb_XMR');
        Configuration::deleteByName('jeeb_XRP');
        Configuration::deleteByName('jeeb_LTC');
        Configuration::deleteByName('jeeb_ETH');
        Configuration::deleteByName('jeeb_TESTBTC');
        Configuration::deleteByName('jeeb_LANG');

      return parent::uninstall();
    }

    public function getContent() {
      $this->_html .= '<h2>'.$this->l('jeeb').'</h2>';

      $this->_postProcess();
      // $this->_setjeebSubscription();
      $this->_setConfigurationForm();

      return $this->_html;
    }

    public function hookPayment($params) {
      global $smarty;

      $smarty->assign(array(
                            'this_path' => $this->_path,
                            'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/")
                           );

      return $this->display(__FILE__, 'payment.tpl');
    }

    private function _setConfigurationForm() {
      $this->_html .= '<form method="post" action="'.htmlentities($_SERVER['REQUEST_URI']).'">
                       <script type="text/javascript">
                       var pos_select = '.(($tab = (int)Tools::getValue('tabs')) ? $tab : '0').';
                       </script>';

      if (_PS_VERSION_ <= '1.5') {
        $this->_html .= '<script type="text/javascript" src="'._PS_BASE_URL_._PS_JS_DIR_.'tabpane.js"></script>
                         <link type="text/css" rel="stylesheet" href="'._PS_BASE_URL_._PS_CSS_DIR_.'tabpane.css" />';
      } else {
        $this->_html .= '<script type="text/javascript" src="'._PS_BASE_URL_._PS_JS_DIR_.'jquery/plugins/tabpane/jquery.tabpane.js"></script>
                         <link type="text/css" rel="stylesheet" href="'._PS_BASE_URL_._PS_JS_DIR_.'jquery/plugins/tabpane/jquery.tabpane.css" />';
      }

      $this->_html .= '<input type="hidden" name="tabs" id="tabs" value="0" />
                       <div class="tab-pane" id="tab-pane-1" style="width:100%;">
                       <div class="tab-page" id="step1">
                       <h4 class="tab">'.$this->l('Settings').'</h2>
                       '.$this->_getSettingsTabHtml().'
                       </div>
                       </div>
                       <div class="clear"></div>
                       <script type="text/javascript">
                       function loadTab(id){}
                       setupAllTabs();
                       </script>
                       </form>';
    }

    private function _getSettingsTabHtml() {
      global $cookie;

      $lowSelected    = '';
      $mediumSelected = '';
      $highSelected   = '';
      $testmode = '';


      if (Configuration::get('jeeb_TESTMODE') == "1") {
        $testmode = "checked";
      } else {
        $testmode = "";
      }

      $btc = $eur = $irr = $usd = $toman = "";
      Configuration::get('jeeb_BASECOIN') == "btc" ? $btc = "selected" : $btc = "" ;
      Configuration::get('jeeb_BASECOIN') == "eur" ? $eur = "selected" : $eur = "" ;
      Configuration::get('jeeb_BASECOIN') == "irr" ? $irr = "selected" : $irr = "" ;
      Configuration::get('jeeb_BASECOIN') == "toman" ? $toman = "selected" : $toman = "" ;
      Configuration::get('jeeb_BASECOIN') == "usd" ? $usd = "selected" : $usd = "" ;

      $target_btc = $target_eth = $target_xrp = $target_xmr = $target_bch = $target_ltc = $target_test_btc = "";
      Configuration::get("jeeb_BTC") == "btc" ? $target_btc = "checked" : $target_btc = "";
      Configuration::get("jeeb_ETH") == "eth" ? $target_eth = "checked" : $target_eth = "";
      Configuration::get("jeeb_XRP") == "xrp" ? $target_xrp = "checked" : $target_xrp = "";
      Configuration::get("jeeb_XMR") == "xmr" ? $target_xmr = "checked" : $target_xmr = "";
      Configuration::get("jeeb_BCH") == "bch" ? $target_bch = "checked" : $target_bch = "";
      Configuration::get("jeeb_LTC") == "ltc" ? $target_ltc = "checked" : $target_ltc = "";
      Configuration::get("jeeb_TESTBTC") == "test-btc" ? $target_test_btc = "checked" : $target_test_btc = "";

      $auto_select = $eng = $persian = "";
      Configuration::get("jeeb_LANG") == "none" ? $auto_select = "selected" : $auto_select = "" ;
      Configuration::get("jeeb_LANG") == "en" ? $eng = "selected" : $eng = "" ;
      Configuration::get("jeeb_LANG") == "fa" ? $persian = "selected" : $persian = "" ;


      $html = '<h2>'.$this->l('Settings').'</h2>
               <div style="clear:both;margin-bottom:30px;">
               <h3 style="clear:both;">'.$this->l('Signature').'</h3>
               <input type="text" style="width:400px;" name="apikey_jeeb" value="'.htmlentities(Tools::getValue('apikey', Configuration::get('jeeb_APIKEY')), ENT_COMPAT, 'UTF-8').'" />
               </div>
               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">'.$this->l('Test Mode').'</h3>
               <label style="width:auto;"><input type="checkbox" name="testmode_jeeb" value="1" '.$testmode.'> '.$this->l('Check to enable').'</label>
               </div>
               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">'.$this->l('Basecoin').'</h3>
               <label style="width:auto;"><select name="basecoin_jeeb"><option value="btc" '.$btc.'>BTC</option><option value="eur" '.$eur.'>EUR</option><option value="irr" '.$irr.'>IRR</option><option value="usd" '.$usd.'>USD</option><option value="toman" '.$toman.'>TOMAN</option></select> '.$this->l('Select the base-currency of your shop').'</label>
               </div>
               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">'.$this->l('Targetcoin').'</h3>
               <input type="checkbox" name="btc_jeeb" value="btc" '.$target_btc.'>BTC<br><input type="checkbox" name="eth_jeeb" value="eth" '.$target_eth.'>ETH<br><input type="checkbox" name="xrp_jeeb" value="xrp" '.$target_xrp.'>XRP<br><input type="checkbox" name="xmr_jeeb" value="xmr" '.$target_xmr.'>XMR<br><input type="checkbox" name="bch_jeeb" value="bch" '.$target_bch.'>BCH<br><input type="checkbox" name="ltc_jeeb" value="ltc" '.$target_ltc.'>LTC<br><input type="checkbox" name="testbtc_jeeb" value="test-btc" '.$target_test_btc.'>TEST-BTC<br><label style="width:auto;">'.$this->l('The target currency to which your base currency will get converted(Multi-select).').'</label>
               </div>
               <div style="clear:both;margin-bottom:30px;overflow:hidden;">
               <h3 style="clear:both;">'.$this->l('Language').'</h3>
               <select name="lang_jeeb"><option value="none" '.$auto_select.'>Auto-Select</option><option value="en" '.$eng.'>English</option><option value="fa" '.$persian.'>Persian</option></select><br><label style="width:auto;">'.$this->l('Set the language of the payment page..').'</label>
               </div>
               <p><b>IMPORTANT NOTE<b>: The minimum price of the product should be 10000 IRR.</p>
               <p class="center"><input class="button" type="submit" name="submitjeeb" value="'.$this->l('Save settings').'" /></p>';

      return $html;
    }

    private function _postProcess() {
      global $currentIndex, $cookie;

      if (Tools::isSubmit('submitjeeb')) {
        $template_available = array('A', 'B', 'C');
        $this->_errors      = array();

        if (Tools::getValue('apikey_jeeb') == NULL)
          $this->_errors[]  = $this->l('Missing API Key');

        if (count($this->_errors) > 0) {
          $error_msg = '';

          foreach ($this->_errors AS $error)
            $error_msg .= $error.'<br />';

          $this->_html = $this->displayError($error_msg);
        } else {
          Configuration::updateValue('jeeb_APIKEY', trim(Tools::getValue('apikey_jeeb')));
          Configuration::updateValue('jeeb_TESTMODE', trim(Tools::getValue('testmode_jeeb')));
          Configuration::updateValue('jeeb_BASECOIN', trim(Tools::getValue('basecoin_jeeb')));
          Configuration::updateValue('jeeb_BTC', trim(Tools::getValue('btc_jeeb')));
          Configuration::updateValue('jeeb_BCH', trim(Tools::getValue('bch_jeeb')));
          Configuration::updateValue('jeeb_XMR', trim(Tools::getValue('xmr_jeeb')));
          Configuration::updateValue('jeeb_XRP', trim(Tools::getValue('xrp_jeeb')));
          Configuration::updateValue('jeeb_LTC', trim(Tools::getValue('ltc_jeeb')));
          Configuration::updateValue('jeeb_ETH', trim(Tools::getValue('eth_jeeb')));
          Configuration::updateValue('jeeb_TESTBTC', trim(Tools::getValue('testbtc_jeeb')));
          Configuration::updateValue('jeeb_LANG', trim(Tools::getValue('lang_jeeb')));
          $this->_html = $this->displayConfirmation($this->l('Settings updated'));
        }

      }

    }


    public function execPayment($cart) {

      $total = $cart->getOrderTotal(true);

      if (_PS_VERSION_ <= '1.5')
        $callBack     = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'order-confirmation.php?id_cart='.$cart->id.'&id_module='.$this->id.'&id_order='.$this->currentOrder;
      else
        $callBack     = Context::getContext()->link->getModuleLink('jeeb', 'validation');

      $baseUri         = "https://core.jeeb.io/api/" ;
      $signature       = Configuration::get('jeeb_APIKEY'); // Signature
      $baseCur         = Configuration::get('jeeb_BASECOIN');
      $lang            = Configuration::get("jeeb_LANG") == "none" ? NULL : Configuration::get("jeeb_LANG") ; //
      $notification    = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'modules/'.$this->name.'/ipn.php';  // Notification Url
      $order_total     = $total;  // Total price in irr
      $params = array(
                      'BTC',
                      'XRP',
                      'XMR',
                      'LTC',
                      'BCH',
                      'ETH',
                      'TESTBTC'
                     );

      foreach ($params as $p) {
        Configuration::get("jeeb_".$p) != NULL ? $target_cur .= Configuration::get("jeeb_".$p) . "/" : $target_cur .="" ;
      }

      error_log("Base Uri : ".$baseUri." Signature : ".$signature." CallbackUri : ".$callBack." NotificationUri : ".$notification);
      error_log("Cost = ". $total." Lang =".$lang);

      if($baseCur=='toman'){
        $baseCur='irr';
        $order_total *= 10;
      }



      $amount = convertIrrToBtc($baseUri, $order_total, $signature, $baseCur);
      $orderNo = uniqid();
      error_log("orderNo : ".$orderNo);

      $params = array(
        'orderNo'          => $orderNo,
        'value'            => (float) $amount,
        'webhookUrl'       => $notification,
        'callBackUrl'      => $callBack,
        'allowReject'      => Configuration::get('jeeb_TESTMODE') == "1" ? false : true,
        "coins"            => $target_cur,
        "allowTestNet"     => Configuration::get("jeeb_TESTMODE") == "1" ? true : false,
        "language"         => $lang
      );

      error_log(json_encode($params));

      $token = createInvoice($baseUri, $amount, $params, $signature);

      $customerId = (int)$this->context->customer->id;

      $db = Db::getInstance();
      $result = array();
      $result = $db->ExecuteS('SELECT * FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . intval($customerId) . ';');
      error_log("Db Result = ".print_r($result[0],true));
      $key=$result[0]["secure_key"];

      $status = "Invoice Created";
      $db = Db::getInstance();
      $result = $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_bitcoin_jeeb` (`id_order`, `key`, `cart_id`, `token`, `status`) VALUES("' . $orderNo . '", "' .$key.'", '. intval($cart->id) . ', "' . $token . '", "' . $status . '") on duplicate key update `status`="'.$status.'"');



      redirectPayment($baseUri, $token);

    }

    }


?>
