<?php
/** Добровольное подключение физлиц и защита от неполных совпадений ФИО. */
define('ABSPATH', '/tmp/');
function add_action() {} function add_filter() {}
function apply_filters($name, $value) { return $value; }
function wp_parse_args($saved, $defaults) { return array_merge($defaults, $saved); }
function get_option($key, $default = false) { return $GLOBALS['opts'][$key] ?? $default; }
class LEM_Banned_Sites { const REMOVABLE_TYPES = ['extremist','terrorist','undesirable']; }
require_once __DIR__ . '/../includes/class-lem-channel.php';
require_once __DIR__ . '/../includes/class-lem-plugin.php';
require_once __DIR__ . '/../includes/class-lem-morphology.php';
require_once __DIR__ . '/../includes/class-lem-scanner.php';
require_once __DIR__ . '/../includes/class-lem-frontend.php';
$GLOBALS['opts'] = [];
$GLOBALS['test_plugin'] = (new ReflectionClass('LEM_Plugin'))->newInstanceWithoutConstructor();
function lem() { return $GLOBALS['test_plugin']; }
$fail = 0;
function check($label, $condition) {
    global $fail;
    if (!$condition) { $fail++; }
    echo ($condition ? 'OK  ' : 'FAIL ') . $label . "\n";
}
function settings($mode = 'off', $profile = 'media', $extra = []) {
    $GLOBALS['opts']['lem_settings'] = array_merge([
        'fedsfm_people_mode'=>$mode,'preset'=>$profile,
    ], $extra);
    return lem()->get_settings();
}
function hit($text, $entity, $settings) {
    LEM_Scanner::flush_pattern_cache();
    return LEM_Scanner::match_entity($text, $entity, $settings) !== null;
}
foreach (['media','nonmedia','manual'] as $profile) {
    $s = settings('off', $profile, ['mark_registries'=>['fedsfm_person'],'track_registries'=>['fedsfm_person']]);
    check($profile . ': профиль не включает физлиц', !in_array('fedsfm_person',$s['mark_registries'],true)
        && !in_array('fedsfm_person',$s['track_registries'],true));
}
check('Неизвестный режим выключает функцию', settings('unknown')['fedsfm_people_mode'] === 'off');
$s = settings('track');
check('Отслеживание включается независимо от профиля', in_array('fedsfm_person',$s['track_registries'],true)
    && !in_array('fedsfm_person',$s['mark_registries'],true));
$s = settings('mark');
check('Маркировка включается только отдельным выбором', in_array('fedsfm_person',$s['mark_registries'],true));
check('Физлица не попадают в чистку ссылок', !in_array('fedsfm_person',settings('mark','manual',[
    'link_registries'=>['fedsfm_person']
])['link_registries'],true));
$person = ['id'=>901,'type'=>'fedsfm_person','name'=>'Иванов Иван Петрович',
           'aliases'=>['Иванов','Иван Иванов'],'is_person'=>1,'is_active'=>1];
$s = settings('mark','media',['surname_mode'=>'always']);
$cases = [
    ['Полное ФИО', 'В суд явился Иванов Иван Петрович.', true],
    ['Обратный порядок', 'Иван Петрович Иванов дал комментарий.', true],
    ['Склонение всех частей', 'О деле Иванова Ивана Петровича сообщили вчера.', true],
    ['Обратный порядок в падеже', 'Беседа с Иваном Петровичем Ивановым.', true],
    ['Одна фамилия', 'Заместитель мэра Иванов доложил о ремонте.', false],
    ['Имя и фамилия', 'Иван Иванов преподаёт в школе.', false],
    ['Фамилия и имя', 'Иванов Иван выиграл соревнование.', false],
    ['Другое отчество', 'Иванов Иван Сергеевич получил награду.', false],
    ['Другое имя', 'Иванов Сергей Петрович вышел на работу.', false],
    ['Инициалы', 'Иванов И. П. приехал на заседание.', false],
    ['Звёздочка не подтверждает фамилию', 'Иванов* выступил в эфире.', false],
    ['Составная фамилия', 'Иван Петрович Иванов-Сидоров получил письмо.', false],
];
foreach ($cases as [$label,$text,$want]) { check($label, hit($text,$person,$s) === $want); }
check('Выключенный режим не ищет даже полное ФИО', !hit('Иванов Иван Петрович',$person,settings('off')));
check('Только отслеживание сохраняет полный поиск', hit('Иванов Иван Петрович',$person,settings('track')));
$yo = $person; $yo['name']='Семёнов Пётр Фёдорович'; $yo['id']=902;
$s=settings('mark');
check('Е и ё в полном ФИО', hit('Семенов Петр Федорович',$yo,$s));
check('Отсев по основам не теряет е/ё', (bool)array_intersect_key(
    LEM_Scanner::entity_stems($yo),LEM_Scanner::text_stems('Семенов Петр Федорович')));
$scanner = new LEM_Scanner();
$found = $scanner->scan_text('<p>Иванов сообщил. Затем выступил Иванов Иван Петрович.</p>',[$person]);
check('В результат попадает полное упоминание, а не первая фамилия', count($found)===1
    && $found[0]['matched_as']==='Иванов Иван Петрович' && !empty($found[0]['full_name_match']));
check('Ручная звёздочка не обходит полное ФИО', LEM_Scanner::asterisk_hit('Иванов*',$person,$s)===null);
$overrides=['excluded'=>[],'forced'=>[901]];
$match=['id'=>901,'type'=>'fedsfm_person','full_name_match'=>true];
check('Полное ФИО маркируется в выбранном режиме', LEM_Frontend::should_mark($match,settings('mark'),$overrides));
check('Отслеживание не маркируется даже при ручном force', !LEM_Frontend::should_mark($match,settings('track'),$overrides));
check('Выключение убирает старые метки даже при ручном force', !LEM_Frontend::should_mark($match,settings('off'),$overrides));
$match['full_name_match']=false;
check('Частичное совпадение нельзя принудительно промаркировать', !LEM_Frontend::should_mark($match,settings('mark'),$overrides));
$old = $person; $old['type']='inoagent';
check('Поиск иноагентов по имени и фамилии сохранён', hit('Иван Иванов',$old,settings('off')));
check('Новая формулировка описывает включение в перечень', strpos(LEM_Frontend::disclaimer_text($person),'включён(-а) в перечень Росфинмониторинга')!==false);
echo $fail ? "\nПРОВАЛЕНО: $fail\n" : "\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";
exit($fail ? 1 : 0);
