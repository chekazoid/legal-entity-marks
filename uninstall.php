<?php
/**
 * Удаление плагина.
 *
 * По умолчанию данные остаются в базе. Причина простая: чтобы обновиться,
 * люди нередко удаляют плагин и ставят заново - привычка с тех времён, когда
 * WordPress отказывался загружать архив поверх существующего. Каждый такой
 * круг стирал настройки, реестры и результаты сканирования всего архива,
 * а на большом сайте это часы работы заново.
 *
 * Полная очистка включается галочкой в настройках, осознанно.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Задачи и временные записи без плагина бесполезны в любом случае
wp_clear_scheduled_hook('lem_fetch_registries');
wp_clear_scheduled_hook('lem_scan_updated');
wp_clear_scheduled_hook('lem_run_rescan');

delete_transient('lem_entities_active');
delete_transient('lem_entities_active_all');
delete_transient('lem_scan_state');
delete_transient('lem_banned_sites_all');
delete_transient('lem_banned_sites_accounts');
delete_transient('lem_banned_sites_index');
delete_transient('lem_banned_scan_state');
delete_transient('lem_banned_remove_state');
delete_transient('lem_rescan_lock');

$settings = get_option('lem_settings', []);
if (empty($settings['delete_data_on_uninstall'])) {
    return; // данные остаются, повторная установка подхватит их как были
}

$table = $wpdb->prefix . 'lem_entities';
$wpdb->query("DROP TABLE IF EXISTS $table");

$banned_table = $wpdb->prefix . 'lem_banned_sites';
$wpdb->query("DROP TABLE IF EXISTS $banned_table");

foreach (['_lem_matches', '_lem_banned_links', '_lem_overrides'] as $meta_key) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
        $meta_key
    ));
}

foreach ([
    'lem_db_version', 'lem_banned_sites_db_version', 'lem_settings',
    'lem_list_version', 'lem_last_fetch_time', 'lem_last_fetch_error',
    'lem_last_fetch_sources', 'lem_brand_version', 'lem_upgrade_version',
    'lem_brand_rules', 'lem_installed_at', 'lem_rescan_state',
    'lem_last_update_report', 'lem_update_notice_seen', 'lem_first_fetch_done',
    'lem_bundled_data_version', 'lem_cron_last_run', 'lem_install_id',
    'lem_registered_at',
] as $option) {
    delete_option($option);
}
