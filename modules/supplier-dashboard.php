<?php
if (!defined('ABSPATH')) exit;

/**
 * Supplier/Back-End Dashboard Module
 * Provides the [kloiber_supplier_dashboard] shortcode. Displays job KPIs,
 * job management table and allows creating jobs from new orders or quotes.
 */
class KIMRP2_SupplierDashboard {

    public static function init() {
        add_shortcode('kloiber_supplier_dashboard', [__CLASS__, 'render']);
    }

    public static function render() {
        if (is_admin()) return '';

        global $wpdb;
        $orders_table    = KIMRP2_Core::table('orders');
        $quotes_table    = KIMRP2_Core::table('quotes');
        $jobs_table      = KIMRP2_Core::table('jobs');
        $customers_table = KIMRP2_Core::table('customers');
        $parts_table     = KIMRP2_Core::table('parts');

        // Create job from order
        if (isset($_GET['create_job_from_order']) && isset($_GET['_kj_nonce'])) {
            $oid   = (int)$_GET['create_job_from_order'];
            $nonce = sanitize_text_field($_GET['_kj_nonce']);
            if ($oid > 0 && wp_verify_nonce($nonce, 'create_job_from_order_'.$oid)) {
                $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $orders_table WHERE id=%d AND UPPER(status)='NEW'", $oid));
                if ($order) {
                    $wpdb->insert($jobs_table, [
                        'job_code'      => null,
                        'customer_id'   => $order->customer_id,
                        'part_id'       => $order->part_id,
                        'qty'           => $order->qty,
                        'status'        => 'Open',
                        'due_date'      => date('Y-m-d', strtotime('+30 days')),
                        'next_operation'=> '',
                        'notes'         => '',
                        'created_at'    => KIMRP2_Core::now()
                    ]);
                    $wpdb->update($orders_table, ['status'=>'PROCESSED'], ['id'=>$oid]);
                }
                wp_safe_redirect(remove_query_arg(['create_job_from_order','_kj_nonce']));
                exit;
            }
        }

        // Create job from quote
        if (isset($_GET['create_job_from_quote']) && isset($_GET['_kjq_nonce'])) {
            $qid   = (int)$_GET['create_job_from_quote'];
            $nonce = sanitize_text_field($_GET['_kjq_nonce']);
            if ($qid > 0 && wp_verify_nonce($nonce, 'create_job_from_quote_'.$qid)) {
                $quote = $wpdb->get_row($wpdb->prepare("SELECT * FROM $quotes_table WHERE id=%d AND UPPER(status)='PENDING'", $qid));
                if ($quote) {
                    $wpdb->insert($jobs_table, [
                        'job_code'      => null,
                        'customer_id'   => $quote->customer_id,
                        'part_id'       => $quote->part_id,
                        'qty'           => $quote->qty,
                        'status'        => 'Open',
                        'due_date'      => date('Y-m-d', strtotime('+30 days')),
                        'next_operation'=> '',
                        'notes'         => '',
                        'created_at'    => KIMRP2_Core::now()
                    ]);
                    $wpdb->update($quotes_table, ['status'=>'ACCEPTED'], ['id'=>$qid]);
                }
                wp_safe_redirect(remove_query_arg(['create_job_from_quote','_kjq_nonce']));
                exit;
            }
        }

        // Update job
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kimrp2_action']) && $_POST['kimrp2_action'] === 'update_job') {
            $nonce = $_POST['_kimrp2_nonce'] ?? '';
            if (wp_verify_nonce($nonce, 'kimrp2_update_job')) {
                $job_id    = (int)($_POST['job_id'] ?? 0);
                $status    = sanitize_text_field($_POST['status'] ?? '');
                $next_op   = sanitize_text_field($_POST['next_operation'] ?? '');
                $notes     = sanitize_textarea_field($_POST['notes'] ?? '');
                if ($job_id > 0) {
                    $wpdb->update($jobs_table, [
                        'status'        => $status,
                        'next_operation'=> $next_op,
                        'notes'         => $notes
                    ], ['id'=>$job_id]);
                }
            }
        }

        // KPI counts
        $kpi_queued     = (int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs_table WHERE UPPER(status) IN ('OPEN','NEW','QUEUED')");
        $kpi_in_process = (int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs_table WHERE UPPER(status) IN ('IN PROGRESS','IN PROCESS','IN PRODUCTION')");
        $kpi_waiting    = (int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs_table WHERE UPPER(status) IN ('HOLD','WAITING')");
        $kpi_late       = (int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs_table WHERE UPPER(status)='LATE'");

        // Jobs list
        $jobs = $wpdb->get_results("
            SELECT j.id, j.qty, j.status, j.due_date, j.next_operation, j.notes,
                   c.name AS customer_name, p.part_number
            FROM $jobs_table j
            LEFT JOIN $customers_table c ON c.id = j.customer_id
            LEFT JOIN $parts_table p ON p.id = j.part_id
            ORDER BY j.id DESC
        ");

        // New orders
        $new_orders = $wpdb->get_results("
            SELECT o.id, o.qty, c.name AS customer_name, p.part_number
            FROM $orders_table o
            LEFT JOIN $customers_table c ON c.id = o.customer_id
            LEFT JOIN $parts_table p ON p.id = o.part_id
            WHERE UPPER(o.status) = 'NEW'
            ORDER BY o.id DESC
        ");

        // Pending quotes
        $pending_quotes = $wpdb->get_results("
            SELECT q.id, q.qty, c.name AS customer_name, p.part_number
            FROM $quotes_table q
            LEFT JOIN $customers_table c ON c.id = q.customer_id
            LEFT JOIN $parts_table p ON p.id = q.part_id
            WHERE UPPER(q.status) = 'PENDING'
            ORDER BY q.id DESC
        ");

        ob_start();
        ?>
        <div id="ki-supplier-dashboard" data-version="2.1.0">
            <style>
            .ki-kpis .ki-box { flex:1; background:#f8f9fa; padding:10px; text-align:center; border:1px solid #ccd0d4; }
            table.ki-table { width:100%; border-collapse:collapse; margin-top:8px; }
            table.ki-table th, table.ki-table td { border:1px solid #ccc; padding:6px; text-align:left; }
            </style>
            <div class="ki-kpis" style="display:flex;gap:20px;margin-bottom:16px;">
                <div class="ki-box"><h4><?php esc_html_e('Jobs Queued','kloiber-mrp'); ?></h4><div id="ki-kpi-queued"><?php echo esc_html($kpi_queued); ?></div></div>
                <div class="ki-box"><h4><?php esc_html_e('In Process','kloiber-mrp'); ?></h4><div id="ki-kpi-in-process"><?php echo esc_html($kpi_in_process); ?></div></div>
                <div class="ki-box"><h4><?php esc_html_e('Waiting','kloiber-mrp'); ?></h4><div id="ki-kpi-waiting"><?php echo esc_html($kpi_waiting); ?></div></div>
                <div class="ki-box"><h4><?php esc_html_e('Late Jobs','kloiber-mrp'); ?></h4><div id="ki-kpi-late"><?php echo esc_html($kpi_late); ?></div></div>
            </div>
            <h3><?php esc_html_e('Jobs','kloiber-mrp'); ?></h3>
            <table id="ki-jobs-table" class="ki-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Job ID','kloiber-mrp'); ?></th>
                        <th><?php esc_html_e('Customer','kloiber-mrp'); ?></th>
                        <th><?php esc_html_e('Part #','kloiber-mrp'); ?></th>
                        <th><?php esc_html_e('Qty','kloiber-mrp'); ?></th>
                        <th><?php esc_html_e('Due Date','kloiber-mrp'); ?></th>
                        <th><?php esc_html_e('Status','kloiber-mrp'); ?></th>
                        <th><?php esc_html_e('Next Operation','kloiber-mrp'); ?></th>
                        <th><?php esc_html_e('Notes','kloiber-mrp'); ?></th>
                        <th><?php esc_html_e('Actions','kloiber-mrp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($jobs)) : ?>
                        <?php foreach ($jobs as $job) : ?>
                            <tr>
                                <td><?php echo esc_html($job->id); ?></td>
                                <td><?php echo esc_html($job->customer_name); ?></td>
                                <td><?php echo esc_html($job->part_number); ?></td>
                                <td><?php echo esc_html($job->qty); ?></td>
                                <td><?php echo esc_html($job->due_date); ?></td>
                                <td><?php echo esc_html($job->status); ?></td>
                                <td><?php echo esc_html($job->next_operation); ?></td>
                                <td><?php echo esc_html($job->notes); ?></td>
                                <td>
                                    <form method="post" style="display:flex;flex-direction:column;">
                                        <?php wp_nonce_field('kimrp2_update_job', '_kimrp2_nonce'); ?>
                                        <input type="hidden" name="kimrp2_action" value="update_job" />
                                        <input type="hidden" name="job_id" value="<?php echo esc_attr($job->id); ?>" />
                                        <select name="status" style="margin-bottom:4px;">
                                            <?php foreach (['Open','In Progress','Hold','Late','Done'] as $opt) : ?>
                                                <option value="<?php echo esc_attr($opt); ?>" <?php selected($job->status, $opt); ?>><?php echo esc_html($opt); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="next_operation" value="<?php echo esc_attr($job->next_operation); ?>" placeholder="<?php esc_attr_e('Next operation','kloiber-mrp'); ?>" style="margin-bottom:4px;" />
                                        <textarea name="notes" rows="2" placeholder="<?php esc_attr_e('Notes','kloiber-mrp'); ?>" style="margin-bottom:4px;"><?php echo esc_textarea($job->notes); ?></textarea>
                                        <button type="submit"><?php esc_html_e('Save','kloiber-mrp'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="9"><?php esc_html_e('No jobs yet','kloiber-mrp'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($new_orders)) : ?>
                <h3 style="margin-top:24px;"><?php esc_html_e('New Reorders','kloiber-mrp'); ?></h3>
                <table class="ki-table">
                    <thead><tr><th><?php esc_html_e('Order ID','kloiber-mrp'); ?></th><th><?php esc_html_e('Customer','kloiber-mrp'); ?></th><th><?php esc_html_e('Part','kloiber-mrp'); ?></th><th><?php esc_html_e('Qty','kloiber-mrp'); ?></th><th><?php esc_html_e('Action','kloiber-mrp'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($new_orders as $o) : ?>
                            <tr>
                                <td><?php echo esc_html($o->id); ?></td>
                                <td><?php echo esc_html($o->customer_name); ?></td>
                                <td><?php echo esc_html($o->part_number); ?></td>
                                <td><?php echo esc_html($o->qty); ?></td>
                                <td>
                                    <?php
                                    $nonce = wp_create_nonce('create_job_from_order_'.$o->id);
                                    $url = add_query_arg([
                                        'create_job_from_order'=>$o->id,
                                        '_kj_nonce'=>$nonce
                                    ], remove_query_arg(['create_job_from_order','_kj_nonce','create_job_from_quote','_kjq_nonce']));
                                    ?>
                                    <a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Create Job','kloiber-mrp'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($pending_quotes)) : ?>
                <h3 style="margin-top:24px;"><?php esc_html_e('Pending Quotes','kloiber-mrp'); ?></h3>
                <table class="ki-table">
                    <thead><tr><th><?php esc_html_e('Quote ID','kloiber-mrp'); ?></th><th><?php esc_html_e('Customer','kloiber-mrp'); ?></th><th><?php esc_html_e('Part','kloiber-mrp'); ?></th><th><?php esc_html_e('Qty','kloiber-mrp'); ?></th><th><?php esc_html_e('Action','kloiber-mrp'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($pending_quotes as $q) : ?>
                            <tr>
                                <td><?php echo esc_html($q->id); ?></td>
                                <td><?php echo esc_html($q->customer_name); ?></td>
                                <td><?php echo esc_html($q->part_number); ?></td>
                                <td><?php echo esc_html($q->qty); ?></td>
                                <td>
                                    <?php
                                    $nonce = wp_create_nonce('create_job_from_quote_'.$q->id);
                                    $url = add_query_arg([
                                        'create_job_from_quote'=>$q->id,
                                        '_kjq_nonce'=>$nonce
                                    ], remove_query_arg(['create_job_from_quote','_kjq_nonce','create_job_from_order','_kj_nonce']));
                                    ?>
                                    <a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Accept & Create Job','kloiber-mrp'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
KIMRP2_SupplierDashboard::init();
?>