<?php
/**
 * Plugin Name: COD Risk Guard
 * Plugin URI: https://pablo-guides.com/
 * Description: Reduce risky WooCommerce cash-on-delivery orders with limits, blocklists and optional COD surcharge.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Pablo Guides
 * License: GPL-2.0-or-later
 * Text Domain: cod-risk-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PG_COD_Risk_Guard {
	const OPTION_KEY = 'pg_cod_risk_guard';

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
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'checkout_validation' ), 10, 2 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'maybe_add_cod_fee' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'add_order_risk_note' ), 20, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'settings_link' ) );
	}

	public static function defaults() {
		return array(
			'max_cod_total'  => 0,
			'cod_surcharge'  => 0,
			'blocked_emails' => '',
			'blocked_phones' => '',
			'blocked_ips'    => '',
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	public static function woocommerce_required_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'COD Risk Guard requires WooCommerce to be installed and active.', 'cod-risk-guard' ) .
				'</p></div>';
		}
	}

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'COD Risk Guard', 'cod-risk-guard' ),
			__( 'COD Risk Guard', 'cod-risk-guard' ),
			'manage_woocommerce',
			'pg-cod-risk-guard',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'pg_cod_risk_guard_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize_settings( $input ) {
		$out = self::defaults();
		$out['max_cod_total'] = isset( $input['max_cod_total'] ) ? max( 0, (float) wc_format_decimal( $input['max_cod_total'] ) ) : 0;
		$out['cod_surcharge'] = isset( $input['cod_surcharge'] ) ? max( 0, (float) wc_format_decimal( $input['cod_surcharge'] ) ) : 0;
		$out['blocked_emails'] = isset( $input['blocked_emails'] ) ? sanitize_textarea_field( $input['blocked_emails'] ) : '';
		$out['blocked_phones'] = isset( $input['blocked_phones'] ) ? sanitize_textarea_field( $input['blocked_phones'] ) : '';
		$out['blocked_ips'] = isset( $input['blocked_ips'] ) ? sanitize_textarea_field( $input['blocked_ips'] ) : '';
		return $out;
	}

	private static function lines( $value ) {
		$items = preg_split( '/\r\n|\r|\n|,/', (string) $value );
		$items = array_map( 'trim', $items );
		return array_values( array_filter( $items, 'strlen' ) );
	}

	private static function normalize_phone( $phone ) {
		return preg_replace( '/[^0-9+]/', '', (string) $phone );
	}

	private static function request_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private static function is_blocked( $email, $phone, $ip ) {
		$s = self::get_settings();
		$emails = array_map( 'strtolower', self::lines( $s['blocked_emails'] ) );
		$phones = array_map( array( __CLASS__, 'normalize_phone' ), self::lines( $s['blocked_phones'] ) );
		$ips = self::lines( $s['blocked_ips'] );

		if ( $email && in_array( strtolower( trim( $email ) ), $emails, true ) ) {
			return true;
		}
		if ( $phone && in_array( self::normalize_phone( $phone ), $phones, true ) ) {
			return true;
		}
		if ( $ip && in_array( $ip, $ips, true ) ) {
			return true;
		}
		return false;
	}

	private static function current_total() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}
		return (float) WC()->cart->get_total( 'edit' );
	}

	private static function posted_payment_method() {
		if ( isset( $_POST['payment_method'] ) ) {
			return sanitize_key( wp_unslash( $_POST['payment_method'] ) );
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			return sanitize_key( (string) WC()->session->get( 'chosen_payment_method' ) );
		}
		return '';
	}

	public static function filter_gateways( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( ! isset( $gateways['cod'] ) ) {
			return $gateways;
		}

		$s = self::get_settings();
		$total = self::current_total();

		if ( $s['max_cod_total'] > 0 && $total > $s['max_cod_total'] ) {
			unset( $gateways['cod'] );
			return $gateways;
		}

		$email = '';
		$phone = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$email = WC()->customer->get_billing_email();
			$phone = WC()->customer->get_billing_phone();
		}
		if ( self::is_blocked( $email, $phone, self::request_ip() ) ) {
			unset( $gateways['cod'] );
		}
		return $gateways;
	}

	public static function checkout_validation( $data, $errors ) {
		if ( empty( $data['payment_method'] ) || 'cod' !== $data['payment_method'] ) {
			return;
		}

		$s = self::get_settings();
		$total = self::current_total();

		if ( $s['max_cod_total'] > 0 && $total > $s['max_cod_total'] ) {
			$errors->add( 'pg_cod_total_limit', __( 'Cash on Delivery is not available for this order total.', 'cod-risk-guard' ) );
		}

		$email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
		$phone = isset( $data['billing_phone'] ) ? sanitize_text_field( $data['billing_phone'] ) : '';
		if ( self::is_blocked( $email, $phone, self::request_ip() ) ) {
			$errors->add( 'pg_cod_blocked', __( 'Cash on Delivery is not available for these checkout details.', 'cod-risk-guard' ) );
		}
	}

	public static function maybe_add_cod_fee( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$s = self::get_settings();
		if ( $s['cod_surcharge'] <= 0 || 'cod' !== self::posted_payment_method() ) {
			return;
		}
		$cart->add_fee( __( 'Cash on Delivery fee', 'cod-risk-guard' ), $s['cod_surcharge'], true );
	}

	public static function add_order_risk_note( $order, $data ) {
		if ( empty( $data['payment_method'] ) || 'cod' !== $data['payment_method'] ) {
			return;
		}
		$flags = array();
		$s = self::get_settings();
		$total = (float) $order->get_total();

		if ( $s['max_cod_total'] > 0 && $total > ( 0.8 * $s['max_cod_total'] ) ) {
			$flags[] = __( 'Order total is close to the configured COD ceiling.', 'cod-risk-guard' );
		}

		if ( self::is_blocked( $order->get_billing_email(), $order->get_billing_phone(), self::request_ip() ) ) {
			$flags[] = __( 'Checkout data matched the COD blocklist.', 'cod-risk-guard' );
		}

		if ( $flags ) {
			$order->add_order_note( 'COD Risk Guard: ' . implode( ' ', $flags ) );
		}
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cod-risk-guard' ) );
		}
		$s = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'COD Risk Guard', 'cod-risk-guard' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'pg_cod_risk_guard_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="max_cod_total"><?php esc_html_e( 'Maximum COD order total', 'cod-risk-guard' ); ?></label></th><td><input id="max_cod_total" type="number" min="0" step="0.01" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_cod_total]" value="<?php echo esc_attr( $s['max_cod_total'] ); ?>"><p class="description"><?php esc_html_e( 'Use 0 for no limit.', 'cod-risk-guard' ); ?></p></td></tr>
					<tr><th><label for="cod_surcharge"><?php esc_html_e( 'COD surcharge', 'cod-risk-guard' ); ?></label></th><td><input id="cod_surcharge" type="number" min="0" step="0.01" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cod_surcharge]" value="<?php echo esc_attr( $s['cod_surcharge'] ); ?>"></td></tr>
					<tr><th><label for="blocked_emails"><?php esc_html_e( 'Blocked emails', 'cod-risk-guard' ); ?></label></th><td><textarea class="large-text code" rows="5" id="blocked_emails" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[blocked_emails]"><?php echo esc_textarea( $s['blocked_emails'] ); ?></textarea><p class="description"><?php esc_html_e( 'One per line or comma-separated.', 'cod-risk-guard' ); ?></p></td></tr>
					<tr><th><label for="blocked_phones"><?php esc_html_e( 'Blocked phones', 'cod-risk-guard' ); ?></label></th><td><textarea class="large-text code" rows="5" id="blocked_phones" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[blocked_phones]"><?php echo esc_textarea( $s['blocked_phones'] ); ?></textarea></td></tr>
					<tr><th><label for="blocked_ips"><?php esc_html_e( 'Blocked IPs', 'cod-risk-guard' ); ?></label></th><td><textarea class="large-text code" rows="5" id="blocked_ips" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[blocked_ips]"><?php echo esc_textarea( $s['blocked_ips'] ); ?></textarea></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pg-cod-risk-guard' ) ) . '">' . esc_html__( 'Settings', 'cod-risk-guard' ) . '</a>' );
		return $links;
	}
}

PG_COD_Risk_Guard::init();
