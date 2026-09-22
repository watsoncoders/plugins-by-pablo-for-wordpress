<?php
/**
 * Plugin Name: Margin Guard for WooCommerce
 * Plugin URI: https://pablo-guides.com/
 * Description: Store product cost prices, calculate gross margin and block product coupons that would breach a configured minimum margin.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Pablo Guides
 * License: GPL-2.0-or-later
 * Text Domain: margin-guard-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PG_Margin_Guard {
	const OPTION_KEY = 'pg_margin_guard_options';
	const COST_META  = '_pg_cost_price';

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
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'cost_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_cost' ) );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'validate_coupon_for_product' ), 10, 4 );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_margin_column' ), 30 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_margin_column' ), 10, 2 );
		add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'settings_link' ) );
	}

	public static function defaults() {
		return array(
			'minimum_margin' => 20,
			'block_coupons'  => 1,
		);
	}

	public static function settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	public static function woocommerce_required_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Margin Guard for WooCommerce requires WooCommerce to be installed and active.', 'margin-guard-for-woocommerce' ) .
				'</p></div>';
		}
	}

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Margin Guard', 'margin-guard-for-woocommerce' ),
			__( 'Margin Guard', 'margin-guard-for-woocommerce' ),
			'manage_woocommerce',
			'pg-margin-guard',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'pg_margin_guard_group',
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
		$out['minimum_margin'] = isset( $input['minimum_margin'] ) ? min( 99.99, max( 0, (float) wc_format_decimal( $input['minimum_margin'] ) ) ) : 20;
		$out['block_coupons']  = ! empty( $input['block_coupons'] ) ? 1 : 0;
		return $out;
	}

	public static function cost_field() {
		woocommerce_wp_text_input(
			array(
				'id'                => self::COST_META,
				'label'             => __( 'Cost price', 'margin-guard-for-woocommerce' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'desc_tip'          => true,
				'description'       => __( 'Internal product cost used to calculate gross margin.', 'margin-guard-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
			)
		);
	}

	public static function save_cost( $product ) {
		if ( ! isset( $_POST[ self::COST_META ] ) ) {
			return;
		}
		$value = wc_format_decimal( wp_unslash( $_POST[ self::COST_META ] ) );
		if ( '' === $value ) {
			$product->delete_meta_data( self::COST_META );
		} else {
			$product->update_meta_data( self::COST_META, max( 0, (float) $value ) );
		}
	}

	private static function margin_percent( $price, $cost ) {
		$price = (float) $price;
		$cost  = (float) $cost;
		if ( $price <= 0 ) {
			return null;
		}
		return ( ( $price - $cost ) / $price ) * 100;
	}

	private static function price_after_coupon( $product, $coupon ) {
		$price = (float) wc_get_price_to_display( $product );
		$type  = $coupon->get_discount_type();
		$amount = (float) $coupon->get_amount();

		if ( 'percent' === $type ) {
			return max( 0, $price * ( 1 - ( $amount / 100 ) ) );
		}
		if ( 'fixed_product' === $type ) {
			return max( 0, $price - $amount );
		}

		// Fixed-cart and custom coupon types are not blocked here because their
		// per-product allocation cannot be determined safely at this hook.
		return $price;
	}

	public static function validate_coupon_for_product( $valid, $product, $coupon, $values ) {
		if ( ! $valid || ! $product instanceof WC_Product || ! $coupon instanceof WC_Coupon ) {
			return $valid;
		}

		$s = self::settings();
		if ( empty( $s['block_coupons'] ) ) {
			return $valid;
		}

		$cost = (float) $product->get_meta( self::COST_META, true );
		if ( $cost <= 0 ) {
			return $valid;
		}

		$new_price = self::price_after_coupon( $product, $coupon );
		$margin = self::margin_percent( $new_price, $cost );

		if ( null !== $margin && $margin < (float) $s['minimum_margin'] ) {
			return false;
		}
		return $valid;
	}

	public static function add_margin_column( $columns ) {
		$columns['pg_margin'] = __( 'Margin', 'margin-guard-for-woocommerce' );
		return $columns;
	}

	public static function render_margin_column( $column, $post_id ) {
		if ( 'pg_margin' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '&mdash;';
			return;
		}

		$cost = (float) $product->get_meta( self::COST_META, true );
		$price = (float) $product->get_price();

		if ( $cost <= 0 || $price <= 0 ) {
			echo '<span class="pg-margin-muted">' . esc_html__( 'No cost', 'margin-guard-for-woocommerce' ) . '</span>';
			return;
		}

		$margin = self::margin_percent( $price, $cost );
		$s = self::settings();
		$class = ( null !== $margin && $margin < (float) $s['minimum_margin'] ) ? 'pg-margin-low' : 'pg-margin-ok';

		printf(
			'<span class="%1$s">%2$s%%</span>',
			esc_attr( $class ),
			esc_html( number_format_i18n( $margin, 1 ) )
		);
	}

	public static function admin_css() {
		echo '<style>
		.column-pg_margin{width:90px}
		.pg-margin-low{color:#b32d2e;font-weight:700}
		.pg-margin-ok{color:#008a20;font-weight:700}
		.pg-margin-muted{color:#777}
		</style>';
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'margin-guard-for-woocommerce' ) );
		}
		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Margin Guard for WooCommerce', 'margin-guard-for-woocommerce' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'pg_margin_guard_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="minimum_margin"><?php esc_html_e( 'Minimum gross margin (%)', 'margin-guard-for-woocommerce' ); ?></label></th>
						<td><input id="minimum_margin" type="number" min="0" max="99.99" step="0.01" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[minimum_margin]" value="<?php echo esc_attr( $s['minimum_margin'] ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Coupon protection', 'margin-guard-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[block_coupons]" value="1" <?php checked( 1, $s['block_coupons'] ); ?>> <?php esc_html_e( 'Block percent and fixed-product coupons that would push a product below the minimum margin.', 'margin-guard-for-woocommerce' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pg-margin-guard' ) ) . '">' . esc_html__( 'Settings', 'margin-guard-for-woocommerce' ) . '</a>' );
		return $links;
	}
}

PG_Margin_Guard::init();
