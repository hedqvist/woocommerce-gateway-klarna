<?php
/**
 * Klarna checkout class
 *
 * @link http://www.woothemes.com/products/klarna/
 * @since 1.0.0
 *
 * @package WC_Gateway_Klarna
 */


class WC_Gateway_Klarna_Checkout extends WC_Gateway_Klarna {
			
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() { 

		global $woocommerce;

		parent::__construct();

		$this->id           = 'klarna_checkout';
		$this->method_title = __( 'Klarna Checkout', 'klarna' );
		$this->has_fields   = false;

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		include( KLARNA_DIR . 'includes/variables-checkout.php' );

		// Helper class
		include_once( KLARNA_DIR . 'classes/class-klarna-helper.php' );
		$this->klarna_helper = new WC_Gateway_Klarna_Helper( $this );
		
		// Define Klarna object
		require_once( KLARNA_LIB . 'Klarna.php' );

		// Test mode or Live mode		
		if ( $this->testmode == 'yes' ) {
			// Disable SSL if in testmode
			$this->klarna_ssl = 'false';
			$this->klarna_mode = Klarna::BETA;
		} else {
			// Set SSL if used in webshop
			if ( is_ssl() ) {
				$this->klarna_ssl = 'true';
			} else {
				$this->klarna_ssl = 'false';
			}
			$this->klarna_mode = Klarna::LIVE;
		}

		// Actions
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_wc_gateway_klarna_checkout', array( $this, 'check_checkout_listener' ) );
		
		// Register custom order status for incomplete Klarna orders
		add_action( 'init', array( $this, 'register_order_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_order_status' ) );
		add_action( 'wp_print_scripts', array( $this, 'custom_status_icon' ) );
			
		// We execute the woocommerce_thankyou hook when the KCO Thank You page is rendered,
		// because other plugins use this, but we don't want to display the actual WC Order
		// details table in KCO Thank You page. This action is removed here, but only when
		// in Klarna Thank You page.
		if ( is_page() ) {
			global $post;
			$klarna_checkout_page_id = url_to_postid( $this->klarna_checkout_thanks_url );
			if ( $post->ID == $klarna_checkout_page_id ) {
				remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
			}
		}
			
		// Ajax
		add_action( 'wp_ajax_customer_update_kco_order_note', array( $this, 'customer_update_kco_order_note' ) );
		add_action( 'wp_ajax_nopriv_customer_update_kco_order_note', array( $this, 'customer_update_kco_order_note' ) );
		add_action( 'wp_footer', array( $this, 'js_order_note' ) );
		add_action( 'wp_footer', array( $this, 'ajaxurl'));

		add_action( 'init', array( $this, 'klarna_checkout_enqueuer' ) );

		// Checkout Page Coupons
		add_shortcode( 'woocommerce_klarna_coupons', array( $this, 'klarna_checkout_coupons') );
		add_action( 'wp_ajax_klarna_checkout_coupons_callback', array( $this, 'klarna_checkout_coupons_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_coupons_callback', array( $this, 'klarna_checkout_coupons_callback' ) );
		add_action( 'wp_ajax_klarna_checkout_remove_coupon_callback', array( $this, 'klarna_checkout_remove_coupon_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_remove_coupon_callback', array( $this, 'klarna_checkout_remove_coupon_callback' ) );

		// Checkout Page Cart
		add_shortcode( 'woocommerce_klarna_cart', array( $this, 'klarna_checkout_cart') );
		add_action( 'wp_ajax_klarna_checkout_cart_callback', array( $this, 'klarna_checkout_cart_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_cart_callback', array( $this, 'klarna_checkout_cart_callback' ) );

		// Checkout Page Shipping
		add_shortcode( 'woocommerce_klarna_shipping', array( $this, 'klarna_checkout_shipping') );
		add_action( 'wp_ajax_klarna_checkout_shipping_callback', array( $this, 'klarna_checkout_shipping_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_shipping_callback', array( $this, 'klarna_checkout_shipping_callback' ) );

		// Checkout Page Order Note
		add_shortcode( 'woocommerce_klarna_order_note', array( $this, 'klarna_checkout_order_note') );
		add_action( 'wp_ajax_klarna_checkout_order_note_callback', array( $this, 'klarna_checkout_order_note_callback' ) );
		add_action( 'wp_ajax_nopriv_klarna_checkout_order_note_callback', array( $this, 'klarna_checkout_order_note_callback' ) );

    }



	/**
	 * Enqueue Klarna Checkout javascript.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_enqueuer() {
		
		wp_register_script( 'klarna_checkout', KLARNA_URL . 'js/klarna-checkout.js' );
		wp_localize_script( 'klarna_checkout', 'kcoAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'klarna_checkout_nonce' => wp_create_nonce( 'klarna_checkout_nonce' ) ) );        
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'klarna_checkout' );
	
	}
	
	
	/**
	 * Register new order status.
	 * 
	 * @since  2.0
	 **/
	function register_order_status() {
		
		register_post_status( 'wc-kco-incomplete', array(
			'label'                     => __( 'Klarna Checkout Incomplete', 'klarna' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Klarna Checkout Incomplete <span class="count">(%s)</span>', 'Klarna Checkout incomplete <span class="count">(%s)</span>', 'klarna' )
		) );
		
	}


	/**
	 * Add new order status to wc_order_statuses.
	 * 
	 * $param   array  $order_statuses
	 * $return  array  $order_statuses
	 *
	 * @since  2.0
	 **/
	function add_order_status( $order_statuses ) {
		
		$order_statuses['wc-kco-incomplete'] = __( 'Klarna Checkout Incomplete', 'klarna' );
	
		return $order_statuses;
		
	}


	/**
	 * Show custom order status icon.
	 * 
	 * @since  2.0
	 **/
	function custom_status_icon() {
		
		if ( ! is_admin() )
			return; 
		
		?>
		<style>
			/* Add custom status order icons */
			.widefat .column-order_status mark.kco-incomplete:after {
				line-height: 1;
				margin: 0;
				position: absolute;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				text-align: center;
				background: url(/wp-content/plugins/woocommerce-gateway-klarna/images/klarna_18.png);
				content: " ";
				display: block;
			}
		</style>
		<?php	
	
	}


	/**
	 * Klarna Checkout cart shortcode callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_cart() {

		ob_start();
		if ( sizeof( WC()->cart->get_cart() ) > 0 ) {
			echo '<div class="woocommerce">';
			echo '<table class="shop_table cart" cellspacing="0">';
				echo '<thead>';
					echo '<tr>';
						echo '<th class="product-remove">&nbsp;</th>';
						echo '<th class="product-name">Product</th>';
						echo '<th class="product-price">Price</th>';
						echo '<th class="product-quantity">Quantity</th>';
						echo '<th class="product-subtotal">Total</th>';
					echo '</tr>';
				echo '</thead>';
				echo '<tbody>';
				foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
					$_product = $cart_item['data'];
					$cart_item_product = wc_get_product( $cart_item['product_id'] );
					echo '<tr class="cart_item">';
						echo '<td class="product-remove">&nbsp;</td>';
						echo '<td class="product-name">';
							if ( ! $_product->is_visible() ) {
								echo apply_filters( 'woocommerce_cart_item_name', $_product->get_title(), $cart_item, $cart_item_key ) . '&nbsp;';
							} else { 
								echo apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s </a>', $_product->get_permalink( $cart_item ), $_product->get_title() ), $cart_item, $cart_item_key );
							}
							// Meta data
							echo WC()->cart->get_item_data( $cart_item );
						echo '</td>';
						echo '<td class="product-price">';
							echo apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
						echo '</td>';
						echo '<td class="product-quantity" data-cart_item_key="' . $cart_item_key .'">';
							if ( $_product->is_sold_individually() ) {
								$product_quantity = sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', $cart_item_key );
							} else {
								$product_quantity = woocommerce_quantity_input( array(
									'input_name'  => "cart[{$cart_item_key}][qty]",
									'input_value' => $cart_item['quantity'],
									'max_value'   => $_product->backorders_allowed() ? '' : $_product->get_stock_quantity(),
									'min_value'   => '1'
								), $_product, false );
							}
							echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key );
						echo '</td>';
						echo '<td class="product-subtotal">';
							echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
						echo '</td>';
					echo '</tr>';
				}
				echo '</tbody>';
			echo '</table>';
			echo '</div>';
		}
		return ob_get_clean();

	}
	

	/**
	 * Klarna Checkout cart AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_cart_callback() {

		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}
		
		$updated_item_key = $_REQUEST['cart_item_key'];
		$new_quantity = $_REQUEST['new_quantity'];
		
		WC()->cart->set_quantity( $updated_item_key, $new_quantity );
		
		$cart_items = WC()->cart->get_cart();
		$updated_item = $cart_items[ $updated_item_key ];
		$updated_product = wc_get_product( $updated_item['product_id'] );
		$data['updated_line_total'] = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $updated_product, $updated_item['quantity'] ), $updated_item, $updated_item_key );

		if ( array_key_exists( 'klarna_checkout', $_SESSION ) ) {
			$sharedSecret = $this->klarna_secret;
			require_once( KLARNA_DIR . '/src/Klarna/Checkout.php' );
			Klarna_Checkout_Order::$baseUri = $this->klarna_server;
			Klarna_Checkout_Order::$contentType = 'application/vnd.klarna.checkout.aggregated-order-v2+json';
			$connector = Klarna_Checkout_Connector::create( $sharedSecret );

			// Resume session
			$klarna_order = new Klarna_Checkout_Order(
				$connector,
				$_SESSION['klarna_checkout']
			);

			$klarna_order->fetch();
			
			$data['klarna_order'] = $_SESSION['klarna_checkout'];

			$cart = $this->cart_to_klarna();

			// Reset cart
			$update['cart']['items'] = array();
			foreach ( $cart as $item ) {
				$update['cart']['items'][] = $item;
			}
			
			$klarna_order->update( $update );
				
		}
		wp_send_json_success( $data );

		wp_die();
		
	}
	
	
	/**
	 * Klarna Checkout coupons shortcode callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_coupons() {
	
		if ( WC()->cart->coupons_enabled() ) {
	
			WC()->cart->calculate_totals();

			ob_start();
				echo '<div class="klarna-checkout-coupons woocommerce">';
					echo '<ul id="klarna-checkout-applied-coupons">';
						foreach ( WC()->cart->get_applied_coupons() as $coupon ) {
							echo '<li>';
							echo '<strong>' . $coupon . ':</strong> ';
							echo wc_price( WC()->cart->get_coupon_discount_amount( $coupon, WC()->cart->display_cart_ex_tax ) );
							echo ' <a class="klarna-checkout-remove-coupon" data-coupon="' . $coupon . '" href="#">(Remove)</a>';
							echo '</li>';
						}
					echo '</ul>';
					echo '<div class="klarna-checkout-coupons-form"></div>';	
				echo '</div>';
			return ob_get_clean();
		
		}

	}


	/**
	 * Klarna Checkout coupons AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_coupons_callback() {

		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		$data = array();
		
		// Adding coupon
		if ( isset( $_REQUEST['coupon'] ) && is_string( $_REQUEST['coupon'] ) ) {
			
			$coupon = $_REQUEST['coupon'];
			$coupon_success = WC()->cart->add_discount( $coupon );
			$applied_coupons = WC()->cart->applied_coupons;
			WC()->session->set( 'applied_coupons', $applied_coupons );
			WC()->cart->calculate_totals();
			wc_clear_notices(); // This notice handled by Klarna plugin	
			
			$coupon_object = new WC_Coupon( $coupon );
	
			$amount = wc_price( WC()->cart->get_coupon_discount_amount( $coupon, WC()->cart->display_cart_ex_tax ) );
			$data['amount'] = $amount;
				
			$data['coupon_success'] = $coupon_success;
			$data['coupon'] = $coupon;
	
			if ( array_key_exists( 'klarna_checkout', $_SESSION ) ) {
				
				$sharedSecret = $this->klarna_secret;
				require_once( KLARNA_DIR . '/src/Klarna/Checkout.php' );
				Klarna_Checkout_Order::$baseUri = $this->klarna_server;
				Klarna_Checkout_Order::$contentType = 'application/vnd.klarna.checkout.aggregated-order-v2+json';
				$connector = Klarna_Checkout_Connector::create( $sharedSecret );
	
				// Resume session
				$klarna_order = new Klarna_Checkout_Order(
					$connector,
					$_SESSION['klarna_checkout']
				);
	
				$klarna_order->fetch();
				
				$data['klarna_order'] = $_SESSION['klarna_checkout'];
	
				$cart = $this->cart_to_klarna();
	
				// Reset cart
				$update['cart']['items'] = array();
				foreach ( $cart as $item ) {
					$update['cart']['items'][] = $item;
				}
				
				$klarna_order->update( $update );
				
			}
			
		}
		
		wp_send_json_success( $data );

		wp_die();
	
	}


	/**
	 * Klarna Checkout coupons AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_remove_coupon_callback() {

		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		$data = array();
		
		// Removing coupon
		if ( isset( $_REQUEST['remove_coupon'] ) ) {
			
			$remove_coupon = $_REQUEST['remove_coupon'];
			
			WC()->cart->remove_coupon( $remove_coupon );
			$applied_coupons = WC()->cart->applied_coupons;
			WC()->session->set( 'applied_coupons', $applied_coupons );
			WC()->cart->calculate_totals();
			wc_clear_notices(); // This notice handled by Klarna plugin	

			if ( array_key_exists( 'klarna_checkout', $_SESSION ) ) {
				
				$sharedSecret = $this->klarna_secret;
				require_once( KLARNA_DIR . '/src/Klarna/Checkout.php' );
				Klarna_Checkout_Order::$baseUri = $this->klarna_server;
				Klarna_Checkout_Order::$contentType = 'application/vnd.klarna.checkout.aggregated-order-v2+json';
				$connector = Klarna_Checkout_Connector::create( $sharedSecret );
	
				// Resume session
				$klarna_order = new Klarna_Checkout_Order(
					$connector,
					$_SESSION['klarna_checkout']
				);
	
				$klarna_order->fetch();
				
				$data['klarna_order'] = $_SESSION['klarna_checkout'];
	
				$cart = $this->cart_to_klarna();
	
				// Reset cart
				$update['cart']['items'] = array();
				foreach ( $cart as $item ) {
					$update['cart']['items'][] = $item;
				}
				
				$klarna_order->update( $update );
				
			}
					
		}
		
		wp_send_json_success( $data );

		wp_die();
	
	}
	
	
	/**
	 * Klarna Checkout coupons shortcode callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_shipping() {
	
		ob_start();

		echo '<div id="klarna-checkout-shipping" class="woocommerce">';
			WC()->cart->calculate_totals();
			wc_cart_totals_shipping_html();
		echo '</div>';

		return ob_get_clean();

	}


	/**
	 * Klarna Checkout shipping AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_shipping_callback() {

		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		$new_method = $_REQUEST['new_method'];
		$chosen_shipping_methods[] = wc_clean( $new_method );
		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
		WC()->cart->calculate_totals();

		if ( array_key_exists( 'klarna_checkout', $_SESSION ) ) {
			$sharedSecret = $this->klarna_secret;
			require_once( KLARNA_DIR . '/src/Klarna/Checkout.php' );
			Klarna_Checkout_Order::$baseUri = $this->klarna_server;
			Klarna_Checkout_Order::$contentType = 'application/vnd.klarna.checkout.aggregated-order-v2+json';
			$connector = Klarna_Checkout_Connector::create( $sharedSecret );

			// Resume session
			$klarna_order = new Klarna_Checkout_Order(
				$connector,
				$_SESSION['klarna_checkout']
			);

			$klarna_order->fetch();
			
			$data['klarna_order'] = $_SESSION['klarna_checkout'];

			$cart = $this->cart_to_klarna();

			// Reset cart
			$update['cart']['items'] = array();
			foreach ( $cart as $item ) {
				$update['cart']['items'][] = $item;
			}
			
			$klarna_order->update( $update );
				
		}
		wp_send_json_success( $data );

		wp_die();
		
	}	
	

	/**
	 * Klarna Checkout coupons shortcode callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_order_note() {

		ob_start(); ?>
			<div class="woocommerce">
				<form>
					<div class="form-row">
						<label for="klarna-checkout-order-note"><?php echo __( 'Add order note.', 'klarna' ); ?></label>
						<textarea id="klarna-checkout-order-note" class="input-text" name="klarna-checkout-order-note" placeholder="<?php __( 'Notes about your order, e.g. special notes for delivery.', 'klarna' ); ?>"></textarea>
					</div>
				</form>
			</div>
		<?php return ob_get_clean();

	}
	
	
	/**
	 * Klarna Checkout coupons AJAX callback.
	 * 
	 * @since  2.0
	 **/
	function klarna_checkout_order_note_callback() {

		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'klarna_checkout_nonce' ) ) {
			exit( 'Nonce can not be verified.' );
		}

		$data = array();
		
		// Adding coupon
		if ( isset( $_REQUEST['coupon'] ) && is_string( $_REQUEST['coupon'] ) ) {
			
			$coupon = $_REQUEST['coupon'];
			$coupon_success = WC()->cart->add_discount( $coupon );
			$applied_coupons = WC()->cart->applied_coupons;
			WC()->session->set( 'applied_coupons', $applied_coupons );
			WC()->cart->calculate_totals();
			wc_clear_notices(); // This notice handled by Klarna plugin	
			
			$coupon_object = new WC_Coupon( $coupon );
	
			$amount = wc_price( WC()->cart->get_coupon_discount_amount( $coupon, WC()->cart->display_cart_ex_tax ) );
			$data['amount'] = $amount;
				
			$data['coupon_success'] = $coupon_success;
			$data['coupon'] = $coupon;
	
			if ( array_key_exists( 'klarna_checkout', $_SESSION ) ) {
				
				$sharedSecret = $this->klarna_secret;
				require_once( KLARNA_DIR . '/src/Klarna/Checkout.php' );
				Klarna_Checkout_Order::$baseUri = $this->klarna_server;
				Klarna_Checkout_Order::$contentType = 'application/vnd.klarna.checkout.aggregated-order-v2+json';
				$connector = Klarna_Checkout_Connector::create( $sharedSecret );
	
				// Resume session
				$klarna_order = new Klarna_Checkout_Order(
					$connector,
					$_SESSION['klarna_checkout']
				);
	
				$klarna_order->fetch();
				
				$data['klarna_order'] = $_SESSION['klarna_checkout'];
	
				$cart = $this->cart_to_klarna();
	
				// Reset cart
				$update['cart']['items'] = array();
				foreach ( $cart as $item ) {
					$update['cart']['items'][] = $item;
				}
				
				$klarna_order->update( $update );
				
			}
			
		}
		
		wp_send_json_success( $data );

		wp_die();
	
	}


	/**
	 * WooCommerce cart to Klarna cart items.
	 *
	 * Helper functions that format WooCommerce cart items for Klarna order items.
	 * 
	 * @since  2.0
	 **/
	function cart_to_klarna() {
				
		global $woocommerce;
		
		/**
		 * Process cart contents
		 */
		if ( sizeof( $woocommerce->cart->get_cart() ) > 0 ) {
	
			foreach ( $woocommerce->cart->get_cart() as $cart_item ) {
	
				if ( $cart_item['quantity'] ) {
	
					$_product = wc_get_product( $cart_item['product_id'] );
	
					// We manually calculate the tax percentage here
					if ( $_product->is_taxable() && $cart_item['line_subtotal_tax'] > 0 ) {
						// Calculate tax percentage
						$item_tax_percentage = round( $cart_item['line_subtotal_tax'] / $cart_item['line_subtotal'], 2 ) * 100;
					} else {
						$item_tax_percentage = 00;
					}
	
					$cart_item_data = $cart_item['data'];
					$cart_item_name = $cart_item_data->post->post_title;
	
					if ( isset( $cart_item['item_meta'] ) ) {
						$item_meta = new WC_Order_Item_Meta( $cart_item['item_meta'] );
						if ( $meta = $item_meta->display( true, true ) ) {
							$item_name .= ' ( ' . $meta . ' )';
						}
					}
						
					// apply_filters to item price so we can filter this if needed
					$klarna_item_price_including_tax = $cart_item['line_subtotal'] + $cart_item['line_subtotal_tax'];
					$item_price = apply_filters( 'klarna_item_price_including_tax', $klarna_item_price_including_tax );
	
					// Get SKU or product id
					$reference = '';
					if ( $_product->get_sku() ) {
						$reference = $_product->get_sku();
					} elseif ( $_product->variation_id ) {
						$reference = $_product->variation_id;
					} else {
						$reference = $_product->id;
					}
	
					// Check if there's a discount applied
					if ( $cart_item['line_subtotal'] > $cart_item['line_total'] ) {
						$item_discount = round( 1 - ( $cart_item['line_total'] / $cart_item['line_subtotal'] ), 2 ) * 10000;
					} else {
						$item_discount = 0;
					}
	
					$item_price = number_format( $item_price * 100, 0, '', '' ) / $cart_item['quantity'];
					
					$cart[] = array(
						'reference'      => strval( $reference ),
						'name'           => strip_tags( $cart_item_name ),
						'quantity'       => (int) $cart_item['quantity'],
						'unit_price'     => (int) $item_price,
						'discount_rate'  => $item_discount,
						'tax_rate'       => intval( $item_tax_percentage . '00' )
					);
	
				} // End if qty
	
			} // End foreach
	
		} // End if sizeof get_items()
	
	
		/**
		 * Process shipping
		 */
		if ( $woocommerce->cart->shipping_total > 0 ) {
	
			// We manually calculate the tax percentage here
			if ( $woocommerce->cart->shipping_tax_total > 0 ) {
				// Calculate tax percentage
				$shipping_tax_percentage = round( $woocommerce->cart->shipping_tax_total / $woocommerce->cart->shipping_total, 2 ) * 100;
			} else {
				$shipping_tax_percentage = 00;
			}
	
			$shipping_price = number_format( ( $woocommerce->cart->shipping_total + $woocommerce->cart->shipping_tax_total ) * 100, 0, '', '' );
	
			// Get shipping method name				
			$shipping_packages = WC()->shipping->get_packages();
			foreach ( $shipping_packages as $i => $package ) {
				$chosen_method = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';
				if ( '' != $chosen_method ) {
				
					$package_rates = $package['rates'];
					foreach ( $package_rates as $rate_key => $rate_value ) {
						if ( $rate_key == $chosen_method ) {
							$klarna_shipping_method = $rate_value->label;
						}
					}
	
				}
	
			}
			if ( ! isset( $klarna_shipping_method ) ) {
				$klarna_shipping_method = __( 'Shipping', 'klarna' );
			}
	
			$cart[] = array(  
				'type'       => 'shipping_fee',
				'reference'  => 'SHIPPING',
				'name'       => $klarna_shipping_method,
				'quantity'   => 1,
				'unit_price' => (int) $shipping_price,
				'tax_rate'   => intval( $shipping_tax_percentage . '00' )
			);
	
		}
	
		/**
		 * Process discounts
		 */
		/*
		if ( ! empty( $woocommerce->cart->coupon_discount_amounts ) ) {
	
			$discount_amounts = $woocommerce->cart->coupon_discount_amounts;
			$discount_tax_amounts = $woocommerce->cart->coupon_discount_tax_amounts;
			
			foreach ( $discount_amounts as $code => $amount ) {
				$amount = (int) number_format( $amount, 2, '', '' );
				
				if ( isset( $discount_tax_amounts[ $code ] ) ) {
					// Calculate tax percentage
					$discount_tax_percentage = round( $discount_tax_amounts[ $code ] / $amount, 2 ) * 100;
				} else {
					$discount_tax_percentage = 00;
				}
		
				$cart[] = array(    
					'reference'   => 'DISCOUNT',  
					'name'        => $code,  
					'quantity'    => 1,  
					'unit_price'  => -$amount,  
					'tax_rate'    => $discount_tax_percentage 
				);
			}
	
		}
		*/
		
		return $cart;
		
	}



	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @since 1.0.0
	 */
	function init_form_fields() {
    
		$this->form_fields = include( KLARNA_DIR . 'includes/settings-checkout.php' );
    
	}
	
	
	
	/**
	 * Admin Panel Options 
	 *
	 * @since 1.0.0
	 */
	 public function admin_options() { ?>

		<h3><?php _e( 'Klarna Checkout', 'klarna' ); ?></h3>

		<p>
			<?php printf(__( 'With Klarna Checkout your customers can pay by invoice or credit card. Klarna Checkout works by replacing the standard WooCommerce checkout form. Documentation <a href="%s" target="_blank">can be found here</a>.', 'klarna'), 'http://wcdocs.woothemes.com/user-guide/extensions/klarna/' ); ?>
		</p>

		<?php
		// If the WooCommerce terms page isn't set, do nothing.
		$klarna_terms_page = get_option( 'woocommerce_terms_page_id' );
		if ( empty( $klarna_terms_page ) && empty( $this->terms_url ) ) {
			echo '<strong>' . __( 'You need to specify a Terms Page in the WooCommerce settings or in the Klarna Checkout settings in order to enable the Klarna Checkout payment method.', 'klarna' ) . '</strong>';
		}

		// Check if Curl is installed. If not - display message to the merchant about this.
		if( function_exists( 'curl_version' ) ) {
			// Do nothing
		} else {
			echo '<div id="message" class="error"><p>' . __( 'The PHP library cURL does not seem to be installed on your server. Klarna Checkout will not work without it.', 'klarna' ) . '</p></div>';
		}
		?>

		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table><!--/.form-table-->

	<?php }
			
		
	/**
	 * Disabled KCO on regular checkout page
	 *
	 * @since 1.0.0
	 */
	function is_available() {

		 return false;

	}


	/**
	 * Set up Klarna configuration.
	 * 
	 * @since  2.0
	 **/
	function configure_klarna( $klarna, $country ) {

		$klarna->config(
			$eid = $this->klarna_eid,
			$secret = $this->klarna_secret,
			$country = $this->klarna_country,
			$language = $this->klarna_language,
			$currency = $this->klarna_currency,
			$mode = $this->klarna_mode,
			$pcStorage = 'json',
			$pcURI = '/srv/pclasses.json',
			$ssl = $this->klarna_ssl,
			$candice = false
		);

	}

	
	/**
	 * Render checkout page
	 *
	 * @since 1.0.0
	 */
	function get_klarna_checkout_page() {
			
		global $woocommerce;
		global $current_user;
		get_currentuserinfo();

		// Debug
		if ( $this->debug=='yes' ) {
			$this->log->add( 'klarna', 'KCO page about to render...' );
		}

		require_once( KLARNA_DIR . '/src/Klarna/Checkout.php' );

		// Check if Klarna order exists, if it does display thank you page
		// otherwise display checkout page
		if ( isset( $_GET['klarna_order'] ) ) { // Display Order response/thank you page via iframe from Klarna

			ob_start();
			include( KLARNA_DIR . 'includes/checkout-thank-you-page.php' );
			return ob_get_clean();

		} else { // Display Checkout page

			ob_start();
			include( KLARNA_DIR . 'includes/checkout-page.php' );
			return ob_get_clean();

		} // End if isset($_GET['klarna_order'])

	} // End Function


	/**
     * Order confirmation via IPN
	 *
	 * @since 1.0.0
     */
	function check_checkout_listener() {

		/**
		 * Check if order is returned from Klarna
		 */
		if ( isset( $_GET['klarna_order'] ) ) {

			global $woocommerce;
			
			if ( $this->debug == 'yes' ) {
				$this->log->add( 'klarna', 'IPN callback from Klarna' );
				$this->log->add( 'klarna', 'Klarna order: ' . $_GET['klarna_order'] );
				$this->log->add( 'klarna', 'GET: ' . json_encode($_GET) );
			}
			
			/**
			 * Retrieve Klarna order
			 */
			require_once( KLARNA_DIR . '/src/Klarna/Checkout.php' );  
			Klarna_Checkout_Order::$contentType = "application/vnd.klarna.checkout.aggregated-order-v2+json";  
			
			switch ( $_GET['scountry'] ) {
				case 'SE':
					$klarna_secret = $this->secret_se;
					break;
				case 'FI' :
					$klarna_secret = $this->secret_fi;
					break;
				case 'NO' :
					$klarna_secret = $this->secret_no;
					break;
				case 'DE' :
					$klarna_secret = $this->secret_de;
					break;
				default:
					$klarna_secret = '';
			}

			if ( $this->debug == 'yes' ) {
				$this->log->add( 'klarna', 'Fetching Klarna order...' );
			}
				
			$connector    = Klarna_Checkout_Connector::create( $klarna_secret );  			
			$checkoutId   = $_GET['klarna_order'];  
			$klarna_order = new Klarna_Checkout_Order( $connector, $checkoutId );  
			$klarna_order->fetch();

			if ( $this->debug == 'yes' ) {
				$this->log->add( 'klarna', 'ID: ' . $klarna_order['id'] );
				$this->log->add( 'klarna', 'Billing: ' . $klarna_order['billing_address']['given_name'] );
				$this->log->add( 'klarna', 'Reference: ' . $klarna_order['reservation'] );
				$this->log->add( 'klarna', 'Fetched order from Klarna: ' . var_export( $klarna_order, true ) );
			}

			if ( $klarna_order['status'] == 'checkout_complete' ) { 

				// Create order in WooCommerce
				$this->log->add( 'klarna', 'Creating local order...' );
				$order = $this->create_order( $klarna_order );
				$order_id = $order->id;
				$this->log->add( 'klarna', 'Local order created, order ID: ' . $order_id );

				// Add order items
				$this->log->add( 'klarna', 'Adding order items...' );
				$this->add_order_items( $order, $klarna_order );
				
				// Store addresses
				$this->log->add( 'klarna', 'Adding order fees...' );
				$this->store_fees( $order, $klarna_order );

				// Store addresses
				$this->log->add( 'klarna', 'Adding order shipping info...' );
				$this->store_shipping( $order, $klarna_order );				
				
				// Store addresses
				$this->log->add( 'klarna', 'Adding order addresses...' );
				$this->store_addresses( $order, $klarna_order );

				// Store addresses
				$this->log->add( 'klarna', 'Adding order tax...' );
				$this->store_tax_rows( $order, $klarna_order );

				// Store addresses
				$this->log->add( 'klarna', 'Adding order coupons...' );
				$this->store_coupons( $order, $klarna_order );

				// Store addresses
				$this->log->add( 'klarna', 'Adding order payment method...' );
				$this->store_payment_method( $order, $klarna_order );
						
				// Let plugins add meta
				do_action( 'woocommerce_checkout_update_order_meta', $order_id, array() );


				// Check if Klarna order needs to be updated
				$this->compare_orders( $order, $klarna_order );
				
				
				// Store user id in order so the user can keep track of track it in My account
				if ( email_exists( $klarna_order['billing_address']['email'] ) ) {
					
					if ( $this->debug == 'yes' ) {
						$this->log->add( 'klarna', 'Billing email: ' . $klarna_order['billing_address']['email'] );
					}
				
					$user = get_user_by('email', $klarna_order['billing_address']['email']);
					
					if ( $this->debug == 'yes' ) {
						$this->log->add( 'klarna', 'Customer User ID: ' . $user->ID );
					}
						
					$this->customer_id = $user->ID;
					
					update_post_meta( $order_id, '_customer_user', $this->customer_id );
				
				} else {
					
					// Create new user
					if ( $this->create_customer_account == 'yes' ) {
												
						$password = '';
						$new_customer = $this->create_new_customer( $klarna_order['billing_address']['email'], $klarna_order['billing_address']['email'], $password );
						$order->add_order_note( sprintf( __( 'New customer created (user ID %s).', 'klarna' ), $new_customer, $klarna_order['id'] ) );

						
						if ( is_wp_error( $new_customer ) ) { // Creation failed

							$order->add_order_note( sprintf( __( 'Customer creation failed. Error: %s.', 'klarna' ), $new_customer->get_error_message(), $klarna_order['id'] ) );
							$this->customer_id = 0;

						} else { // Creation succeeded

							$order->add_order_note( sprintf( __( 'New customer created (user ID %s).', 'klarna' ), $new_customer, $klarna_order['id'] ) );
							
							// Add customer billing address - retrieved from callback from Klarna
							update_user_meta( $new_customer, 'billing_first_name', $klarna_order['billing_address']['given_name'] );
							update_user_meta( $new_customer, 'billing_last_name', $klarna_order['billing_address']['family_name'] );
							update_user_meta( $new_customer, 'billing_address_1', $received__billing_address_1 );
							update_user_meta( $new_customer, 'billing_address_2', $klarna_order['billing_address']['care_of'] );
							update_user_meta( $new_customer, 'billing_postcode', $klarna_order['billing_address']['postal_code'] );
							update_user_meta( $new_customer, 'billing_city', $klarna_order['billing_address']['city'] );
							update_user_meta( $new_customer, 'billing_country', $klarna_order['billing_address']['country'] );
							update_user_meta( $new_customer, 'billing_email', $klarna_order['billing_address']['email'] );
							update_user_meta( $new_customer, 'billing_phone', $klarna_order['billing_address']['phone'] );
							
							// Add customer shipping address - retrieved from callback from Klarna
							$allow_separate_shipping = ( isset( $klarna_order['options']['allow_separate_shipping_address'] ) ) ? $klarna_order['options']['allow_separate_shipping_address'] : '';
							
							if ( $allow_separate_shipping == 'true' || $_GET['scountry'] == 'DE' ) {
								
								update_user_meta( $new_customer, 'shipping_first_name', $klarna_order['shipping_address']['given_name'] );
								update_user_meta( $new_customer, 'shipping_last_name', $klarna_order['shipping_address']['family_name'] );
								update_user_meta( $new_customer, 'shipping_address_1', $received__shipping_address_1 );
								update_user_meta( $new_customer, 'shipping_address_2', $klarna_order['shipping_address']['care_of'] );
								update_user_meta( $new_customer, 'shipping_postcode', $klarna_order['shipping_address']['postal_code'] );
								update_user_meta( $new_customer, 'shipping_city', $klarna_order['shipping_address']['city'] );
								update_user_meta( $new_customer, 'shipping_country', $klarna_order['shipping_address']['country'] );
							
							} else {
								
								update_user_meta( $new_customer, 'shipping_first_name', $klarna_order['billing_address']['given_name'] );
								update_user_meta( $new_customer, 'shipping_last_name', $klarna_order['billing_address']['family_name'] );
								update_user_meta( $new_customer, 'shipping_address_1', $received__billing_address_1 );
								update_user_meta( $new_customer, 'shipping_address_2', $klarna_order['billing_address']['care_of'] );
								update_user_meta( $new_customer, 'shipping_postcode', $klarna_order['billing_address']['postal_code'] );
								update_user_meta( $new_customer, 'shipping_city', $klarna_order['billing_address']['city'] );
								update_user_meta( $new_customer, 'shipping_country', $klarna_order['billing_address']['country'] );
							}


							$this->customer_id = $new_customer;
							
						}
					
						update_post_meta( $order_id, '_customer_user', $this->customer_id );

					}

				}
				
				$order->add_order_note( sprintf( 
					__( 'Klarna Checkout payment completed. Reservation number: %s.  Klarna order number: %s', 'klarna' ),
					$klarna_order['reservation'], 
					$klarna_order['id'] 
				) );

				
				// Update the order in Klarnas system
				$this->log->add( 'klarna', 'Updating Klarna order status to "created"...' );
				$update['status'] = 'created';
				$update['merchant_reference'] = array(  
					'orderid1' => ltrim( $order->get_order_number(), '#' )
				);
				$klarna_order->update( $update );
				
				// Check if order is not already completed or processing
				// To avoid triggering of multiple payment_complete() callbacks
				if ( $order->status == 'completed' || $order->status == 'processing' ) {
	        
					if ( $this->debug == 'yes' ) {
						$this->log->add( 'klarna', 'Aborting, Order #' . $order_id . ' is already complete.' );
					}
			        
			    } else { // Payment complete		    
			    	
			    	// Update order meta
			    	update_post_meta( $order_id, 'klarna_order_status', 'created' );
					update_post_meta( $order_id, '_klarna_order_reservation', $klarna_order['reservation'] );
					
					$order->payment_complete();
					// Debug
					if ( $this->debug == 'yes') {
						$this->log->add( 'klarna', 'Payment complete action triggered' );
					}
					
					// Empty cart
					$woocommerce->cart->empty_cart();
				
				}
				
				// Other plugins and themes can hook into here
				do_action( 'klarna_after_kco_push_notification', $order_id );
				
			}
		
		} // Endif klarnaListener == checkout
	
	} // End function check_checkout_listener
		

	/**
	 * Create new order
	 *
	 * @since 1.0.0
	 */
	public function compare_orders( $order, $klarna_order ) {

		// $order->add_order_note( 'WooCommerce order total - ' . $order->get_total() );
		// $order->add_order_note( 'Klarna order total - ' . $klarna_order['cart']['total_price_including_tax'] );
		
		/**
		 * Compare these two amounts and update Klarna order if necessary
		 */

	}

		
	/**
	 * Create new order
	 *
	 * @since 1.0.0
	 */
	public function create_order( $klarna_order ) {
		
		/* 
		$this->shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		if ( sizeof( $woocommerce->cart->get_cart() ) == 0 ) {
			wc_add_notice( sprintf( 
				__( 'Sorry, your session has expired. <a href="%s">Return to homepage &rarr;</a>', 'klarna' ), 
				home_url() 
			), 'error' );
		}
		*/
			
		// Customer accounts
		$this->customer_id = apply_filters( 'woocommerce_checkout_customer_id', get_current_user_id() );
		
		// Order data
		$order_data = array(
			'status'        => apply_filters( 'woocommerce_default_order_status', 'pending' ),
			'customer_id'   => $this->customer_id,
			'customer_note' => isset( $this->posted['order_comments'] ) ? $this->posted['order_comments'] : ''
		);


		// Create the order
		$order = wc_create_order( $order_data );

		if ( is_wp_error( $order ) ) {

			throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );

		} else {

			// $order_id = $order->id;
			// do_action( 'woocommerce_new_order', $order_id );

		}

		return $order;
	
	} // End function create_order()


	/**
	 * Adds items to order
	 *
	 * @since 1.0.0
	 */
	public function add_order_items( $order, $klarna_order ) {

		$klarna_transient = sanitize_key( $_GET['sid'] );
		$klarna_wc = get_transient( $klarna_transient );

		foreach ( $klarna_wc->cart->get_cart() as $cart_item_key => $values ) {
			$item_id = $order->add_product(
				$values['data'],
				$values['quantity'],
				array(
					'variation' => $values['variation'],
					'totals'    => array(
						'subtotal'     => $values['line_subtotal'],
						'subtotal_tax' => $values['line_subtotal_tax'],
						'total'        => $values['line_total'],
						'tax'          => $values['line_tax'],
						'tax_data'     => $values['line_tax_data'] // Since 2.2
					)
				)
			);

			if ( ! $item_id ) {
				throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
			}

			// Allow plugins to add order item meta
			do_action( 'woocommerce_add_order_item_meta', $item_id, $values, $cart_item_key );
		}

	}


	/**
	 * Adds addresses to order
	 *
	 * @since 1.0.0
	 */
	function store_addresses( $order, $klarna_order ) {
		
		$order_id = $order->id;
		
		// Different names on the returned street address if it's a German purchase or not
		$received__billing_address_1  = '';
		$received__shipping_address_1 = '';

		if ( $_GET['scountry'] == 'DE' ) {

			$received__billing_address_1 = $klarna_order['billing_address']['street_name'] . ' ' . $klarna_order['billing_address']['street_number'];
			$received__shipping_address_1 = $klarna_order['shipping_address']['street_name'] . ' ' . $klarna_order['shipping_address']['street_number'];					
		
		} else {
		
			$received__billing_address_1 	= $klarna_order['billing_address']['street_address'];
			$received__shipping_address_1 	= $klarna_order['shipping_address']['street_address'];
		
		}
			
		// Add customer billing address - retrieved from callback from Klarna
		update_post_meta( $order_id, '_billing_first_name', $klarna_order['billing_address']['given_name'] );
		update_post_meta( $order_id, '_billing_last_name', $klarna_order['billing_address']['family_name'] );
		update_post_meta( $order_id, '_billing_address_1', $received__billing_address_1 );
		update_post_meta( $order_id, '_billing_address_2', $klarna_order['billing_address']['care_of'] );
		update_post_meta( $order_id, '_billing_postcode', $klarna_order['billing_address']['postal_code'] );
		update_post_meta( $order_id, '_billing_city', $klarna_order['billing_address']['city'] );
		update_post_meta( $order_id, '_billing_country', $klarna_order['billing_address']['country'] );
		update_post_meta( $order_id, '_billing_email', $klarna_order['billing_address']['email'] );
		update_post_meta( $order_id, '_billing_phone', $klarna_order['billing_address']['phone'] );
		
		// Add customer shipping address - retrieved from callback from Klarna
		$allow_separate_shipping = ( isset( $klarna_order['options']['allow_separate_shipping_address'] ) ) ? $klarna_order['options']['allow_separate_shipping_address'] : '';
		
		if ( $allow_separate_shipping == 'true' || $_GET['scountry'] == 'DE' ) {
			
			update_post_meta( $order_id, '_shipping_first_name', $klarna_order['shipping_address']['given_name'] );
			update_post_meta( $order_id, '_shipping_last_name', $klarna_order['shipping_address']['family_name'] );
			update_post_meta( $order_id, '_shipping_address_1', $received__shipping_address_1 );
			update_post_meta( $order_id, '_shipping_address_2', $klarna_order['shipping_address']['care_of'] );
			update_post_meta( $order_id, '_shipping_postcode', $klarna_order['shipping_address']['postal_code'] );
			update_post_meta( $order_id, '_shipping_city', $klarna_order['shipping_address']['city'] );
			update_post_meta( $order_id, '_shipping_country', $klarna_order['shipping_address']['country'] );
		
		} else {
			
			update_post_meta( $order_id, '_shipping_first_name', $klarna_order['billing_address']['given_name'] );
			update_post_meta( $order_id, '_shipping_last_name', $klarna_order['billing_address']['family_name'] );
			update_post_meta( $order_id, '_shipping_address_1', $received__billing_address_1 );
			update_post_meta( $order_id, '_shipping_address_2', $klarna_order['billing_address']['care_of'] );
			update_post_meta( $order_id, '_shipping_postcode', $klarna_order['billing_address']['postal_code'] );
			update_post_meta( $order_id, '_shipping_city', $klarna_order['billing_address']['city'] );
			update_post_meta( $order_id, '_shipping_country', $klarna_order['billing_address']['country'] );
		}
		
	}


	/**
	 * Adds fees to order
	 *
	 * @since 1.0.0
	 */
	function store_fees( $order, $klarna_order ) {

		$klarna_transient = sanitize_key( $_GET['sid'] );
		$klarna_wc = get_transient( $klarna_transient );
		
		foreach ( $klarna_wc->cart->get_fees() as $fee_key => $fee ) {
			$item_id = $order->add_fee( $fee );

			if ( ! $item_id ) {
				throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
			}

			// Allow plugins to add order item meta to fees
			do_action( 'woocommerce_add_order_fee_meta', $order_id, $item_id, $fee, $fee_key );
		}
		
	}


	/**
	 * Adds fees to order
	 *
	 * @since 1.0.0
	 */
	function store_shipping( $order, $klarna_order ) {

		$klarna_transient = sanitize_key( $_GET['sid'] );
		$klarna_wc = get_transient( $klarna_transient );

		// Store shipping for all packages
		foreach ( $klarna_wc->shipping->get_packages() as $package_key => $package ) {
			if ( isset( $package['rates'][ $this->shipping_methods[ $package_key ] ] ) ) {
				$item_id = $order->add_shipping( $package['rates'][ $this->shipping_methods[ $package_key ] ] );

				if ( ! $item_id ) {
					throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
				}

				// Allows plugins to add order item meta to shipping
				do_action( 'woocommerce_add_shipping_order_item', $order_id, $item_id, $package_key );
			}
		}
		
	}

	/**
	 * Adds tax_rows to order
	 *
	 * @since 1.0.0
	 */
	function store_tax_rows( $order, $klarna_order ) {

		$klarna_transient = sanitize_key( $_GET['sid'] );
		$klarna_wc = get_transient( $klarna_transient );

		foreach ( array_keys( $klarna_wc->cart->taxes + $klarna_wc->cart->shipping_taxes ) as $tax_rate_id ) {
			if ( ! $order->add_tax( $tax_rate_id, $klarna_wc->cart->get_tax_amount( $tax_rate_id ), WC()->cart->get_shipping_tax_amount( $tax_rate_id ) ) ) {
				throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
			}
		}

	}

	/**
	 * Adds coupons to order
	 *
	 * @since 1.0.0
	 */
	function store_coupons( $order, $klarna_order ) {

		$klarna_transient = sanitize_key( $_GET['sid'] );
		$klarna_wc = get_transient( $klarna_transient );

		foreach ( $klarna_wc->cart->get_coupons() as $code => $coupon ) {
			if ( ! $order->add_coupon( $code, $klarna_wc->cart->get_coupon_discount_amount( $code ) ) ) {
				throw new Exception( __( 'Error: Unable to create order. Please try again.', 'woocommerce' ) );
			}
		}

	}
	
	/**
	 * Adds fees to order
	 *
	 * @since 1.0.0
	 */
	function store_payment_method( $order, $klarna_order ) {

		$klarna_transient = sanitize_key( $_GET['sid'] );
		$klarna_wc = get_transient( $klarna_transient );

		$available_gateways = $klarna_wc->payment_gateways->payment_gateways();
		$this->payment_method = $available_gateways[ 'klarna_checkout' ];
	
		$order->set_payment_method( $this->payment_method );
		$order->set_total( $klarna_wc->cart->shipping_total, 'shipping' );
		$order->set_total( $klarna_wc->cart->get_order_discount_total(), 'order_discount' );
		$order->set_total( $klarna_wc->cart->get_cart_discount_total(), 'cart_discount' );
		$order->set_total($klarna_wc->cart->tax_total, 'tax' );
		$order->set_total( $klarna_wc->cart->shipping_tax_total, 'shipping_tax' );
		$order->set_total( $klarna_wc->cart->total );

	}






	/**
	 * Create a new customer
	 *
	 * @param  string $email
	 * @param  string $username
	 * @param  string $password
	 * @return WP_Error on failure, Int (user ID) on success
	 *
	 * @since 1.0.0
	*/
	function create_new_customer( $email, $username = '', $password = '' ) {

    	// Check the e-mail address
		if ( empty( $email ) || ! is_email( $email ) )
            return new WP_Error( "registration-error", __( "Please provide a valid email address.", "woocommerce" ) );

		if ( email_exists( $email ) )
            return new WP_Error( "registration-error", __( "An account is already registered with your email address. Please login.", "woocommerce" ) );


		// Handle username creation
		$username = sanitize_user( current( explode( '@', $email ) ) );

		// Ensure username is unique
		$append     = 1;
		$o_username = $username;

		while ( username_exists( $username ) ) {
			$username = $o_username . $append;
			$append ++;
		}

		// Handle password creation
		$password = wp_generate_password();
		$password_generated = true;
    

		// WP Validation
		$validation_errors = new WP_Error();

		do_action( 'woocommerce_register_post', $username, $email, $validation_errors );

		$validation_errors = apply_filters( 'woocommerce_registration_errors', $validation_errors, $username, $email );

		if ( $validation_errors->get_error_code() )
            return $validation_errors;

		$new_customer_data = apply_filters( 'woocommerce_new_customer_data', array(
        	'user_login' => $username,
			'user_pass'  => $password,
			'user_email' => $email,
			'role'       => 'customer'
		) );

		$customer_id = wp_insert_user( $new_customer_data );

		if ( is_wp_error( $customer_id ) )
        	return new WP_Error( "registration-error", '<strong>' . __( 'ERROR', 'woocommerce' ) . '</strong>: ' . __( 'Couldn&#8217;t register you&hellip; please contact us if you continue to have problems.', 'woocommerce' ) );
	
		// Send New account creation email to customer?
		if( $this->send_new_account_email == 'yes' ) {
        	do_action( 'woocommerce_created_customer', $customer_id, $new_customer_data, $password_generated );
		}
	
		return $customer_id;

	}

	
	/**
	 * Helper function get_enabled
	 *
	 * @since 1.0.0
	 */	 
	function get_enabled() {

		return $this->enabled;

	}
	
	/**
	 * Helper function get_modify_standard_checkout_url
	 *
	 * @since 1.0.0
	 */
	function get_modify_standard_checkout_url() {

		return $this->modify_standard_checkout_url;

	}

	/**
	 * Helper function get_klarna_checkout_page
	 *
	 * @since 1.0.0
	 */
	function get_klarna_checkout_url() {

 		return $this->klarna_checkout_url;

 	}
 	
 	
 	/**
	 * Helper function get_klarna_country
	 *
	 * @since 1.0.0
	 */
	function get_klarna_country() {

		return $this->klarna_country;

	}
 	
	

	/**
	 * Helper function - get authorized countries
	 *
	 * @since 1.0.0
	 */
	function get_authorized_countries() {

		$this->authorized_countries	= array();

		if ( ! empty( $this->eid_se ) ) {
			$this->authorized_countries[] = 'SE';
		}

		if ( ! empty( $this->eid_no ) ) {
			$this->authorized_countries[] = 'NO';
		}

		if ( ! empty( $this->eid_fi ) ) {
			$this->authorized_countries[] = 'FI';
		}

		if ( ! empty( $this->eid_de ) ) {
			$this->authorized_countries[] = 'DE';
		}
		
		return $this->authorized_countries;

	}
	
	
	/**
	 * Helper function - get correct currency for selected country
	 *
	 * @since 1.0.0
	 */
	function get_currency_for_country( $country ) {
				
		switch ( $country ) {
			case 'DK':
				$currency = 'DKK';
				break;
			case 'DE' :
				$currency = 'EUR';
				break;
			case 'NL' :
				$currency = 'EUR';
				break;
			case 'NO' :
				$currency = 'NOK';
				break;
			case 'FI' :
				$currency = 'EUR';
				break;
			case 'SE' :
				$currency = 'SEK';
				break;
			case 'AT' :
				$currency = 'EUR';
				break;
			default:
				$currency = '';
		}
		
		return $currency;

	}
	
	
	/**
	 * Helper function - get Account Signup Text
	 *
	 * @since 1.0.0
	 */
 	public function get_account_signup_text() {

	 	return $this->account_signup_text;

 	}


 	/**
	 * Helper function - get Account Login Text
	 *
	 * @since 1.0.0
	 */
 	public function get_account_login_text() {

	 	return $this->account_login_text;

 	}
 	
 	
	/**
	 * Activate the order/reservation in Klarnas system and return the result
	 *
	 * @since 1.0.0
	 */
	function activate_reservation() {

		global $woocommerce;

		$order_id = $_GET['post'];
		$order = wc_get_order( $order_id );

		require_once( KLARNA_LIB . 'Klarna.php' );
		if ( ! function_exists('xmlrpc_encode_entitites') && ! class_exists('xmlrpcresp' ) ) {
			require_once( KLARNA_LIB . '/transport/xmlrpc-3.0.0.beta/lib/xmlrpc.inc' );
			require_once( KLARNA_LIB . '/transport/xmlrpc-3.0.0.beta/lib/xmlrpc_wrappers.inc' );
		}

		// Split address into House number and House extension for NL & DE customers
		if ( $this->shop_country == 'NL' || $this->shop_country == 'DE' ) {
		
			require_once('split-address.php');
			
			$klarna_billing_address				= $order->billing_address_1;
			$splitted_address 					= splitAddress($klarna_billing_address);
			
			$klarna_billing_address				= $splitted_address[0];
			$klarna_billing_house_number		= $splitted_address[1];
			$klarna_billing_house_extension		= $splitted_address[2];
			
			$klarna_shipping_address			= $order->shipping_address_1;
			$splitted_address 					= splitAddress($klarna_shipping_address);
			
			$klarna_shipping_address			= $splitted_address[0];
			$klarna_shipping_house_number		= $splitted_address[1];
			$klarna_shipping_house_extension	= $splitted_address[2];
		
		} else {
			
			$klarna_billing_address				= $order->billing_address_1;
			$klarna_billing_house_number		= '';
			$klarna_billing_house_extension		= '';
			
			$klarna_shipping_address			= $order->shipping_address_1;
			$klarna_shipping_house_number		= '';
			$klarna_shipping_house_extension	= '';
			
		}
				
		$klarna = new Klarna();

		/**
		 * Setup Klarna configuration
		 */
		$country = $this->klarna_helper->get_klarna_country();
		$this->configure_klarna( $klarna, $country );
		
		Klarna::$xmlrpcDebug = false;
		Klarna::$debug = false;
		
		/**
		 * Setup Klarna configuration
		 */
		$country = $this->klarna_helper->get_klarna_country();
		$this->configure_klarna( $klarna, $country );

		$klarna_order = new WC_Gateway_Klarna_Order( $order, $klarna );
		$klarna_order->prepare_order(
			$klarna_billing,
			$klarna_shipping,
			$this->ship_to_billing_address
		);

		// Set store specific information so you can e.g. search and associate invoices with order numbers.
		$k->setEstoreInfo(
		    $orderid1 = $order_id,         // Maybe the estore's order number/id.
		    $orderid2 = $order->order_key, // Could an order number from another system?
		    $user = ''                     // Username, email or identifier for the user?
		);
		
		try {

			// Transmit all the specified data, from the steps above, to Klarna.
			$result = $k->activateReservation(
			    null,           // PNO (Date of birth for DE and NL).
			    get_post_meta( $order_id, 'klarna_order_reservation', true ),           // Reservation to activate
			    null,           // Gender.
			    '',             // OCR number to use if you have reserved one.
			    KlarnaFlags::NO_FLAG, //No specific behaviour like RETURN_OCR or TEST_MODE.
			    -1 // Get the pclass object that the customer has choosen.
			);
			
			// Retreive response
			$risk = $result[0]; // ok or no_risk
			$invno = $result[1];
			
			// Invoice created
			if ( $risk == 'ok' ) {
	    		update_post_meta( $order_id, 'klarna_order_status', 'activated' );
				update_post_meta( $order_id, 'klarna_order_invoice', $invno );
				$order->add_order_note(sprintf(__('Klarna payment reservation has been activated. Invoice number: %s.', 'klarna'), $invno));
			}
			echo "risk: {$risk}\ninvno: {$invno}\n";
			// Reservation is activated, proceed accordingly.

	    } catch( Exception $e ) {

	    	// Something went wrong, print the message:
	    	$order->add_order_note(
	    		sprintf(
	    			__( 'Klarna reservation activation error: %s. Error code: $e->getCode()', 'klarna'),
	    			$e->getMessage(),
	    			$e->getCode()
	    		)
	    	);

	    }

	} // End function activate_reservation
    
} // End class WC_Gateway_Klarna_Checkout

	
// Extra Class for Klarna Checkout
class WC_Gateway_Klarna_Checkout_Extra {
	
	public function __construct() {

		add_action( 'init', array( $this, 'start_session' ), 1 );
		
		add_shortcode( 'woocommerce_klarna_checkout', array( $this, 'klarna_checkout_page') );
		add_shortcode( 'woocommerce_klarna_checkout_order_note', array( $this, 'klarna_checkout_order_note') );

		// add_shortcode( 'woocommerce_klarna_login', array( $this, 'klarna_checkout_login') );
		// add_shortcode( 'woocommerce_klarna_cart', array( $this, 'klarna_checkout_cart') );
		// add_shortcode( 'woocommerce_klarna_country', array( $this, 'klarna_checkout_country') );
		// add_shortcode( 'woocommerce_klarna_shipping', array( $this, 'klarna_checkout_shipping') );
		
		add_filter( 'woocommerce_get_checkout_url', array( $this, 'change_checkout_url' ), 20 );
		
		add_action( 'woocommerce_register_form_start', array( $this, 'add_account_signup_text' ) );
		add_action( 'woocommerce_login_form_start', array( $this, 'add_account_login_text' ) );
		
		// Filter Checkout page ID, so WooCommerce Google Analytics integration can
		// output Ecommerce tracking code on Klarna Thank You page
		add_filter( 'woocommerce_get_checkout_page_id', array( $this, 'change_checkout_page_id' ) );
		
	}


	// Klarna Checkout page coupons
	function klarna_checkout_coupons_js_2() { ?>
		
		<script>
		jQuery(document).ready(function($){
			jQuery('#klarna-suspend').toggle(function ( event ) {
				event.preventDefault();
				window._klarnaCheckout(function (api) {
					api.suspend();
				});
			}, function( event ) {
				event.preventDefault();
				window._klarnaCheckout(function (api) {
					api.resume();
				});
			});
		});
		</script>
	
	<?php }


		
	// Set session
	function start_session() {		
		
		$data = new WC_Gateway_Klarna_Checkout;
		$enabled = $data->get_enabled();
		
    	if ( ! session_id() && $enabled == 'yes' ) {
        	session_start();
        }
    }
    
	// Shortcode KCO page
	function klarna_checkout_page() {

		$data = new WC_Gateway_Klarna_Checkout;
		return '<div class="klarna_checkout">' . $data->get_klarna_checkout_page() . '</div>';

	}
	
	// Shortcode Order note
	function klarna_checkout_order_note() {

		global $woocommerce;
    	$field = array(
			'type'              => 'textarea',
			'label'             => __( 'Order Notes', 'woocommerce' ),
			'placeholder'       => _x('Notes about your order, e.g. special notes for delivery.', 'placeholder', 'woocommerce'),
			'class'             => array('notes'),
		);
		
		ob_start();
		
		echo '<div class="woocommerce"><form>';
    	woocommerce_form_field( 'kco_order_note', $field );
		echo '</form></div>';

		return ob_get_clean();
    	
	}
	

	// Klarna Checkout page login
	function klarna_checkout_login() {

		ob_start();
		echo '<div class="woocommerce">';
		wc_get_template( 'checkout/form-login.php', array( 'checkout' => WC()->checkout() ) );
		echo '</div>';
		return ob_get_clean();

	}


	function klarna_checkout_css() {

		global $post;
		global $klarna_checkout_thanks_url;

		$checkout_page_id = url_to_postid( $klarna_checkout_thanks_url );

		if ( $post->ID == $checkout_page_id ) { ?>
			<style type="text/css">.wc-proceed-to-checkout{display:none !important;}.woocommerce .cart-collaterals .cart_totals{width:100%;float:none;}</style>
		<?php }

	}


	function set_cart_constant() {

		global $post;
		global $klarna_checkout_thanks_url;

		$checkout_page_id = url_to_postid( $klarna_checkout_thanks_url );

		if ( $post->ID == $checkout_page_id ) {
			
			if ( has_shortcode( $post->post_content, 'woocommerce_klarna_cart' ) ) {

				remove_action( 'woocommerce_cart_collaterals', 'woocommerce_cross_sell_display' );
				remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 10 );

				if ( ! defined('WOOCOMMERCE_CART') ) {
					define( 'WOOCOMMERCE_CART', true );
				}

			}

		}

	}


	// Klarna Checkout page cart
	function klarna_checkout_cart() {

		ob_start();
		// echo '<div class="woocommerce">';
		// wc_get_template( 'checkout/review-order.php', array( 'checkout' => WC()->checkout() ) );
		// echo '</div>';
		echo do_shortcode( '[woocommerce_cart]' );
		return ob_get_clean();

	}


	function maybe_change_cart_url( $cart_page_url ) {

		global $post;
		global $klarna_checkout_thanks_url;

		return '';

		// if ( isset( $post ) ) {

			$checkout_page_id = url_to_postid( $klarna_checkout_thanks_url );

			if ( $post->ID == $checkout_page_id ) {
				return $klarna_checkout_thanks_url;
			}

		// }

		return $cart_page_url;

	}


	// Klarna Checkout shipping
	function klarna_checkout_shipping() {

		ob_start();
		wc_get_template( 'checkout/review-order.php', array( 'checkout' => WC()->checkout() ) );
		return ob_get_clean();

	}
	
	/**
	 *  Change Checkout URL
	 *
	 *  Triggered from the 'woocommerce_get_checkout_url' action.
	 *  Alter the checkout url to the custom Klarna Checkout Checkout page.
	 *
	 **/	 
	function change_checkout_url( $url ) {

		global $woocommerce;

		$data = new WC_Gateway_Klarna_Checkout;
		$enabled = $data->get_enabled();
		$klarna_checkout_url = $data->get_klarna_checkout_url();
		$modify_standard_checkout_url = $data->get_modify_standard_checkout_url();
		$klarna_country = $data->get_klarna_country();
		$available_countries = $data->get_authorized_countries();

		// Change the Checkout URL if this is enabled in the settings
		if ( $modify_standard_checkout_url == 'yes' && $enabled == 'yes' && ! empty( $klarna_checkout_url ) && in_array( $klarna_country, $available_countries ) ) {
			$url = $klarna_checkout_url;
		}
		
		return $url;

	}
	
	/**
	 *  Function Add Account signup text
	 *
	 *  @since version 1.8.9
	 * 	Add text above the Account Registration Form. 
	 *  Useful for legal text for German stores. See documentation for more information. Leave blank to disable.
	 *
	 **/
	public function add_account_signup_text() {

		global $woocommerce;
		$data = new WC_Gateway_Klarna_Checkout;
		$account_signup_text = '';
		$account_signup_text = $data->get_account_signup_text();
		

		// Change the Checkout URL if this is enabled in the settings
		if( ! empty( $account_signup_text ) ) {
			echo $account_signup_text;
		}

	}
	
	
	/**
	 *  Function Add Account login text
	 *
	 *  @since version 1.8.9
	 * 	Add text above the Account Login Form. 
	 *  Useful for legal text for German stores. See documentation for more information. Leave blank to disable.
	 **/
	public function add_account_login_text() {

		global $woocommerce;
		$data = new WC_Gateway_Klarna_Checkout;
		$account_login_text = '';
		$account_login_text = $data->get_account_login_text();
		
	
		// Change the Checkout URL if this is enabled in the settings
		if ( !empty($account_login_text) ) {
			echo $account_login_text;
		}

	}

	/**
	 * Change checkout page ID to Klarna Thank You page, when in Klarna Thank You page only
	 */
	public function change_checkout_page_id( $checkout_page_id ) {

		global $post;
		global $klarna_checkout_thanks_url;

		if ( is_page() ) {
			$current_page_url = get_permalink( $post->ID );
			// Compare Klarna Thank You page URL to current page URL
			if ( esc_url( trailingslashit( $klarna_checkout_thanks_url ) ) == esc_url( trailingslashit( $current_page_url ) ) ) {
				$checkout_page_id = $post->ID;
			}
		}

		return $checkout_page_id;

	}
		

} // End class WC_Gateway_Klarna_Checkout_Extra

$wc_klarna_checkout_extra = new WC_Gateway_Klarna_Checkout_Extra;