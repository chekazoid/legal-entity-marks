<?php
require_once __DIR__ . '/wp-stubs.php';
/** Очередь физлиц: отключение, границы порций, повтор и смена снимка. Без сети и базы. */
define('ABSPATH','/tmp/');define('LEM_TABLE','lem_entities');
function add_action() {}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function add_option($key,$value,$deprecated='',$autoload=false){if(array_key_exists($key,$GLOBALS['options']))return false;$GLOBALS['options'][$key]=$value;return true;}
function current_time($format){return '2026-11-24 12:00:00';}
function wp_generate_password($length=24,$special=false,$extra=false){static $id=0;return str_pad((string)++$id,$length,'a');}
function wp_next_scheduled($hook){return $GLOBALS['scheduled'][$hook]??false;}
function wp_schedule_single_event($time,$hook){$GLOBALS['scheduled'][$hook]=$time;}
function wp_clear_scheduled_hook($hook){unset($GLOBALS['scheduled'][$hook]);}
function lem(){return $GLOBALS['plugin'];}
require_once __DIR__.'/../includes/class-lem-people.php';
class PeopleTestDB {
    public $prefix='wp_';public $options='wp_options';public $present=0;public $active_total=0;public $removals=0;public $broken=false;
    public function prepare($sql,...$args){return ['sql'=>$sql,'args'=>$args];}
    public function suppress_errors($value){return false;}
    public function query($query){if(is_string($query))return 1;if(strpos($query['sql'],'SET is_active = IF')!==false)$this->removals++;return $this->broken?false:1;}
    public function get_var($query){if(strpos($query['sql'],'SELECT ENGINE')!==false)return 'InnoDB';if(strpos($query['sql'],'LOCK(')!==false)return 1;return strpos($query['sql'],'name IN')!==false?$this->present:$this->active_total;}
}
class PeopleTestChannel {
    public $calls=0;public $token=true;public $total=501;public $response=null;public $cancel=false;
    public function is_enabled(){return true;}public function has_token(){return $this->token;}
    public function fetch_people_page($offset,$version){
        $this->calls++;
        if($this->cancel){lem()->settings['fedsfm_people_mode']='off';lem()->people->cancel();}
        if($this->response!==null)return $this->response;
        $entries=[];
        for($i=$offset;$i<min($offset+500,$this->total);$i++)$entries[]=['name'=>'Синтетический'.$i.' Иван Петрович','type'=>LEM_People::TYPE,'is_person'=>1];
        return ['entries'=>$entries,'offset'=>$offset,'total'=>$this->total,'version'=>'synthetic-v1','fetched_at'=>'2026-11-24T00:00:00Z'];
    }
}
function setup_people($mode='mark'){
    $GLOBALS['options']=[];$GLOBALS['scheduled']=[];$GLOBALS['wpdb']=new PeopleTestDB();
    $GLOBALS['plugin']=new class {
        public $settings=['fedsfm_people_mode'=>'off'];public $people;public $channel;public $importer;public $entities;public $cache;public $rescan;
        public function get_settings(){return $this->settings;}
    };
    lem()->settings['fedsfm_people_mode']=$mode;
    lem()->channel=new PeopleTestChannel();
    lem()->importer=new class {public $calls=0;public $deferred=false;public function import_verified_entries($entries,$type,$generation,$defer=false){$this->calls++;$this->deferred=$defer;$GLOBALS['wpdb']->present=count($entries);$GLOBALS['wpdb']->active_total+=count($entries);return [];}};
    lem()->entities=new class {public function flush_cache(){}};
    lem()->cache=new class {public function purge_all_marked(){}};
    lem()->rescan=new class {public $calls=0;public function enqueue($reason,$started){$this->calls++;}};
    lem()->people=new LEM_People();
}
function tick_people(){wp_clear_scheduled_hook(LEM_People::HOOK);lem()->people->run_import();}
$fail=0;
function check_people($label,$condition){global $fail;if(!$condition)$fail++;echo($condition?'OK  ':'FAIL ').$label."\n";}
setup_people('off');lem()->people->queue();tick_people();
check_people('Выключенный режим не делает запросы',lem()->channel->calls===0&&!get_option(LEM_People::STATE));
setup_people();lem()->channel->token=false;lem()->people->queue();
check_people('Без токена очередь не запускается',!get_option(LEM_People::STATE)['running']&&lem()->channel->calls===0);
setup_people();lem()->people->queue();
check_people('Включение лишь планирует первый запрос',lem()->channel->calls===0&&wp_next_scheduled(LEM_People::HOOK));
tick_people();$state=get_option(LEM_People::STATE);
check_people('Один запуск берёт одну порцию',lem()->channel->calls===1&&$state['offset']===500&&$state['running']);
check_people('Новые записи до завершения не активируются',lem()->importer->deferred);
check_people('Неполный импорт не исключает прежних', $GLOBALS['wpdb']->removals===0&&lem()->rescan->calls===0);
lem()->channel->response=['error'=>'429','retry'=>true];tick_people();
check_people('429 не сдвигает порцию',get_option(LEM_People::STATE)['offset']===500&&get_option(LEM_People::STATE)['retries']===1);
lem()->channel->response=['error'=>'409','restart'=>true];tick_people();
check_people('Смена снимка начинает заново',get_option(LEM_People::STATE)['offset']===0&&get_option(LEM_People::STATE)['restarts']===1&&$GLOBALS['wpdb']->removals===0);
lem()->channel->response=null;$GLOBALS['wpdb']->active_total=0;tick_people();tick_people();
check_people('После всех страниц выполняется сверка',!get_option(LEM_People::STATE)['running']&&get_option(LEM_People::SUCCESS)['total']===501&&$GLOBALS['wpdb']->removals===1&&lem()->rescan->calls===1);
$GLOBALS['wpdb']->active_total=501;$calls=lem()->channel->calls;lem()->people->queue(true);tick_people();
check_people('Неизменившийся снимок сверяется одним запросом',lem()->channel->calls===$calls+1&&!get_option(LEM_People::STATE)['running']&&$GLOBALS['wpdb']->removals===1);
$old=get_option(LEM_People::STATE)['generation'];lem()->people->queue(true);
check_people('Два запуска в одну секунду имеют разные отметки',get_option(LEM_People::STATE)['generation']!==$old);
setup_people();lem()->people->queue();lem()->channel->cancel=true;tick_people();
check_people('Выключение во время HTTP-запроса не импортирует ответ',lem()->importer->calls===0&&!get_option(LEM_People::STATE));
setup_people();lem()->people->queue();
update_option(LEM_People::LOCK,['token'=>'other','until'=>time()+120]);tick_people();
check_people('Параллельный запуск не делает второй запрос',lem()->channel->calls===0);
delete_option(LEM_People::LOCK);lem()->channel->response=['entries'=>[],'offset'=>0,'total'=>501,'version'=>'v','fetched_at'=>'2026-11-24'];tick_people();
check_people('Пустая страница не исключает прежних',!get_option(LEM_People::STATE)['running']&&$GLOBALS['wpdb']->removals===0);
setup_people();lem()->people->queue();$GLOBALS['wpdb']->broken=true;tick_people();
check_people('Ошибка базы не запускает исключение прежних',!get_option(LEM_People::STATE)['running']&&$GLOBALS['wpdb']->removals===0);
setup_people();lem()->people->queue();lem()->channel->response=['error'=>'unavailable','retry'=>true];
for($i=0;$i<4;$i++)tick_people();
check_people('Повторы ограничены',!get_option(LEM_People::STATE)['running']&&lem()->channel->calls===4);
setup_people();lem()->channel->total=32001;lem()->people->queue();
for($i=0;$i<65;$i++)tick_people();
check_people('Большой перечень проходит 65 отдельных порций',lem()->channel->calls===65&&get_option(LEM_People::SUCCESS)['total']===32001&&$GLOBALS['wpdb']->removals===1);
echo $fail?"\nПРОВАЛЕНО: $fail\n":"\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";exit($fail?1:0);
