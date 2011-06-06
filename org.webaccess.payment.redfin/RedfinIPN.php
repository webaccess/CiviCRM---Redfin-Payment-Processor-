<?php

class CRM_RedfinRecurContribution {
    
    function __construct() 
    {
        // you can run this program either from an apache command, or from the cli
        if ( php_sapi_name( ) == "cli" ) {
            require_once ("cli.php");
            $cli = new civicrm_cli ( );
            //if it doesn't die, it's authenticated
        } else { 
            //from the webserver
            $this->initialize( );
          
            $config = CRM_Core_Config::singleton();
           
            // this does not return on failure
            CRM_Utils_System::authenticateScript( true );
            
            //log the execution time of script
            CRM_Core_Error::debug_log_message( 'RedfinIPN.php' );
            
            // load bootstrap to call hooks
            require_once 'CRM/Utils/System.php';
            CRM_Utils_System::loadBootStrap(  );
        }
    }

    function initialize( ) 
    {
        require_once '../civicrm.config.php';
        require_once 'CRM/Core/Config.php';

        $config = CRM_Core_Config::singleton();
    }

    function redfinContribution( )
    {
        require_once 'CRM/Core/BAO/PaymentProcessor.php';
        require_once 'CRM/Utils/System.php';
        require_once 'CRM/Contribute/BAO/Contribution.php';
        require_once 'CRM/Core/BAO/MessageTemplates.php';
        require_once 'CRM/Core/BAO/UFMatch.php';
        require_once 'api/v2/Contact.php';
        require_once 'CRM/Core/Extensions.php';
        require_once 'CRM/Core/DAO.php';
        require_once 'CRM/Core/BAO/FinancialTrxn.php';
        require_once 'CRM/Contribute/BAO/ContributionPage.php';
        require_once 'CRM/Core/OptionGroup.php';  
        require_once 'CRM/Contribute/PseudoConstant.php';
        require_once 'CRM/Utils/Cache.php';
        
        $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( );
        $paymentProcessorClass = 'org.webaccess.payment.redfin';
        $extension = new CRM_Core_Extensions();
        if ( $extension->isExtensionKey( $paymentProcessorClass ) ) {
            $paymentClass = $extension->keyToClass( $paymentProcessorClass, 'org_webaccess_payment' );
            require_once( $extension->classToPath( $paymentClass ) );
        } else {                
            $paymentClass = "CRM_Core_" . $paymentProcessorClass;
            require_once( str_replace( '_', DIRECTORY_SEPARATOR , $paymentClass ) . '.php' );
        }
        
        $isTest = trim( CRM_Utils_Array::value( 'is_test', $_REQUEST ) );
        if ( !$isTest ) {
            $isTest = 0;
        }
        $processor_info = array( 'class_name' => $paymentProcessorClass,
                                 'is_test'    => $isTest );
        CRM_Core_BAO_PaymentProcessor::retrieve( $processor_info, $defaults );
        
        $today = date( 'Y-m-d' );
        $recurContribution = "
    SELECT  recur.id, recur.frequency_unit, recur.frequency_interval, 
            recur.installments, recur.start_date, recur.trxn_id, 
            count( contri.contribution_recur_id ) as count_id
      FROM  civicrm_contribution contri 
INNER JOIN  civicrm_contribution_recur recur ON ( recur.id = contri.contribution_recur_id 
       AND  recur.contribution_status_id = 2 
       AND  recur.is_test = %1 
       AND  recur.payment_processor_id = %2 ) 
  GROUP BY  contri.contribution_recur_id";
        
        $queryParams = array( 1 => array( $isTest, 'Integer' ),
                              2 => array( $defaults['id'], 'Integer' ) );

        $recurResult = CRM_Core_DAO::executeQuery( $recurContribution, $queryParams );
        while ( $recurResult->fetch( ) ) {
            $count = "+" . $recurResult->frequency_interval * $recurResult->count_id . " " . $recurResult->frequency_unit;
            $now = date( 'Y-m-d', strtotime( $count, strtotime( $recurResult->start_date ) ) );
            if ( $today === $now ) {
                $details = array( );
                $address = array( );
                $params = self::getParams( $recurResult->trxn_id );

                $extData = "<SequenceNum>". ++ $recurResult->count_id . "</SequenceNum><SequenceCount>{$recurResult->installments}</SequenceCount>";
                $redfinParams = array( 'UserName'   => $defaults['user_name'],
                                       'Password'   => $defaults['password'],
                                       'TransType'  => 'RepeatSale',
                                       'CardNum'    => '',
                                       'ExpDate'    => '',
                                       'MagData'    => '',
                                       'NameOnCard' => '',
                                       'Amount'     => '',
                                       'InvNum'     => '',
                                       'PNRef'      => $recurResult->trxn_id,
                                       'Zip'        => '',
                                       'Street'     => '',
                                       'CVNum'      => '',
                                       'ExtData'    => $extData 
                                       );
                
                $postString = array( );
                foreach ( $redfinParams as $key => $value ) {
                    $postString[] = $key . '=' . urlencode( trim( $value ));
                }
                
                $postString = implode( '&', $postString );
                $result = "";
                $gatewayUrl = (string)$defaults['url_site'];
                $transactionResponse = self::sendToRedfin( $gatewayUrl, $postString );
                $approval = self::my_xml_parser( $transactionResponse, "</Result>" );
                $xml = simplexml_load_string( $transactionResponse );
                $pnref['trxn_id'] = (int)$xml->PNRef;
                $session = CRM_Core_Session::singleton( );
                $params['receive_date'] = date( 'Y-m-d H:m:s' ); 
                $params['trxn_id'] = (int)$xml->PNRef;
                $null['null'] = NULL;
                if ( $approval == 0 ) {
                    $params['contribution_status_id'] = 1;
                    $contribution = CRM_Contribute_BAO_Contribution::create( $params, $null );
                    $trxnParams = array(
                                        'contribution_id'   => $contribution->id,
                                        'trxn_date'         => $params['receive_date'],
                                        'trxn_type'         => 'Debit',
                                        'total_amount'      => $contribution->total_amount,
                                        'fee_amount'        => CRM_Utils_Array::value( 'fee_amount', $params['trxn_id']  ),
                                        'net_amount'        => CRM_Utils_Array::value( 'net_amount', $params['trxn_id'], $params['amount'] ),
                                        'currency'          => $contribution->currency,
                                        'payment_processor' => $defaults['name'],
                                        'trxn_id'           => $params['trxn_id'],
                                        'trxn_result_code'  => NULL,
                                        );
                    $trxn =& CRM_Core_BAO_FinancialTrxn::create( $trxnParams );
                    
                    CRM_Core_DAO::commonRetrieveAll( 'CRM_Contribute_DAO_ContributionPage', 'id', $params['contribution_page_id'], $getInfo, NULL );
                    foreach ( $getInfo as $Key => $values ) {
                        unset( $values['recur_frequency_unit'] );
                    }
                    CRM_Core_OptionGroup::getAssoc( "civicrm_contribution_page.amount.{$params['contribution_page_id']}", $values['amount'], true );
                    $values['contribution_id'] = $contribution->id;
                    $values['custom_post_id']  = 1;
                    $values['custom_pre_id']   = NULL;
                    $values['accountingCode']  = NULL; 
                    $values['footer_text']     = NULL;
                    $values['membership_id']   = NULL;
                    
                    $billingName = CRM_Core_DAO::getFieldValue( 'CRM_Core_DAO_Address', $params['address_id'], 'name' );
                    $billingName = str_replace( CRM_Core_DAO::VALUE_SEPARATOR, ' ', $billingName );
                    CRM_Core_DAO::commonRetrieveAll( 'CRM_Core_DAO_Address', 'id', $params['address_id'], $address, NULL );
                    
                    if ( ! empty( $address[$params['address_id']]['state_province_id'] ) ) {
                        $state_province = CRM_Core_PseudoConstant::stateProvinceAbbreviation( $address[$params['address_id']]['state_province_id'], false );
                    }
                    
                    if ( ! empty( $address[$params['address_id']]['country_id'] ) ) {
                        $country = CRM_Core_PseudoConstant::country( $address[$params['address_id']]['country_id'] );
                    }
                    
                    $billingAddress = $address[$params['address_id']]['street_address']."\n".$address[$params['address_id']]['city'].", ".$state_province." ".$address[$params['address_id']]['postal_code']."\n".$country."\n";
                    
                    $trxnid = (array) $params['trxn_id'];
                    $smarty =& CRM_Core_Smarty::singleton();
                    foreach ( $values['amount'] as $k => $val ) {
                        $smarty->assign( 'amount', $val['value'] );
                    }
                    $smarty->assign( 'address', $billingAddress );
                    $smarty->assign( 'title', $values['title'] );
                    $smarty->assign( 'receive_date', $params['receive_date'] );
                    $smarty->assign( 'trxn_id',$trxnid['0'] );
                    $smarty->assign( 'billingName', $billingName );
                    $smarty->assign( 'is_monetary',$values['is_monetary'] );
                    CRM_Contribute_BAO_ContributionPage::sendMail( $params['contact_id'], $values, $isTest , $returnMessageText = false );
                    if ( ( $recurResult->count_id ) == $recurResult->installments ) {
                        $completed['contribution_status_id'] = array_search( 'Completed', $contributionStatus );
                        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur', $recurResult->id, 
                                                     'contribution_status_id', $completed );
                    }
                } else if ( $approval == 103 ) {
                    continue;
                } else {
                    $params['contribution_status_id'] = array_search( 'Failed', $contributionStatus );
                    $contribution = CRM_Contribute_BAO_Contribution::create( $params, $null );
                    if ( ( $recurResult->count_id ) == $recurResult->installments ) {
                        $completed['contribution_status_id'] = array_search( 'Completed', $contributionStatus );
                        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur', $recurResult->id, 
                                                     'contribution_status_id', $completed );
                    }	
                }
            } else if ( $now < $today ) {
                $query = "
                    SELECT MAX(receive_date) FROM civicrm_contribution 
                     WHERE contribution_recur_id = %1 
                  GROUP BY contribution_recur_id";
                $dateParams = array( 1 => array( $recurResult->id , 'Integer' ), );
                $max_date = CRM_Core_DAO::singleValueQuery( $query, $dateParams );
                $latestCount = "+" . $recurResult->frequency_interval ." " . $recurResult->frequency_unit;
                $latestDate = date( 'Y-m-d', strtotime( $latestCount, strtotime( $max_date ) ) );
                if ( $now === $latestDate) {
                    $params = self::getParams( $recurResult->trxn_id );
                    $params['receive_date'] = date('Y-m-d H:m:s', strtotime( $latestCount, strtotime( $max_date ) ) );
                    $params['contribution_status_id'] = array_search( 'Failed', $contributionStatus );
                    $contribution = CRM_Contribute_BAO_Contribution::create( $params, $null );
                    if ( ( $recurResult->count_id +1 ) == $recurResult->installments ) {
                        $completed['contribution_status_id'] = array_search( 'Completed', $contributionStatus );
                        CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur', $recurResult->id, 
                                                     'contribution_status_id', $completed );
                    }
                }                
            }
        }
    } 
    
    function getParams( $trid ) 
    {
        CRM_Core_DAO::commonRetrieveAll( 'CRM_Contribute_DAO_Contribution', 'trxn_id', $trid, $details, NULL );
        
        foreach ( $details as $key => $value ) {
            
            $params = array( 'contact_id'            => $value['contact_id'],
                             'contribution_type_id'  => $value['contribution_type_id'],
                             'payment_instrument_id' => $value['payment_instrument_id'],
                             'contribution_recur_id' => $value['contribution_recur_id'],
                             'total_amount'          => $value['total_amount'],
                             'address_id'            => $value['address_id'],
                             'source'                => $value['source'],
                             'contribution_source'   => $value['contribution_source'],
                             'non_deductible_amount' => $value['non_deductible_amount'],
                             'contribution_page_id'  => $value['contribution_page_id'],
                             'currency'              => $value['currency'],
                             'is_test'               => $value['is_test'],
                             'is_pay_later'          => $value['is_pay_later'], );
        }
        return $params;
    }
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
  }

$obj = new CRM_RedfinRecurContribution( );
$obj->redfinContribution( );
