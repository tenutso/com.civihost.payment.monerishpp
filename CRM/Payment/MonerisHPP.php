<?php 

require_once 'CRM/Core/Payment.php';
require_once 'CRM/Core/Payment/BaseIPN.php';

class CRM_Core_Payment_MonerisHPP extends CRM_Core_Payment { 
    /**
     * mode of operation: live or test
     *
     * @var object
     * @static
     */
    static protected $_mode = null;

    /**
     * We only need one instance of this object. So we use the singleton
     * pattern and cache the instance in this variable
     *
     * @var object
     * @static
     */
    static private $_singleton = null;

    /** 
     * Constructor 
     *
     * @param string $mode the mode of operation: live or test
     * 
     * @return void 
     */ 
    function __construct( $mode, &$paymentProcessor ) {
        $this->_mode             = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('Moneris Hosted Paypage');
    }

    /** 
     * singleton function used to manage this object 
     * 
     * @param string $mode the mode of operation: live or test
     *
     * @return object 
     * @static 
     * 
     */ 
    static function &singleton( $mode, &$paymentProcessor ) {
        $processorName = $paymentProcessor['name'];
        if (self::$_singleton[$processorName] === null ) {
            self::$_singleton[$processorName] = new CRM_Core_Payment_MonerisHPP( $mode, $paymentProcessor );
        }
        return self::$_singleton[$processorName];
    }

    /** 
     * This function checks to see if we have the right config values 
     * 
     * @return string the error message if any 
     * @public 
     */ 
    function checkConfig( ) {
        $config = CRM_Core_Config::singleton( );

        $error = array( );

        if ( empty( $this->_paymentProcessor['user_name'] ) ) {
            $error[] = ts( 'User Name is not set in the Administer CiviCRM &raquo; Payment Processor.' );
        }
        
        if ( empty( $this->_paymentProcessor['password'] ) ) {
            $error[] = ts( 'Password is not set in the Administer CiviCRM &raquo; Payment Processor.' );
        }
        
        if ( ! empty( $error ) ) {
            return implode( '<p>', $error );
        } else {
            return null;
        }
    }

    function doDirectPayment( &$params ) {
        CRM_Core_Error::fatal( ts( 'This function is not implemented' ) );
    }
	
	
	
	

    /**  
     * Sets appropriate parameters for checking out to google
     *  
     * @param array $params  name value pair of contribution datat
     *  
     * @return void  
     * @access public 
     *  
     */  
    function doTransferCheckout( &$params, $component ) {
    	
		$config = CRM_Core_Config::singleton( );

        if ( $component != 'contribute' && $component != 'event' ) {
            CRM_Core_Error::fatal( ts( 'Component is invalid' ) );
        }
    	//echo "<pre>"; print_r($params); echo "</pre>"; exit();
    	//echo "<pre>"; print_r($this->_paymentProcessor); echo "</pre>"; exit();
        
        
		
		$hppURL = $this->_paymentProcessor['url_site'];
		$ps_store_id = $this->_paymentProcessor['user_name'];
		$hpp_key = $this->_paymentProcessor['password'];
		$hpp_params="?ps_store_id=$ps_store_id&hpp_key=$hpp_key";
		
		$hpp_additional = array(
			'id1' => $params['accountingCode'],
                        'description1' => substr($params['description'],0,49),
			'price1' => number_format($params['amount'], 2),
			'quantity1' => 1,
			'subtotal1' => number_format($params['amount'], 2),
			
			//5504efac22845681fcbf721b79d3e176_7030
			'charge_total' => number_format($params['amount'], 2), // hpp requires decimals
			//'bill_first_name' => $params['first_name'],
			//'bill_last_name' => $params['last_name'],
			'rvar1' => $params['qfKey'],
			'rvar2' => $params['invoiceID'],
			'rvar3' => $component,
			'rvar4' => $params['contactID'],
			'rvar5' => $params['contributionID'],
			'rvar6' => $params['contributionTypeID']
			
		);
                
                if (isset($params['first_name'])) {
                   $hpp_additional['bill_first_name'] = $params['first_name'];
                }
                if (isset($params['last_name'])) {
                   $hpp_additional['bill_last_name'] = $params['last_name'];
                }
                if (isset($params['email-5'])) {
                   $hpp_additional['email'] = $params['email-5'];
                }
                
		
		if ($component == 'event') {
			$hpp_additional['rvar7'] = $params['eventID'];
			$hpp_additional['rvar8'] = $params['participantID'];			
		}
		if ($component == 'contribute' AND isset($params['membershipID'])) {
			$hpp_additional['rvar9'] = $params['membershipID'];
		}
		
		$hpp_additional['rvar10'] = $ps_store_id;	
		
		
		if ( $params['is_recur'] ) {
				$hpp_additional['doRecur'] = 1;
				$hpp_additional['recurUnit'] = $params['frequency_unit'];
				$hpp_additional['recurPeriod'] = $params['frequency_interval'];
				$today = new DateTime(date("Y/m/d", time));
				$hpp_additional['recurStartDate'] = "2012/05/04";
				$hpp_additional['recurStartNow'] = true;
				$hpp_additional['recurNum'] = $params['installments'];
				$hpp_additional['recurAmount'] = number_format($params['amount'], 2);
				$hppURL = $this->_paymentProcessor['url_recur'];
			}
		
		
		foreach ($hpp_additional as $key => $val) {
				$val = urlencode($val);
			if ( $key == 'rvar1') {
				
                $val = str_replace( '%2F', '/', $val );
            }
			
			$hpp_params .= "&$key=$val";
		} 
		
		
		$monerisURL = $hppURL . $hpp_params;
		//echo "<pre>"; print_r($monerisURL); echo "</pre>"; exit();
		CRM_Utils_System::redirect( $monerisURL );
    }
    
    
    function doRecurCheckout( &$params, $component ) {
        $intervalUnit   = CRM_Utils_Array::value( 'frequency_unit', $params );
        if ( $intervalUnit == 'week' ) {
            $intervalUnit = 'WEEKLY';
        } else if ( $intervalUnit == 'year' ) {
            $intervalUnit = 'YEARLY';
        } else if ( $intervalUnit == 'day' ) {
            $intervalUnit = 'DAILY';
        } else if ( $intervalUnit == 'month' ) {
            $intervalUnit = 'MONTHLY';
        }

        $merchant_id  = $this->_paymentProcessor['user_name'];   // Merchant ID
        $merchant_key = $this->_paymentProcessor['password'];    // Merchant Key
        $server_type  = ( $this->_mode == 'test' ) ? 'sandbox' : '';

        $itemName     = CRM_Utils_Array::value( 'item_name', $params );
        $description  = CRM_Utils_Array::value( 'description', $params );
        $amount       = CRM_Utils_Array::value( 'amount', $params );
        $installments = CRM_Utils_Array::value( 'installments', $params );
                        
        $cart = new GoogleCart($merchant_id, $merchant_key, $server_type, $params['currencyID']); 
        $item = new GoogleItem($itemName, $description, 1, $amount);
        $subscription_item = new GoogleSubscription("merchant", $intervalUnit, $amount, $installments);
                
        $item->SetSubscription($subscription_item);
        $cart->AddItem($item);

        $this->submitPostParams( $params, $component, $cart ); 

    }

    

    function &error( $errorCode = null, $errorMessage = null ) {
        $e =& CRM_Core_Error::singleton( );
        if ( $errorCode ) {
            $e->push( $errorCode, 0, null, $errorMessage );
        } else {
            $e->push( 9001, 0, null, 'Unknown System Error.' );
        }
        return $e;
    }

    /**
     * Set a field to the specified value.  Value must be a scalar (int,
     * float, string, or boolean)
     *
     * @param string $field
     * @param mixed $value
     * @return bool false if value is not a scalar, true if successful
     */ 
    function _setParam( $field, $value ) {
        if ( ! is_scalar($value) ) {
            return false;
        } else {
            $this->_params[$field] = $value;
        }
    }

    /**
     * Get the value of a field if set
     *
     * @param string $field the field
     * @return mixed value of the field, or empty string if the field is
     * not set
     */
    function _getParam( $field ) {
        return CRM_Utils_Array::value( $field, $this->_params, '' );
    }

    function cancelSubscriptionURL( $entityID = null, $entity = null ) 
    {
        if ( $entityID && $entity == 'membership' ) {
            require_once 'CRM/Contact/BAO/Contact/Utils.php';
            $contactID = CRM_Core_DAO::getFieldValue( "CRM_Member_DAO_Membership", $entityID, "contact_id" );
            $checksumValue = CRM_Contact_BAO_Contact_Utils::generateChecksum( $contactID, null, 'inf' );

            return CRM_Utils_System::url( 'civicrm/contribute/unsubscribe', 
                                          "reset=1&mid={$entityID}&cs={$checksumValue}", true, null, false, false );
        }

        return ( $this->_mode == 'test' ) ?
            'https://sandbox.google.com/checkout/' : 'https://checkout.google.com/';
    }

    function cancelSubscription( ) 
    {
        $orderNo = $this->_getParam( 'subscriptionId' );

        $merchant_id  = $this->_paymentProcessor['user_name'];
        $merchant_key = $this->_paymentProcessor['password'];
        $server_type  = ( $this->_mode == 'test' ) ? 'sandbox' : '';
        
        $googleRequest = new GoogleRequest( $merchant_id, $merchant_key, $server_type );
        $result = $googleRequest->SendCancelItems($orderNo, array(), 'Cancelled by admin', '');

        if ( $result[0] != 200 ) {
            return self::error($result[0], $result[1]);
        }
        return true;
    }
}
