<?php
defined('ABSPATH') || exit;

/** Move data written by versions through 1.15.2, preserving registry IDs. */
class CLEM_Migration {
    const DONE = 'clem_storage_migrated';

    public static function run() {
        if (get_option(self::DONE)) {
            return true;
        }

        return true === CLEM_Registry_Sync::with_lock(static function () {
            if (get_option(self::DONE)) {
                return true;
            }
            if (!self::move_tables() || !self::move_post_meta() || !self::move_options()) {
                return false;
            }
            foreach (['lem_fetch_registries', 'lem_scan_updated', 'lem_import_people', 'lem_run_rescan'] as $hook) {
                wp_clear_scheduled_hook($hook);
            }
            return update_option(self::DONE, true, false);
        });
    }

    private static function move_tables() {
        global $wpdb;
        $moves = [];

        /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Inspect and atomically rename only the plugin tables. Identifiers consist of a validated WordPress prefix and allowlisted suffixes. */
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $wpdb->prefix)) {
            return false;
        }
        foreach (['lem_entities', 'lem_banned_sites'] as $suffix) {
            $old = $wpdb->prefix . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old)));
            if ($wpdb->last_error) {
                return false;
            }
            if ($found !== $old) {
                continue;
            }

            $new = $wpdb->prefix . 'c' . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new)));
            if ($wpdb->last_error || $found === $new) {
                return false;
            }
            $moves[] = "`$old` TO `$new`";
        }
        return !$moves || false !== $wpdb->query('RENAME TABLE ' . implode(', ', $moves));
        /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared */
    }

    private static function move_post_meta() {
        global $wpdb;

        foreach (['_lem_matches', '_lem_banned_links', '_lem_overrides'] as $old) {
            $new = '_c' . substr($old, 1);
            $after = 0;
            do {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read only the IDs needed for keyset pagination; the metadata API handles writes and invalidation.
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id LIMIT 500",
                    $old,
                    $after
                ));
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ($wpdb->last_error) {
                    return false;
                }
                foreach ($ids as $id) {
                    $after = (int) $id;
                    $meta = get_metadata_by_mid('post', $after);
                    if ($meta && $meta->meta_key === $old
                        && !update_metadata_by_mid('post', $after, $meta->meta_value, $new)) {
                        return false;
                    }
                }
            } while (count($ids) === 500);
        }

        return true;
    }

    private static function move_options() {
        $keys = [
            'settings', 'db_version', 'banned_sites_db_version', 'brand_rules',
            'bundled_data_version', 'installed_at', 'list_version', 'first_fetch_done',
            'last_fetch_time', 'last_fetch_sources',
            'last_update_report', 'update_notice_seen', 'rescan_state', 'cron_last_run',
            'install_id', 'registered_at', 'people_last_success',
        ];
        foreach (CLEM_Registry_Sync::TYPES as $type) {
            $keys[] = 'registry_snapshot_' . $type;
        }
        foreach ($keys as $key) {
            $old = 'lem_' . $key;
            $new = 'clem_' . $key;
            $value = get_option($old, null);
            if ($value === null) {
                continue;
            }
            if (get_option($new, null) === null && !add_option($new, $value, '', false)) {
                return false;
            }
            delete_option($old);
        }
        foreach ([
            'lem_people_import_state', 'lem_people_import_lock', 'lem_upgrade_version',
            'lem_brand_version', 'lem_last_fetch_diagnostics', 'lem_last_fetch_error', 'lem_channel_health',
        ] as $obsolete) {
            delete_option($obsolete);
        }
        return true;
    }
}
