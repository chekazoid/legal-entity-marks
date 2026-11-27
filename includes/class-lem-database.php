<?php
defined('ABSPATH') || exit;

class CLEM_Database {

    const DB_VERSION = '1.3.0';

    public function __construct() {
        // Не admin_init: плагин может обновиться автообновлением, и тогда
        // WP-Cron пойдёт за реестрами раньше, чем человек зайдёт в админку.
        // Импорт при этом писал бы в колонки, которых ещё нет
        add_action('init', [$this, 'check_version'], 5);
    }

    public function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . CLEM_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL,
            name MEDIUMTEXT NOT NULL,
            aliases TEXT,
            is_person TINYINT(1) DEFAULT 0,
            status_text TEXT,
            source_url VARCHAR(500) DEFAULT '',
            date_included DATE DEFAULT NULL,
            date_excluded DATE DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            registry_present TINYINT(1) NOT NULL DEFAULT 1,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            first_seen DATETIME DEFAULT NULL,
            sync_generation VARCHAR(32) NOT NULL DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_type_active (type, is_active),
            INDEX idx_type_name (type, name(190)),
            INDEX idx_first_seen (first_seen)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // У записей, заведённых до появления колонки, новичками считаться нечего:
        // проставляем дату включения в реестр, иначе всё разом станет «свежим»
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
        $wpdb->query("UPDATE $table SET first_seen = COALESCE(date_included, '2000-01-01')
                      WHERE first_seen IS NULL") ;
                      /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

        update_option('clem_db_version', self::DB_VERSION);
    }

    const BANNED_SITES_DB_VERSION = '1.2.0';

    public function create_banned_sites_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'clem_banned_sites';
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
        /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $old = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'idx_domain'") ;
        /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        if (!empty($old)) {
            /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. Version-gated custom-table migration or explicitly requested uninstall cleanup. */
            $wpdb->query("ALTER TABLE $table DROP INDEX idx_domain") ;
            /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        }

        update_option('clem_banned_sites_db_version', self::BANNED_SITES_DB_VERSION);
    }

    public function check_version() {
        if (get_option('clem_db_version') !== self::DB_VERSION) {
            $this->create_table();
        }
        if (get_option('clem_banned_sites_db_version') !== self::BANNED_SITES_DB_VERSION) {
            $this->create_banned_sites_table();
        }
    }

    public function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . CLEM_TABLE;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
    }
}
