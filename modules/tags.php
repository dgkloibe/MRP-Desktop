<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Tags {

    public static function init() {
        add_shortcode('kimrp_tags', [__CLASS__, 'render']);
        add_action('admin_post_kimrp2_create_tag', [__CLASS__, 'handle_create_tag']);
        add_action('admin_post_kimrp2_delete_tag', [__CLASS__, 'handle_delete_tag']);
    }

    private static function tags_table() { return KIMRP2_Core::table('tags'); }
    private static function entity_tags_table() { return KIMRP2_Core::table('entity_tags'); }

    private static function back_url($fallback = '') {
        $ref = wp_get_referer();
        if ($ref) return $ref;
        return $fallback ?: site_url('/');
    }

    public static function handle_create_tag() {
        if (!current_user_can(KIMRP2_Core::CAP)) wp_die('Forbidden');
        $back = self::back_url(site_url('/tags/'));

        $nonce = $_POST['_kimrp_nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'kimrp2_create_tag')) {
            wp_safe_redirect(add_query_arg('kimrp_err', 'bad_nonce', $back)); exit;
        }

        global $wpdb;
        $name = trim(sanitize_text_field($_POST['name'] ?? ''));
        if ($name === '') {
            wp_safe_redirect(add_query_arg('kimrp_err', 'missing_name', $back)); exit;
        }

        $ok = $wpdb->insert(self::tags_table(), ['name'=>$name,'created_at'=>KIMRP2_Core::now()]);
        if ($ok) wp_safe_redirect(add_query_arg('kimrp_ok', 'tag_created', $back));
        else wp_safe_redirect(add_query_arg('kimrp_err', 'create_failed', $back));
        exit;
    }

    public static function handle_delete_tag() {
        if (!current_user_can(KIMRP2_Core::CAP)) wp_die('Forbidden');
        $back = self::back_url(site_url('/tags/'));

        $id = (int)($_POST['tag_id'] ?? 0);
        $nonce = $_POST['_kimrp_nonce'] ?? '';
        if ($id <= 0 || !wp_verify_nonce($nonce, 'kimrp2_delete_tag_'.$id)) {
            wp_safe_redirect(add_query_arg('kimrp_err', 'bad_delete', $back)); exit;
        }

        global $wpdb;
        $wpdb->delete(self::entity_tags_table(), ['tag_id'=>$id]);
        $wpdb->delete(self::tags_table(), ['id'=>$id]);
        wp_safe_redirect(add_query_arg('kimrp_ok', 'tag_deleted', $back));
        exit;
    }

    private static function get_all_tags() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . self::tags_table() . " ORDER BY name ASC");
    }

    public static function render() {
        if (!current_user_can(KIMRP2_Core::CAP)) return '';

        $notice = '';
        $error = '';

        if (!empty($_GET['kimrp_ok'])) {
            $ok = sanitize_text_field($_GET['kimrp_ok']);
            if ($ok === 'tag_created') $notice = 'Tag created.';
            if ($ok === 'tag_deleted') $notice = 'Tag deleted.';
        }
        if (!empty($_GET['kimrp_err'])) {
            $err = sanitize_text_field($_GET['kimrp_err']);
            if ($err === 'bad_nonce') $error = 'Invalid request.';
            if ($err === 'missing_name') $error = 'Tag name required.';
            if ($err === 'create_failed') $error = 'Could not create tag (maybe duplicate name).';
            if ($err === 'bad_delete') $error = 'Invalid delete request.';
        }

        if (isset($_GET['kimrp_ok']) || isset($_GET['kimrp_err'])) {
            echo '<script>(function(){try{var u=new URL(location.href);u.searchParams.delete("kimrp_ok");u.searchParams.delete("kimrp_err");history.replaceState({}, "", u.toString());}catch(e){}})();</script>';
        }

        $rows = self::get_all_tags();

        ob_start();
        echo KIMRP2_Core::ui_css_once();
        ?>
        <div class="kimrp2-wrap">
            <h2>Tags</h2>

            <?php if ($notice) echo KIMRP2_Core::notice($notice); ?>
            <?php if ($error): ?><div class="notice notice-error"><p><?= esc_html($error) ?></p></div><?php endif; ?>

            <h3>Create Tag</h3>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                <input type="hidden" name="action" value="kimrp2_create_tag">
                <input type="hidden" name="_kimrp_nonce" value="<?= esc_attr(wp_create_nonce('kimrp2_create_tag')) ?>">
                <div>
                    <label style="display:block;">Name</label>
                    <input name="name" placeholder="Purchased Parts" required>
                </div>
                <div><button class="kimrp2-btn" type="submit">Create</button></div>
            </form>

            <h3 style="margin-top:16px;">All Tags</h3>
            <table class="widefat striped">
                <thead><tr><th>Name</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= esc_html($r->name) ?></td>
                        <td>
                            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
                                <input type="hidden" name="action" value="kimrp2_delete_tag">
                                <input type="hidden" name="tag_id" value="<?= (int)$r->id ?>">
                                <input type="hidden" name="_kimrp_nonce" value="<?= esc_attr(wp_create_nonce('kimrp2_delete_tag_'.$r->id)) ?>">
                                <button class="kimrp2-btn kimrp2-btn-secondary" type="submit" onclick="return confirm('Delete this tag?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="opacity:.75;margin-top:10px;">
                Shortcode: <code>[kimrp_tags]</code>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
KIMRP2_Tags::init();