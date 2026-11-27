<?php
defined('ABSPATH') || exit;

class CLEM_Link_Scanner {

    const SCAN_STATE_KEY   = 'clem_banned_scan_state';
    const REMOVE_STATE_KEY = 'clem_banned_remove_state';

    public function __construct() {
        add_action('wp_ajax_clem_banned_scan_init',    [$this, 'ajax_scan_init']);
        add_action('wp_ajax_clem_banned_scan_process', [$this, 'ajax_scan_process']);
        add_action('wp_ajax_clem_banned_scan_cancel',  [$this, 'ajax_scan_cancel']);

        add_action('wp_ajax_clem_banned_remove_init',    [$this, 'ajax_remove_init']);
        add_action('wp_ajax_clem_banned_remove_process', [$this, 'ajax_remove_process']);
        add_action('wp_ajax_clem_banned_remove_post',    [$this, 'ajax_remove_post']);
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
                $anchor   = wp_strip_all_tags($m[2][0]);
                $full_tag = $m[0][0];
                $offset   = $m[0][1];
                $parsed   = wp_parse_url($url);
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
     * Сканирует контент на наличие ссылок на запрещённые домены и аккаунты.
     *
     * @param array|null $accounts Запрещённые аккаунты; null - взять из реестра
     */
    public function scan_post_content($content, $banned_domains, $accounts = null) {
        if ($accounts === null) {
            $accounts = clem_plugin()->banned_sites->get_accounts();
        }
        $links = $this->extract_links($content);
        $found = [];
        foreach ($links as $link) {
            $matched = CLEM_Banned_Sites::is_domain_banned($link['host'], $banned_domains);
            if ($matched === null) {
                // Домен разрешён, но ссылка может вести на аккаунт организации
                $matched = CLEM_Banned_Sites::match_account($link['host'], $link['url'], $accounts);
            }
            if ($matched !== null) {
                // Реестр владельца: ссылка на сайт иноагента законом не запрещена,
                // а ссылка на ресурс нежелательной организации это уже риск
                $type = clem_plugin()->banned_sites->type_of($matched);
                $found[] = [
                    'url'            => $link['url'],
                    'anchor'         => $link['anchor'],
                    'matched_domain' => $matched,
                    'registry'       => $type,
                    'removable'      => clem_plugin()->banned_sites->is_removable($type),
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
    public function remove_banned_links($content, $banned_domains, $accounts = null) {
        if ($accounts === null) {
            $accounts = clem_plugin()->banned_sites->get_accounts();
        }
        $pattern = '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si';
        return preg_replace_callback($pattern, function ($m) use ($banned_domains, $accounts) {
            $url    = $m[1];
            $parsed = wp_parse_url($url);
            $host   = $parsed['host'] ?? '';
            if (empty($host)) {
                return $m[0];
            }
            $host = mb_strtolower(preg_replace('/^www\./i', '', $host));
            $matched = CLEM_Banned_Sites::is_domain_banned($host, $banned_domains);
            if ($matched === null) {
                $matched = CLEM_Banned_Sites::match_account($host, $url, $accounts);
            }
            // Ресурсы иноагентов из текста не вычищаем: ссылка на них разрешена,
            // достаточно маркировки. Иначе кнопка «удалить» снесла бы
            // нормальные ссылки на издания
            if ($matched !== null && clem_plugin()->banned_sites->is_removable(
                    clem_plugin()->banned_sites->type_of($matched))) {
                return $m[2]; // только текст ссылки
            }
            return $m[0];
        }, $content);
    }

    /**
     * Материалы, где есть хотя бы одна ссылка к удалению.
     *
     * Мета хранит все найденные ссылки, включая ресурсы иноагентов: они нужны
     * отчёту. Но для чистки текста годятся не все, иначе счётчики обещают
     * убрать сотню статей, а меняется десяток
     */
    public static function posts_with_removable() {
        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value != '' LIMIT 5000",
            CLEM_BANNED_LINKS_META_KEY
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */

        $out = [];
        foreach ($rows as $r) {
            $meta = json_decode($r->meta_value, true);
            foreach ((array) ($meta['links'] ?? []) as $l) {
                if (self::link_is_removable($l)) {
                    $out[] = (int) $r->post_id;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Убирать ли эту ссылку из текста.
     *
     * Мета, записанная до разделения реестров, отметки не содержит. Считать
     * такую ссылку удаляемой нельзя: на сайтах СМИ там лежат ресурсы
     * иноагентов, ссылаться на которые не запрещено. Поэтому реестр
     * определяем по текущему списку ресурсов.
     */
    public static function link_is_removable(array $link) {
        $index = clem_plugin()->banned_sites->get_index();
        $key = $link['matched_domain'] ?? '';
        return array_key_exists($key, $index) && clem_plugin()->banned_sites->is_removable($index[$key]);
    }

    /**
     * Оставляет только ссылки, которые полагается убирать из текста.
     * Ресурсы иноагентов остаются: ссылаться на них не запрещено.
     */
    public static function removable_only(array $links) {
        return array_values(array_filter($links, static function ($l) {
            return self::link_is_removable($l);
        }));
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

        $updated = wp_update_post(wp_slash(['ID' => $post_id, 'post_content' => $cleaned]), true);
        if (is_wp_error($updated)) {
            return 0;
        }

        // Пересобираем мету по очищенному тексту: часть ссылок остаётся
        // (ресурсы иноагентов), и отчёт должен их видеть
        $left = $this->scan_post_content($cleaned, $banned_domains);
        if (!empty($left)) {
            update_post_meta($post_id, CLEM_BANNED_LINKS_META_KEY, wp_slash(wp_json_encode([
                'links' => $left, 'scanned_at' => current_time('mysql'),
            ], JSON_UNESCAPED_UNICODE)));
        } else {
            delete_post_meta($post_id, CLEM_BANNED_LINKS_META_KEY);
        }
        clem_plugin()->cache->purge_post($post_id);

        return 1;
    }

    /* ------------------------------------------------------------------
     * AJAX: сканирование ссылок (batch)
     * ------------------------------------------------------------------ */

    public function ajax_scan_init() {
        check_ajax_referer('clem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $banned = clem_plugin()->banned_sites->get_all_domains();
        if (empty($banned)) {
            wp_send_json_error('Реестр ресурсов пуст. Добавьте домены перед сканированием.');
        }

        global $wpdb;
        $settings     = clem_plugin()->get_settings();
        $post_types   = $settings['post_types'] ?: [''];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status = 'publish'",
            ...$post_types
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare */

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
        check_ajax_referer('clem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $state = get_transient(self::SCAN_STATE_KEY);
        if (!$state || $state['status'] !== 'running') {
            wp_send_json_error('Нет активного сканирования');
        }

        global $wpdb;
        $settings     = clem_plugin()->get_settings();
        $post_types   = $settings['post_types'] ?: [''];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $banned       = clem_plugin()->banned_sites->get_all_domains();

        $batch_size = 25;
        $offset     = (int) $state['offset'];

        $params   = $post_types;
        $params[] = $batch_size;
        $params[] = $offset;

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders) AND post_status = 'publish'
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            ...$params
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber */

        $now = current_time('mysql');

        $budget    = CLEM_Scanner::time_budget();
        $start     = microtime(true);
        $processed = 0;

        wp_suspend_cache_addition(true);

        try {
            foreach ($posts as $post) {
                $found = $this->scan_post_content($post->post_content, $banned);
                if (!empty($found)) {
                    $meta_json = wp_json_encode([
                        'links'      => $found,
                        'scanned_at' => $now,
                    ], JSON_UNESCAPED_UNICODE);

                    update_post_meta($post->ID, CLEM_BANNED_LINKS_META_KEY, wp_slash($meta_json));
                    $state['posts_with_links']++;
                    $state['total_links'] += count($found);
                } else {
                    delete_post_meta($post->ID, CLEM_BANNED_LINKS_META_KEY);
                }

                $processed++;
                if (microtime(true) - $start > $budget) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            wp_suspend_cache_addition(false);
            $state['offset'] = $offset + $processed;
            set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
            wp_send_json_error('Ошибка при сканировании ссылок: ' . $e->getMessage());
        }

        wp_suspend_cache_addition(false);
        wp_cache_flush_runtime();

        $state['offset'] = $offset + $processed;

        if ($state['offset'] >= $state['total'] || empty($posts)) {
            $state['status']      = 'complete';
            $state['finished_at'] = current_time('mysql');
        }

        set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success($state);
    }

    public function ajax_scan_cancel() {
        check_ajax_referer('clem_scan_nonce', 'nonce');
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
        check_ajax_referer('clem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $total = count(self::posts_with_removable());

        if ($total === 0) {
            wp_send_json_error('Нет статей со ссылками, которые нужно убирать');
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
        check_ajax_referer('clem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $state = get_transient(self::REMOVE_STATE_KEY);
        if (!$state || $state['status'] !== 'running') {
            wp_send_json_error('Нет активного процесса удаления');
        }

        global $wpdb;
        $banned = clem_plugin()->banned_sites->get_all_domains();

        // Берём батч из 20 материалов, где есть что удалять
        $post_ids = array_slice(self::posts_with_removable(), 0, 20);

        if (empty($post_ids)) {
            $state['status']      = 'complete';
            $state['finished_at'] = current_time('mysql');
            set_transient(self::REMOVE_STATE_KEY, $state, HOUR_IN_SECONDS);
            wp_send_json_success($state);
            return;
        }

        foreach ($post_ids as $post_id) {
            $this->clean_post((int) $post_id, $banned);
            $state['cleaned']++;
        }

        // Проверяем, остались ли ещё
        $remaining = count(self::posts_with_removable());

        if ($remaining === 0) {
            $state['status']      = 'complete';
            $state['finished_at'] = current_time('mysql');
        }

        set_transient(self::REMOVE_STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success($state);
    }

    /**
     * AJAX: удаление ссылок из одного поста.
     */
    public function ajax_remove_post() {
        check_ajax_referer('clem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $post_id = absint(wp_unslash($_POST['post_id'] ?? 0));
        if ($post_id <= 0) {
            wp_send_json_error('Неверный ID статьи');
        }

        $banned = clem_plugin()->banned_sites->get_all_domains();
        $result = $this->clean_post($post_id, $banned);
        wp_send_json_success(['cleaned' => $result, 'post_id' => $post_id]);
    }

    /* ------------------------------------------------------------------
     * Batch scan для CLI/Cron
     * ------------------------------------------------------------------ */

    public function batch_scan($args = []) {
        global $wpdb;

        $settings     = clem_plugin()->get_settings();
        $post_types   = $settings['post_types'] ?: [''];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $batch_size = (int) ($args['batch'] ?? 500);
        $log        = $args['log'] ?? function ($msg) {};
        $banned     = clem_plugin()->banned_sites->get_all_domains();

        if (empty($banned)) {
            $log('Реестр ресурсов пуст.');
            return ['posts_with_links' => 0, 'total_links' => 0, 'total_posts' => 0];
        }

        $log('Загружено ' . count($banned) . ' ресурсов из реестров.');

        if (!empty($args['post_id'])) {
            $post    = get_post((int) $args['post_id']);
            $found   = $post ? $this->scan_post_content($post->post_content, $banned) : [];
            $now     = current_time('mysql');
            if (!empty($found)) {
                // wp_slash: update_post_meta снимает слэши и иначе ломает экранирование кавычек в JSON
                update_post_meta($post->ID, CLEM_BANNED_LINKS_META_KEY, wp_slash(wp_json_encode([
                    'links' => $found, 'scanned_at' => $now,
                ], JSON_UNESCAPED_UNICODE)));
            } else {
                delete_post_meta($post->ID, CLEM_BANNED_LINKS_META_KEY);
            }
            return ['posts_with_links' => empty($found) ? 0 : 1, 'total_links' => count($found), 'total_posts' => 1];
        }

        $where  = "p.post_type IN ($placeholders) AND p.post_status = 'publish'";
        $params = $post_types;

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where", ...$params
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare */
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
            /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_content FROM {$wpdb->posts} p WHERE $where ORDER BY p.ID ASC LIMIT %d OFFSET %d",
                ...$batch_params
            )) ;
            /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber */
            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                $found = $this->scan_post_content($post->post_content, $banned);
                if (!empty($found)) {
                    $meta_json = wp_json_encode([
                        'links' => $found, 'scanned_at' => $now,
                    ], JSON_UNESCAPED_UNICODE);
                    update_post_meta($post->ID, CLEM_BANNED_LINKS_META_KEY, wp_slash($meta_json));
                    $posts_with_links++;
                    $total_links += count($found);
                } else {
                    delete_post_meta($post->ID, CLEM_BANNED_LINKS_META_KEY);
                }
            }

            $offset += $batch_size;
            wp_cache_flush_runtime();
            $log("Обработано $offset / $total ...");
        }

        wp_suspend_cache_addition(false);

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
        $banned     = clem_plugin()->banned_sites->get_all_domains();

        if (!empty($args['post_id'])) {
            if ($dry_run) {
                $post  = get_post((int) $args['post_id']);
                $found = $post ? $this->scan_post_content($post->post_content, $banned) : [];
                $found = self::removable_only($found);
                $log('Найдено ' . count($found) . ' ссылок к удалению (пробный запуск).');
                return ['cleaned' => 0, 'would_clean' => count($found) > 0 ? 1 : 0];
            }
            $result = $this->clean_post((int) $args['post_id'], $banned);
            return ['cleaned' => $result];
        }

        $post_ids = self::posts_with_removable();

        $total = count($post_ids);
        $log("Статей со ссылками к удалению: $total");

        if ($total === 0) {
            return ['cleaned' => 0];
        }

        if ($dry_run) {
            $log('Пробный запуск - изменения не применяются.');
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


        return ['cleaned' => $cleaned];
    }
}
