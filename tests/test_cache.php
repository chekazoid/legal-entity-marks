<?php
/** All marked pages must be invalidated, including those past the previous 5000-page cap. */
require_once __DIR__.'/wp-stubs.php';
define('ABSPATH','/tmp/');define('CLEM_META_KEY','_clem_entities');
function do_action($name,...$args){$GLOBALS['cache_actions'][]=[$name,$args];}
function get_permalink($id) { return 'https://example.test/' . $id; }
function wp_cache_delete($id, $group) { $GLOBALS['deleted_cache_keys'][] = [$id, $group]; }
function wp_cache_flush_group($group) { $GLOBALS['flushed_cache_groups'][] = $group; }
require_once __DIR__.'/../includes/class-lem-cache.php';
$GLOBALS['wpdb']=new class {
    public $postmeta='wp_postmeta';public $calls=0;
    public function prepare($sql,...$args){return $args[1];}
    public function get_results($cursor){$this->calls++;$rows=[];for($i=$cursor+1;$i<=min($cursor+500,5001);$i++){$rows[]=(object)['meta_id'=>$i,'post_id'=>$i+100];}return $rows;}
};
$cache=new class extends CLEM_Cache {public $ids=[];public function purge_post($id){$this->ids[]=$id;}};
$count=$cache->purge_all_marked();
$ok=$count===5001&&count(array_unique($cache->ids))===5001&&end($cache->ids)===5101&&$GLOBALS['wpdb']->calls===11&&$GLOBALS['cache_actions'][0][1]===[5001];
echo $ok?"PASS All 5001 marked pages invalidated in bounded batches\n":"FAIL Cache invalidation lost pages\n";
(new CLEM_Cache())->purge_post(42);
$targeted = $GLOBALS['deleted_cache_keys'] === [[42, 'posts']]
    && empty($GLOBALS['flushed_cache_groups'])
    && end($GLOBALS['cache_actions']) === ['clem_purge_post_cache', [42, 'https://example.test/42']];
echo $targeted ? "PASS Single-post purge preserves unrelated post objects and emits the page-cache hook\n"
    : "FAIL Single-post purge had a wider impact or skipped the page-cache hook\n";
exit($ok && $targeted ? 0 : 1);
