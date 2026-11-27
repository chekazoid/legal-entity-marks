<?php
/** On an isolated WordPress site, run with seed under 1.15.2, then verify under 1.16.0. */
defined('ABSPATH') || exit;
global $wpdb;

if (($args[0] ?? '') === 'seed') {
    if (!defined('LEM_VERSION') || LEM_VERSION !== '1.15.2') {
        throw new RuntimeException('Use the published 1.15.2 for the migration fixture.');
    }
    $entity = lem()->entities->insert([
        'type' => 'inoagent', 'name' => 'Migration Example', 'aliases' => ['Migration Alias'],
        'is_manual' => 1,
    ]);
    $resource = lem()->banned_sites->insert([
        'domain' => 'migration.example', 'account' => '', 'label' => 'Migration Example',
        'registry' => 'inoagent', 'entity_id' => $entity,
    ]);
    $content = '<p>Migration Example опубликовала отчёт.</p>';
    $settings = lem()->get_settings();
    $settings['preset'] = 'manual';
    $settings['mark_registries'] = [];
    $settings['track_registries'] = ['inoagent'];
    $settings['auto_scan_on_publish'] = false;
    $settings['accent_color'] = '#b11d38';
    $settings['stats_enabled'] = true;
    $settings['stats_consent_at'] = '2026-11-25 12:00:00';
    $settings['channel_enabled'] = true;
    lem()->update_settings($settings);
    $post = wp_insert_post(['post_title' => 'Migration fixture', 'post_status' => 'publish', 'post_content' => $content], true);
    if (!$entity || !$resource || is_wp_error($post)) {
        throw new RuntimeException('Could not create legacy data.');
    }
    $json = wp_json_encode(['text' => 'Литерал с C:\\draft и кавычками "Тест"']);
    lem()->scanner->scan_post($post);
    $matched = get_post_meta($post, '_lem_matches', true);
    if (empty(json_decode($matched, true)['entities'])) {
        throw new RuntimeException('The legacy scanner missed the migration fixture.');
    }
    update_post_meta($post, '_lem_banned_links', wp_slash($json));
    update_post_meta($post, '_lem_overrides', wp_slash(wp_json_encode(['forced' => [(int) $entity], 'excluded' => []])));
    update_option('lem_registry_snapshot_inoagent', ['version' => 'legacy-test']);
    update_option('lem_install_id', 'legacy-installation');
    update_option('lem_rescan_state', ['offset' => 5, 'total' => 12, 'done' => false]);
    update_option('clem_test_migration_fixture', compact('entity', 'resource', 'post', 'json', 'matched', 'content'));
    echo "Legacy fixture created.\n";
    return;
}

$fixture = get_option('clem_test_migration_fixture');
if (($args[0] ?? '') !== 'verify' || !is_array($fixture) || !defined('CLEM_VERSION')) {
    throw new RuntimeException('Run verify under the updated plugin after creating the legacy fixture.');
}

$checks = 0;
$assert = static function ($ok, $description) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException($description);
    }
    $checks++;
    echo "PASS $description\n";
};
$assert(get_option(CLEM_Migration::DONE), 'Storage migration completed');
$settings = clem_plugin()->get_settings();
$assert($settings['accent_color'] === '#b11d38', 'Editorial settings survive');
$assert(!$settings['channel_enabled'] && !$settings['stats_enabled'], 'Previously enabled defaults cannot send requests');
$assert(get_option('clem_install_id') === 'legacy-installation', 'Existing local installation ID survives');
$assert(get_option('clem_registry_snapshot_inoagent')['version'] === 'legacy-test', 'Verification metadata survives');
$assert(clem_plugin()->entities->get_by_id($fixture['entity'])['name'] === 'Migration Example', 'Entity IDs remain stable');
$resource = $wpdb->get_row($wpdb->prepare(
    "SELECT id, entity_id FROM {$wpdb->prefix}clem_banned_sites WHERE id = %d", $fixture['resource']
), ARRAY_A);
$assert((int) $resource['entity_id'] === (int) $fixture['entity'], 'Resource still links to the same registry ID');
$assert(get_post_meta($fixture['post'], CLEM_META_KEY, true) === $fixture['matched'], 'Stored scan matches survive');
$assert(get_post_meta($fixture['post'], CLEM_BANNED_LINKS_META_KEY, true) === $fixture['json'], 'Link metadata preserves slashes and quotes');
$assert(CLEM_Frontend::get_overrides($fixture['post'])['forced'] === [(int) $fixture['entity']], 'Per-article override survives');
$GLOBALS['wp_query'] = new WP_Query(['p' => $fixture['post'], 'post_type' => 'post']);
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->the_post();
$rendered = clem_plugin()->frontend->filter_content($fixture['content']);
$assert(strpos($rendered, 'Migration Example<sup') !== false, 'Migrated Always mark setting renders a footnote');
$assert(!metadata_exists('post', $fixture['post'], '_lem_matches'), 'Legacy metadata was moved');
$assert(!wp_next_scheduled('lem_fetch_registries') && !wp_next_scheduled('clem_fetch_registries'), 'No online cron before new consent');
$assert(clem_plugin()->rescan->status()['offset'] === 5 && wp_next_scheduled(CLEM_Rescan::HOOK),
    'Local archive scan resumes at the saved offset');
$assert(CLEM_Migration::run() && count(get_post_meta($fixture['post'], CLEM_META_KEY, false)) === 1, 'Repeat startup cannot duplicate metadata');
echo "Migration integration checks passed: $checks\n";
