<?php
require_once __DIR__ . '/wp-stubs.php';
/**
 * Updates use only consented Observer snapshots and retain data on failures.
 * Запуск: php tests/test_fetch.php
 */
define('ABSPATH', '/tmp/');
define('CLEM_TABLE', 'clem_entities');
define('CLEM_DATA_DIR', __DIR__ . '/../data');
function get_option($key, $default = false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['opts'][$key] = $value; }
function delete_option($key) { unset($GLOBALS['opts'][$key]); }
function current_time($format) { return '2026-11-13 12:00:00'; }
function clem_plugin() { return $GLOBALS['plugin']; }
require_once __DIR__ . '/../includes/class-lem-channel.php';
require_once __DIR__ . '/../includes/class-lem-importer.php';

class FetchTestChannel extends CLEM_Channel {
    public $enabled = true;
    public $failed = [];
    public $requested = [];

    public function is_enabled() { return $this->enabled; }
    public function fetch($type) {
        $this->requested[] = $type;
        if (in_array($type, $this->failed, true)) {
            return ['error' => 'TEST channel timeout'];
        }
        return ['entries' => [['name' => 'Тестовая запись']], 'count' => 1, 'meta' => ['complete' => true]];
    }
}

class FetchTestImporter extends CLEM_Importer {
    public $imports = [];

    public function import_snapshot(array $data, $type, array $meta) {
        $this->imports[] = [$type, $meta['complete']];
        return ['added' => 0, 'updated' => count($data), 'total' => count($data)];
    }
    public function import_banned_sites($file = null) { return ['added' => 0, 'skipped' => 0]; }
    public function apply_brand_aliases($file = null) { return ['applied' => 0]; }
}
function scenario(array $failed = [], $enabled = true) {
    $GLOBALS['opts'] = [
        'clem_last_fetch_error' => 'old error',
        'clem_last_fetch_time' => '2026-11-12 12:00:00',
        'clem_last_fetch_diagnostics' => ['old'],
        'clem_registry_snapshot_extremist' => ['version' => 'previous'],
    ];
    $GLOBALS['wpdb'] = new class {
        public $prefix = 'wp_';
        public function prepare($sql, ...$args) { return $sql; }
        public function get_var($sql) { return 1; }
    };
    $channel = new FetchTestChannel();
    $channel->enabled = $enabled;
    $channel->failed = $failed;
    $GLOBALS['plugin'] = (object) [
        'channel' => $channel,
        'entities' => new class { public function flush_cache() {} },
        'rescan' => new class { public function enqueue($reason, $started) {} },
        'cache' => new class { public function purge_all_marked() {} },
        'people' => new class { public function queue() {} },
    ];
    $importer = new FetchTestImporter();
    return [$importer, $importer->fetch_all(), $channel->requested];
}
$fail = 0;
function check($description, $condition) {
    global $fail;
    if (!$condition) { $fail++; }
    echo ($condition ? 'OK  ' : 'FAIL ') . $description . "\n";
}

$types = CLEM_Registry_Sync::TYPES;
[$importer, $errors, $requested] = scenario();
check('All four complete snapshots are imported through Observer', $errors === [] && $requested === $types
    && $importer->imports === array_map(static function ($type) { return [$type, true]; }, $types));
check('Successful update clears previous warnings', get_option('clem_last_fetch_error', '') === ''
    && get_option('clem_last_fetch_diagnostics', []) === []);

[$importer, $errors, $requested] = scenario(['extremist', 'terrorist']);
$sources = get_option('clem_last_fetch_sources');
check('Partial failure keeps one verified list and one local list', $sources['extremist'] === 'retained'
    && $sources['terrorist'] === 'local' && count($errors) === 2 && $requested === $types);
check('Failed categories do not trigger substitute imports', array_column($importer->imports, 0) === ['inoagent', 'undesirable']);
check('Diagnostics identify the failing categories', array_keys(get_option('clem_last_fetch_diagnostics')) === ['extremist', 'terrorist']);

[$importer, $errors] = scenario($types);
check('Complete failure preserves the last successful refresh time', count($errors) === 4 && $importer->imports === []
    && get_option('clem_last_fetch_time') === '2026-11-12 12:00:00');

[$importer, $errors, $requested] = scenario([], false);
check('Without consent, no request or import takes place', $requested === [] && $importer->imports === []
    && $errors === [CLEM_Channel::disabled_message()]);
exit($fail ? 1 : 0);
