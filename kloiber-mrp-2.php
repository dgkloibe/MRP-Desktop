<?php
    /**
     * Plugin Name: Kloiber MRP 2
     * Description: Clean modular rebuild of internal MRP system.
     * Version: 2.1.0
     * Author: Kloiber Industrial
     */

    if (!defined('ABSPATH')) exit;

    define('KIMRP2_PATH', plugin_dir_path(__FILE__));
    define('KIMRP2_URL', plugin_dir_url(__FILE__));

    require_once KIMRP2_PATH . 'modules/core.php';
    require_once KIMRP2_PATH . 'modules/install.php';
    require_once KIMRP2_PATH . 'modules/parts.php';
    require_once KIMRP2_PATH . 'modules/customers.php';
    require_once KIMRP2_PATH . 'modules/jobs.php';
    require_once KIMRP2_PATH . 'modules/kanban.php';
    require_once KIMRP2_PATH . 'modules/inventory.php';
    require_once KIMRP2_PATH . 'modules/tags.php';
    // New dashboard modules
    require_once KIMRP2_PATH . 'modules/customer-dashboard.php';
    require_once KIMRP2_PATH . 'modules/supplier-dashboard.php';

    register_activation_hook(__FILE__, ['KIMRP2_Install', 'maybe_install']);
    KIMRP2_Core::init();
?>