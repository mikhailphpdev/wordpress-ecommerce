<?php
/**
 * The PayPal Express Checkout Gateway class
 *
 */

class WPSC_Payment_Gateway_Paypal_Express_Checkout extends WPSC_Payment_Gateway {
	public $sandbox_url = 'https://www.sandbox.paypal.com/webscr';
	public $live_url    = 'https://www.paypal.com/cgi-bin/webscr';
	private $gateway;

	/**
	 * Constructor of PayPal Express Checkout Gateway
	 *
	 * @param array $options
	 * @return void
	 *
	 * @since 3.9
	 */
	public function __construct( $options ) {
		parent::__construct();
		$this->title = __( 'PayPal Express Checkout 3.0', 'wpsc' );

		require_once( 'php-merchant/gateways/paypal-express-checkout.php' );
		$this->gateway = new PHP_Merchant_Paypal_Express_Checkout( $options );
		$this->gateway->set_options( array(
			'api_username'     => $this->setting->get( 'api_username' ),
			'api_password'     => $this->setting->get( 'api_password' ),
			'api_signature'    => $this->setting->get( 'api_signature' ),
			'cancel_url'       => $this->get_shopping_cart_payment_url(),
			'currency'         => $this->get_currency_code(),
			'test'             => (bool) $this->setting->get( 'sandbox_mode' ),
			'address_override' => 1,
			'solution_type'    => 'mark',
			'cart_logo'        => $this->setting->get( 'cart_logo' ),
			'cart_border'      => $this->setting->get( 'cart_border' ),
		) );
	
	}

	/**
	 * Run the gateway hooks
	 *
	 * @access public
     * @since 4.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter(
			'wpsc_payment_method_form_fields',
			array( 'WPSC_Payment_Gateway_Paypal_Express_Checkout', 'filter_unselect_default' ), 100 , 1
		);
	}

	/**
	 * No payment gateway is selected by default
	 *
	 * @access public
	 * @param array $fields
	 * @return array
	 *
	 * @since 3.9
	 */
	public static function filter_unselect_default( $fields ) {
		foreach ( $fields as $i=>$field ) {
			$fields[ $i ][ 'checked' ] = false;
		}

		return $fields;
	}

	/**
	 * Returns the HTML of the logo of the payment gateway.
	 *
	 * @access public
	 * @return string
	 *
	 * @since 3.9
	 */
	public function get_mark_html() {
		$html = '<a href="https://www.paypal.com/webapps/mpp/paypal-popup" title="' . esc_attr__( 'How PayPal Works' ) . '" onclick="javascript:window.open(\'https://www.paypal.com/webapps/mpp/paypal-popup\',\'WIPaypal\',\'toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=1060, height=700\'); return false;"><img src="https://www.paypalobjects.com/webstatic/mktg/logo/pp_cc_mark_37x23.jpg" border="0" alt="PayPal Logo"></a>';

		return apply_filters( 'wpsc_paypal-ec_mark_html', $html );
	}


	/**
	 * Returns the PayPal redirect URL
	 *
	 * @param array $data Arguments to encode with the URL
	 * @return string
	 *
	 * @since 3.9
	 */
	public function get_redirect_url( $data = array() ) {

		// Select either the Sandbox or the Live URL
		if ( $this->setting->get( 'sandbox_mode' ) ) {
			$url = $this->sandbox_url;
		} else {
			$url = $this->live_url;
		}

		// Common Vars
		$common = array(
			'cmd'        => '_express-checkout',
			'useraction' => 'commit',
		);

		if ( wp_is_mobile() ) {
			$common['cmd'] = '_express-checkout-mobile';
		}

		// Merge the two arrays
		$data = array_merge( $data, $common );

		// Build the URL
		$url = add_query_arg( $data, $url );

		return $url;
	}

	/**
	 * Returns the URL of the Return Page after the PayPal Checkout
	 *
	 * @return string
	 */
	protected function get_return_url() {
		$location = add_query_arg( array(
			'sessionid'                => $this->purchase_log->get( 'sessionid' ),
			'payment_gateway'          => 'paypal-express-checkout',
			'payment_gateway_callback' => 'confirm_transaction',
		),
		get_option( 'transact_url' )
	);
		return apply_filters( 'wpsc_paypal_express_checkout_return_url', $location, $this );
	}

	/**
	 * Returns the URL of the IPN Page
	 *
	 * @return string
	 */
	protected function get_notify_url() {
		$location = add_query_arg( array(
			'payment_gateway'          => 'paypal-express-checkout',
			'payment_gateway_callback' => 'ipn',
		), home_url( 'index.php' ) );

		return apply_filters( 'wpsc_paypal_express_checkout_notify_url', $location );
	}

	/**
	 * Creates a new Purchase Log entry and set it to the current object
	 *
	 * @return null
	 */
	protected function set_purchase_log_for_callbacks( $sessionid = false ) {
		// Define the sessionid if it's not passed
		if ( $sessionid === false ) {
			$sessionid = $_REQUEST['sessionid'];
		}

		// Create a new Purchase Log entry
		$purchase_log = new WPSC_Purchase_Log( $sessionid, 'sessionid' );

		if ( ! $purchase_log->exists() ) {
			return null;
		}

		// Set the Purchase Log for the gateway object
		$this->set_purchase_log( $purchase_log );
	}

	/**
	 * IPN Callback function
	 *
	 * @return void
	 */
	public function callback_ipn() {
		$ipn = new PHP_Merchant_Paypal_IPN( false, (bool) $this->setting->get( 'sandbox_mode', false ) );

		if ( $ipn->is_verified() ) {
			$sessionid = $ipn->get( 'invoice' );
			$this->set_purchase_log_for_callbacks( $sessionid );

			if ( $ipn->is_payment_denied() ) {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::PAYMENT_DECLINED );
			} elseif ( $ipn->is_payment_refunded() ) {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::REFUNDED );
			} elseif ( $ipn->is_payment_completed() ) {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::ACCEPTED_PAYMENT );
			} elseif ( $ipn->is_payment_pending() ) {
				if ( $ipn->is_payment_refund_pending() ) {
					$this->purchase_log->set( 'processed', WPSC_Purchase_Log::REFUND_PENDING );
				} else {
					$this->purchase_log->set( 'processed', WPSC_Purchase_Log::ORDER_RECEIVED );
				}
			}

			$this->purchase_log->save();
			transaction_results( $sessionid, false );
		}

		exit;
	}

	/**
	 * Confirm Transaction Callback
	 *
	 * @return bool
	 *
	 * @since 3.9
	 */
	public function callback_confirm_transaction() {
		if ( ! isset( $_REQUEST['sessionid'] ) || ! isset( $_REQUEST['token'] ) || ! isset( $_REQUEST['PayerID'] ) ) {
			return false;
		}

		// Set the Purchase Log
		$this->set_purchase_log_for_callbacks();

		// Display the Confirmation Page
		$this->do_transaction();
	}

	/**
	 * Process the transaction through the PayPal APIs
	 *
	 * @since 3.9
	 */
	public function do_transaction() {
		$args = array_map( 'urldecode', $_GET );
		extract( $args, EXTR_SKIP );

		if ( ! isset( $sessionid ) || ! isset( $token ) || ! isset( $PayerID ) ) {
			return;
		}

		$this->set_purchase_log_for_callbacks();

		$total = $this->convert( $this->purchase_log->get( 'totalprice' ) );
		$options = array(
			'token'         => $token,
			'payer_id'      => $PayerID,
			'message_id'    => $this->purchase_log->get( 'id' ),
			'invoice'		=> $this->purchase_log->get( 'sessionid' ),
		);
		$options += $this->checkout_data->get_gateway_data();
		$options += $this->purchase_log->get_gateway_data( parent::get_currency_code(), $this->get_currency_code() );

		if ( $this->setting->get( 'ipn', false ) ) {
			$options['notify_url'] = $this->get_notify_url();
		}

		// GetExpressCheckoutDetails
		$details = $this->gateway->get_details_for( $token );
		$this->log_payer_details( $details );

		$response = $this->gateway->purchase( $options );
		$this->log_protection_status( $response );
		$location = remove_query_arg( 'payment_gateway_callback' );

		if ( $response->has_errors() ) {
			$errors = $response->get_params();

			if ( isset( $errors['L_ERRORCODE0'] ) && '10486' == $errors['L_ERRORCODE0'] ) {
				wp_redirect( $this->get_redirect_url( array( 'token' => $token ) ) );
				exit;
			}

			wpsc_update_customer_meta( 'paypal_express_checkout_errors', $response->get_errors() );
			$location = add_query_arg( array( 'payment_gateway_callback' => 'display_paypal_error' ) );

		} elseif ( $response->is_payment_completed() || $response->is_payment_pending() ) {
			$location = remove_query_arg( 'payment_gateway' );

			if ( $response->is_payment_completed() ) {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::ACCEPTED_PAYMENT );
			} else {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::ORDER_RECEIVED );
			}

			$this->purchase_log->set( 'transactid', $response->get( 'transaction_id' ) )
				->set( 'date', time() )
				->save();
		} else {
			$location = add_query_arg( array( 'payment_gateway_callback' => 'display_generic_error' ) );
		}

		wp_redirect( $location );
		exit;
	}

	public function callback_display_paypal_error() {
		add_filter( 'wpsc_get_transaction_html_output', array( $this, 'filter_paypal_error_page' ) );
	}

	public function callback_display_generic_error() {
		add_filter( 'wpsc_get_transaction_html_output', array( $this, 'filter_generic_error_page' ) );
	}

	/**
	 * Records the Payer ID, Payer Status and Shipping Status to the Purchase
	 * Log on GetExpressCheckout Call
	 *
	 * @return void
	 */
	public function log_payer_details( $details ) {
		if ( isset( $details->get( 'payer' )->id ) && !empty( $details->get( 'payer' )->id ) ) {
			$payer_id = $details->get( 'payer' )->id;
		} else {
			$payer_id = 'not set';
		}
		if ( isset( $details->get( 'payer' )->status ) && !empty( $details->get( 'payer' )->status ) ) {
			$payer_status = $details->get( 'payer' )->status;
		} else {
			$payer_status = 'not set';
		}
		if ( isset( $details->get( 'payer' )->shipping_status ) && !empty( $details->get( 'payer' )->shipping_status ) ) {
			$payer_shipping_status = $details->get( 'payer' )->shipping_status;
		} else {
			$payer_shipping_status = 'not set';
		}
		$paypal_log = array(
			'payer_id'        => $payer_id,
			'payer_status'    => $payer_status,
			'shipping_status' => $payer_shipping_status,
			'protection'      => null,
		);

		wpsc_update_purchase_meta( $this->purchase_log->get( 'id' ), 'paypal_ec_details' , $paypal_log );
	}

	/**
	 * Records the Protection Eligibility status to the Purchase Log on
	 * DoExpressCheckout Call
	 *
	 * @return void
	 */
	public function log_protection_status( $response ) {
		$params = $response->get_params();

		if ( isset( $params['PAYMENTINFO_0_PROTECTIONELIGIBILITY'] ) ) {
			$elg                      = $params['PAYMENTINFO_0_PROTECTIONELIGIBILITY'];
		} else {
			$elg = false;
		}
		$paypal_log               = wpsc_get_purchase_meta( $this->purchase_log->get( 'id' ), 'paypal_ec_details', true );
		$paypal_log['protection'] = $elg;
		wpsc_update_purchase_meta( $this->purchase_log->get( 'id' ), 'paypal_ec_details' , $paypal_log );
	}

	public function callback_process_confirmed_payment() {
		$args = array_map( 'urldecode', $_GET );
		extract( $args, EXTR_SKIP );

		if ( ! isset( $sessionid ) || ! isset( $token ) || ! isset( $PayerID ) ) {
			return;
		}

		$this->set_purchase_log_for_callbacks();

		$total = $this->convert( $this->purchase_log->get( 'totalprice' ) );
		$options = array(
			'token'         => $token,
			'payer_id'      => $PayerID,
			'message_id'    => $this->purchase_log->get( 'id' ),
			'invoice'       => $this->purchase_log->get( 'sessionid' ),
		);
		$options += $this->checkout_data->get_gateway_data();
		$options += $this->purchase_log->get_gateway_data( parent::get_currency_code(), $this->get_currency_code() );

		if ( $this->setting->get( 'ipn', false ) ) {
			$options['notify_url'] = $this->get_notify_url();
		}

		// GetExpressCheckoutDetails
		$details = $this->gateway->get_details_for( $token );
		$this->log_payer_details( $details );

		$response = $this->gateway->purchase( $options );
		$this->log_protection_status( $response );
		$location = remove_query_arg( 'payment_gateway_callback' );

		if ( $response->has_errors() ) {
			wpsc_update_customer_meta( 'paypal_express_checkout_errors', $response->get_errors() );
			$location = add_query_arg( array( 'payment_gateway_callback' => 'display_paypal_error' ) );
		} elseif ( $response->is_payment_completed() || $response->is_payment_pending() ) {
			$location = remove_query_arg( 'payment_gateway' );

			if ( $response->is_payment_completed() ) {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::ACCEPTED_PAYMENT );
			} else {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::ORDER_RECEIVED );
			}

			$this->purchase_log->set( 'transactid', $response->get( 'transaction_id' ) )
				->set( 'date', time() )
				->save();
		} else {
			$location = add_query_arg( array( 'payment_gateway_callback' => 'display_generic_error' ) );
		}

		wp_redirect( $location );
		exit;
	}

	/**
	 * Error Page Template
	 *
	 * @since 3.9
	 */
	public function filter_paypal_error_page() {
		$errors = wpsc_get_customer_meta( 'paypal_express_checkout_errors' );
		ob_start();
?>
	<p>
	<?php _e( 'Sorry, your transaction could not be processed by PayPal. Please contact the site administrator. The following errors are returned:' , 'wpsc' ); ?>
		</p>
			<ul>
			<?php foreach ( $errors as $error ): ?>
			<li><?php echo esc_html( $error['details'] ) ?> (<?php echo esc_html( $error['code'] ); ?>)</li>
			<?php endforeach; ?>
		</ul>
			<p><a href="<?php echo esc_url( $this->get_shopping_cart_payment_url() ); ?>"><?php ( 'Click here to go back to the checkout page.') ?></a></p>
<?php
		$output = apply_filters( 'wpsc_paypal_express_checkout_gateway_error_message', ob_get_clean(), $errors );
		return $output;
	}

	/**
	 * Generic Error Page Template
	 *
	 * @since 3.9
	 */
	public function filter_generic_error_page() {
		ob_start();
?>
<p><?php _e( 'Sorry, but your transaction could not be processed by PayPal for some reason. Please contact the site administrator.' , 'wpsc' ); ?></p>
<p><a href="<?php echo esc_attr( $this->get_shopping_cart_payment_url() ); ?>"><?php _e( 'Click here to go back to the checkout page.', 'wpsc' ) ?></a></p>
<?php
		$output = apply_filters( 'wpsc_paypal_express_checkout_generic_error_message', ob_get_clean() );
		return $output;
	}

	/**
	 * Settings Form Template
	 *
	 * @since 3.9
	 */
	public function setup_form() {
		$paypal_currency = $this->get_currency_code();
?>

<!-- Account Credentials -->
<tr>
	<td colspan="2">
		<h4><?php _e( 'Account Credentials', 'wpsc' ); ?></h4>
	</td>
</tr>
<tr>
	<td>
		<label for="wpsc-paypal-express-api-username"><?php _e( 'API Username', 'wpsc' ); ?></label>
	</td>
	<td>
		<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_username' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_username' ) ); ?>" id="wpsc-paypal-express-api-username" />
	</td>
</tr>
<tr>
	<td>
		<label for="wpsc-paypal-express-api-password"><?php _e( 'API Password', 'wpsc' ); ?></label>
	</td>
	<td>
		<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_password' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_password' ) ); ?>" id="wpsc-paypal-express-api-password" />
	</td>
</tr>
<tr>
	<td>
		<label for="wpsc-paypal-express-api-signature"><?php _e( 'API Signature', 'wpsc' ); ?></label>
	</td>
	<td>
		<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_signature' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_signature' ) ); ?>" id="wpsc-paypal-express-api-signature" />
	</td>
</tr>
<tr>
	<td>
		<label><?php _e( 'Sandbox Mode', 'wpsc' ); ?></label>
	</td>
	<td>
		<label><input <?php checked( $this->setting->get( 'sandbox_mode' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
		<label><input <?php checked( (bool) $this->setting->get( 'sandbox_mode' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
	</td>
</tr>
<tr>
	<td>
		<label><?php _e( 'IPN', 'wpsc' ); ?></label>
	</td>
	<td>
		<label><input <?php checked( $this->setting->get( 'ipn' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'ipn' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
		<label><input <?php checked( (bool) $this->setting->get( 'ipn' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'ipn' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
	</td>
</tr>

<!-- Cart Customization -->
<tr>
	<td colspan="2">
		<label><h4><?php _e( 'Cart Customization', 'wpsc'); ?></h4></label>
	</td>
</tr>
<tr>
	<td>
		<label for="wpsc-paypal-express-cart-logo"><?php _e( 'Merchant Logo', 'wpsc' ); ?></label>
	</td>
	<td>
		<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'cart_logo' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'cart_logo' ) ); ?>" id="wpsc-paypal-express-cart-logo" />
	</td>
</tr>
<tr>
	<td>
		<label for="wpsc-paypal-express-cart-border"><?php _e( 'Cart Border Color', 'wpsc' ); ?></label>
	</td>
	<td>
		<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'cart_border' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'cart_border' ) ); ?>" id="wpsc-paypal-express-cart-border" />
	</td>
</tr>

<!-- Currency Conversion -->
<?php if ( ! $this->is_currency_supported() ) : ?>
<tr>
	<td colspan="2">
		<h4><?php _e( 'Currency Conversion', 'wpsc' ); ?></h4>
	</td>
</tr>
<tr>
	<td colspan="2">
		<p><?php _e( 'Your base currency is currently not accepted by PayPal. As a result, before a payment request is sent to PayPal, WP eCommerce has to convert the amounts into one of PayPal supported currencies. Please select your preferred currency below.', 'wpsc' ); ?></p>
	</td>
</tr>
<tr>
	<td>
		<label for "wpsc-paypal-express-currency"><?php _e( 'PayPal Currency', 'wpsc' ); ?></label>
	</td>
	<td>
		<select name="<?php echo esc_attr( $this->setting->get_field_name( 'currency' ) ); ?>" id="wpsc-paypal-express-currency">
			<?php foreach ( $this->gateway->get_supported_currencies() as $currency ) : ?>
			<option <?php selected( $currency, $paypal_currency ); ?> value="<?php echo esc_attr( $currency ); ?>"><?php echo esc_html( $currency ); ?></option>
			<?php endforeach ?>
		</select>
	</td>
</tr>
<?php endif ?>

<!-- Checkout Shortcut -->
<tr>
    <td colspan="2">
        <h4><?php _e( 'Express Checkout Shortcut', 'wpsc' ); ?></h4>
    </td>
</tr>
<tr>
    <td>
        <label><?php _e( 'Enable Shortcut', 'wpsc' ); ?></label>
    </td>
    <td>
        <label><input <?php checked( $this->setting->get( 'shortcut' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'shortcut' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
        <label><input <?php checked( (bool) $this->setting->get( 'shortcut' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'shortcut' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
    </td>
</tr>

<!-- Error Logging -->
<tr>
	<td colspan="2">
		<h4><?php _e( 'Error Logging', 'wpsc' ); ?></h4>
	</td>
</tr>
<tr>
	<td>
		<label><?php _e( 'Enable Debugging', 'wpsc' ); ?></label>
	</td>
	<td>
		<label><input <?php checked( $this->setting->get( 'debugging' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
		<label><input <?php checked( (bool) $this->setting->get( 'debugging' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
	</td>
</tr>
<?php
	}

	/**
	 * Check if the selected currency is supported by the gateway
	 *
	 * @return bool
	 *
	 * @since 3.9
	 */
	protected function is_currency_supported() {
		return in_array( parent::get_currency_code(), $this->gateway->get_supported_currencies() );
	}

	/**
	 * Return the Currency ISO code
	 *
	 * @return string
	 *
	 * @since 3.9
	 */
	public function get_currency_code() {
		$code = parent::get_currency_code();

		if ( ! in_array( $code, $this->gateway->get_supported_currencies() ) ) {
			$code = $this->setting->get( 'currency', 'USD' );
		}

		return $code;
	}

	/**
	 * Convert an amount (integer) to the supported currency
	 * @param integer $amt
	 *
	 * @return integer
	 *
	 * @since 3.9
	 */
	protected function convert( $amt ) {
		if ( $this->is_currency_supported() ) {
			return $amt;
		}

		return wpsc_convert_currency( $amt, parent::get_currency_code(), $this->get_currency_code() );
	}

	/**
	 * Process the SetExpressCheckout API Call
	 *
	 * @return void
	 *
	 * @since 3.9
	 */
	public function process() {
		$total = $this->convert( $this->purchase_log->get( 'totalprice' ) );
		$options = array(
			'return_url'       => $this->get_return_url(),
			'message_id'       => $this->purchase_log->get( 'id' ),
			'invoice'          => $this->purchase_log->get( 'sessionid' ),
			'address_override' => 1,
		);

		$options += $this->checkout_data->get_gateway_data();
		$options += $this->purchase_log->get_gateway_data( parent::get_currency_code(), $this->get_currency_code() );

		if ( $this->setting->get( 'ipn', false ) ) {
			$options['notify_url'] = $this->get_notify_url();
		}

		// SetExpressCheckout
		$response = $this->gateway->setup_purchase( $options );

		if ( $response->is_successful() ) {
			$params = $response->get_params();
			if ( $params['ACK'] == 'SuccessWithWarning' ) {
				$this->log_error( $response );
				wpsc_update_customer_meta( 'paypal_express_checkout_errors', $response->get_errors() );
			}
			// Successful redirect
			$url = $this->get_redirect_url( array( 'token' => $response->get( 'token' ) ) );
		} else {

			// SetExpressCheckout Failure
			$this->log_error( $response );
			wpsc_update_customer_meta( 'paypal_express_checkout_errors', $response->get_errors() );

			$url = add_query_arg( array(
				'payment_gateway'          => 'paypal-express-checkout',
				'payment_gateway_callback' => 'display_paypal_error',
			), $this->get_return_url() );
		}

		wp_redirect( $url );
		exit;
	}

	/**
	 * Log an error message
	 *
	 * @param PHP_Merchant_Paypal_Express_Checkout_Response $response
	 * @return void
	 *
	 * @since 3.9
	 */
	public function log_error( $response ) {
		if ( $this->setting->get( 'debugging' ) ) {
			$log_data = array(
				'post_title'    => 'PayPal ExpressCheckout Operation Failure',
				'post_content'  =>  'There was an error processing the payment. Find details in the log entry meta fields.',
				'log_type'      => 'error'
			);

			$log_meta = array(
				'correlation_id'   => $response->get( 'correlation_id' ),
				'time' => $response->get( 'datetime' ),
				'errors' => $response->get_errors(),
			);

			$log_entry = WPSC_Logging::insert_log( $log_data, $log_meta );
		}
	}
}
