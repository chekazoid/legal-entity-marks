<?php
defined('ABSPATH') || exit;

class LEM_Cache {

    public function purge_post($post_id) {
        $url = get_permalink($post_id);

        // WP Super Cache
        if (function_exists('wp_cache_post_change')) {
            wp_cache_post_change($post_id);
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($post_id);
        }

        // WP Rocket
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_post')) {
            LiteSpeed_Cache_API::purge_post($post_id);
        }

        // WP Fastest Cache
        if (function_exists('wpfc_clear_post_cache_by_id')) {
            wpfc_clear_post_cache_by_id($post_id);
        }

        // Starter Cache (WordPress 6.5+)
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('posts');
        }

        // Custom cache solutions (nginx FastCGI и другие — подпишитесь на хук)
        do_action('lem_purge_post_cache', $post_id, $url);
    }

    public function purge_all_marked() {
        global $wpdb;
        $post_ids = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '" . LEM_META_KEY . "'
             AND meta_value != '' AND meta_value IS NOT NULL
             LIMIT 5000"
        );

        if (empty($post_ids)) {
            return 0;
        }

        $purged = 0;
        foreach ($post_ids as $pid) {
            $this->purge_post($pid);
            $purged++;
        }

        do_action('lem_purge_all_cache', $purged);
        return $purged;
    }

    public function purge_all() {
        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all();
        }

        do_action('lem_purge_all_cache', 0);
    }
}
