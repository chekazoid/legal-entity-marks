<?php
defined('ABSPATH') || exit;

/**
 * Запросы наружу.
 *
 * Отдельный слой понадобился из-за одной живой поломки: на сайте редакции
 * обновление реестров падало с «cURL error 28: Connection timeout after
 * 10000 ms», хотя плагин просил сорок пять секунд. Значение подменял чужой
 * фильтр http_request_args - так делают плагины ускорения и безопасности,
 * и спорить с ними бессмысленно, они правы для обычных запросов.
 *
 * Наши запросы другие: страница Минюста отвечает медленно, а выгрузка
 * иноагентов весит четверть мегабайта. Поэтому свой таймаут мы отстаиваем,
 * и только для своих запросов.
 */
class LEM_Http {

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
            if (empty($request['_lem'])) {
                return $request;
            }
            $request['timeout'] = max((float) ($request['timeout'] ?? 0), $timeout);
            return $request;
        };

        $args['_lem'] = true;
        add_filter('http_request_args', $keep, PHP_INT_MAX);
        try {
            $response = $method === 'post'
                ? wp_remote_post($url, $args)
                : wp_remote_get($url, $args);
        } finally {
            remove_filter('http_request_args', $keep, PHP_INT_MAX);
        }

        return $response;
    }
}
