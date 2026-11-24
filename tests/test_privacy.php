<?php
/** Network contract: no statistics before explicit consent, including upgrades. */
require_once __DIR__ . '/wp-stubs.php';
define('ABSPATH', '/tmp/');
define('LEM_VERSION', '1.15.1');
function add_action() {} function add_filter() {}
function wp_parse_args($saved, $defaults) { return array_merge($defaults, $saved); }
function get_option($key, $default = false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['opts'][$key] = $value; return true; }
function wp_generate_password($length, $special = false, $extra = false) { $GLOBALS['generated']++; return str_repeat('A', $length); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function wp_remote_retrieve_response_code($value) { return $value['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function is_wp_error($value) { return false; }
function home_url($path = '') { return 'https://example.org' . $path; }
function get_bloginfo($field) { return $field === 'version' ? '7.1' : 'Test newsroom'; }
function sanitize_email($value) { return trim($value); }
function wp_json_encode($value) { return json_encode($value); }
function current_time($format, $utc = false) { return '2026-11-24 00:00:00'; }
class LEM_Banned_Sites { const REMOVABLE_TYPES = ['extremist', 'terrorist', 'undesirable']; }
class LEM_Http {
    public static $calls = [];
    public static function get($url, $args) {
        self::$calls[] = ['method' => 'get', 'url' => $url, 'args' => $args];
        return ['code' => 200, 'body' => '{"ok":true,"last_snapshot":"2026-11-24"}'];
    }
    public static function post($url, $args) {
        self::$calls[] = ['method' => 'post', 'url' => $url, 'args' => $args];
        return ['code' => 200, 'body' => '{"ok":true}'];
    }
}
require_once __DIR__ . '/../includes/class-lem-channel.php';
require_once __DIR__ . '/../includes/class-lem-plugin.php';
$GLOBALS['opts'] = []; $GLOBALS['generated'] = 0;
$GLOBALS['plugin'] = (new ReflectionClass('LEM_Plugin'))->newInstanceWithoutConstructor();
function lem() { return $GLOBALS['plugin']; }
$channel = new LEM_Channel();
$failures = 0;
function check($name, $ok) { global $failures; if (!$ok) { $failures++; } echo ($ok ? 'OK ' : 'FAIL ') . $name . "\n"; }
function headers() { $call = end(LEM_Http::$calls); return $call['args']['headers'] ?? []; }
check('Fresh install has statistics off', !lem()->get_settings()['stats_enabled']);
$channel->health();
check('A normal health request sends no statistics by default', headers() === []);
check('No identifier generated for an anonymous health request', $GLOBALS['generated'] === 0);
$GLOBALS['opts']['lem_settings'] = ['stats_enabled' => true];
$channel->health();
check('Legacy enabled default is not consent', !$channel->stats_enabled() && headers() === []);
$GLOBALS['opts']['lem_settings']['stats_consent_at'] = '2026-11-24 00:00:00';
$channel->health();
check('Explicit consent sends only the identifier and version', array_keys(headers()) === ['X-LEM-Install', 'X-LEM-Version']);
check('Identifier generated only once', $GLOBALS['generated'] === 1);
$id = headers()['X-LEM-Install'];
$channel->health();
check('Repeated request reuses the identifier', headers()['X-LEM-Install'] === $id && $GLOBALS['generated'] === 1);
$GLOBALS['opts']['lem_settings']['stats_enabled'] = false;
$channel->health();
check('Disabling consent immediately removes statistics headers', headers() === []);
check('Disabling does not delete local data', get_option('lem_install_id') === $id);
check('Ordinary channel requests never register a site', !in_array('post', array_column(LEM_Http::$calls, 'method'), true));
$reply = $channel->register_site('editor@example.org');
$call = end(LEM_Http::$calls); $payload = json_decode($call['args']['body'], true);
check('Only the explicit registration call sends site fields', $reply['ok'] && $call['method'] === 'post' && $payload['site'] === 'https://example.org/');
check('Registration does not enable statistics', !$channel->stats_enabled());
check('Registration fields match the disclosed contract', array_keys($payload) === ['install_id','site','name','plugin','wordpress','php','email']);
exit($failures ? 1 : 0);
