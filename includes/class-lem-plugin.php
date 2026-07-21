<?php
defined('ABSPATH') || exit;

class LEM_Plugin {

    private static $instance = null;

    public $database;
    public $entities;
    public $scanner;
    public $frontend;
    public $importer;
    public $cache;
    public $cron;
    public $admin;
    public $metabox;
    public $cli;
    public $banned_sites;
    public $link_scanner;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    private function load_dependencies() {
        $dir = LEM_DIR . 'includes/';
        require_once $dir . 'class-lem-database.php';
        require_once $dir . 'class-lem-entities.php';
        require_once $dir . 'class-lem-morphology.php';
        require_once $dir . 'class-lem-scanner.php';
        require_once $dir . 'class-lem-frontend.php';
        require_once $dir . 'class-lem-importer.php';
        require_once $dir . 'class-lem-cache.php';
        require_once $dir . 'class-lem-cron.php';
        require_once $dir . 'class-lem-banned-sites.php';
        require_once $dir . 'class-lem-link-scanner.php';

        if (is_admin()) {
            require_once LEM_DIR . 'admin/class-lem-admin.php';
            require_once $dir . 'class-lem-metabox.php';
        }
        if (defined('WP_CLI') && WP_CLI) {
            require_once $dir . 'class-lem-cli.php';
        }
    }

    private function init_components() {
        $this->database = new LEM_Database();
        $this->entities = new LEM_Entities();
        $this->scanner  = new LEM_Scanner();
        $this->frontend = new LEM_Frontend();
        $this->importer = new LEM_Importer();
        $this->cache    = new LEM_Cache();
        $this->cron         = new LEM_Cron();
        $this->banned_sites = new LEM_Banned_Sites();
        $this->link_scanner = new LEM_Link_Scanner();

        if (is_admin()) {
            $this->admin   = new LEM_Admin();
            $this->metabox = new LEM_Metabox();
        }
        if (defined('WP_CLI') && WP_CLI) {
            $this->cli = new LEM_CLI();
        }
    }

    private function register_hooks() {
        register_activation_hook(LEM_FILE, [$this, 'activate']);
        register_deactivation_hook(LEM_FILE, [$this, 'deactivate']);
    }

    public function activate() {
        $this->database->create_table();
        $this->database->create_banned_sites_table();

        // Import bundled data if table is empty
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count === 0) {
            $this->importer->import_all_bundled();
        }

        // Import bundled banned sites if table is empty
        $banned_table = $wpdb->prefix . 'lem_banned_sites';
        $banned_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $banned_table");
        if ($banned_count === 0) {
            $this->importer->import_banned_sites();
        }

        $this->cron->schedule_events();
    }

    public function deactivate() {
        $this->cron->clear_events();
    }

    const REGISTRY_TYPES = ['inoagent', 'extremist', 'terrorist', 'undesirable'];

    const CONTEXT_TRIGGERS = ['blockquote', 'link', 'quotes', 'embed'];

    const SURNAME_MODES = ['off', 'confirmed', 'always'];

    public function get_settings() {
        $defaults = [
            'post_types'            => ['post'],
            'filter_priority'       => 9999,
            'accent_color'          => '#f88c00',
            'disclaimer_bg'         => '#fff9f0',
            'disclaimer_border'     => '#f88c00',
            'cron_interval'         => 'weekly',
            'auto_scan_on_publish'  => true,
            'registries'            => self::REGISTRY_TYPES,
            'inoagent_context_only' => false,
            'match_word_forms'      => true,
            'surname_mode'          => 'confirmed',
            'mark_excluded'         => false,
            'context_triggers'      => [
                'blockquote' => true,
                'link'       => true,
                'quotes'     => true,
                'embed'      => true,
            ],
        ];
        $saved    = get_option('lem_settings', []);
        $settings = wp_parse_args($saved, $defaults);

        // Нормализация: настройки могли прийти из старой версии или из БД в чужом виде
        $settings['registries'] = array_values(array_intersect(
            self::REGISTRY_TYPES,
            (array) ($settings['registries'] ?: [])
        ));
        $triggers = (array) ($settings['context_triggers'] ?: []);
        foreach (self::CONTEXT_TRIGGERS as $t) {
            $triggers[$t] = !empty($triggers[$t]);
        }
        $settings['context_triggers'] = $triggers;

        if (!in_array($settings['surname_mode'], self::SURNAME_MODES, true)) {
            $settings['surname_mode'] = 'confirmed';
        }

        return $settings;
    }

    public function update_settings($settings) {
        update_option('lem_settings', $settings);
    }
}
