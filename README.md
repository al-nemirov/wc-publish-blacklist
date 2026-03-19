# WC Publish Blacklist

[English](#english) | [Русский](#русский)

---

## English

Prevent selected WooCommerce products from being published — even after sync with MoySklad/WooMS.

### Use Case

When auto-syncing catalogs from MoySklad or WooMS, some products get published automatically even though they shouldn't. This plugin forces them back to draft.

### Features

- Product blacklist by ID
- AJAX-powered search by name and SKU
- Force draft status on save / status transition
- Display current status of blacklisted products
- One-click removal from blacklist
- Intercepts `save_post_product` and `transition_post_status` hooks
- Security: nonce + `manage_woocommerce` capability check

### Installation

1. Copy folder to `wp-content/plugins/`
2. Activate the plugin
3. Go to **WooCommerce → Blacklist**

### How It Works

1. Search for a product (by name or SKU)
2. Click to add it to the blacklist
3. Any attempt to publish the product (manually or via sync) — the plugin automatically reverts it to draft

### Requirements

- WordPress 5.0+, WooCommerce 3.0+, PHP 7.4+

---

## Русский

Запрет публикации выбранных товаров WooCommerce даже после синхронизации с МойСклад/WooMS.

### Для чего

При автосинхронизации из МойСклад или WooMS некоторые товары публикуются автоматически, хотя не должны. Плагин принудительно возвращает их в черновики.

### Возможности

- Чёрный список товаров по ID
- AJAX-поиск по названию и SKU
- Принудительный перевод в draft при сохранении / смене статуса
- Отображение текущего статуса в списке
- Удаление из списка одной кнопкой
- Перехват хуков `save_post_product` и `transition_post_status`

### Как работает

1. Найдите товар через поиск
2. Нажмите — он добавится в чёрный список
3. Любая попытка публикации → автоматический возврат в черновик

## Author / Автор

Alexander Nemirov
