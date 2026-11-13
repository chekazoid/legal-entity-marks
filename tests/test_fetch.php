<?php
/**
 * Результат обновления: канал считается успехом, копии требуют предупреждения.
 * Сеть и база заменены тестовыми объектами, цепочка fetch_all остаётся настоящей.
 * Запуск: php tests/test_fetch.php
 */
define('ABSPATH', '/tmp/');
define('LEM_TABLE', 'lem_entities');
define('LEM_DATA_DIR', __DIR__ . '/../data');
function get_option($key, $default = false) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['opts'][$key] = $value; }
function delete_option($key) { unset($GLOBALS['opts'][$key]); }
function current_time($format) { return '2026-11-13 12:00:00'; }
function lem() { return $GLOBALS['plugin']; }
require_once __DIR__ . '/../includes/class-lem-channel.php';
require_once __DIR__ . '/../includes/class-lem-importer.php';

class FetchTestChannel extends LEM_Channel {
    public $enabled = true;
    public $error = '';
    public function is_enabled() { return $this->enabled; }
    public function fetch($type) {
        return $this->error !== '' ? ['error' => $this->error]
            : ['entries' => [['name' => 'Тестовая запись']], 'count' => 1];
    }
    public function health() { return ['ok' => true, 'last_snapshot' => '2026-11-13', 'error' => '']; }
}
class FetchTestImporter extends LEM_Importer {
    public $failed = [];
    public $mirror_error = false;
    public $imports = [];
    private function official($type) {
        return in_array($type, $this->failed, true) ? ['error' => 'TEST official timeout ' . $type]
            : ['added' => 0, 'updated' => 1, 'total' => 1];
    }
    public function fetch_inoagents() { return $this->official('inoagent'); }
    public function fetch_extremist_orgs() { return $this->official('extremist'); }
    public function fetch_terrorist_orgs() { return $this->official('terrorist'); }
    public function fetch_undesirable_orgs() { return $this->official('undesirable'); }
    public function fetch_url($url, $timeout = 30) {
        return $this->mirror_error ? ['error' => 'TEST mirror timeout']
            : ['body' => json_encode([['name' => 'Тестовая копия']])];
    }
    public function import_entries(array $data, $type_override = null, $trusted = true) {
        $this->imports[] = [$type_override, $trusted];
        return ['added' => 0, 'updated' => count($data), 'total' => count($data)];
    }
    public function import_json($file, $type_override = null, $trusted = true) {
        return $this->import_entries([['name' => 'Тестовый встроенный перечень']], $type_override, $trusted);
    }
    public function import_banned_sites($file = null) { return ['added' => 0, 'skipped' => 0]; }
    public function apply_brand_aliases($file = null) { return ['applied' => 0]; }
}
function scenario(array $failed, $channel_error = '', $enabled = true, $mirror_error = false) {
    $GLOBALS['opts'] = ['lem_last_fetch_error' => 'old error', 'lem_last_fetch_diagnostics' => ['old']];
    $GLOBALS['wpdb'] = new class {
        public $prefix = 'wp_';
        public function get_var($sql) { return 1; }
    };
    $channel = new FetchTestChannel();
    $channel->enabled = $enabled;
    $channel->error = $channel_error;
    $GLOBALS['plugin'] = (object) [
        'channel' => $channel,
        'entities' => new class { public function flush_cache() {} },
        'rescan' => new class { public function enqueue($reason, $started) {} },
    ];
    $importer = new FetchTestImporter();
    $importer->failed = $failed;
    $importer->mirror_error = $mirror_error;
    $logs = [];
    $errors = $importer->fetch_all(static function ($msg) use (&$logs) { $logs[] = $msg; });
    return [$importer, $errors, $logs];
}
$fail = 0;
function check($description, $condition) {
    global $fail;
    if (!$condition) { $fail++; }
    echo ($condition ? 'OK  ' : 'FAIL ') . $description . "\n";
}

scenario([]);
check('Успех официальных источников очищает прошлую ошибку', get_option('lem_last_fetch_error', '') === '');
check('Успех официальных источников очищает диагностику', get_option('lem_last_fetch_diagnostics', []) === []);

[$importer, $errors, $logs] = scenario(['inoagent', 'terrorist', 'undesirable']);
check('Три реестра из канала не считаются ошибкой', $errors === []);
check('Старая общая ошибка удалена после успеха канала', get_option('lem_last_fetch_error', '') === '');
$sources = get_option('lem_last_fetch_sources');
check('Источники сохранены по каждому реестру', $sources === [
    'inoagent' => 'channel', 'extremist' => 'official', 'terrorist' => 'channel', 'undesirable' => 'channel',
]);
check('Все три выгрузки импортированы как доверенные', $importer->imports === [
    ['inoagent', true], ['terrorist', true], ['undesirable', true],
]);
$diagnostics = get_option('lem_last_fetch_diagnostics', []);
check('Причина отказа официального источника сохранена отдельно',
    ($diagnostics['inoagent'][0] ?? '') === 'TEST official timeout inoagent');
check('Не печатается ERROR при успешном переключении', strpos(implode("\n", $logs), 'ERROR:') === false);

[, $errors] = scenario(['inoagent', 'extremist', 'terrorist', 'undesirable']);
check('При полном отказе органов предупреждение только о зеркале', count($errors) === 1
    && strpos($errors[0], 'Экстремистские организации:') === 0
    && strpos($errors[0], 'Перечень взят из зеркала') !== false);

[$importer, $errors] = scenario(['inoagent', 'terrorist', 'undesirable'], 'TEST channel unavailable', true, true);
check('Отказ канала и зеркала оставляет предупреждения', count($errors) === 3
    && strpos(get_option('lem_last_fetch_error'), 'TEST channel unavailable') !== false
    && strpos(get_option('lem_last_fetch_error'), 'TEST mirror timeout') !== false);
check('Встроенные копии остаются недоверенными', $importer->imports === [
    ['inoagent', false], ['terrorist', false], ['undesirable', false],
]);
check('Диагностика сохраняет все отказы цепочки', (get_option('lem_last_fetch_diagnostics', [])['terrorist'] ?? []) === [
    'TEST official timeout terrorist', 'канал реестров: TEST channel unavailable',
    'зеркало недоступно (TEST mirror timeout)',
]);

[, $errors] = scenario(['inoagent'], '', false);
check('Выключенный канал не скрывает предупреждение', count($errors) === 1
    && strpos($errors[0], 'канал реестров выключен') !== false);

[, $errors] = scenario(['inoagent']);
check('Восстановившийся канал очищает предыдущие предупреждения', $errors === []
    && get_option('lem_last_fetch_error', '') === '');
check('Диагностика не накапливает отказы прошлых запусков', array_keys(get_option('lem_last_fetch_diagnostics', [])) === ['inoagent']);

echo $fail ? "\nПРОВАЛЕНО: $fail\n" : "\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";
exit($fail ? 1 : 0);
