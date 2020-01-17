<?php

include dirname(__FILE__) . '/../../config/config.inc.php';
include dirname(__FILE__) . '/../../header.php';
include dirname(__FILE__) . '/jeeb.php';

$jeeb = new jeeb();
$postdata = file_get_contents("php://input");
$json = json_decode($postdata, true);
// fclose($handle);
if ($json['signature'] == Configuration::get('jeeb_signature') && $json['orderNo']) {
    $db = Db::getInstance();
    $orderId = (int) $json['orderNo'];
    $order = new Order($orderId);

    if ($json['stateId'] == 2) {

    } else if ($json['stateId'] == 3) {
        $order_status = Configuration::get('JEEB_PENDING_CONFIRMATION');

        $db->Execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET current_state = ' . $order_status . ' WHERE `id_order`=' . $orderId . ';');

        $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_history` (`id_order`, `id_order_state`, `date_add`) VALUES (' . $orderId . ', ' . $order_status . ', now());');

    } else if ($json['stateId'] == 4) {
        $signature = Configuration::get('jeeb_signature');
        $is_confirmed = $jeeb->confirm_payment($signature, $json["token"]);

        if ($is_confirmed) {
            $order_status = Configuration::get('JEEB_COMPLETED');

            $db->Execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET current_state = ' . $order_status . ' WHERE `id_order`=' . $orderId . ';');

            $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_history` (`id_order`, `id_order_state`, `date_add`) VALUES (' . $orderId . ', ' . $order_status . ', now());');

        } else {
            $order_status = Configuration::get('PS_OS_ERROR');

            $db->Execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET current_state = ' . $order_status . ' WHERE `id_order`=' . $orderId . ';');

            $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_history` (`id_order`, `id_order_state`, `date_add`) VALUES (' . $orderId . ', ' . $order_status . ', now());');

        }
    } else if ($json['stateId'] == 5) {
        $order_status = Configuration::get('JEEB_EXPIRED');

        $db->Execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET current_state = ' . $order_status . ' WHERE `id_order`=' . $orderId . ';');

        $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_history` (`id_order`, `id_order_state`, `date_add`) VALUES (' . $orderId . ', ' . $order_status . ', now());');

    } else if ($json['stateId'] == 6 || $json['stateId'] == 7) {
        $order_status = Configuration::get('JEEB_REFUNDED');

        $db->Execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET current_state = ' . $order_status . ' WHERE `id_order`=' . $orderId . ';');

        $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_history` (`id_order`, `id_order_state`, `date_add`) VALUES (' . $orderId . ', ' . $order_status . ', now());');

    } else {
        error_log('Cannot read state id sent by Jeeb');
    }
    header("HTTP/1.1 200 OK");
} else {
    header("HTTP/1.0 404 Not Found");
}
