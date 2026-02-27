<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Parts {

    public static function init() {
        add_shortcode('kimrp_parts', [__CLASS__, 'render']);
    }

    private static function table() {
        return KIMRP2_Core::table('parts');
    }

    private static function fmt_qty($qty) {
        $qty = (float)$qty;
        if (abs($qty - round($qty)) < 0.000001) return (string)(int)round($qty);
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    public static function render() {
        if (!current_user_can(KIMRP2_Core::CAP)) return '';

        global $wpdb;
        $t = self::table();

        $notice = '';
        $close_after_save = false;

        // POST: create/update
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = sanitize_text_field($_POST['_kimrp_action'] ?? '');
            $id = (int)($_POST['id'] ?? 0);

            $part_number = trim(sanitize_text_field($_POST['part_number'] ?? ''));
            $description = trim(sanitize_text_field($_POST['description'] ?? ''));
            $uom = trim(sanitize_text_field($_POST['uom'] ?? 'pcs'));
            if ($uom === '') $uom = 'pcs';

            $std_reorder = (float)($_POST['standard_reorder_qty'] ?? 0);

            if ($action === 'create') {
                if ($part_number !== '') {
                    $wpdb->insert($t, [
                        'part_number' => $part_number,
                        'description' => $description,
                        'uom' => $uom,
                        'standard_reorder_qty' => $std_reorder,
                        'created_at' => KIMRP2_Core::now(),
                    ]);
                    $notice = 'Part created.';
                }
            }

            if ($action === 'update' && $id > 0) {
                $wpdb->update($t, [
                    'part_number' => $part_number,
                    'description' => $description,
                    'uom' => $uom,
                    'standard_reorder_qty' => $std_reorder,
                ], ['id' => $id]);
                $notice = 'Part updated.';
                $close_after_save = true;
            }
        }

        // GET: edit
        $edit_id = (!$close_after_save && isset($_GET['edit_part'])) ? (int)$_GET['edit_part'] : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $edit_id)) : null;

        // list
        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY part_number ASC");

        ob_start();
        echo KIMRP2_Core::ui_css_once();
        ?>
        <div class="kimrp2-wrap">
            <h2>Parts</h2>

            <?php if ($notice) echo KIMRP2_Core::notice($notice); ?>

            <?php if ($close_after_save): ?>
                <script>
                  (function(){
                    try{
                      var url = new URL(window.location.href);
                      url.searchParams.delete('edit_part');
                      window.history.replaceState({}, '', url.toString());
                    }catch(e){}
                  })();
                </script>
            <?php endif; ?>

            <?php if ($edit): ?>
                <h3>Edit Part</h3>
                <form method="post" action="<?= esc_url($_SERVER['REQUEST_URI']) ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                    <input type="hidden" name="_kimrp_action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$edit->id ?>">

                    <div>
                        <label style="display:block;">Part Number</label>
                        <input name="part_number" value="<?= esc_attr($edit->part_number) ?>" required>
                    </div>
                    <div style="min-width:320px;max-width:520px;">
                        <label style="display:block;">Description</label>
                        <input name="description" value="<?= esc_attr($edit->description) ?>" style="width:100%;">
                    </div>
                    <div>
                        <label style="display:block;">Unit</label>
                        <input name="uom" value="<?= esc_attr($edit->uom ?: 'pcs') ?>" style="width:90px;">
                    </div>
                    <div>
                        <label style="display:block;">Standard Reorder Qty</label>
                        <input name="standard_reorder_qty" value="<?= esc_attr(self::fmt_qty($edit->standard_reorder_qty)) ?>" style="width:140px;">
                    </div>

                    <div>
                        <button class="kimrp2-btn" type="submit">Save</button>
                        <a class="kimrp2-btn kimrp2-btn-secondary" href="<?= esc_url(remove_query_arg(['edit_part'])) ?>">Close</a>
                    </div>
                </form>

            <?php else: ?>
                <h3>Create Part</h3>
                <form method="post" action="<?= esc_url($_SERVER['REQUEST_URI']) ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                    <input type="hidden" name="_kimrp_action" value="create">

                    <div>
                        <label style="display:block;">Part Number</label>
                        <input name="part_number" placeholder="EV10047F" required>
                    </div>
                    <div style="min-width:320px;max-width:520px;">
                        <label style="display:block;">Description</label>
                        <input name="description" style="width:100%;" placeholder="Surron 1MTB bracket">
                    </div>
                    <div>
                        <label style="display:block;">Unit</label>
                        <input name="uom" value="pcs" style="width:90px;">
                    </div>
                    <div>
                        <label style="display:block;">Standard Reorder Qty</label>
                        <input name="standard_reorder_qty" placeholder="0" style="width:140px;">
                    </div>

                    <div>
                        <button class="kimrp2-btn" type="submit">Create</button>
                    </div>
                </form>
            <?php endif; ?>

            <h3 style="margin-top:16px;">Parts List</h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Part Number</th>
                        <th>Description</th>
                        <th>Unit</th>
                        <th>Std Reorder Qty</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="5" style="opacity:.7;">No parts yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= esc_html($r->part_number) ?></td>
                            <td><?= esc_html($r->description) ?></td>
                            <td><?= esc_html($r->uom ?: 'pcs') ?></td>
                            <td><?= esc_html(self::fmt_qty($r->standard_reorder_qty)) ?></td>
                            <td><a href="<?= esc_url(add_query_arg('edit_part', (int)$r->id)) ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <p style="opacity:.75;margin-top:10px;">
                Shortcode: <code>[kimrp_parts]</code>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
KIMRP2_Parts::init();