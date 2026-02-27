<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Kanban {

    public static function init() {
        add_shortcode('kimrp_kanban', [__CLASS__, 'render']);
    }

    private static function get_parts() {
        global $wpdb;
        $parts_table = KIMRP2_Core::table('parts');
        return $wpdb->get_results("SELECT id, part_number, COALESCE(standard_reorder_qty,0) AS standard_reorder_qty FROM $parts_table ORDER BY part_number ASC");
    }

    private static function part_select($parts, $selected = 0, $select_id = 'kimrp_part_id', $name = 'part_id') {
        $html = '<select class="kimrp2-searchable" data-placeholder="Search part..." name="'.esc_attr($name).'" id="'.esc_attr($select_id).'" required>';
        $html .= '<option value="" data-std="0">Select Part</option>';
        foreach ($parts as $p) {
            $sel = ((int)$selected === (int)$p->id) ? 'selected' : '';
            $std = (float)$p->standard_reorder_qty;
            $html .= '<option value="'.esc_attr($p->id).'" data-std="'.esc_attr($std).'" '.$sel.'>'.esc_html($p->part_number).'</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function due_plus_days($days) {
        return date('Y-m-d', time() + ((int)$days * 86400));
    }

    private static function next_code($name, $prefix, $pad) {
        global $wpdb;
        $ct = KIMRP2_Core::table('counters');

        $wpdb->query('START TRANSACTION');
        $row = $wpdb->get_row($wpdb->prepare("SELECT next_val FROM $ct WHERE name=%s FOR UPDATE", $name));
        if (!$row) {
            $wpdb->insert($ct, ['name'=>$name, 'next_val'=>2]);
            $val = 1;
        } else {
            $val = (int)$row->next_val;
            $wpdb->update($ct, ['next_val'=>$val+1], ['name'=>$name]);
        }
        $wpdb->query('COMMIT');

        return $prefix . str_pad((string)$val, $pad, '0', STR_PAD_LEFT);
    }

    private static function create_job_from_card($card) {
        global $wpdb;
        $jobs = KIMRP2_Core::table('jobs');

        $job_code = self::next_code('job', 'J-', 6);

        $wpdb->insert($jobs, [
            'job_code' => $job_code,
            'customer_id' => null,
            'part_id' => (int)$card->part_id,
            'qty' => (float)$card->reorder_qty,
            'status' => 'Open',
            'due_date' => self::due_plus_days(30),
            'created_at' => KIMRP2_Core::now()
        ]);

        return [(int)$wpdb->insert_id, $job_code];
    }

    private static function fmt_qty($qty) {
        $qty = (float)$qty;
        if (abs($qty - round($qty)) < 0.000001) return (string)(int)round($qty);
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    private static function part_std_qty($parts, $part_id) {
        foreach ($parts as $p) {
            if ((int)$p->id === (int)$part_id) return (float)$p->standard_reorder_qty;
        }
        return 0.0;
    }

    private static function is_same_qty($a, $b) {
        return abs(((float)$a) - ((float)$b)) < 0.000001;
    }

    public static function render() {
        if (!current_user_can(KIMRP2_Core::CAP)) return '';

        global $wpdb;
        $t = KIMRP2_Core::table('kanban');
        $parts = self::get_parts();

        $notice = '';
        $redirect_to = '';

        // Delete card (GET)
        if (isset($_GET['delete_kb']) && isset($_GET['_kbnonce'])) {
            $id = (int)$_GET['delete_kb'];
            $nonce = sanitize_text_field($_GET['_kbnonce']);
            if ($id > 0 && wp_verify_nonce($nonce, 'kimrp_delete_kb_'.$id)) {
                $wpdb->delete($t, ['id' => $id]);
                $notice = "Kanban card deleted.";
                echo '<script>(function(){try{var u=new URL(location.href);u.searchParams.delete("delete_kb");u.searchParams.delete("_kbnonce");history.replaceState({}, "", u.toString());}catch(e){}})();</script>';
            }
        }

        // Reorder (create job, stay on page)
        if (isset($_GET['kimrp_reorder'])) {
            $id = (int)$_GET['kimrp_reorder'];
            $nonce = $_GET['_kimrp_nonce'] ?? '';
            if (wp_verify_nonce($nonce, 'kimrp_reorder_'.$id)) {
                $card = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
                if ($card) {
                    [, $job_code] = self::create_job_from_card($card);
                    $notice = "Reorder created job ($job_code).";
                }
            }
        }

        // Reorder & Open Job (create + redirect to /jobs/?open_job=ID)
        if (isset($_GET['kimrp_reorder_open'])) {
            $id = (int)$_GET['kimrp_reorder_open'];
            $nonce = $_GET['_kimrp_nonce2'] ?? '';
            if (wp_verify_nonce($nonce, 'kimrp_reorder_open_'.$id)) {
                $card = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
                if ($card) {
                    [$new_job_id, $job_code] = self::create_job_from_card($card);
                    $redirect_to = add_query_arg('open_job', $new_job_id, site_url('/jobs/'));
                    $notice = "Reorder created job ($job_code). Opening job…";
                }
            }
        }

        // Create / Update card (POST)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = sanitize_text_field($_POST['_kimrp_action'] ?? '');

            if ($action === 'create') {
                $part_id = (int)($_POST['part_id'] ?? 0);
                $use_std = !empty($_POST['use_std_qty']) ? 1 : 0;
                $manual = isset($_POST['reorder_qty']) ? (float)$_POST['reorder_qty'] : 0.0;
                $std = self::part_std_qty($parts, $part_id);
                $final_qty = $use_std ? $std : $manual;

                $kb_code = self::next_code('kanban', 'KB-', 6);
                $wpdb->insert($t, [
                    'kb_code' => $kb_code,
                    'part_id' => $part_id,
                    'reorder_qty' => $final_qty,
                    'created_at' => KIMRP2_Core::now()
                ]);
                $notice = "Kanban card created ($kb_code).";
            }

            if ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $part_id = (int)($_POST['part_id'] ?? 0);
                $use_std = !empty($_POST['use_std_qty']) ? 1 : 0;
                $manual = isset($_POST['reorder_qty']) ? (float)$_POST['reorder_qty'] : 0.0;
                $std = self::part_std_qty($parts, $part_id);
                $final_qty = $use_std ? $std : $manual;

                if ($id > 0) {
                    $wpdb->update($t, [
                        'part_id' => $part_id,
                        'reorder_qty' => $final_qty,
                    ], ['id' => $id]);
                    $notice = "Kanban card updated.";
                    echo '<script>(function(){try{var u=new URL(location.href);u.searchParams.delete("edit_kb");history.replaceState({}, "", u.toString());}catch(e){}})();</script>';
                }
            }
        }

        // Edit card (GET)
        $edit_id = isset($_GET['edit_kb']) ? (int)$_GET['edit_kb'] : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $edit_id)) : null;

        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC");
        $base_url = remove_query_arg(['kimrp_reorder','_kimrp_nonce','kimrp_reorder_open','_kimrp_nonce2','edit_kb','delete_kb','_kbnonce']);

        ob_start();
        echo KIMRP2_Core::ui_css_once();
        ?>
        <div class="kimrp2-wrap">
            <h2>Kanban</h2>

            <?php if ($notice) echo KIMRP2_Core::notice($notice); ?>

            <?php if ($redirect_to): ?>
                <p><a class="kimrp2-btn" href="<?= esc_url($redirect_to) ?>"><b>Click here if it doesn’t auto-open.</b></a></p>
                <script>window.location.href = <?= json_encode($redirect_to) ?>;</script>
            <?php endif; ?>

            <?php
                $edit_use_std = true;
                $edit_manual_qty = '';
                $edit_std = 0.0;
                if ($edit) {
                    $edit_std = self::part_std_qty($parts, (int)$edit->part_id);
                    $edit_use_std = self::is_same_qty((float)$edit->reorder_qty, (float)$edit_std);
                    $edit_manual_qty = $edit_use_std ? '' : self::fmt_qty($edit->reorder_qty);
                }
            ?>

            <?php if ($edit): ?>
                <h3>Edit Kanban Card <?= esc_html($edit->kb_code ?: ('ID '.$edit->id)) ?></h3>
                <form method="post" id="kimrp_kanban_edit">
                    <input type="hidden" name="_kimrp_action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$edit->id ?>">

                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                        <div>
                            <label style="display:block;">Part</label>
                            <?= self::part_select($parts, (int)$edit->part_id, 'kimrp_part_id_edit', 'part_id') ?>
                        </div>

                        <div>
                            <label style="display:block;">
                                <input type="checkbox" name="use_std_qty" id="kimrp_use_std_qty_edit" value="1" <?= $edit_use_std ? 'checked' : '' ?>>
                                Use part standard reorder qty
                            </label>
                            <div style="opacity:.75;font-size:12px;margin-top:4px;">
                                Std: <span id="kimrp_std_display_edit"><?= esc_html(self::fmt_qty($edit_std)) ?></span>
                            </div>
                        </div>

                        <div id="kimrp_manual_qty_wrap_edit" style="display:<?= $edit_use_std ? 'none' : 'block' ?>;">
                            <label style="display:block;">Override reorder qty</label>
                            <input name="reorder_qty" id="kimrp_reorder_qty_edit" value="<?= esc_attr($edit_manual_qty) ?>" placeholder="Qty" style="width:140px;">
                        </div>

                        <div>
                            <button class="kimrp2-btn" type="submit">Save</button>
                            <a class="kimrp2-btn kimrp2-btn-secondary" href="<?= esc_url(remove_query_arg('edit_kb')) ?>">Cancel</a>
                        </div>
                    </div>
                </form>

            <?php else: ?>
                <h3>Create Kanban Card</h3>
                <form method="post" id="kimrp_kanban_create">
                    <input type="hidden" name="_kimrp_action" value="create">

                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                        <div>
                            <label style="display:block;">Part</label>
                            <?= self::part_select($parts, 0, 'kimrp_part_id_create', 'part_id') ?>
                        </div>

                        <div>
                            <label style="display:block;">
                                <input type="checkbox" name="use_std_qty" id="kimrp_use_std_qty_create" value="1" checked>
                                Use part standard reorder qty
                            </label>
                            <div style="opacity:.75;font-size:12px;margin-top:4px;">
                                Std: <span id="kimrp_std_display_create">0</span>
                            </div>
                        </div>

                        <div id="kimrp_manual_qty_wrap_create" style="display:none;">
                            <label style="display:block;">Override reorder qty</label>
                            <input name="reorder_qty" id="kimrp_reorder_qty_create" placeholder="Qty" style="width:140px;">
                        </div>

                        <div>
                            <button class="kimrp2-btn" type="submit">Create Card</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <script>
            (function(){
                function stdFromSelected(selId){
                    var sel = document.getElementById(selId);
                    if(!sel) return 0;
                    var opt = sel.options[sel.selectedIndex];
                    var v = opt ? opt.getAttribute('data-std') : '0';
                    var n = parseFloat(v || '0');
                    return isNaN(n) ? 0 : n;
                }
                function fmt(n){
                    if (Math.abs(n - Math.round(n)) < 1e-6) return String(Math.round(n));
                    return (Math.round(n * 1000) / 1000).toString();
                }
                function sync(which){
                    var selId = which === 'edit' ? 'kimrp_part_id_edit' : 'kimrp_part_id_create';
                    var useId = which === 'edit' ? 'kimrp_use_std_qty_edit' : 'kimrp_use_std_qty_create';
                    var wrapId = which === 'edit' ? 'kimrp_manual_qty_wrap_edit' : 'kimrp_manual_qty_wrap_create';
                    var dispId = which === 'edit' ? 'kimrp_std_display_edit' : 'kimrp_std_display_create';
                    var inpId = which === 'edit' ? 'kimrp_reorder_qty_edit' : 'kimrp_reorder_qty_create';

                    var std = stdFromSelected(selId);
                    var useStd = document.getElementById(useId);
                    var wrap = document.getElementById(wrapId);
                    var disp = document.getElementById(dispId);
                    var inp = document.getElementById(inpId);

                    if(disp) disp.textContent = fmt(std);
                    if(useStd && wrap){
                        wrap.style.display = useStd.checked ? 'none' : 'block';
                        if(useStd.checked && inp) inp.value = '';
                    }
                }

                document.addEventListener('change', function(e){
                    if(!e.target) return;
                    if (e.target.id === 'kimrp_part_id_create' || e.target.id === 'kimrp_use_std_qty_create') sync('create');
                    if (e.target.id === 'kimrp_part_id_edit' || e.target.id === 'kimrp_use_std_qty_edit') sync('edit');
                });

                document.addEventListener('DOMContentLoaded', function(){
                    sync('create');
                    sync('edit');
                });
            })();
            </script>

            <table class="widefat striped" style="margin-top:14px;">
                <thead>
                    <tr>
                        <th>KB Code</th>
                        <th>Part ID</th>
                        <th>Reorder Qty</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $r):
                    $n1 = wp_create_nonce('kimrp_reorder_'.$r->id);
                    $url_reorder = add_query_arg(['kimrp_reorder'=>$r->id,'_kimrp_nonce'=>$n1], $base_url);

                    $n2 = wp_create_nonce('kimrp_reorder_open_'.$r->id);
                    $url_open = add_query_arg(['kimrp_reorder_open'=>$r->id,'_kimrp_nonce2'=>$n2], $base_url);

                    $url_edit = add_query_arg(['edit_kb'=>$r->id], $base_url);

                    $del_nonce = wp_create_nonce('kimrp_delete_kb_'.$r->id);
                    $url_del = add_query_arg(['delete_kb'=>$r->id,'_kbnonce'=>$del_nonce], $base_url);
                ?>
                    <tr>
                        <td><?= esc_html($r->kb_code ?: ('ID '.$r->id)) ?></td>
                        <td><?= esc_html($r->part_id) ?></td>
                        <td><?= esc_html(self::fmt_qty($r->reorder_qty)) ?></td>
                        <td>
                            <a class="kimrp2-btn kimrp2-btn-secondary" href="<?= esc_url($url_reorder) ?>">Reorder</a>
                            <a class="kimrp2-btn" href="<?= esc_url($url_open) ?>">Reorder &amp; Open Job</a>
                            <a class="kimrp2-btn kimrp2-btn-secondary" href="<?= esc_url($url_edit) ?>">Edit</a>
                            <a class="kimrp2-btn kimrp2-btn-secondary" href="<?= esc_url($url_del) ?>" onclick="return confirm('Delete this kanban card?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:8px;opacity:.8;">
                Kanban jobs auto-set due date to <b>today + 30 days</b>.
            </div>

            <p style="opacity:.75;margin-top:10px;">
                Shortcode: <code>[kimrp_kanban]</code>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
KIMRP2_Kanban::init();