<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Inventory {

    public static function init() {
        add_shortcode('kimrp_inventory', [__CLASS__, 'render']);
    }

    private static function part_select($selected = 0) {
        global $wpdb;
        $parts_table = KIMRP2_Core::table('parts');
        $parts = $wpdb->get_results("SELECT id, part_number FROM $parts_table ORDER BY part_number ASC");

        // Searchable
        $html = '<select class="kimrp2-searchable" data-placeholder="Search part..." name="part_id" required>';
        $html .= '<option value="">Select Part</option>';
        foreach ($parts as $p) {
            $sel = ((int)$selected === (int)$p->id) ? 'selected' : '';
            $html .= '<option value="'.esc_attr($p->id).'" '.$sel.'>'.
                     esc_html($p->part_number).' (ID '.$p->id.')</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function fmt_qty($qty) {
        if (abs($qty - round($qty)) < 0.000001) return (string)(int)round($qty);
        return rtrim(rtrim(number_format((float)$qty, 3, '.', ''), '0'), '.');
    }

    public static function render() {
        if (!current_user_can(KIMRP2_Core::CAP)) return '';

        global $wpdb;
        $inv = KIMRP2_Core::table('inventory');
        $moves = KIMRP2_Core::table('inventory_moves');
        $parts = KIMRP2_Core::table('parts');

        $notice = '';

        // POST: adjust inventory
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $part_id = (int)($_POST['part_id'] ?? 0);
            $mode = sanitize_text_field($_POST['mode'] ?? 'delta'); // delta|set
            $delta = (float)($_POST['delta'] ?? 0);
            $new_qty = (float)($_POST['new_qty'] ?? 0);
            $note = sanitize_text_field($_POST['note'] ?? '');

            if ($part_id > 0) {
                $current = $wpdb->get_var($wpdb->prepare("SELECT qty FROM $inv WHERE part_id=%d", $part_id));
                $before = ($current === null) ? 0.0 : (float)$current;

                if ($mode === 'set') {
                    $after = $new_qty;
                    $delta_used = $after - $before;
                } else {
                    $delta_used = $delta;
                    $after = $before + $delta_used;
                }

                // Upsert current inventory
                $exists = $wpdb->get_var($wpdb->prepare("SELECT part_id FROM $inv WHERE part_id=%d", $part_id));
                if ($exists) {
                    $wpdb->update($inv, ['qty'=>$after], ['part_id'=>$part_id]);
                } else {
                    $wpdb->insert($inv, ['part_id'=>$part_id, 'qty'=>$after]);
                }

                // Log move
                $wpdb->insert($moves, [
                    'part_id'=>$part_id,
                    'delta'=>$delta_used,
                    'qty_before'=>$before,
                    'qty_after'=>$after,
                    'note'=>$note,
                    'created_at'=>KIMRP2_Core::now()
                ]);

                $notice = 'Inventory updated.';
            }
        }

        // Current inventory table
        $current_rows = $wpdb->get_results("
            SELECT p.id AS part_id, p.part_number, p.uom, COALESCE(i.qty, 0) AS qty
            FROM $parts p
            LEFT JOIN $inv i ON i.part_id = p.id
            ORDER BY p.part_number ASC
        ");

        // History
        $history_rows = $wpdb->get_results("
            SELECT m.*, p.part_number
            FROM $moves m
            LEFT JOIN $parts p ON p.id = m.part_id
            ORDER BY m.id DESC
            LIMIT 200
        ");

        ob_start();
        ?>
        <h2>Inventory</h2>

        <?php if ($notice): ?>
            <div class="notice notice-success"><p><?= esc_html($notice) ?></p></div>
        <?php endif; ?>

        <h3>Adjust Inventory</h3>
        <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <div>
                <label style="display:block;">Part</label>
                <?= self::part_select() ?>
            </div>

            <div>
                <label style="display:block;">Mode</label>
                <select name="mode">
                    <option value="delta" selected>Delta (+ / -)</option>
                    <option value="set">Set New Qty</option>
                </select>
            </div>

            <div>
                <label style="display:block;">Delta</label>
                <input name="delta" placeholder="+5 or -2" style="width:120px;">
            </div>

            <div>
                <label style="display:block;">New Qty</label>
                <input name="new_qty" placeholder="override" style="width:120px;">
            </div>

            <div>
                <label style="display:block;">Note</label>
                <input name="note" placeholder="optional note" style="width:220px;">
            </div>

            <div>
                <button type="submit">Save</button>
            </div>
        </form>

        <p style="opacity:.8; margin-top:6px;">
            Tip: Use <b>Delta</b> for adjustments, or <b>Set New Qty</b> to override. Whole numbers display with no decimals.
        </p>

        <h3 style="margin-top:18px;">Current Inventory</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Part</th>
                    <th>Qty</th>
                    <th>UOM</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($current_rows as $r): ?>
                <tr>
                    <td><?= esc_html($r->part_number) ?></td>
                    <td><?= esc_html(self::fmt_qty((float)$r->qty)) ?></td>
                    <td><?= esc_html($r->uom ?: 'pcs') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:18px;">Adjustment History (latest 200)</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Part</th>
                    <th>Delta</th>
                    <th>Before</th>
                    <th>After</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history_rows as $m): ?>
                <tr>
                    <td><?= esc_html($m->created_at) ?></td>
                    <td><?= esc_html($m->part_number) ?></td>
                    <td><?= esc_html(self::fmt_qty((float)$m->delta)) ?></td>
                    <td><?= esc_html(self::fmt_qty((float)$m->qty_before)) ?></td>
                    <td><?= esc_html(self::fmt_qty((float)$m->qty_after)) ?></td>
                    <td><?= esc_html($m->note) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        return ob_get_clean();
    }
}
KIMRP2_Inventory::init();