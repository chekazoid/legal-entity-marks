<?php
defined('ABSPATH') || exit;

class LEM_Link_Scanner {

    const SCAN_STATE_KEY   = 'lem_banned_scan_state';
    const REMOVE_STATE_KEY = 'lem_banned_remove_state';

    public function __construct() {
        add_action('wp_ajax_lem_banned_scan_init',    [$this, 'ajax_scan_init']);
        add_action('wp_ajax_lem_banned_scan_process', [$this, 'ajax_scan_process']);
        add_action('wp_ajax_lem_banned_scan_cancel',  [$this, 'ajax_scan_cancel']);

        add_action('wp_ajax_lem_banned_remove_init',    [$this, 'ajax_remove_init']);
        add_action('wp_ajax_lem_banned_remove_process', [$this, 'ajax_remove_process']);
        add_action('wp_ajax_lem_banned_remove_post',    [$this, 'ajax_remove_post']);
    }

    /* ------------------------------------------------------------------
     * Извлечение ссылок из HTML
     * ------------------------------------------------------------------ */

    /**
     * Извлекает все ссылки <a href="..."> из HTML-контента.
     */
    public function extract_links($html) {
        $links   = [];
        $pattern = '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si';
        if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $url      = $m[1][0];
                $anchor   = strip_tags($m[2][0]);
                $full_tag = $m[0][0];
                $offset   = $m[0][1];
                $parsed   = parse_url($url);
                $host     = $parsed['host'] ?? '';
                if (empty($host)) {
                    continue;
                }

                $links[] = [
                    'url'      => $url,
                    'host'     => mb_strtolower(preg_replace('/^www\./i', '', $host)),
                    'anchor'   => $anchor,
                    'full_tag' => $full_tag,
                    'offset'   => $offset,
                ];
            }
        }
        return $links;
    }

    /**
     * Сканирует контент на наличие ссылок на запрещённые домены.
     */
    public function scan_post_content($content, $banned_domains) {
        $links = $this->extract_links($content);
        $found = [];
        foreach ($links as $link) {
            $matched = LEM_Banned_Sites::is_domain_banned($link['host'], $banned_domains);
            if ($matched !== null) {
                $found[] = [
                    'url'            => $link['url'],
                    'anchor'         => $link['anchor'],
                    'matched_domain' => $matched,
                    'offset'         => $link['offset'],
                ];
            }
        }
        return $found;
    }

    /* ------------------------------------------------------------------
     * Удаление ссылок
     * ------------------------------------------------------------------ */

    /**
     * Удаляет все ссылки на запрещённые домены из контента.
     * <a href="banned.com">текст</a> → текст
     */
    public function remove_banned_links($content, $banned_domains) {
        $pattern = '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si';
        return preg_replace_callback($pattern, function ($m) use ($banned_domains) {
            $url    = $m[1];
            $parsed = parse_url($url);
            $host   = $parsed['host'] ?? '';
            if (empty($host)) {
                return $m[0];
            }
            $host = mb_strtolower(preg_replace('/^www\./i', '', $host));
            if (LEM_Banned_Sites::is_domain_banned($host, $banned_domains) !== null) {
                return $m[2]; // только текст ссылки
            }
            return $m[0];
        }, $content);
    }

    /**
     * Очищает один пост от запрещённых ссылок.
     * Возвращает количество изменённых ссылок.
     */
    public function clean_post($post_id, $banned_domains) {
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }

        $cleaned = $this->remove_banned_links($post->post_content, $banned_domains);
        if ($cleaned === $post->post_content) {
            return 0;
        }

        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => $cleaned], ['ID' => $post_id]);
        clean_post_cache($post_id);
        delete_post_meta($post_id, LEM_BANNED_LINKS_META_KEY);
        lem()->cache->purge_post($post_id);

        return 1;
    }

    /* ------------------------------------------------------------------
     * AJAX: сканирование ссылок (batch)
     * ------------------------------------------------------------------ */

    public function ajax_scan_init() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $banned = lem()->banned_sites->get_all_domains();
        if (empty($banned)) {
            wp_send_json_error('Реестр запрещённых доменов пуст. Добавьте домены перед сканированием.');
        }

        global $wpdb;
        $settings     = lem()->get_settings();
        $post_types   = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status = 'publish'",
            ...$post_types
        ));

        $state = [
            'status'           => 'running',
            'total'            => $total,
            'offset'           => 0,
            'posts_with_links' => 0,
            'total_links'      => 0,
            'started_at'       => current_time('mysql'),
        ];
        set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);

        wp_send_json_success($state);
    }

    public function ajax_scan_process() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $state = get_transient(self::SCAN_STATE_KEY);
        if (!$state || $state['status'] !== 'running') {
            wp_send_json_error('Нет активного сканирования');
        }

        global $wpdb;
        $settings     = lem()->get_settings();
        $post_types   = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $banned       = lem()->banned_sites->get_all_domains();

        $batch_size = 50;
        $offset     = (int) $state['offset'];

        $params   = $post_types;
        $params[] = $batch_size;
        $params[] = $offset;

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders) AND post_status = 'publish'
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            ...$params
        ));

        $now = current_time('mysql');

        wp_suspend_cache_addition(true);

        foreach ($posts as $post) {
            $found = $this->scan_post_content($post->post_content, $banned);
            if (!empty($found)) {
                $meta_json = wp_json_encode([
                    'links'      => $found,
                    'scanned_at' => $now,
                ], JSON_UNESCAPED_UNICODE);

                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                    $post->ID, LEM_BANNED_LINKS_META_KEY
                ));
                if ($existing) {
                    $wpdb->update($wpdb->postmeta, ['meta_value' => $meta_json], ['meta_id' => $existing]);
                } else {
                    $wpdb->insert($wpdb->postmeta, [
                        'post_id'    => $post->ID,
                        'meta_key'   => LEM_BANNED_LINKS_META_KEY,
                        'meta_value' => $meta_json,
                    ]);
                }
                $state['posts_with_links']++;
                $state['total_links'] += count($found);
            } else {
                $wpdb->delete($wpdb->postmeta, [
                    'post_id'  => $post->ID,
                    'meta_key' => LEM_BANNED_LINKS_META_KEY,
                ]);
            }
        }

        wp_suspend_cache_addition(false);
        wp_cache_flush_runtime();

        $state['offset'] = $offset + count($posts);

        if ($state['offset'] >= $state['total'] || empty($posts)) {
            $state['status']      = 'complete';
            $state['finished_at'] = current_time('mysql');
            try { wp_cache_flush(); } catch (\Throwable $e) {}
        }

        set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success($state);
    }

    public function ajax_scan_cancel() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $state = get_transient(self::SCAN_STATE_KEY);
        if ($state) {
            $state['status'] = 'cancelled';
            set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
        }
        wp_send_json_success(['status' => 'cancelled']);
    }

    /* ------------------------------------------------------------------
     * AJAX: массовое удаление ссылок (batch)
     * ------------------------------------------------------------------ */

    public function ajax_remove_init() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        global $wpdb;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            LEM_BANNED_LINKS_META_KEY
        ));

        if ($total === 0) {
            wp_send_json_error('Нет статей с запрещёнными ссылками');
        }

        $state = [
            'status'     => 'running',
            'total'      => $total,
            'cleaned'    => 0,
            'started_at' => current_time('mysql'),
        ];
        set_transient(self::REMOVE_STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success($state);
    }

    public function ajax_remove_process() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $state = get_transient(self::REMOVE_STATE_KEY);
        if (!$state || $state['status'] !== 'running') {
            wp_send_json_error('Нет активного процесса удаления');
        }

        global $wpdb;
        $banned = lem()->banned_sites->get_all_domains();

        // Берём батч из 20 постов с запрещёнными ссылками
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 20",
            LEM_BANNED_LINKS_META_KEY
        ));

        if (empty($post_ids)) {
            $state['status']      = 'complete';
            $state['finished_at'] = current_time('mysql');
            set_transient(self::REMOVE_STATE_KEY, $state, HOUR_IN_SECONDS);
            try { wp_cache_flush(); } catch (\Throwable $e) {}
            wp_send_json_success($state);
            return;
        }

        foreach ($post_ids as $post_id) {
            $this->clean_post((int) $post_id, $banned);
            $state['cleaned']++;
        }

        // Проверяем, остались ли ещё
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            LEM_BANNED_LINKS_META_KEY
        ));

        if ($remaining === 0) {
            $state['status']      = 'complete';
            $state['finished_at'] = current_time('mysql');
            try { wp_cache_flush(); } catch (\Throwable $e) {}
        }

        set_transient(self::REMOVE_STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success($state);
    }

    /**
     * AJAX: удаление ссылок из одного поста.
     */
    public function ajax_remove_post() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if ($post_id <= 0) {
            wp_send_json_error('Неверный ID статьи');
        }

        $banned = lem()->banned_sites->get_all_domains();
        $result = $this->clean_post($post_id, $banned);
        wp_send_json_success(['cleaned' => $result, 'post_id' => $post_id]);
    }

    /* ------------------------------------------------------------------
     * Batch scan для CLI/Cron
     * ------------------------------------------------------------------ */

    public function batch_scan($args = []) {
        global $wpdb;

        $settings     = lem()->get_settings();
        $post_types   = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $batch_size = (int) ($args['batch'] ?? 500);
        $log        = $args['log'] ?? function ($msg) {};
        $banned     = lem()->banned_sites->get_all_domains();

        if (empty($banned)) {
            $log('Реестр запрещённых доменов пуст.');
            return ['posts_with_links' => 0, 'total_links' => 0, 'total_posts' => 0];
        }

        $log('Загружено ' . count($banned) . ' запрещённых доменов.');

        if (!empty($args['post_id'])) {
            $post    = get_post((int) $args['post_id']);
            $found   = $post ? $this->scan_post_content($post->post_content, $banned) : [];
            $now     = current_time('mysql');
            if (!empty($found)) {
                // wp_slash: update_post_meta снимает слэши и иначе ломает экранирование кавычек в JSON
                update_post_meta($post->ID, LEM_BANNED_LINKS_META_KEY, wp_slash(wp_json_encode([
                    'links' => $found, 'scanned_at' => $now,
                ], JSON_UNESCAPED_UNICODE)));
            } else {
                delete_post_meta($post->ID, LEM_BANNED_LINKS_META_KEY);
            }
            return ['posts_with_links' => empty($found) ? 0 : 1, 'total_links' => count($found), 'total_posts' => 1];
        }

        $where  = "p.post_type IN ($placeholders) AND p.post_status = 'publish'";
        $params = $post_types;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where", ...$params
        ));
        $log("Статей для сканирования: $total (батч: $batch_size)");

        if ($total === 0) {
            return ['posts_with_links' => 0, 'total_links' => 0, 'total_posts' => 0];
        }

        $now    = current_time('mysql');
        $offset = 0;
        $posts_with_links = 0;
        $total_links      = 0;

        wp_suspend_cache_addition(true);

        while ($offset < $total) {
            $batch_params = array_merge($params, [$batch_size, $offset]);
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_content FROM {$wpdb->posts} p WHERE $where ORDER BY p.ID ASC LIMIT %d OFFSET %d",
                ...$batch_params
            ));
            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                $found = $this->scan_post_content($post->post_content, $banned);
                if (!empty($found)) {
                    $meta_json = wp_json_encode([
                        'links' => $found, 'scanned_at' => $now,
                    ], JSON_UNESCAPED_UNICODE);
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                        $post->ID, LEM_BANNED_LINKS_META_KEY
                    ));
                    if ($existing) {
                        $wpdb->update($wpdb->postmeta, ['meta_value' => $meta_json], ['meta_id' => $existing]);
                    } else {
                        $wpdb->insert($wpdb->postmeta, [
                            'post_id'    => $post->ID,
                            'meta_key'   => LEM_BANNED_LINKS_META_KEY,
                            'meta_value' => $meta_json,
                        ]);
                    }
                    $posts_with_links++;
                    $total_links += count($found);
                } else {
                    $wpdb->delete($wpdb->postmeta, [
                        'post_id'  => $post->ID,
                        'meta_key' => LEM_BANNED_LINKS_META_KEY,
                    ]);
                }
            }

            $offset += $batch_size;
            wp_cache_flush_runtime();
            $log("Обработано $offset / $total ...");
        }

        wp_suspend_cache_addition(false);
        try { wp_cache_flush(); } catch (\Throwable $e) {}

        return [
            'posts_with_links' => $posts_with_links,
            'total_links'      => $total_links,
            'total_posts'      => $total,
        ];
    }

    /**
     * Массовое удаление ссылок для CLI.
     */
    public function batch_remove($args = []) {
        global $wpdb;

        $batch_size = (int) ($args['batch'] ?? 100);
        $dry_run    = !empty($args['dry_run']);
        $log        = $args['log'] ?? function ($msg) {};
        $banned     = lem()->banned_sites->get_all_domains();

        if (!empty($args['post_id'])) {
            if ($dry_run) {
                $post  = get_post((int) $args['post_id']);
                $found = $post ? $this->scan_post_content($post->post_content, $banned) : [];
                $log('Найдено ' . count($found) . ' запрещённых ссылок (пробный запуск).');
                return ['cleaned' => 0, 'would_clean' => count($found) > 0 ? 1 : 0];
            }
            $result = $this->clean_post((int) $args['post_id'], $banned);
            return ['cleaned' => $result];
        }

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            LEM_BANNED_LINKS_META_KEY
        ));

        $total = count($post_ids);
        $log("Статей с запрещёнными ссылками: $total");

        if ($total === 0) {
            return ['cleaned' => 0];
        }

        if ($dry_run) {
            $log('Пробный запуск — изменения не применяются.');
            return ['cleaned' => 0, 'would_clean' => $total];
        }

        $cleaned = 0;
        $chunks  = array_chunk($post_ids, $batch_size);
        foreach ($chunks as $chunk) {
            foreach ($chunk as $post_id) {
                $cleaned += $this->clean_post((int) $post_id, $banned);
            }
            wp_cache_flush_runtime();
            $log("Очищено $cleaned / $total ...");
        }

        try { wp_cache_flush(); } catch (\Throwable $e) {}

        return ['cleaned' => $cleaned];
    }
}
