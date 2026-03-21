<?php
/**
 * Plugin Name: WC Publish Blacklist
 * Plugin URI:  https://github.com/al-nemirov/wc-publish-blacklist
 * Description: Prevents selected WooCommerce products from being published even after external sync (MoySklad, WooMS, etc.).
 * Version:     1.0.0
 * Author:      Alexander Nemirov
 * Author URI:  https://github.com/al-nemirov
 * Text Domain: wc-publish-blacklist
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 *
 * @package WC_Publish_Blacklist
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Основной класс плагина WC Publish Blacklist.
 *
 * Управляет чёрным списком товаров WooCommerce, запрещая их публикацию.
 * Перехватывает хуки сохранения и смены статуса записей, автоматически
 * возвращая заблокированные товары в статус «Черновик».
 *
 * @since   1.0.0
 * @package WC_Publish_Blacklist
 */
class WC_Publish_Blacklist {

    /**
     * Ключ опции для хранения массива ID заблокированных товаров.
     *
     * @since 1.0.0
     * @var string
     */
    const OPTION_KEY = 'wc_publish_blacklist_ids';

    /**
     * Устаревший мета-ключ (использовался до версии 1.1.0).
     *
     * Хранится для одноразовой миграции: очистки старых post_meta записей.
     * Единственный источник истины — опция OPTION_KEY.
     *
     * @since      1.0.0
     * @deprecated 1.1.0 Blacklist data consolidated into OPTION_KEY only.
     * @var string
     */
    const LEGACY_META_KEY = '_wc_publish_blacklisted';

    /**
     * Ключ опции-флага, сигнализирующего о завершении миграции мета-ключей.
     *
     * @since 1.1.0
     * @var string
     */
    const MIGRATION_OPTION = 'wc_publish_blacklist_meta_migrated';

    /**
     * Ключ опции для хранения журнала действий (аудит).
     *
     * @since 1.1.0
     * @var string
     */
    const LOG_OPTION_KEY = 'wc_publish_blacklist_log';

    /**
     * Максимальное количество записей в журнале действий.
     *
     * @since 1.1.0
     * @var int
     */
    const LOG_MAX_ENTRIES = 200;

    /**
     * Slug страницы плагина в меню WooCommerce.
     *
     * @since 1.0.0
     * @var string
     */
    const MENU_SLUG = 'wc-publish-blacklist';

    /**
     * Конструктор: регистрирует все хуки и AJAX-обработчики.
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Одноразовая миграция: очистка устаревших post_meta записей
        add_action( 'admin_init', [ $this, 'maybe_migrate_legacy_meta' ] );

        // Регистрация подменю в WooCommerce
        add_action( 'admin_menu', [ $this, 'add_menu' ] );

        // Подключение скриптов на странице плагина
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // AJAX: поиск товаров
        add_action( 'wp_ajax_wcpbl_search_products', [ $this, 'ajax_search_products' ] );

        // AJAX: добавление в чёрный список
        add_action( 'wp_ajax_wcpbl_add_to_blacklist', [ $this, 'ajax_add_to_blacklist' ] );

        // AJAX: удаление из чёрного списка
        add_action( 'wp_ajax_wcpbl_remove_from_blacklist', [ $this, 'ajax_remove_from_blacklist' ] );

        // Блокировка публикации при сохранении товара (приоритет 999 — выполняется последним)
        add_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999, 2 );

        // Блокировка публикации при смене статуса (внешняя синхронизация)
        add_action( 'transition_post_status', [ $this, 'enforce_blacklist_on_transition' ], 999, 3 );
    }

    // ─── Menu ────────────────────────────────────────────────────────────────

    /**
     * Регистрирует подменю «Blacklist» в разделе WooCommerce.
     *
     * Доступ ограничен правом manage_woocommerce.
     *
     * @since  1.0.0
     * @return void
     */
    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Publish Blacklist',
            'Blacklist',
            'manage_woocommerce',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // ─── Page ─────────────────────────────────────────────────────────────

    /**
     * Отрисовывает страницу управления чёрным списком.
     *
     * Загружает текущий список заблокированных товаров, получает данные
     * о каждом товаре (имя, артикул, статус) и выводит HTML-разметку
     * с таблицей и формой поиска. Включает встроенные CSS и JS.
     *
     * @since  1.0.0
     * @return void
     */
    public function render_page() {
        // Получаем массив ID из чёрного списка
        $blacklist = $this->get_blacklist();
        $products  = [];

        // Собираем данные о каждом товаре
        foreach ( $blacklist as $id ) {
            $p = wc_get_product( $id );
            if ( $p ) {
                $products[] = [
                    'id'     => $id,
                    'name'   => $p->get_name(),
                    'sku'    => $p->get_sku(),
                    'status' => get_post_status( $id ),
                    'edit'   => get_edit_post_link( $id ),
                ];
            }
        }
        ?>
        <div class="wrap" id="wcpbl-app">
            <h1>Publish Blacklist</h1>
            <p class="description">
                Products on this list <strong>will never be published</strong> — even after external sync.
                They are automatically reverted to <em>Draft</em> status.
            </p>

            <div class="wcpbl-search-box card" style="padding:16px;max-width:600px;margin-bottom:24px;">
                <h2 style="margin-top:0;">Add product to blacklist</h2>
                <div style="display:flex;gap:8px;">
                    <input type="text" id="wcpbl-search-input"
                           placeholder="Enter product name or SKU (min 3 chars)..."
                           class="regular-text" style="flex:1;" />
                    <button type="button" class="button button-primary" id="wcpbl-search-btn">Search</button>
                </div>
                <div id="wcpbl-search-results" style="margin-top:12px;"></div>
            </div>

            <div class="card" style="padding:16px;">
                <h2 style="margin-top:0;">Blacklisted (<?php echo count($products); ?> products)</h2>
                <?php if ( empty($products) ) : ?>
                    <p>List is empty. Add products using the search above.</p>
                <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>SKU</th>
                            <th>Current Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="wcpbl-list">
                    <?php foreach ( $products as $p ) : ?>
                        <tr id="wcpbl-row-<?php echo $p['id']; ?>">
                            <td><?php echo $p['id']; ?></td>
                            <td><a href="<?php echo esc_url($p['edit']); ?>" target="_blank"><?php echo esc_html($p['name']); ?></a></td>
                            <td><code><?php echo esc_html($p['sku']); ?></code></td>
                            <td>
                                <span class="wcpbl-status wcpbl-status-<?php echo $p['status']; ?>">
                                    <?php echo $this->status_label($p['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="button button-small wcpbl-remove-btn"
                                        data-id="<?php echo $p['id']; ?>">
                                    Remove
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <style>
        .wcpbl-status { display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600; }
        .wcpbl-status-publish { background:#d63638; color:#fff; }
        .wcpbl-status-draft   { background:#dba617; color:#fff; }
        .wcpbl-status-private { background:#555d66; color:#fff; }
        .wcpbl-result-item {
            display:flex; justify-content:space-between; align-items:center;
            padding:8px 12px; border:1px solid #ddd; border-radius:4px;
            margin-bottom:6px; background:#fafafa;
        }
        .wcpbl-result-item:hover { background:#f0f0f0; }
        .wcpbl-result-name { font-weight:500; }
        .wcpbl-result-meta { color:#777; font-size:12px; }
        </style>

        <script>
        jQuery(function($){
            const nonce = <?php echo wp_json_encode( wp_create_nonce('wcpbl_nonce') ); ?>;

            function esc(str) {
                const d = document.createElement('div');
                d.textContent = str || '';
                return d.innerHTML;
            }

            // ── Debounce-обёртка ────────────────────────────────────────────
            function debounce(fn, delay) {
                let timer;
                return function() {
                    clearTimeout(timer);
                    timer = setTimeout(() => fn.apply(this, arguments), delay);
                };
            }

            const MIN_QUERY_LENGTH = 3;

            // ── Поиск товаров ──────────────────────────────────────────────
            function doSearch() {
                const q = $('#wcpbl-search-input').val().trim();
                const $res = $('#wcpbl-search-results');
                if (q.length < MIN_QUERY_LENGTH) {
                    if (q.length > 0) {
                        $res.html('<p style="color:#888;">Enter at least ' + MIN_QUERY_LENGTH + ' characters</p>');
                    } else {
                        $res.html('');
                    }
                    return;
                }
                $res.html('<em>Searching...</em>');
                $.post(ajaxurl, { action:'wcpbl_search_products', q, nonce }, function(r){
                    if (!r.success || !r.data.length) {
                        const msg = (r.data && typeof r.data === 'string') ? esc(r.data) : 'Nothing found';
                        $res.html('<p style="color:#d63638;">' + msg + '</p>');
                        return;
                    }
                    let html = '';
                    r.data.forEach(p => {
                        html += `<div class="wcpbl-result-item" data-id="${parseInt(p.id)}">
                            <div>
                                <div class="wcpbl-result-name">${esc(p.name)}</div>
                                <div class="wcpbl-result-meta">ID: ${parseInt(p.id)} | SKU: ${esc(p.sku || '—')} | Status: ${esc(p.status_label)}</div>
                            </div>
                            <button class="button wcpbl-add-btn" data-id="${parseInt(p.id)}" data-name="${esc(p.name)}">
                                + Blacklist
                            </button>
                        </div>`;
                    });
                    $res.html(html);
                });
            }

            const debouncedSearch = debounce(doSearch, 400);

            $('#wcpbl-search-btn').on('click', doSearch);
            $('#wcpbl-search-input').on('keyup', debouncedSearch);
            $('#wcpbl-search-input').on('keypress', e => { if(e.key==='Enter') { e.preventDefault(); doSearch(); } });

            // ── Добавление в чёрный список ─────────────────────────────────
            $(document).on('click', '.wcpbl-add-btn', function(){
                const id   = $(this).data('id');
                const name = $(this).data('name');
                const $btn = $(this).prop('disabled', true).text('Adding...');
                $.post(ajaxurl, { action:'wcpbl_add_to_blacklist', id, nonce }, function(r){
                    if (r.success) {
                        $btn.closest('.wcpbl-result-item').html(
                            `<em style="color:#006505;">${name} added. <a href="">Refresh page</a> to see in table.</em>`
                        );
                    } else {
                        alert(r.data || 'Error');
                        $btn.prop('disabled', false).text('+ Blacklist');
                    }
                });
            });

            // ── Удаление из чёрного списка ─────────────────────────────────
            $(document).on('click', '.wcpbl-remove-btn', function(){
                if (!confirm('Remove product from blacklist?')) return;
                const id   = $(this).data('id');
                const $row = $(`#wcpbl-row-${id}`);
                $.post(ajaxurl, { action:'wcpbl_remove_from_blacklist', id, nonce }, function(r){
                    if (r.success) $row.fadeOut(300, () => $row.remove());
                    else alert(r.data || 'Error');
                });
            });
        });
        </script>
        <?php
    }

    // ─── AJAX: search ─────────────────────────────────────────────────────────

    /**
     * AJAX-обработчик: поиск товаров по названию и артикулу (SKU).
     *
     * Выполняет поиск через WP_Query (по названию) и wc_get_products (по SKU),
     * объединяет результаты с дедупликацией и возвращает JSON-массив.
     *
     * @since  1.0.0
     * @return void Отправляет JSON-ответ и завершает выполнение.
     */
    public function ajax_search_products() {
        check_ajax_referer( 'wcpbl_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Access denied' );

        $q = sanitize_text_field( $_POST['q'] ?? '' );

        // Минимальная длина запроса — 3 символа
        if ( mb_strlen( $q ) < 3 ) {
            wp_send_json_error( 'Please enter at least 3 characters' );
        }

        // Серверный rate-limit: не чаще 1 запроса в секунду на пользователя
        $user_id       = get_current_user_id();
        $transient_key = 'wcpbl_search_' . $user_id;
        if ( get_transient( $transient_key ) ) {
            wp_send_json_error( 'Too many requests. Please wait a moment.' );
        }
        set_transient( $transient_key, 1, 1 );

        $results_limit = 10;

        // Поиск по названию через WP_Query
        $args = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
            'posts_per_page' => $results_limit,
            's'              => $q,
        ];

        // Дополнительный поиск по артикулу (SKU)
        $by_sku = wc_get_products( [ 'sku' => $q, 'limit' => $results_limit ] );

        $posts  = get_posts( $args );
        $ids_seen = [];
        $results  = [];

        // Метки статусов для отображения
        $status_labels = [
            'publish' => 'Published',
            'draft'   => 'Draft',
            'private' => 'Private',
            'pending' => 'Pending',
        ];

        // Объединяем результаты с дедупликацией по ID, ограничиваем общее число
        foreach ( array_merge( $by_sku, array_map( 'wc_get_product', $posts ) ) as $p ) {
            if ( ! $p || isset( $ids_seen[ $p->get_id() ] ) ) continue;
            if ( count( $results ) >= $results_limit ) break;
            $ids_seen[ $p->get_id() ] = true;
            $results[] = [
                'id'           => $p->get_id(),
                'name'         => $p->get_name(),
                'sku'          => $p->get_sku(),
                'status'       => get_post_status( $p->get_id() ),
                'status_label' => $status_labels[ get_post_status($p->get_id()) ] ?? get_post_status($p->get_id()),
            ];
        }

        wp_send_json_success( $results );
    }

    // ─── AJAX: add to blacklist ──────────────────────────────────────────────

    /**
     * AJAX-обработчик: добавление товара в чёрный список.
     *
     * Добавляет ID товара в опцию (единственный источник истины).
     * Если товар уже опубликован, немедленно переводит в черновик.
     *
     * @since  1.0.0
     * @return void Отправляет JSON-ответ и завершает выполнение.
     */
    public function ajax_add_to_blacklist() {
        check_ajax_referer( 'wcpbl_nonce', 'nonce' );
        if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error('Access denied');

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error('Invalid ID');

        if ( get_post_type( $id ) !== 'product' ) {
            wp_send_json_error( 'Post is not a WooCommerce product' );
        }

        $list = $this->get_blacklist();

        // Добавляем только если ещё нет в списке
        if ( ! in_array( $id, $list ) ) {
            $list[] = $id;
            update_option( self::OPTION_KEY, $list );

            $this->log_action( $id, 'added_to_blacklist', 'Manually added via admin UI' );
        }

        // Немедленно снимаем с публикации, если товар опубликован
        if ( get_post_status( $id ) === 'publish' ) {
            // Отключаем хук, чтобы избежать рекурсии
            remove_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999 );
            wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
            add_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999, 2 );

            $this->log_action( $id, 'publish_blocked', 'Auto-drafted on blacklist add (was published)' );
        }

        wp_send_json_success();
    }

    // ─── AJAX: remove from blacklist ───────────────────────────────────────────

    /**
     * AJAX-обработчик: удаление товара из чёрного списка.
     *
     * Удаляет ID товара из опции.
     * После удаления товар снова может быть опубликован.
     *
     * @since  1.0.0
     * @return void Отправляет JSON-ответ и завершает выполнение.
     */
    public function ajax_remove_from_blacklist() {
        check_ajax_referer( 'wcpbl_nonce', 'nonce' );
        if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error('Access denied');

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid ID' );

        if ( get_post_type( $id ) !== 'product' ) {
            wp_send_json_error( 'Post is not a WooCommerce product' );
        }

        // Удаляем ID из массива и переиндексируем
        $list = array_filter( $this->get_blacklist(), function( $i ) use ( $id ) { return $i !== $id; } );
        update_option( self::OPTION_KEY, array_values( $list ) );

        $this->log_action( $id, 'removed_from_blacklist', 'Manually removed via admin UI' );

        wp_send_json_success();
    }

    // ─── Enforce draft on save ────────────────────────────────────────────────

    /**
     * Принудительно возвращает товар в черновик при сохранении.
     *
     * Срабатывает на хуке save_post_product с приоритетом 999,
     * чтобы выполниться после всех остальных обработчиков (включая синхронизацию).
     * Отключает собственный хук перед обновлением, чтобы избежать бесконечной рекурсии.
     *
     * @since  1.0.0
     * @param  int      $post_id ID сохраняемого товара.
     * @param  \WP_Post $post    Объект записи товара.
     * @return void
     */
    public function enforce_blacklist_on_save( $post_id, $post ) {
        // Проверяем: товар в чёрном списке и пытается стать опубликованным
        if ( $this->is_blacklisted( $post_id ) && $post->post_status === 'publish' ) {
            // Отключаем хук, чтобы предотвратить бесконечный цикл
            remove_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999 );
            wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
            add_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999, 2 );

            $this->log_action( $post_id, 'publish_blocked', 'Blocked on save_post_product hook' );
        }
    }

    // ─── Enforce draft on transition (external syncs) ────────────────────────

    /**
     * Принудительно возвращает товар в черновик при смене статуса.
     *
     * Срабатывает на хуке transition_post_status с приоритетом 999.
     * Перехватывает любые попытки перевода товара в статус «publish»,
     * включая изменения через REST API и внешние системы синхронизации.
     *
     * @since  1.0.0
     * @param  string   $new_status Новый статус записи.
     * @param  string   $old_status Предыдущий статус записи.
     * @param  \WP_Post $post       Объект записи.
     * @return void
     */
    public function enforce_blacklist_on_transition( $new_status, $old_status, $post ) {
        // Пропускаем не-товары
        if ( $post->post_type !== 'product' ) return;

        // Пропускаем, если новый статус не «publish»
        if ( $new_status !== 'publish' ) return;

        // Пропускаем, если товар не в чёрном списке
        if ( ! $this->is_blacklisted( $post->ID ) ) return;

        // Немедленно откатываем — отключаем хук для предотвращения рекурсии
        remove_action( 'transition_post_status', [ $this, 'enforce_blacklist_on_transition' ], 999 );
        wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ] );
        add_action( 'transition_post_status', [ $this, 'enforce_blacklist_on_transition' ], 999, 3 );

        $this->log_action(
            $post->ID,
            'publish_blocked',
            sprintf( 'Blocked on status transition %s -> publish', $old_status )
        );
    }

    // ─── Migration ────────────────────────────────────────────────────────────

    /**
     * Одноразовая миграция: удаляет устаревшие post_meta записи.
     *
     * До версии 1.1.0 данные хранились дублированно: в wp_options и в post_meta.
     * Теперь единственный источник истины — опция OPTION_KEY.
     * Эта миграция удаляет все старые мета-значения _wc_publish_blacklisted.
     *
     * @since  1.1.0
     * @return void
     */
    public function maybe_migrate_legacy_meta() {
        if ( get_option( self::MIGRATION_OPTION ) ) {
            return;
        }

        global $wpdb;

        // Удаляем все устаревшие мета-записи одним запросом
        $wpdb->delete(
            $wpdb->postmeta,
            [ 'meta_key' => self::LEGACY_META_KEY ],
            [ '%s' ]
        );

        update_option( self::MIGRATION_OPTION, 1, true );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Записывает событие в журнал аудита.
     *
     * Хранит до LOG_MAX_ENTRIES записей в опции wp_options.
     * Каждая запись содержит: дату, пользователя, ID/название товара и причину.
     *
     * @since  1.1.0
     * @param  int    $product_id ID товара.
     * @param  string $action     Тип действия (added_to_blacklist, removed_from_blacklist, publish_blocked).
     * @param  string $reason     Описание причины / контекст.
     * @return void
     */
    private function log_action( int $product_id, string $action, string $reason = '' ) {
        $log = (array) get_option( self::LOG_OPTION_KEY, [] );

        $user    = wp_get_current_user();
        $product = wc_get_product( $product_id );

        $entry = [
            'timestamp'    => current_time( 'mysql' ),
            'timestamp_gmt'=> current_time( 'mysql', true ),
            'user_id'      => $user->ID ?? 0,
            'user_login'   => $user->user_login ?? 'system',
            'product_id'   => $product_id,
            'product_title'=> $product ? $product->get_name() : get_the_title( $product_id ),
            'action'       => $action,
            'reason'       => $reason,
        ];

        $log[] = $entry;

        // Обрезаем до максимального количества записей
        if ( count( $log ) > self::LOG_MAX_ENTRIES ) {
            $log = array_slice( $log, -self::LOG_MAX_ENTRIES );
        }

        update_option( self::LOG_OPTION_KEY, $log, false );
    }

    /**
     * Возвращает массив ID товаров из чёрного списка.
     *
     * @since  1.0.0
     * @return array<int> Массив ID заблокированных товаров.
     */
    private function get_blacklist(): array {
        return (array) get_option( self::OPTION_KEY, [] );
    }

    /**
     * Проверяет, находится ли товар в чёрном списке.
     *
     * @since  1.0.0
     * @param  int $id ID товара для проверки.
     * @return bool True, если товар в чёрном списке.
     */
    private function is_blacklisted( int $id ): bool {
        return in_array( $id, $this->get_blacklist(), true );
    }

    /**
     * Возвращает читаемую метку статуса записи.
     *
     * @since  1.0.0
     * @param  string $status Статус записи WordPress (publish, draft, private, pending).
     * @return string Человекочитаемая метка статуса.
     */
    private function status_label( string $status ): string {
        return [
            'publish' => 'Published',
            'draft'   => 'Draft',
            'private' => 'Private',
            'pending' => 'Pending',
        ][$status] ?? $status;
    }

    /**
     * Подключает необходимые скрипты на странице плагина.
     *
     * Загружает jQuery только на странице управления чёрным списком,
     * проверяя slug текущей страницы.
     *
     * @since  1.0.0
     * @param  string $hook Slug текущей страницы администратора.
     * @return void
     */
    public function enqueue_scripts( $hook ) {
        // Подключаем скрипты только на странице плагина
        if ( strpos($hook, self::MENU_SLUG) === false ) return;
        wp_enqueue_script('jquery');
    }
}

// Инициализация плагина
new WC_Publish_Blacklist();
