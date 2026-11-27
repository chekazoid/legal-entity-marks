<?php
require_once __DIR__ . '/wp-stubs.php';
/** Очередь физлиц: отключение, границы порций, повтор и смена снимка. Без сети и базы. */
define('ABSPATH','/tmp/');define('CLEM_TABLE','clem_entities');
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
function clem_plugin(){return $GLOBALS['plugin'];}
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
        if($this->cancel){clem_plugin()->settings['fedsfm_people_mode']='off';clem_plugin()->people->cancel();}
        if($this->response!==null)return $this->response;
        $entries=[];
        for($i=$offset;$i<min($offset+500,$this->total);$i++)$entries[]=['name'=>'Синтетический'.$i.' Иван Петрович','type'=>CLEM_People::TYPE,'is_person'=>1];
        return ['entries'=>$entries,'offset'=>$offset,'total'=>$this->total,'version'=>'synthetic-v1','fetched_at'=>'2026-11-24T00:00:00Z'];
    }
}
function setup_people($mode='mark'){
    $GLOBALS['options']=[];$GLOBALS['scheduled']=[];$GLOBALS['wpdb']=new PeopleTestDB();
    $GLOBALS['plugin']=new class {
        public $settings=['fedsfm_people_mode'=>'off'];public $people;public $channel;public $importer;public $entities;public $cache;public $rescan;
        public function get_settings(){return $this->settings;}
    };
    clem_plugin()->settings['fedsfm_people_mode']=$mode;
    clem_plugin()->channel=new PeopleTestChannel();
    clem_plugin()->importer=new class {public $calls=0;public $deferred=false;public function import_verified_entries($entries,$type,$generation,$defer=false){$this->calls++;$this->deferred=$defer;$GLOBALS['wpdb']->present=count($entries);$GLOBALS['wpdb']->active_total+=count($entries);return [];}};
    clem_plugin()->entities=new class {public function flush_cache(){}};
    clem_plugin()->cache=new class {public function purge_all_marked(){}};
    clem_plugin()->rescan=new class {public $calls=0;public function enqueue($reason,$started){$this->calls++;}};
    clem_plugin()->people=new CLEM_People();
}
function tick_people(){wp_clear_scheduled_hook(CLEM_People::HOOK);clem_plugin()->people->run_import();}
$fail=0;
function check_people($label,$condition){global $fail;if(!$condition)$fail++;echo($condition?'OK  ':'FAIL ').$label."\n";}
setup_people('off');clem_plugin()->people->queue();tick_people();
check_people('Выключенный режим не делает запросы',clem_plugin()->channel->calls===0&&!get_option(CLEM_People::STATE));
setup_people();clem_plugin()->channel->token=false;clem_plugin()->people->queue();
check_people('Без токена очередь не запускается',!get_option(CLEM_People::STATE)['running']&&clem_plugin()->channel->calls===0);
setup_people();clem_plugin()->people->queue();
check_people('Включение лишь планирует первый запрос',clem_plugin()->channel->calls===0&&wp_next_scheduled(CLEM_People::HOOK));
tick_people();$state=get_option(CLEM_People::STATE);
check_people('Один запуск берёт одну порцию',clem_plugin()->channel->calls===1&&$state['offset']===500&&$state['running']);
check_people('Новые записи до завершения не активируются',clem_plugin()->importer->deferred);
check_people('Неполный импорт не исключает прежних', $GLOBALS['wpdb']->removals===0&&clem_plugin()->rescan->calls===0);
clem_plugin()->channel->response=['error'=>'429','retry'=>true];tick_people();
check_people('429 не сдвигает порцию',get_option(CLEM_People::STATE)['offset']===500&&get_option(CLEM_People::STATE)['retries']===1);
clem_plugin()->channel->response=['error'=>'409','restart'=>true];tick_people();
check_people('Смена снимка начинает заново',get_option(CLEM_People::STATE)['offset']===0&&get_option(CLEM_People::STATE)['restarts']===1&&$GLOBALS['wpdb']->removals===0);
clem_plugin()->channel->response=null;$GLOBALS['wpdb']->active_total=0;tick_people();tick_people();
check_people('После всех страниц выполняется сверка',!get_option(CLEM_People::STATE)['running']&&get_option(CLEM_People::SUCCESS)['total']===501&&$GLOBALS['wpdb']->removals===1&&clem_plugin()->rescan->calls===1);
$GLOBALS['wpdb']->active_total=501;$calls=clem_plugin()->channel->calls;clem_plugin()->people->queue(true);tick_people();
check_people('Неизменившийся снимок сверяется одним запросом',clem_plugin()->channel->calls===$calls+1&&!get_option(CLEM_People::STATE)['running']&&$GLOBALS['wpdb']->removals===1);
$old=get_option(CLEM_People::STATE)['generation'];clem_plugin()->people->queue(true);
check_people('Два запуска в одну секунду имеют разные отметки',get_option(CLEM_People::STATE)['generation']!==$old);
setup_people();clem_plugin()->people->queue();clem_plugin()->channel->cancel=true;tick_people();
check_people('Выключение во время HTTP-запроса не импортирует ответ',clem_plugin()->importer->calls===0&&!get_option(CLEM_People::STATE));
setup_people();clem_plugin()->people->queue();
update_option(CLEM_People::LOCK,['token'=>'other','until'=>time()+120]);tick_people();
check_people('Параллельный запуск не делает второй запрос',clem_plugin()->channel->calls===0);
delete_option(CLEM_People::LOCK);clem_plugin()->channel->response=['entries'=>[],'offset'=>0,'total'=>501,'version'=>'v','fetched_at'=>'2026-11-24'];tick_people();
check_people('Пустая страница не исключает прежних',!get_option(CLEM_People::STATE)['running']&&$GLOBALS['wpdb']->removals===0);
setup_people();clem_plugin()->people->queue();$GLOBALS['wpdb']->broken=true;tick_people();
check_people('Ошибка базы не запускает исключение прежних',!get_option(CLEM_People::STATE)['running']&&$GLOBALS['wpdb']->removals===0);
setup_people();clem_plugin()->people->queue();clem_plugin()->channel->response=['error'=>'unavailable','retry'=>true];
for($i=0;$i<4;$i++)tick_people();
check_people('Повторы ограничены',!get_option(CLEM_People::STATE)['running']&&clem_plugin()->channel->calls===4);
setup_people();clem_plugin()->channel->total=32001;clem_plugin()->people->queue();
for($i=0;$i<65;$i++)tick_people();
check_people('Большой перечень проходит 65 отдельных порций',clem_plugin()->channel->calls===65&&get_option(CLEM_People::SUCCESS)['total']===32001&&$GLOBALS['wpdb']->removals===1);
echo $fail?"\nПРОВАЛЕНО: $fail\n":"\nВСЕ ПРОВЕРКИ ПРОЙДЕНЫ\n";exit($fail?1:0);
