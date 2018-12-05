<?php


use Paylike\Exception\ApiException;

class Paylike_Payment_Model_Paylike extends Mage_Payment_Model_Method_Abstract {

	const REQUEST_TYPE_AUTH_CAPTURE = 'AUTH_CAPTURE';
	const REQUEST_TYPE_AUTH_ONLY = 'AUTH_ONLY';

	protected $_isGateway = true;
	protected $_canAuthorize = true;
	protected $_canUseCheckout = true;
	protected $_canCapture = true;
	protected $_canCapturePartial = false;
	protected $_canRefund = true;
	protected $_canRefundInvoicePartial = true;
	protected $_canVoid = true;
	protected $_code = 'paylike';
	protected $_formBlockType = 'paylike_payment/form_paylike';
	protected $_infoBlockType = 'paylike_payment/info_paylike';

	private $client;

	/**
	 * @throws Varien_Exception
	 */
	public function _construct() {
		parent::_construct();

		$this->_init( 'paylike_payment/paylike' );
	}

	/**
	 * @param Varien_Object $payment
	 * @param float         $amount
	 * @param bool          $ajax
	 *
	 * @return $this|Mage_Payment_Model_Abstract
	 * @throws Mage_Core_Exception
	 * @throws Varien_Exception
	 */
	public function authorize( Varien_Object $payment, $amount, $ajax = false ) {
		$order_id = $payment->getOrder()->getId();
		/** @var Mage_Sales_Model_Order $order */
		$order = $payment->getOrder();

		Mage::log( '------------- Start payment --------------' . PHP_EOL . "Info: Begin processing payment for order $order_id for the amount of {$order->getGrandTotal()}." . PHP_EOL . 'Transaction id:' . $payment->getPaylikeTransactionId() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );

		/** The transaction id is a getter from the request, paylike_transaction_id */
		$payment->setTransactionId( $payment->getPaylikeTransactionId() );
		$payment->setIsTransactionClosed( 0 );

		try {
			$transaction = $this->getClient()->transactions()->fetch( $payment->getPaylikeTransactionId() );
		} catch ( ApiException  $exception ) {
			$message = $this->handleExceptions( $exception, 'Issue: Authorization Failed!' );
			Mage::throwException( $message );

			return $this;
		}

		if ( ! $transaction['succesfull'] ) {
			Mage::log( '------------- Problem payment --------------' . PHP_EOL . json_encode( $transaction ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			Mage::throwException( Mage::helper( 'paylike_payment' )->__( 'There has been a problem, the transaction failed, try to pay again, or contact support' ) );
		}


		$data = array(
			'paylike_tid'     => $payment->getPaylikeTransactionId(),
			'order_id'        => $order_id,
			'payed_at'        => date( 'Y-m-d H:i:s' ),
			'payed_amount'    => $amount,
			'refunded_amount' => 0,
			'captured'        => 'NO'
		);

		$model = Mage::getModel( 'paylike_payment/paylikeadmin' );

		try {
			$model->setData( $data )
			      ->save();

			return $this;

		} catch ( Exception $e ) {

			$errormsg = Mage::helper( 'paylike_payment' )->__( $e->getMessage() );
			Mage::throwException( $errormsg );

		}

		return $this;
	}

	/**
	 * @param Varien_Object $payment
	 * @param               $amount
	 * @param bool          $ajax
	 *
	 * @return $this|Mage_Payment_Model_Abstract
	 * @throws Mage_Core_Exception
	 * @throws Mage_Core_Model_Store_Exception
	 * @throws Varien_Exception
	 */
	public function capture( Varien_Object $payment, $amount, $ajax = false ) {
		if ( $amount <= 0 ) {
			if ( $ajax ) {
				$response = array(
					'error'   => 1,
					'message' => 'The amount is not valid for capture.'
				);

				return $response;
			} else {
				$errormsg = Mage::helper( 'paylike_payment' )->__( 'The amount is not valid for capture.' );
				Mage::throwException( $errormsg );
			}
		}

		if ( ! $payment->getLastTransId() ) {
			$payment->setLastTransId( $payment->getPaylikeTransactionId() );
		}

		$order         = $payment->getOrder();
		$order_id      = $order->getId();
		$real_order_id = $order->getRealOrderId();
		$currency_code = $order->getOrderCurrencyCode();
		Paylike\Client::setKey( $this->getApiKey() );
		$arr     = array(
			'currency'   => $currency_code,
			'descriptor' => $this->getDescriptor( "#" . $real_order_id ),
			'amount'     => Mage::helper( 'paylike_payment/currencies' )->Ceil( $payment->getAmountAuthorized(), $currency_code ),
		);
		$capture = Paylike\Transaction::capture( $payment->getLastTransId(), $arr );
		Mage::log( 'Capture' . json_encode( $capture ) );
		Mage::log( 'Key:' . json_encode( $this->getApiKey() ) );

		if ( ! $capture ) {
			Mage::throwException( 'Capture has failed, this may be a problem with your configuration, or with the server. Check your configuration and try again. Key used:' . $this->getApiKey() );
		}
		$paylike_admin = Mage::getModel( 'paylike_payment/paylikeadmin' )
		                     ->getCollection()
		                     ->addFieldToFilter( 'paylike_tid', $payment->getPaylikeTransactionId() )
		                     ->addFieldToFilter( 'order_id', $order_id )
		                     ->getFirstItem()
		                     ->getData();

		$payment->setTransactionId( $payment->getPaylikeTransactionId() );
		$payment->setIsTransactionClosed( 1 );

		if ( empty( $paylike_admin ) ) {
			$data  = array(
				'paylike_tid'     => $payment->getPaylikeTransactionId(),
				'order_id'        => $order_id,
				'payed_at'        => date( 'Y-m-d H:i:s' ),
				'payed_amount'    => $amount,
				'refunded_amount' => 0,
				'captured'        => 'YES'
			);
			$model = Mage::getModel( 'paylike_payment/paylikeadmin' );
			$model->setData( $data )
			      ->save();


		} else {
			$id   = $paylike_admin['id'];
			$data = array(
				'captured' => 'YES'
			);

			$model = Mage::getModel( 'paylike_payment/paylikeadmin' );

			try {
				$model->load( $id )
				      ->addData( $data )
				      ->setId( $id )
				      ->save();

				return $this;

			} catch ( Exception $e ) {

				$errormsg = Mage::helper( 'paylike_payment' )->__( $e->getMessage() );
				Mage::throwException( $errormsg );

			}
		}


		return $this;
	}

	/**
	 * @param Varien_Object $payment
	 * @param float         $amount
	 * @param bool          $ajax
	 *
	 * @return $this|array|Mage_Payment_Model_Abstract
	 * @throws Mage_Core_Exception
	 * @throws Varien_Exception
	 */
	public function refund( Varien_Object $payment, $amount, $ajax = false ) {
		$order_id      = $payment->getOrder()->getId();
		$paylike_admin = Mage::getModel( 'paylike_payment/paylikeadmin' )
		                     ->getCollection()
		                     ->addFieldToFilter( 'paylike_tid', $payment->getPaylikeTransactionId() )
		                     ->addFieldToFilter( 'order_id', $order_id )
		                     ->getFirstItem()
		                     ->getData();

		if ( ! empty( $paylike_admin ) && $paylike_admin['captured'] == 'YES' ) {
			$apiKey = $this->getApiKey();
			if ( empty( $apiKey ) ) {
				if ( $ajax ) {
					$response = array(
						'error'   => 1,
						'message' => 'The API key is not valid.'
					);

					return $response;
				} else {
					$errormsg = Mage::helper( 'paylike_payment' )->__( 'The API key is not valid.' );
					Mage::throwException( $errormsg );
				}
			}
			if ( $amount <= 0 ) {
				if ( $ajax ) {
					$response = array(
						'error'   => 1,
						'message' => 'The amount you entered for refund is not valid.'
					);

					return $response;
				} else {
					$errormsg = Mage::helper( 'paylike_payment' )->__( 'The amount you entered for refund is not valid.' );
					Mage::throwException( $errormsg );
				}
			}
			Paylike\Client::setKey( $this->getApiKey() );
			$order         = $payment->getOrder();
			$real_order_id = $order->getRealOrderId();
			$currency_code = $order->getOrderCurrencyCode();
			$arr           = array(
				'descriptor' => $this->getDescriptor( '#' . $real_order_id ),
				'amount'     => Mage::helper( 'paylike_payment/currencies' )->Ceil( $payment->getAmountPaid(), $currency_code )
			);
			$refund        = Paylike\Transaction::refund( $payment->getLastTransId(), $arr );
			if ( is_array( $refund ) && ! empty( $refund['error'] ) && $refund['error'] == 1 ) {
				if ( $ajax ) {
					$response = array(
						'error'   => 1,
						'message' => $refund['message']
					);

					return $response;
				} else {
					$errormsg = Mage::helper( 'paylike_payment' )->__( $refund['message'] );
					Mage::throwException( $errormsg );
				}
			} else {
				if ( ! empty( $refund['transaction'] ) ) {
					$payment->setTransactionId( $payment->getPaylikeTransactionId() );

					$id   = $paylike_admin['id'];
					$data = array(
						'refunded_amount' => $paylike_admin['refunded_amount'] + $amount
					);

					$model = Mage::getModel( 'paylike_payment/paylikeadmin' );

					try {
						$model->load( $id )
						      ->addData( $data )
						      ->setId( $id )
						      ->save();

						if ( $ajax ) {
							$response = array(
								'success' => 1,
								'message' => 'The transaction has been refunded successfully.'
							);

							return $response;
						} else {
							return $this;
						}
					} catch ( Exception $e ) {
						if ( $ajax ) {
							$response = array(
								'error'   => 1,
								'message' => $e->getMessage()
							);

							return $response;
						} else {
							$errormsg = Mage::helper( 'paylike_payment' )->__( $e->getMessage() );
							Mage::throwException( $errormsg );
						}
					}
				} else {
					if ( ! empty( $refund[0]['message'] ) ) {
						if ( $ajax ) {
							$response = array(
								'error'   => 1,
								'message' => $refund[0]['message']
							);

							return $response;
						} else {
							$errormsg = Mage::helper( 'paylike_payment' )->__( $refund[0]['message'] );
							Mage::throwException( $errormsg );
						}
					} else {
						if ( $ajax ) {
							$response = array(
								'error'   => 1,
								'message' => 'The transaction is not valid.'
							);

							return $response;
						} else {
							$errormsg = Mage::helper( 'paylike_payment' )->__( 'The transaction is not valid.' );
							Mage::throwException( $errormsg );
						}
					}
				}
			}
		} else if ( ! empty( $paylike_admin ) && $paylike_admin['captured'] == 'NO' ) {
			if ( $ajax ) {
				$response = array(
					'error'   => 1,
					'message' => 'In order to refund you first need to capture the transaction.'
				);

				return $response;
			} else {
				$errormsg = Mage::helper( 'paylike_payment' )->__( 'In order to refund you first need to capture the transaction.' );
				Mage::throwException( $errormsg );
			}
		} else {
			if ( $ajax ) {
				$response = array(
					'error'   => 1,
					'message' => 'The transaction is not valid.'
				);

				return $response;
			} else {
				$errormsg = Mage::helper( 'paylike_payment' )->__( 'The transaction is not valid.' );
				Mage::throwException( $errormsg );
			}
		}

		return $this;
	}

	/**
	 * @param Varien_Object $payment
	 * @param bool          $ajax
	 *
	 * @return array|Mage_Payment_Model_Abstract
	 * @throws Mage_Core_Exception
	 * @throws Varien_Exception
	 */
	public function void( Varien_Object $payment, $ajax = false ) {
		$order_id      = $payment->getOrder()->getId();
		$paylike_admin = Mage::getModel( 'paylike_payment/paylikeadmin' )
		                     ->getCollection()
		                     ->addFieldToFilter( 'paylike_tid', $payment->getPaylikeTransactionId() )
		                     ->addFieldToFilter( 'order_id', $order_id )
		                     ->getFirstItem()
		                     ->getData();

		if ( ! empty( $paylike_admin ) && $paylike_admin['captured'] == 'NO' ) {
			$amount = $payment->getAmountAuthorized();
			$arr    = array(
				'amount' => Mage::helper( 'paylike_payment/currencies' )->Ceil( $payment->getAmountAuthorized(), $payment->getOrder()->getOrderCurrencyCode() ),
			);
			$apiKey = $this->getApiKey();
			if ( empty( $apiKey ) ) {
				if ( $ajax ) {
					$response = array(
						'error'   => 1,
						'message' => 'The API key is not valid.'
					);

					return $response;
				} else {
					$errormsg = Mage::helper( 'paylike_payment' )->__( 'The API key is not valid.' );
					Mage::throwException( $errormsg );
				}
			}
			Paylike\Client::setKey( $this->getApiKey() );
			$void = Paylike\Transaction::void( $payment->getLastTransId(), $arr );

			if ( is_array( $void ) && ! empty( $void['error'] ) && $void['error'] == 1 ) {
				if ( $ajax ) {
					$response = array(
						'error'   => 1,
						'message' => $void['message'],
					);

					return $response;
				} else {
					$errormsg = Mage::helper( 'paylike_payment' )->__( $void['message'] );
					Mage::throwException( $errormsg );
				}
			} else {
				if ( ! empty( $void['transaction'] ) ) {
					if ( $ajax ) {
						$response = array(
							'success' => 1,
							'message' => 'The transaction has been successfully voided.',
						);

						return $response;
					} else {
						return $this;
					}
				} else {
					if ( ! empty( $void[0]['message'] ) ) {
						if ( $ajax ) {
							$response = array(
								'error'   => 1,
								'message' => $void[0]['message'],
							);

							return $response;
						} else {
							$errormsg = Mage::helper( 'paylike_payment' )->__( $void[0]['message'] );
							Mage::throwException( $errormsg );
						}
					} else {
						if ( $ajax ) {
							$response = array(
								'error'   => 1,
								'message' => 'The transaction is not valid.'
							);

							return $response;
						} else {
							$errormsg = Mage::helper( 'paylike_payment' )->__( 'The transaction is not valid.' );
							Mage::throwException( $errormsg );
						}
					}
				}
			}
		} else if ( ! empty( $paylike_admin ) && $paylike_admin['captured'] == 'YES' ) {
			if ( $ajax ) {
				$response = array(
					'error'   => 1,
					'message' => 'You can\'t void the transaction because it has already been captured, you can only refund.'
				);

				return $response;
			} else {
				$errormsg = Mage::helper( 'paylike_payment' )->__( 'You can\'t void the transaction because it has already been captured, you can only refund.' );
				Mage::throwException( $errormsg );
			}
		} else {
			if ( $ajax ) {
				$response = array(
					'error'   => 1,
					'message' => 'The transaction is not valid.'
				);

				return $response;
			} else {
				$errormsg = Mage::helper( 'paylike_payment' )->__( 'The transaction is not valid.' );
				Mage::throwException( $errormsg );
			}
		}
	}

	/**
	 * @param null $quote
	 *
	 * @return bool|mixed
	 */
	public function isAvailable( $quote = null ) {
		return Mage::getStoreConfig( 'payment/paylike/status' );
	}

	/**
	 * @param mixed $data
	 *
	 * @return $this|Mage_Payment_Model_Info
	 * @throws Varien_Exception
	 */
	public function assignData( $data ) {
		if ( ! ( $data instanceof Varien_Object ) ) {
			$data = new Varien_Object( $data );
		}
		$info = $this->getInfoInstance();
		$info->setPaylikeTransactionId( $data->getPaylikeTransactionId() );

		return $this;
	}

	/**
	 * @return $this|Mage_Payment_Model_Abstract
	 * @throws Mage_Core_Exception
	 * @throws Varien_Exception
	 */
	public function validate() {
		$info = $this->getInfoInstance();
		if ( $info->getPaylikeTransactionId() == null ) {
			$errorMsg = false;
			Mage::throwException( $errorMsg );
		}

		return $this;
	}

	/**
	 * @return mixed
	 */
	protected function getApiKey() {
		if ( $this->getPaymentMode() == 'test' ) {
			return Mage::getStoreConfig( 'payment/paylike/test_api_key' );
		} else {
			return Mage::getStoreConfig( 'payment/paylike/live_api_key' );
		}
	}

	/**
	 * @return mixed
	 */
	protected function getPublicKey() {
		if ( $this->getPaymentMode() == 'test' ) {
			return Mage::getStoreConfig( 'payment/paylike/test_public_key' );
		} else {
			return Mage::getStoreConfig( 'payment/paylike/live_public_key' );
		}
	}

	/**
	 * @return mixed
	 */
	protected function getPaymentMode() {
		return Mage::getStoreConfig( 'payment/paylike/payment_mode' );
	}

	/* protected  function getPopupTitle()
	  {
	  return Mage::getStoreConfig(Mage_Core_Model_Store::XML_PATH_STORE_STORE_NAME);
  } */

	/**
	 * @return bool
	 */
	public function canRefund() {
		return $this->_canRefund;
	}

	/**
	 * @param Varien_Object $payment
	 *
	 * @return bool
	 */
	public function canVoid( Varien_Object $payment ) {
		return $this->_canVoid;
	}

	/**
	 * @return string
	 */
	public function getModuleCode() {
		return $this->_code;
	}

	/**
	 * Get the key of the global merchant descriptor
	 */
	protected function getGlobalMerchantDescriptor() {
		Paylike\Client::setKey( $this->getApiKey() );
		$adapter = Paylike\Client::getAdapter();
		$data    = $adapter->request( 'me', null, 'get' );
		if ( ! isset( $data['identity'] ) ) {
			return null;
		} else {
			$merchants = $adapter->request( 'identities/' . $data['identity']['id'] . '/merchants?limit=10', $data, 'get' );
		}
		foreach ( $merchants as $merchant ) {
			if ( $this->getPaymentMode() == 'test' && $merchant['test'] && $merchant['key'] == $this->getPublicKey() ) {
				return $merchant['descriptor'];
			}
			if ( ! $merchant['test'] && $this->getPaymentMode() != 'test' && $merchant['key'] == $this->getPublicKey() ) {
				return $merchant['descriptor'];
			}
		}
	}

	/**
	 * Get account user descriptor and append text to it if needed
	 *
	 * @param $text_to_append
	 *
	 * @return bool|null|string|string[]
	 */
	protected function getDescriptor( $text_to_append ) {
		$descriptor = $this->getGlobalMerchantDescriptor();
		if ( ! $descriptor ) {
			return '';
		}
		if ( strlen( $descriptor ) + strlen( $text_to_append ) <= 22 ) {
			$descriptor = $descriptor . $text_to_append;
		}
		//remove non ascii chars
		$descriptor = preg_replace( '/^[\x20-\x7E]$/', '', $descriptor );

		return substr( $descriptor, 0, 22 );
	}

	/**
	 * Log exceptions.
	 *
	 * @param \Paylike\Exception\ApiException $exception
	 * @param string                          $context
	 *
	 * @return bool|string
	 */
	public function handleExceptions( $exception, $context = '' ) {
		if ( ! $exception ) {
			return false;
		}
		$exception_type = get_class( $exception );
		$message        = '';
		switch ( $exception_type ) {
			case 'Paylike\\Exception\\NotFound':
				$message = Mage::helper( 'paylike_payment' )->__( 'Transaction not found! Check the transaction key used for the operation.' );
				break;
			case 'Paylike\\Exception\\InvalidRequest':
				$message = Mage::helper( 'paylike_payment' )->__( 'The request is not valid! Check if there is any validation bellow this message and adjust if possible, if not, and the problem persists, contact the developer.' );
				break;
			case 'Paylike\\Exception\\Forbidden':
				$message = Mage::helper( 'paylike_payment' )->__( 'The operation is not allowed! You do not have the rights to perform the operation, make sure you have all the grants required on your Paylike account.' );
				break;
			case 'Paylike\\Exception\\Unauthorized':
				$message = Mage::helper( 'paylike_payment' )->__( 'The operation is not properly authorized! Check the credentials set in settings for Paylike.' );
				break;
			case 'Paylike\\Exception\\Conflict':
				$message = Mage::helper( 'paylike_payment' )->__( 'The operation leads to a conflict! The same transaction is being requested for modification at the same time. Try again later.' );
				break;
			case 'Paylike\\Exception\\ApiConnection':
				$message = Mage::helper( 'paylike_payment' )->__( 'Network issues ! Check your connection and try again.' );
				break;
			case 'Paylike\\Exception\\ApiException':
				$message = Mage::helper( 'paylike_payment' )->__( 'There has been a server issue! If this problem persists contact the developer.' );
				break;
		}
		$message = Mage::helper( 'paylike_payment' )->__( 'Error: ' ) . $message;
		Mage::logException( $exception );

		return $message;
	}

	/**
	 * @return \Paylike\Paylike
	 * @throws ApiException
	 */
	private function getClient() {

		require_once dirname( __FILE__ ) . '/api/vendor/autoload.php';
		if ( ! $this->client ) {
			$this->client = new \Paylike\Paylike( $this->getApiKey() );
		}

		return $this->client;
	}


}