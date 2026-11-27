<?php
/** Network contract: no statistics before explicit consent, including upgrades. */
require_once __DIR__ . '/wp-stubs.php';
define('ABSPATH', '/tmp/');
define('CLEM_VERSION', '1.15.1');
function add_action() {} function add_filter() {}
function wp_parse_args($saved, $defaults) { return array_merge($defaults, $saved); }
function get_option($key, $default = false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['opts'][$key] = $value; return true; }
function wp_generate_password($length, $special = false, $extra = false) { $GLOBALS['generated']++; return str_repeat('A', $length); }
function sanitize_url($value, $protocols) { return $value; }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function wp_remote_retrieve_response_code($value) { return $value['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function is_wp_error($value) { return false; }
function home_url($path = '') { return 'https://example.org' . $path; }
function get_bloginfo($field) { return $field === 'version' ? '7.1' : 'Test newsroom'; }
function sanitize_email($value) { return trim($value); }
function wp_json_encode($value) { return json_encode($value); }
function current_time($format, $utc = false) { return '2026-11-24 00:00:00'; }
class CLEM_Banned_Sites { const REMOVABLE_TYPES = ['extremist', 'terrorist', 'undesirable']; }
class CLEM_Http {
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
$GLOBALS['plugin'] = (new ReflectionClass('CLEM_Plugin'))->newInstanceWithoutConstructor();
function clem_plugin() { return $GLOBALS['plugin']; }
$channel = new CLEM_Channel();
$failures = 0;
function check($name, $ok) { global $failures; if (!$ok) { $failures++; } echo ($ok ? 'OK ' : 'FAIL ') . $name . "\n"; }
function headers() { $call = end(CLEM_Http::$calls); return $call['args']['headers'] ?? []; }
check('Fresh install has the channel and statistics off', !$channel->is_enabled() && !$channel->stats_enabled());
$channel->health();
$channel->fetch('inoagent');
$channel->fetch_people_page(0);
$channel->register_site('editor@example.org');
check('Every channel endpoint stays offline until consent', CLEM_Http::$calls === [] && $GLOBALS['generated'] === 0);
$GLOBALS['opts']['clem_settings'] = ['channel_enabled' => true, 'stats_enabled' => true, 'stats_consent_at' => '2026-11-24 00:00:00'];
$channel->health();
check('Prior enabled settings do not permit requests', CLEM_Http::$calls === []);
$GLOBALS['opts']['clem_settings']['channel_consent_url'] = CLEM_Channel::DEFAULT_URL;
$GLOBALS['opts']['clem_settings']['stats_consent_at'] = '';
$channel->health();
check('Registry consent alone does not enable counting', !$channel->stats_enabled() && headers() === [] && $GLOBALS['generated'] === 0);
$GLOBALS['opts']['clem_settings']['stats_consent_at'] = '2026-11-26 00:00:00';
$channel->health();
check('Explicit consent sends only the identifier and version', array_keys(headers()) === ['X-LEM-Install', 'X-LEM-Version']);
check('Identifier generated only once', $GLOBALS['generated'] === 1);
$id = headers()['X-LEM-Install'];
$channel->health();
check('Repeated request reuses the identifier', headers()['X-LEM-Install'] === $id && $GLOBALS['generated'] === 1);
$GLOBALS['opts']['clem_settings']['stats_enabled'] = false;
$channel->health();
check('Disabling consent immediately removes statistics headers', headers() === []);
check('Disabling does not delete local data', get_option('clem_install_id') === $id);
check('Ordinary channel requests never register a site', !in_array('post', array_column(CLEM_Http::$calls, 'method'), true));
$reply = $channel->register_site('editor@example.org');
$call = end(CLEM_Http::$calls); $payload = json_decode($call['args']['body'], true);
check('Only the explicit registration call sends site fields', $reply['ok'] && $call['method'] === 'post' && $payload['site'] === 'https://example.org/');
check('Registration does not enable statistics', !$channel->stats_enabled());
check('Registration fields match the disclosed contract', array_keys($payload) === ['install_id','site','name','plugin','wordpress','php','email']);
$count = count(CLEM_Http::$calls);
$GLOBALS['opts']['clem_settings']['channel_url'] = 'https://alternative.example/v1/';
$channel->health();
check('Changing the URL requires consent for the new endpoint', !$channel->is_enabled() && count(CLEM_Http::$calls) === $count);
exit($failures ? 1 : 0);
