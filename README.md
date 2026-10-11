# Regional Inventory for WooCommerce

Free, open-source **continent/region inventory pools**, atomic reservations, and market routing for WooCommerce.

Maintained by [Shadow Software](https://shadowsoftware.com).

## Status

Scaffold / pre-1.0. Designed for [WordPress.org](https://wordpress.org/plugins/) submission from day one (Plugin Check, PHPCS, GPL-2.0-or-later, prefixed APIs).

Aura Labs (and other merchants) configure markets in their own site code. This plugin stays brand-neutral.

## Goals

- One product identity, independent stock balances per region/continent pool
- Atomic reserve / release across classic checkout and the Store API
- Clear separation: market resolver (site) vs inventory repository (this plugin)
- No paid SaaS dependency

## Development

TDD-first. PHPUnit + WooCommerce test bootstrap will land with the first implementation PRs.

## License

GPL-2.0-or-later
