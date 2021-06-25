<?php

class SI_Payflexi extends SI_Credit_Card_Processors
{
    const MODE_TEST = 'test';
    const MODE_LIVE = 'live';
    const MODAL_JS_OPTION = 'si_use_payflexi_js_modal';
    const DISABLE_JS_OPTION = 'si_use_payflexi_js';
    const API_SECRET_KEY_OPTION = 'si_payflexi_secret_key';
    const API_SECRET_KEY_TEST_OPTION = 'si_payflexi_secret_key_test';
    const API_PUB_KEY_OPTION = 'si_payflexi_pub_key';
    const API_PUB_KEY_TEST_OPTION = 'si_payflexi_pub_key_test';

    const PAYFLEXI_CUSTOMER_KEY_USER_META = 'si_payflexi_customer_id_v1';
    const TOKEN_INPUT_NAME = 'payflexi_charge_token';

    const API_MODE_OPTION = 'si_payflexi_mode';
    const CURRENCY_CODE_OPTION = 'si_payflexi_currency';
    const PAYMENT_GATEWAY_OPTION = 'si_payflexi_payment_gateway';
    const PAYMENT_METHOD = 'PayFlexi (Pay in Instalments)';
    const PAYMENT_SLUG = 'payflexi';
    const TOKEN_KEY = 'si_token_key'; // Combine with $blog_id to get the actual meta key
    const PAYER_ID = 'si_payer_id'; // Combine with $blog_id to get the actual meta key


    const UPDATE = 'payflexi_version_upgrade_v1';

    protected static $instance;
    protected static $api_mode = self::MODE_TEST;
    private static $payment_modal;
    private static $disable_payflexi_js;
    private static $api_secret_key_test;
    private static $api_pub_key_test;
    private static $api_secret_key;
    private static $api_pub_key;
    private static $currency_code = 'USD';
    private static $payment_gateway = 'stripe';

    public static function get_instance()
    {
        if (! (isset(self::$instance) && is_a(self::$instance, __CLASS__))) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function is_test()
    {
        return self::MODE_TEST === self::$api_mode;
    }

    public function get_payment_method()
    {
        return self::PAYMENT_METHOD;
    }

    public function get_slug()
    {
        return self::PAYMENT_SLUG;
    }

    public static function register()
    {
        // Register processor
        self::add_payment_processor(__CLASS__, __('PayFlexi', 'sprout-invoices'));
        
        if ( ! self::is_active() ) {
			return;
		}
        // Enqueue Scripts
        if (apply_filters('si_remove_scripts_styles_on_doc_pages', '__return_true')) {
            // enqueue after enqueue is filtered
            add_action('si_doc_enqueue_filtered', array( __CLASS__, 'enqueue' ));
        } else { // enqueue normal
            add_action('wp_enqueue_scripts', array( __CLASS__, 'enqueue' ));
        }

    }

    public static function public_name()
    {
        return __('Debit & Credit Card', 'sprout-invoices');
    }

    public static function checkout_options()
    {
        $option = array(
            'icons' => array(
                SI_URL . '/resources/front-end/img/visa.png',
                SI_URL . '/resources/front-end/img/mastercard.png',
                SI_URL . '/resources/front-end/img/amex.png',
                SI_URL . '/resources/front-end/img/discover.png',
                ),
            'label' => __('Debit & Credit Card', 'sprout-invoices'),
            'accepted_cards' => array(
                'visa',
                'mastercard',
                'amex',
                'verve',
                'discover',
                ),
            );
        if (self::$payment_modal) {
            $option['purchase_button_callback'] = array( __CLASS__, 'payment_button' );
        }
        return $option;
    }

    protected function __construct()
    {
        parent::__construct();
        self::$api_mode = get_option(self::API_MODE_OPTION, self::MODE_TEST);
        self::$payment_modal = get_option(self::MODAL_JS_OPTION, true);
        self::$disable_payflexi_js = get_option(self::DISABLE_JS_OPTION, false);
        self::$currency_code = get_option(self::CURRENCY_CODE_OPTION, 'USD');
        self::$payment_gateway = get_option(self::PAYMENT_GATEWAY_OPTION, 'stripe');

        self::$api_secret_key = get_option(self::API_SECRET_KEY_OPTION, '');
        self::$api_pub_key = get_option(self::API_PUB_KEY_OPTION, '');
        self::$api_secret_key_test = get_option(self::API_SECRET_KEY_TEST_OPTION, '');
        self::$api_pub_key_test = get_option(self::API_PUB_KEY_TEST_OPTION, '');

        // Remove pages
        add_filter('si_checkout_pages', array( $this, 'remove_checkout_pages' ));

        if (! self::$disable_payflexi_js) {
            add_filter('si_valid_process_payment_page_fields', '__return_false');
        }

        add_action('rest_api_init', function () {
            register_rest_route( 'sprout/invoices/', '/payflexi/webhook', array(
              'methods'  => 'POST',
              'callback' => array( __CLASS__, 'process_webhooks'),
            ));
        });

        add_action( 'checkout_completed', array( $this, 'post_checkout_redirect' ), 10, 2 );
    }

    /**
     * The review page is unnecessary
     *
     * @param  array $pages
     * @return array
     */
    public function remove_checkout_pages($pages)
    {
        unset($pages[ SI_Checkouts::REVIEW_PAGE ]);
        return $pages;
    }

    /**
     * Hooked on init add the settings page and options.
     */
    public static function register_settings()
    {
        // Settings
        $settings['payments'] = array(
            'si_payflexi_settings' => array(
                'title' => __('PayFlexi Settings', 'sprout-invoices'),
                'weight' => 200,
                'settings' => array(
                    self::PAYMENT_GATEWAY_OPTION => array(
                        'label' => __('Enabled Payment Gateway', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$payment_gateway,
                            'description' => __( 'This should correspond with your configured gateway on your PayFlexi merchant dashboard. Currently e.g, "stripe", "paystack", "flutterwave"' , 'sprout-invoices' ),
                            ),
                        ),
                    self::API_MODE_OPTION => array(
                        'label' => __('Mode', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'radios',
                            'options' => array(
                                self::MODE_LIVE => __('Live', 'sprout-invoices'),
                                self::MODE_TEST => __('Test', 'sprout-invoices'),
                                ),
                            'default' => self::$api_mode,
                            ),
                        ),
                    self::API_SECRET_KEY_OPTION => array(
                        'label' => __('PayFlexi Live Secret Key', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$api_secret_key,
                            ),
                        ),
                    self::API_PUB_KEY_OPTION => array(
                        'label' => __('PayFlexi Live Public Key', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$api_pub_key,
                            ),
                        ),
                    self::API_SECRET_KEY_TEST_OPTION => array(
                        'label' => __('PayFlexi Test Secret Key', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$api_secret_key_test,
                            ),
                        ),
                    self::API_PUB_KEY_TEST_OPTION => array(
                        'label' => __('PayFlexi Test Public Key', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$api_pub_key_test,
                            ),
                        ),
                    self::CURRENCY_CODE_OPTION => array(
                        'label' => __('Currency Code', 'sprout-invoices'),
                        'option' => array(
                            'type' => 'text',
                            'default' => self::$currency_code,
                            'attributes' => array( 'class' => 'small-text' ),
                            ),
                        ),
                    'webhook' => array(
                        'label' => __( 'Webhook URL' , 'sprout-invoices' ),
                        'option' => array(
                            'type' => 'text',
                            'default' => site_url('', 'https') . '/wp-json/sprout/invoices/payflexi/webhook',
                            'description' => __( 'Please copy the webhook URL above and add it to your PayFlexi merchant dashboard in the API settings page.' , 'sprout-invoices' ),
                            ),
                        ),
                    ),
                ),
            );
        return $settings;
    }

    ///////////////////
    // Payment Modal //
    ///////////////////

    public static function payment_button($invoice_id = 0)
    {
        if (! $invoice_id) {
            $invoice_id = get_the_id();
        }
        $invoice = SI_Invoice::get_instance($invoice_id);

        // print_r($invoice);
        $user = si_who_is_paying($invoice);
        $user_email = ($user) ? $user->user_email : '' ;

        $key = (self::$api_mode === self::MODE_TEST) ? self::$api_pub_key_test : self::$api_pub_key ;
        
        $payment_amount = (si_has_invoice_deposit($invoice->get_id())) ? $invoice->get_deposit() : $invoice->get_balance();

        $checkout_form_url =  add_query_arg( array( SI_Checkouts::CHECKOUT_ACTION => SI_Checkouts::PAYMENT_PAGE ), si_get_credit_card_checkout_form_action());
        $checkout_input_name = SI_Checkouts::CHECKOUT_ACTION;
        $checkout_input_value = SI_Checkouts::PAYMENT_PAGE;

        $data_attributes = array(
                'key' => $key,
                'name' => get_bloginfo( 'name' ),
                'email' => $user_email,
                'gateway' => self::$payment_gateway,
                'currency' => self::get_currency_code($invoice_id),
                'amount' => $payment_amount,
                'ref' => $invoice_id.'_'.time(),
                'description' => $invoice->get_title(),
                'invoice_id' => $invoice_id,
                'checkout_form_url' => $checkout_form_url,
                'checkout_input_name' => $checkout_input_name,
                'checkout_input_value' => $checkout_input_value
            );

        $data_attributes = apply_filters('si_payflexi_js_data_attributes', $data_attributes, $invoice_id); ?>
        
        <?php

        if ( ! in_array( $invoice->get_status(), array(SI_Invoice::STATUS_PARTIAL, SI_Invoice::STATUS_PAID ) ) ) {

            if ( '' !== $key ) {
                ?>
                    <?php printf( '<button id="payflexi_payment_button" class="button"><span>%s</span></button>', __( 'PayFlexi (Pay in Instalments) ' , 'sprout-invoices' ) ) ?>
                    <style type="text/css">
                        #payment_selection.dropdown #plaid.payment_option {
                            display: block;
                            clear: both;
                            min-height: 45px;
                        }
                        #payflexi_payment_button.button {
                            float: right;
                            margin-right: 15px;
                        }
                    </style>
                <?php
            }

        }
        
        echo '<script type="text/javascript" src="https://payflexi.co/js/v1/global-payflexi.js"></script>';
		echo '<script type="text/javascript" src="' . SI_ADDON_PAYFLEXI_URL . '/resources/js/si-payflexi.jquery.js"></script>';

        // Enqueue scripts
        wp_localize_script( 'si-payflexi-js', 'si_payflexi_js_object', apply_filters( 'si_payflexi_js_object_localization', $data_attributes ) );
            
        ?>

			<script type="text/javascript">
				/* <![CDATA[ */
				var si_payflexi_js_object = <?php echo wp_json_encode( $data_attributes ); ?>;
				/* ]]> */
			</script>
     
        <?php
    }


    public function process_payment(SI_Checkouts $checkout, SI_Invoice $invoice)
    {
        $reference = $_POST['reference'];
        $key = (self::$api_mode === self::MODE_TEST) ? self::$api_secret_key_test : self::$api_secret_key ;
        $payflexi_url = 'https://api.payflexi.co/merchants/transactions/' . sanitize_text_field($reference);
        $headers = array(
            'Authorization' => 'Bearer ' . $key,
        );
        
        $args = array(
            'sslverify' => false, //Set to true on production
            'headers'    => $headers,
            'timeout'    => 60,
        );

        $request = wp_remote_get($payflexi_url, $args);

        if (! is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request)) {
          
            $payflexi_response = json_decode(wp_remote_retrieve_body($request));
            
            if (!$payflexi_response->errors) {
                $payflexi_transaction_reference = $payflexi_response->data->reference;
                $invoice_amount = $payflexi_response->data->amount ? $payflexi_response->data->amount : 0;
                $amount_paid  = $payflexi_response->data->txn_amount ? $payflexi_response->data->txn_amount : 0;

                // create new payment
                $payment_id = SI_Payment::new_payment(
                    array(
                    'payment_method' => self::get_payment_method(),
                    'invoice' => $invoice->get_id(),
                    'amount' => $amount_paid,
                    'data' => array(
                        'Status' => 'Successful',
                        'Transaction Reference' => $reference,
                     ),
                    ),
                    SI_Payment::STATUS_AUTHORIZED
                );
                if (! $payment_id) {
                    return false;
                }

                add_post_meta($invoice->get_id(), '_sprout_payflexi_transaction_reference', $payflexi_transaction_reference, true );

                $payment = SI_Payment::get_instance($payment_id);
                do_action('payment_authorized', $payment);
                $payment->set_status(SI_Payment::STATUS_COMPLETE);
                do_action('payment_complete', $payment);

                return $payment;
            }
        }
    }

	public function post_checkout_redirect( SI_Checkouts $checkout, SI_Payment $payment ) {
		if ( ! is_a( $checkout->get_processor(), __CLASS__ ) ) {
			return;
		}
		$access_code = ( isset( $_REQUEST['key'] ) ) ? $_REQUEST['key'] : '' ;

		wp_redirect( add_query_arg( array( 'key' => $access_code ), $checkout->checkout_confirmation_url( self::PAYMENT_SLUG ) ) );
		exit();
	}
    /**
     * Process Webhook
    */
    public static function process_webhooks()
    {
 
        if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') || ! array_key_exists('HTTP_X_PAYFLEXI_SIGNATURE', $_SERVER)) {
            exit;
        }

        $json = file_get_contents('php://input');

        $secret_key = (self::$api_mode === self::MODE_TEST) ? self::$api_secret_key_test : self::$api_secret_key ;

        // validate event do all at once to avoid timing attack
        if ($_SERVER['HTTP_X_PAYFLEXI_SIGNATURE'] !== hash_hmac('sha512', $json, $secret_key)) {
            exit;
        }

        $event = json_decode($json);

        if ('transaction.approved' == $event->event && 'approved' == $event->data->status) {
            
            http_response_code(200);

            $payflexi_transaction_reference = $event->data->reference;
			$payflexi_initial_transaction_reference = $event->data->initial_reference;

            $invoice_details = explode( '_', $payflexi_initial_transaction_reference);
            $invoice_id = (int) $invoice_details[0];

            $invoice = SI_Invoice::get_instance($invoice_id);

            if ($invoice->get_status() === SI_Invoice::STATUS_PAID) {
                exit;
            }

            $saved_payflexi_transaction_reference = get_post_meta($invoice_id, '_sprout_payflexi_transaction_reference', true);
           
            ray(['Saved Ref' => $saved_payflexi_transaction_reference]);
            
            if((!$saved_payflexi_transaction_reference && $payflexi_transaction_reference === $payflexi_initial_transaction_reference) || $payflexi_transaction_reference  !== $payflexi_initial_transaction_reference){
                
                $transaction_amount  = $event->data->txn_amount;

                $payment_id = SI_Payment::new_payment(
                    array(
                    'payment_method' => self::PAYMENT_METHOD,
                    'invoice' => $invoice->get_id(),
                    'amount' => $transaction_amount,
                    'data' => array(
                        'Status' => 'Successful',
                        'Transaction Reference' => $payflexi_transaction_reference,
                     ),
                    ),
                    SI_Payment::STATUS_AUTHORIZED
                );

                if (! $payment_id) {
                    return false;
                }

                $payment = SI_Payment::get_instance($payment_id);

                do_action('payment_authorized', $payment);
                $payment->set_status(SI_Payment::STATUS_COMPLETE);
                do_action('payment_complete', $payment);
    
                return $payment;

                exit;

            }          
        }

        exit;
    }

    public static function set_token($token)
    {
        global $blog_id;
        update_user_meta(get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, $token);
    }

    public static function unset_token()
    {
        global $blog_id;
        delete_user_meta(get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY);
    }

    public static function get_token()
    {
        if (isset($_REQUEST['token']) && $_REQUEST['token']) {
            return $_REQUEST['token'];
        }
        global $blog_id;
        return get_user_meta(get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, true);
    }

    private static function get_currency_code($invoice_id)
    {
        return apply_filters('si_currency_code', self::$currency_code, $invoice_id, self::PAYMENT_METHOD);
    }
    /**
     * Grabs error messages from a PayFlexi response and displays them to the user
     *
     * @param  array $response
     * @param  bool  $display
     * @return void
     */
    private function set_error_messages($message, $display = true)
    {
        if ($display) {
            self::set_message($message, self::MESSAGE_STATUS_ERROR);
        } else {
            do_action('si_error', __CLASS__ . '::' . __FUNCTION__ . ' - error message from payflexi', $message);
        }
    }
}
SI_Payflexi::register();
