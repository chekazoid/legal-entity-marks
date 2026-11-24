<?php
/** Run with: wp eval-file wp-content/plugins/legal-entity-marks/tests/integration-wordpress.php */
defined('ABSPATH') || exit;
global $wpdb;
$option_rows = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'lem\\_%' OR option_name LIKE '\\_transient\\_lem\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lem\\_%' OR option_name = 'cron'", ARRAY_A);
$original_get = $_GET; $original_post = $_POST;
$entity_id = $site_id = $post_id = 0;
$checks = 0;
$assert = static function ($name, $value) use (&$checks) {
    if (!$value) { throw new RuntimeException($name); }
    $checks++; echo 'PASS ' . $name . "\n";
};
$http_calls = [];
$http_stub = static function ($pre, $args, $url) use (&$http_calls) {
    if (strpos($url, LEM_Channel::DEFAULT_URL) !== 0) { return $pre; }
    $http_calls[] = $args;
    return ['headers' => [], 'body' => '{"ok":true,"last_snapshot":"2026-11-24"}', 'response' => ['code'=>200,'message'=>'OK'], 'cookies'=>[]];
};
add_filter('pre_http_request', $http_stub, 10, 3);
try {
    wp_set_current_user(1);
    $_GET = [];
    require_once LEM_DIR . 'admin/class-lem-admin.php';
    $admin = new LEM_Admin();
    $settings = lem()->get_settings();
    $settings['stats_enabled'] = true;
    unset($settings['stats_consent_at']);
    lem()->update_settings($settings);
    $assert('Legacy enabled setting is inactive without consent', !lem()->channel->stats_enabled());
    lem()->channel->health();
    $assert('Real WordPress HTTP request has no installation header', !isset(end($http_calls)['headers']['X-LEM-Install']));
    $base_form = [
        'lem_settings_nonce' => wp_create_nonce('lem_save_settings'),
        'lem_channel_enabled' => '1', 'lem_channel_url' => LEM_Channel::DEFAULT_URL,
        'lem_fedsfm_people_mode' => 'off', 'lem_preset' => 'media',
    ];
    $_POST = $base_form + ['lem_stats_enabled' => '1'];
    $admin->handle_actions();
    $assert('Submitting a valid consent form stores the consent time', lem()->channel->stats_enabled() && get_option('lem_settings')['stats_consent_at'] !== '');
    lem()->channel->health();
    $assert('Consented request includes identifier and version', isset(end($http_calls)['headers']['X-LEM-Install']) && end($http_calls)['headers']['X-LEM-Version'] === LEM_VERSION);
    $_POST = ['lem_settings_nonce' => 'invalid'] + $base_form;
    $admin->handle_actions();
    $assert('Invalid nonce cannot change consent', lem()->channel->stats_enabled());
    $_POST = $base_form;
    $admin->handle_actions();
    $assert('An unchecked consent checkbox disables counting', !lem()->channel->stats_enabled() && get_option('lem_settings')['stats_consent_at'] === '');
    lem()->channel->health();
    $assert('Opt-out immediately removes both statistics headers', !isset(end($http_calls)['headers']['X-LEM-Install']) && !isset(end($http_calls)['headers']['X-LEM-Version']));
    $assert('The privacy test used no real registration request', count($http_calls) === 3);

    $tag = 'WporgAudit' . wp_generate_password(10, false, false);
    $name = $tag . " O'Reilly % _";
    $entity_id = lem()->entities->insert(['type'=>'inoagent','name'=>$name,'aliases'=>[],'is_person'=>0,'is_active'=>1]);
    $assert('A quoted synthetic registry entry can be inserted', $entity_id > 0);
    $found = lem()->entities->search(['search'=>$name, 'type'=>'inoagent', 'orderby'=>'name; DROP TABLE invalid']);
    $assert('Literal quotes and LIKE wildcards remain data in entity searches', $found['total'] === 1 && (int) $found['items'][0]['id'] === (int) $entity_id);
    $assert('SQL-looking search text does not expand a result', lem()->entities->search(['search'=>$tag . "' OR 1=1 --"])['total'] === 0);
    $domain = strtolower($tag) . '.example';
    $site_id = lem()->banned_sites->insert(['domain'=>$domain,'label'=>$name,'registry'=>'undesirable']);
    $assert('A synthetic resource can be inserted', $site_id > 0);
    $found = lem()->banned_sites->search(['search'=>$name]);
    $assert('Literal wildcards and quotes remain data in resource searches', $found['total'] === 1 && (int) $found['items'][0]['id'] === (int) $site_id);
    $assert('Entity pagination retains the total', lem()->entities->search(['search'=>$tag,'limit'=>1,'offset'=>1])['items'] === []);

    register_post_type('lem_audit_test', ['public'=>false,'supports'=>['title','editor']]);
    $settings = lem()->get_settings(); $settings['post_types'] = ['lem_audit_test'];
    $settings['auto_scan_on_publish'] = false; $settings['link_registries'] = ['undesirable'];
    lem()->update_settings($settings);
    $content = '<p>Path C:\\draft\\file and <a href="https://' . $domain . '/">quoted "O\'Reilly" link</a>.</p>';
    $post_id = wp_insert_post(wp_slash(['post_title'=>$tag,'post_content'=>$content,'post_type'=>'lem_audit_test','post_status'=>'publish']),true);
    $assert('Synthetic article preserves slashes and quotes', !is_wp_error($post_id) && get_post($post_id)->post_content === $content);
    $result = lem()->link_scanner->batch_scan(['batch'=>1]);
    $meta = json_decode(get_post_meta($post_id, LEM_BANNED_LINKS_META_KEY, true),true);
    $assert('Batch scan stores valid JSON through the WordPress metadata API', $result['total_posts'] === 1 && count($meta['links'] ?? []) === 1 && strpos($meta['links'][0]['anchor'], "O'Reilly") !== false);
    $cleaned = lem()->link_scanner->clean_post($post_id, lem()->banned_sites->get_all_domains());
    $expected = '<p>Path C:\\draft\\file and quoted "O\'Reilly" link.</p>';
    $assert('Explicit link removal preserves article slashes and quotes', $cleaned === 1 && get_post($post_id)->post_content === $expected);
    $assert('Link metadata is removed after the last matched link is removed', get_post_meta($post_id, LEM_BANNED_LINKS_META_KEY, true) === '');
    $settings['post_types'] = []; lem()->update_settings($settings);
    $assert('Empty post-type selection produces an empty batch without SQL errors', lem()->link_scanner->batch_scan()['total_posts'] === 0);
    $assert('Empty post-type selection produces an empty mention scan', lem()->scanner->batch_scan()['posts_with_matches'] === 0);
    echo 'WordPress integration checks passed: ' . $checks . "\n";
} finally {
    if ($post_id && !is_wp_error($post_id)) { wp_delete_post($post_id,true); }
    if ($site_id) { lem()->banned_sites->delete($site_id); }
    if ($entity_id) { lem()->entities->delete($entity_id); }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lem\\_%' OR option_name LIKE '\\_transient\\_lem\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lem\\_%' OR option_name = 'cron'");
    foreach ($option_rows as $row) { $wpdb->insert($wpdb->options,$row); }
    wp_cache_flush();
    $_GET = $original_get; $_POST = $original_post;
    remove_filter('pre_http_request',$http_stub,10);
}
