<?php

/**
 * Plugin Name: All in One Invite Codes WooCommerce
 * Plugin URI: https://themekraft.com/products/all-in-one-invite-codes-woocommerce/
 * Description: Create Invite only Products with WooCommerce
 * Version: 1.0.7
 * Requires PHP: 7.4
 * Requires Plugins: all-in-one-invite-codes, woocommerce
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

if ( ! function_exists( 'all_in_one_invite_codes_addon_plugin_dependencies_slug' ) ) {
	/**
	 * Let the running All in One Invite Codes copy (free or premium) satisfy
	 * "Requires Plugins: all-in-one-invite-codes".
	 *
	 * @param string $slug Dependency slug.
	 *
	 * @return string
	 */
	function all_in_one_invite_codes_addon_plugin_dependencies_slug( $slug ) {
		if ( 'all-in-one-invite-codes' === $slug && defined( 'TK_ALL_IN_ONE_INVITE_CODES_INSTALL_PATH' ) ) {
			return basename( TK_ALL_IN_ONE_INVITE_CODES_INSTALL_PATH );
		}

		return $slug;
	}

	add_filter( 'wp_plugin_dependencies_slug', 'all_in_one_invite_codes_addon_plugin_dependencies_slug' );
}

/**
 * Whether All in One Invite Codes (free or premium) is active and in place.
 *
 * Read from the active plugin lists because this add-on loads before its parent,
 * so the parent's functions do not exist yet when this file runs.
 *
 * @return bool
 */
function aioic_woocommerce_parent_is_active() {
	$active_plugins = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	foreach ( array( 'all-in-one-invite-codes', 'all-in-one-invite-codes-premium' ) as $folder ) {
		$basename = $folder . '/all-in-one-invite-codes.php';
		if ( in_array( $basename, $active_plugins, true ) && file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Admin notice shown while All in One Invite Codes is missing.
 */
function aioic_woocommerce_parent_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'All in One Invite Codes WooCommerce is not running because All in One Invite Codes is not active.', 'all-in-one-invite-codes-woocommerce' )
	);
}

// Without the parent, every hook below would call functions that do not exist.
if ( ! aioic_woocommerce_parent_is_active() ) {
	add_action( 'admin_notices', 'aioic_woocommerce_parent_missing_notice' );

	return;
}

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
	// Create the purchased codes once per order, for the customer who placed it.
	if (!$order || $order->get_meta('_aioic_purchased_codes_created')) {
		return;
	}
	$order->update_meta_data('_aioic_purchased_codes_created', time());
	$order->save();

	// Get and Loop Over Order Items
	foreach ($order->get_items() as $item_id => $item) {
		$product_id = $item->get_product_id();
		$quantity = $item->get_quantity();
		$is_sell_invite_code = get_post_meta($product_id, 'sell_invite_code', true);
		for ($i = 0; $i < $quantity; $i++) {

			if ($is_sell_invite_code == 'on') {
				$generate_invite_code = get_post_meta($product_id, 'invites_codes_amount', true);
				$user_id = $order->get_customer_id();
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
	// The invite code used for the order is used up when the order is placed (aioic_woocommerce_consume_invite_code).
}

function aioic_woocommerce_save_product_options_fields($post_id, $post)
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
function aioic_woocommerce_invite_code_tab_content()
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
function aioic_woocommerce_invite_code_section($sections)
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
	if (aioic_woocommerce_cart_needs_invite_code()) {
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
		$fields = apply_filters_deprecated( 'woocommerce_forms_field', array( array(
			'invite_code' => array(
				'type'        => 'text',
				'label'       => __( 'Invite code', 'all-in-one-invite-codes-woocommerce' ),
				'placeholder' => __( 'Please enter a valid code', 'all-in-one-invite-codes-woocommerce' ),
				'required'    => true,
			),
		) ), '1.0.8', 'aioic_woocommerce_forms_field' );

		return apply_filters( 'aioic_woocommerce_forms_field', $fields );
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

/**
 * Whether the WooCommerce registration asks for an invite code (General Settings).
 *
 * @return bool
 */
function aioic_woocommerce_registration_enabled()
{
	$general_settings = get_option( 'all_in_one_invite_codes_general' );

	return is_array( $general_settings ) && isset( $general_settings['woo_registration'] ) && 'enabled' === $general_settings['woo_registration'];
}

/**
 * Read the invite code posted with the WooCommerce registration form.
 *
 * @return string
 */
function aioic_woocommerce_posted_registration_code()
{
	// WooCommerce verified the registration nonce before these hooks.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	return isset( $_POST['invite_code'] ) ? sanitize_key( wp_unslash( $_POST['invite_code'] ) ) : '';
}

add_action('woocommerce_register_post', 'all_in_one_invite_codes_woo_validate_invite_field', 10, 3);
function all_in_one_invite_codes_woo_validate_invite_field( $username, $email, $validation_errors ) {
	// Only when the setting adds the field to the registration form.
	if ( ! aioic_woocommerce_registration_enabled() ) {
		return $validation_errors;
	}

	$code = aioic_woocommerce_posted_registration_code();
	if ( '' === $code ) {
		$validation_errors->add( 'invite_code_error', __( '<strong>Error</strong>: Invite code is required!', 'all-in-one-invite-codes-woocommerce' ) );

		return $validation_errors;
	}

	$is_valid_code = all_in_one_invite_codes_validate_code( $code, $email, 'any' );

	if( is_array( $is_valid_code ) && isset( $is_valid_code['error'] ) ){
		$validation_errors->add( 'invite_code_error', $is_valid_code['error'] );
	}

	return $validation_errors;
}

add_action('woocommerce_created_customer', 'all_in_one_invite_update_code_woo_registration_form', 10, 3);
function all_in_one_invite_update_code_woo_registration_form( $customer_id, $new_customer_data, $password_generated  ){
	if ( ! aioic_woocommerce_registration_enabled() ) {
		return;
	}

	$code    = aioic_woocommerce_posted_registration_code();
	$code_id = '' === $code ? 0 : aioic_woocommerce_get_code_id( $code );
	if ( ! $code_id ) {
		return;
	}

	// Keep the code on the customer, like the other registration forms do.
	update_user_meta( $customer_id, 'tk_all_in_one_invite_code', $code );
	aioic_woocommerce_use_code( $code_id );
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
	if (!aioic_woocommerce_cart_needs_invite_code()) {
		return;
	}

	// WooCommerce verified the checkout nonce before this action.
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$code  = isset($_POST['all_in_one_invite_codes_woo_product']) ? sanitize_key(wp_unslash($_POST['all_in_one_invite_codes_woo_product'])) : '';
	$email = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$result = aioic_woocommerce_check_invite_code($code, $email);
	if (true !== $result) {
		wc_add_notice($result, 'error');
	}
}

/**
 * Whether a product (or its parent product) is set as invite only.
 *
 * @param int $product_id Product or variation id.
 *
 * @return bool
 */
function aioic_woocommerce_is_invite_only_product($product_id)
{
	return $product_id > 0 && 'on' === get_post_meta($product_id, 'invite_code', true);
}

/**
 * Whether the cart holds at least one invite only product.
 *
 * Checks the parent product as well, because the setting is saved on the parent
 * of a variable product.
 *
 * @param WC_Cart|null $cart Cart, defaults to the current one.
 *
 * @return bool
 */
function aioic_woocommerce_cart_needs_invite_code($cart = null)
{
	if (null === $cart && function_exists('WC')) {
		$cart = WC()->cart;
	}
	if (!$cart instanceof WC_Cart) {
		return false;
	}

	foreach ($cart->get_cart() as $item) {
		$product_id   = isset($item['product_id']) ? (int) $item['product_id'] : 0;
		$variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;
		if (aioic_woocommerce_is_invite_only_product($product_id) || aioic_woocommerce_is_invite_only_product($variation_id)) {
			return true;
		}
	}

	return false;
}

/**
 * Whether an order holds at least one invite only product.
 *
 * @param WC_Order $order Order.
 *
 * @return bool
 */
function aioic_woocommerce_order_needs_invite_code($order)
{
	foreach ($order->get_items() as $item) {
		if (!$item instanceof WC_Order_Item_Product) {
			continue;
		}
		if (aioic_woocommerce_is_invite_only_product((int) $item->get_product_id()) || aioic_woocommerce_is_invite_only_product((int) $item->get_variation_id())) {
			return true;
		}
	}

	return false;
}

/**
 * Check the invite code entered for invite only products.
 *
 * @param string $code  Invite code.
 * @param string $email Billing email.
 *
 * @return true|string True when the code can be used, the error message otherwise.
 */
function aioic_woocommerce_check_invite_code($code, $email)
{
	$code = sanitize_key($code);
	if ('' === $code) {
		return __('This Product needs an invitation. Please enter a valid invite code!', 'all-in-one-invite-codes-woocommerce');
	}

	$result = all_in_one_invite_codes_validate_code($code, $email, 'woocommerce_checkout');
	if (is_array($result) && isset($result['error'])) {
		return $result['error'];
	}

	return true;
}

/**
 * Product ids the block checkout uses to show the invite code field: invite only
 * products and their variations.
 *
 * @return int[]
 */
function aioic_woocommerce_invite_only_product_ids()
{
	$ids = get_transient('aioic_woocommerce_invite_only_ids');
	if (is_array($ids)) {
		return $ids;
	}

	$ids = get_posts(array(
		'post_type'      => 'product',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- cached in a transient.
		'meta_key'       => 'invite_code',
		'meta_value'     => 'on',
	));
	if ($ids) {
		$ids = array_merge($ids, get_posts(array(
			'post_type'       => 'product_variation',
			'post_status'     => 'any',
			'posts_per_page'  => -1,
			'fields'          => 'ids',
			'no_found_rows'   => true,
			'post_parent__in' => $ids,
		)));
	}
	$ids = array_values(array_unique(array_map('intval', $ids)));

	set_transient('aioic_woocommerce_invite_only_ids', $ids, HOUR_IN_SECONDS);

	return $ids;
}

/**
 * Forget the cached invite only product ids when a product changes.
 */
function aioic_woocommerce_flush_invite_only_product_ids()
{
	delete_transient('aioic_woocommerce_invite_only_ids');
}
add_action('woocommerce_update_product', 'aioic_woocommerce_flush_invite_only_product_ids');
add_action('woocommerce_new_product', 'aioic_woocommerce_flush_invite_only_product_ids');
add_action('woocommerce_new_product_variation', 'aioic_woocommerce_flush_invite_only_product_ids');
add_action('woocommerce_delete_product_variation', 'aioic_woocommerce_flush_invite_only_product_ids');
add_action('woocommerce_process_product_meta', 'aioic_woocommerce_flush_invite_only_product_ids', 20);

/**
 * Forget the cached invite only product ids when the setting changes outside the product editor.
 *
 * @param int    $meta_id  Meta id.
 * @param int    $post_id  Post id.
 * @param string $meta_key Meta key.
 */
function aioic_woocommerce_flush_invite_only_ids_on_meta($meta_id, $post_id, $meta_key)
{
	if ('invite_code' === $meta_key) {
		aioic_woocommerce_flush_invite_only_product_ids();
	}
}
add_action('added_post_meta', 'aioic_woocommerce_flush_invite_only_ids_on_meta', 10, 3);
add_action('updated_post_meta', 'aioic_woocommerce_flush_invite_only_ids_on_meta', 10, 3);
add_action('deleted_post_meta', 'aioic_woocommerce_flush_invite_only_ids_on_meta', 10, 3);

/**
 * Add the invite code field to the block checkout. It is shown and required only
 * when the cart holds an invite only product (WooCommerce versions with
 * conditional fields); the order is checked on the server either way.
 */
function aioic_woocommerce_register_block_checkout_field()
{
	if (!function_exists('woocommerce_register_additional_checkout_field')) {
		return;
	}

	$args = array(
		'id'                => 'aioic/invite-code',
		'label'             => __('Invite Code', 'all-in-one-invite-codes-woocommerce'),
		'optionalLabel'     => __('Invite Code (only needed for invite only products)', 'all-in-one-invite-codes-woocommerce'),
		'location'          => 'order',
		'type'              => 'text',
		'required'          => false,
		'sanitize_callback' => 'aioic_woocommerce_sanitize_block_invite_code',
	);

	if (class_exists('\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFieldsSchema\Validation')) {
		$ids = aioic_woocommerce_invite_only_product_ids();
		if (!$ids) {
			// No invite only product: nothing to ask for.
			return;
		}
		$in_cart          = array('contains' => array('enum' => $ids));
		$args['required'] = array(
			'type'       => 'object',
			'properties' => array(
				'cart' => array('properties' => array('items' => $in_cart)),
			),
		);
		$args['hidden']   = array(
			'type'       => 'object',
			'properties' => array(
				'cart' => array('properties' => array('items' => array('not' => $in_cart))),
			),
		);
	}

	try {
		woocommerce_register_additional_checkout_field($args);
	} catch (\Throwable $e) {
		// The server side check below still protects the order.
		return;
	}
}
add_action('woocommerce_init', 'aioic_woocommerce_register_block_checkout_field');

/**
 * Sanitize the block checkout invite code.
 *
 * @param mixed $value Field value.
 *
 * @return string
 */
function aioic_woocommerce_sanitize_block_invite_code($value)
{
	return sanitize_key((string) $value);
}

/**
 * Check invite only products on the block checkout (Store API), whatever the
 * request contains, and keep the code on the order like the classic checkout does.
 *
 * @param WC_Order        $order   Order being placed.
 * @param WP_REST_Request $request Store API request.
 *
 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the code is missing or cannot be used.
 */
function aioic_woocommerce_store_api_validate_invite_code($order, $request)
{
	// The checkout block also sends PUT requests while the customer types; only placing the order counts.
	if ('POST' !== $request->get_method() || !aioic_woocommerce_order_needs_invite_code($order)) {
		return;
	}

	$fields = $request->get_param('additional_fields');
	$code   = is_array($fields) && isset($fields['aioic/invite-code']) ? sanitize_key((string) $fields['aioic/invite-code']) : '';

	$result = aioic_woocommerce_check_invite_code($code, $order->get_billing_email());
	if (true !== $result) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('aioic_invite_code_invalid', esc_html(wp_strip_all_tags($result)), 400);
	}

	$order->update_meta_data('all_in_one_invite_codes_woo_product', $code);
}
add_action('woocommerce_store_api_checkout_update_order_from_request', 'aioic_woocommerce_store_api_validate_invite_code', 10, 2);

/**
 * Look up an invite code post id.
 *
 * @param string $code Invite code.
 *
 * @return int
 */
function aioic_woocommerce_get_code_id($code)
{
	$ids = get_posts(array(
		'post_type'      => 'tk_invite_codes',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- codes are looked up by their meta value.
		'meta_query'     => array(
			array(
				'key'   => 'tk_all_in_one_invite_code',
				'value' => $code,
			),
		),
	));

	return $ids ? (int) $ids[0] : 0;
}

/**
 * Use up the invite code of an order with invite only products when the order is
 * placed: single use codes become Used, multiple use codes lose one use.
 *
 * @param WC_Order|int $order Order or order id.
 */
function aioic_woocommerce_consume_invite_code($order)
{
	$order = $order instanceof WC_Order ? $order : wc_get_order($order);
	if (!$order || $order->get_meta('_aioic_invite_code_consumed') || !aioic_woocommerce_order_needs_invite_code($order)) {
		return;
	}

	$code    = sanitize_key((string) $order->get_meta('all_in_one_invite_codes_woo_product'));
	$code_id = '' === $code ? 0 : aioic_woocommerce_get_code_id($code);
	if (!$code_id) {
		return;
	}

	aioic_woocommerce_use_code($code_id);

	$order->update_meta_data('_aioic_invite_code_consumed', $code_id);
	$order->save();
}
/**
 * Use up an invite code: single use codes become Used, multiple use codes lose one use.
 *
 * @param int $code_id Invite code post id.
 */
function aioic_woocommerce_use_code($code_id)
{
	$options = get_post_meta($code_id, 'all_in_one_invite_codes_options', true);
	if (is_array($options) && isset($options['multiple_use'])) {
		$left                      = max(0, (isset($options['generate_codes']) ? (int) $options['generate_codes'] : 0) - 1);
		$options['generate_codes'] = $left;
		update_post_meta($code_id, 'all_in_one_invite_codes_options', $options);
		if (0 === $left) {
			update_post_meta($code_id, 'tk_all_in_one_invite_code_status', 'Used');
		}
	} else {
		update_post_meta($code_id, 'tk_all_in_one_invite_code_status', 'Used');
	}
}

add_action('woocommerce_checkout_order_processed', 'aioic_woocommerce_consume_invite_code', 20, 1);
add_action('woocommerce_store_api_checkout_order_processed', 'aioic_woocommerce_consume_invite_code', 20, 1);


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
	// An order can be completed more than once (status changed back and forth).
	if (!$order || $order->get_meta('_aioic_invite_codes_rewarded')) {
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

		$order->update_meta_data('_aioic_invite_codes_rewarded', time());
		$order->save();

		// For multiple use codes generate_codes holds the uses left, not codes to hand out.
		if (isset($all_in_one_invite_codes_options['multiple_use'])) {
			return;
		}

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
			// The Freemius SDK comes with All in One Invite Codes, which loads it before this add-on initializes.
			if (!function_exists('fs_dynamic_init')) {
				return false;
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
			add_filter('woocommerce_product_data_tabs', 'aioic_woocommerce_invite_code_section', 10, 1); // Add section
			add_action('woocommerce_product_data_panels', 'aioic_woocommerce_invite_code_tab_content'); // Add Section Tab content
			add_action('woocommerce_process_product_meta', 'aioic_woocommerce_save_product_options_fields', 12, 2); // Save option
			// Paid orders only: processing (paid, or accepted cash on delivery) or completed.
			add_action('woocommerce_order_status_processing', 'aioic_woocommerce_purchase_complete');
			add_action('woocommerce_order_status_completed', 'aioic_woocommerce_purchase_complete');
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
