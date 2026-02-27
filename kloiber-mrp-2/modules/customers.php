<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Customers {

    public static function init() {
        add_shortcode('kimrp_customers', [__CLASS__, 'render']);

        // Admin-post handlers
        add_action('admin_post_kimrp2_customer_create', [__CLASS__, 'handle_create']);
        add_action('admin_post_kimrp2_customer_update', [__CLASS__, 'handle_update']);
    }

    private static function table() {
        return KIMRP2_Core::table('customers');
    }

    private static function back_url($fallback = '') {
        $ref = wp_get_referer();
        if ($ref) return $ref;
        return $fallback ?: site_url('/');
    }

    private static function get_table_columns($table) {
        global $wpdb;
        $cols = [];
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `$table`");
        if ($rows) {
            foreach ($rows as $r) {
                if (!empty($r->Field)) $cols[$r->Field] = true;
            }
        }
        return $cols;
    }

    private static function filter_data_by_columns($data, $cols) {
        $out = [];
        foreach ($data as $k => $v) {
            if (isset($cols[$k])) $out[$k] = $v;
        }
        return $out;
    }

    public static function handle_create() {
        if (!current_user_can(KIMRP2_Core::CAP)) wp_die('Forbidden');

        $back = self::back_url(site_url('/customers/'));

        $nonce = $_POST['_kimrp_nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'kimrp2_customer_create')) {
            wp_safe_redirect(add_query_arg('kimrp_err', 'bad_nonce', $back));
            exit;
        }

        $name  = trim(sanitize_text_field($_POST['name'] ?? ''));
        $email = trim(sanitize_text_field($_POST['email'] ?? ''));
        $phone = trim(sanitize_text_field($_POST['phone'] ?? ''));
        $notes = trim(sanitize_textarea_field($_POST['notes'] ?? ''));

        if ($name === '') {
            wp_safe_redirect(add_query_arg('kimrp_err', 'missing_name', $back));
            exit;
        }

        global $wpdb;
        $table = self::table();
        $cols = self::get_table_columns($table);

        // Try full insert (if columns exist)
        $data_full = self::filter_data_by_columns([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
            'created_at' => KIMRP2_Core::now(),
        ], $cols);

        $ok = false;

        if (!empty($data_full)) {
            $ok = (bool)$wpdb->insert($table, $data_full);
        }

        // Fallback: minimal insert (covers older schemas)
        if (!$ok) {
            $data_min = self::filter_data_by_columns([
                'name' => $name,
                'created_at' => KIMRP2_Core::now(),
            ], $cols);

            if (!empty($data_min)) {
                $ok = (bool)$wpdb->insert($table, $data_min);
            }
        }

        if ($ok) {
            wp_safe_redirect(add_query_arg('kimrp_ok', 'customer_created', $back));
        } else {
            // Optional: include DB error only when WP_DEBUG is on
            if (defined('WP_DEBUG') && WP_DEBUG && !empty($wpdb->last_error)) {
                $back = add_query_arg('kimrp_db', rawurlencode($wpdb->last_error), $back);
            }
            wp_safe_redirect(add_query_arg('kimrp_err', 'create_failed', $back));
        }
        exit;
    }

    public static function handle_update() {
        if (!current_user_can(KIMRP2_Core::CAP)) wp_die('Forbidden');

        $back = self::back_url(site_url('/customers/'));

        $id = (int)($_POST['id'] ?? 0);
        $nonce = $_POST['_kimrp_nonce'] ?? '';
        if ($id <= 0 || !wp_verify_nonce($nonce, 'kimrp2_customer_update_'.$id)) {
            wp_safe_redirect(add_query_arg('kimrp_err', 'bad_update', $back));
            exit;
        }

        $name  = trim(sanitize_text_field($_POST['name'] ?? ''));
        $email = trim(sanitize_text_field($_POST['email'] ?? ''));
        $phone = trim(sanitize_text_field($_POST['phone'] ?? ''));
        $notes = trim(sanitize_textarea_field($_POST['notes'] ?? ''));

        if ($name === '') {
            wp_safe_redirect(add_query_arg('kimrp_err', 'missing_name', $back));
            exit;
        }

        global $wpdb;
        $table = self::table();
        $cols = self::get_table_columns($table);

        $data = self::filter_data_by_columns([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'notes' => $notes,
        ], $cols);

        // If schema only has name, this still works
        $wpdb->update($table, $data, ['id' => $id]);

        // close editor after save
        $back2 = remove_query_arg(['edit_customer'], $back);
        wp_safe_redirect(add_query_arg('kimrp_ok', 'customer_updated', $back2));
        exit;
    }

    public static function render() {
        if (!current_user_can(KIMRP2_Core::CAP)) return '';

        global $wpdb;
        $t = self::table();

        $notice = '';
        $error = '';

        if (!empty($_GET['kimrp_ok'])) {
            $ok = sanitize_text_field($_GET['kimrp_ok']);
            if ($ok === 'customer_created') $notice = 'Customer created.';
            if ($ok === 'customer_updated') $notice = 'Customer updated.';
        }
        if (!empty($_GET['kimrp_err'])) {
            $err = sanitize_text_field($_GET['kimrp_err']);
            if ($err === 'bad_nonce') $error = 'Invalid request.';
            if ($err === 'missing_name') $error = 'Customer name is required.';
            if ($err === 'create_failed') $error = 'Could not create customer.';
            if ($err === 'bad_update') $error = 'Invalid update request.';
        }

        // Optional DB error display (only if WP_DEBUG)
        $db_err = '';
        if (defined('WP_DEBUG') && WP_DEBUG && !empty($_GET['kimrp_db'])) {
            $db_err = sanitize_text_field(wp_unslash($_GET['kimrp_db']));
        }

        // Edit mode
        $edit_id = isset($_GET['edit_customer']) ? (int)$_GET['edit_customer'] : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $edit_id)) : null;

        // List
        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY name ASC");

        ob_start();
        echo KIMRP2_Core::ui_css_once();
        ?>
        <div class="kimrp2-wrap">
            <h2>Customers</h2>

            <?php if ($notice) echo KIMRP2_Core::notice($notice); ?>
            <?php if ($error): ?><div class="notice notice-error"><p><?= esc_html($error) ?></p></div><?php endif; ?>
            <?php if ($db_err): ?><div class="notice notice-error"><p><?= esc_html($db_err) ?></p></div><?php endif; ?>

            <?php if (isset($_GET['kimrp_ok']) || isset($_GET['kimrp_err']) || isset($_GET['kimrp_db'])): ?>
                <script>
                  (function(){
                    try{
                      var u=new URL(location.href);
                      u.searchParams.delete('kimrp_ok');
                      u.searchParams.delete('kimrp_err');
                      u.searchParams.delete('kimrp_db');
                      history.replaceState({}, "", u.toString());
                    }catch(e){}
                  })();
                </script>
            <?php endif; ?>

            <?php if ($edit): ?>
                <h3>Edit Customer</h3>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                    <input type="hidden" name="action" value="kimrp2_customer_update">
                    <input type="hidden" name="id" value="<?= (int)$edit->id ?>">
                    <input type="hidden" name="_kimrp_nonce" value="<?= esc_attr(wp_create_nonce('kimrp2_customer_update_'.$edit->id)) ?>">

                    <div>
                        <label style="display:block;">Name</label>
                        <input name="name" value="<?= esc_attr($edit->name ?? '') ?>" required>
                    </div>
                    <div>
                        <label style="display:block;">Email</label>
                        <input name="email" value="<?= esc_attr($edit->email ?? '') ?>">
                    </div>
                    <div>
                        <label style="display:block;">Phone</label>
                        <input name="phone" value="<?= esc_attr($edit->phone ?? '') ?>">
                    </div>
                    <div style="min-width:320px;max-width:520px;">
                        <label style="display:block;">Notes</label>
                        <textarea name="notes" rows="2" style="width:100%;"><?= esc_textarea($edit->notes ?? '') ?></textarea>
                    </div>

                    <div>
                        <button class="kimrp2-btn" type="submit">Save</button>
                        <a class="kimrp2-btn kimrp2-btn-secondary" href="<?= esc_url(remove_query_arg(['edit_customer'])) ?>">Close</a>
                    </div>
                </form>
            <?php else: ?>
                <h3>Create Customer</h3>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                    <input type="hidden" name="action" value="kimrp2_customer_create">
                    <input type="hidden" name="_kimrp_nonce" value="<?= esc_attr(wp_create_nonce('kimrp2_customer_create')) ?>">

                    <div>
                        <label style="display:block;">Name</label>
                        <input name="name" placeholder="Leon Speakers" required>
                    </div>
                    <div>
                        <label style="display:block;">Email</label>
                        <input name="email">
                    </div>
                    <div>
                        <label style="display:block;">Phone</label>
                        <input name="phone">
                    </div>
                    <div style="min-width:320px;max-width:520px;">
                        <label style="display:block;">Notes</label>
                        <textarea name="notes" rows="2" style="width:100%;"></textarea>
                    </div>

                    <div>
                        <button class="kimrp2-btn" type="submit">Create</button>
                    </div>
                </form>
            <?php endif; ?>

            <h3 style="margin-top:16px;">Customer List</h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="4" style="opacity:.7;">No customers yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= esc_html($r->name ?? '') ?></td>
                            <td><?= esc_html($r->email ?? '') ?></td>
                            <td><?= esc_html($r->phone ?? '') ?></td>
                            <td><a href="<?= esc_url(add_query_arg('edit_customer', (int)$r->id)) ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <p style="opacity:.75;margin-top:10px;">
                Shortcode: <code>[kimrp_customers]</code>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
KIMRP2_Customers::init();