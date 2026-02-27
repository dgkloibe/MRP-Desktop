<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Install {

    public static function maybe_install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        // Parts
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('parts') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            part_number VARCHAR(100),
            description TEXT,
            uom VARCHAR(20) DEFAULT 'pcs',
            standard_reorder_qty FLOAT DEFAULT 0,
            created_at DATETIME
        ) $charset;");

        // Customers (extended columns)
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('customers') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200),
            email VARCHAR(200) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            notes TEXT NULL,
            created_at DATETIME
        ) $charset;");

        // Jobs (add next_operation, notes)
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('jobs') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_code VARCHAR(30),
            customer_id BIGINT UNSIGNED NULL,
            part_id BIGINT UNSIGNED,
            qty FLOAT,
            status VARCHAR(50),
            due_date DATE NULL,
            next_operation VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at DATETIME,
            UNIQUE KEY job_code_unique (job_code)
        ) $charset;");

        // Kanban
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('kanban') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kb_code VARCHAR(30),
            part_id BIGINT UNSIGNED,
            reorder_qty FLOAT,
            created_at DATETIME,
            UNIQUE KEY kb_code_unique (kb_code)
        ) $charset;");

        // Inventory
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('inventory') . " (
            part_id BIGINT UNSIGNED PRIMARY KEY,
            qty FLOAT
        ) $charset;");

        // Inventory moves
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('inventory_moves') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            part_id BIGINT UNSIGNED,
            delta FLOAT,
            qty_before FLOAT,
            qty_after FLOAT,
            note VARCHAR(255),
            created_at DATETIME
        ) $charset;");

        // Counters
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('counters') . " (
            name VARCHAR(50) PRIMARY KEY,
            next_val BIGINT UNSIGNED
        ) $charset;");

        // Tags
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('tags') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            created_at DATETIME,
            UNIQUE KEY tag_name_unique (name)
        ) $charset;");

        // Entity tags
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('entity_tags') . " (
            entity_type VARCHAR(20) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME,
            PRIMARY KEY (entity_type, entity_id, tag_id),
            KEY et_entity (entity_type, entity_id),
            KEY et_tag (tag_id)
        ) $charset;");

        // Orders table
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('orders') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NULL,
            part_id BIGINT UNSIGNED,
            qty FLOAT,
            status VARCHAR(50) DEFAULT 'NEW',
            created_at DATETIME
        ) $charset;");

        // Quotes table
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('quotes') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NULL,
            part_id BIGINT UNSIGNED,
            qty FLOAT,
            status VARCHAR(50) DEFAULT 'PENDING',
            created_at DATETIME
        ) $charset;");

        // Insert default counters (idempotent)
        $ct = KIMRP2_Core::table('counters');
        $wpdb->query("INSERT IGNORE INTO $ct (name, next_val) VALUES
            ('job',1),('kanban',1),('order',1),('quote',1)");

        // Seed sample customers
        $customers_table = KIMRP2_Core::table('customers');
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $customers_table") === 0) {
            $wpdb->insert($customers_table, [
                'name'=>'Acme Corporation','email'=>'info@acme.com','phone'=>'123-456-7890',
                'notes'=>'Preferred customer','created_at'=>KIMRP2_Core::now()
            ]);
            $wpdb->insert($customers_table, [
                'name'=>'Beta Manufacturing','email'=>'contact@beta.com','phone'=>'555-0100',
                'notes'=>'','created_at'=>KIMRP2_Core::now()
            ]);
            $wpdb->insert($customers_table, [
                'name'=>'Gamma Industries','email'=>'sales@gamma.com','phone'=>'555-0200',
                'notes'=>'','created_at'=>KIMRP2_Core::now()
            ]);
        }

        // Seed sample parts
        $parts_table = KIMRP2_Core::table('parts');
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $parts_table") === 0) {
            $seed_parts = [
                ['part_number'=>'P-1001','description'=>'Widget A','uom'=>'pcs','standard_reorder_qty'=>10],
                ['part_number'=>'P-1002','description'=>'Widget B','uom'=>'pcs','standard_reorder_qty'=>5],
                ['part_number'=>'P-1003','description'=>'Widget C','uom'=>'pcs','standard_reorder_qty'=>20],
                ['part_number'=>'P-1004','description'=>'Gadget X','uom'=>'pcs','standard_reorder_qty'=>8],
                ['part_number'=>'P-1005','description'=>'Gadget Y','uom'=>'pcs','standard_reorder_qty'=>15],
                ['part_number'=>'P-1006','description'=>'Assembly Z','uom'=>'pcs','standard_reorder_qty'=>12],
                ['part_number'=>'P-1007','description'=>'Component Q','uom'=>'pcs','standard_reorder_qty'=>7],
                ['part_number'=>'P-1008','description'=>'Component R','uom'=>'pcs','standard_reorder_qty'=>9],
            ];
            foreach ($seed_parts as $p) {
                $p['created_at'] = KIMRP2_Core::now();
                $wpdb->insert($parts_table, $p);
            }
        }

        // Seed sample jobs
        $jobs_table = KIMRP2_Core::table('jobs');
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs_table") === 0) {
            $customer_ids = $wpdb->get_col("SELECT id FROM $customers_table");
            $part_ids = $wpdb->get_col("SELECT id FROM $parts_table");
            $statuses = ['Open','In Progress','Hold','Late','Done'];
            for ($i=0; $i<12; $i++) {
                $cid = $customer_ids[$i % count($customer_ids)];
                $pid = $part_ids[$i % count($part_ids)];
                $status = $statuses[$i % count($statuses)];
                $due = date('Y-m-d', strtotime('+' . (10 + $i) . ' days'));
                $wpdb->insert($jobs_table, [
                    'job_code' => 'J-' . str_pad((string)($i+1), 6, '0', STR_PAD_LEFT),
                    'customer_id'=>$cid,
                    'part_id'=>$pid,
                    'qty'=>rand(5,20),
                    'status'=>$status,
                    'due_date'=>$due,
                    'next_operation'=>'',
                    'notes'=>'',
                    'created_at'=>KIMRP2_Core::now()
                ]);
            }
        }

        // Seed sample orders
        $orders_table = KIMRP2_Core::table('orders');
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $orders_table") === 0) {
            $customer_ids = $wpdb->get_col("SELECT id FROM $customers_table");
            $part_ids = $wpdb->get_col("SELECT id FROM $parts_table");
            $order_statuses = ['NEW','IN PRODUCTION','COMPLETED','IN PROCESS'];
            for ($i=0; $i<10; $i++) {
                $cid = $customer_ids[$i % count($customer_ids)];
                $pid = $part_ids[$i % count($part_ids)];
                $status = $order_statuses[$i % count($order_statuses)];
                $wpdb->insert($orders_table, [
                    'customer_id'=>$cid,
                    'part_id'=>$pid,
                    'qty'=>rand(1,25),
                    'status'=>$status,
                    'created_at'=>KIMRP2_Core::now()
                ]);
            }
        }

        // Seed sample quotes
        $quotes_table = KIMRP2_Core::table('quotes');
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $quotes_table") === 0) {
            $customer_ids = $wpdb->get_col("SELECT id FROM $customers_table");
            $part_ids = $wpdb->get_col("SELECT id FROM $parts_table");
            for ($i=0; $i<5; $i++) {
                $cid = $customer_ids[$i % count($customer_ids)];
                $pid = $part_ids[$i % count($part_ids)];
                $wpdb->insert($quotes_table, [
                    'customer_id'=>$cid,
                    'part_id'=>$pid,
                    'qty'=>rand(1,15),
                    'status'=>'PENDING',
                    'created_at'=>KIMRP2_Core::now()
                ]);
            }
        }
    }
}
?>