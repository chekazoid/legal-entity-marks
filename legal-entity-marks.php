<?php
/**
 * Plugin Name: Legal Entity Marks
 * Plugin URI:  https://github.com/chekazoid/legal-entity-marks
 * Description: Маркировка и отслеживание упоминаний иноагентов, экстремистских, террористических и нежелательных организаций. Сноски и дисклеймеры для СМИ, отчёт по упоминаниям и ссылкам для остальных сайтов.
 * Version:     1.12.0
 * Author:      Алексей Шляпужников
 * Author URI:  https://shliapuzhnikov.com
 * License:     GPL-2.0+
 * Text Domain: legal-entity-marks
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.1
 */

defined('ABSPATH') || exit;

define('LEM_VERSION', '1.12.0');
define('LEM_FILE', __FILE__);
define('LEM_DIR', plugin_dir_path(__FILE__));
define('LEM_URL', plugin_dir_url(__FILE__));
define('LEM_DATA_DIR', LEM_DIR . 'data');
define('LEM_TABLE', 'lem_entities');
define('LEM_META_KEY', '_lem_matches');
define('LEM_BANNED_LINKS_META_KEY', '_lem_banned_links');
define('LEM_OVERRIDES_META_KEY', '_lem_overrides');

require_once LEM_DIR . 'includes/class-lem-plugin.php';

function lem() {
    return LEM_Plugin::instance();
}

lem();
