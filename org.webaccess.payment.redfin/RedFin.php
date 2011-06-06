<?php
require_once 'CRM/Core/Payment.php';
class org_webaccess_payment_redfin extends CRM_Core_Payment {

    static protected $_mode = null;
    
    static protected $_params = array();
    
    static private $_singleton = null;
    
    function __construct( $mode, &$paymentProcessor ) {
        $this->_mode             = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('RedFin');
        $config =& CRM_Core_Config::singleton();
        $this->_setParam( 'paymentType', 'RedFin' );
        
        $this->_setParam( 'timestamp', time( ) );
        srand( time( ) );
        $this->_setParam( 'sequence', rand( 1, 1000 ) );
    }
    
    function checkConfig( ) 
	{
        $error = array();
        if ( empty( $this->_paymentProcessor['user_name'] ) ) {
            $error[] = ts( 'APILogin is not set for this payment processor' );
        }
        
        if ( ! empty( $error ) ) {
            return implode( '<p>', $error );
        } else {
            return null;
        }
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
            self::$_singleton[$processorName] = new org_webaccess_payment_redfin( $mode, $paymentProcessor );
        }
        return self::$_singleton[$processorName];
    }
    
    function doTransferCheckout( &$params, $component = 'contribute' ) {
    }
    
    function doDirectPayment( &$params, $component = 'contribute' )
    {
        $this->_setParam( 'card_expiry_month', $params['credit_card_exp_date']['M'] );
        $this->_setParam( 'card_expiry_year', $params['credit_card_exp_date']['Y'] );

        $url    = ( $component == 'event' ) ? 'civicrm/event/register' : 'civicrm/contribute/transact';
        $cancel = ( $component == 'event' ) ? '_qf_Register_display'   : '_qf_Main_display';
        
        $cancelURL = CRM_Utils_System::url( $url, 
                                            "$cancel=1&cancel=1&qfKey={$params['qfKey']}" );
       
        $extData ='';
        $transType = 'Sale';
        if ( $params['is_recur'] == 1 ) {
            $transType = 'RepeatSale';
            $extData = "<SequenceNum>{$params['frequency_interval']}</SequenceNum><SequenceCount>{$params['installments']}</SequenceCount>";
        }
        $redfinParams = array( 'UserName'   => $this->_paymentProcessor['user_name'],
                               'Password'   => $this->_paymentProcessor['password'],
                               'TransType'  => $transType,
                               'CardNum'    => $params['credit_card_number'],
                               'ExpDate'    => str_pad( $this->_getParam( 'card_expiry_month' ), 2, '0', STR_PAD_LEFT ).date( 'y', strtotime($this->_getParam('card_expiry_year').'-01-01')),
                               'MagData'    => '',
                               'NameOnCard' => $params['billing_first_name'],
                               'Amount'     => $params['amount'],
                               'InvNum'     => $params['invoiceID'],
                               'PNRef'      => '',
                               'Zip'        => $params['postal_code'],
                               'Street'     => $params['street_address'],
                               'CVNum'      => $params['cvv2'],
                               'ExtData'    => $extData );

        $postString = array( );
        foreach ( $redfinParams as $key => $value ) {
            $postString[] = $key . '=' . urlencode( trim( $value ) );
        }
        $postString = implode( '&', $postString );
        $result = "";
        $gatewayUrl = (string)$this->_paymentProcessor['url_site'];
        $transactionResponse = $this->sendToRedfin( $gatewayUrl, $postString );
        $approval = (int)$this->my_xml_parser( $transactionResponse, "</Result>" );
        $xml = simplexml_load_string( $transactionResponse );
        $pnref['trxn_id'] = (string)$xml->PNRef;
        $session = CRM_Core_Session::singleton( );
       
        if ( $approval == 0 ) { 
            if ( $params['is_recur'] == 1 ) {
                $params['trxn_id'] = (int)$xml->PNRef;
                self::processRecurContribution( $component = 'contribute', $params);
                $recur = new CRM_Contribute_DAO_ContributionRecur( );
                $recur->id = $params['contributionRecurID'];
                $recur->find( true ); 
                require_once 'CRM/Contribute/BAO/ContributionPage.php';
                $subscriptionPaymentStatus = 'START';
                CRM_Contribute_BAO_ContributionPage::recurringNofify( $subscriptionPaymentStatus, $params['contactID'],
                                                                      $params['contributionPageID'], $recur );
                return $pnref;
            } else {
                
                return $pnref;
            }
        } else {

            require_once 'CRM/Core/Error.php';
            $error =& CRM_Core_Error::singleton( );
            $result['l_errorcode0'] = $approval;
            $result['l_shortmessage0'] = (string)$this->my_xml_parser( $transactionResponse, "</RespMSG>" );
            $result['l_longmessage0']  = (string)$this->my_xml_parser( $transactionResponse, "</Message>" );
            $error->push( $result['l_errorcode0'],
                          0, null,
                          "{$result['l_shortmessage0']} {$result['l_longmessage0']}" );
            return $error;

        } 
    } 
    
    function _setParam( $field, $value ) {
        if ( ! is_scalar( $value ) ) {
            return false;
        } else {
            $this->_params[$field] = $value;
        }
    }
    
    /**
     * sendToRedfin function used to send the params to redfin and get the response
     */
    function sendToRedfin( $url, $parameters ) {
        $server = parse_url( $url );
        $result = null;
        if ( function_exists( 'curl_init' ) ) {
            $header = array( "MIME-Version: 1.0","Content-type: application/x-www-form-urlencoded","Contenttransfer-encoding: text" ); 
            $ch = curl_init( $url );
            curl_setopt( $ch, CURLOPT_URL, $url ); 
            curl_setopt( $ch, CURLOPT_VERBOSE, 1 ); 
            curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP ); 
            // Uncomment for host with proxy server
            // curl_setopt ($ch, CURLOPT_PROXY, "http://proxyaddress:port"); 
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $header); 
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, FALSE ); 
            curl_setopt( $ch, CURLOPT_POST, true ); 
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $parameters ); 
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true ); 
            curl_setopt( $ch, CURLOPT_TIMEOUT, 10 ); 
            $result = curl_exec( $ch );
            curl_close( $ch );
        } 
        return $result;
    }
    /**
     * my_xml_parser fuction to parse xml response
     */
    function my_xml_parser( $haystack, $needle ) {
        if ( ( $end = strpos( $haystack, $needle ) ) === FALSE )
            return("");         
        for($x = $end; $x > 0; $x--) {
            if ($haystack{$x} == ">")
                return ( trim( substr( $haystack, $x + 1, $end - $x - 1 ) ) );
        }
        return ("");
    }

    function _getParam( $field ) {
        return CRM_Utils_Array::value( $field, $this->_params, '' );
    }

    /**
     * processRecurContribution function to make the transaction entry and financial transaction entry
     */
    function processRecurContribution( $component = 'contribute', $params ) 
    {
        require_once 'CRM/Contribute/PseudoConstant.php';
        $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( );
        $completed['contribution_status_id'] = array_search( 'Completed', $contributionStatus );

        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_Contribution', $params['contributionID'], 'trxn_id', $params['trxn_id'] );
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_Contribution', $params['contributionID'], 'contribution_status_id', $completed );
        
        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur', $params['contributionRecurID'], 
                                     'trxn_id', $params['trxn_id'] );
        $trxnParams = array(
                            'contribution_id'   => $params['contributionID'],
                            'trxn_date'         => date( 'YmdHis' ),
                            'trxn_type'         => 'Debit',
                            'total_amount'      => $params['amount'],
                            'fee_amount'        => CRM_Utils_Array::value( 'fee_amount', $params['trxn_id']  ),
                            'net_amount'        => CRM_Utils_Array::value( 'net_amount', $params['trxn_id'], $params['amount'] ),
                            'currency'          => $params['currencyID'],
                            'payment_processor' => $this->_paymentProcessor['name'],
                            'trxn_id'           => $params['trxn_id'],
                            'trxn_result_code'  => NULL,
                            );
        
        require_once 'CRM/Core/BAO/FinancialTrxn.php';
        $trxn =& CRM_Core_BAO_FinancialTrxn::create( $trxnParams );
    }

}