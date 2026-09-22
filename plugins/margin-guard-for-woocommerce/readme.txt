=== Margin Guard for WooCommerce ===
Contributors: pablo-guides
Tags: woocommerce, margin, coupons, pricing
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Store product cost prices, calculate gross margin and protect minimum margins from product coupons.

== Description ==

Features:
* Cost price field on WooCommerce products.
* Margin column in the product list.
* Configurable minimum gross margin.
* Optional blocking of percent and fixed-product coupons that would breach the minimum.

Note: fixed-cart and custom coupon types are not blocked in version 1.0.0 because their allocation is not reliably known at the product-level validation hook.

== Installation ==

1. Upload and activate the plugin.
2. Add a cost price to products.
3. Go to WooCommerce > Margin Guard and configure the minimum margin.

== Changelog ==

= 1.0.0 =
* Initial release.
