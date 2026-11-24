<?php
defined('ABSPATH') || exit;

/** Добровольная загрузка ФИО порциями, без комплектной копии перечня. */
class LEM_People {
    const TYPE = 'fedsfm_person';
    const HOOK = 'lem_import_people';
    const STATE = 'lem_people_import_state';
    const SUCCESS = 'lem_people_last_success';
    const LOCK = 'lem_people_import_lock';

    public function __construct() {
        add_action(self::HOOK, [$this, 'run_import']);
    }

    public static function enabled($settings) {
        return in_array($settings['fedsfm_people_mode'] ?? 'off', ['track', 'mark'], true);
    }

    public function cancel() {
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::STATE);
        lem()->entities->flush_cache();
    }

    private function schedule($delay = 30) {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    public function queue($restart = false) {
        if (!self::enabled(lem()->get_settings())) {
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
        if (!lem()->channel->is_enabled() || !lem()->channel->has_token()) {
            $state['running'] = false;
            $state['error'] = 'Для загрузки физических лиц включите канал и укажите его токен.';
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
        if (!self::enabled(lem()->get_settings())) {
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
            $page = lem()->channel->fetch_people_page($state['offset'], $state['version']);
            $fresh = get_option(self::STATE, []);
            // Пользователь мог выключить функцию или сменить канал, пока шёл HTTP-запрос.
            if (!self::enabled(lem()->get_settings()) || empty($fresh['running'])
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
                $this->fail($state, 'Канал вернул несогласованные страницы перечня. Прежние данные сохранены.');
                return;
            }
            global $wpdb;
            $table = $wpdb->prefix . LEM_TABLE;
            $last = (array) get_option(self::SUCCESS, []);
            if ($state['offset'] === 0 && ($last['version'] ?? '') === $page['version']
                && ($last['total'] ?? 0) === $page['total']
                && (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE type = %s AND is_active = 1", self::TYPE
                )) === $page['total']) {
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
                    $this->fail($state, 'Канал вернул неподходящую запись физических лиц. Прежние данные сохранены.');
                    return;
                }
                $entry = ['name' => trim($entry['name']), 'type' => self::TYPE,
                          'is_person' => 1, 'aliases' => []];
                $names[] = $entry['name'];
            }
            unset($entry);
            if (count(array_unique($names)) !== count($names)) {
                $this->fail($state, 'В странице перечня повторяются ФИО. Загрузите перечень заново.');
                return;
            }
            lem()->importer->import_entries($entries, self::TYPE);
            $in = implode(',', array_fill(0, count($names), '%s'));
            // Отметка поколения: исключать отсутствующих можно лишь после всех страниц.
            $args = array_merge([$state['generation'], self::TYPE], $names);
            $saved = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET sync_generation = %s WHERE type = %s AND name IN ($in)", ...$args
            ));
            $present = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE type = %s AND is_active = 1 AND name IN ($in)",
                ...array_merge([self::TYPE], $names)
            ));
            if ($saved === false || $present !== count($names)) {
                $this->fail($state, 'Не удалось сохранить порцию перечня. Исключение прежних записей не выполнялось.');
                return;
            }
            $fresh = get_option(self::STATE, []);
            if (!self::enabled(lem()->get_settings()) || empty($fresh['running'])
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
            $removed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET is_active = 0 WHERE type = %s AND is_active = 1 AND sync_generation <> %s",
                self::TYPE, $state['generation']
            ));
            if ($removed === false) {
                $this->fail($state, 'Не удалось завершить сверку перечня. Повторите обновление.');
                return;
            }
            update_option(self::SUCCESS, [
                'synced_at' => current_time('mysql'), 'fetched_at' => $page['fetched_at'],
                'total' => $state['total'], 'version' => $state['version'],
            ], false);
            $state['running'] = false;
            $state['error'] = '';
            update_option(self::STATE, $state, false);
            lem()->entities->flush_cache();
            lem()->cache->purge_all_marked();
            lem()->rescan->enqueue('registry-update', $state['started_at']);
        } finally {
            if (get_option(self::LOCK) === $lock) {
                delete_option(self::LOCK);
            }
        }
    }
}
