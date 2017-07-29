<?php

defined( '_JEXEC' ) or die( 'Restricted access' );

require_once( JPATH_ADMINISTRATOR . '/components/com_j2store/library/plugins/payment.php' );
require_once( JPATH_ADMINISTRATOR . '/components/com_j2store/helpers/j2store.php' );

class plgJ2StorePayment_ir123pay extends J2StorePaymentPlugin {
	var $_element = 'payment_ir123pay';
	private $merchant_id = '';
	private $callback_url = '';
	private $redirectToIr123pay = '';

	public function __construct( & $subject, $config ) {
		parent::__construct( $subject, $config );
		$this->loadLanguage( '', JPATH_ADMINISTRATOR );
		$this->merchant_id        = trim( $this->params->get( 'merchant_id' ) );
		$this->callback_url       = JUri::root() . '/index.php?option=com_j2store&view=checkout&task=confirmPayment&orderpayment_type=payment_ir123pay&paction=callback';
		$this->redirectToIr123pay = 'https://123pay.ir/checkout/invoice/';
	}

	public function _renderForm( $data ) {
		$vars          = new JObject();
		$vars->message = JText::_( "J2STORE_IR123PAY_PAYMENT_MESSAGE" );
		$html          = $this->_getLayout( 'form', $vars );

		return $html;
	}

	public function _prePayment( $data ) {
		$vars                       = new StdClass();
		$vars->display_name         = $this->params->get( 'display_name', '' );
		$vars->onbeforepayment_text = JText::_( "J2STORE_IR123PAY_PAYMENT_PREPARATION_MESSAGE" );

		$merchant_id  = trim( $this->params->get( 'merchant_id' ) );
		$amount       = (int) ( $data['orderpayment_amount'] );
		$callback_url = urlencode( $this->callback_url );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://123pay.ir/api/v1/create/payment' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, "merchant_id=$merchant_id&amount=$amount&callback_url=$callback_url" );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

		$result = json_decode( $response );

		if ( ! $result->status ) {
			$vars->error = $result->message;
			$html        = $this->_getLayout( 'prepayment', $vars );

			return $html;
		}

		if ( $result->Status ) {
			$vars->redirectToI1P = $result->payment_url;
			$html                = $this->_getLayout( 'prepayment', $vars );

			return $html;
		}

		$vars->error = $result->message;
		$html        = $this->_getLayout( 'prepayment', $vars );

		return $html;
	}

	public function _postPayment( $data ) {
		$vars    = new JObject();
		$orderId = $data['order_id'];
		F0FTable::addIncludePath( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
		$order = F0FTable::getInstance( 'Order', 'J2StoreTable' )->getClone();
		$order->load( array( 'order_id' => $orderId ) );

		if ( $order->load( array( 'order_id' => $orderId ) ) ) {

			$currency           = J2Store::currency();
			$currencyValues     = $this->getCurrency( $order );
			$orderPaymentAmount = (int) ( $currency->format( $order->order_total, $currencyValues['currency_code'], $currencyValues['currency_value'], false ) );

			$order->add_history( JText::_( 'J2STORE_CALLBACK_RESPONSE_RECEIVED' ) );

			$merchant_id = $this->params->get( 'merchant_id' );

			$app    = JFactory::getApplication();
			$RefNum = $app->input->getString( 'RefNum' );

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'https://123pay.ir/api/v1/verify/payment' );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, "merchant_id=$merchant_id&RefNum=$RefNum" );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			$response = curl_exec( $ch );
			curl_close( $ch );

			$result = json_decode( $response );

			if ( ! $result->status ) {
				$vars->message = $result->message;
				$html          = $this->_getLayout( 'postpayment', $vars );

				return $html;
			}

			if ( $result->status AND $result->amount == $orderPaymentAmount ) {
				$order->payment_complete();
				$order->empty_cart();
				$message       = JText::_( "J2STORE_IR123PAY_PAYMENT_SUCCESS" ) . "\n";
				$message       .= JText::_( "J2STORE_IR123PAY_PAYMENT_I1P_REF" ) . $result->RefNum;
				$vars->message = $message;
				$html          = $this->_getLayout( 'postpayment', $vars );

				return $html;
			}

			$message       = JText::_( "J2STORE_IR123PAY_PAYMENT_FAILED" ) . "\n";
			$message       .= JText::_( "J2STORE_IR123PAY_PAYMENT_ERROR" );
			$message       .= $result->message . "\n";
			$message       .= JText::_( "J2STORE_IR123PAY_PAYMENT_CONTACT" ) . "\n";
			$vars->message = $message;
			$html          = $this->_getLayout( 'postpayment', $vars );

			return $html;
		}

		$vars->message = JText::_( "J2STORE_IR123PAY_PAYMENT_PAGE_ERROR" );
		$html          = $this->_getLayout( 'postpayment', $vars );

		return $html;
	}
}