# Elmonofy ERP Integration (WooCommerce)

WordPress plugin that integrates WooCommerce with ERP for order sync and stock/price updates.

## Plugin Info
- **Name:** Elmonofy ERP Integration
- **Main File:** `elmonofy-integration.php`
- **Version:** 5.1.0
- **Author:** Bido & Najdi

## What It Does
- Adds ERP configuration page in WP Admin
- Verifies ERP connectivity via AJAX test
- Syncs WooCommerce orders to ERP asynchronously
- Handles retries and sync markers to avoid duplicate sends
- Provides inbound API handling for stock/price updates

## Repository Layout
- `elmonofy-integration.php` → active plugin entrypoint
- `API-DOCUMENTATION.md` → API contract and usage details
- `archive/` → historical variants kept for reference

## Requirements
- WordPress + WooCommerce
- Proper ERP endpoint and token configured in plugin settings

## Setup
1. Put folder in `wp-content/plugins/elmonofy-erp-integration`
2. Activate plugin from WP Admin
3. Configure ERP URL + token in settings page
4. Run connection test

## Notes
- Legacy versions are preserved in `archive/` and not used as entrypoint.
- Keep only `elmonofy-integration.php` active in production deployments.
