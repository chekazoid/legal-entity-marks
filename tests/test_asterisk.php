<?php
define('ABSPATH','/tmp/'); function add_action(){} function add_filter(){}
require_once __DIR__ . '/../includes/class-lem-morphology.php';
require_once __DIR__ . '/../includes/class-lem-scanner.php';
$S=['match_word_forms'=>true,'surname_mode'=>'confirmed'];
$fail=0;
function scan($text,$ents,$s){ // эмулируем scan_text проход по нескольким сущностям
  $plain=$text; $has_star=mb_strpos($plain,'*')!==false; $out=[];
  $mode=$s['surname_mode'];
  foreach($ents as $e){
    $strict=LEM_Scanner::build_pattern($e,$s,'strict');
    $sh=$strict?(preg_match($strict,$plain)?true:false):false;
    $allow=($mode==='always')||($mode==='confirmed'&&$sh);
    $hit=LEM_Scanner::match_entity($plain,$e,$s,$allow);
    if(!$hit && $has_star) $hit=LEM_Scanner::asterisk_hit($plain,$e,$s);
    if($hit) $out[]=$hit['matched_as'];
  }
  return $out;
}
function t($d,$text,$ents,$s,$want_contains,$want){global $fail;
  $r=scan($text,$ents,$s);
  $has=in_array($want_contains,array_map(fn($x)=>mb_strpos($x,$want_contains)!==false?$want_contains:$x,$r)) || (bool)array_filter($r,fn($x)=>mb_strpos($x,$want_contains)!==false);
  $ok=($has===$want);if(!$ok)$fail++;
  printf("%s  %-52s -> [%s]\n",$ok?'OK  ':'ФЕЙЛ',mb_substr($d,0,52),implode(', ',$r));}

$mon=['id'=>1,'type'=>'inoagent','name'=>'Монгайт Анна Викторовна','aliases'=>['Монгайт Анна'],'is_person'=>1];
echo "=== одинокая фамилия со звёздочкой (полного имени НЕТ) ===\n";
t('«Монгайт**» без полного имени -> ловим по звёздочке','в telegram-канале Монгайт** сообщила',[$mon],$S,'Монгайт',true);
t('«Монгайт» без звёздочки и без полного имени -> НЕ ловим','по данным Монгайт стало известно',[$mon],$S,'Монгайт',false);
t('«Анна Монгайт» без звёздочки -> ловим (полное имя)','Анна Монгайт уехала в Европу',[$mon],$S,'Монгайт',true);

echo "\n=== quoted-бренд без кавычек, но со звёздочкой ===\n";
$d=['id'=>2,'type'=>'inoagent','name'=>'Телеканал Дождь','aliases'=>['«Дождь»'],'is_person'=>0];
t('«Дождь**» без кавычек, со звёздочкой -> ловим','продюсер телеканала Дождь** уехал',[$d],$S,'Дождь',true);
t('«дождь» без кавычек и без звёздочки -> НЕ ловим (погода)','весь день шёл дождь',[$d],$S,'Дождь',false);
t('«Дождь» в кавычках -> ловим','телеканала «Дождь» сообщили',[$d],$S,'Дождь',true);

echo "\n=== рискованная фамилия «Белый» со звёздочкой -> всё равно НЕ ловим одну ===\n";
$bel=['id'=>3,'type'=>'inoagent','name'=>'Белый Андрей Иванович','aliases'=>[],'is_person'=>1];
t('«белый**» одна рискованная фамилия -> НЕ ловим','покрасил в белый** цвет',[$bel],$S,'елы',false);
t('«Андрей Белый**» -> ловим (полное имя)','поэт Андрей Белый** писал',[$bel],$S,'Белый',true);

echo "\n".($fail===0?"ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n":"ПРОВАЛЕНО: $fail\n");
exit($fail===0?0:1);
