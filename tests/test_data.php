<?php
/**
 * Договор между комплектными данными и кодом.
 *
 * Появился после случая, когда у 82 доменов в data/banned-sites.json был
 * проставлен тип, а импортёр его молча выбрасывал: в админке нежелательные
 * организации выглядели как «добавлен вручную». Тест падает, если в данных
 * заводится поле, которого код не знает, или значение, которое он не примет.
 *
 * Запуск: php tests/test_data.php
 */
define('ABSPATH', '/tmp/'); define('HOUR_IN_SECONDS', 3600);
function add_action(){} function add_filter(){}
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower((string) $k)); }

require_once __DIR__ . '/../includes/class-lem-banned-sites.php';
require_once __DIR__ . '/../includes/class-lem-importer.php';

/** REGISTRY_TYPES живёт в LEM_Plugin, который тянет за собой весь WordPress. */
class LEM_Plugin { const REGISTRY_TYPES = ['inoagent', 'extremist', 'terrorist', 'undesirable']; }

$fail = 0;
function ok($d, $cond, $detail = '') {
    global $fail;
    if (!$cond) $fail++;
    printf("%s  %-52s %s\n", $cond ? 'OK  ' : 'ФЕЙЛ', mb_substr($d, 0, 52), $detail);
}
function load($file) {
    $path = __DIR__ . '/../data/' . $file;
    if (!file_exists($path)) { return null; }
    return json_decode(file_get_contents($path), true);
}

echo "=== запрещённые ресурсы: data/banned-sites.json ===\n";
$sites = load('banned-sites.json');
ok('файл читается', is_array($sites) && !empty($sites), is_array($sites) ? count($sites) . ' записей' : 'не разобрался');

if (is_array($sites)) {
    // Ключи, которые код действительно использует при импорте
    $known   = ['domain', 'label', 'type'];
    $unknown = [];
    foreach ($sites as $entry) {
        foreach (array_keys($entry) as $key) {
            if (!in_array($key, $known, true)) { $unknown[$key] = true; }
        }
    }
    ok('нет полей, которых код не знает', empty($unknown),
        empty($unknown) ? '' : 'лишние: ' . implode(', ', array_keys($unknown)));

    $bad_type = $no_domain = 0;
    foreach ($sites as $entry) {
        if (trim((string) ($entry['domain'] ?? '')) === '') { $no_domain++; }
        // Тип должен пережить очистку: иначе домен станет «добавленным вручную»
        if (LEM_Banned_Sites::clean_registry($entry['type'] ?? '') === '') { $bad_type++; }
    }
    ok('у всех записей есть домен', $no_domain === 0, $no_domain ? "без домена: $no_domain" : '');
    ok('у всех записей понятный реестр', $bad_type === 0, $bad_type ? "без реестра: $bad_type" : '');

    // Домен должен разбираться так же, как при вставке в базу
    $broken = [];
    foreach ($sites as $entry) {
        $t = LEM_Banned_Sites::split_target($entry['domain']);
        if ($t['domain'] === '') { $broken[] = $entry['domain']; }
    }
    ok('все домены разбираются', empty($broken),
        empty($broken) ? '' : 'сломаны: ' . implode(', ', array_slice($broken, 0, 3)));
}

echo "\n=== брендовые связки: data/brand-aliases.json ===\n";
$brands = load('brand-aliases.json');
ok('файл читается', is_array($brands) && !empty($brands), is_array($brands) ? count($brands) . ' правил' : 'не разобрался');

if (is_array($brands)) {
    $known   = ['match', 'aliases', 'quoted', 'note', 'enabled'];
    $unknown = [];
    $empty_match = $empty_alias = 0;
    foreach ($brands as $rule) {
        foreach (array_keys($rule) as $key) {
            if (!in_array($key, $known, true)) { $unknown[$key] = true; }
        }
        if (trim((string) ($rule['match'] ?? '')) === '') { $empty_match++; }
        if (empty($rule['aliases']) && empty($rule['quoted'])) { $empty_alias++; }
    }
    ok('нет полей, которых код не знает', empty($unknown),
        empty($unknown) ? '' : 'лишние: ' . implode(', ', array_keys($unknown)));
    ok('у всех правил есть что искать', $empty_match === 0, $empty_match ? "пустых: $empty_match" : '');
    ok('у всех правил есть что добавить', $empty_alias === 0, $empty_alias ? "пустых: $empty_alias" : '');
}

echo "\n=== реестры организаций ===\n";
$files = [
    'foreign-agents-raw.json' => 'inoagent',
    'extremist-orgs.json'     => 'extremist',
    'terrorist-orgs.json'     => 'terrorist',
    'undesirable-orgs.json'   => 'undesirable',
];
foreach ($files as $file => $type) {
    $data = load($file);
    if (!is_array($data)) { ok("$file читается", false, 'нет файла или битый JSON'); continue; }

    $junk = $no_name = 0;
    foreach ($data as $entry) {
        $name = trim((string) ($entry['name'] ?? $entry['fullName'] ?? ''));
        if ($name === '') { $no_name++; continue; }
        // Обрывки описаний со страниц Минюста в комплект попадать не должны
        if (LEM_Importer::is_junk_name($name)) { $junk++; }
    }
    ok("$file: записи с именем", $no_name === 0, count($data) . " записей" . ($no_name ? ", без имени: $no_name" : ''));
    ok("$file: без обрывков описаний", $junk === 0, $junk ? "мусорных: $junk" : '');
}

echo $fail ? "\nПРОВАЛЕНО: $fail\n" : "\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";
exit($fail ? 1 : 0);
