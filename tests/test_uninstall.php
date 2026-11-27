<?php
/** Uninstall must preserve editorial data by default and clear WP metadata caches via the API when opted in. */
define('WP_UNINSTALL_PLUGIN', true);
$GLOBALS['purge'] = false; $GLOBALS['removed_options'] = []; $GLOBALS['removed_meta'] = [];
function wp_clear_scheduled_hook($hook) {}
function delete_transient($key) {}
function delete_option($key) { $GLOBALS['removed_options'][] = $key; }
function get_option($key, $default = []) { return ['delete_data_on_uninstall'=>$GLOBALS['purge']]; }
function delete_post_meta_by_key($key) { $GLOBALS['removed_meta'][] = $key; }
class UninstallDB {
    public $prefix = 'test_'; public $queries = [];
    public function query($sql) { $this->queries[] = $sql; }
}
$wpdb = new UninstallDB();
$failed = 0;
function check($name, $value) { global $failed; if (!$value) {$failed++;} echo ($value?'OK ':'FAIL ') . $name . "\n"; }
include __DIR__ . '/../uninstall.php';
check('Default uninstall keeps registry tables', $wpdb->queries === []);
check('Default uninstall keeps article metadata', $GLOBALS['removed_meta'] === []);
check('Default uninstall keeps settings and identifier', !in_array('clem_settings',$GLOBALS['removed_options'],true) && !in_array('clem_install_id',$GLOBALS['removed_options'],true));
$GLOBALS['purge'] = true;
include __DIR__ . '/../uninstall.php';
check('Explicit cleanup drops only the two plugin tables', $wpdb->queries === ['DROP TABLE IF EXISTS test_clem_entities','DROP TABLE IF EXISTS test_clem_banned_sites']);
check('Explicit cleanup uses WordPress metadata deletion for all plugin keys', $GLOBALS['removed_meta'] === ['_clem_matches','_clem_banned_links','_clem_overrides']);
check('Explicit cleanup deletes settings, identifier and registration marker', in_array('clem_settings',$GLOBALS['removed_options'],true) && in_array('clem_install_id',$GLOBALS['removed_options'],true) && in_array('clem_registered_at',$GLOBALS['removed_options'],true));
exit($failed?1:0);
