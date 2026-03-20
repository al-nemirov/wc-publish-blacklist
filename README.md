# WC Publish Blacklist

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue?logo=wordpress)
![WooCommerce](https://img.shields.io/badge/WooCommerce-3.0%2B-96588a?logo=woo)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

[English](#english) | [Русский](#русский)

---

## English

Prevent selected WooCommerce products from being published -- even after sync with MoySklad / WooMS.

### Use Case

When auto-syncing catalogs from MoySklad or WooMS, some products get published automatically even though they should remain as drafts. This plugin forces them back to draft status immediately.

### Features

- Product blacklist by ID
- AJAX-powered search by name and SKU
- Force draft status on save / status transition
- Display current status of blacklisted products
- One-click removal from blacklist
- Intercepts `save_post_product` and `transition_post_status` hooks
- Security: nonce verification + `manage_woocommerce` capability check

### Requirements

| Dependency   | Version |
|--------------|---------|
| WordPress    | 5.0+    |
| WooCommerce  | 3.0+    |
| PHP          | 7.4+    |

### Installation

#### Manual upload

1. Download the latest release as a `.zip` archive.
2. In WordPress admin go to **Plugins > Add New > Upload Plugin**.
3. Select the `.zip` file and click **Install Now**.
4. Activate the plugin.

#### Via FTP / file manager

1. Copy the `wc-publish-blacklist` folder into `wp-content/plugins/`.
2. In WordPress admin go to **Plugins** and activate **WC Publish Blacklist**.

#### Via Git (for development)

```bash
cd wp-content/plugins/
git clone https://github.com/yourusername/wc-publish-blacklist.git
```

Activate the plugin from the WordPress admin panel.

### Usage

1. Navigate to **WooCommerce > Blacklist** in the admin sidebar.
2. Use the search box to find a product by name or SKU.
3. Click **+ Blacklist** next to the product to add it.
4. The product is immediately reverted to **Draft** status if it is currently published.
5. Any future attempt to publish the product (manually or via external sync) will be blocked automatically.
6. To remove a product from the blacklist, click **Remove** in the table.

### Hooks for Developers

The plugin intercepts the following WordPress / WooCommerce hooks:

| Hook                     | Priority | Purpose                                           |
|--------------------------|----------|---------------------------------------------------|
| `save_post_product`      | 999      | Reverts blacklisted products to draft on save     |
| `transition_post_status` | 999      | Reverts blacklisted products on any status change |

#### Checking if a product is blacklisted

The plugin stores blacklisted IDs in the `wc_publish_blacklist_ids` option and sets the `_wc_publish_blacklisted` post meta key on each blacklisted product. You can check these values in your own code:

```php
// Option-based check
$blacklist = (array) get_option( 'wc_publish_blacklist_ids', [] );
if ( in_array( $product_id, $blacklist, true ) ) {
    // product is blacklisted
}

// Meta-based check
if ( get_post_meta( $product_id, '_wc_publish_blacklisted', true ) ) {
    // product is blacklisted
}
```

#### AJAX Actions

| Action                       | Method | Description                          |
|------------------------------|--------|--------------------------------------|
| `wcpbl_search_products`      | POST   | Search products by name or SKU       |
| `wcpbl_add_to_blacklist`     | POST   | Add a product ID to the blacklist    |
| `wcpbl_remove_from_blacklist`| POST   | Remove a product ID from the blacklist |

All AJAX actions require a valid `wcpbl_nonce` nonce and the `manage_woocommerce` capability.

### Contributing

Contributions are welcome! To get started:

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/my-feature`.
3. Commit your changes with clear messages.
4. Push to your fork and open a Pull Request.

Please make sure your code follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

---

## Русский

Запрет публикации выбранных товаров WooCommerce даже после синхронизации с МойСклад / WooMS.

### Для чего

При автосинхронизации каталога из МойСклад или WooMS некоторые товары публикуются автоматически, хотя должны оставаться черновиками. Плагин принудительно возвращает их в статус «Черновик» немедленно.

### Возможности

- Чёрный список товаров по ID
- AJAX-поиск по названию и артикулу (SKU)
- Принудительный перевод в draft при сохранении / смене статуса
- Отображение текущего статуса товаров в списке
- Удаление из списка одной кнопкой
- Перехват хуков `save_post_product` и `transition_post_status`
- Безопасность: проверка nonce + право `manage_woocommerce`

### Требования

| Зависимость  | Версия  |
|--------------|---------|
| WordPress    | 5.0+    |
| WooCommerce  | 3.0+    |
| PHP          | 7.4+    |

### Установка

#### Ручная загрузка

1. Скачайте последний релиз в виде `.zip` архива.
2. В админке WordPress перейдите в **Плагины > Добавить новый > Загрузить плагин**.
3. Выберите `.zip` файл и нажмите **Установить**.
4. Активируйте плагин.

#### Через FTP / файловый менеджер

1. Скопируйте папку `wc-publish-blacklist` в `wp-content/plugins/`.
2. В админке WordPress перейдите в **Плагины** и активируйте **WC Publish Blacklist**.

#### Через Git (для разработки)

```bash
cd wp-content/plugins/
git clone https://github.com/yourusername/wc-publish-blacklist.git
```

Активируйте плагин через панель администратора WordPress.

### Использование

1. Перейдите в **WooCommerce > Blacklist** в боковом меню админки.
2. Используйте поле поиска для нахождения товара по названию или артикулу.
3. Нажмите **+ Blacklist** рядом с товаром, чтобы добавить его в чёрный список.
4. Товар немедленно переводится в статус **Черновик**, если он был опубликован.
5. Любая будущая попытка публикации (вручную или через синхронизацию) будет заблокирована автоматически.
6. Для удаления товара из чёрного списка нажмите **Remove** в таблице.

### Хуки для разработчиков

Плагин перехватывает следующие хуки WordPress / WooCommerce:

| Хук                        | Приоритет | Назначение                                             |
|-----------------------------|-----------|--------------------------------------------------------|
| `save_post_product`         | 999       | Возвращает товар в черновик при сохранении              |
| `transition_post_status`    | 999       | Возвращает товар в черновик при любой смене статуса     |

#### Проверка наличия товара в чёрном списке

Плагин хранит ID товаров в опции `wc_publish_blacklist_ids` и устанавливает мета-ключ `_wc_publish_blacklisted` для каждого заблокированного товара:

```php
// Проверка через опцию
$blacklist = (array) get_option( 'wc_publish_blacklist_ids', [] );
if ( in_array( $product_id, $blacklist, true ) ) {
    // товар в чёрном списке
}

// Проверка через мета
if ( get_post_meta( $product_id, '_wc_publish_blacklisted', true ) ) {
    // товар в чёрном списке
}
```

#### AJAX-действия

| Действие                     | Метод | Описание                                |
|------------------------------|-------|-----------------------------------------|
| `wcpbl_search_products`      | POST  | Поиск товаров по названию или артикулу  |
| `wcpbl_add_to_blacklist`     | POST  | Добавить товар в чёрный список          |
| `wcpbl_remove_from_blacklist`| POST  | Удалить товар из чёрного списка         |

Все AJAX-действия требуют валидный nonce `wcpbl_nonce` и право `manage_woocommerce`.

### Участие в разработке

Мы рады вашему участию! Для начала:

1. Сделайте форк репозитория.
2. Создайте ветку: `git checkout -b feature/my-feature`.
3. Закоммитьте изменения с понятным описанием.
4. Отправьте в свой форк и откройте Pull Request.

Убедитесь, что код соответствует [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).

---

## Author / Автор

Alexander Nemirov

## License / Лицензия

[MIT](LICENSE)
