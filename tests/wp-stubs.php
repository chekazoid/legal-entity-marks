<?php
// Minimal equivalents used only by the standalone unit suites.
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false) {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
        $text = strip_tags($text);
        return trim($remove_breaks ? preg_replace('/[\\r\\n\\t ]+/', ' ', $text) : $text);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
