<?php
session_start( );

require_once '../civicrm.config.php';
require_once 'CRM/Core/Config.php';
require_once 'CRM/Core/Payment/MonerisHppIPN.php';

$config = CRM_Core_Config::singleton();
$component = CRM_Utils_Array::value( 'rvar3', $_GET );
$user_name = $_GET['rvar10'];

//print_r($_GET); exit();

$query = "
SELECT  url_site, password, user_name, signature 
FROM    civicrm_payment_processor 
WHERE   payment_processor_type = 'MonerisHPP' 
AND     user_name = %1
";
$params = array( 1 => array( $user_name, 'String' ) );

$paymentProcessor =& CRM_Core_DAO::executeQuery( $query, $params );
while ( $paymentProcessor->fetch( ) ) {
    $site_url = $paymentProcessor->url_site;
    $ps_store_id = $paymentProcessor->user_name;
    $hpp_key = $paymentProcessor->password;
}

CRM_Core_Payment_MonerisHppIPN::main( $component, $site_url, $ps_store_id, $hpp_key);