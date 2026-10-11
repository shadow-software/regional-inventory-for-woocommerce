=== Regional Inventory for WooCommerce ===
Contributors: shadowsoftware
Tags: woocommerce, inventory, stock, warehouse, multi-location
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Continent/region inventory pools, reservations, and market-aware stock for WooCommerce.

== Description ==

Regional Inventory for WooCommerce keeps one product catalog while tracking independent stock balances per region (for example EU, Asia, Americas). It provides atomic reservations so concurrent checkouts cannot oversell the same regional unit, and integrates with classic checkout and the WooCommerce Store API.

This plugin does not replace your shipping, currency, or translation stack. Pair it with your own market routing (domain or country rules) to choose which pool fulfills an order.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/regional-inventory-for-woocommerce`
2. Activate through the Plugins screen
3. Configure regions and seed balances under WooCommerce → Regional Inventory
4. Connect your storefront market resolver so each request maps to a region

== Frequently Asked Questions ==

= Does this duplicate products per region? =

No. One product identity; separate balances and reservations per region.

= Is a paid license required? =

No. This plugin is free and GPL-licensed.

== Changelog ==

= 0.1.0 =
* Scaffold for WordPress.org submission and TDD implementation.
