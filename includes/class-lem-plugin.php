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
    public $postlist;
    public $cli;
    public $banned_sites;
    public $link_scanner;
    public $rescan;
    public $brands;
    public $report;

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
        require_once $dir . 'class-lem-rescan.php';
        require_once $dir . 'class-lem-brands.php';
        require_once $dir . 'class-lem-report.php';

        if (is_admin()) {
            require_once LEM_DIR . 'admin/class-lem-admin.php';
            require_once $dir . 'class-lem-metabox.php';
            require_once $dir . 'class-lem-postlist.php';
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
        $this->rescan       = new LEM_Rescan();
        $this->brands       = new LEM_Brands();
        $this->report       = new LEM_Report();

        if (is_admin()) {
            $this->admin    = new LEM_Admin();
            $this->metabox  = new LEM_Metabox();
            $this->postlist = new LEM_Postlist();
        }
        if (defined('WP_CLI') && WP_CLI) {
            $this->cli = new LEM_CLI();
        }
    }

    private function register_hooks() {
        register_activation_hook(LEM_FILE, [$this, 'activate']);
        register_deactivation_hook(LEM_FILE, [$this, 'deactivate']);
        add_action('admin_init', [$this, 'maybe_run_upgrade_tasks']);
        add_action('admin_init', [$this, 'maybe_sync_bundled_data'], 11);
    }

    /**
     * Отметка о содержимом комплектных данных.
     * Меняется, когда в data/ появляется то, что надо докатить на уже
     * установленные сайты: новые брендовые правила, типы доменов и подобное.
     */
    const BUNDLED_DATA_VERSION = '1.12.0-sites-registry';

    /**
     * Докатка комплектных данных.
     *
     * Отдельно от maybe_run_upgrade_tasks: та привязана к номеру версии плагина
     * и не сработает, если под одним номером приедет пересобранный архив.
     * Здесь своя отметка, поэтому докатку можно повторить, подняв её.
     */
    public function maybe_sync_bundled_data() {
        if (get_option('lem_bundled_data_version') === self::BUNDLED_DATA_VERSION) {
            return;
        }
        if (!$this->database->table_exists()) {
            return;
        }

        // Типы у комплектных доменов: без них экстремистские и нежелательные
        // выглядят как добавленные вручную
        $this->importer->import_banned_sites();

        // Сначала довозим новые комплектные правила, потом применяем: иначе
        // свежие правила (DOXA, «Вёрстка») добавились бы уже ПОСЛЕ применения
        // и не попали бы в алиасы до следующего обновления реестров
        $this->brands->sync_bundled();

        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $table") > 0) {
            $this->importer->apply_brand_aliases();
        }

        update_option('lem_bundled_data_version', self::BUNDLED_DATA_VERSION, false);
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

        add_option('lem_installed_at', current_time('mysql'));
        update_option('lem_upgrade_version', LEM_VERSION);
        $this->cron->schedule_events();
    }

    /**
     * Разовые задачи после обновления плагина (срабатывают один раз на версию).
     *
     * Обновление файлов на непустой базе не запускает import_all_bundled,
     * поэтому здесь:
     *  1) докатываем курируемые брендовые алиасы (иначе новые бренды вроде
     *     «Медуза» не попадут в реестр до ручной команды);
     *  2) ставим разовую задачу обновить реестры из источников, чтобы после
     *     обновления плагина сайт сразу получил свежие данные, а не ждал
     *     недельного крона. Сам фетч уходит в фон и админку не тормозит.
     */
    public function maybe_run_upgrade_tasks() {
        if (get_option('lem_upgrade_version') === LEM_VERSION) {
            return;
        }

        add_option('lem_installed_at', current_time('mysql'));

        // Комплектные данные (брендовые правила, типы доменов) довозит
        // maybe_sync_bundled_data: у неё своя отметка, не зависящая от номера версии

        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'lem_fetch_registries');

        update_option('lem_upgrade_version', LEM_VERSION);
        delete_option('lem_brand_version'); // имя из 1.6.2, больше не используется
    }

    public function deactivate() {
        $this->cron->clear_events();
    }

    const REGISTRY_TYPES = ['inoagent', 'extremist', 'terrorist', 'undesirable'];

    const CONTEXT_TRIGGERS = ['blockquote', 'link', 'quotes', 'embed'];

    const SURNAME_MODES = ['off', 'confirmed', 'always'];

    /**
     * Готовые профили. Маркировка и отслеживание разделены: отслеживаемые
     * реестры попадают в отчёт «Упоминания», но меток на сайте не оставляют.
     */
    const PRESETS = [
        'media' => [
            'label' => 'СМИ',
            'hint'  => 'Маркируются все четыре реестра, как требует закон о СМИ.',
            'mark'  => ['inoagent', 'extremist', 'terrorist', 'undesirable'],
            'track' => [],
        ],
        'nonmedia' => [
            'label' => 'Не СМИ',
            'hint'  => 'Маркируются экстремистские и террористические. Нежелательные только отслеживаются: упоминания и ссылки видны в отчёте, но меток на сайте нет. Иноагенты не затрагиваются.',
            'mark'  => ['extremist', 'terrorist'],
            'track' => ['undesirable'],
        ],
        'manual' => [
            'label' => 'Вручную',
            'hint'  => 'Отмечайте галочками сами.',
            'mark'  => null,
            'track' => null,
        ],
    ];

    public function get_settings() {
        $defaults = [
            'post_types'            => ['post'],
            // Ссылка на сайт иноагента законом не запрещена, поэтому в чистку
            // по умолчанию идут только три реестра
            'link_registries'       => LEM_Banned_Sites::REMOVABLE_TYPES,
            'filter_priority'       => 9999,
            'accent_color'          => '#f88c00',
            'disclaimer_bg'         => '#fff9f0',
            'disclaimer_border'     => '#f88c00',
            'cron_interval'         => 'weekly',
            'auto_scan_on_publish'  => true,
            'preset'                => 'media',
            'mark_registries'       => self::REGISTRY_TYPES,
            'track_registries'      => [],
            'inoagent_context_only' => false,
            'match_word_forms'      => true,
            'surname_mode'          => 'confirmed',
            'mark_excluded'         => false,
            'extra_fields_mode'     => 'off',   // off | selected | all
            'extra_fields'          => [],
            'context_triggers'      => [
                'blockquote' => true,
                'link'       => true,
                'quotes'     => true,
                'embed'      => true,
            ],
        ];
        $saved    = get_option('lem_settings', []);
        $settings = wp_parse_args($saved, $defaults);

        // Совместимость: до 1.9.0 был один список registries, он управлял и
        // поиском, и маркировкой. Переносим его в «маркировать»
        if (isset($saved['registries']) && !isset($saved['mark_registries'])) {
            $settings['mark_registries'] = (array) $saved['registries'];
        }

        $settings['mark_registries'] = array_values(array_intersect(
            self::REGISTRY_TYPES,
            (array) ($settings['mark_registries'] ?: [])
        ));
        $settings['track_registries'] = array_values(array_intersect(
            self::REGISTRY_TYPES,
            (array) ($settings['track_registries'] ?: [])
        ));

        // Пресет расставляет галочки сам, «Вручную» оставляет как есть
        if (!isset(self::PRESETS[$settings['preset']])) {
            $settings['preset'] = 'manual';
        }
        $preset = self::PRESETS[$settings['preset']];
        if ($preset['mark'] !== null) {
            $settings['mark_registries']  = $preset['mark'];
            $settings['track_registries'] = $preset['track'];
        }

        // Реестр, который маркируется, отслеживается по определению
        $settings['link_registries'] = array_values(array_intersect(
            self::REGISTRY_TYPES,
            (array) ($settings['link_registries'] ?: [])
        ));

        $settings['track_registries'] = array_values(array_unique(array_merge(
            $settings['track_registries'],
            $settings['mark_registries']
        )));

        // Старое имя оставляем для обратной совместимости стороннего кода
        $settings['registries'] = $settings['mark_registries'];
        $triggers = (array) ($settings['context_triggers'] ?: []);
        foreach (self::CONTEXT_TRIGGERS as $t) {
            $triggers[$t] = !empty($triggers[$t]);
        }
        $settings['context_triggers'] = $triggers;

        if (!in_array($settings['surname_mode'], self::SURNAME_MODES, true)) {
            $settings['surname_mode'] = 'confirmed';
        }

        if (!in_array($settings['extra_fields_mode'], ['off', 'selected', 'all'], true)) {
            $settings['extra_fields_mode'] = 'off';
        }
        $settings['extra_fields'] = array_values(array_filter(
            array_map('strval', (array) ($settings['extra_fields'] ?: []))
        ));

        return $settings;
    }

    public function update_settings($settings) {
        update_option('lem_settings', $settings);
    }
}
