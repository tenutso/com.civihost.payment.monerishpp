<?php

require_once 'CRM/Core/Payment/BaseIPN.php';

class CRM_Core_Payment_MonerisHppIPN extends CRM_Core_Payment_BaseIPN {

    /**
     * We only need one instance of this object. So we use the singleton
     * pattern and cache the instance in this variable
     *
     * @var object
     * @static
     */
    static private $_singleton = null;
    private $_paymentProcessor;

    /**
     * mode of operation: live or test
     *
     * @var object
     * @static
     */
    static protected $_mode = null;

    static function retrieve($name, $type, $object, $abort = true) {
        $value = CRM_Utils_Array::value($name, $object);
        if ($abort && $value === null) {
            CRM_Core_Error::debug_log_message("Could not find an entry for $name");
            echo "Failure: Missing Parameter - " . $name . "<p>";
            exit();
        }

        if ($value) {
            if (!CRM_Utils_Type::validate($value, $type)) {
                CRM_Core_Error::debug_log_message("Could not find a valid entry for $name");
                echo "Failure: Invalid Parameter<p>";
                exit();
            }
        }

        return $value;
    }

    /**
     * Constructor 
     * 
     * @param string $mode the mode of operation: live or test
     *
     * @return void 
     */
    function __construct($mode, &$paymentProcessor) {
        parent::__construct();

        $this->_mode = $mode;
        $this->_paymentProcessor = $paymentProcessor;
    }

    /**
     * singleton function used to manage this object  
     *  
     * @param string $mode the mode of operation: live or test
     *  
     * @return object  
     * @static  
     */
    static function &singleton($mode, $component, &$paymentProcessor) {
        if (self::$_singleton === null) {
            self::$_singleton = new CRM_Core_Payment_MonerisHppIPN($mode, $paymentProcessor);
        }
        return self::$_singleton;
    }

    /**
     * The function gets called when a new order takes place.
     *  
     * @param xml   $dataRoot    response send by google in xml format
     * @param array $privateData contains the name value pair of <merchant-private-data>
     *  
     * @return void  
     *  
     */
    function newOrderNotify($success, $privateData, $component, $amount, $transactionReference) {
        $ids = $input = $params = array();

        $input['component'] = strtolower($component);

        $ids['contact'] = self::retrieve('contactID', 'Integer', $privateData, true);
        $ids['contribution'] = self::retrieve('contributionID', 'Integer', $privateData, true);

        if ($input['component'] == "event") {
            $ids['event'] = self::retrieve('eventID', 'Integer', $privateData, true);
            $ids['participant'] = self::retrieve('participantID', 'Integer', $privateData, true);
            $ids['membership'] = null;
        } else {
            $ids['membership'] = self::retrieve('membershipID', 'Integer', $privateData, false);
        }
        $ids['contributionRecur'] = $ids['contributionPage'] = null;

        if (!$this->validateData($input, $ids, $objects)) {
            return false;
        }

        // make sure the invoice is valid and matches what we have in the contribution record
        $input['invoice'] = $privateData['invoiceID'];
        $input['newInvoice'] = $transactionReference;
        $contribution = & $objects['contribution'];
        $input['trxn_id'] = $transactionReference;

        if ($contribution->invoice_id != $input['invoice']) {
            CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
            echo "Failure: Invoice values dont match between database and IPN request<p>";
            return;
        }

        // lets replace invoice-id with Payment Processor -number because thats what is common and unique 
        // in subsequent calls or notifications sent by google.
        $contribution->invoice_id = $input['newInvoice'];

        $input['amount'] = $amount;

        if ($contribution->total_amount != $input['amount']) {
            CRM_Core_Error::debug_log_message("Amount values dont match between database and IPN request");
            echo "Failure: Amount values dont match between database and IPN request. " . $contribution->total_amount . "/" . $input['amount'] . "<p>";
            return;
        }

        require_once 'CRM/Core/Transaction.php';
        $transaction = new CRM_Core_Transaction( );

        // fix for CRM-2842
        // if ( ! $this->createContact( $input, $ids, $objects ) ) {
        //     return false;
        // }
        // check if contribution is already completed, if so we ignore this ipn

        if ($contribution->contribution_status_id == 1) {
            CRM_Core_Error::debug_log_message("returning since contribution has already been handled");
            echo "Success: Contribution has already been handled<p>";
            return true;
        } else {
            /* Since trxn_id hasn't got any use here, 
             * lets make use of it by passing the eventID/membershipTypeID to next level.
             * And change trxn_id to the payment processor reference before finishing db update */
            if (isset($ids['event'])) {
                $contribution->trxn_id =
                        $ids['event'] . CRM_Core_DAO::VALUE_SEPARATOR .
                        $ids['participant'];
            } else {
                $contribution->trxn_id = $ids['membership'];
            }
        }
        $this->completeTransaction($input, $ids, $objects, $transaction);
        return true;
    }

    /**
     * The function returns the component(Event/Contribute..)and whether it is Test or not
     *  
     * @param array   $privateData    contains the name-value pairs of transaction related data
     * @param int     $orderNo        <order-total> send by google
     *  
     * @return array context of this call (test, component, payment processor id)
     * @static  
     */
    static function getContext($privateData, $orderNo) {
        require_once 'CRM/Contribute/DAO/Contribution.php';

        $component = null;
        $isTest = null;

        $contributionID = $privateData['contributionID'];
        $contribution = new CRM_Contribute_DAO_Contribution( );
        $contribution->id = $contributionID;

        if (!$contribution->find(true)) {
            CRM_Core_Error::debug_log_message("Could not find contribution record: $contributionID");
            echo "Failure: Could not find contribution record for $contributionID<p>";
            exit();
        }

        if (stristr($contribution->source, 'Online Contribution')) {
            $component = 'contribute';
        } elseif (stristr($contribution->source, 'Online Event Registration')) {
            $component = 'event';
        }
        $isTest = $contribution->is_test;

        $duplicateTransaction = 0;
        if ($contribution->contribution_status_id == 1) {
            //contribution already handled. (some processors do two notifications so this could be valid)
            $duplicateTransaction = 1;
        }

        if ($component == 'contribute') {
            if (!$contribution->contribution_page_id) {
                CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: $contributionID");
                echo "Failure: Could not find contribution page for contribution record: $contributionID<p>";
                exit();
            }

            // get the payment processor id from contribution page
            $paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage', $contribution->contribution_page_id, 'payment_processor_id');
        } else {

            $eventID = $privateData['eventID'];

            if (!$eventID) {
                CRM_Core_Error::debug_log_message("Could not find event ID");
                echo "Failure: Could not find eventID<p>";
                exit();
            }

            // we are in event mode
            // make sure event exists and is valid
            require_once 'CRM/Event/DAO/Event.php';
            $event = new CRM_Event_DAO_Event( );
            $event->id = $eventID;
            if (!$event->find(true)) {
                CRM_Core_Error::debug_log_message("Could not find event: $eventID");
                echo "Failure: Could not find event: $eventID<p>";
                exit();
            }

            // get the payment processor id from contribution page
            $paymentProcessorID = $event->payment_processor_id;
        }

        if (!$paymentProcessorID) {
            CRM_Core_Error::debug_log_message("Could not find payment processor for contribution record: $contributionID");
            echo "Failure: Could not find payment processor for contribution record: $contributionID<p>";
            exit();
        }

        return array($isTest, $component, $paymentProcessorID, $duplicateTransaction);
    }

    function verifyTransaction($site_url, $ps_store_id, $hpp_key, $transactionKey, $mode) {

        $query = "ps_store_id=$ps_store_id&hpp_key=$hpp_key&transactionKey=$transactionKey";


        if ($mode == 'test') {
            $url = 'https://esqa.moneris.com/HPPDP/verifyTxn.php';
        } else {
            $url = 'https://www3.moneris.com/HPPDP/verifyTxn.php';
        }

        //for testing purposes
        if ($ps_store_id == 'FCVV4tore1') {
            $url = 'https://esqa.moneris.com/HPPDP/verifyTxn.php';
        }


        $curl = curl_init();

        //print_r($query); exit();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
        //curl_setopt($curl, CURLOPT_, 0);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_SSLVERSION, 3);

        if (strtoupper(substr(@php_uname('s'), 0, 3)) === 'WIN') {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($curl);
        curl_close($curl);
        //echo $response;		exit();
        $oXML = new SimpleXMLElement(trim($response));
        //print_r($oXML);		exit();	
        if ($transactionKey == $oXML->transactionKey && $oXML->response_code < 50) {
            return true;
        }

        return false;
    }

    /**
     * This method is handles the response that will be invoked by the
     * notification or request sent by the payment processor.
     * hex string from paymentexpress is passed to this function as hex string. Code based on googleIPN
     * mac_key is only passed if the processor is pxaccess as it is used for decryption
     * $dps_method is either pxaccess or pxpay
     */
    static function main($component, $site_url, $ps_store_id, $hpp_key) {
        //echo "<pre>"; print_r(self::$_paymentProcessor); echo "</pre>"; exit();
        $amount = $_GET['charge_total'];
        $success = $_GET['response_code'] < 50 ? true : false;
        $transactionReference = $_GET['bank_approval_code'] . "-" . $_GET['bank_transaction_id'];
        $qfKey = $_GET['rvar1'];
        $privateData['invoiceID'] = $_GET['rvar2'];
        $privateData['contactID'] = $_GET['rvar4'];
        $privateData['contributionID'] = $_GET['rvar5'];
        $privateData['contributionTypeID'] = $_GET['rvar6'];
        //print_r($_GET); exit();

        if ($component == 'event') {
            $privateData['eventID'] = $_GET['rvar7'];
            $privateData['participantID'] = $_GET['rvar8'];
        }
        if ($component == 'contribute' AND isset($_GET['rvar9'])) { // rvar9 = membershipID
            $privateData['membershipID'] = $_GET['rvar9'];
        }

        list( $mode, $component, $paymentProcessorID, $duplicateTransaction) = self::getContext($privateData, $transactionReference);
        $mode = $mode ? 'test' : 'live';

        //

        $verified = self::verifyTransaction($site_url, $ps_store_id, $hpp_key, $_GET['transactionKey'], $mode);

        require_once 'CRM/Core/BAO/PaymentProcessor.php';
        $paymentProcessor = CRM_Core_BAO_PaymentProcessor::getPayment($paymentProcessorID, $mode);

        $ipn = & self::singleton($mode, $component, $paymentProcessor);



        if ($success == 1 && $verified) {
            if ($duplicateTransaction == 0) {
                $ipn->newOrderNotify($success, $privateData, $component, $amount, $transactionReference);
            }

            if ($component == "event") {

                $finalURL = CRM_Utils_System::url('civicrm/event/register', "_qf_ThankYou_display=1&qfKey=$qfKey", false, null, false);
                //print_r($finalURL); exit();
            } elseif ($component == "contribute") {
                $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_ThankYou_display=1&qfKey=$qfKey", false, null, false);
            }

            CRM_Utils_System::redirect($finalURL);
        } else {

            if ($component == "event") {
                $finalURL = CRM_Utils_System::url('civicrm/event/confirm', "reset=1&cc=fail&participantId={$privateData['participantID']}", false, null, false);
            } elseif ($component == "contribute") {
                $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_Main_display=1&cancel=1&qfKey=$qfKey", false, null, false);
            }

            CRM_Utils_System::redirect($finalURL);
        }
    }

    /**
     * Converts the comma separated name-value pairs in <TxnData2> 
     * to an array of values.
     */
    static function stringToArray($str) {
        $vars = $labels = array();
        $labels = explode(',', $str);
        foreach ($labels as $label) {
            $terms = explode('=', $label);
            $vars[$terms[0]] = $terms[1];
        }
        return $vars;
    }

}
