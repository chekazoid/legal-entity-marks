<?php
/**
 * Plugin Name: Chekazoid Legal Entity Marks
 * Plugin URI:  https://github.com/chekazoid/legal-entity-marks
 * Description: Маркировка и отслеживание упоминаний иноагентов, экстремистских, террористических и нежелательных организаций. Сноски и дисклеймеры для СМИ, отчёт по упоминаниям и ссылкам для остальных сайтов.
 * Version:     1.16.1
 * Author:      Алексей Шляпужников
 * Author URI:  https://shliapuzhnikov.com
 * License:     GPL-2.0+
 * Text Domain: chekazoid-legal-entity-marks
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.1
 */

defined('ABSPATH') || exit;

define('CLEM_VERSION', '1.16.1');
define('CLEM_FILE', __FILE__);
define('CLEM_DIR', plugin_dir_path(__FILE__));
define('CLEM_URL', plugin_dir_url(__FILE__));
define('CLEM_DATA_DIR', CLEM_DIR . 'data');
define('CLEM_TABLE', 'clem_entities');
define('CLEM_META_KEY', '_clem_matches');
define('CLEM_BANNED_LINKS_META_KEY', '_clem_banned_links');
define('CLEM_OVERRIDES_META_KEY', '_clem_overrides');

function clem_plugin() {
    require_once CLEM_DIR . 'includes/class-lem-plugin.php';
    return CLEM_Plugin::instance();
}

function clem_startup_error() {
    if (version_compare(PHP_VERSION, '7.4', '<') || version_compare(get_bloginfo('version'), '6.1', '<')) {
        return __('Для Chekazoid Legal Entity Marks нужны PHP 7.4 и WordPress 6.1 или более новые версии.', 'chekazoid-legal-entity-marks');
    }
    if (!extension_loaded('mbstring')) {
        return __('Для Chekazoid Legal Entity Marks требуется расширение PHP mbstring.', 'chekazoid-legal-entity-marks');
    }
    if (class_exists('LEM_Plugin', false)) {
        return __('Деактивируйте прежнюю версию Legal Entity Marks перед переходом на Chekazoid Legal Entity Marks.', 'chekazoid-legal-entity-marks');
    }
    require_once CLEM_DIR . 'includes/class-lem-registry-sync.php';
    require_once CLEM_DIR . 'includes/class-lem-migration.php';
    if (!CLEM_Migration::run()) {
        return __('Не удалось перенести данные Legal Entity Marks. Проверьте доступ к базе и наличие таблиц с обоими префиксами.', 'chekazoid-legal-entity-marks');
    }
    return '';
}

function clem_start() {
    $error = clem_startup_error();
    if ($error !== '') {
        add_action('admin_notices', static function () use ($error) {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
        });
        return;
    }
    clem_plugin();
}

function clem_activate() {
    $error = clem_startup_error();
    if ($error !== '') {
        wp_die(esc_html($error));
    }
    clem_plugin()->activate();
}

add_action('init', 'clem_start', 0);
register_activation_hook(CLEM_FILE, 'clem_activate');
