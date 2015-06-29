<?php
/**
 * Events Manager Pro Stripe Gateway
 *
 * @package   EM_Gateway_Stripe
 * @author    Oliveconcepts <info@oliveconcepts.com>
 * @link      http://oliveconcepts.com
 * @copyright 2015 Oliveconcepts.com
 */
/**
 * Paypal Pro Gateway class
 *
 * @package EM_Gateway_PapalPro
 * @author  Oliveconcepts <info@oliveconcepts.com>
 */
class EM_Gateway_Stripe extends EM_Gateway {
		//change these properties below if creating a new gateway, not advised to change this for PaypalPro
		var $gateway = 'emp_stripe';
		var $title = 'Stripe';
		var $status = 4;
		var $status_txt = 'Processing (Stripe)';
		var $button_enabled = false; //we can's use a button here
		var $supports_multiple_bookings = true;
		var $registered_timer = 0;
		/**
		 * Sets up gateaway and adds relevant actions/filters 
		 */
		function __construct() {
			parent::__construct();
			if($this->is_active()) {
				//Force SSL for booking submissions, since we have card info
				if(get_option('em_'.$this->gateway.'_mode') == 'live'){ //no need if in sandbox mode
					$this->testmode="no";
				}
				else
				{
					$this->testmode="yes";
				}
				$this->test_publishable_key=get_option('em_'.$this->gateway.'_test_publishable_key');
				$this->test_secret_key=get_option('em_'.$this->gateway.'_test_secret_key');
				$this->live_publishable_key=get_option('em_'.$this->gateway.'_live_publishable_key');
				$this->live_secret_key=get_option('em_'.$this->gateway.'_live_secret_key');
				$this->SecretKey=($this->testmode=='yes')?$this->test_secret_key:$this->live_secret_key;
				$this->debug=get_option('em_'.$this->gateway.'_debug',false);			
			}
		}
		/* 
		 * --------------------------------------------------
		 * Booking Interception - functions that modify booking object behaviour
		 * --------------------------------------------------
		 */
		/**
		 * This function intercepts the previous booking form url from the javascript localized array of EM variables and forces it to be an HTTPS url. 
		 * @param array $localized_array
		 * @return array
		 */
		function em_wp_localize_script($localized_array){
			$localized_array['bookingajaxurl'] = $this->force_ssl($localized_array['bookingajaxurl']);
			return $localized_array;
		}
		/**
		 * Turns any url into an HTTPS url.
		 * @param string $url
		 * @return string
		 */
		function force_ssl($url){
			return str_replace('http://','https://', $url);
		}
		/**
		 * Triggered by the em_booking_add_yourgateway action, modifies the booking status if the event isn't free and also adds a filter to modify user feedback returned.
		 * @param EM_Event $EM_Event
		 * @param EM_Booking $EM_Booking
		 * @param boolean $post_validation
		 */
		function booking_add($EM_Event,$EM_Booking, $post_validation = false){
			global $wpdb, $wp_rewrite, $EM_Notices;
			$this->registered_timer = current_time('timestamp', 1);
			parent::booking_add($EM_Event, $EM_Booking, $post_validation);
			if( $post_validation && empty($EM_Booking->booking_id) ){
				if( get_option('dbem_multiple_bookings') && get_class($EM_Booking) == 'EM_Multiple_Booking' ){
					add_filter('em_multiple_booking_save', array(&$this, 'em_booking_save'),2,2);			    
				}else{
					add_filter('em_booking_save', array(&$this, 'em_booking_save'),2,2);
				}		    	
			}
		}
		/**
		 * Added to filters once a booking is added. Once booking is saved, we capture payment, and approve the booking (saving a second time). If payment isn't approved, just delete the booking and return false for save. 
		 * @param bool $result
		 * @param EM_Booking $EM_Booking
		 */
		function em_booking_save( $result, $EM_Booking ){
			global $wpdb, $wp_rewrite, $EM_Notices;
			//make sure booking save was successful before we try anything
			if( $result ){
				if( $EM_Booking->get_price() > 0 ){
					//handle results
					$capture = $this->processStripe($EM_Booking);
					if($capture){
						//Set booking status, but no emails sent
						if( !get_option('em_'.$this->gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval') ){
							$EM_Booking->set_status(1, false); //Approve
						}else{
							$EM_Booking->set_status(0, false); //Set back to normal "pending"
						}
					}else{
						//not good.... error inserted into booking in capture function. Delete this booking from db
						if( !is_user_logged_in() && get_option('dbem_bookings_anonymous') && !get_option('dbem_bookings_registration_disable') && !empty($EM_Booking->person_id) ){
							//delete the user we just created, only if created after em_booking_add filter is called (which is when a new user for this booking would be created)
							$EM_Person = $EM_Booking->get_person();
							if( strtotime($EM_Person->data->user_registered) >= $this->registered_timer ){
								if( is_multisite() ){
									include_once(ABSPATH.'/wp-admin/includes/ms.php');
									wpmu_delete_user($EM_Person->ID);
								}else{
									include_once(ABSPATH.'/wp-admin/includes/user.php');
									wp_delete_user($EM_Person->ID);
								}
								//remove email confirmation
								global $EM_Notices;
								$EM_Notices->notices['confirms'] = array();
							}
						}
						$EM_Booking->delete();
						return false;
					}
				}
			}
			return $result;
		}
		/**
		 * Intercepts return data after a booking has been made and adds paypal pro vars, modifies feedback message.
		 * @param array $return
		 * @param EM_Booking $EM_Booking
		 * @return array
		 */
		function booking_form_feedback( $return, $EM_Booking = false ){
			//Double check $EM_Booking is an EM_Booking object and that we have a booking awaiting payment.
			if( !empty($return['result']) ){
				if( !empty($EM_Booking->booking_meta['gateway']) && $EM_Booking->booking_meta['gateway'] == $this->gateway && $EM_Booking->get_price() > 0 ){
					$return['message'] = get_option('em_emp_stripe_booking_feedback');
				}else{
					//returning a free message
					$return['message'] = get_option('em_emp_stripe_booking_feedback_free');
				}
			}
			return $return;
		}
		/* 
		 * --------------------------------------------------
		 * Booking UI - modifications to booking pages and tables containing Paypal Pro bookings
		 * --------------------------------------------------
		 */
		/**
		 * Outputs custom content and credit card information.
		 */
		function booking_form(){
			echo get_option('em_'.$this->gateway.'_form');
			?>
			<p class="em-bookings-form-gateway-cardno">
			  <label><?php  _e('Card Number','em-pro'); ?></label>
			  <input type="text" size="15" name="stripe_card_num" value="" class="input" />
			</p>
			<p class="em-bookings-form-gateway-expiry">
			  <label><?php  _e('Expiration Date','em-pro'); ?></label>
			  <span class="expire_date"><select name="stripe_exp_date_month"  style="width:150px; display:inline;">
				<?php 
					for($i = 1; $i <= 12; $i++){
						$m = $i > 9 ? $i:"0$i";
						echo "<option>$m</option>";
					} 
				?>
			  </select> / 
			  <select name="stripe_exp_date_year" style="width:150px; display:inline;">
				<?php 
					$year = date('Y',current_time('timestamp'));
					for($i = $year; $i <= $year+10; $i++){
						echo "<option>$i</option>";
					}
				?>
			  </select></span>
			</p>
			<p class="em-bookings-form-ccv">
			  <label><?php  _e('CCV','em-pro'); ?></label>
			  <input type="text" size="4" name="stripe_card_code" value="" class="input" />
			</p>
            <?php 
		}
		/**
		 * Retreive the paypal pro vars needed to send to the gateway to proceed with payment
		 * @param EM_Booking $EM_Booking
		 */
		function processStripe($EM_Booking){
			global $EM_Notices;
			
			if(empty($_POST['stripe_card_num']))
			{
				$EM_Booking->add_error(__('Please enter credit card number', 'em-pro'). '"');
				return false;
			}
			if(empty($_POST['stripe_exp_date_month']))
			{
				$EM_Booking->add_error(__('Please select expire month', 'em-pro'). '"');
				return false;
			}
			if(empty($_POST['stripe_exp_date_year']))
			{
				$EM_Booking->add_error(__('Please select expire year', 'em-pro').'"');
				return false;
			}
			if(empty($_POST['stripe_card_code']))
			{
				$EM_Booking->add_error(__('Please enter CVV number', 'em-pro').'"');
				return false;
			}
			if($this->debug=='yes'){
				// Send request to paypal
				EM_Pro::log(sprintf( __( 'Payment Processing Start here', 'emp_stripe' )));
			}
			// Get the credit card details submitted by the form
			include("lib/Stripe.php");
			if($this->debug=='yes'){
				EM_Pro::log(sprintf( __( 'Payment Processing start after include library', 'emp_stripe' )));
			}
			Stripe::setApiKey($this->SecretKey);
			if($this->debug=='yes'){
				EM_Pro::log(sprintf( __( 'Set Secret Key', 'emp_stripe' )));
			}
			try {
				$amount = $EM_Booking->get_price(false, false, true);
				if($this->debug=='yes'){
				EM_Pro::log(sprintf( __( 'Credit Card token create', 'emp_stripe' )));
				}
				$token_id = Stripe_Token::create(array(
				 "card" => array( 
							"number" => $_POST['stripe_card_num'], 
							"exp_month" => $_POST['stripe_exp_date_month'], 
							"exp_year" => $_POST['stripe_exp_date_year'], 
							"cvc" => $_POST['stripe_card_code'] ) 
				 ));
				if($this->debug=='yes'){
					EM_Pro::log(sprintf( __( 'Token genreated ID : %s', 'emp_stripe'),print_r($token_id->id,true)));
				}
			
				//Email Info
				$email_customer = get_option('em_'.$this->gateway.'_header_email_customer',0) ? '1':'0'; //for later
				$header_email_receipt = get_option('em_'.$this->gateway.'_header_email_receipt');
				$footer_email_receipt = get_option('em_'.$this->gateway.'_footer_email_receipt');
				//Order Info
				$booking_id = $EM_Booking->booking_id;
				$booking_description = preg_replace('/[^a-zA-Z0-9\s]/i', "", $EM_Booking->get_event()->event_name); //clean event name
				$charge = Stripe_Charge::create(array( 
					"amount" => $amount*100, 
					"currency" => get_option('dbem_bookings_currency', 'USD'), 
					"card" => $token_id->id, 
					"metadata" => array("order_id" => $booking_id),
					"description"=> $booking_description
				));
				
				if($this->debug=='yes'){
					EM_Pro::log(sprintf( __( 'Return Response from Stripe: %s', 'emp_stripe'),print_r($charge,true)));
				}
				
				if($token_id->id !=''){
					if ($charge->paid == true) {
						if($this->debug=='yes'){
							EM_Pro::log(sprintf( __( 'Payment Received...', 'emp_stripe' )));
						}
						$EM_Booking->booking_meta[$this->gateway] = array('txn_id'=>$charge->id, 'amount' => $amount);
						$this->record_transaction($EM_Booking, $amount, get_option('dbem_bookings_currency', 'USD'), date('Y-m-d H:i:s', current_time('timestamp')), $charge->id, 'Completed', '');
						$result = true;
					}
					else
					{
						if($this->debug=='yes'){
							EM_Pro::log(sprintf( __( 'Stripe payment failed. Payment declined.', 'emp_stripe' )));
						}
						$EM_Booking->add_error('Stripe payment failed. Payment declined.');	
						$result =  false;
					}
				}
				else
				{
					if($this->debug=='yes'){
					EM_Pro::log(sprintf( __( 'Stripe payment failed. Payment declined. Please Check your Admin settings', 'emp_stripe' )));
					}
					$EM_Booking->add_error('Stripe payment failed. Payment declined. Please Check your Admin settings');
				}
				//Return transaction_id or false
				return apply_filters('em_gateway_stripe_capture', $result, $EM_Booking, $this);
			}catch(Exception $e) {
				$EM_Booking->add_error(__('Connection error:', 'em-pro') . ': "' . $e->getMessage() . '"');
				return false;
			}
			
		}
		/*
		 * --------------------------------------------------
		 * Gateway Settings Functions
		 * --------------------------------------------------
		 */
		/**
		 * Outputs custom PayPal setting fields in the settings page 
		 */
		function mysettings() {
			global $EM_options;
			?>
			<table class="form-table">
			<tbody>
			  <tr valign="top">
				  <th scope="row"><?php _e('Success Message', 'em-pro') ?></th>
				  <td>
					<input type="text" name="_booking_feedback" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('The message that is shown to a user when a booking is successful and payment has been taken.','em-pro'); ?></em>
				  </td>
			  </tr>
			  <tr valign="top">
				  <th scope="row"><?php _e('Success Free Message', 'em-pro') ?></th>
				  <td>
					<input type="text" name="_booking_feedback_free" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_free" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('If some cases if you allow a free ticket (e.g. pay at gate) as well as paid tickets, this message will be shown and the user will not be charged.','em-pro'); ?></em>
				  </td>
			  </tr>
			</tbody>
			</table>
			<h3><?php echo sprintf(__('%s Options','dbem'),'Stripe')?></h3>
			<table class="form-table">
			<tbody>
            	<tr valign="top">
                      <th scope="row"><?php _e('Stripe Currency', 'em-pro') ?></th>
                      <td><?php echo esc_html(get_option('dbem_bookings_currency','USD')); ?><br /><i><?php echo sprintf(__('Set your currency in the <a href="%s">settings</a> page.','dbem'),EM_ADMIN_URL.'&amp;page=events-manager-options#bookings'); ?></i></td>
                </tr>
                <tr valign="top">
					  <th scope="row"><?php _e('Mode', 'em-pro'); ?></th>
					  <td>
						  <select name="_mode">
							<?php $selected = get_option('em_'.$this->gateway.'_mode'); ?>
							<option value="sandbox" <?php echo ($selected == 'sandbox') ? 'selected="selected"':''; ?>><?php _e('Sandbox','emp-pro'); ?></option>
							<option value="live" <?php echo ($selected == 'live') ? 'selected="selected"':''; ?>><?php _e('Live','emp-pro'); ?></option>
						  </select>
					  </td>
				</tr>
				<tr valign="top">
					  <th scope="row"><?php _e('Test Secret Key', 'emp-pro') ?></th>
					  <td><input type="text" name="_test_secret_key" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_test_secret_key", "" )); ?>" style='width: 40em;' /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Test Publishable Key', 'emp-pro') ?></th>
					<td><input type="text" name="_test_publishable_key" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_test_publishable_key", "" )); ?>" style='width: 40em;' /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Live Secret Key', 'emp-pro') ?></th>
					<td><input type="text" name="_live_secret_key" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_live_secret_key", "" )); ?>" style='width: 40em;' /></td>
				</tr>
                <tr valign="top">
					<th scope="row"><?php _e('Live Publishable Key', 'emp-pro') ?></th>
					<td><input type="text" name="_live_publishable_key" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_live_publishable_key", "" )); ?>" style='width: 40em;' /></td>
				</tr>
                <tr valign="top">
                      <th scope="row"><?php _e('Debug Mode', 'em-pro'); ?></th>
                      <td>
                        <select name="_debug">
                          <option value="no" <?php if (get_option('em_'. $this->gateway . "_debug" ) == 'no') echo 'selected="selected"'; ?>><?php _e('Off', 'em-pro') ?></option>
                          <option value="yes" <?php if (get_option('em_'. $this->gateway . "_debug" ) == 'yes') echo 'selected="selected"'; ?>><?php _e('On', 'em-pro') ?></option>
                        </select>
                      </td>
                </tr>
                <tr valign="top">
				  <th scope="row"><?php _e('Manually approve completed transactions?', 'em-pro') ?></th>
				  <td>
					<input type="checkbox" name="_manual_approval" value="1" <?php echo (get_option('em_'. $this->gateway . "_manual_approval" )) ? 'checked="checked"':''; ?> /><br />
					<em><?php _e('By default, when someone pays for a booking, it gets automatically approved once the payment is confirmed. If you would like to manually verify and approve bookings, tick this box.','em-pro'); ?></em><br />
					<em><?php echo sprintf(__('Approvals must also be required for all bookings in your <a href="%s">settings</a> for this to work properly.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options'); ?></em>
				  </td>
			  </tr>
			</tbody>
			</table>
			<?php
		}
		/* 
		 * Run when saving settings, saves the settings available in EM_Gateway_Authorize_AIM::mysettings()
		 */
		function update() {
			parent::update();
			$gateway_options = array(
				$this->gateway . "_mode" => $_REQUEST[ '_mode' ],
				$this->gateway . "_test_publishable_key" => $_REQUEST[ '_test_publishable_key' ],
				$this->gateway . "_test_secret_key" => $_REQUEST[ '_test_secret_key' ],
				$this->gateway . "_live_publishable_key" => $_REQUEST[ '_live_publishable_key' ],
				$this->gateway . "_live_secret_key" => ($_REQUEST[ '_live_secret_key' ]),
				$this->gateway . "_email_customer" => ($_REQUEST[ '_email_customer' ]),
				$this->gateway . "_header_email_receipt" => $_REQUEST[ '_header_email_receipt' ],
				$this->gateway . "_footer_email_receipt" => $_REQUEST[ '_footer_email_receipt' ],
				$this->gateway . "_manual_approval" => $_REQUEST[ '_manual_approval' ],
				$this->gateway . "_booking_feedback" => wp_kses_data($_REQUEST[ '_booking_feedback' ]),
				$this->gateway . "_booking_feedback_free" => wp_kses_data($_REQUEST[ '_booking_feedback_free' ]),
				$this->gateway . "_debug" => $_REQUEST['_debug' ]
			);
			foreach($gateway_options as $key=>$option){
				update_option('em_'.$key, stripslashes($option));
			}
			//default action is to return true
			return true;
		}
	}
?>