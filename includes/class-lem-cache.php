<?php
defined('ABSPATH') || exit;

class CLEM_Cache {

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

        // Invalidate the current article without evicting other post objects.
        wp_cache_delete($post_id, 'posts');

        // Custom cache solutions (nginx FastCGI и другие - подпишитесь на хук)
        do_action('clem_purge_post_cache', $post_id, $url);
    }

    public function purge_all_marked() {
        global $wpdb;
        $cursor = 0;
        $purged = 0;
        do {
            /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read batches of current WordPress metadata; the cursor avoids silently leaving pages beyond a fixed total limit cached. */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value != '' AND meta_value IS NOT NULL
                   AND meta_id > %d ORDER BY meta_id LIMIT 500", CLEM_META_KEY, $cursor
            ));
            /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
            foreach ($rows as $row) {
                $this->purge_post((int) $row->post_id);
                $cursor = (int) $row->meta_id;
                $purged++;
            }
        } while (count($rows) === 500);

        do_action('clem_purge_all_cache', $purged);
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

        do_action('clem_purge_all_cache', 0);
    }
}
