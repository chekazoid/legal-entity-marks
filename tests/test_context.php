<?php
/**
 * Проверка контекстного правила на реальной вёрстке Gutenberg.
 * Запуск: php test_context.php
 */
define('ABSPATH', '/tmp/');
function add_action() {}
function add_filter() {}

require_once __DIR__ . '/../includes/class-lem-scanner.php';
require_once __DIR__ . '/../includes/class-lem-frontend.php';

$TRIGGERS = ['blockquote' => true, 'link' => true, 'quotes' => true, 'embed' => true];

// Иноагент-персона и организация
$person = ['id' => 1, 'name' => 'Иванов Иван Иванович', 'aliases' => ['Иванов Иван'], 'type' => 'inoagent'];
$org    = ['id' => 2, 'name' => 'Медиазона', 'aliases' => [], 'type' => 'inoagent'];

function in_context($html, $entity, $triggers) {
    $ctx = LEM_Scanner::extract_context_text($html, $triggers);
    foreach (LEM_Scanner::search_terms($entity) as $term) {
        if (LEM_Scanner::word_match($ctx, $term) !== false) {
            return true;
        }
    }
    return false;
}

$cases = [
    // [описание, HTML, сущность, ожидаемый результат]
    ['голое упоминание фамилии в абзаце',
     '<p>На заседании выступил Иванов Иван Иванович и покинул зал.</p>',
     'person', false],

    ['фамилия внутри блока цитаты',
     '<p>Заседание прошло бурно.</p><blockquote class="wp-block-quote"><p>Иванов Иван сказал, что не согласен</p></blockquote>',
     'person', true],

    ['подводка перед цитатой (имя в предыдущем абзаце)',
     '<p>Об этом заявил Иванов Иван Иванович:</p><blockquote class="wp-block-quote"><p>Решение считаю ошибочным</p></blockquote>',
     'person', true],

    ['подпись после цитаты (cite в следующем блоке)',
     '<blockquote class="wp-block-quote"><p>Решение считаю ошибочным</p></blockquote><p>Иванов Иван, юрист</p>',
     'person', true],

    ['имя внутри гиперссылки',
     '<p>Подробности в <a href="https://example.org/post">материале Иванова Ивана</a>.</p>',
     'person', false],

    ['имя в абзаце с гиперссылкой',
     '<p>Иванов Иван Иванович <a href="https://example.org">опубликовал отчёт</a> вчера.</p>',
     'person', true],

    ['имя в абзаце с прямой речью',
     '<p>Иванов Иван Иванович заявил: «Я не согласен с этим решением суда».</p>',
     'person', true],

    ['короткие кавычки не считаются прямой речью',
     '<p>Иванов Иван Иванович получил премию «Ника» в прошлом году.</p>',
     'person', false],

    // Регрессия: закавыченный ТЕРМИН это не цитата человека. Из-за него
    // абзац засчитывался как контекст и любой иноагент в нём получал метку.
    ['закавыченный термин «иностранным агентом» не цитата',
     '<p>В январе Минюст объявил Иванова Ивана Ивановича «иностранным агентом».</p>',
     'person', false],

    ['закавыченный термин «нежелательной» не цитата',
     '<p>Иванова Ивана Ивановича штрафовали за участие в «нежелательной» организации.</p>',
     'person', false],

    ['имя во встроенном посте с подводкой',
     '<p>Иванов Иван Иванович написал в соцсети:</p><figure class="wp-block-embed is-provider-twitter"><div class="wp-block-embed__wrapper">https://twitter.com/x/status/1</div></figure>',
     'person', true],

    ['упоминание далеко от цитаты другого человека',
     '<p>Иванов Иван Иванович не участвовал.</p><p>Обычный абзац без ничего.</p><p>Ещё абзац текста тут.</p><blockquote><p>Совсем другая цитата Петрова</p></blockquote>',
     'person', false],

    ['организация в домене ссылки',
     '<p>Ссылка на источник: <a href="https://mediazona.example/article">материал</a>.</p>',
     'org', false],

    ['организация в тексте ссылки',
     '<p>Об этом писала <a href="https://example.org">Медиазона</a> в марте.</p>',
     'org', true],

    ['организация голым упоминанием',
     '<p>Ранее об этом сообщала Медиазона со ссылкой на источники.</p>',
     'org', false],
];

$fail = 0;
foreach ($cases as [$desc, $html, $who, $expect]) {
    $entity = $who === 'person' ? $GLOBALS['person'] : $GLOBALS['org'];
    $got    = in_context($html, $entity, $TRIGGERS);
    $ok     = ($got === $expect);
    if (!$ok) {
        $fail++;
    }
    printf("%s  %-52s ожидалось=%-5s получено=%s\n",
        $ok ? 'OK  ' : 'ФЕЙЛ',
        mb_substr($desc, 0, 52),
        $expect ? 'да' : 'нет',
        $got ? 'да' : 'нет'
    );
}

echo "\n--- правило отбора should_mark ---\n";
$settings = [
    'registries'            => ['inoagent', 'extremist', 'terrorist', 'undesirable'],
    'inoagent_context_only' => true,
];
$none = ['excluded' => [], 'forced' => []];

$rules = [
    ['иноагент вне контекста',            ['id'=>1,'type'=>'inoagent','in_context'=>false], $settings, $none, false],
    ['иноагент в контексте',              ['id'=>1,'type'=>'inoagent','in_context'=>true],  $settings, $none, true],
    ['экстремистская вне контекста',      ['id'=>3,'type'=>'extremist'],                    $settings, $none, true],
    ['иноагент вне контекста, но forced', ['id'=>1,'type'=>'inoagent','in_context'=>false], $settings, ['excluded'=>[],'forced'=>[1]], true],
    ['иноагент в контексте, но excluded', ['id'=>1,'type'=>'inoagent','in_context'=>true],  $settings, ['excluded'=>[1],'forced'=>[]], false],
    ['excluded важнее forced',            ['id'=>1,'type'=>'inoagent','in_context'=>false], $settings, ['excluded'=>[1],'forced'=>[1]], false],
    ['старая мета без in_context',        ['id'=>1,'type'=>'inoagent'],                     $settings, $none, true],
];
foreach ($rules as [$desc, $match, $st, $ov, $expect]) {
    $got = LEM_Frontend::should_mark($match, $st, $ov);
    $ok  = ($got === $expect);
    if (!$ok) { $fail++; }
    printf("%s  %-52s ожидалось=%-5s получено=%s\n",
        $ok ? 'OK  ' : 'ФЕЙЛ', mb_substr($desc, 0, 52),
        $expect ? 'да' : 'нет', $got ? 'да' : 'нет');
}

$st_off = ['registries' => ['extremist'], 'inoagent_context_only' => false];
$got = LEM_Frontend::should_mark(['id'=>1,'type'=>'inoagent'], $st_off, $none);
$ok = ($got === false);
if (!$ok) { $fail++; }
printf("%s  %-52s ожидалось=нет   получено=%s\n", $ok ? 'OK  ' : 'ФЕЙЛ',
    'выключенная категория иноагентов', $got ? 'да' : 'нет');

echo "\n" . ($fail === 0 ? "ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n" : "ПРОВАЛЕНО ПРОВЕРОК: $fail\n");
exit($fail === 0 ? 0 : 1);
