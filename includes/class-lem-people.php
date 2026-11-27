<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-lem-registry-sync.php';

/** Добровольная загрузка ФИО порциями, без комплектной копии перечня. */
class CLEM_People {
    const TYPE = 'fedsfm_person';
    const HOOK = 'clem_import_people';
    const STATE = 'clem_people_import_state';
    const SUCCESS = 'clem_people_last_success';
    const LOCK = 'clem_people_import_lock';

    public function __construct() {
        add_action(self::HOOK, [$this, 'run_import']);
    }

    public static function enabled($settings) {
        return in_array($settings['fedsfm_people_mode'] ?? 'off', ['track', 'mark'], true);
    }

    public function cancel() {
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::STATE);
        clem_plugin()->entities->flush_cache();
    }

    private function schedule($delay = 30) {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    public function queue($restart = false) {
        if (!self::enabled(clem_plugin()->get_settings())) {
            return;
        }
        $state = get_option(self::STATE, []);
        if (!$restart && !empty($state['running'])) {
            $this->schedule();
            return;
        }
        $started = current_time('mysql');
        $state = [
            'generation' => wp_generate_password(24, false, false),
            'started_at' => $started, 'offset' => 0, 'total' => 0,
            'version' => '', 'running' => true, 'error' => '', 'retries' => 0, 'restarts' => 0,
        ];
        if (!clem_plugin()->channel->is_enabled() || !clem_plugin()->channel->has_token()) {
            $state['running'] = false;
            $state['error'] = __('Для загрузки физических лиц включите канал и укажите его токен.', 'chekazoid-legal-entity-marks');
        }
        update_option(self::STATE, $state, false);
        if ($state['running']) {
            $this->schedule(1);
        }
    }

    private function fail($state, $message) {
        $state['running'] = false;
        $state['error'] = $message;
        update_option(self::STATE, $state, false);
    }

    public function run_import() {
        $result = CLEM_Registry_Sync::with_lock(function () { $this->run_import_locked(); return []; });
        if (!empty($result['busy'])) { $this->schedule(); }
    }

    private function run_import_locked() {
        if (!self::enabled(clem_plugin()->get_settings())) {
            $this->cancel();
            return;
        }
        $state = get_option(self::STATE, []);
        if (empty($state['running'])) {
            return;
        }
        $lock = get_option(self::LOCK, []);
        if (!empty($lock['until']) && $lock['until'] < time()) {
            delete_option(self::LOCK);
        }
        $lock = ['token' => wp_generate_password(24, false, false), 'until' => time() + 180];
        if (!add_option(self::LOCK, $lock, '', false)) {
            $this->schedule();
            return;
        }
        try {
            $page = clem_plugin()->channel->fetch_people_page($state['offset'], $state['version']);
            $fresh = get_option(self::STATE, []);
            // Пользователь мог выключить функцию или сменить канал, пока шёл HTTP-запрос.
            if (!self::enabled(clem_plugin()->get_settings()) || empty($fresh['running'])
                || ($fresh['generation'] ?? '') !== $state['generation']) {
                return;
            }
            if (isset($page['error'])) {
                if (!empty($page['restart']) && $state['restarts'] < 2) {
                    $state['offset'] = 0;
                    $state['version'] = '';
                    $state['started_at'] = current_time('mysql');
                    $state['generation'] = wp_generate_password(24, false, false);
                    $state['restarts']++;
                    $state['retries'] = 0;
                    update_option(self::STATE, $state, false);
                    $this->schedule(60);
                } elseif (!empty($page['retry']) && $state['retries'] < 3) {
                    $state['retries']++;
                    update_option(self::STATE, $state, false);
                    $this->schedule(60);
                } else {
                    $this->fail($state, $page['error']);
                }
                return;
            }
            $entries = $page['entries'];
            if ($page['offset'] !== $state['offset'] || $page['total'] <= 0
                || count($entries) > 500 || empty($entries)
                || $state['offset'] + count($entries) > $page['total']
                || ($state['version'] !== '' && ($state['version'] !== $page['version']
                    || $state['total'] !== $page['total']))) {
                $this->fail($state, __('Канал вернул несогласованные страницы перечня. Прежние данные сохранены.', 'chekazoid-legal-entity-marks'));
                return;
            }
            global $wpdb;
            $table = $wpdb->prefix . CLEM_TABLE;
            $last = (array) get_option(self::SUCCESS, []);
            if ($state['offset'] === 0 && ($last['version'] ?? '') === $page['version']
                && ($last['total'] ?? 0) === $page['total'] && !empty($last['generation'])
                /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
                && (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE type = %s AND is_active = 1 AND sync_generation = %s", self::TYPE, $last['generation']
                )) === $page['total']) {
                /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
                $last['synced_at'] = current_time('mysql');
                update_option(self::SUCCESS, $last, false);
                $state['running'] = false;
                $state['offset'] = $state['total'] = $page['total'];
                $state['version'] = $page['version'];
                update_option(self::STATE, $state, false);
                return;
            }
            $names = [];
            foreach ($entries as &$entry) {
                if (!is_array($entry) || !is_string($entry['name'] ?? null)
                    || mb_strlen(trim($entry['name'])) < 3 || mb_strlen($entry['name']) > 500
                    || ($entry['type'] ?? '') !== self::TYPE || empty($entry['is_person'])) {
                    $this->fail($state, __('Канал вернул неподходящую запись физических лиц. Прежние данные сохранены.', 'chekazoid-legal-entity-marks'));
                    return;
                }
                $entry = ['name' => trim($entry['name']), 'type' => self::TYPE,
                          'is_person' => 1, 'aliases' => []];
                $names[] = $entry['name'];
            }
            unset($entry);
            if (count(array_unique($names)) !== count($names)) {
                $this->fail($state, __('В странице перечня повторяются ФИО. Загрузите перечень заново.', 'chekazoid-legal-entity-marks'));
                return;
            }
            try {
                $imported = clem_plugin()->importer->import_verified_entries($entries, self::TYPE, $state['generation'], true);
                if (isset($imported['error'])) { throw new RuntimeException('people page write failed'); }
            } catch (Throwable $error) {
                $this->fail($state, __('Не удалось сохранить порцию перечня. Прежние статусы сохранены.', 'chekazoid-legal-entity-marks'));
                return;
            }
            $in = implode(',', array_fill(0, count($names), '%s'));
            // Отметка поколения: исключать отсутствующих можно лишь после всех страниц.
            $args = array_merge([$state['generation'], self::TYPE], $names);
            /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
            $saved = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET sync_generation = %s WHERE type = %s AND name IN ($in)", ...$args
            )) ;
            /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber */
            /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
            $present = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE type = %s AND sync_generation = %s AND name IN ($in)",
                ...array_merge([self::TYPE, $state['generation']], $names)
            )) ;
            /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber */
            if ($saved === false || $present !== count($names)) {
                $this->fail($state, __('Не удалось сохранить порцию перечня. Исключение прежних записей не выполнялось.', 'chekazoid-legal-entity-marks'));
                return;
            }
            $fresh = get_option(self::STATE, []);
            if (!self::enabled(clem_plugin()->get_settings()) || empty($fresh['running'])
                || ($fresh['generation'] ?? '') !== $state['generation']) {
                return;
            }
            $state['version'] = $page['version'];
            $state['total'] = $page['total'];
            $state['offset'] += count($entries);
            $state['retries'] = 0;
            if ($state['offset'] < $state['total']) {
                update_option(self::STATE, $state, false);
                $this->schedule();
                return;
            }
            $completed = $this->finish_snapshot($state, $page);
            if (isset($completed['error'])) {
                $this->fail($state, $completed['error']);
                return;
            }
            clem_plugin()->entities->flush_cache();
            clem_plugin()->cache->purge_all_marked();
            clem_plugin()->rescan->enqueue('registry-update', $state['started_at']);
        } finally {
            if (get_option(self::LOCK) === $lock) {
                delete_option(self::LOCK);
            }
        }
    }
    /** Publish statuses and successful-generation metadata together. */
    private function finish_snapshot($state, $page) {
        global $wpdb;
        $table = $wpdb->prefix . CLEM_TABLE;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Transactional publication of a custom registry table and its generation metadata; identifiers come from WordPress/plugin constants and values are prepared. Live engine/count checks cannot use cached state. Caches are invalidated after commit or rollback. */
        foreach ([$table, $wpdb->options] as $target) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $target
            ));
            if (strtolower((string) $engine) !== 'innodb') {
                return ['error' => __('Для безопасной сверки таблицы плагина и настроек должны использовать InnoDB. Данные сохранены.', 'chekazoid-legal-entity-marks')];
            }
        }
        $quiet = $wpdb->suppress_errors(true);
        try {
            if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('people transaction failed'); }
            $complete = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE type = %s AND sync_generation = %s",
                self::TYPE, $state['generation']
            ));
            if ($complete !== $state['total']) { throw new RuntimeException('people pages overlap or are incomplete'); }
            $saved = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET is_active = IF(sync_generation = %s, 1, 0),
                    registry_present = IF(sync_generation = %s, 1, 0)
                 WHERE type = %s AND (is_manual = 0 OR sync_generation = %s)",
                $state['generation'], $state['generation'], self::TYPE, $state['generation']
            ));
            if ($saved === false) { throw new RuntimeException('people publication failed'); }
            if (update_option(self::SUCCESS, [
                'synced_at' => current_time('mysql'), 'fetched_at' => $page['fetched_at'],
                'total' => $state['total'], 'version' => $state['version'], 'generation' => $state['generation'],
            ], false) === false) { throw new RuntimeException('people snapshot state write failed'); }
            $state['running'] = false;
            $state['error'] = '';
            if (update_option(self::STATE, $state, false) === false) { throw new RuntimeException('people worker state write failed'); }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('people commit failed'); }
            return [];
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            foreach ([self::SUCCESS, self::STATE, 'alloptions', 'notoptions'] as $key) { wp_cache_delete($key, 'options'); }
            return ['error' => __('Не удалось завершить полную сверку физических лиц. Прежние статусы сохранены.', 'chekazoid-legal-entity-marks')];
        } finally {
            $wpdb->suppress_errors($quiet);
        }
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
    }

}
