<?php
define('ABSPATH','/tmp/'); function add_action(){} function add_filter(){}
require_once __DIR__ . '/../includes/class-lem-morphology.php';
require_once __DIR__ . '/../includes/class-lem-scanner.php';
$S=['match_word_forms'=>true,'surname_mode'=>'confirmed'];
$fail=0;
function t($d,$text,$ent,$s,$want){global $fail;$h=LEM_Scanner::match_entity($text,$ent,$s);$g=$h!==null;$ok=$g===$want;if(!$ok)$fail++;
  printf("%s  %-46s %s\n",$ok?'OK  ':'ФЕЙЛ',mb_substr($d,0,46),$g?'найдено «'.$h['matched_as'].'»':'не найдено');}

// Бренд-общеупотребительное слово: алиас хранится как «Дождь» (в ёлочках)
$dozhd=['id'=>1,'type'=>'inoagent','name'=>'Телеканал Дождь','aliases'=>['«Дождь»'],'is_person'=>0];
echo "=== quoted-бренд «Дождь» ===\n";
t('«Дождь» в ёлочках -> ловим','Об этом сообщил телеканал «Дождь» вчера',$dozhd,$S,true);
t('"Дождь" в прямых кавычках -> ловим','Об этом сообщил телеканал "Дождь"',$dozhd,$S,true);
t('шёл дождь (погода) -> НЕ ловим','Весь день шёл сильный дождь и ветер',$dozhd,$S,false);
t('дождь без кавычек в тексте -> НЕ ловим','Прогноз обещает дождь и грозу',$dozhd,$S,false);
t('полное «Телеканал Дождь» -> ловим','Об этом писал Телеканал Дождь в эфире',$dozhd,$S,true);

echo "\n=== обычный бренд «Медуза» (без требования кавычек) ===\n";
$med=['id'=>2,'type'=>'inoagent','name'=>'SIA «Medusa Project»','aliases'=>['Медуза','Meduza'],'is_person'=>0];
t('Медуза без кавычек -> ловим','Об этом сообщила Медуза со ссылкой',$med,$S,true);
t('«Медуза» в кавычках -> ловим','Об этом сообщила «Медуза»',$med,$S,true);

echo "\n=== «Проект» ===\n";
$pr=['id'=>3,'type'=>'inoagent','name'=>'Издание «Проект»','aliases'=>['«Проект»'],'is_person'=>0];
t('«Проект» в кавычках -> ловим','Расследование издания «Проект» вышло',$pr,$S,true);
t('проект без кавычек (обычное слово) -> НЕ ловим','Новый проект запустят весной',$pr,$S,false);

echo "\n".($fail===0?"ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n":"ПРОВАЛЕНО: $fail\n");
exit($fail===0?0:1);
