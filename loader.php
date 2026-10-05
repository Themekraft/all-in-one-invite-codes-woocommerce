<?php

/**
 * Plugin Name: All in One Invite Codes WooCommerce
 * Plugin URI: https://themekraft.com/products/all-in-one-invite-codes-woocommerce/
 * Description: Create Invite only Products with WooCommerce
 * Version: 1.0.5-beta.1
 * Author: ThemeKraft
 * Author URI: https://themekraft.com/
 * Licence: GPLv3
 * Network: false
 * WC tested up to: 11.1
 * Text Domain: all-in-one-invite-codes-woocommerce
 * Domain Path: /languages
 *
 * ****************************************************************************
 *
 * This script is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.    See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA    02111-1307    USA
 *
 ****************************************************************************
 */

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

function aioic_woocommerce_load_plugin_textdomain()
{
	load_plugin_textdomain('all-in-one-invite-codes-woocommerce', false, basename(dirname(__FILE__)) . '/languages');
}
add_action('init', 'aioic_woocommerce_load_plugin_textdomain');
add_action('admin_enqueue_scripts', 'aioic_woocommerce_admin_scripts', 100, 1);

function aioic_woocommerce_admin_scripts()
{
	wp_enqueue_script('aioic_woocommerce_admin',  plugins_url('/', __FILE__) . 'assets/js/aioic_woocommerce.js', array('jquery'), false, true);
}

function aioic_woocommerce_purchase_complete($order_id)
{

	$order = wc_get_order($order_id);
	// Get and Loop Over Order Items
	foreach ($order->get_items() as $item_id => $item) {
		$product_id = $item->get_product_id();
		$quantity = $item->get_quantity();
		$is_sell_invite_code = get_post_meta($product_id, 'sell_invite_code', true);
		for ($i = 0; $i < $quantity; $i++) {
			
			if ($is_sell_invite_code == 'on') {
				$generate_invite_code = get_post_meta($product_id, 'invites_codes_amount', true);
				$user_id = get_current_user_id();
				$args        = array(
					'post_type'   => 'tk_invite_codes',
					'post_author' => $user_id,

					'post_status' => 'publish',
					'post_title'  => '',
				);
				$new_code_id = wp_insert_post($args);

				// Create and save the new invite code as post meta
				$code = all_in_one_invite_codes_md5($new_code_id);
				$code = wp_filter_post_kses($code);

				$args        = array(
					'ID'          => $new_code_id,
					'post_title'  => $code,
				);
				wp_update_post($args);

				update_post_meta($new_code_id, 'tk_all_in_one_invite_code', $code);

				$all_in_one_invite_codes_new_options['generate_codes'] = $generate_invite_code;
				$all_in_one_invite_codes_new_options['code_total'] = $generate_invite_code;
				$all_in_one_invite_codes_new_options['type'] = 'any';
				$all_in_one_invite_codes_new_options['multiple_use'] = true;
				update_post_meta($new_code_id, 'all_in_one_invite_codes_options', $all_in_one_invite_codes_new_options);
				update_post_meta($new_code_id, 'tk_all_in_one_invite_code_status', 'Active');
			}
		}
	}
	$order_meta =  $order->get_meta_data();
	$invite_code = false;
	foreach ($order_meta as $index => $value) {

		$current_data = $value->get_data();
		if (isset($current_data['key']) && $current_data['key'] == "all_in_one_invite_codes_woo_product") {

			$invite_code =  isset($current_data['value']) ? $current_data['value'] : false;
			break;
		}
	}
	if ($invite_code) {
		// Get all invite codes with this code. Should only be one post.
		$args  = array(
			'post_type'  => 'tk_invite_codes',
			'meta_query' => array(
				array(
					'key'     => 'tk_all_in_one_invite_code',
					'value'   => $invite_code,
					'compare' => '=',
				)
			)
		);
		$query = new WP_Query($args);
		// If have posts means we have a valid code.
		if ($query->have_posts()) {

			$post_id = $query->get_posts()[0]->ID;

			update_post_meta($post_id, 'tk_all_in_one_invite_code_status', 'Used');
		}
	}
}

function saveProductOptionsFields($post_id, $post)
{

	$product = wc_get_product($post_id);

	if (empty($product)) {
		return;
	}
	$type = $product->get_type();

	$invite_only = isset($_POST['invite_only']) ? $_POST['invite_only'] : false;
	if ($invite_only) {
		update_post_meta($post_id, 'invite_code', $invite_only);
	} else {
		delete_post_meta($post_id, 'invite_code');
	}

	$sell_invite = isset($_POST['sell_invite_code']) ? $_POST['sell_invite_code'] : false;
	if ($sell_invite) {
		update_post_meta($post_id, 'sell_invite_code', $sell_invite);
	} else {
		delete_post_meta($post_id, 'sell_invite_code');
	}

	$aioic_generate_invites = isset($_POST['invites_codes_amount']) ? $_POST['invites_codes_amount'] : false;
	if ($aioic_generate_invites) {
		update_post_meta($post_id, 'invites_codes_amount', $aioic_generate_invites);
	} else {
		delete_post_meta($post_id, 'invites_codes_amount');
	}
}


/**
 * Add content to generated tab
 */
function addInviteCodeTabContent()
{

	$product_id                  = isset($_GET['post']) ? $_GET['post'] : false;
	$is_checked                  = '';
	$is_sell_invite_code_checked = '';
	$generate_invite_code_value  = 0;
	$generate_invite_code_visibility = 'style="display:none"';
	if ($product_id) {

		$result = get_post_meta($product_id, 'invite_code', true);
		if ($result == 'on') {
			$is_checked = 'checked="true"';
		}
		$is_sell_invite_code = get_post_meta($product_id, 'sell_invite_code', true);
		if ($is_sell_invite_code == 'on') {
			$is_sell_invite_code_checked = 'checked="true"';
			$generate_invite_code_visibility = 'style="display:block"';
		}
		$generate_invite_code = get_post_meta($product_id, 'invites_codes_amount', true);
		if (!empty($generate_invite_code)) {
			$generate_invite_code_value = $generate_invite_code;
		}
	}

	echo '<div id="invite-code" class="panel woocommerce_options_panel wc-metaboxes-wrapper">';

	echo '<p class="form-field"><label style="width: 50%">' . __('Make this product invite only ', 'all-in-one-invite-codes-woocommerce') . ' :</label>  <input style="margin-right: 15px;" type="checkbox" name="invite_only"' . $is_checked . ' > <i> ' . __('This will add an invite validation to the checkout', 'all-in-one-invite-codes-woocommerce') . ' </i></p>';
	echo '<p class="form-field" id="sell_invite_codes"><label style="width: 50%">' . __('Sell Invite Codes', 'all-in-one-invite-codes-woocommerce') . ' :</label>  <input id="aioic_sell_invite_codes_chbx" style="margin-right: 15px;" type="checkbox" name="sell_invite_code"' . $is_sell_invite_code_checked . ' > <i> ' . __('A new Invite Code will be created when this product is purchased', 'all-in-one-invite-codes-woocommerce') . ' </i></p>';

	echo '<p class="form-field" id="aioic_generate_invite_codes"' . $generate_invite_code_visibility . '><label style="width: 50%">' . __('Invite Codes to Generate', 'all-in-one-invite-codes-woocommerce') . ' :</label>  <input style="width: 25%;;" type="number" name="invites_codes_amount" value="' . $generate_invite_code_value . '" > <i style="padding-left: 10px; display:table;"> ' . __('Amount of Invite Code invitations that will be generated when this product is purchased', 'all-in-one-invite-codes-woocommerce') . ' </i></p>';

	echo '</div>';
}

/**
 * Add new tab to general product tabs
 *
 * @param $sections
 *
 * @return mixed
 */
function addInviteCodeSection($sections)
{


	$sections['invite-code'] = array(
		'label'  => 'Invite Code',
		'target' => 'invite-code',
		'class'  => array(),
	);

	return $sections;
}

/**
 * Add the field to the checkout
 **/
add_action('woocommerce_after_order_notes', 'all_in_one_invite_codes_checkout_field');


function all_in_one_invite_codes_checkout_field($checkout)
{
	global $woocommerce;
	$invite_only_in_cart = false;
	foreach ($woocommerce->cart->get_cart() as $cart_item_key => $values) {
		$_product = $values['data'];

		if ($_product->get_id()) {
			$result = get_post_meta($_product->get_id(), 'invite_code', true);
			if ($result == 'on') {
				$invite_only_in_cart = true;
				break;
			}
		}
	}

	if ($invite_only_in_cart === true) {
		echo '<div id="all_in_one_invite_code"><h3>' . __('Invite Only Product', 'all-in-one-invite-codes-woocommerce') . '</h3><p style="margin: 0 0 8px;">' . __('Please add your invite code here', 'all-in-one-invite-codes-woocommerce') . '!</p>';


		woocommerce_form_field('all_in_one_invite_codes_woo_product', array(
			'type'     => 'text',
			'class'    => array('inscription-text form-row-wide'),
			'label'    => __('Invite Code'),
			'required' => 'true'
		), $checkout->get_value('all_in_one_invite_codes_woo_product'));

		echo '</div>';
	}
}

add_action( 'all_in_one_invite_codes_add_general_settings_option', 'all_in_one_invite_codes_woo_registration_form_settings', );
function all_in_one_invite_codes_woo_registration_form_settings( $options ){
	?>
	<tr valign="top">
		<th scope="row" valign="top">
			<?php esc_html_e( 'Add Invite code field to WooCommerce registration form', 'all-in-one-invite-codes' ); ?>
		</th>
		<td>
			<select name="all_in_one_invite_codes_general[woo_registration]" id="all_in_one_invite_codes_general_woo">
				<?php $selected = (isset( $options['woo_registration'] ) && $options['woo_registration'] === 'disabled') ? 'selected' : '' ; ?>
				<option value="disabled" <?php echo $selected; ?>>Disable</option>
				<?php $selected = (isset( $options['woo_registration'] ) && $options['woo_registration'] === 'enabled') ? 'selected' : '' ; ?>
				<option value="enabled" <?php echo $selected; ?>>Enable</option>
        	</select>
		</td>
	</tr>

	<?php
}

add_action( 'woocommerce_register_form', 'all_in_one_invite_codes_edit_woo_form', 15 );
function all_in_one_invite_codes_add_invite_field_woo() {
	$general_settings = get_option( 'all_in_one_invite_codes_general' );
	if( ! empty( $general_settings ) && isset( $general_settings['woo_registration'] ) && 'enabled' === $general_settings['woo_registration'] ){
		return apply_filters( 'woocommerce_forms_field', array(
			'invite_code' => array(
				'type'        => 'text',
				'label'       => __( 'Invite code', 'all-in-one-invite-codes-woocommerce' ),
				'placeholder' => __( 'Please enter a valid code', 'all-in-one-invite-codes-woocommerce' ),
				'required'    => true,
			),
		) );
	}
}
function all_in_one_invite_codes_edit_woo_form() {
	$general_settings = get_option( 'all_in_one_invite_codes_general' );
	if( ! empty( $general_settings ) && isset( $general_settings['woo_registration'] ) && 'enabled' === $general_settings['woo_registration'] ){
		$fields = all_in_one_invite_codes_add_invite_field_woo();
		foreach ( $fields as $key => $field_args ) {
			woocommerce_form_field( $key, $field_args );
		}
	}
}

add_action('woocommerce_register_post', 'all_in_one_invite_codes_woo_validate_invite_field', 10, 3);
function all_in_one_invite_codes_woo_validate_invite_field( $username, $email, $validation_errors ) {
    if ( isset( $_POST['invite_code'] ) && empty( $_POST['invite_code'] ) ) {
        $validation_errors->add( 'invite_code_error', __( '<strong>Error</strong>: Invite code is required!', 'all-in-one-invite-codes-woocommerce' ) );
    }

	$is_valid_code = all_in_one_invite_codes_validate_code( $_POST['invite_code'], $_POST['email'], 'any' );

	if( is_array( $is_valid_code ) && isset( $is_valid_code['error'] ) ){
		$validation_errors->add( 'invite_code_error', __( $is_valid_code['error'], 'all-in-one-invite-codes-woocommerce' ) );
	}

    return $validation_errors;
}

add_action('woocommerce_created_customer', 'all_in_one_invite_update_code_woo_registration_form', 10, 3);
function all_in_one_invite_update_code_woo_registration_form( $customer_id, $new_customer_data, $password_generated  ){
	
	if( isset( $_POST['invite_code'] ) ){
		$args  = array(
			'post_type'  => 'tk_invite_codes',
			'meta_query' => array(
				array(
					'key'     => 'tk_all_in_one_invite_code',
					'value'   => $_POST['invite_code'],
					'compare' => '=',
				),
			),
		);
		$query = new WP_Query( $args );
	
		// Get the invite code id
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) :
				$query->the_post();
				$post_parent_post_id = get_the_ID();
			endwhile;
		}
		update_post_meta( $post_parent_post_id, 'tk_all_in_one_invite_code_status', 'Used' );
	}

}

/**
 * Check if Conditional Product is In cart
 *
 * @param $product_id
 *
 * @return bool
 */
function all_in_one_invite_is_conditional_product_in_cart($product_id)
{
	global $woocommerce;

	$invite_only_in_cart = false;

	foreach ($woocommerce->cart->get_cart() as $cart_item_key => $values) {
		$_product = $values['data'];

		if ($_product->get_id() === $product_id) {
			$invite_only_in_cart = true;
		}
	}

	return $invite_only_in_cart;
}

/**
 * Update the order meta with field value
 **/
add_action('woocommerce_checkout_update_order_meta', 'all_in_one_invite_codes_checkout_field_update_order_meta');
function all_in_one_invite_codes_checkout_field_update_order_meta($order_id)
{
	if (!empty($_POST['all_in_one_invite_codes_woo_product'])) {
		$order = wc_get_order($order_id);
		if ($order) {
			$order->update_meta_data('all_in_one_invite_codes_woo_product', sanitize_text_field(wp_unslash($_POST['all_in_one_invite_codes_woo_product'])));
			$order->save();
		}
	}
}

/**
 * Add the field to order emails
 **/
add_filter('woocommerce_email_order_meta_keys', 'all_in_one_invite_codes_order_mail_meta_keys');
function all_in_one_invite_codes_order_mail_meta_keys($keys)
{
	// WooCommerce reads the value from the order and skips orders without it.
	$keys['Invite Code'] = 'all_in_one_invite_codes_woo_product';

	return $keys;
}

add_action('woocommerce_checkout_process', 'all_in_one_invite_codes_woo_checkout_validation');
function all_in_one_invite_codes_woo_checkout_validation()
{

	// you can add any custom validations here
	if (isset($_POST['all_in_one_invite_codes_woo_product'])) {


		if (!empty($_POST['all_in_one_invite_codes_woo_product'])) {

			$result = all_in_one_invite_codes_validate_code($_POST['all_in_one_invite_codes_woo_product'], $_POST['billing_email'], 'woocommerce_checkout');

			if (isset($result['error'])) {
				wc_add_notice($result['error'], 'error');
			}
		} else {

			wc_add_notice(__('This Product needs an invitation. Please enter a valid invite code!', 'all-in-one-invite-codes-woocommerce'), 'error');
		}
	}
}


/**
 * Create new invite codes after the checkout is complete
 *
 * @since  0.1
 *
 */
add_action('woocommerce_order_status_completed', 'all_in_one_invite_code_woo_payment_complete');
function all_in_one_invite_code_woo_payment_complete($order_id)
{


	$order = wc_get_order($order_id);
	if (!$order) {
		return;
	}
	$user = $order->get_user();
	if ($user) {
		$user_id = $user->ID;
		$code    = trim((string) $order->get_meta('all_in_one_invite_codes_woo_product', true));
		if ('' === $code) {
			return;
		}

		// Remember the code the customer used at checkout.
		update_user_meta($user_id, 'tk_all_in_one_invite_code', $code);

		// Get the invite code
		$args  = array(
			'post_type'  => 'tk_invite_codes',
			'meta_query' => array(
				array(
					'key'     => 'tk_all_in_one_invite_code',
					'value'   => $code,
					'compare' => '=',
				)
			)
		);
		$query = new WP_Query($args);


		// Get the invite code id
		if (!$query->have_posts()) {
			return;
		}
		$podt_id = $query->posts[count($query->posts) - 1]->ID;

		// get the invite code options
		$all_in_one_invite_codes_options = get_post_meta($podt_id, 'all_in_one_invite_codes_options', true);

		// Check if and how many new invite code should get created.
		if (isset($all_in_one_invite_codes_options['generate_codes']) && $all_in_one_invite_codes_options['generate_codes'] > 0) {

			// Alright, loop and create all needed codes for this user.
			for ($i = 1; $i <= $all_in_one_invite_codes_options['generate_codes']; $i++) {
				$args        = array(
					'post_type'   => 'tk_invite_codes',
					'post_author' => $user_id,
					'post_parent' => $podt_id,
					'post_status' => 'publish'
				);
				$new_code_id = wp_insert_post($args);

				// Create and save the new invite code as post meta
				$code = all_in_one_invite_codes_md5($new_code_id);
				update_post_meta($new_code_id, 'tk_all_in_one_invite_code', wp_filter_post_kses($code));

				// Assign the amount of new codes to the code witch should get created if one of this codes get used.
				$all_in_one_invite_codes_new_options['generate_codes'] = $all_in_one_invite_codes_options['generate_codes'];
				update_post_meta($new_code_id, 'all_in_one_invite_codes_options', $all_in_one_invite_codes_new_options);
			}
		}
	}
}


add_filter('all_in_one_invite_codes_options_type_options', 'all_in_one_invite_codes_woocommerce_options_type_options');
function all_in_one_invite_codes_woocommerce_options_type_options($options)
{

	$options['woocommerce_checkout'] = 'WooCommerce Purchase Complete';

	return $options;
}
add_filter('all_in_one_invite_codes_options_email_templates', 'all_in_one_invite_codes_woocommerce_options_email_templates');
function all_in_one_invite_codes_woocommerce_options_email_templates($options)
{

	$options['woocommerce_checkout'] = __('WooCommerce Email Template', 'all-in-one-invite-codes');

	return $options;
}

if (!function_exists('wc_fs')) {
	// Create a helper function for easy SDK access.
	function wc_fs()
	{
		global $wc_fs;

		if (!isset($wc_fs)) {
			// Include Freemius SDK.
			if (file_exists(dirname(dirname(__FILE__)) . '/all-in-one-invite-codes/includes/resources/freemius/start.php')) {
				// Try to load SDK from parent plugin folder.
				require_once dirname(dirname(__FILE__)) . '/all-in-one-invite-codes/includes/resources/freemius/start.php';
			} elseif (file_exists(dirname(dirname(__FILE__)) . '/all-in-one-invite-codes-premium/includes/resources/freemius/start.php')) {
				// Try to load SDK from premium parent plugin folder.
				require_once dirname(dirname(__FILE__)) . '/all-in-one-invite-codes-premium/includes/resources/freemius/start.php';
			}

			$wc_fs = fs_dynamic_init(array(
				'id'               => '3326',
				'slug'             => 'woocommerce-checkout',
				'type'             => 'plugin',
				'public_key'       => 'pk_2386bde4e0f20f447639c236a33a8',
				'is_premium'       => true,
				'is_premium_only'  => true,
				'has_paid_plans'   => true,
				'is_org_compliant' => false,
				'trial'            => array(
					'days'               => 7,
					'is_require_payment' => true,
				),
				'parent'           => array(
					'id'         => '3322',
					'slug'       => 'all-in-one-invite-codes',
					'public_key' => 'pk_955be38b0c4d2a2914a9f4bc98355',
					'name'       => 'All in One Invite Codes',
				),
				'menu'             => array(
					'first-path' => 'plugins.php',
					'support'    => false,
				),
				'bundle_license_auto_activation' => true,
			));
		}

		return $wc_fs;
	}
}


function wc_fs_is_parent_active_and_loaded()
{
	// Check if the parent's init SDK method exists.
	return function_exists('all_in_one_invite_codes_core_fs');
}

function wc_fs_is_parent_active()
{
	$active_plugins = get_option('active_plugins', array());

	if (is_multisite()) {
		$network_active_plugins = get_site_option('active_sitewide_plugins', array());
		$active_plugins         = array_merge($active_plugins, array_keys($network_active_plugins));
	}

	foreach ($active_plugins as $basename) {
		if (
			0 === strpos(strtolower($basename), 'all-in-one-invite-codes/') ||
			0 === strpos(strtolower($basename), 'all-in-one-invite-codes-premium/')
		) {
			return true;
		}
	}

	return false;
}

function wc_fs_init()
{
	if (wc_fs_is_parent_active_and_loaded()) {
		// Init Freemius.
		wc_fs();


		// Signal that the add-on's SDK was initiated.
		do_action('wc_fs_loaded');

		if( wc_fs()->is_plan('professional') || wc_fs()->is_trial() ){
			add_filter('woocommerce_product_data_tabs', 'addInviteCodeSection', 10, 1); // Add section
			add_action('woocommerce_product_data_panels', 'addInviteCodeTabContent'); // Add Section Tab content
			add_action('woocommerce_process_product_meta', 'saveProductOptionsFields', 12, 2); // Save option
			add_action('woocommerce_thankyou',  'aioic_woocommerce_purchase_complete');
		}

	} else {
		// Parent is inactive, add your error handling here.
	}
}

if (wc_fs_is_parent_active_and_loaded()) {
	// If parent already included, init add-on.
	wc_fs_init();
} elseif (wc_fs_is_parent_active()) {
	// Init add-on only after the parent is loaded.
	add_action('all_in_one_invite_codes_core_fs_loaded', 'wc_fs_init');
} else {
	// Even though the parent is not activated, execute add-on for activation / uninstall hooks.
	wc_fs_init();
}
