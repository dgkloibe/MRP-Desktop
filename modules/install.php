<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Install {

    public static function maybe_install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . KIMRP2_Core::table('parts') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            part_number VARCHAR(100),
            description TEXT,
            uom VARCHAR(20) DEFAULT 'pcs',
            standard_reorder_qty FLOAT DEFAULT 0,
            created_at DATETIME
        ) $charset;");

        dbDelta("CREATE TABLE " . KIMRP2_Core::table('customers') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200),
            created_at DATETIME
        ) $charset;");

        dbDelta("CREATE TABLE " . KIMRP2_Core::table('jobs') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_code VARCHAR(30),
            customer_id BIGINT UNSIGNED NULL,
            part_id BIGINT UNSIGNED,
            qty FLOAT,
            status VARCHAR(50),
            due_date DATE NULL,
            created_at DATETIME,
            UNIQUE KEY job_code_unique (job_code)
        ) $charset;");

        dbDelta("CREATE TABLE " . KIMRP2_Core::table('kanban') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kb_code VARCHAR(30),
            part_id BIGINT UNSIGNED,
            reorder_qty FLOAT,
            created_at DATETIME,
            UNIQUE KEY kb_code_unique (kb_code)
        ) $charset;");

        dbDelta("CREATE TABLE " . KIMRP2_Core::table('inventory') . " (
            part_id BIGINT UNSIGNED PRIMARY KEY,
            qty FLOAT
        ) $charset;");

        dbDelta("CREATE TABLE " . KIMRP2_Core::table('inventory_moves') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            part_id BIGINT UNSIGNED,
            delta FLOAT,
            qty_before FLOAT,
            qty_after FLOAT,
            note VARCHAR(255),
            created_at DATETIME
        ) $charset;");

        dbDelta("CREATE TABLE " . KIMRP2_Core::table('counters') . " (
            name VARCHAR(50) PRIMARY KEY,
            next_val BIGINT UNSIGNED
        ) $charset;");

        // Tags library
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('tags') . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            created_at DATETIME,
            UNIQUE KEY tag_name_unique (name)
        ) $charset;");

        // Entity-to-tags mapping (jobs for now)
        dbDelta("CREATE TABLE " . KIMRP2_Core::table('entity_tags') . " (
            entity_type VARCHAR(20) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME,
            PRIMARY KEY (entity_type, entity_id, tag_id),
            KEY et_entity (entity_type, entity_id),
            KEY et_tag (tag_id)
        ) $charset;");

        $ct = KIMRP2_Core::table('counters');
        $wpdb->query("INSERT IGNORE INTO $ct (name, next_val) VALUES ('job', 1), ('kanban', 1)");
    }
}