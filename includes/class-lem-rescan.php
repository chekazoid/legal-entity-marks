<?php
defined('ABSPATH') || exit;

/**
 * Проверка архива после обновления реестров.
 *
 * Организация, о которой вчера можно было писать свободно, сегодня может
 * оказаться в реестре, а публикация пятилетней давности со ссылкой на её сайт
 * никуда не делась. Поэтому каждое обновление реестра тянет за собой проход
 * по всему архиву: и по тексту, и по ссылкам.
 *
 * Работа идёт частями по расписанию: большой сайт за один запрос не обойти,
 * а ронять его ради проверки нельзя.
 */
class LEM_Rescan {

    const STATE_OPTION  = 'lem_rescan_state';
    const REPORT_OPTION = 'lem_last_update_report';
    const HOOK          = 'lem_run_rescan';
    const LOCK          = 'lem_rescan_lock';
    const SLICE         = 25;

    public function __construct() {
        add_action(self::HOOK, [$this, 'run']);
    }

    /**
     * Поставить проверку архива в очередь.
     *
     * @param string $trigger  что вызвало: registry-update, manual
     * @param string $since    отсечка «новизны»: записи реестра, появившиеся
     *                         не раньше этого момента, считаются новичками
     */
    public function enqueue($trigger = 'registry-update', $since = null) {
        global $wpdb;

        $settings   = lem()->get_settings();
        $post_types = $settings['post_types'];
        if (empty($post_types)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders) AND post_status IN ('publish','private','future')",
            ...$post_types
        ));

        update_option(self::STATE_OPTION, [
            'trigger'    => $trigger,
            'since'      => $since ?: current_time('mysql'),
            'started_at' => current_time('mysql'),
            'offset'     => 0,
            'total'      => $total,
            'done'       => false,
            'counters'   => [
                'scanned'      => 0,
                'with_matches' => 0,
                'fresh'        => 0,
                'with_links'   => 0,
                'links'        => 0,
            ],
        ], false);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 10, self::HOOK);
        }
        return true;
    }

    /** Состояние для админки. */
    public function status() {
        $state = get_option(self::STATE_OPTION, []);
        if (empty($state)) {
            return null;
        }
        $state['percent'] = $state['total'] > 0
            ? min(100, (int) round($state['offset'] / $state['total'] * 100))
            : 100;
        return $state;
    }

    /** Итог последнего обновления: что принесло и что нашлось в архиве. */
    public function last_report() {
        return get_option(self::REPORT_OPTION, []);
    }

    public function cancel() {
        delete_option(self::STATE_OPTION);
        wp_clear_scheduled_hook(self::HOOK);
        delete_transient(self::LOCK);
    }

    /**
     * Обработка очередной порции. Сама себя перезапускает, пока архив не кончится.
     */
    public function run() {
        $state = get_option(self::STATE_OPTION, []);
        if (empty($state) || !empty($state['done'])) {
            return;
        }
        if (get_transient(self::LOCK)) {
            return; // предыдущий запуск ещё идёт
        }
        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);

        global $wpdb;
        $settings   = lem()->get_settings();
        $post_types = $settings['post_types'];
        $entities   = lem()->entities->get_for_marking(!empty($settings['mark_excluded']));
        $banned     = lem()->banned_sites->get_all_domains();
        $accounts   = lem()->banned_sites->get_accounts();
        $now        = current_time('mysql');

        $budget = LEM_Scanner::time_budget();
        $start  = microtime(true);

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        try {
            while ($state['offset'] < $state['total']) {
                $params = array_merge($post_types, [self::SLICE, $state['offset']]);
                $posts  = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_title, post_content, post_type FROM {$wpdb->posts}
                     WHERE post_type IN ($placeholders)
                       AND post_status IN ('publish','private','future')
                     ORDER BY ID ASC LIMIT %d OFFSET %d",
                    ...$params
                ));
                if (empty($posts)) {
                    break;
                }

                foreach ($posts as $post) {
                    // Текст и ссылки за один проход: контент уже загружен
                    $found = lem()->scanner->scan_text(
                        lem()->scanner->collect_text($post, $settings),
                        $entities
                    );
                    $fresh = LEM_Scanner::write_matches($post->ID, $found, $now);

                    $state['counters']['scanned']++;
                    if (!empty($found)) {
                        $state['counters']['with_matches']++;
                    }
                    $state['counters']['fresh'] += $fresh;

                    if (!empty($banned) || !empty($accounts)) {
                        $links = lem()->link_scanner->scan_post_content(
                            $post->post_content, $banned, $accounts
                        );
                        if (!empty($links)) {
                            update_post_meta($post->ID, LEM_BANNED_LINKS_META_KEY,
                                wp_slash(wp_json_encode([
                                    'links' => $links, 'scanned_at' => $now,
                                ], JSON_UNESCAPED_UNICODE)));
                            $state['counters']['with_links']++;
                            $state['counters']['links'] += count($links);
                        } else {
                            delete_post_meta($post->ID, LEM_BANNED_LINKS_META_KEY);
                        }
                    }
                }

                $state['offset'] += count($posts);
                update_option(self::STATE_OPTION, $state, false);

                if ((microtime(true) - $start) > $budget) {
                    break; // добьём следующим запуском
                }
            }
        } catch (Throwable $e) {
            $state['error'] = $e->getMessage();
        }

        delete_transient(self::LOCK);

        if ($state['offset'] >= $state['total'] || !empty($state['error'])) {
            $state['done']        = true;
            $state['finished_at'] = current_time('mysql');
            update_option(self::STATE_OPTION, $state, false);
            $this->finish($state);
            return;
        }

        update_option(self::STATE_OPTION, $state, false);
        wp_schedule_single_event(time() + 30, self::HOOK);
    }

    /**
     * Итог прохода: что нового пришло в реестры и где это встретилось.
     */
    private function finish(array $state) {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        $since = $state['since'];

        // Первичное наполнение: новичков нет, есть просто первая опись архива
        $initial = ($state['trigger'] ?? '') === 'initial';

        $new_entities = $initial ? [] : $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, type, date_included FROM $table
             WHERE first_seen >= %s AND is_active = 1
             ORDER BY type, name",
            $since
        ), ARRAY_A);
        $new_entities = $new_entities ?: [];

        // Из новичков реестра важны те, что реально встретились в архиве
        $mentioned = [];
        $posts     = [];
        if (!empty($new_entities)) {
            $new_ids = array_flip(array_map('intval', array_column($new_entities, 'id')));
            $rows    = lem()->report->get_rows(['limit' => 100000]);
            foreach ($rows['items'] as $row) {
                if (isset($new_ids[(int) $row['entity_id']])) {
                    $mentioned[(int) $row['entity_id']] = true;
                    $posts[(int) $row['post_id']]       = true;
                }
            }
        }

        $by_type = [];
        foreach ($new_entities as $e) {
            $by_type[$e['type']] = ($by_type[$e['type']] ?? 0) + 1;
        }

        update_option(self::REPORT_OPTION, [
            'initial'       => $initial,
            'with_matches'  => $state['counters']['with_matches'],
            'at'            => $state['finished_at'] ?? current_time('mysql'),
            'since'         => $since,
            'trigger'       => $state['trigger'],
            'new_entities'  => count($new_entities),
            'by_type'       => $by_type,
            'names'         => array_slice(array_column($new_entities, 'name'), 0, 10),
            'mentioned'     => count($mentioned),
            'mentioned_in'  => count($posts),
            'posts_scanned' => $state['counters']['scanned'],
            'with_links'    => $state['counters']['with_links'],
            'links'         => $state['counters']['links'],
            'error'         => $state['error'] ?? '',
        ], false);

        if ($state['counters']['with_matches'] > 0) {
            lem()->cache->purge_all_marked();
        }
    }
}
