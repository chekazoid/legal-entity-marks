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
wp_clear_scheduled_hook('clem_fetch_registries');
wp_clear_scheduled_hook('clem_scan_updated');
wp_clear_scheduled_hook('clem_import_people');
delete_option('clem_people_import_lock');
wp_clear_scheduled_hook('clem_run_rescan');

delete_transient('clem_entities_active');
delete_transient('clem_entities_active_all');
delete_transient('clem_entities_active_people');
delete_transient('clem_entities_active_all_people');
delete_transient('clem_scan_state');
delete_transient('clem_banned_sites_all');
delete_transient('clem_banned_sites_accounts');
delete_transient('clem_banned_sites_index');
delete_transient('clem_banned_scan_state');
delete_transient('clem_banned_remove_state');
delete_transient('clem_rescan_lock');

$clem_settings = get_option('clem_settings', []);
if (empty($clem_settings['delete_data_on_uninstall'])) {
    return; // данные остаются, повторная установка подхватит их как были
}

$clem_table = $wpdb->prefix . 'clem_entities';
/* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. Version-gated custom-table migration or explicitly requested uninstall cleanup. */
$wpdb->query("DROP TABLE IF EXISTS $clem_table") ;
/* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

$clem_banned_table = $wpdb->prefix . 'clem_banned_sites';
/* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. Version-gated custom-table migration or explicitly requested uninstall cleanup. */
$wpdb->query("DROP TABLE IF EXISTS $clem_banned_table") ;
/* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

foreach (['_clem_matches', '_clem_banned_links', '_clem_overrides'] as $clem_meta_key) {
    // WordPress also invalidates persistent metadata caches for affected posts.
    delete_post_meta_by_key($clem_meta_key);
}

foreach ([
    'clem_db_version', 'clem_banned_sites_db_version', 'clem_settings',
    'clem_list_version', 'clem_last_fetch_time', 'clem_last_fetch_error',
    'clem_last_fetch_sources', 'clem_last_fetch_diagnostics', 'clem_brand_version', 'clem_upgrade_version',
    'clem_brand_rules', 'clem_installed_at', 'clem_rescan_state',
    'clem_last_update_report', 'clem_update_notice_seen', 'clem_first_fetch_done',
    'clem_bundled_data_version', 'clem_cron_last_run', 'clem_install_id',
    'clem_registered_at', 'clem_people_import_state', 'clem_people_last_success',
    'clem_channel_health', 'clem_storage_migrated',
    'clem_registry_snapshot_inoagent', 'clem_registry_snapshot_extremist',
    'clem_registry_snapshot_terrorist', 'clem_registry_snapshot_undesirable',
] as $clem_option) {
    delete_option($clem_option);
}
