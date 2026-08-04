<?php
/**
 * Канал реестров: соответствие типов и разбор отказов.
 *
 * Сетевую часть здесь не проверяем - для неё есть кнопка «Проверить канал»
 * в настройках и живой прогон на стенде. Здесь то, что должно работать
 * без сети: какие реестры канал ведёт и что показать администратору,
 * когда он ответил не так, как ждали.
 *
 * Запуск: php tests/test_channel.php
 */
define('ABSPATH', '/tmp/'); define('LEM_VERSION', 'test');
function add_action(){} function add_filter(){}
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function home_url($p = '') { return 'https://example.org' . $p; }
function get_bloginfo($k) { return $k === 'name' ? 'Тестовая редакция' : '7.0.1'; }
function sanitize_email($e) { return trim((string) $e); }
function wp_generate_password($len = 12) { return str_repeat('a', $len); }
function get_option($k, $d = '') { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
$GLOBALS['opts'] = [];

require_once __DIR__ . '/../includes/class-lem-channel.php';

$fail = 0;
function eq($d, $got, $want) {
    global $fail;
    $g = is_bool($got) ? ($got ? 'да' : 'нет') : (string) $got;
    $w = is_bool($want) ? ($want ? 'да' : 'нет') : (string) $want;
    $ok = $g === $w;
    if (!$ok) $fail++;
    printf("%s  %-50s %s\n", $ok ? 'OK  ' : 'ФЕЙЛ', mb_substr($d, 0, 50),
        $ok ? $g : "получили «{$g}», ждали «{$w}»");
}

echo "=== какие реестры канал ведёт ===\n";
eq('иноагенты',      LEM_Channel::supports('inoagent'), true);
eq('нежелательные',  LEM_Channel::supports('undesirable'), true);
eq('террористические', LEM_Channel::supports('terrorist'), true);
// В канале такого реестра нет, для него остаётся встроенный перечень
eq('экстремистские - нет',  LEM_Channel::supports('extremist'), false);
// Перечень Росфинмониторинга не подключаем: тридцать тысяч физлиц
eq('Росфинмониторинг - нет', LEM_Channel::supports('fedsfm'), false);
eq('выдуманный тип - нет',   LEM_Channel::supports('нечто'), false);

echo "\n=== адреса выгрузок ===\n";
$map = LEM_Channel::REGISTRY_MAP;
eq('иноагенты -> inoagent',       $map['inoagent'], 'inoagent');
eq('нежелательные -> undesirable', $map['undesirable'], 'undesirable');
eq('террористические -> terrorist', $map['terrorist'], 'terrorist');

echo "\n=== разбор отказов канала ===\n";
$explain = new ReflectionMethod('LEM_Channel', 'explain_code');
$explain->setAccessible(true);
$say = static function ($code, $body = '') use ($explain) {
    return $explain->invoke(null, $code, $body);
};

// Администратор должен различать «не настроено» и «доступ отозван»
// 401 приходит только от перечня Росфинмониторинга: выгрузки трёх реестров открыты
$e401 = $say(401, '{"detail":"нужен заголовок Authorization: Bearer <токен>"}');
eq('401 говорит, что реестру нужен токен', mb_strpos($e401, 'требует токен') !== false, true);
$e403 = $say(403, '{"detail":"токен не признан"}');
eq('403 говорит о токене',    mb_strpos($e403, 'токен не признан или отозван') !== false, true);
eq('403 приводит ответ канала', mb_strpos($e403, 'токен не признан') !== false, true);
$e404 = $say(404, '{"detail":"нет такого реестра"}');
eq('404 про отсутствие реестра', mb_strpos($e404, 'нет такого реестра') !== false, true);
$e429 = $say(429);
eq('429 про частоту',          mb_strpos($e429, 'слишком частые') !== false, true);
$e500 = $say(500);
eq('прочее показывает код',    mb_strpos($e500, '500') !== false, true);
// Мусор вместо JSON не должен ломать сообщение
eq('битый ответ не ломает',    $say(502, '<html>oops') !== '', true);

echo "\n=== таймаут ===\n";
// Выгрузка иноагентов - четверть мегабайта, пяти секунд по умолчанию мало
eq('не меньше 30 секунд', LEM_Channel::TIMEOUT >= 30, true);

echo "\n=== что уходит при регистрации сайта ===\n";
// Состав жёстко зафиксирован: он показан человеку на странице настроек,
// и любое новое поле должно попадать сначала туда, а не тихо в запрос
$channel = new LEM_Channel();
$payload = $channel->registration_payload('red@example.org');
$declared = ['install_id', 'site', 'name', 'plugin', 'wordpress', 'php', 'email'];
sort($declared);
$actual = array_keys($payload);
sort($actual);
eq('состав ровно тот, что заявлен', implode(',', $actual), implode(',', $declared));
eq('адрес сайта на месте',   $payload['site'], 'https://example.org/');
eq('почта только та, что ввели', $payload['email'], 'red@example.org');
eq('почта не выдумывается',  $channel->registration_payload()['email'], '');

echo "\n=== идентификатор установки ===\n";
$id = $channel->install_id();
eq('выдан',                  $id !== '', true);
eq('постоянен',              $channel->install_id(), $id);
eq('в нём нет адреса сайта', mb_stripos($id, 'example') === false, true);

echo $fail ? "\nПРОВАЛЕНО: $fail\n" : "\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";
exit($fail ? 1 : 0);
