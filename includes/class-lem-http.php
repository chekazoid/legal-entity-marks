<?php
defined('ABSPATH') || exit;

/**
 * Preserves the channel timeout when a site-wide HTTP filter sets a shorter one.
 */
class CLEM_Http {

    public static function get($url, $args = []) {
        return self::request('get', $url, $args);
    }

    public static function post($url, $args = []) {
        return self::request('post', $url, $args);
    }

    private static function request($method, $url, array $args) {
        $timeout = (float) ($args['timeout'] ?? 30);

        // Возвращаем свой таймаут последним слоем, поверх чужих фильтров
        $keep = static function ($request) use ($timeout) {
            if (empty($request['_clem_request'])) {
                return $request;
            }
            $request['timeout'] = max((float) ($request['timeout'] ?? 0), $timeout);
            $request['redirection'] = 0;
            $request['sslverify'] = true;
            $request['reject_unsafe_urls'] = true;
            return $request;
        };

        $args['_clem_request'] = true;
        $args['redirection'] = 0;
        add_filter('http_request_args', $keep, PHP_INT_MAX);
        try {
            $response = $method === 'post'
                ? wp_safe_remote_post($url, $args)
                : wp_safe_remote_get($url, $args);
        } finally {
            remove_filter('http_request_args', $keep, PHP_INT_MAX);
        }

        return $response;
    }
}
