<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Jobs {

    public static function init() {
        add_shortcode('kimrp_jobs', [__CLASS__, 'render']);
    }

    private static function get_all_tags() {
        global $wpdb;
        $t = KIMRP2_Core::table('tags');
        return $wpdb->get_results("SELECT id, name FROM $t ORDER BY name ASC");
    }

    private static function get_entity_tag_ids($entity_type, $entity_id) {
        global $wpdb;
        $et = KIMRP2_Core::table('entity_tags');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT tag_id FROM $et WHERE entity_type=%s AND entity_id=%d",
            $entity_type, $entity_id
        ));
        return array_map('intval', $ids ?: []);
    }

    private static function set_entity_tags($entity_type, $entity_id, $tag_ids) {
        global $wpdb;
        $et = KIMRP2_Core::table('entity_tags');

        $tag_ids = array_values(array_unique(array_map('intval', $tag_ids ?: [])));

        $wpdb->delete($et, ['entity_type'=>$entity_type, 'entity_id'=>$entity_id]);

        foreach ($tag_ids as $tid) {
            if ($tid <= 0) continue;
            $wpdb->insert($et, [
                'entity_type' => $entity_type,
                'entity_id' => (int)$entity_id,
                'tag_id' => (int)$tid,
                'created_at' => KIMRP2_Core::now()
            ]);
        }
    }

    private static function tags_picker($all_tags, $selected_ids, $prefix_id) {
        $selected_ids = array_map('intval', $selected_ids ?: []);
        ob_start(); ?>
        <div style="min-width:260px;max-width:420px;">
            <label style="display:block;">Tags</label>
            <input type="text" placeholder="Filter tags..." id="<?= esc_attr($prefix_id) ?>_filter" style="width:260px;max-width:100%;margin-bottom:6px;">
            <div id="<?= esc_attr($prefix_id) ?>_list" style="border:1px solid #ddd;padding:8px;border-radius:6px;max-height:180px;overflow:auto;background:#fff;">
                <?php if (empty($all_tags)): ?>
                    <div style="opacity:.7;">No tags yet. Create some on the Tags page.</div>
                <?php else: ?>
                    <?php foreach ($all_tags as $t):
                        $chk = in_array((int)$t->id, $selected_ids, true) ? 'checked' : '';
                    ?>
                        <label class="kimrp_tag_item" style="display:block;margin:2px 0;">
                            <input type="checkbox" name="tag_ids[]" value="<?= (int)$t->id ?>" <?= $chk ?>>
                            <?= esc_html($t->name) ?>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function(){
          var f = document.getElementById(<?= json_encode($prefix_id.'_filter') ?>);
          var list = document.getElementById(<?= json_encode($prefix_id.'_list') ?>);
          if(!f || !list) return;
          f.addEventListener('input', function(){
            var q = (f.value || '').toLowerCase().trim();
            var items = list.querySelectorAll('.kimrp_tag_item');
            items.forEach(function(it){
              var txt = (it.textContent || '').toLowerCase();
              it.style.display = (!q || txt.indexOf(q) !== -1) ? 'block' : 'none';
            });
          });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private static function part_select($selected = 0) {
        global $wpdb;
        $parts_table = KIMRP2_Core::table('parts');
        $parts = $wpdb->get_results("SELECT id, part_number FROM $parts_table ORDER BY part_number ASC");

        $html = '<select class="kimrp2-searchable" data-placeholder="Search part..." name="part_id" required>';
        $html .= '<option value="">Select Part</option>';
        foreach ($parts as $p) {
            $sel = ((int)$selected === (int)$p->id) ? 'selected' : '';
            $html .= '<option value="'.esc_attr($p->id).'" '.$sel.'>'.esc_html($p->part_number).'</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function customer_select($selected = 0) {
        global $wpdb;
        $ct = KIMRP2_Core::table('customers');
        $rows = $wpdb->get_results("SELECT id, name FROM $ct ORDER BY name ASC");

        $html = '<select class="kimrp2-searchable" data-placeholder="Search customer..." name="customer_id">';
        $html .= '<option value="">(No customer)</option>';
        foreach ($rows as $c) {
            $sel = ((int)$selected === (int)$c->id) ? 'selected' : '';
            $html .= '<option value="'.esc_attr($c->id).'" '.$sel.'>'.esc_html($c->name).'</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function filter_customer_select($selected = '') {
        global $wpdb;
        $ct = KIMRP2_Core::table('customers');
        $rows = $wpdb->get_results("SELECT id, name FROM $ct ORDER BY name ASC");

        $html = '<select class="kimrp2-searchable" data-placeholder="Filter customer..." name="f_customer_id">';
        $html .= '<option value="">All customers</option>';
        foreach ($rows as $c) {
            $sel = ((string)$selected === (string)$c->id) ? 'selected' : '';
            $html .= '<option value="'.esc_attr($c->id).'" '.$sel.'>'.esc_html($c->name).'</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function filter_part_select($selected = '') {
        global $wpdb;
        $parts_table = KIMRP2_Core::table('parts');
        $parts = $wpdb->get_results("SELECT id, part_number FROM $parts_table ORDER BY part_number ASC");

        $html = '<select class="kimrp2-searchable" data-placeholder="Filter part..." name="f_part_id">';
        $html .= '<option value="">All parts</option>';
        foreach ($parts as $p) {
            $sel = ((string)$selected === (string)$p->id) ? 'selected' : '';
            $html .= '<option value="'.esc_attr($p->id).'" '.$sel.'>'.esc_html($p->part_number).'</option>';
        }
        $html .= '</select>';
        return $html;
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

    public static function render() {
        if (!current_user_can(KIMRP2_Core::CAP)) return '';

        global $wpdb;
        $t = KIMRP2_Core::table('jobs');
        $parts = KIMRP2_Core::table('parts');
        $cust = KIMRP2_Core::table('customers');

        $all_tags = self::get_all_tags();

        $notice = '';
        $close_after_save = false;

        // POST create/update
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = sanitize_text_field($_POST['_kimrp_action'] ?? '');
            $id = (int)($_POST['id'] ?? 0);

            $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
            $part_id = (int)($_POST['part_id'] ?? 0);
            $qty = (float)($_POST['qty'] ?? 0);
            $status = sanitize_text_field($_POST['status'] ?? 'Open');
            $due = !empty($_POST['due_date']) ? sanitize_text_field($_POST['due_date']) : null;

            $tag_ids = isset($_POST['tag_ids']) ? (array)$_POST['tag_ids'] : [];
            $tag_ids = array_map('intval', $tag_ids);

            if ($action === 'create') {
                $job_code = self::next_code('job', 'J-', 6);
                $wpdb->insert($t, [
                    'job_code' => $job_code,
                    'customer_id' => $customer_id,
                    'part_id' => $part_id,
                    'qty' => $qty,
                    'status' => $status,
                    'due_date' => $due,
                    'created_at' => KIMRP2_Core::now()
                ]);
                $new_id = (int)$wpdb->insert_id;
                self::set_entity_tags('job', $new_id, $tag_ids);
                $notice = "Job created ($job_code).";
            }

            if ($action === 'update' && $id > 0) {
                $wpdb->update($t, [
                    'customer_id' => $customer_id,
                    'part_id' => $part_id,
                    'qty' => $qty,
                    'status' => $status,
                    'due_date' => $due,
                ], ['id' => $id]);

                self::set_entity_tags('job', $id, $tag_ids);

                $notice = "Job updated.";
                $close_after_save = true;
            }
        }

        // open_job forces edit mode (only on GET)
        if (!$close_after_save && isset($_GET['open_job']) && (int)$_GET['open_job'] > 0) {
            $_GET['edit_job'] = (int)$_GET['open_job'];
        }

        $edit_id = (!$close_after_save && isset($_GET['edit_job'])) ? (int)$_GET['edit_job'] : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $edit_id)) : null;
        $edit_tag_ids = $edit ? self::get_entity_tag_ids('job', (int)$edit->id) : [];

        // Filters (GET)
        $f_status = sanitize_text_field($_GET['f_status'] ?? '');
        $f_customer_id = sanitize_text_field($_GET['f_customer_id'] ?? '');
        $f_part_id = sanitize_text_field($_GET['f_part_id'] ?? '');
        $f_due_from = sanitize_text_field($_GET['f_due_from'] ?? '');
        $f_due_to = sanitize_text_field($_GET['f_due_to'] ?? '');

        $where = [];
        $params = [];

        if ($f_status !== '') { $where[] = "j.status = %s"; $params[] = $f_status; }
        if ($f_customer_id !== '') { $where[] = "j.customer_id = %d"; $params[] = (int)$f_customer_id; }
        if ($f_part_id !== '') { $where[] = "j.part_id = %d"; $params[] = (int)$f_part_id; }
        if ($f_due_from !== '') { $where[] = "j.due_date >= %s"; $params[] = $f_due_from; }
        if ($f_due_to !== '') { $where[] = "j.due_date <= %s"; $params[] = $f_due_to; }

        $sql_where = $where ? ("WHERE " . implode(" AND ", $where)) : "";

        $base_sql = "
            SELECT j.*, p.part_number, c.name AS customer_name
            FROM $t j
            LEFT JOIN $parts p ON j.part_id = p.id
            LEFT JOIN $cust c ON j.customer_id = c.id
            $sql_where
            ORDER BY j.id DESC
        ";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($base_sql, $params)) : $wpdb->get_results($base_sql);

        // tag display map
        $tag_map = [];
        if (!empty($rows)) {
            $ids = array_filter(array_map(fn($r)=> (int)$r->id, $rows));
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                $et = KIMRP2_Core::table('entity_tags');
                $tg = KIMRP2_Core::table('tags');
                $pairs = $wpdb->get_results("
                    SELECT et.entity_id, t.name
                    FROM $et et
                    LEFT JOIN $tg t ON t.id = et.tag_id
                    WHERE et.entity_type='job' AND et.entity_id IN ($in)
                    ORDER BY t.name ASC
                ");
                foreach ($pairs as $p2) {
                    $jid = (int)$p2->entity_id;
                    if (!isset($tag_map[$jid])) $tag_map[$jid] = [];
                    if (!empty($p2->name)) $tag_map[$jid][] = $p2->name;
                }
            }
        }

        ob_start();
        echo KIMRP2_Core::ui_css_once();
        ?>
        <div class="kimrp2-wrap">
            <h2>Jobs</h2>

            <?php if ($notice) echo KIMRP2_Core::notice($notice); ?>

            <?php if ($close_after_save): ?>
                <script>
                  (function(){
                    try{
                      var url = new URL(window.location.href);
                      url.searchParams.delete('edit_job');
                      url.searchParams.delete('open_job');
                      window.history.replaceState({}, '', url.toString());
                    }catch(e){}
                  })();
                </script>
            <?php endif; ?>

            <h3>Filters</h3>
            <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                <div>
                    <label style="display:block;">Status</label>
                    <select name="f_status">
                        <option value="">All</option>
                        <?php foreach (['Open','In Progress','Done','Hold'] as $s): ?>
                            <option value="<?= esc_attr($s) ?>" <?= ($f_status === $s) ? 'selected' : '' ?>><?= esc_html($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display:block;">Customer</label>
                    <?= self::filter_customer_select($f_customer_id) ?>
                </div>

                <div>
                    <label style="display:block;">Part</label>
                    <?= self::filter_part_select($f_part_id) ?>
                </div>

                <div>
                    <label style="display:block;">Due from</label>
                    <input type="date" name="f_due_from" value="<?= esc_attr($f_due_from) ?>">
                </div>

                <div>
                    <label style="display:block;">Due to</label>
                    <input type="date" name="f_due_to" value="<?= esc_attr($f_due_to) ?>">
                </div>

                <div>
                    <button class="kimrp2-btn" type="submit">Apply</button>
                    <a class="kimrp2-btn kimrp2-btn-secondary" href="<?= esc_url(remove_query_arg(['f_status','f_customer_id','f_part_id','f_due_from','f_due_to'])) ?>">Clear</a>
                </div>
            </form>

            <?php if ($edit): ?>
                <h3 style="margin-top:18px;">Edit Job <?= esc_html($edit->job_code ?: ('ID '.$edit->id)) ?></h3>
                <form method="post" action="<?= esc_url($_SERVER['REQUEST_URI']) ?>">
                    <input type="hidden" name="_kimrp_action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$edit->id ?>">

                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                        <div>
                            <label style="display:block;">Customer</label>
                            <?= self::customer_select((int)$edit->customer_id) ?>
                        </div>
                        <div>
                            <label style="display:block;">Part</label>
                            <?= self::part_select((int)$edit->part_id) ?>
                        </div>
                        <div>
                            <label style="display:block;">Qty</label>
                            <input name="qty" value="<?= esc_attr($edit->qty) ?>" required style="width:120px;">
                        </div>
                        <div>
                            <label style="display:block;">Status</label>
                            <select name="status">
                                <?php foreach (['Open','In Progress','Done','Hold'] as $s): ?>
                                    <option value="<?= $s ?>" <?= ($edit->status === $s)?'selected':'' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;">Due</label>
                            <input name="due_date" type="date" value="<?= esc_attr($edit->due_date) ?>">
                        </div>

                        <?= self::tags_picker($all_tags, $edit_tag_ids, 'kimrp_job_edit_tags') ?>

                        <div>
                            <button class="kimrp2-btn" type="submit">Save</button>
                            <a class="kimrp2-btn kimrp2-btn-secondary" href="<?= esc_url(remove_query_arg(['edit_job','open_job'])) ?>">Close</a>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <h3 style="margin-top:18px;">Create Job</h3>
                <form method="post" action="<?= esc_url($_SERVER['REQUEST_URI']) ?>">
                    <input type="hidden" name="_kimrp_action" value="create">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                        <div>
                            <label style="display:block;">Customer</label>
                            <?= self::customer_select() ?>
                        </div>
                        <div>
                            <label style="display:block;">Part</label>
                            <?= self::part_select() ?>
                        </div>
                        <div>
                            <label style="display:block;">Qty</label>
                            <input name="qty" placeholder="Qty" required style="width:120px;">
                        </div>
                        <div>
                            <label style="display:block;">Due</label>
                            <input name="due_date" type="date">
                        </div>

                        <?= self::tags_picker($all_tags, [], 'kimrp_job_create_tags') ?>

                        <div>
                            <input type="hidden" name="status" value="Open">
                            <button class="kimrp2-btn" type="submit">Create Job</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <table class="widefat striped" style="margin-top:14px;">
                <thead>
                    <tr>
                        <th>Job #</th>
                        <th>Customer</th>
                        <th>Part</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Due</th>
                        <th>Tags</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?= esc_html($r->job_code ?: ('ID '.$r->id)) ?></td>
                        <td><?= esc_html($r->customer_name ?: '') ?></td>
                        <td><?= esc_html($r->part_number) ?></td>
                        <td><?= esc_html($r->qty) ?></td>
                        <td><?= esc_html($r->status) ?></td>
                        <td><?= esc_html($r->due_date) ?></td>
                        <td><?= esc_html(isset($tag_map[(int)$r->id]) ? implode(', ', $tag_map[(int)$r->id]) : '') ?></td>
                        <td><a href="<?= esc_url(add_query_arg('edit_job',$r->id)) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="opacity:.75;margin-top:10px;">
                Shortcode: <code>[kimrp_jobs]</code>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
KIMRP2_Jobs::init();