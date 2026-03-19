<?php
/**
 * Plugin Name: WC Publish Blacklist
 * Description: Prevents selected products from being published even after external sync.
 * Version: 1.0.0
 * Author: Alexander Nemirov
 * Text Domain: wc-publish-blacklist
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Publish_Blacklist {

    const OPTION_KEY   = 'wc_publish_blacklist_ids';
    const META_KEY     = '_wc_publish_blacklisted';
    const MENU_SLUG    = 'wc-publish-blacklist';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_wcpbl_search_products', [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_wcpbl_add_to_blacklist', [ $this, 'ajax_add_to_blacklist' ] );
        add_action( 'wp_ajax_wcpbl_remove_from_blacklist', [ $this, 'ajax_remove_from_blacklist' ] );

        // Block on any post save
        add_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999, 2 );

        // Block on status transition (external syncs)
        add_action( 'transition_post_status', [ $this, 'enforce_blacklist_on_transition' ], 999, 3 );
    }

    // ─── Menu ────────────────────────────────────────────────────────────────

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

    public function render_page() {
        $blacklist = $this->get_blacklist();
        $products  = [];
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
                           placeholder="Enter product name or SKU..."
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

            // ── Search ──────────────────────────────────────────────────────
            function doSearch() {
                const q = $('#wcpbl-search-input').val().trim();
                if (!q) return;
                const $res = $('#wcpbl-search-results').html('<em>Searching...</em>');
                $.post(ajaxurl, { action:'wcpbl_search_products', q, nonce }, function(r){
                    if (!r.success || !r.data.length) {
                        $res.html('<p style="color:#d63638;">Nothing found</p>');
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

            $('#wcpbl-search-btn').on('click', doSearch);
            $('#wcpbl-search-input').on('keypress', e => { if(e.key==='Enter') doSearch(); });

            // ── Add ───────────────────────────────────────────────────
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

            // ── Remove ────────────────────────────────────────────────────
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

    public function ajax_search_products() {
        check_ajax_referer( 'wcpbl_nonce', 'nonce' );
        if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error('Access denied');

        $q = sanitize_text_field( $_POST['q'] ?? '' );

        $args = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish','draft','private','pending' ],
            'posts_per_page' => 20,
            's'              => $q,
        ];

        // Also search by SKU
        $by_sku = wc_get_products([ 'sku' => $q, 'limit' => 10 ]);

        $posts  = get_posts( $args );
        $ids_seen = [];
        $results  = [];

        $status_labels = [
            'publish' => 'Published',
            'draft'   => 'Draft',
            'private' => 'Private',
            'pending' => 'Pending',
        ];

        foreach ( array_merge( $by_sku, array_map('wc_get_product', $posts) ) as $p ) {
            if ( ! $p || isset($ids_seen[$p->get_id()]) ) continue;
            $ids_seen[$p->get_id()] = true;
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

    public function ajax_add_to_blacklist() {
        check_ajax_referer( 'wcpbl_nonce', 'nonce' );
        if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error('Access denied');

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error('Invalid ID');

        $list = $this->get_blacklist();
        if ( ! in_array($id, $list) ) {
            $list[] = $id;
            update_option( self::OPTION_KEY, $list );
            update_post_meta( $id, self::META_KEY, 1 );
        }

        // Immediately unpublish
        if ( get_post_status($id) === 'publish' ) {
            remove_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999 );
            wp_update_post([ 'ID' => $id, 'post_status' => 'draft' ]);
            add_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999, 2 );
        }

        wp_send_json_success();
    }

    // ─── AJAX: remove from blacklist ───────────────────────────────────────────

    public function ajax_remove_from_blacklist() {
        check_ajax_referer( 'wcpbl_nonce', 'nonce' );
        if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error('Access denied');

        $id   = absint( $_POST['id'] ?? 0 );
        $list = array_filter( $this->get_blacklist(), function($i) use ($id) { return $i !== $id; } );
        update_option( self::OPTION_KEY, array_values($list) );
        delete_post_meta( $id, self::META_KEY );

        wp_send_json_success();
    }

    // ─── Enforce draft on save ────────────────────────────────────────────────

    public function enforce_blacklist_on_save( $post_id, $post ) {
        if ( $this->is_blacklisted($post_id) && $post->post_status === 'publish' ) {
            // Remove hook to prevent infinite loop
            remove_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999 );
            wp_update_post([ 'ID' => $post_id, 'post_status' => 'draft' ]);
            add_action( 'save_post_product', [ $this, 'enforce_blacklist_on_save' ], 999, 2 );
        }
    }

    // ─── Enforce draft on transition (external syncs) ────────────────────────

    public function enforce_blacklist_on_transition( $new_status, $old_status, $post ) {
        if ( $post->post_type !== 'product' ) return;
        if ( $new_status !== 'publish' ) return;
        if ( ! $this->is_blacklisted($post->ID) ) return;

        // Immediately revert — remove hooks to prevent loop
        remove_action( 'transition_post_status', [ $this, 'enforce_blacklist_on_transition' ], 999 );
        wp_update_post([ 'ID' => $post->ID, 'post_status' => 'draft' ]);
        add_action( 'transition_post_status', [ $this, 'enforce_blacklist_on_transition' ], 999, 3 );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function get_blacklist(): array {
        return (array) get_option( self::OPTION_KEY, [] );
    }

    private function is_blacklisted( int $id ): bool {
        return in_array( $id, $this->get_blacklist(), true );
    }

    private function status_label( string $status ): string {
        return [
            'publish' => 'Published',
            'draft'   => 'Draft',
            'private' => 'Private',
            'pending' => 'Pending',
        ][$status] ?? $status;
    }

    public function enqueue_scripts( $hook ) {
        if ( strpos($hook, self::MENU_SLUG) === false ) return;
        wp_enqueue_script('jquery');
    }
}

new WC_Publish_Blacklist();
