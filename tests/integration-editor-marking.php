<?php
/** Run on the test WordPress with wp eval-file. Synthetic records are removed on exit. */
defined('ABSPATH') || exit;

require_once CLEM_DIR . 'includes/class-lem-metabox.php';
require_once CLEM_DIR . 'includes/class-lem-postlist.php';

$plugin = clem_plugin();
$original_settings = get_option('clem_settings');
$original_overrides = $_POST;
$original_query = $GLOBALS['wp_query'];
$original_main_query = $GLOBALS['wp_the_query'];
$original_post = $GLOBALS['post'] ?? null;
$original_user = get_current_user_id();
$post_ids = [];
$entity_ids = [];
$site_ids = [];
$checks = 0;
$assert = static function ($label, $ok) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo "PASS $label\n";
};

try {
    wp_set_current_user(1);
    $settings = $plugin->get_settings();
    $settings['preset'] = 'manual';
    $settings['post_types'] = ['post'];
    $settings['mark_registries'] = [];
    $settings['track_registries'] = ['inoagent', 'extremist', 'terrorist', 'undesirable'];
    $settings['fedsfm_people_mode'] = 'off';
    $settings['inoagent_context_only'] = true;
    $settings['auto_scan_on_publish'] = false;
    $settings['match_word_forms'] = true;
    $settings['mark_excluded'] = false;
    $plugin->update_settings($settings);

    $tag = 'EditorMarkingTest' . wp_generate_password(8, false, false);
    $agent = ['type' => 'inoagent', 'name' => $tag . ' Editorial Group'];
    $second = ['type' => 'inoagent', 'name' => $tag . ' Research Group'];
    $city = ['type' => 'extremist', 'name' => 'Местная религиозная организация ' . $tag . ' «Уфа»(ОГРН 1234567890123)', 'aliases' => ['Уфа']];
    $salavat = ['type' => 'extremist', 'name' => 'Местная религиозная организация ' . $tag . ' «Салават»', 'aliases' => ['Салават']];
    $club = ['type' => 'extremist', 'name' => $tag . ' «Клуб Болельщиков Футбольного Клуба «Динамо» Киров»', 'aliases' => ['«Динамо»', 'Клуб Болельщиков Футбольного Клуба «Динамо', 'Клуб Болельщиков Футбольного Клуба «Динамо» Киров']];
    foreach (compact('agent', 'second', 'city', 'salavat', 'club') as $key => $entry) {
        $entity_ids[$key] = (int) $plugin->entities->insert($entry);
        $assert($key . ' synthetic entity saved', $entity_ids[$key] > 0);
    }
    $seeds = [];
    foreach ($entity_ids as $key => $id) {
        $seeds[$key] = $plugin->entities->get_by_id($id);
    }
    $assert('Supporter organisation keeps its meaningful full alias', CLEM_Scanner::match_entity('Клуб Болельщиков Футбольного Клуба «Динамо» Киров', $seeds['club'], $settings) !== null);
    $assert('Full local organisation name matches without the registration number', CLEM_Scanner::match_entity('Местная религиозная организация ' . $tag . ' «Уфа»', $seeds['city'], $settings) !== null);
    $incoming = ['type' => 'extremist', 'name' => 'Местная религиозная организация ' . $tag . ' «Салаватский район»', 'aliases' => ['Салаватский район']];
    $import_result = $plugin->importer->import_verified_entries([$incoming], 'extremist', 'editor-test');
    $imported = $plugin->entities->search(['type' => 'extremist', 'search' => $incoming['name']])['items'][0] ?? [];
    $entity_ids['imported'] = (int) ($imported['id'] ?? 0);
    $assert('Incoming registry aliases cannot restore the locality as a match', $import_result['added'] === 1 && $entity_ids['imported'] > 0 && $imported['aliases'] === []);
    $sport = '<p>В Уфе «Салават Юлаев» уступил минскому «Динамо».</p>';
    $assert('City and sports matches are rejected during a fresh scan', $plugin->scanner->scan_text($sport, array_intersect_key($seeds, array_flip(['city', 'salavat', 'club']))) === []);
    $assert('Manual asterisk cannot revive unsupported city or team aliases', CLEM_Scanner::asterisk_hit('Салават*', $seeds['salavat'], $settings) === null && CLEM_Scanner::asterisk_hit('Динамо*', $seeds['club'], $settings) === null);

    $create_post = static function ($title, $body) use (&$post_ids) {
        $id = wp_insert_post(wp_slash(['post_title' => $title, 'post_content' => $body, 'post_type' => 'post', 'post_status' => 'publish']), true);
        if (is_wp_error($id) || !$id) {
            throw new RuntimeException('Could not save synthetic article');
        }
        $post_ids[] = $id;
        return $id;
    };
    $content = '<p>' . $agent['name'] . ' и ' . $second['name'] . ' опубликовали отчёты.</p>';
    $post_id = $create_post($tag . ' selected', $content);
    $other_id = $create_post($tag . ' other', $content);
    $sport_id = $create_post($tag . ' sport', $sport);
    $assert('Both genuine mentions are found with automatic marking off', count($plugin->scanner->scan_post($post_id)) === 2 && count($plugin->scanner->scan_post($other_id)) === 2);

    $meta = new CLEM_Metabox();
    // Send the same fields as the editor and use the normal save_post hook.
    $save = static function ($post_id, $overrides) {
        $_POST = [
            'clem_overrides_present' => '1',
            CLEM_Metabox::NONCE_FIELD => wp_create_nonce(CLEM_Metabox::NONCE_ACTION),
            'clem_override' => $overrides,
        ];
        do_action('save_post', $post_id, get_post($post_id), true);
    };
    $save($post_id, [$entity_ids['agent'] => 'force', $entity_ids['second'] => 'auto']);
    $assert('Editor saves exactly one explicit mark', CLEM_Frontend::get_overrides($post_id)['forced'] === [$entity_ids['agent']]);

    // Old scan metadata can still contain all three false positives. A saved
    // override for one of them must not turn it into a public mark.
    $stale = [];
    foreach (['city' => 'Уфе', 'salavat' => 'Салават', 'club' => '«Динамо»'] as $key => $match) {
        $stale[] = ['id' => $entity_ids[$key], 'name' => $seeds[$key]['name'], 'type' => 'extremist', 'matched_as' => $match, 'position' => 0];
    }
    CLEM_Scanner::write_matches($sport_id, $stale);
    $save($sport_id, [$entity_ids['city'] => 'force']);

    $render = static function ($id, $content) {
        $GLOBALS['wp_query'] = new WP_Query(['p' => $id, 'post_type' => 'post']);
        $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
        $GLOBALS['wp_query']->the_post();
        return (new CLEM_Frontend())->filter_content($content);
    };
    $rendered = $render($post_id, $content);
    $assert('One footnote appears for the explicitly confirmed mention', substr_count($rendered, 'class="clem-ref"') === 1 && strpos($rendered, $agent['name'] . '<sup') !== false);
    $assert('Unconfirmed mention in the same article stays unmarked', strpos($rendered, $second['name'] . '<sup') === false);
    $assert('Another article with identical text stays unmarked', $render($other_id, $content) === $content);
    $assert('Stale sport matches cannot produce a public footnote', $render($sport_id, $sport) === $sport);

    $report = new CLEM_Report();
    $marked = $report->get_rows(['search' => $tag, 'mode' => 'marked']);
    $assert('Marked report agrees with the frontend', $marked['total'] === 1 && (int) $marked['items'][0]['post_id'] === $post_id);
    $assert('Report filters saved sport matches before rescanning', $report->get_rows(['search' => $tag . ' sport'])['total'] === 0);
    $postlist = new CLEM_Postlist();
    ob_start();
    $postlist->render_column('clem_marks', $post_id);
    $column = ob_get_clean();
    $assert('Post list counts the explicitly confirmed mark', strpos($column, 'меток: 1') !== false);
    ob_start();
    $meta->render(get_post($sport_id));
    $editor = ob_get_clean();
    $assert('Editor hides obsolete matches', strpos($editor, 'clem_override[') === false);

    $save($post_id, [$entity_ids['agent'] => 'exclude']);
    $assert('Removing the manual mark also removes the footnote', $render($post_id, $content) === $content);
    $save($post_id, [$entity_ids['agent'] => 'force']);
    $settings['track_registries'] = ['extremist'];
    $plugin->update_settings($settings);
    $assert('A fully disabled category cannot be marked', $render($post_id, $content) === $content);
    $assert('Unmarked sport article has no matches after rescanning', $plugin->scanner->scan_post($sport_id) === [] && get_post_meta($sport_id, CLEM_META_KEY, true) === '');

    $settings['track_registries'] = ['inoagent'];
    $settings['inoagent_context_only'] = false;
    $plugin->update_settings($settings);
    $ambiguous = [
        'type' => 'inoagent', 'name' => $tag . ' Проект «После»',
        'aliases' => ['После', '«После»'],
    ];
    $entity_ids['ambiguous'] = (int) $plugin->entities->insert($ambiguous);
    $legacy_text = '<p>После ДТП дорогу открыли.</p>';
    $legacy_post = $create_post($tag . ' legacy', $legacy_text);
    CLEM_Scanner::write_matches($legacy_post, [[
        'id' => $entity_ids['ambiguous'], 'name' => $ambiguous['name'],
        'type' => 'inoagent', 'matched_as' => 'После', 'position' => 0,
    ]]);
    $save($legacy_post, [$entity_ids['ambiguous'] => 'force']);
    $assert('Legacy occurrence of an ordinary word does not get a footnote', $render($legacy_post, $legacy_text) === $legacy_text);
    $assert('Archived report hides the same legacy occurrence', (new CLEM_Report())->get_rows(['search' => $tag . ' legacy'])['total'] === 0);
    $entry = $plugin->entities->get_by_id($entity_ids['ambiguous']);
    $confirmed = $plugin->scanner->scan_text('Об этом сообщил проект После*.', [$entry]);
    $stored_confirmation = array_merge($confirmed[0] ?? [], $entry);
    $assert('A deliberate editor asterisk survives saved mention validation',
        count($confirmed) === 1 && !empty($confirmed[0]['editor_asterisk'])
        && CLEM_Scanner::is_current_mention($stored_confirmation, $settings));

    $assert('A whole shared platform cannot be registered as a resource',
        !$plugin->banned_sites->insert(['domain' => 'max.ru', 'registry' => 'undesirable']));
    global $wpdb;
    $table = $wpdb->prefix . 'clem_banned_sites';
    foreach (['max.ru', 'vk.ru'] as $domain) {
        $wpdb->insert($table, ['domain' => $domain, 'account' => '', 'label' => $tag, 'registry' => 'undesirable']);
        $site_ids[] = $wpdb->insert_id;
    }
    $plugin->banned_sites->flush_cache();
    $legacy_links = [
        ['url' => 'https://max.ru/ufa_rb/example', 'matched_domain' => 'max.ru'],
        ['url' => 'https://vk.ru/wall1316249_7275', 'matched_domain' => 'vk.ru'],
    ];
    update_post_meta($legacy_post, CLEM_BANNED_LINKS_META_KEY, wp_slash(wp_json_encode(['links' => $legacy_links])));
    $assert('Previously indexed platform-wide matches are absent from the current resource index',
        !array_intersect_key($plugin->banned_sites->get_index(), array_flip(['max.ru', 'vk.ru'])));
    $assert('Legacy shared-platform matches are unavailable for removal',
        CLEM_Link_Scanner::removable_only($legacy_links) === []);
    $assert('Previously saved platform-wide links do not reappear as link-only results',
        (new CLEM_Report())->get_rows(['search' => $tag . ' legacy'])['total'] === 0);

    echo "Editor marking integration checks passed: $checks\n";
} finally {
    $_POST = $original_overrides;
    $GLOBALS['wp_query'] = $original_query;
    $GLOBALS['wp_the_query'] = $original_main_query;
    $GLOBALS['post'] = $original_post;
    foreach ($post_ids as $id) {
        wp_delete_post($id, true);
    }
    foreach ($site_ids as $id) {
        if ($id) { $plugin->banned_sites->delete($id); }
    }
    foreach ($entity_ids as $id) {
        if ($id) { $plugin->entities->delete($id); }
    }
    if ($original_settings === false) {
        delete_option('clem_settings');
    } else {
        update_option('clem_settings', $original_settings);
    }
    $plugin->entities->flush_cache();
    wp_set_current_user($original_user);
}
