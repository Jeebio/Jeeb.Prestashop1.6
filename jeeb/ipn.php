<?php

include dirname(__FILE__) . '/../../config/config.inc.php';
include dirname(__FILE__) . '/../../header.php';
include dirname(__FILE__) . '/jeeb.php';

$jeeb = new jeeb();
$postdata = file_get_contents("php://input");
$json = json_decode($postdata, true);

$jeeb->notify_log($postdata);

if ( $_GET['hashKey'] === md5(Configuration::get($jeeb->_fieldName('apiKey')) . $json['orderNo']) ) {
    $jeeb->notify_log('HashKey:' . $_GET['hashKey'] . ' is valid');

    $orderId = (int) $json['orderNo'];

    $jeeb->notify_log($json['state']);

    switch ($json['state']) {
        case 'PendingTransaction':
            $jeeb->changeOrderStatus($orderId, Configuration::get('JEEB_PENDING_TRANSACTION') );
            $jeeb->notify_log("status changed to JEEB_PENDING_TRANSACTION");

            break;
        case 'PendingConfirmation':
            $jeeb->changeOrderStatus($orderId, Configuration::get('JEEB_PENDING_CONFIRMATION') );

            if ($json['refund'] == true) {
                $jeeb->addNoteToOrder($orderId, 'Jeeb: Payment will be refunded.');
            }

            break;

        case 'Completed':
            $api_key = Configuration::get('jeeb_apiKey');
            $is_confirmed = $jeeb->confirm_payment($api_key, $json["token"]);

            $jeeb->notify_log($is_confirmed);
    
            if ($is_confirmed) {
                $jeeb->changeOrderStatus($orderId, Configuration::get('JEEB_COMPLETED'));
            } else {
                $jeeb->changeOrderStatus($orderId, Configuration::get('PS_OS_ERROR'));
                $jeeb->addNoteToOrder($orderId, 'Jeeb: Double spending avoided.'); 
            }
            break;

        case 'Rejected':
            $jeeb->changeOrderStatus($orderId, Configuration::get('JEEB_REFUNDED') );
            break;
        
        case 'Expired':
            $jeeb->changeOrderStatus($orderId, Configuration::get('JEEB_EXPIRED') );

            break;

        default:
            error_log('Cannot read state sent by Jeeb');

            break;
    }
    

    header("HTTP/1.1 200 OK");
} else {
    header("HTTP/1.0 404 Not Found");
}
