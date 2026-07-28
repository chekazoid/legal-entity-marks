<?php
/**
 * Разбор записей реестра: очистка названий, алиасы, домены из поля Минюста.
 * Запуск: php tests/test_import.php
 */
define('ABSPATH','/tmp/'); define('HOUR_IN_SECONDS', 3600);
function add_action(){} function add_filter(){}
function esc_html($s){return $s;} function esc_attr($s){return $s;}
require_once __DIR__ . '/../includes/class-lem-banned-sites.php';
require_once __DIR__ . '/../includes/class-lem-importer.php';

$fail = 0;
function eq($d, $got, $want) {
    global $fail;
    $g = is_array($got) ? implode(' | ', $got) : (string) $got;
    $w = is_array($want) ? implode(' | ', $want) : (string) $want;
    $ok = $g === $w;
    if (!$ok) $fail++;
    printf("%s  %-46s %s\n", $ok ? 'OK  ' : 'ФЕЙЛ', mb_substr($d, 0, 46),
        $ok ? $g : "получили «{$g}», ждали «{$w}»");
}

echo "=== хвост страны и организационно-правовой формы ===\n";
eq('Cultural Vistas (США)',      LEM_Importer::strip_legal_suffix('Cultural Vistas (США)'), 'Cultural Vistas');
eq('Eurasianet, США',            LEM_Importer::strip_legal_suffix('Eurasianet, США'), 'Eurasianet');
eq('Hidemy.network Ltd.',        LEM_Importer::strip_legal_suffix('Hidemy.network Ltd.'), 'Hidemy.network');
eq('Dekoder gGmbH, ФРГ (два хвоста)', LEM_Importer::strip_legal_suffix('Dekoder gGmbH, ФРГ'), 'Dekoder');
eq('Mnenie media z.s., Чешская Республика', LEM_Importer::strip_legal_suffix('Mnenie media z.s., Чешская Республика'), 'Mnenie media');
eq('без хвоста не трогаем',      LEM_Importer::strip_legal_suffix('Медиазона'), 'Медиазона');
eq('США внутри названия остаётся', LEM_Importer::strip_legal_suffix('Радио США сегодня'), 'Радио США сегодня');

echo "\n=== обрывки описания вместо названия ===\n";
$junk = [
    'Организация исключена в связи с ликвидацией юридического лица',
    'эмблема Партии представляет собой стилизованное изображение',
    'В соответствии с Уставом организация действует на территории',
    'Решением Верховного Суда признана экстремистской',
];
foreach ($junk as $j) {
    eq(mb_substr($j, 0, 40), LEM_Importer::is_junk_name($j) ? 'мусор' : 'название', 'мусор');
}
$real = ['Медиазона', 'Международное религиозное объединение «Свидетели Иеговы»', 'Артподготовка'];
foreach ($real as $r) {
    eq($r, LEM_Importer::is_junk_name($r) ? 'мусор' : 'название', 'название');
}

echo "\n=== свои домены из поля со ссылками (field_6_s) ===\n";
eq('DOXA: сайт + соцсети',
    LEM_Importer::extract_own_domains('https://doxa.team/; https://www.instagram.com/doxa_journal/; https://t.me/doxajournal'),
    ['doxa.team']);
eq('Вёрстка: www отбрасывается',
    LEM_Importer::extract_own_domains('https://www.verstka.media/ https://t.me/verstka'),
    ['verstka.media']);
eq('только соцсети -> пусто',
    LEM_Importer::extract_own_domains('https://t.me/some_channel; https://youtube.com/@some'),
    []);
eq('поддомен соцсети тоже отсекается',
    LEM_Importer::extract_own_domains('https://music.apple.com/ru/podcast; https://example.org/'),
    ['example.org']);
eq('пустое поле',            LEM_Importer::extract_own_domains(''), []);
eq('текст вместо ссылок',    LEM_Importer::extract_own_domains('сведения отсутствуют'), []);
eq('два своих домена',
    LEM_Importer::extract_own_domains('https://zona.media/; https://mediazona.ca/'),
    ['zona.media', 'mediazona.ca']);

echo "\n=== аккаунты организации на чужих площадках ===\n";
function acc($list) {
    return array_map(static function ($a) { return $a['domain'] . '/' . $a['account']; }, $list);
}
eq('телеграм и инстаграм DOXA',
    acc(LEM_Importer::extract_social_accounts('https://doxa.team/; https://www.instagram.com/doxa_journal/; https://t.me/doxajournal')),
    ['instagram.com/doxa_journal', 't.me/doxajournal']);
eq('канал YouTube через /channel/',
    acc(LEM_Importer::extract_social_accounts('https://www.youtube.com/channel/UCabc123def')),
    ['youtube.com/ucabc123def']);
eq('YouTube через @',
    acc(LEM_Importer::extract_social_accounts('https://youtube.com/@doxajournal')),
    ['youtube.com/doxajournal']);
eq('ролик YouTube аккаунтом не считается',
    acc(LEM_Importer::extract_social_accounts('https://www.youtube.com/watch?v=abc')), []);
eq('свой сайт в аккаунты не идёт',
    acc(LEM_Importer::extract_social_accounts('https://doxa.team/articles/1')), []);
eq('t.me/s/name - служебный сегмент пропускаем',
    acc(LEM_Importer::extract_social_accounts('https://t.me/s/verstka')), ['t.me/verstka']);

echo "\n=== разбор того, что вводит пользователь ===\n";
$sp = LEM_Banned_Sites::split_target('t.me/doxajournal');
eq('t.me/doxajournal', $sp['domain'] . ' + ' . $sp['account'], 't.me + doxajournal');
$sp = LEM_Banned_Sites::split_target('https://www.Example.ORG/some/page');
eq('обычный сайт: путь игнорируется', $sp['domain'] . ' + ' . $sp['account'], 'example.org + ');
$sp = LEM_Banned_Sites::split_target('vk.com/doxa_journal');
eq('vk.com/doxa_journal', $sp['domain'] . ' + ' . $sp['account'], 'vk.com + doxa_journal');
$sp = LEM_Banned_Sites::split_target('не ссылка вовсе');
eq('мусор вместо адреса', $sp['domain'] . ' + ' . $sp['account'], ' + ');

echo "\n=== сопоставление ссылки с запрещённым аккаунтом ===\n";
$accounts = [
    ['domain' => 't.me', 'account' => 'doxajournal'],
    ['domain' => 'youtube.com', 'account' => 'verstka'],
];
function m($url, $accounts) {
    $host = parse_url($url, PHP_URL_HOST);
    $r = LEM_Banned_Sites::match_account($host, $url, $accounts);
    return $r === null ? 'нет' : $r;
}
eq('ссылка на запрещённый канал',      m('https://t.me/doxajournal', $accounts), 't.me/doxajournal');
eq('ссылка на пост в этом канале',     m('https://t.me/doxajournal/1478', $accounts), 't.me/doxajournal');
eq('чужой телеграм-канал не трогаем',  m('https://t.me/kremlin', $accounts), 'нет');
eq('телеграм вообще без аккаунта',     m('https://t.me/', $accounts), 'нет');
eq('YouTube через @ и www',            m('https://www.youtube.com/@verstka', $accounts), 'youtube.com/verstka');
eq('чужой ролик на YouTube',           m('https://www.youtube.com/watch?v=xyz', $accounts), 'нет');
eq('регистр в имени аккаунта',         m('https://t.me/DoxaJournal', $accounts), 't.me/doxajournal');

echo "\n=== алиасы из названия ===\n";
eq('содержимое кавычек',
    LEM_Importer::generate_aliases('Новостной портал «DOXA»'), ['DOXA']);
eq('хвост страны даёт алиас',
    LEM_Importer::generate_aliases('Eurasianet, США'), ['Eurasianet']);
eq('кириллический короткий -> в ёлочках',
    LEM_Importer::generate_aliases('Проект «Центр «Досье»'), ['«Досье»']);
eq('физлицо: Фамилия Имя без кавычек',
    LEM_Importer::generate_aliases('Иванов Иван Иванович', true), ['Иванов Иван']);
eq('физлицо с псевдонимом',
    LEM_Importer::generate_aliases('Федоров Мирон Янович «Оксимирон»', true), ['Федоров Мирон', '«Оксимирон»']);

echo $fail ? "\nПРОВАЛЕНО: $fail\n" : "\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";
exit($fail ? 1 : 0);
