<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://www.webstudiobrana.com
 * @since      1.0.0
 *
 * @package    Wsb_Hub3
 * @subpackage Wsb_Hub3/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * @package    Wsb_Hub3
 * @subpackage Wsb_Hub3/public
 * @author     Branko Borilovic <brana.hr@gmail.com>
 */
use Automattic\WooCommerce\Utilities\OrderUtil;
class Wsb_Hub3_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name 
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version   
	 */
	private $version;

	/**
	 * Validator class
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Wsb_Hub3_Validator   $validator  
	 */
	protected $validator;

	protected $hpos;

	// Images to embed in the email being sent: content ID => file path.
	private $email_images = array();

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name 
	 * @param      string    $version  
	 */
	public function __construct( $plugin_name, $version ) {
		$this->hpos = OrderUtil::custom_orders_table_usage_is_enabled();
		$this->plugin_name = $plugin_name;
		$this->version = $version;
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wsb-hub3-validator.php';
		$this->validator = new Wsb_Hub3_Validator();
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		// Registered everywhere, so shortcodes on other pages can load it.
		wp_register_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/wsb-hub3-public.css', array(), $this->version . '.' . filemtime( plugin_dir_path( __FILE__ ) . 'css/wsb-hub3-public.css' ), 'all' );
		if(is_checkout() || is_account_page()){
			wp_enqueue_style( $this->plugin_name );
		}
		
	}

	/**
	 * Register scripts for frontend.
	 *
	 * @since    1.0.1
	 */
	public function enqueue_scripts() {
		if(is_checkout() || is_account_page()){
			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/wsb-hub3-public.js', array('jquery'), $this->version . '.' . filemtime( plugin_dir_path( __FILE__ ) . 'js/wsb-hub3-public.js' ), false );
		}
		

	}

	function wsb_hub3_update_barcode_meta( $order_id, $data ) {

		$order = wc_get_order( $order_id );

		// Classic checkout. The block checkout saves the choice in wsb_hub3_store_api_save_iban().
		if ( doing_action( 'woocommerce_checkout_update_order_meta' ) && 'bacs' === $order->get_payment_method() ) {
			$this->save_checkout_iban( $order, isset( $_POST['_wsb_barcode_iban'] ) ? sanitize_text_field( wp_unslash( $_POST['_wsb_barcode_iban'] ) ) : '' );
			$order->save_meta_data();
		}
		$receiver_iban = (string) $order->get_meta('_wsb_barcode_iban');
		if(!$receiver_iban) $receiver_iban = esc_html(get_option( 'wsb_hub3_receiver_iban' ));

		$order_status = $order->get_status();
		$croatian_only = esc_html(get_option( 'wsb_hub3_croatian_customers_only', 'no' ));
		if( "yes" == $croatian_only ){
			if('HR' != $data['billing_country']){
				return;
			}
		}
		if( 'bacs' != $data['payment_method'] ) {
			return;
		}

		$url = "https://hub3.bigfish.software/api/v2/barcode";

		$img_type = get_option( 'wc_wsb_hub3_admin_tab_img_type', 'png' );
		$img_padding = get_option( 'wsb_hub3_img_padding', '10' );
		$img_color = get_option( 'wsb_hub3_img_color', '#000000' );
		$amount = (float)$order->get_total();
		$barcode_amount = number_format( $amount *= 100, 0, '', '' );
		//$palatali = array("Č", "č", "Ć", "ć", "Ž", "ž", "Š", "š", "Đ", "đ");

        $name_max_chars = 30;
		$street_max_chars = 27;	
		$first_name = $data['billing_first_name'];
		$last_name = $data['billing_last_name'];
		$sender_name = $first_name . " " . $last_name;
		$sender_street = $data['billing_address_1'];

		if ($this->hpos) {
			$r1_checkbox = $order->get_meta('R1 račun');
		} else {
			$r1_checkbox = get_post_meta( $order_id, 'R1 račun', true );
		}
		if( !empty( $r1_checkbox ) ){
			if ($this->hpos) {
				$sender_name = $order->get_meta('Ime tvrtke');
				if(!$sender_name) $sender_name = get_post_meta( $order_id, 'Ime tvrtke', true );
			} else {
				$sender_name = get_post_meta( $order_id, 'Ime tvrtke', true );
			}
			if ($this->hpos) {
				$sender_street = $order->get_meta('Adresa tvrtke');
			} else {
				$sender_street = get_post_meta( $order_id, 'Adresa tvrtke', true );
			}
		} else {
			$company = $order->get_billing_company();
			if(!empty($company)){
				$sender_name = $company;
			} 
		}

		$name_length = mb_strlen($sender_name);
		if($name_length > $name_max_chars){
			$sender_name = mb_substr($sender_name, 0, $name_max_chars);
		}

		$street_length = mb_strlen($sender_street);
		if($street_length > $street_max_chars){
			$sender_street = mb_substr($sender_street, 0, $street_max_chars);
		}

		$place_max_chars = 27;
		$sender_postcode = $data['billing_postcode'];
		$sender_city = $data['billing_city'];
		$sender_place = $sender_postcode . " " . $sender_city;
		$place_length = mb_strlen($sender_place);
		if($place_length > $place_max_chars){
			$sender_place = mb_substr($sender_place, 0, $place_max_chars);
		}

		$receiver_name = get_option( 'wsb_hub3_receiver_name' );
		$receiver_street = get_option( 'wsb_hub3_receiver_address' );
		$receiver_place = get_option( 'wsb_hub3_receiver_postcode' ) . " " . get_option( 'wsb_hub3_receiver_city' );
		$receiver_model = Wsb_Hub3_Validator::receiver_model();
		$receiver_reference = $this->get_reference($order_id, $reference_changes);
		$purpose = get_option( 'wsb_hub3_payment_purpose' );
		$order_number = $order->get_order_number();
		$description = $this->get_payment_description($order);

		$hubparams = array();
		$hubparams['renderer'] = 'image';
		$hubparams['options']['format'] = $img_type;
		if(!empty($img_color)){
			$hubparams['options']['color'] = $img_color;
		} else {
			$hubparams['options']['color'] = '#000000';
		}
		
		$hubparams['options']['padding'] = !empty($img_padding) ? (int) $img_padding : 0;
		$hubparams['options']['scale'] = 3;
		$hubparams['options']['ratio'] = 3;
		
		$hubparams['data']['amount'] = (int) $barcode_amount;
		$hubparams['data']['currency'] = get_woocommerce_currency();
		$hubparams['data']['sender']['name'] = $sender_name;
		$hubparams['data']['sender']['street'] = $sender_street;
		$hubparams['data']['sender']['place'] = $sender_place;

		$hubparams['data']['receiver']['name'] = $receiver_name;
		$hubparams['data']['receiver']['street'] = $receiver_street;
		$hubparams['data']['receiver']['place'] = $receiver_place;
		$hubparams['data']['receiver']['iban'] = $receiver_iban;
		$hubparams['data']['receiver']['model'] = $receiver_model;
		$hubparams['data']['receiver']['reference'] = $receiver_reference;
		$hubparams['data']['purpose']= $purpose;
		$hubparams['data']['description']= $description;
		
		$barcode = wp_remote_post( esc_url($url), array(
			'headers'     => array('Content-Type' => 'application/json; charset=utf-8'),
			'body'        => json_encode($hubparams),
			'method'      => 'POST',
			'timeout'     => 15,
		));

		$notes = array();
		if ( $reference_changes ) {
			/* translators: 1: adjusted payment reference, 2: explanation of the changes */
			$notes[] = sprintf( __( 'HUB3 payment reference was adjusted to FINA rules: %1$s. %2$s', 'wsb-hub3' ), $receiver_reference, implode( ' ', Wsb_Hub3_Validator::reference_change_messages( $reference_changes ) ) );
		}

		$previous_barcode = (string) $order->get_meta( '_wsb_hub3_barcode' );
		$barcode_file = Wsb_Hub3_Files::name( $order, 'barcode', $img_type );
		$barcode_path = Wsb_Hub3_Files::path( $barcode_file );
		if ( '' !== $previous_barcode && $previous_barcode !== $barcode_file && Wsb_Hub3_Files::exists( $previous_barcode ) ) {
			unlink( Wsb_Hub3_Files::path( $previous_barcode ) );
		}
		if ( is_wp_error( $barcode ) || 200 !== (int) wp_remote_retrieve_response_code( $barcode ) ) {
			if ( is_wp_error( $barcode ) ) {
				$error = $barcode->get_error_message();
			} else {
				$response = json_decode( wp_remote_retrieve_body( $barcode ), true );
				$error    = ! empty( $response['errors'] ) ? implode( '; ', (array) $response['errors'] ) : 'HTTP ' . wp_remote_retrieve_response_code( $barcode );
			}
			// Remove any older barcode so outdated payment data is never shown.
			if ( file_exists( $barcode_path ) ) {
				unlink( $barcode_path );
			}
			$order->delete_meta_data( '_wsb_hub3_barcode' );
			/* translators: %s: error returned by the barcode service */
			$notes[] = sprintf( __( 'HUB3 barcode could not be generated: %s', 'wsb-hub3' ), $error );
			wc_get_logger()->error( 'Barcode for order ' . $order_id . ' not generated: ' . $error, array( 'source' => 'wsb-hub3' ) );
		} else {
			file_put_contents( $barcode_path, wp_remote_retrieve_body( $barcode ) );
			$order->update_meta_data( '_wsb_hub3_barcode', $barcode_file );
		}

		// Orders are regenerated on every admin update, so only add a note when something changed.
		$note = implode( ' ', $notes );
		if ( $note !== (string) $order->get_meta( '_wsb_hub3_last_note' ) ) {
			if ( '' !== $note ) {
				$order->add_order_note( $note );
			}
			$order->update_meta_data( '_wsb_hub3_last_note', $note );
		}

		$order->update_meta_data( '_wsb_sender_name', sanitize_text_field($sender_name));
		// create_hub3() reads the barcode and sender name from saved meta.
		$order->save_meta_data();
		$hub3_image = $this->create_hub3($order_id, $order);
		
		if("" != $hub3_image){
			$order->update_meta_data( '_wsb_hub3_slip', $hub3_image);
		}
		
		//$order->save();
		$order->save_meta_data();
	}

	function wsb_remove_bank_details($accounts, $order_id){
		$show_accounts = get_option( 'wsb_hub3_bank_accounts_display', 'no' );
		if('no' == $show_accounts){
			return array();
		} else {
			return $accounts;
		}
	}

	function wsb_hub3_barcode_thankyou($order_id){
		$order = wc_get_order( $order_id );
		if(!$order) return;
		$payment_method = $order->get_payment_method();
		$order_status = $order->get_status();
		$country = $order->get_billing_country();
		$status_to_display = str_replace("wc-", "", get_option( 'wsb_hub3_order_status', 'on-hold' ));
		$croatian_only = esc_html(get_option( 'wsb_hub3_croatian_customers_only', 'no' ));
		if( "yes" == $croatian_only ){
			if('HR' != $country){
				return;
			}
		}

		if('bacs' != $payment_method || $status_to_display != $order_status) {
			return;
		}
		$display_param = esc_html(get_option( 'wsb_hub3_display_details_thankyou', 'hub3' ));
		// Payment details are shown with the [wsb_hub3] and [wsb_barcode] shortcodes instead.
		if ( 'none' === $display_param ) {
			return;
		}
		if("barcode" != $display_param){ // Hide payment description if set to display barcode only
			echo "<p class='barcode-text'>" . wptexturize(get_option( 'wsb_hub3_description_text' )). "</p>";
		}

		$order = $this->ensure_images( $order );
		$barcode_image = Wsb_Hub3_Files::url( $order->get_meta('_wsb_hub3_barcode') );
		$slip_width = get_option( 'wsb_hub3_slip_width', 800 ) . "px";
		$barcode_width = get_option( 'wsb_hub3_barcode_width', 400 ) . "px";

		if("html" == $display_param){
			echo $this->get_data_html($order_id);
		}
		if("hub3" == $display_param){
			$hub3_image = Wsb_Hub3_Files::url( $order->get_meta('_wsb_hub3_slip') );
			if($hub3_image){
				echo "<div class='slipdiv'><a title='" . __( 'Enlarge (New window)', 'wsb-hub3' ) . "' href='". esc_url( $hub3_image ) ."' target='new'><img style='width: " . esc_html($slip_width) . "' src='". esc_url( $hub3_image ) ."' alt='HUB-3A' /></a></div>";
			}
			if($barcode_image){
				echo "<p class='barcode-text'><button id='barcode_toggler' class='btn'>" . __( 'Show larger barcode', 'wsb-hub3' ) . "</button></p>";
				echo "<div id='barcodediv' class='barcodediv'>";
				echo "<p class='barcode-text'>" . wptexturize(get_option( 'wsb_hub3_barcode_text' )). "</p>";
				echo "<p class='barcode-text'><img style='width: " . esc_html($barcode_width) . "' src='". esc_url( $barcode_image ) ."' alt='barcode' /></p></div>";
			}
		}

		if("html" == $display_param || "barcode" == $display_param){
			if($barcode_image){
				echo "<p class='barcode-text'>" . wptexturize(get_option( 'wsb_hub3_barcode_text' )). "</p>";
				echo "<div class='barcodediv'><img style='width: " . esc_html($barcode_width) . "' src='". esc_url( $barcode_image ) ."' alt='barcode' /></div>";
			}
		}
		

	}


	function wsb_hub3_barcode_order_display($order_id){
		$order = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		$order_status = $order->get_status();
		$country = $order->get_billing_country();
		$status_to_display = str_replace("wc-", "", get_option( 'wsb_hub3_order_status', 'on-hold' ));
		$croatian_only = esc_html(get_option( 'wsb_hub3_croatian_customers_only', 'no' ));
		if( "yes" == $croatian_only ){
			if('HR' != $country){
				return;
			}
		}
		if('bacs' != $payment_method || $status_to_display != $order_status) {
			return;
		}

		$display_param = esc_html(get_option( 'wsb_hub3_display_details_order', 'hub3' ));
		if("barcode" != $display_param){ // Hide payment description if set to display barcode only
			echo "<p class='barcode-text'>" . wptexturize(get_option( 'wsb_hub3_description_text' )). "</p>";
		}
		$order = $this->ensure_images( $order );
		$barcode_image = Wsb_Hub3_Files::url( $order->get_meta('_wsb_hub3_barcode') );
		$slip_width = get_option( 'wsb_hub3_slip_width', 800 ) . "px";
		$barcode_width = get_option( 'wsb_hub3_barcode_width', 400 ) . "px";
		
		
		if("html" == $display_param){
			echo $this->get_data_html($order_id);
		}
		if("hub3" == $display_param){
			$hub3_image = Wsb_Hub3_Files::url( $order->get_meta('_wsb_hub3_slip') );
			if($hub3_image){
				echo "<div class='slipdiv'><a title='" . __( 'Enlarge (New window)', 'wsb-hub3' ) . "' href='". esc_url( $hub3_image ) ."' target='new'><img style='width: " . esc_html($slip_width) . "' src='". esc_url( $hub3_image ) ."' alt='HUB-3A' /></a></div>";
			}
			if($barcode_image){
				echo "<p class='barcode-text'><button id='barcode_toggler' class='btn'><span class='barcode_btn_text'>" . __( 'Show larger barcode', 'wsb-hub3' ) . "</span></button></p>";
				echo "<div id='barcodediv' class='barcodediv'>";
				echo "<p class='barcode-text'>" . wptexturize(get_option( 'wsb_hub3_barcode_text' )). "</p>";
				echo "<img style='width: " . esc_html($barcode_width) . "' src='". esc_url( $barcode_image ) ."' alt='barcode' /></div>";
			}
		}

		if("html" == $display_param || "barcode" == $display_param){
			if($barcode_image){
				echo "<p class='barcode-text'>" . wptexturize(get_option( 'wsb_hub3_barcode_text' )). "</p>";
				echo "<div class='barcodediv'><img style='width: " . esc_html($barcode_width) . "' src='". esc_url( $barcode_image ) ."' alt='barcode' /></div>";
			}
		}

	}

	function wsb_hub3_email_after_order_table( $order, $sent_to_admin, $plain_text, $email ) 
	{
		$croatian_only = esc_html(get_option( 'wsb_hub3_croatian_customers_only', 'no' ));
		if(!$sent_to_admin){
			$status_to_display = str_replace("wc-", "", get_option( 'wsb_hub3_order_status', 'on-hold' ));
			if ( $order->has_status( $status_to_display ) ){
				$order_id = $order->get_id();
				$payment_method = $order->get_payment_method();
				$country = $order->get_billing_country();
				if( ('yes' == $croatian_only && 'HR' == $country && 'bacs' == $payment_method ) || ('no' == $croatian_only && 'bacs' == $payment_method ) ){
					$order = $this->ensure_images( $order );
					$display_param = esc_html(get_option( 'wsb_hub3_display_details_email', 'hub3' ));
	
					if("barcode" != $display_param){ // Hide payment description if set to display barcode only
						echo "<p style='text-align:center;'>" . wptexturize(get_option( 'wsb_hub3_description_text' )). "</p>";
					}
					$barcode_image = $this->email_image_src( $order->get_meta('_wsb_hub3_barcode'), 'barcode', $order_id );
					$slip_width = get_option( 'wsb_hub3_slip_width_email', 560 );
					$barcode_width = get_option( 'wsb_hub3_barcode_width_email', 400 );
	
					
					if("html" == $display_param){
						echo $this->get_data_html($order_id);
					}
					if("hub3" == $display_param){
						$hub3_image = $this->email_image_src( $order->get_meta('_wsb_hub3_slip'), 'slip', $order_id );
						if($hub3_image){
							echo "<div style='text-align:center;'><img width='". esc_html($slip_width) . "' style='margin: 0 auto; width: " . esc_html($slip_width . "px") . "' src='". esc_attr( $hub3_image ) ."' alt='HUB-3A' /></div>";
						}
					}
			
					if($barcode_image){
						echo "<p style='text-align:center;'>" . wptexturize(get_option( 'wsb_hub3_barcode_text' )). "</p>";
						echo "<div style='text-align:center;'><img width='". esc_html($barcode_width) . "' style='margin: 0 auto; width: " . esc_html($barcode_width  . "px" ) . "' src='". esc_attr( $barcode_image ) ."' alt='barcode' /></div>";
					}	
				}

			}
		} else {
			$status_to_display = str_replace("wc-", "", get_option( 'wsb_hub3_order_status', 'on-hold' ));
			if ( $order->has_status( $status_to_display ) ){
				$order_id = $order->get_id();
				$payment_method = $order->get_payment_method();
				$country = $order->get_billing_country();
				
				if( ('yes' == $croatian_only && 'HR' == $country && 'bacs' == $payment_method ) || ('no' == $croatian_only && 'bacs' == $payment_method ) ) {
					$order = $this->ensure_images( $order );
					$barcode_image = $this->email_image_src( $order->get_meta('_wsb_hub3_barcode'), 'barcode', $order_id );
					$slip_width = get_option( 'wsb_hub3_slip_width_email', 560 );
					$barcode_width = get_option( 'wsb_hub3_barcode_width_email', 400 );
					$send_slip = esc_html(get_option( 'wsb_hub3_send_admin_slip', 'no' ));
					if($send_slip == "yes"){
						$hub3_image = $this->email_image_src( $order->get_meta('_wsb_hub3_slip'), 'slip', $order_id );
						if($hub3_image){
							echo "<div style='text-align:center;'><img width='". esc_html($slip_width) . "' style='margin: 0 auto; width: " . esc_html($slip_width . "px") . "' src='". esc_attr( $hub3_image ) ."' alt='HUB-3A' /></div>";
						}
					}
			
					$send_barcode = esc_html(get_option( 'wsb_hub3_send_admin_barcode', 'no' ));
					if($send_barcode == "yes"){
						if($barcode_image){
							echo "<div style='text-align:center;'><img width='". esc_html($barcode_width) . "' style='margin: 0 auto; width: " . esc_html($barcode_width  . "px" ) . "' src='". esc_attr( $barcode_image ) ."' alt='barcode' /></div>";
						}
					}
					
				}
			}
		}
		
	}

	/**
	 * Recreates a missing barcode or slip, e.g. for orders whose images a plugin update deleted before 3.1.0.
	 * Returns the order with fresh meta.
	 * @since    3.1.0
	 */
	public function ensure_images( $order ) {
		$missing = ! Wsb_Hub3_Files::exists( $order->get_meta( '_wsb_hub3_barcode' ) ) || ! Wsb_Hub3_Files::exists( $order->get_meta( '_wsb_hub3_slip' ) );
		$lock    = 'wsb_hub3_regenerate_' . $order->get_id();
		if ( ! $missing || get_transient( $lock ) ) {
			return $order;
		}
		// Retry at most hourly, so a failing barcode service isn't called on every page view.
		set_transient( $lock, 1, HOUR_IN_SECONDS );
		$this->wsb_hub3_admin_order_update( $order->get_id(), $order );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Image source for emails: an embedded image (cid:) when enabled, otherwise the file URL.
	 * Embedded images don't depend on the website, so they also work behind Cloudflare "Under attack" mode.
	 */
	private function email_image_src( $file, $type, $order_id ) {
		if ( ! Wsb_Hub3_Files::exists( $file ) ) {
			return '';
		}
		if ( 'no' === get_option( 'wsb_hub3_email_embed_images', 'yes' ) ) {
			return Wsb_Hub3_Files::url( $file );
		}
		$cid                        = 'wsb-hub3-' . $type . '-' . $order_id;
		$this->email_images[ $cid ] = Wsb_Hub3_Files::path( $file );
		return 'cid:' . $cid;
	}

	/**
	 * Attaches the images referenced in the email body as inline images.
	 * @since    3.1.0
	 */
	public function wsb_hub3_embed_email_images( $phpmailer ) {
		foreach ( $this->email_images as $cid => $path ) {
			// Emails rendered but not sent (e.g. previews) must not add their images to the next email.
			if ( false !== strpos( (string) $phpmailer->Body, 'cid:' . $cid ) && is_file( $path ) ) {
				$phpmailer->addEmbeddedImage( $path, $cid, basename( $path ) );
			}
		}
		$this->email_images = array();
	}

	function get_reference($order_id, &$changes = null){
		$order = wc_get_order( $order_id );
		$date  = Wsb_Hub3_Validator::reference_date( get_option( 'wsb_hub3_receiver_reference_date', 'ddmmyyyy' ), strtotime( $order->get_date_created() ) );
		$html = esc_html( Wsb_Hub3_Validator::build_reference(
			Wsb_Hub3_Validator::receiver_model(),
			get_option( 'wsb_hub3_receiver_reference_prefix' ),
			get_option( 'wsb_hub3_receiver_reference', 'orderid' ),
			$date,
			$order->get_order_number(),
			get_option( 'wsb_hub3_receiver_reference_sufix' ),
			$changes
		) );

		/* da se može filtrirati prema potrebi */
        return apply_filters('wsb_hub3_receiver_reference', $html, $order_id);
	}

	/**
	 * Payment description with [order] replaced, limited to the HUB-3 maximum of 35 characters.
	 * The template text is shortened, never the order number, which identifies the payment.
	 */
	function get_payment_description($order){
		$max          = 35;
		$template     = get_option( 'wsb_hub3_payment_description', 'Plaćanje narudžbe br. [order]' );
		$order_number = (string) $order->get_order_number();
		$budget       = $max - substr_count( $template, '[order]' ) * mb_strlen( $order_number );
		$description  = '';
		$pieces       = preg_split( '/(\[order\])/', $template, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $pieces as $i => $piece ) {
			if ( '[order]' === $piece ) {
				$description .= $order_number;
				continue;
			}
			if ( mb_strlen( $piece ) > $budget ) {
				$cut        = mb_substr( $piece, 0, max( 0, $budget ) );
				$split_word = ! preg_match( '/^\s/u', mb_substr( $piece, mb_strlen( $cut ), 1 ) );
				// Drop a partial word, and keep a space before a following order number.
				if ( $split_word || isset( $pieces[ $i + 1 ] ) ) {
					$cut = preg_replace( '/\S+$/u', '', $cut );
				}
				$piece = $cut;
			}
			$budget      -= mb_strlen( $piece );
			$description .= $piece;
		}
		return mb_substr( trim( preg_replace( '/\s+/u', ' ', $description ) ), 0, $max );
	}

	function get_data_html($order_id){
		$order = wc_get_order( $order_id );
		$order_number = $order->get_order_number();
		$reference = $this->get_reference($order_id);
		$description = $this->get_payment_description($order);
		
		$total = $order->get_formatted_order_total();
		if ($this->hpos) {
			$sender_name = $order->get_meta('_wsb_sender_name');
		} else {
			$sender_name = get_post_meta( $order_id, '_wsb_sender_name', true );
		}

		$html = "";
		$html .= "<h2 class='hub3-title'>" . __( 'Payment details', 'wsb-hub3' ) . "</h2>";
		$html .= "<table class='woocommerce-table hub3-table'><tbody>";
		$html .= "<tr><td>" . __( 'Recipient', 'wsb-hub3' ) . ": </td><td>" .  esc_html(get_option( 'wsb_hub3_receiver_name' )) . "<br>" . esc_html(get_option( 'wsb_hub3_receiver_address' )) . "<br>" . esc_html(get_option( 'wsb_hub3_receiver_postcode' )) . " " . esc_html(get_option( 'wsb_hub3_receiver_city' )) . "</td></tr>";
		$html .= "<tr><td>" . __( 'Amount', 'wsb-hub3' ) . ": </td><td>" . $total . "</td></tr>";
		$html .= "<tr><td>" . __( 'IBAN', 'wsb-hub3' ) . ": </td><td>" . esc_html(get_option( 'wsb_hub3_receiver_iban' )) . "</td></tr>";
		$html .= "<tr><td>" . __( 'Model', 'wsb-hub3' ) . ": </td><td>HR" . esc_html( Wsb_Hub3_Validator::receiver_model() ) . "</td></tr>";
		if ( '' !== $reference ) {
			$html .= "<tr><td>" . __( 'Reference', 'wsb-hub3' ) . ": </td><td>" . esc_html($reference) . "</td></tr>";
		}
		if(!empty(get_option( 'wsb_hub3_payment_purpose' ))){
			$html .= "<tr><td>" . __( 'Purpose code', 'wsb-hub3' ) . ": </td><td>" . esc_html(get_option( 'wsb_hub3_payment_purpose' )) . "</td></tr>";
		}
		$html .= "<tr><td>" . __( 'Description', 'wsb-hub3' ) . ": </td><td>" . esc_html($description) . "</td></tr>";
		$html .= "</tbody></table>";

		return $html;
	}

	 function create_hub3($order_id, $order = null){
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		$order_number = $order->get_order_number();
		$recipient = get_option( 'wsb_hub3_receiver_name' );
		$recipient_address = esc_html(get_option( 'wsb_hub3_receiver_address' ));
		$recipient_place = esc_html(get_option( 'wsb_hub3_receiver_postcode' ) . " " . get_option( 'wsb_hub3_receiver_city' ));
		if ($this->hpos) {
			$iban = $order->get_meta('_wsb_barcode_iban');
		} else {
			$iban = get_post_meta( $order_id, '_wsb_barcode_iban', true );
		}
		if(!$iban) $iban = esc_html(get_option( 'wsb_hub3_receiver_iban' ));
		$reference = $reference = $this->get_reference($order_id);
		$description = $this->get_payment_description($order);
		$model = "HR" . Wsb_Hub3_Validator::receiver_model();
		
		if ($this->hpos) {
			$sender = $order->get_meta('_wsb_sender_name');
		} else {
			$sender = get_post_meta( $order_id, '_wsb_sender_name', true );
		}
		$sender_address = $order->get_billing_address_1(); 
		$sender_address2 = $order->get_billing_address_2();
		$sender_postcode = $order->get_billing_postcode(); 
		$sender_city = $order->get_billing_city();
		$currency = get_woocommerce_currency();
		$total = esc_html( "=" . number_format($order->get_total(),2,"",""));
		$total2 = esc_html( $currency. " = " . number_format($order->get_total(),2,",",""));

		$hub3a = imagecreatefromjpeg(plugin_dir_path(__DIR__) . 'public/img/hub-3a.jpg');
		$black = imagecolorallocate($hub3a, 0x30, 0x30, 0x30);
		$font_roboto = plugin_dir_path( __DIR__ ) . 'public/fonts/RobotoMono-Regular.ttf';
		$font_times = plugin_dir_path( __DIR__ ) . 'public/fonts/times-new-roman.ttf';
		
		$this->imagettftextWsb($hub3a, 18, 0, 402, 54, $black, $font_roboto, $currency, 3);

		$bbox_total = imagettfbbox(18, 0, $font_roboto, $total);
		$x_total = 768 - ( $bbox_total[4] + (strlen($total))*3 );

		$this->imagettftextWsb($hub3a, 18, 0, $x_total, 55, $black, $font_roboto, $total, 3);
		$this->imagettftextWsb($hub3a, 18, 0, 401, 160, $black, $font_roboto, $iban, 3);
		$this->imagettftextWsb($hub3a, 18, 0, 278, 202, $black, $font_roboto, $model, 3);
		$this->imagettftextWsb($hub3a, 18, 0, 384, 202, $black, $font_roboto, $reference, 3);
		if(!empty(get_option( 'wsb_hub3_payment_purpose' ))){
			$purpose = esc_html( get_option( 'wsb_hub3_payment_purpose' ));
			$this->imagettftextWsb($hub3a, 18, 0, 277, 250, $black, $font_roboto, $purpose, 3);
		}
		// Usable text widths in px, measured from the box borders in hub-3a.jpg.
		$left_width = 218;
		$right_width = 274;
		$this->imagettftextWsb($hub3a, $this->fit_font_size(12, $font_times, $description, 328), 0, 438, 227, $black, $font_times, $description);
		$this->imagettftextWsb($hub3a, $this->fit_font_size(12, $font_times, $description, 280), 0, 805, 240, $black, $font_times, $description);

		$bbox_total2 = imagettfbbox(12, 0, $font_times, $total2);
		$x_total2 = 1080 - $bbox_total2[4];
		$this->imagettftextWsb($hub3a, 12, 0, $x_total2, 54, $black, $font_times, $total2);

		$sender_place = $sender_postcode . " " . $sender_city;
		$this->imagettftextWsb($hub3a, $this->fit_font_size(14, $font_times, $sender, $left_width), 0, 35, 60, $black, $font_times, $sender);
		$this->imagettftextWsb($hub3a, $this->fit_font_size(14, $font_times, $sender_address, $left_width), 0, 35, 80, $black, $font_times, $sender_address);
		if( "" == $sender_address2 ){
			$this->imagettftextWsb($hub3a, $this->fit_font_size(14, $font_times, $sender_place, $left_width), 0, 35, 100, $black, $font_times, $sender_place );	
		} else {
			$this->imagettftextWsb($hub3a, $this->fit_font_size(14, $font_times, $sender_address2, $left_width), 0, 35, 100, $black, $font_times, $sender_address2);
			$this->imagettftextWsb($hub3a, $this->fit_font_size(14, $font_times, $sender_place, $left_width), 0, 35, 120, $black, $font_times, $sender_place );
		}

		$this->imagettftextWsb($hub3a, $this->fit_font_size(14, $font_times, $recipient, $left_width), 0, 35, 200, $black, $font_times, $recipient);
		$this->imagettftextWsb($hub3a, $this->fit_font_size(14, $font_times, $recipient_address, $left_width), 0, 35, 220, $black, $font_times, $recipient_address);
		$this->imagettftextWsb($hub3a, $this->fit_font_size(14, $font_times, $recipient_place, $left_width), 0, 35, 240, $black, $font_times, $recipient_place);

		$sender2 = $sender . ", " . $sender_city;
		$size_sender2 = $this->fit_font_size(12, $font_times, $sender2, $right_width);
		$bbox_sender2 = imagettfbbox($size_sender2, 0, $font_times, $sender2);
		$x_sender2 = 1080 - $bbox_sender2[4];
		$this->imagettftextWsb($hub3a, $size_sender2, 0, $x_sender2, 86, $black, $font_times, $sender2);

		$reference2 = trim($model . " " . $reference);
		$bbox_reference2 = imagettfbbox(12, 0, $font_times, $reference2);
		$x_reference2 = 1080 - $bbox_reference2[4];
		$this->imagettftextWsb($hub3a, 12, 0, $x_reference2, 201, $black, $font_times, $reference2);

		$bbox_iban2 = imagettfbbox(12, 0, $font_times, $iban);
		$x_iban2 = 1080 - $bbox_iban2[4];
		$this->imagettftextWsb($hub3a, 12, 0, $x_iban2, 162, $black, $font_times, $iban);

		$img_file = (string) $order->get_meta( '_wsb_hub3_barcode' );
		$barcode_big = Wsb_Hub3_Files::path( $img_file );
		$barcode_resized = false;
		$img_type = ( $img_file && is_file( $barcode_big ) ) ? getimagesize( $barcode_big ) : false;
		if ( ! $img_type ) {
			$img_type = array( 2 => 0 );
		}
		if($img_type[2] == 1){ //gif
			$barcode_resized = $this->resize_barcode_image(imagecreatefromgif(esc_html($barcode_big)));
		} 
        if($img_type[2] == 2){ //jpg
			$barcode_resized = $this->resize_barcode_image(imagecreatefromjpeg(esc_html($barcode_big)));
		} 
        if($img_type[2] == 3){ //png
			$barcode_resized = $this->resize_barcode_image(imagecreatefrompng(esc_html($barcode_big)));
		}

		if($barcode_resized && $barcode_big){
			imagecopy($hub3a, $barcode_resized, 31, 300, 0, 0, imagesx($barcode_resized), imagesy($barcode_resized));
		}
		
		$previous_slip = (string) $order->get_meta( '_wsb_hub3_slip' );
		$hub3_image = Wsb_Hub3_Files::name( $order, 'hub-3a', 'jpg' );
		if ( '' !== $previous_slip && $previous_slip !== $hub3_image && Wsb_Hub3_Files::exists( $previous_slip ) ) {
			unlink( Wsb_Hub3_Files::path( $previous_slip ) );
		}
		if(!imagejpeg($hub3a, Wsb_Hub3_Files::path( $hub3_image ), 100)){
			$hub3_image = "";
		}
		imagedestroy($hub3a);
		if($barcode_resized){
			imagedestroy($barcode_resized);
		}
			return $hub3_image;
	}

	private function imagettftextWsb($image, $size, $angle, $x, $y, $color, $font, $text, $spacing = 0)
	{        
		if ($spacing == 0)
		{
			imagettftext($image, $size, $angle, $x, $y, $color, $font, $text);
		}
		else
		{
			$temp_x = $x;
			for ($i = 0; $i < strlen($text); $i++)
			{
				$bbox = imagettftext($image, $size, $angle, (int) $temp_x, (int) $y, $color, $font, $text[$i]);
				$temp_x += $spacing + 14.7;
			}
		}
	}

	private function fit_font_size($size, $font, $text, $max_width, $min_size = 8)
	{
		while ($size > $min_size) {
			$bbox = imagettfbbox($size, 0, $font, (string) $text);
			if ($bbox[2] - $bbox[0] <= $max_width) {
				break;
			}
			$size -= 0.5;
		}
		return $size;
	}

	private function resize_barcode_image($image) {
		if($new_image = imagescale($image, 305, -1,  IMG_BICUBIC_FIXED)){
			return $new_image;
		} else {
			return false;
		}
	}

	/**
	 * Re-creates a barcode and HUB3 on admin order update.
	 *
	 * @since    1.0.3
	 */
	public function wsb_hub3_admin_order_update($order_id, $order){
		
		//$order = wc_get_order( $order_id );
		
		//exit;
		//remove_action( 'woocommerce_update_order', __FUNCTION__, 25, 2 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$data = array();
		$data['payment_method'] = $order->get_payment_method();
		if('bacs' != $data['payment_method']) {
			return;
		}
		$data['billing_country'] = $order->get_billing_country();
		$data['billing_first_name'] = $order->get_billing_first_name();
		$data['billing_last_name'] = $order->get_billing_last_name();
		$data['billing_address_1'] = $order->get_billing_address_1();
		$data['billing_postcode'] = $order->get_billing_postcode();
		$data['billing_city'] = $order->get_billing_city();
		//var_dump($data);
		$this->wsb_hub3_update_barcode_meta($order_id, $data);
	}

	/**
	 * Direct bank transfer (BACS) accounts with a valid IBAN, which the customer can choose to pay to.
	 * $invalid receives the names of accounts whose IBAN is not valid.
	 * @since    3.1.0
	 */
	public static function bacs_accounts( &$invalid = null ) {
		$invalid  = array();
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( empty( $gateways['bacs'] ) ) {
			return array();
		}
		// A separate validator, so skipped accounts don't show up as settings errors.
		$validator = new Wsb_Hub3_Validator();
		$accounts  = array();
		foreach ( (array) $gateways['bacs']->account_details as $account ) {
			$iban = strtoupper( preg_replace( '/\s+/', '', (string) ( $account['iban'] ?? '' ) ) );
			if ( '' === $iban || isset( $accounts[ $iban ] ) ) {
				continue;
			}
			if ( ! $validator->is_valid_iban( $iban ) ) {
				$invalid[] = (string) ( $account['account_name'] ?? $iban );
				continue;
			}
			$accounts[ $iban ] = array(
				'iban'         => $iban,
				'account_name' => (string) ( $account['account_name'] ?? '' ),
				'bank_name'    => (string) ( $account['bank_name'] ?? '' ),
			);
		}
		return array_values( $accounts );
	}

	/**
	 * "ZABA - Zagrebacka banka (HR46 2360 ...)" label for an account from bacs_accounts().
	 */
	public static function account_label( $account ) {
		$name = implode( ' - ', array_filter( array( $account['account_name'], $account['bank_name'] ) ) );
		return trim( $name . ' (' . trim( chunk_split( $account['iban'], 4, ' ' ) ) . ')' );
	}

	/**
	 * Saves the IBAN chosen at checkout. Unknown values fall back to the first account, so a tampered request can't set another IBAN.
	 */
	private function save_checkout_iban( $order, $requested ) {
		$ibans = wp_list_pluck( self::bacs_accounts(), 'iban' );
		if ( ! $ibans ) {
			return;
		}
		$requested = strtoupper( preg_replace( '/\s+/', '', (string) $requested ) );
		$order->update_meta_data( '_wsb_barcode_iban', in_array( $requested, $ibans, true ) ? $requested : $ibans[0] );
	}

	/**
	 * Lets the block checkout (Store API) send the chosen IBAN.
	 * @since    3.1.0
	 */
	public function wsb_hub3_register_store_api_data() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data( array(
			'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
			'namespace'       => 'wsb-hub3',
			'schema_callback' => function () {
				return array(
					'iban' => array(
						'description' => __( 'Account to pay', 'wsb-hub3' ),
						'type'        => array( 'string', 'null' ),
						'context'     => array(),
					),
				);
			},
		) );
	}

	/**
	 * Block checkout: runs before the order is saved, so the barcode is generated with the chosen IBAN.
	 * @since    3.1.0
	 */
	public function wsb_hub3_store_api_save_iban( $order, $request ) {
		if ( 'bacs' !== $order->get_payment_method() ) {
			return;
		}
		$extensions = (array) $request->get_param( 'extensions' );
		$this->save_checkout_iban( $order, $extensions['wsb-hub3']['iban'] ?? '' );
	}

	/**
	 * Adds the IBAN choice to the block checkout.
	 * @since    3.1.0
	 */
	public function wsb_hub3_register_checkout_block( $integration_registry ) {
		require_once plugin_dir_path( __FILE__ ) . 'class-wsb-hub3-blocks-integration.php';
		$integration_registry->register( new Wsb_Hub3_Blocks_Integration( $this->version ) );
	}

	/**
	 * Lists the account chosen for the HUB3 payment first and marks it, on the thank-you page and in emails.
	 * @since    3.1.0
	 */
	public function wsb_hub3_mark_chosen_account( $accounts, $order_id ) {
		$order  = $order_id ? wc_get_order( $order_id ) : false;
		$chosen = $order ? (string) $order->get_meta( '_wsb_barcode_iban' ) : '';
		if ( '' === $chosen || count( (array) $accounts ) < 2 ) {
			return $accounts;
		}
		foreach ( $accounts as $i => $account ) {
			if ( strtoupper( preg_replace( '/\s+/', '', (string) ( $account['iban'] ?? '' ) ) ) === $chosen ) {
				$account['account_name'] = trim( ( $account['account_name'] ?? '' ) . ' ' . __( '(selected for payment)', 'wsb-hub3' ) );
				unset( $accounts[ $i ] );
				array_unshift( $accounts, $account );
				break;
			}
		}
		return $accounts;
	}

	/**
	 * Classic checkout: radio buttons with the bank accounts in the bank transfer description.
	 *
	 * @since    2.0
	 */
	function wsb_hub3_gateway_description( $description, $gateway_id ) {
		if ( 'bacs' !== $gateway_id || ! is_checkout() ) {
			return $description;
		}
		$accounts = self::bacs_accounts();
		// With one account there is nothing to choose; it is saved automatically.
		if ( count( $accounts ) < 2 ) {
			return $description;
		}
		$options = array();
		foreach ( $accounts as $account ) {
			$options[ $account['iban'] ] = self::account_label( $account );
		}
		return $description . woocommerce_form_field( '_wsb_barcode_iban', array(
			'type'     => 'radio',
			'class'    => array( 'wsb-hub3-iban-choice', 'form-row-wide' ),
			'label'    => __( 'Account to pay', 'wsb-hub3' ),
			'required' => true,
			'return'   => true,
			'options'  => $options,
		), $accounts[0]['iban'] );
	}

}
