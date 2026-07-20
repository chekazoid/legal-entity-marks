<?php
define('ABSPATH', '/tmp/');
require_once __DIR__ . '/../includes/class-lem-morphology.php';

$fail = 0;

function check($desc, $got, $want) {
    global $fail;
    $ok = in_array($want, $got, true);
    if (!$ok) { $fail++; }
    printf("%s  %-38s нужна форма «%s»%s\n",
        $ok ? 'OK  ' : 'ФЕЙЛ', $desc, $want,
        $ok ? '' : ' | получено: ' . implode(', ', array_slice($got, 0, 8)));
}

echo "=== определение рода ===\n";
$genders = [
    'Апахончич Дарья Александровна' => 'f',
    'Пономарев Лев Александрович'   => 'm',
    'Иванов Иван Иванович'          => 'm',
    'Савицкая Людмила Алексеевна'   => 'f',
    'Достоевский Фёдор Михайлович'  => 'm',
    'Толстая Татьяна'               => 'f',
    'Шмидт Мария'                   => 'f',
];
foreach ($genders as $name => $want) {
    $got = LEM_Morphology::detect_gender($name);
    $ok  = $got === $want;
    if (!$ok) { $fail++; }
    printf("%s  %-38s ожидался=%s получен=%s\n", $ok ? 'OK  ' : 'ФЕЙЛ', $name, $want, $got);
}

echo "\n=== мужские фамилии: родительный падеж («по словам …») ===\n";
$m_gen = [
    'Иванов'      => 'Иванова',
    'Медведев'    => 'Медведева',
    'Пугачёв'     => 'Пугачёва',
    'Ильин'       => 'Ильина',
    'Птицын'      => 'Птицына',
    'Достоевский' => 'Достоевского',
    'Троцкий'     => 'Троцкого',
    'Толстой'     => 'Толстого',
    'Шмидт'       => 'Шмидта',
    'Ковальчук'   => 'Ковальчука',
    'Гоголь'      => 'Гоголя',
    'Пономарев'   => 'Пономарева',
];
foreach ($m_gen as $sur => $want) {
    check($sur, LEM_Morphology::surname_forms($sur, 'm'), $want);
}

echo "\n=== мужские фамилии: творительный («с …») ===\n";
$m_ins = [
    'Иванов'      => 'Ивановым',
    'Достоевский' => 'Достоевским',
    'Шмидт'       => 'Шмидтом',
    'Гоголь'      => 'Гоголем',
    'Толстой'     => 'Толстым',
];
foreach ($m_ins as $sur => $want) {
    check($sur, LEM_Morphology::surname_forms($sur, 'm'), $want);
}

echo "\n=== женские фамилии ===\n";
check('Иванова (род.)',      LEM_Morphology::surname_forms('Иванова', 'f'), 'Ивановой');
check('Иванова (вин.)',      LEM_Morphology::surname_forms('Иванова', 'f'), 'Иванову');
check('Савицкая (род.)',     LEM_Morphology::surname_forms('Савицкая', 'f'), 'Савицкой');
check('Савицкая (вин.)',     LEM_Morphology::surname_forms('Савицкая', 'f'), 'Савицкую');

echo "\n--- женская на согласную НЕ склоняется:\n";
$ap = LEM_Morphology::surname_forms('Апахончич', 'f');
$ok = (count($ap) === 1 && $ap[0] === 'Апахончич');
if (!$ok) { $fail++; }
printf("%s  Апахончич (жен) -> %s\n", $ok ? 'OK  ' : 'ФЕЙЛ', implode(', ', $ap));
$ap_m = LEM_Morphology::surname_forms('Апахончич', 'm');
$ok = in_array('Апахончича', $ap_m, true);
if (!$ok) { $fail++; }
printf("%s  Апахончич (муж) -> есть «Апахончича»: %s\n", $ok ? 'OK  ' : 'ФЕЙЛ', $ok ? 'да' : 'нет');

echo "\n=== несклоняемые ===\n";
foreach (['Шевченко', 'Черных', 'Долгих', 'Живаго', 'Дурново'] as $sur) {
    $f = LEM_Morphology::surname_forms($sur, 'm');
    $ok = count($f) === 1;
    if (!$ok) { $fail++; }
    printf("%s  %-12s -> %s\n", $ok ? 'OK  ' : 'ФЕЙЛ', $sur, implode(', ', $f));
}

echo "\n=== имена ===\n";
check('Иван (род.)',    LEM_Morphology::first_name_forms('Иван', 'm'), 'Ивана');
check('Иван (твор.)',   LEM_Morphology::first_name_forms('Иван', 'm'), 'Иваном');
check('Андрей (род.)',  LEM_Morphology::first_name_forms('Андрей', 'm'), 'Андрея');
check('Игорь (твор.)',  LEM_Morphology::first_name_forms('Игорь', 'm'), 'Игорем');
check('Дарья (род.)',   LEM_Morphology::first_name_forms('Дарья', 'f'), 'Дарьи');
check('Дарья (вин.)',   LEM_Morphology::first_name_forms('Дарья', 'f'), 'Дарью');
check('Мария (род.)',   LEM_Morphology::first_name_forms('Мария', 'f'), 'Марии');
check('Ольга (род.)',   LEM_Morphology::first_name_forms('Ольга', 'f'), 'Ольги');
check('Анна (род.)',    LEM_Morphology::first_name_forms('Анна', 'f'), 'Анны');
check('Людмила (твор.)',LEM_Morphology::first_name_forms('Людмила', 'f'), 'Людмилой');

echo "\n=== защита от ложных срабатываний ===\n";
foreach ([['Белый', false], ['Мороз', false], ['Ким', false], ['Иванов', true], ['Апахончич', true]] as [$s, $want]) {
    $got = LEM_Morphology::surname_is_searchable($s);
    $ok  = $got === $want;
    if (!$ok) { $fail++; }
    printf("%s  %-12s искать по одной фамилии: %s\n", $ok ? 'OK  ' : 'ФЕЙЛ', $s, $got ? 'да' : 'нет');
}

echo "\n" . ($fail === 0 ? "ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n" : "ПРОВАЛЕНО: $fail\n");
exit($fail === 0 ? 0 : 1);
