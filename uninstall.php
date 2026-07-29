<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$table = $wpdb->prefix . 'lem_entities';
$wpdb->query("DROP TABLE IF EXISTS $table");

$banned_table = $wpdb->prefix . 'lem_banned_sites';
$wpdb->query("DROP TABLE IF EXISTS $banned_table");

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
    '_lem_matches'
));
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
    '_lem_banned_links'
));
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
    '_lem_overrides'
));

delete_option('lem_db_version');
delete_option('lem_banned_sites_db_version');
delete_option('lem_settings');
delete_option('lem_list_version');
delete_option('lem_last_fetch_time');
delete_option('lem_last_fetch_error');
delete_option('lem_brand_version');
delete_option('lem_upgrade_version');
delete_option('lem_brand_rules');
delete_option('lem_installed_at');
delete_option('lem_rescan_state');
delete_option('lem_last_update_report');
delete_option('lem_update_notice_seen');
delete_option('lem_first_fetch_done');
delete_option('lem_bundled_data_version');
delete_option('lem_cron_last_run');
delete_transient('lem_entities_active');
delete_transient('lem_entities_active_all');
delete_transient('lem_scan_state');
delete_transient('lem_banned_sites_all');
delete_transient('lem_banned_scan_state');
delete_transient('lem_banned_remove_state');
delete_transient('lem_banned_sites_accounts');
delete_transient('lem_banned_sites_index');
delete_transient('lem_rescan_lock');

wp_clear_scheduled_hook('lem_fetch_registries');
wp_clear_scheduled_hook('lem_scan_updated');
wp_clear_scheduled_hook('lem_run_rescan');
