<?php
defined('ABSPATH') || exit;

/** Apply only complete, validated registry snapshots; keep vanished rows as history. */
class CLEM_Registry_Sync {
    const TYPES = ['inoagent', 'extremist', 'terrorist', 'undesirable'];
    const OPTION_PREFIX = 'clem_registry_snapshot_';
    private static $lock_depth = 0;

    private static function lock_name() {
        global $wpdb;
        return 'clem_sync_' . md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix);
    }

    /** A connection-owned lock cannot expire while a slow HTTP refresh is running. */
    public static function with_lock(callable $callback) {
        global $wpdb;
        $name = self::lock_name();
        if (self::$lock_depth > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned advisory locks are live state and must never be cached.
            $owned = (int) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name));
            if ($owned !== 1) {
                return ['error' => __('Блокировка обновления потеряна. Прежние данные сохранены.', 'chekazoid-legal-entity-marks'), 'busy' => true];
            }
            return $callback();
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned advisory locks are live state and must never be cached.
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== 1) {
            return ['error' => __('Обновление реестров уже выполняется. Прежние данные сохранены.', 'chekazoid-legal-entity-marks'), 'busy' => true];
        }
        self::$lock_depth++;
        try {
            return $callback();
        } finally {
            self::$lock_depth--;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned advisory locks are live state and must never be cached.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    public static function has_snapshot($type) {
        return (bool) get_option(self::OPTION_PREFIX . $type)
            || ($type === 'fedsfm_person' && (bool) get_option('clem_people_last_success'));
    }

    public static function date($value) {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value)) { return false; }
        $value = trim($value);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "$m[3]-$m[2]-$m[1]" : false;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : false;
        }
        return false;
    }

    /** Validate the entire payload before changing even one row. */
    public static function validate(array $entries, $type, array $meta) {
        if (!in_array($type, self::TYPES, true) || ($meta['complete'] ?? false) !== true
            || !is_int($meta['count'] ?? null) || $meta['count'] !== count($entries)
            || !$entries || count($entries) > 100000
            || !in_array($meta['source'] ?? '', ['official', 'channel'], true)
            || !is_string($meta['version'] ?? null) || $meta['version'] === ''
            || (isset($meta['source_modified']) && (!is_string($meta['source_modified'])
                || ($meta['source_modified'] !== '' && !strtotime($meta['source_modified']))))
            || !is_string($meta['fetched_at'] ?? null) || !strtotime($meta['fetched_at'])
            || strtotime($meta['fetched_at']) > time() + DAY_IN_SECONDS) {
            return ['error' => __('Полнота или версия списка не подтверждена. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
        }
        $unique = [];
        $skipped = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)
                || (($entry['type'] ?? $type) !== $type)
                || (isset($entry['aliases']) && (!is_array($entry['aliases'])
                    || count(array_filter($entry['aliases'], 'is_string')) !== count($entry['aliases'])))) {
                return ['error' => __('В списке есть повреждённая запись. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
            }
            $name = trim($entry['name']);
            if (mb_strlen($name) < 3 || mb_strlen($name) > 16384) {
                return ['error' => __('В списке есть неподходящее наименование. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
            }
            $date_in = self::date($entry['date_included'] ?? $entry['dateIn'] ?? null);
            $date_out = self::date($entry['date_excluded'] ?? $entry['dateOut'] ?? null);
            if ($date_in === false || $date_out === false) {
                return ['error' => __('В списке есть неподходящая дата. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
            }
            if (CLEM_Importer::is_junk_name($name)) { $skipped++; continue; }
            $entry['name'] = $name;
            $entry['type'] = $type;
            $entry['date_included'] = $date_in;
            $entry['date_excluded'] = $date_out;
            if (isset($entry['is_active']) && !in_array($entry['is_active'], [0, 1, false, true], true)) {
                return ['error' => __('В списке есть неподходящий статус. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
            }
            $entry['is_active'] = $date_out === null && (!isset($entry['is_active']) || (bool) $entry['is_active']) ? 1 : 0;
            // Multiple rows with the same displayed name are one name match:
            // it remains current if at least one source row is current.
            if (isset($unique[$name])) {
                $old = $unique[$name];
                $entry['aliases'] = array_values(array_unique(array_merge($old['aliases'] ?? [], $entry['aliases'] ?? [])));
                if ($old['is_active']) { $entry['date_excluded'] = null; $entry['is_active'] = 1; }
            }
            $unique[$name] = $entry;
        }
        if (!$unique) {
            return ['error' => __('Полный список не содержит распознанных записей. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
        }
        ksort($unique, SORT_STRING);
        $identity = [];
        foreach ($unique as $name => $entry) { $identity[] = [$name, $entry['date_excluded'], $entry['is_active']]; }
        return ['entries' => array_values($unique), 'skipped' => $skipped,
            'fingerprint' => hash('sha256', wp_json_encode($identity, JSON_UNESCAPED_UNICODE))];
    }

    private static function option_cache_clear($option) {
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    public static function apply($importer, array $entries, $type, array $meta) {
        return self::with_lock(static function () use ($importer, $entries, $type, $meta) {
            global $wpdb;
            $checked = self::validate($entries, $type, $meta);
            if (isset($checked['error'])) { return $checked; }
            $option = self::OPTION_PREFIX . $type;
            $previous = get_option($option, []);
            $revision = strtotime($meta['source_modified'] ?? '') ?: 0;
            $old_revision = strtotime($previous['source_modified'] ?? '') ?: 0;
            $older_revision = $revision && $old_revision && $revision < $old_revision;
            $older_fetch = (!$revision || !$old_revision)
                && strtotime($meta['fetched_at']) < (strtotime($previous['fetched_at'] ?? '') ?: 0);
            if ($older_revision || $older_fetch) {
                if (($previous['fingerprint'] ?? '') === $checked['fingerprint']) {
                    return ['added'=>0, 'updated'=>0, 'skipped'=>$checked['skipped'], 'domains'=>0,
                        'total'=>count($entries), 'removed'=>0, 'same_snapshot'=>true];
                }
                return ['error' => __('Источник вернул более старый срез. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
            }
            $table = $wpdb->prefix . CLEM_TABLE;
            foreach ([$table, $wpdb->prefix . 'clem_banned_sites', $wpdb->options] as $target) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Check the live storage engine before opening a transaction; cached metadata could miss a host migration.
                $engine = $wpdb->get_var($wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $target
                ));
                if (strtolower((string) $engine) !== 'innodb') {
                    return ['error' => __('Для безопасной сверки таблицы плагина и настроек должны использовать InnoDB. Данные сохранены.', 'chekazoid-legal-entity-marks')];
                }
            }
            $generation = wp_generate_password(24, false, false);
            $quiet = $wpdb->suppress_errors(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control must run on the same live connection as the snapshot writes.
            if ($wpdb->query('START TRANSACTION') === false) {
                $wpdb->suppress_errors($quiet);
                return ['error' => __('Не удалось начать безопасную сверку. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
            }
            try {
                $result = $importer->import_verified_entries($checked['entries'], $type, $generation);
                if (isset($result['error'])) { throw new RuntimeException($result['error']); }
                /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom-table name is the WordPress prefix plus a plugin constant; values are prepared. Read and reconcile the uncommitted generation before publishing it, then invalidate caches. */
                $present = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE type = %s AND sync_generation = %s",
                    $type, $generation
                ));
                if ($present !== count($checked['entries'])) { throw new RuntimeException('snapshot verification failed'); }
                $removed = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET is_active = 0, registry_present = 0
                     WHERE type = %s AND is_manual = 0 AND sync_generation <> %s AND registry_present = 1",
                    $type, $generation
                ));
                /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
                if ($removed === false) { throw new RuntimeException('snapshot reconciliation failed'); }
                $state = ['generation'=>$generation, 'source'=>$meta['source'], 'version'=>$meta['version'],
                    'fetched_at'=>$meta['fetched_at'], 'source_modified'=>$meta['source_modified'] ?? '',
                    'verified_at'=>current_time('mysql', true), 'fingerprint'=>$checked['fingerprint'],
                    'received'=>count($entries), 'accepted'=>count($checked['entries']), 'removed'=>(int)$removed];
                if (!update_option($option, $state, false)) { throw new RuntimeException('snapshot state write failed'); }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control must run on the same live connection as the snapshot writes.
                if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('snapshot commit failed'); }
                $result['removed'] = (int) $removed;
                $result['skipped'] += $checked['skipped'];
                return $result;
            } catch (Throwable $error) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control must run on the same live connection as the snapshot writes.
                $wpdb->query('ROLLBACK');
                self::option_cache_clear($option);
                return ['error' => __('Не удалось сохранить полный срез. Прежние данные сохранены.', 'chekazoid-legal-entity-marks')];
            } finally {
                $wpdb->suppress_errors($quiet);
                clem_plugin()->entities->flush_cache();
                if (isset(clem_plugin()->banned_sites)) { clem_plugin()->banned_sites->flush_cache(); }
            }
        });
    }
}
