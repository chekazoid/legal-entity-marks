<?php
defined('ABSPATH') || exit;

class LEM_Scanner {

    const SCAN_STATE_KEY = 'lem_scan_state';

    public function __construct() {
        add_action('transition_post_status', [$this, 'on_post_publish'], 20, 3);
        add_action('wp_ajax_lem_scan_init', [$this, 'ajax_scan_init']);
        add_action('wp_ajax_lem_scan_process', [$this, 'ajax_scan_process']);
        add_action('wp_ajax_lem_scan_cancel', [$this, 'ajax_scan_cancel']);
    }

    /* ------------------------------------------------------------------
     * Core matching
     * ------------------------------------------------------------------ */

    public static function word_match($text, $term) {
        $quoted  = preg_quote($term, '/');
        $pattern = '/(?<!\pL)' . $quoted . '(?!\pL)/ui';
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return mb_strlen(substr($text, 0, $m[0][1]));
        }
        return false;
    }

    /**
     * Words too generic to be used as standalone search terms.
     * These cause massive false positives when extracted from entity names.
     */
    const TERM_BLACKLIST = [
        'вместе', 'движение', 'фирма', 'братство', 'независимость',
        'мемориал', 'эхо', 'сирена', 'правда', 'победа', 'свобода',
        'родина', 'союз', 'центр', 'община', 'партия', 'фонд',
        'армия', 'мост', 'единство', 'весна', 'прогресс', 'возрождение',
        'справедливость', 'держава', 'путь', 'воля', 'честь',
        'поколение', 'агора', 'зимин',
        'голос', 'агентство', 'белый', 'атака', 'акцент', 'арктика',
        'башня', 'таганрог',
        // Countries
        'швейцария', 'финляндия', 'норвегия', 'германия', 'франция',
        'великобритания', 'нидерланды', 'турция', 'канада', 'литва',
        'латвия', 'эстония', 'польша', 'болгария', 'чехия', 'украина',
        'грузия', 'сша', 'япония', 'израиль', 'испания', 'италия',
        'азербайджан', 'молдова', 'казахстан',
        // Regions
        'донбасс', 'крым', 'кавказ', 'сибирь',
    ];

    public static function search_terms($entity) {
        $terms = [$entity['name']];
        if (!empty($entity['aliases'])) {
            $terms = array_merge($terms, $entity['aliases']);
        }

        $cleaned = [];
        foreach ($terms as $term) {
            $t = trim($term);
            if (mb_strlen($t) < 3) {
                continue;
            }
            // Skip blacklisted single-word terms
            if (in_array(mb_strtolower($t), self::TERM_BLACKLIST, true)) {
                continue;
            }
            $cleaned[] = $t;
            $stripped = preg_replace('/^[«"\']+|[»"\']+$/u', '', $t);
            if ($stripped !== $t && mb_strlen($stripped) >= 3) {
                if (!in_array(mb_strtolower($stripped), self::TERM_BLACKLIST, true)) {
                    $cleaned[] = $stripped;
                }
            }
        }
        return array_unique($cleaned);
    }

    public function scan_text($text, $entities = null) {
        if ($entities === null) {
            $entities = lem()->entities->get_all_active();
        }
        $plain = strip_tags($text);
        $found = [];

        foreach ($entities as $entity) {
            $terms      = self::search_terms($entity);
            $matched_as = null;
            $first_pos  = PHP_INT_MAX;

            foreach ($terms as $term) {
                $pos = self::word_match($plain, $term);
                if ($pos !== false && $pos < $first_pos) {
                    $first_pos  = $pos;
                    $matched_as = $term;
                }
            }

            if ($matched_as !== null) {
                $found[] = [
                    'id'         => (int) $entity['id'],
                    'name'       => $entity['name'],
                    'type'       => $entity['type'],
                    'matched_as' => $matched_as,
                    'position'   => $first_pos,
                ];
            }
        }

        usort($found, fn($a, $b) => $a['position'] - $b['position']);
        return $found;
    }

    public function scan_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $settings   = lem()->get_settings();
        $post_types = $settings['post_types'];
        if (!in_array($post->post_type, $post_types, true)) {
            return [];
        }

        $found = $this->scan_text($post->post_title . "\n\n" . $post->post_content);
        $meta  = [
            'entities'     => $found,
            'scanned_at'   => current_time('mysql'),
            'list_version' => get_option('lem_list_version', ''),
        ];
        // wp_slash: update_post_meta снимает слэши и иначе ломает экранирование кавычек в JSON
        update_post_meta($post_id, LEM_META_KEY, wp_slash(wp_json_encode($meta, JSON_UNESCAPED_UNICODE)));

        return $found;
    }

    /* ------------------------------------------------------------------
     * Auto-scan on publish
     * ------------------------------------------------------------------ */

    public function on_post_publish($new_status, $old_status, $post) {
        $settings = lem()->get_settings();
        if (!$settings['auto_scan_on_publish']) {
            return;
        }
        if ($new_status !== 'publish') {
            return;
        }
        if (!in_array($post->post_type, $settings['post_types'], true)) {
            return;
        }
        $this->scan_post($post->ID);
    }

    /* ------------------------------------------------------------------
     * AJAX batch scan
     * ------------------------------------------------------------------ */

    public function ajax_scan_init() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $settings   = lem()->get_settings();
        $post_types = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $mode = sanitize_text_field($_POST['mode'] ?? 'all');

        $where = "post_type IN ($placeholders) AND post_status = 'publish'";
        $params = $post_types;

        if ($mode === 'recent') {
            $where   .= ' AND post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where",
            ...$params
        ));

        $state = [
            'status'              => 'running',
            'mode'                => $mode,
            'total'               => $total,
            'offset'              => 0,
            'posts_with_matches'  => 0,
            'total_mentions'      => 0,
            'started_at'          => current_time('mysql'),
        ];
        set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);

        wp_send_json_success($state);
    }

    public function ajax_scan_process() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $state = get_transient(self::SCAN_STATE_KEY);
        if (!$state || $state['status'] !== 'running') {
            wp_send_json_error('No active scan');
        }

        global $wpdb;
        $settings     = lem()->get_settings();
        $post_types   = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $batch_size = 50;
        $offset     = (int) $state['offset'];

        $where  = "post_type IN ($placeholders) AND post_status = 'publish'";
        $params = $post_types;

        if ($state['mode'] === 'recent') {
            $where .= ' AND post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        }

        $params[] = $batch_size;
        $params[] = $offset;

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE $where ORDER BY ID ASC LIMIT %d OFFSET %d",
            ...$params
        ));

        $entities = lem()->entities->get_all_active();
        $list_ver = get_option('lem_list_version', '');
        $now      = current_time('mysql');

        wp_suspend_cache_addition(true);

        foreach ($posts as $post) {
            $found = $this->scan_text($post->post_title . "\n\n" . $post->post_content, $entities);
            if (!empty($found)) {
                $meta_json = wp_json_encode([
                    'entities'     => $found,
                    'scanned_at'   => $now,
                    'list_version' => $list_ver,
                ], JSON_UNESCAPED_UNICODE);

                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                    $post->ID, LEM_META_KEY
                ));
                if ($existing) {
                    $wpdb->update($wpdb->postmeta, ['meta_value' => $meta_json], ['meta_id' => $existing]);
                } else {
                    $wpdb->insert($wpdb->postmeta, [
                        'post_id'    => $post->ID,
                        'meta_key'   => LEM_META_KEY,
                        'meta_value' => $meta_json,
                    ]);
                }
                $state['posts_with_matches']++;
                $state['total_mentions'] += count($found);
            } else {
                $wpdb->delete($wpdb->postmeta, [
                    'post_id'  => $post->ID,
                    'meta_key' => LEM_META_KEY,
                ]);
            }
        }

        wp_suspend_cache_addition(false);
        wp_cache_flush_runtime();

        $state['offset'] = $offset + count($posts);

        if ($state['offset'] >= $state['total'] || empty($posts)) {
            $state['status']      = 'complete';
            $state['finished_at'] = current_time('mysql');
            try {
                wp_cache_flush();
            } catch (\Throwable $e) {
                // Ignore cache flush errors
            }
        }

        set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success($state);
    }

    public function ajax_scan_cancel() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $state = get_transient(self::SCAN_STATE_KEY);
        if ($state) {
            $state['status'] = 'cancelled';
            set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
        }

        wp_send_json_success(['status' => 'cancelled']);
    }

    /* ------------------------------------------------------------------
     * Batch scan for WP-CLI / Cron
     * ------------------------------------------------------------------ */

    public function batch_scan($args = []) {
        global $wpdb;

        $settings     = lem()->get_settings();
        $post_types   = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $batch_size = (int) ($args['batch'] ?? 500);
        $dry_run    = !empty($args['dry_run']);
        $log        = $args['log'] ?? function ($msg) {};

        $where  = "p.post_type IN ($placeholders) AND p.post_status = 'publish'";
        $params = $post_types;

        if (!empty($args['post_id'])) {
            $found = $this->scan_post((int) $args['post_id']);
            return ['posts_with_matches' => empty($found) ? 0 : 1, 'total_mentions' => count($found)];
        }

        if (!empty($args['recent'])) {
            $where .= ' AND p.post_date >= DATE_SUB(NOW(), INTERVAL ' . (int) $args['recent'] . ' DAY)';
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where",
            ...$params
        ));
        $log("Posts to scan: $total (batch size: $batch_size)");

        if ($total === 0) {
            return ['posts_with_matches' => 0, 'total_mentions' => 0];
        }

        $entities = lem()->entities->get_all_active();
        $list_ver = get_option('lem_list_version', '');
        $now      = current_time('mysql');
        $offset   = 0;
        $posts_with_matches = 0;
        $total_mentions     = 0;

        wp_suspend_cache_addition(true);

        while ($offset < $total) {
            $batch_params = array_merge($params, [$batch_size, $offset]);
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_content FROM {$wpdb->posts} p WHERE $where ORDER BY p.ID ASC LIMIT %d OFFSET %d",
                ...$batch_params
            ));
            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                $found = $this->scan_text($post->post_title . "\n\n" . $post->post_content, $entities);
                if (!empty($found)) {
                    if (!$dry_run) {
                        $meta_json = wp_json_encode([
                            'entities'     => $found,
                            'scanned_at'   => $now,
                            'list_version' => $list_ver,
                        ], JSON_UNESCAPED_UNICODE);
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                            $post->ID, LEM_META_KEY
                        ));
                        if ($existing) {
                            $wpdb->update($wpdb->postmeta, ['meta_value' => $meta_json], ['meta_id' => $existing]);
                        } else {
                            $wpdb->insert($wpdb->postmeta, [
                                'post_id'    => $post->ID,
                                'meta_key'   => LEM_META_KEY,
                                'meta_value' => $meta_json,
                            ]);
                        }
                    }
                    $posts_with_matches++;
                    $total_mentions += count($found);
                } else {
                    if (!$dry_run) {
                        $wpdb->delete($wpdb->postmeta, [
                            'post_id'  => $post->ID,
                            'meta_key' => LEM_META_KEY,
                        ]);
                    }
                }
            }

            $offset += $batch_size;
            wp_cache_flush_runtime();
            $log("Processed $offset / $total ...");
        }

        wp_suspend_cache_addition(false);

        try {
            wp_cache_flush();
        } catch (\Throwable $e) {
            // Ignore
        }

        return [
            'posts_with_matches' => $posts_with_matches,
            'total_mentions'     => $total_mentions,
            'total_posts'        => $total,
        ];
    }
}
