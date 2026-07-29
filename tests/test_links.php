<?php
/**
 * Ссылки на ресурсы из реестров: что находится и что удаляется из текста.
 *
 * Самое опасное место плагина: ошибка здесь молча вырезает из статей живые
 * ссылки. Ссылаться на ресурсы иноагентов не запрещено, поэтому чистка обязана
 * их пропускать, даже когда в мете нет отметки (так у сайтов, сканировавших
 * архив до разделения реестров).
 *
 * Запуск: php tests/test_links.php
 */
define('ABSPATH', '/tmp/'); define('HOUR_IN_SECONDS', 3600);
define('LEM_BANNED_LINKS_META_KEY', '_lem_banned_links');
function add_action(){} function add_filter(){} function apply_filters($t, $v){ return $v; }
function esc_html($s){ return $s; } function esc_attr($s){ return $s; }

require_once __DIR__ . '/../includes/class-lem-banned-sites.php';
require_once __DIR__ . '/../includes/class-lem-link-scanner.php';

/** Реестр ресурсов: ключ такой же, каким сканер помечает найденную ссылку. */
class StubSites {
    public $index = [
        'primer-media.example'  => 'inoagent',
        'tihaya-gavan.example'  => 'undesirable',
        'ekstrem.example'       => 'extremist',
        'ruchnoy.example'       => '',            // добавлен вручную
        't.me/inoagent_channel' => 'inoagent',
        't.me/banned_channel'   => 'undesirable',
    ];
    public function type_of($key) { return $this->index[$key] ?? ''; }
    public function is_removable($type) {
        return $type === '' || in_array($type, ['extremist', 'terrorist', 'undesirable'], true);
    }
    public function get_accounts() {
        return [
            ['domain' => 't.me', 'account' => 'inoagent_channel'],
            ['domain' => 't.me', 'account' => 'banned_channel'],
        ];
    }
}
class StubPlugin { public $banned_sites; function __construct() { $this->banned_sites = new StubSites(); } }
function lem() { static $p = null; if ($p === null) { $p = new StubPlugin(); } return $p; }

$fail = 0;
function ok($d, $got, $want) {
    global $fail;
    $g = is_bool($got) ? ($got ? 'да' : 'нет') : (string) $got;
    $w = is_bool($want) ? ($want ? 'да' : 'нет') : (string) $want;
    $good = $g === $w;
    if (!$good) $fail++;
    printf("%s  %-52s %s\n", $good ? 'OK  ' : 'ФЕЙЛ', mb_substr($d, 0, 52),
        $good ? $g : "получили «{$g}», ждали «{$w}»");
}

$scanner  = new LEM_Link_Scanner();
$domains  = ['primer-media.example', 'tihaya-gavan.example', 'ekstrem.example', 'ruchnoy.example'];
$accounts = lem()->banned_sites->get_accounts();

$html = '<p>Писало <a href="https://primer-media.example/news/1">издание</a>, '
      . 'сообщал <a href="https://tihaya-gavan.example/report">фонд</a>, '
      . 'а также <a href="https://ekstrem.example/x">организация</a>. '
      . 'Домен из ручного списка: <a href="https://ruchnoy.example/y">тут</a>. '
      . 'Канал <a href="https://t.me/inoagent_channel/5">в телеграме</a> '
      . 'и <a href="https://t.me/banned_channel">другой</a>. '
      . 'Обычная <a href="https://example.com/page">ссылка</a>.</p>';

$found = $scanner->scan_post_content($html, $domains, $accounts);

echo "=== что нашёл сканер ===\n";
ok('всего найдено ссылок', count($found), 6);
$by_key = [];
foreach ($found as $l) { $by_key[$l['matched_domain']] = $l; }
ok('иноагент: реестр',        $by_key['primer-media.example']['registry'], 'inoagent');
ok('иноагент: не удаляется',  $by_key['primer-media.example']['removable'], false);
ok('нежелательная: удаляется', $by_key['tihaya-gavan.example']['removable'], true);
ok('экстремистская: удаляется', $by_key['ekstrem.example']['removable'], true);
ok('ручной домен: удаляется',  $by_key['ruchnoy.example']['removable'], true);
ok('канал иноагента: не удаляется', $by_key['t.me/inoagent_channel']['removable'], false);
ok('запрещённый канал: удаляется',  $by_key['t.me/banned_channel']['removable'], true);
ok('посторонняя ссылка не найдена', isset($by_key['example.com']) ? 'найдена' : 'нет', 'нет');

echo "\n=== отбор удаляемых ===\n";
ok('к удалению из шести', count(LEM_Link_Scanner::removable_only($found)), 4);

echo "\n=== чистка текста ===\n";
$clean = $scanner->remove_banned_links($html, $domains, $accounts);
ok('ссылка на иноагента цела',       strpos($clean, 'href="https://primer-media.example/news/1"') !== false, true);
ok('канал иноагента цел',            strpos($clean, 'href="https://t.me/inoagent_channel/5"') !== false, true);
ok('обычная ссылка цела',            strpos($clean, 'href="https://example.com/page"') !== false, true);
ok('нежелательная убрана',           strpos($clean, 'tihaya-gavan.example') === false, true);
ok('экстремистская убрана',          strpos($clean, 'ekstrem.example') === false, true);
ok('ручной домен убран',             strpos($clean, 'ruchnoy.example') === false, true);
ok('запрещённый канал убран',        strpos($clean, 'banned_channel') === false, true);
ok('текст удалённой ссылки остался', strpos($clean, 'фонд') !== false, true);

echo "\n=== мета, записанная до разделения реестров (отметки нет) ===\n";
$legacy_ino  = ['url' => 'https://primer-media.example/x', 'matched_domain' => 'primer-media.example'];
$legacy_und  = ['url' => 'https://tihaya-gavan.example/x', 'matched_domain' => 'tihaya-gavan.example'];
$legacy_gone = ['url' => 'https://udalyon.example/x',      'matched_domain' => 'udalyon.example'];
ok('старая ссылка на иноагента не удаляется',  LEM_Link_Scanner::link_is_removable($legacy_ino), false);
ok('старая ссылка на нежелательную удаляется', LEM_Link_Scanner::link_is_removable($legacy_und), true);
ok('домена уже нет в реестре: удаляется',      LEM_Link_Scanner::link_is_removable($legacy_gone), true);
ok('явная отметка важнее реестра',
    LEM_Link_Scanner::link_is_removable($legacy_ino + ['removable' => true]), true);

echo "\n=== разбор ссылок из HTML ===\n";
$links = $scanner->extract_links('<a href="https://a.example/x">раз</a> текст <a href=\'https://b.example\'>два</a>');
ok('нашлись обе ссылки', count($links), 2);
ok('хост первой',        $links[0]['host'], 'a.example');
ok('текст второй',       $links[1]['anchor'], 'два');

echo $fail ? "\nПРОВАЛЕНО: $fail\n" : "\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";
exit($fail ? 1 : 0);
