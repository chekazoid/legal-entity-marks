<?php
define('ABSPATH','/tmp/'); function add_action(){} function add_filter(){}
require_once __DIR__ . '/../includes/class-lem-morphology.php';
require_once __DIR__ . '/../includes/class-lem-scanner.php';
$S=['match_word_forms'=>true,'surname_mode'=>'confirmed'];
$fail=0;
function t($d,$text,$e,$s,$want){global $fail;$h=LEM_Scanner::match_entity($text,$e,$s);$g=$h!==null;$ok=$g===$want;if(!$ok)$fail++;
 printf("%s  %-48s %s\n",$ok?'OK  ':'ФЕЙЛ',mb_substr($d,0,48),$g?'найдено «'.$h['matched_as'].'»':'не найдено');}
$rs=['id'=>1,'type'=>'inoagent','name'=>'Радио Свободная Европа/Радио Свобода','aliases'=>['Радио Свобода'],'is_person'=>0];
echo "=== кавычки между словами названия ===\n";
t('обычный пробел',            'опередить Радио Свобода и выпустить', $rs,$S,true);
t('кавычки внутри: Радио „Свобода“', 'опередить «Радио „Свобода“» и выпустить', $rs,$S,true);
t('ёлочки внутри: Радио «Свобода»',  'материал Радио «Свобода» вышел', $rs,$S,true);
t('перенос строки между словами',    "опередить Радио\nСвобода вчера", $rs,$S,true);
t('чужое слово между -> НЕ ловим',   'Радио и Свобода это разные вещи', $rs,$S,false);
echo "\n".($fail===0?"ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n":"ПРОВАЛЕНО: $fail\n");
exit($fail===0?0:1);
