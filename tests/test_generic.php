<?php
/**
 * Алиасы из одних общеупотребительных слов ищутся только в кавычках.
 * Реальный случай: у нежелательной организации из реестра есть альтернативное
 * название «Служба поддержки», и оно помечало обычный оборот речи в статье.
 */
define('ABSPATH', '/tmp/');
function add_action() {}
function add_filter() {}
require_once __DIR__ . '/../includes/class-lem-morphology.php';
require_once __DIR__ . '/../includes/class-lem-scanner.php';

$S    = ['match_word_forms' => true, 'surname_mode' => 'confirmed'];
$fail = 0;

function t($desc, $text, $entity, $settings, $want) {
    global $fail;
    $hit = LEM_Scanner::match_entity($text, $entity, $settings);
    $got = $hit !== null;
    $ok  = ($got === $want);
    if (!$ok) { $fail++; }
    printf("%s  %-54s %s\n", $ok ? 'OK  ' : 'ФЕЙЛ', mb_substr($desc, 0, 54),
        $got ? 'найдено «' . $hit['matched_as'] . '»' : 'не найдено');
}

function g($desc, $term, $want) {
    global $fail;
    $got = LEM_Scanner::is_generic_phrase($term);
    $ok  = ($got === $want);
    if (!$ok) { $fail++; }
    printf("%s  %-40s только в кавычках: %s\n", $ok ? 'OK  ' : 'ФЕЙЛ',
        mb_substr($term, 0, 40), $got ? 'да' : 'нет');
}

$helpdesk = [
    'id' => 1, 'type' => 'undesirable', 'is_person' => 0,
    'name' => 'Nodibinājums «Helpdesk Media Foundation» («Медиа-фонд «Служба поддержки», «Служба поддержки») (Латвийская Республика)',
    'aliases' => ['Helpdesk Media Foundation', 'Служба поддержки', 'Латвийская Республика'],
];

echo "=== алиас из общих слов не ловит обычную речь ===\n";
t('«служба поддержки для журналистов» -> не помечаем',
  'Скорая медиапомощь - служба поддержки для журналистов.', $helpdesk, $S, false);
t('«Служба поддержки» в кавычках -> помечаем',
  'Организация «Служба поддержки» помогла изданию.', $helpdesk, $S, true);
t('латинское название -> помечаем как раньше',
  'Фонд Helpdesk Media Foundation признан нежелательным.', $helpdesk, $S, true);
t('«Латвийская Республика» в обычном тексте -> не помечаем',
  'Компания зарегистрирована в стране Латвийская Республика.', $helpdesk, $S, false);
t('полное название из реестра -> помечаем',
  'В перечень внесён Nodibinājums «Helpdesk Media Foundation» («Медиа-фонд «Служба поддержки», «Служба поддержки») (Латвийская Республика).',
  $helpdesk, $S, true);

echo "\n=== эвристика: что считается общей фразой ===\n";
g('два общих слова',            'Служба поддержки', true);
g('страна',                     'Латвийская Республика', true);
g('три общих слова',            'Центр защиты прав', true);
g('различимое название',        'Радио Свобода', false);
g('бренд издания',              'Вёрстка Медиа', false);
g('редкое слово внутри',        'Национал-большевистская партия', false);
g('длинное название (4 слова)', 'Международная федерация за права человека', false);
g('одно слово',                 'Поддержки', false);

echo "\n" . ($fail === 0 ? "ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n" : "ПРОВАЛЕНО: $fail\n");
exit($fail === 0 ? 0 : 1);
