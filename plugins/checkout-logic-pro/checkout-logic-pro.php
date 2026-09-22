<?php
/**
 * Plugin Name: Checkout Logic Pro
 * Plugin URI: https://pablo-guides.com/
 * Description: Conditional WooCommerce checkout fields and payment method rules.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Pablo Guides
 * License: GPL-2.0-or-later
 * Text Domain: checkout-logic-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PG_Checkout_Logic_Pro {
	const OPTION_KEY = 'pg_checkout_logic_pro';

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'bootstrap' ) );
	}

	public static function bootstrap() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_required_notice' ) );
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'filter_checkout_fields' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_payment_gateways' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'settings_link' ) );
	}

	public static function defaults() {
		return array(
			'hide_company_under'     => 0,
			'hide_order_notes_under' => 0,
			'require_phone_over'     => 0,
			'disable_cod_over'       => 0,
			'disable_bacs_under'     => 0,
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	public static function woocommerce_required_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Checkout Logic Pro requires WooCommerce to be installed and active.', 'checkout-logic-pro' ) .
			'</p></div>';
	}

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Checkout Logic Pro', 'checkout-logic-pro' ),
			__( 'Checkout Logic', 'checkout-logic-pro' ),
			'manage_woocommerce',
			'pg-checkout-logic-pro',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'pg_checkout_logic_pro_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize_settings( $input ) {
		$output = self::defaults();
		foreach ( array_keys( $output ) as $key ) {
			$output[ $key ] = isset( $input[ $key ] ) ? max( 0, (float) wc_format_decimal( $input[ $key ] ) ) : 0;
		}
		return $output;
	}

	private static function cart_subtotal() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}
		return (float) WC()->cart->get_subtotal();
	}

	public static function filter_checkout_fields( $fields ) {
		$settings = self::get_settings();
		$subtotal = self::cart_subtotal();

		if ( $settings['hide_company_under'] > 0 && $subtotal < $settings['hide_company_under'] ) {
			unset( $fields['billing']['billing_company'] );
		}

		if ( $settings['hide_order_notes_under'] > 0 && $subtotal < $settings['hide_order_notes_under'] ) {
			unset( $fields['order']['order_comments'] );
		}

		if ( $settings['require_phone_over'] > 0 && $subtotal >= $settings['require_phone_over'] && isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['required'] = true;
		}

		return $fields;
	}

	public static function filter_payment_gateways( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}

		$settings = self::get_settings();
		$subtotal = self::cart_subtotal();

		if ( $settings['disable_cod_over'] > 0 && $subtotal > $settings['disable_cod_over'] ) {
			unset( $gateways['cod'] );
		}

		if ( $settings['disable_bacs_under'] > 0 && $subtotal < $settings['disable_bacs_under'] ) {
			unset( $gateways['bacs'] );
		}

		return $gateways;
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'checkout-logic-pro' ) );
		}

		$s = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Checkout Logic Pro', 'checkout-logic-pro' ); ?></h1>
			<p><?php esc_html_e( 'Use 0 to disable any rule. All amounts use the store currency.', 'checkout-logic-pro' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'pg_checkout_logic_pro_group' ); ?>
				<table class="form-table" role="presentation">
					<?php self::number_row( 'hide_company_under', __( 'Hide company field when subtotal is below', 'checkout-logic-pro' ), $s['hide_company_under'] ); ?>
					<?php self::number_row( 'hide_order_notes_under', __( 'Hide order notes when subtotal is below', 'checkout-logic-pro' ), $s['hide_order_notes_under'] ); ?>
					<?php self::number_row( 'require_phone_over', __( 'Require phone when subtotal is at least', 'checkout-logic-pro' ), $s['require_phone_over'] ); ?>
					<?php self::number_row( 'disable_cod_over', __( 'Disable Cash on Delivery when subtotal is above', 'checkout-logic-pro' ), $s['disable_cod_over'] ); ?>
					<?php self::number_row( 'disable_bacs_under', __( 'Disable bank transfer when subtotal is below', 'checkout-logic-pro' ), $s['disable_bacs_under'] ); ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function number_row( $key, $label, $value ) {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input class="regular-text" type="number" min="0" step="0.01" id="%1$s" name="%3$s[%1$s]" value="%4$s"></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value )
		);
	}

	public static function settings_link( $links ) {
		$url = admin_url( 'admin.php?page=pg-checkout-logic-pro' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'checkout-logic-pro' ) . '</a>' );
		return $links;
	}
}

PG_Checkout_Logic_Pro::init();
