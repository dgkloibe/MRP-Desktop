<?php
if (!defined('ABSPATH')) exit;

class KIMRP2_Core {

    public const CAP = 'manage_options';

    /**
     * REQUIRED: main plugin file calls KIMRP2_Core::init()
     * Keep safe/lightweight.
     */
    public static function init() {
        return true;
    }

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'kimrp2_' . $name;
    }

    public static function now() {
        return current_time('mysql');
    }

    public static function notice($msg) {
        return '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
    }

    // One-time CSS output for ALL shortcodes (scoped to .kimrp2-wrap only)
    public static function ui_css_once() {
        static $done = false;
        if ($done) return '';
        $done = true;

        return '
<style>
.kimrp2-wrap .kimrp2-btn,
.kimrp2-wrap button,
.kimrp2-wrap input[type="submit"],
.kimrp2-wrap input[type="button"]{
  background:#b22626 !important;
  color:#ffffff !important;
  border:1px solid #b22626 !important;
  padding:8px 14px !important;
  border-radius:6px !important;
  cursor:pointer !important;
  text-decoration:none !important;
  display:inline-block !important;
  line-height:1.2 !important;
  font-weight:600 !important;
}
.kimrp2-wrap .kimrp2-btn:hover,
.kimrp2-wrap button:hover,
.kimrp2-wrap input[type="submit"]:hover,
.kimrp2-wrap input[type="button"]:hover{
  background:#2C6BC3 !important;
  border-color:#2C6BC3 !important;
  color:#ffffff !important;
}
.kimrp2-wrap a.kimrp2-btn{ text-decoration:none !important; }
.kimrp2-wrap .kimrp2-btn.kimrp2-btn-secondary{
  background:#ffffff !important;
  color:#2b2b2b !important;
  border:1px solid #c9c9c9 !important;
}
.kimrp2-wrap .kimrp2-btn.kimrp2-btn-secondary:hover{
  background:#2C6BC3 !important;
  border-color:#2C6BC3 !important;
  color:#ffffff !important;
}
</style>';
    }
}