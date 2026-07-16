<?php
defined('ABSPATH') || exit;

class LEM_Database {

    const DB_VERSION = '1.0.0';

    public function __construct() {
        add_action('admin_init', [$this, 'check_version']);
    }

    public function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . LEM_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL,
            name VARCHAR(500) NOT NULL,
            aliases TEXT,
            is_person TINYINT(1) DEFAULT 0,
            status_text TEXT,
            source_url VARCHAR(500) DEFAULT '',
            date_included DATE DEFAULT NULL,
            date_excluded DATE DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_type_active (type, is_active)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('lem_db_version', self::DB_VERSION);
    }

    public function create_banned_sites_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'lem_banned_sites';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            domain VARCHAR(255) NOT NULL,
            label VARCHAR(500) DEFAULT '',
            entity_id BIGINT UNSIGNED DEFAULT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_domain (domain),
            INDEX idx_entity (entity_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('lem_banned_sites_db_version', '1.0.0');
    }

    public function check_version() {
        if (get_option('lem_db_version') !== self::DB_VERSION) {
            $this->create_table();
        }
        if (get_option('lem_banned_sites_db_version') !== '1.0.0') {
            $this->create_banned_sites_table();
        }
    }

    public function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        return $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    }
}
