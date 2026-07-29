<?php
defined('ABSPATH') || exit;

class LEM_Database {

    const DB_VERSION = '1.1.0';

    public function __construct() {
        // Не admin_init: плагин может обновиться автообновлением, и тогда
        // WP-Cron пойдёт за реестрами раньше, чем человек зайдёт в админку.
        // Импорт при этом писал бы в колонки, которых ещё нет
        add_action('init', [$this, 'check_version'], 5);
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
            first_seen DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_type_active (type, is_active),
            INDEX idx_first_seen (first_seen)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // У записей, заведённых до появления колонки, новичками считаться нечего:
        // проставляем дату включения в реестр, иначе всё разом станет «свежим»
        $wpdb->query("UPDATE $table SET first_seen = COALESCE(date_included, '2000-01-01')
                      WHERE first_seen IS NULL");

        update_option('lem_db_version', self::DB_VERSION);
    }

    const BANNED_SITES_DB_VERSION = '1.2.0';

    public function create_banned_sites_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'lem_banned_sites';
        $charset = $wpdb->get_charset_collate();

        // account: аккаунт организации на чужой площадке (t.me/doxajournal).
        // Пустая строка означает, что запрещён весь домен
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            domain VARCHAR(255) NOT NULL,
            account VARCHAR(190) NOT NULL DEFAULT '',
            label VARCHAR(500) DEFAULT '',
            registry VARCHAR(20) NOT NULL DEFAULT '',
            entity_id BIGINT UNSIGNED DEFAULT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_domain_account (domain, account),
            INDEX idx_entity (entity_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Старый ключ разрешал один домен на всю таблицу, а у t.me аккаунтов много.
        // dbDelta чужие индексы не трогает, поэтому убираем вручную
        $old = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'idx_domain'");
        if (!empty($old)) {
            $wpdb->query("ALTER TABLE $table DROP INDEX idx_domain");
        }

        update_option('lem_banned_sites_db_version', self::BANNED_SITES_DB_VERSION);
    }

    public function check_version() {
        if (get_option('lem_db_version') !== self::DB_VERSION) {
            $this->create_table();
        }
        if (get_option('lem_banned_sites_db_version') !== self::BANNED_SITES_DB_VERSION) {
            $this->create_banned_sites_table();
        }
    }

    public function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        return $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    }
}
