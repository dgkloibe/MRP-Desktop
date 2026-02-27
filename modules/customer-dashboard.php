<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer Dashboard Module
 * Provides the [kloiber_customer_dashboard] shortcode with KPIs, order history,
 * active parts, reorder and quote forms. Uses nonces for security and sanitizes input.
 */
class KIMRP2_CustomerDashboard {

    public static function init() {
        add_shortcode('kloiber_customer_dashboard', [__CLASS__, 'render']);
    }

    private static function get_current_customer_id() {
        global $wpdb;
        $user = wp_get_current_user();
        if ($user && $user->exists() && !empty($user->user_email)) {
            $email = $user->user_email;
            $tbl = KIMRP2_Core::table('customers');
            $cid = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE email = %s", $email));
            if ($cid) return (int)$cid;
        }
        $cid = $wpdb->get_var("SELECT id FROM " . KIMRP2_Core::table('customers') . " ORDER BY id ASC LIMIT 1");
        return $cid ? (int)$cid : 0;
    }

    public static function render() {
        if (is_admin()) return '';

        global $wpdb;
        $customer_id = self::get_current_customer_id();
        $orders_table = KIMRP2_Core::table('orders');
        $quotes_table = KIMRP2_Core::table('quotes');
        $parts_table = KIMRP2_Core::table('parts');

        // Handle GET: duplicate order (reorder)
        if (isset($_GET['reorder_id']) && isset($_GET['_kimrpnonce'])) {
            $oid = (int)$_GET['reorder_id'];
            $nonce = sanitize_text_field($_GET['_kimrpnonce']);
            if ($oid > 0 && wp_verify_nonce($nonce, 'kimrp2_reorder_'.$oid)) {
                $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $orders_table WHERE id=%d AND customer_id=%d", $oid, $customer_id));
                if ($order) {
                    $wpdb->insert($orders_table, [
                        'customer_id' => $order->customer_id,
                        'part_id'     => $order->part_id,
                        'qty'         => $order->qty,
                        'status'      => 'NEW',
                        'created_at'  => KIMRP2_Core::now()
                    ]);
                }
            }
        }

        // Handle POST submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kimrp2_action'])) {
            $action = sanitize_text_field($_POST['kimrp2_action']);
            if ($action === 'submit_reorder') {
                $nonce = $_POST['_kimrp2_nonce'] ?? '';
                if (wp_verify_nonce($nonce, 'kimrp2_submit_reorder')) {
                    $part_id = (int)($_POST['part_id'] ?? 0);
                    $qty     = max(1, (float)($_POST['qty'] ?? 1));
                    if ($part_id > 0) {
                        $wpdb->insert($orders_table, [
                            'customer_id' => $customer_id,
                            'part_id'     => $part_id,
                            'qty'         => $qty,
                            'status'      => 'NEW',
                            'created_at'  => KIMRP2_Core::now()
                        ]);
                    }
                }
            }
            if ($action === 'request_quote') {
                $nonce = $_POST['_kimrp2_nonce'] ?? '';
                if (wp_verify_nonce($nonce, 'kimrp2_request_quote')) {
                    $part_id = (int)($_POST['part_id'] ?? 0);
                    $qty     = max(1, (float)($_POST['qty'] ?? 1));
                    if ($part_id > 0) {
                        $wpdb->insert($quotes_table, [
                            'customer_id' => $customer_id,
                            'part_id'     => $part_id,
                            'qty'         => $qty,
                            'status'      => 'PENDING',
                            'created_at'  => KIMRP2_Core::now()
                        ]);
                    }
                }
            }
        }

        // KPIs
        $total_orders    = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $orders_table WHERE customer_id=%d", $customer_id));
        $in_production   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $orders_table WHERE customer_id=%d AND UPPER(status) IN ('IN PRODUCTION','IN PROCESS')", $customer_id));
        $pending_quotes  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $quotes_table WHERE customer_id=%d AND UPPER(status)='PENDING'", $customer_id));

        // Orders list
        $orders = $wpdb->get_results($wpdb->prepare("
            SELECT o.id, o.qty, o.status, o.created_at, p.part_number
            FROM $orders_table o
            LEFT JOIN $parts_table p ON p.id = o.part_id
            WHERE o.customer_id = %d
            ORDER BY o.created_at DESC
        ", $customer_id));

        // Active parts
        $active_parts = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.part_number
            FROM $orders_table o
            JOIN $parts_table p ON p.id = o.part_id
            WHERE o.customer_id = %d
        ", $customer_id));

        // All parts for forms
        $parts = $wpdb->get_results("SELECT id, part_number FROM $parts_table ORDER BY part_number ASC");

        ob_start();
        ?>
        <div id="ki-cust-dashboard" data-version="2.1.0">
            <style>
                .ki-tabs button { margin-right:8px; padding:6px 12px; }
                .ki-tabs button.active { font-weight:bold; }
                .ki-content { display:none; }
                .ki-content.active { display:block; }
                .ki-kpis .ki-box { flex:1; background:#f8f9fa; padding:10px; text-align:center; border:1px solid #ccd0d4; }
                table.ki-table { width:100%; border-collapse:collapse; margin-top:8px; }
                table.ki-table th, table.ki-table td { border:1px solid #ccc; padding:6px; text-align:left; }
            </style>
            <div class="ki-tabs">
                <button type="button" data-tab="order-history" class="active"><?php esc_html_e('Order History','kloiber-mrp'); ?></button>
                <button type="button" data-tab="active-parts"><?php esc_html_e('Active Part Numbers','kloiber-mrp'); ?></button>
                <button type="button" data-tab="submit-reorder"><?php esc_html_e('Submit Reorder','kloiber-mrp'); ?></button>
                <button type="button" data-tab="request-quote"><?php esc_html_e('Request a Quote','kloiber-mrp'); ?></button>
            </div>
            <div class="ki-kpis" style="display:flex;gap:20px;margin:16px 0;">
                <div class="ki-box"><h4><?php esc_html_e('Total Orders','kloiber-mrp'); ?></h4><div id="ki-kpi-total-orders"><?php echo esc_html($total_orders); ?></div></div>
                <div class="ki-box"><h4><?php esc_html_e('Parts in Production','kloiber-mrp'); ?></h4><div id="ki-kpi-in-production"><?php echo esc_html($in_production); ?></div></div>
                <div class="ki-box"><h4><?php esc_html_e('Pending Quotes','kloiber-mrp'); ?></h4><div id="ki-kpi-pending-quotes"><?php echo esc_html($pending_quotes); ?></div></div>
            </div>
            <!-- Order History -->
            <div class="ki-content active" data-tab="order-history">
                <h3><?php esc_html_e('Order History','kloiber-mrp'); ?></h3>
                <table id="ki-order-table" class="ki-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Order #','kloiber-mrp'); ?></th>
                            <th><?php esc_html_e('Part #','kloiber-mrp'); ?></th>
                            <th><?php esc_html_e('Qty','kloiber-mrp'); ?></th>
                            <th><?php esc_html_e('Status','kloiber-mrp'); ?></th>
                            <th><?php esc_html_e('Date','kloiber-mrp'); ?></th>
                            <th><?php esc_html_e('Actions','kloiber-mrp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($orders)) : ?>
                            <?php foreach ($orders as $order) : ?>
                                <tr>
                                    <td><?php echo esc_html($order->id); ?></td>
                                    <td><?php echo esc_html($order->part_number); ?></td>
                                    <td><?php echo esc_html($order->qty); ?></td>
                                    <td><?php echo esc_html($order->status); ?></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($order->created_at))); ?></td>
                                    <td>
                                        <?php
                                        $nonce = wp_create_nonce('kimrp2_reorder_'.$order->id);
                                        $reorder_url = add_query_arg([
                                            'reorder_id'  => $order->id,
                                            '_kimrpnonce' => $nonce
                                        ], remove_query_arg(['reorder_id','_kimrpnonce']));
                                        ?>
                                        <a href="<?php echo esc_url($reorder_url); ?>"><?php esc_html_e('Reorder','kloiber-mrp'); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="6"><?php esc_html_e('No orders yet','kloiber-mrp'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Active Parts -->
            <div class="ki-content" data-tab="active-parts">
                <h3><?php esc_html_e('Active Part Numbers','kloiber-mrp'); ?></h3>
                <table class="ki-table">
                    <thead><tr><th><?php esc_html_e('Part #','kloiber-mrp'); ?></th></tr></thead>
                    <tbody>
                        <?php if (!empty($active_parts)) : ?>
                            <?php foreach ($active_parts as $ap) : ?>
                                <tr><td><?php echo esc_html($ap->part_number); ?></td></tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td><?php esc_html_e('No parts yet','kloiber-mrp'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Submit Reorder -->
            <div class="ki-content" data-tab="submit-reorder">
                <h3><?php esc_html_e('Submit Reorder','kloiber-mrp'); ?></h3>
                <form method="post">
                    <input type="hidden" name="kimrp2_action" value="submit_reorder" />
                    <?php wp_nonce_field('kimrp2_submit_reorder', '_kimrp2_nonce'); ?>
                    <div style="margin-bottom:8px;">
                        <label><?php esc_html_e('Part','kloiber-mrp'); ?></label><br/>
                        <select name="part_id" required>
                            <option value=""><?php esc_html_e('Select Part','kloiber-mrp'); ?></option>
                            <?php foreach ($parts as $p) : ?>
                                <option value="<?php echo esc_attr($p->id); ?>"><?php echo esc_html($p->part_number); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:8px;">
                        <label><?php esc_html_e('Quantity','kloiber-mrp'); ?></label><br/>
                        <input type="number" name="qty" value="1" min="1" required />
                    </div>
                    <button type="submit"><?php esc_html_e('Place Order','kloiber-mrp'); ?></button>
                </form>
            </div>
            <!-- Request Quote -->
            <div class="ki-content" data-tab="request-quote">
                <h3><?php esc_html_e('Request a Quote','kloiber-mrp'); ?></h3>
                <form method="post">
                    <input type="hidden" name="kimrp2_action" value="request_quote" />
                    <?php wp_nonce_field('kimrp2_request_quote', '_kimrp2_nonce'); ?>
                    <div style="margin-bottom:8px;">
                        <label><?php esc_html_e('Part','kloiber-mrp'); ?></label><br/>
                        <select name="part_id" required>
                            <option value=""><?php esc_html_e('Select Part','kloiber-mrp'); ?></option>
                            <?php foreach ($parts as $p) : ?>
                                <option value="<?php echo esc_attr($p->id); ?>"><?php echo esc_html($p->part_number); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:8px;">
                        <label><?php esc_html_e('Quantity','kloiber-mrp'); ?></label><br/>
                        <input type="number" name="qty" value="1" min="1" required />
                    </div>
                    <button type="submit"><?php esc_html_e('Submit Quote','kloiber-mrp'); ?></button>
                </form>
            </div>
            <script>
            (function(){
                var tabs = document.querySelectorAll('#ki-cust-dashboard .ki-tabs button');
                tabs.forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var tab = btn.getAttribute('data-tab');
                        tabs.forEach(function(b){ b.classList.toggle('active', b===btn); });
                        var contents = document.querySelectorAll('#ki-cust-dashboard .ki-content');
                        contents.forEach(function(ct){
                            ct.classList.toggle('active', ct.getAttribute('data-tab') === tab);
                        });
                    });
                });
            })();
            </script>
        </div>
        <?php
        return ob_get_clean();
    }
}
KIMRP2_CustomerDashboard::init();
?>