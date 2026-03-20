# Changelog / История изменений

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [1.0.0] - 2025-03-19

### Added / Добавлено

- Product blacklist management page under **WooCommerce > Blacklist**.
- AJAX-powered product search by name and SKU.
- Automatic draft enforcement via `save_post_product` hook (priority 999).
- Automatic draft enforcement via `transition_post_status` hook (priority 999).
- Post meta flag `_wc_publish_blacklisted` for per-product lookup.
- Option `wc_publish_blacklist_ids` for centralized blacklist storage.
- One-click add and remove from the blacklist.
- Current status display (Published / Draft / Private / Pending) in the admin table.
- Nonce verification and `manage_woocommerce` capability check on all AJAX actions.
- Inline CSS and JS for zero external dependencies.
- Bilingual README (English / Russian).
- MIT license.
