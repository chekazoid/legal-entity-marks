<?php
defined('ABSPATH') || exit;

class LEM_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'show_notices']);
        add_action('admin_init', [$this, 'handle_actions']);

        add_action('wp_ajax_lem_save_entity', [$this, 'ajax_save_entity']);
        add_action('wp_ajax_lem_delete_entity', [$this, 'ajax_delete_entity']);
        add_action('wp_ajax_lem_fetch_registries', [$this, 'ajax_fetch_registries']);
        add_action('wp_ajax_lem_purge_cache', [$this, 'ajax_purge_cache']);
        add_action('wp_ajax_lem_check_sources', [$this, 'ajax_check_sources']);
        add_action('wp_ajax_lem_rescan_step', [$this, 'ajax_rescan_step']);
        add_action('wp_ajax_lem_check_channel', [$this, 'ajax_check_channel']);
        add_action('wp_ajax_lem_register_site', [$this, 'ajax_register_site']);

        add_action('wp_ajax_lem_save_banned_site', [$this, 'ajax_save_banned_site']);
        add_action('wp_ajax_lem_delete_banned_site', [$this, 'ajax_delete_banned_site']);
        add_action('wp_ajax_lem_import_banned_sites', [$this, 'ajax_import_banned_sites']);
    }

    public function register_menu() {
        add_menu_page(
            __('Маркировка', 'legal-entity-marks'),
            __('Маркировка', 'legal-entity-marks'),
            'manage_options',
            'lem-dashboard',
            [$this, 'page_dashboard'],
            'dashicons-warning',
            80
        );

        add_submenu_page('lem-dashboard', __('Обзор', 'legal-entity-marks'), __('Обзор', 'legal-entity-marks'), 'manage_options', 'lem-dashboard', [$this, 'page_dashboard']);
        add_submenu_page('lem-dashboard', __('Реестр', 'legal-entity-marks'), __('Реестр', 'legal-entity-marks'), 'manage_options', 'lem-entities', [$this, 'page_entities']);
        add_submenu_page('lem-dashboard', __('Сканер', 'legal-entity-marks'), __('Сканер', 'legal-entity-marks'), 'manage_options', 'lem-scanner', [$this, 'page_scanner']);
        add_submenu_page('lem-dashboard', __('Ссылки', 'legal-entity-marks'), __('Ссылки', 'legal-entity-marks'), 'manage_options', 'lem-banned-links', [$this, 'page_banned_links']);
        add_submenu_page('lem-dashboard', __('Упоминания', 'legal-entity-marks'), __('Упоминания', 'legal-entity-marks'), 'manage_options', 'lem-report', [$this, 'page_report']);
        add_submenu_page('lem-dashboard', __('Бренды СМИ', 'legal-entity-marks'), __('Бренды СМИ', 'legal-entity-marks'), 'manage_options', 'lem-brands', [$this, 'page_brands']);
        add_submenu_page('lem-dashboard', __('Настройки', 'legal-entity-marks'), __('Настройки', 'legal-entity-marks'), 'manage_options', 'lem-settings', [$this, 'page_settings']);
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'lem-') === false) {
            return;
        }

        wp_enqueue_style('lem-admin', LEM_URL . 'admin/css/admin.css', [], LEM_VERSION);

        $config = [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('lem_scan_nonce'),
            'crudNonce' => wp_create_nonce('lem_crud_nonce'),
        ];
        add_action('admin_head', function () use ($config) {
            echo '<script>var lemAdmin = ' . wp_json_encode($config) . ';</script>';
        });
    }

    public function page_dashboard() {
        include LEM_DIR . 'admin/views/dashboard.php';
    }

    public function page_entities() {
        include LEM_DIR . 'admin/views/entities.php';
    }

    public function page_scanner() {
        include LEM_DIR . 'admin/views/scanner.php';
    }

    public function page_banned_links() {
        include LEM_DIR . 'admin/views/banned-links.php';
    }

    public function page_settings() {
        include LEM_DIR . 'admin/views/settings.php';
    }

    public function page_report() {
        include LEM_DIR . 'admin/views/report.php';
    }

    public function page_brands() {
        include LEM_DIR . 'admin/views/brands.php';
    }

    /**
     * Выгрузка отчёта «Упоминания» в CSV.
     */
    private function handle_report_export() {
        if (sanitize_key(wp_unslash($_GET['lem_export'] ?? '')) !== 'csv' || !current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('lem_export_csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=lem-mentions-'
            . gmdate('Y-m-d') . '.csv');

        // Отборы те же, что на экране: иначе выгрузка молча отдаёт другое
        $fresh  = sanitize_text_field(wp_unslash($_GET['fresh'] ?? ''));
        $update = lem()->rescan->last_report();
        $since  = '';
        if ($fresh === 'update' && !empty($update['since'])) {
            $since = $update['since'];
        } elseif ($fresh === '30') {
            $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        }

        lem()->report->export_csv([
            'registry'   => sanitize_text_field(wp_unslash($_GET['registry'] ?? '')),
            'mode'       => sanitize_text_field(wp_unslash($_GET['mode'] ?? 'all')),
            'search'     => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'since'      => $since,
            'links_only' => !empty($_GET['links_only']),
        ]);
        exit;
    }

    /**
     * Сохранение брендовых правил со страницы «Бренды СМИ».
     */
    private function handle_brand_actions() {
        if (!isset($_POST['lem_brands_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lem_brands_nonce'])), 'lem_save_brands')
            || !current_user_can('manage_options')) {
            return;
        }

        $rules = [];
        foreach ((array) map_deep(wp_unslash($_POST['lem_brand'] ?? []), 'sanitize_text_field') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $match = $row['match'] ?? '';
            if ($match === '' || !empty($row['delete'])) {
                continue;
            }
            $split = static function ($raw) {
                return array_filter(array_map('trim', explode(',', sanitize_text_field($raw))));
            };
            $rules[] = [
                'match'   => $match,
                'aliases' => $split($row['aliases'] ?? ''),
                'quoted'  => $split($row['quoted'] ?? ''),
                'note'    => ($row['note'] ?? ''),
                'status'  => ($row['status'] ?? ''),
                'enabled' => !empty($row['enabled']),
            ];
        }

        lem()->brands->save_rules($rules);

        // Правила меняют алиасы сущностей, поэтому сразу применяем
        $result = lem()->importer->apply_brand_aliases();

        add_settings_error('lem_brands', 'saved',
            __('Правила сохранены. Алиасов применено: ', 'legal-entity-marks') . (int) $result['applied']
            . __('. Не забудьте пересканировать статьи.', 'legal-entity-marks'), 'success');

        if (!empty($result['unmatched'])) {
            add_settings_error('lem_brands', 'unmatched',
                __('Не нашли ни одной записи в реестре (проверьте текст в «Искать в названии»): ', 'legal-entity-marks')
                . implode('; ', array_map('esc_html', $result['unmatched'])), 'warning');
        }
    }

    public function show_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $error = get_option('lem_last_fetch_error', '');
        if (!empty($error)) {
            $last = get_option('lem_last_fetch_time', '');
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Маркировка:', 'legal-entity-marks') . '</strong> '
                . esc_html__('Реестры обновились не полностью. ', 'legal-entity-marks') . esc_html($error)
                . ($last ? ' (' . esc_html($last) . ')' : '')
                . '</p></div>';
        }

        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        if (lem()->database->table_exists()) {
            /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_active = 1") ;
            /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            if ($count === 0) {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Маркировка:', 'legal-entity-marks') . '</strong> '
                    . esc_html__('Реестр сущностей пуст! Перейдите в Маркировка > Обзор для импорта данных.', 'legal-entity-marks')
                    . '</p></div>';
            }
        }

        $this->show_update_notice();
        $this->show_stale_notice();
    }

    /**
     * Что принесло последнее обновление реестров.
     *
     * Организация, о которой вчера писали свободно, сегодня может оказаться
     * в реестре, а публикация со ссылкой на неё никуда не делась. Поэтому итог
     * проверки архива показываем на всех страницах, пока его не закроют.
     */
    private function show_update_notice() {
        $report = lem()->rescan->last_report();
        if (empty($report['at']) || (int) $report['mentioned'] < 1) {
            return;
        }
        if (get_option('lem_update_notice_seen') === $report['at']) {
            return;
        }

        $report_url = admin_url('admin.php?page=lem-report&fresh=update');
        $hide_url   = wp_nonce_url(
            add_query_arg('lem_hide_update', '1', admin_url('admin.php?page=lem-dashboard')),
            'lem_hide_update'
        );

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Маркировка:', 'legal-entity-marks') . '</strong> ';
        printf(
            /* translators: 1: newly mentioned registry entries, 2: number of articles. */
            esc_html__('После обновления реестров в материалах сайта нашлись новые организации: %1$d в %2$d публикациях.', 'legal-entity-marks'),
            (int) $report['mentioned'], (int) $report['mentioned_in']
        );
        echo ' <a href="' . esc_url($report_url) . '">' . esc_html__('Посмотреть список', 'legal-entity-marks') . '</a> · '
            . '<a href="' . esc_url($hide_url) . '">' . esc_html__('Скрыть', 'legal-entity-marks') . '</a></p></div>';
    }

    /**
     * Реестры давно (или ни разу) не обновлялись из источников.
     *
     * Обычная причина - не работает WP-Cron, и тогда данные тихо устаревают:
     * встроенный снимок остаётся с даты сборки плагина, а новых иноагентов
     * в нём нет. Молчаливую деградацию и делаем заметной.
     */
    private function show_stale_notice() {
        $stale_days = (int) apply_filters('lem_stale_days', 30);
        $last       = get_option('lem_last_fetch_time', '');
        $now        = current_time('timestamp');
        $dashboard  = admin_url('admin.php?page=lem-dashboard');
        $message    = '';

        if (empty($last)) {
            // На свежей установке фетч ставится в очередь и проходит за минуты,
            // поэтому не паникуем сразу после установки
            $installed = get_option('lem_installed_at', '');
            $since     = $installed ? strtotime($installed) : 0;
            if ($since && ($now - $since) > 2 * DAY_IN_SECONDS) {
                $message = __('Реестры ни разу не обновлялись из источников, а плагин установлен ', 'legal-entity-marks')
                    . human_time_diff($since, $now)
                    . __(' назад. Скорее всего не работает WP-Cron, и списки останутся на дате сборки плагина.', 'legal-entity-marks');
            }
        } else {
            $ts = strtotime($last);
            if ($ts && ($now - $ts) > $stale_days * DAY_IN_SECONDS) {
                $message = __('Реестры не обновлялись ', 'legal-entity-marks') . human_time_diff($ts, $now)
                    . __('. Обычно это значит, что не работает WP-Cron.', 'legal-entity-marks');
            }
        }

        if ($message === '') {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Маркировка:', 'legal-entity-marks') . '</strong> '
            . esc_html($message) . ' <a href="' . esc_url($dashboard) . '">' . esc_html__('Обновить сейчас', 'legal-entity-marks')
            . '</a> ' . esc_html__('или запустите', 'legal-entity-marks') . ' <code>wp lem fetch --source=all</code>.</p></div>';
    }

    public function handle_actions() {
        $this->handle_brand_actions();
        $this->handle_report_export();

        if (isset($_GET['lem_hide_update']) && current_user_can('manage_options')) {
            check_admin_referer('lem_hide_update');
            $report = lem()->rescan->last_report();
            update_option('lem_update_notice_seen', $report['at'] ?? '', false);
        }

        if (!isset($_POST['lem_settings_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lem_settings_nonce'])), 'lem_save_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = lem()->get_settings();

        if (isset($_POST['lem_post_types'])) {
            $settings['post_types'] = array_map('sanitize_text_field', (array) wp_unslash($_POST['lem_post_types']));
        }
        if (isset($_POST['lem_filter_priority'])) {
            $settings['filter_priority'] = (int) sanitize_text_field(wp_unslash($_POST['lem_filter_priority']));
        }
        if (isset($_POST['lem_accent_color'])) {
            $settings['accent_color'] = sanitize_hex_color(wp_unslash($_POST['lem_accent_color'])) ?: '#f88c00';
        }
        if (isset($_POST['lem_disclaimer_bg'])) {
            $settings['disclaimer_bg'] = sanitize_hex_color(wp_unslash($_POST['lem_disclaimer_bg'])) ?: '#fff9f0';
        }
        if (isset($_POST['lem_disclaimer_border'])) {
            $settings['disclaimer_border'] = sanitize_hex_color(wp_unslash($_POST['lem_disclaimer_border'])) ?: '#f88c00';
        }
        if (isset($_POST['lem_cron_interval'])) {
            $settings['cron_interval'] = sanitize_text_field(wp_unslash($_POST['lem_cron_interval']));
        }
        $settings['auto_scan_on_publish'] = !empty($_POST['lem_auto_scan']);

        // Профиль сайта и реестры
        $preset = sanitize_text_field(wp_unslash($_POST['lem_preset'] ?? 'manual'));
        $preset = isset(LEM_Plugin::PRESETS[$preset]) ? $preset : 'manual';
        $settings['preset'] = $preset;

        if (LEM_Plugin::PRESETS[$preset]['mark'] !== null) {
            // Галочки профиля приходят отключёнными и браузером не отправляются,
            // поэтому берём их из самого профиля, а не из формы
            $settings['mark_registries']  = LEM_Plugin::PRESETS[$preset]['mark'];
            $settings['track_registries'] = LEM_Plugin::PRESETS[$preset]['track'];
        } elseif (isset($_POST['lem_registries_present'])) {
            $settings['mark_registries'] = array_values(array_intersect(
                LEM_Plugin::REGISTRY_TYPES,
                array_map('sanitize_text_field', (array) wp_unslash($_POST['lem_mark_registries'] ?? []))
            ));
            $settings['track_registries'] = array_values(array_intersect(
                LEM_Plugin::REGISTRY_TYPES,
                array_map('sanitize_text_field', (array) wp_unslash($_POST['lem_track_registries'] ?? []))
            ));
        }
        // Переключение на «Вручную» без работающего скрипта оставляет то, что было:
        // иначе форма молча снимала бы всю маркировку с сайта

        $settings['link_registries'] = array_values(array_intersect(
            LEM_Plugin::REGISTRY_TYPES,
            array_map('sanitize_text_field', (array) wp_unslash($_POST['lem_link_registries'] ?? []))
        ));

        $old_people_config = [$settings['fedsfm_people_mode'], $settings['channel_enabled'],
            $settings['channel_url'], $settings['channel_token']];
        $people_mode = sanitize_text_field(wp_unslash($_POST['lem_fedsfm_people_mode'] ?? 'off'));
        $settings['fedsfm_people_mode'] = in_array($people_mode, LEM_Plugin::PEOPLE_MODES, true)
            ? $people_mode : 'off';

        // Канал реестров. Токен не выводится на страницу, поэтому пустое поле
        // означает «оставить прежний», а не «стереть»: для стирания есть галочка
        $settings['channel_enabled'] = !empty($_POST['lem_channel_enabled']);
        $settings['channel_url']     = sanitize_url(wp_unslash($_POST['lem_channel_url'] ?? ''));
        if ($settings['channel_url'] === '') {
            $settings['channel_url'] = LEM_Channel::DEFAULT_URL;
        }
        $settings['stats_enabled'] = !empty($_POST['lem_stats_enabled']);
        $settings['stats_consent_at'] = $settings['stats_enabled']
            ? ($settings['stats_consent_at'] ?: current_time('mysql', true)) : '';
        $settings['delete_data_on_uninstall'] = !empty($_POST['lem_delete_data']);

        $token_input = sanitize_text_field(wp_unslash($_POST['lem_channel_token'] ?? ''));
        if (!empty($_POST['lem_channel_token_clear'])) {
            $settings['channel_token'] = '';
        } elseif ($token_input !== '') {
            $settings['channel_token'] = sanitize_text_field($token_input);
        }

        unset($settings['registries']); // пересоберётся из mark_registries

        // Морфология: словоформы и правило для одиночной фамилии
        $old_forms   = $settings['match_word_forms'];
        $old_surname = $settings['surname_mode'];
        $settings['match_word_forms'] = !empty($_POST['lem_match_word_forms']);
        $mode = sanitize_text_field(wp_unslash($_POST['lem_surname_mode'] ?? 'confirmed'));
        $settings['surname_mode'] = in_array($mode, LEM_Plugin::SURNAME_MODES, true)
            ? $mode
            : 'confirmed';
        $morphology_changed = ($old_forms !== $settings['match_word_forms'])
            || ($old_surname !== $settings['surname_mode']);

        // Дополнительные поля записи
        $old_extra = [$settings['extra_fields_mode'], $settings['extra_fields']];
        $mode_extra = sanitize_text_field(wp_unslash($_POST['lem_extra_fields_mode'] ?? 'off'));
        $settings['extra_fields_mode'] = in_array($mode_extra, ['off', 'selected', 'all'], true)
            ? $mode_extra : 'off';
        $settings['extra_fields'] = array_map('sanitize_text_field',
            (array) wp_unslash($_POST['lem_extra_fields'] ?? []));
        if ($old_extra !== [$settings['extra_fields_mode'], $settings['extra_fields']]) {
            $morphology_changed = true; // нужен перескан: изменился объём текста
        }

        // Маркировка исключённых из реестра
        $old_excluded = $settings['mark_excluded'];
        $settings['mark_excluded'] = !empty($_POST['lem_mark_excluded']);
        if ($old_excluded !== $settings['mark_excluded']) {
            $morphology_changed = true; // требует пересканирования
        }

        // Контекстный режим для иноагентов
        $settings['inoagent_context_only'] = !empty($_POST['lem_inoagent_context_only']);
        $posted_triggers = array_map('sanitize_text_field', (array) wp_unslash($_POST['lem_context_triggers'] ?? []));
        $old_triggers    = $settings['context_triggers'];
        $new_triggers    = [];
        foreach (LEM_Plugin::CONTEXT_TRIGGERS as $trigger) {
            $new_triggers[$trigger] = in_array($trigger, $posted_triggers, true);
        }
        $settings['context_triggers'] = $new_triggers;

        lem()->update_settings($settings);

        lem()->cron->clear_events();
        lem()->cron->schedule_events();
        lem()->entities->flush_cache();
        $people_config = [$settings['fedsfm_people_mode'], $settings['channel_enabled'],
            $settings['channel_url'], $settings['channel_token']];
        if ($settings['fedsfm_people_mode'] === 'off') {
            lem()->people->cancel();
        } else {
            $people_state = (array) get_option(LEM_People::STATE, []);
            $restart_people = $old_people_config[0] === 'off'
                || array_slice($people_config, 1) !== array_slice($old_people_config, 1);
            if ($restart_people || !empty($people_state['running']) || !empty($people_state['error'])
                || !get_option(LEM_People::SUCCESS)) {
                lem()->people->queue($restart_people);
            }
        }

        // Кеш страниц держит старую разметку дисклеймеров
        lem()->cache->purge_all_marked();

        add_settings_error('lem_settings', 'saved', __('Настройки сохранены.', 'legal-entity-marks'), 'success');

        if ($morphology_changed
            || ($new_triggers !== $old_triggers && $settings['inoagent_context_only'])) {
            add_settings_error(
                'lem_settings',
                'rescan',
                __('Правила поиска изменились. Запустите пересканирование в разделе «Сканер», ', 'legal-entity-marks')
                . __('иначе для ранее отсканированных статей останется прежний результат.', 'legal-entity-marks'),
                'warning'
            );
        }

        $fresh = lem()->get_settings();
        if (empty($fresh['mark_registries']) && empty($fresh['track_registries'])) {
            add_settings_error(
                'lem_settings',
                'no_registries',
                __('Не выбран ни один реестр: плагин ничего не будет ни маркировать, ни отслеживать.', 'legal-entity-marks'),
                'warning'
            );
        } elseif (empty($fresh['mark_registries'])) {
            add_settings_error(
                'lem_settings',
                'track_only',
                __('Маркировка отключена полностью. Упоминания будут видны только в разделе «Упоминания».', 'legal-entity-marks'),
                'info'
            );
        }
    }

    public function ajax_save_entity() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        $id   = absint(wp_unslash($_POST['id'] ?? 0));
        $data = [
            'type'        => sanitize_text_field(wp_unslash($_POST['type'] ?? 'inoagent')),
            'name'        => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'aliases'     => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['aliases'] ?? ''))))),
            'is_person'   => absint(wp_unslash($_POST['is_person'] ?? 0)),
            'status_text' => sanitize_text_field(wp_unslash($_POST['status_text'] ?? '')),
            'is_active'   => absint(wp_unslash($_POST['is_active'] ?? 1)),
        ];

        if (empty($data['name'])) {
            wp_send_json_error(__('Укажите название', 'legal-entity-marks'));
        }

        if ($id > 0) {
            lem()->entities->update($id, $data);
            wp_send_json_success(['id' => $id, 'action' => 'updated']);
        } else {
            $new_id = lem()->entities->insert($data);
            if ($new_id) {
                wp_send_json_success(['id' => $new_id, 'action' => 'created']);
            } else {
                wp_send_json_error(__('Не удалось создать запись', 'legal-entity-marks'));
            }
        }
    }

    public function ajax_delete_entity() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        $id = absint(wp_unslash($_POST['id'] ?? 0));
        if ($id > 0) {
            lem()->entities->delete($id);
            wp_send_json_success(['id' => $id]);
        } else {
            wp_send_json_error(__('Неверный ID', 'legal-entity-marks'));
        }
    }

    public function ajax_fetch_registries() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        $errors  = lem()->importer->fetch_all();
        $domains = (int) lem()->importer->last_fetch_domains;
        $extra   = $domains > 0
            ? __('. Из записей реестра добавлено доменов: ', 'legal-entity-marks') . $domains
            : '';

        $extra .= __('. Проверка архива запущена в фоне', 'legal-entity-marks');
        $success_message = __('Все реестры обновлены', 'legal-entity-marks');
        if (LEM_People::enabled(lem()->get_settings())) {
            $success_message = __('Основные реестры обновлены', 'legal-entity-marks');
            $people_state = (array) get_option(LEM_People::STATE, []);
            $extra .= !empty($people_state['error'])
                ? __('. Физлица Росфинмониторинга не обновлены: ', 'legal-entity-marks') . $people_state['error']
                : __('. Физлица Росфинмониторинга загружаются отдельно в фоне', 'legal-entity-marks');
        }

        if (empty($errors)) {
            wp_send_json_success(['message' => $success_message . $extra]);
        } else {
            wp_send_json_success(['message' => __('Завершено с ошибками', 'legal-entity-marks') . $extra, 'errors' => $errors]);
        }
    }

    /**
     * Опрос источников с этого сервера: у поддержки нет доступа к чужому хостингу,
     * а «реестры не обновляются» может означать что угодно
     */
    public function ajax_check_sources() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }
        wp_send_json_success(['sources' => lem()->importer->check_sources()]);
    }

    /**
     * Шаг проверки архива по требованию.
     *
     * Очередь рассчитана на WP-Cron, но он срабатывает только при заходах
     * на сайт, а на части хостингов отключён вовсе. Кнопка в админке даёт
     * прогнать архив вручную, не дожидаясь крона.
     */
    public function ajax_rescan_step() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        if (!empty($_POST['start'])) {
            lem()->rescan->enqueue('manual', current_time('mysql'));
        }

        lem()->rescan->run();
        $state = lem()->rescan->status();

        wp_send_json_success([
            'offset'  => (int) ($state['offset'] ?? 0),
            'total'   => (int) ($state['total'] ?? 0),
            'percent' => (int) ($state['percent'] ?? 100),
            'done'    => !empty($state['done']),
        ]);
    }

    /**
     * Проверка канала реестров из админки.
     * Токен в ответ не попадает: наружу идут только состояние и дата среза.
     */
    public function ajax_check_channel() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        $health = lem()->channel->health();
        $lines  = [];

        if ($health['ok']) {
            $snapshot = $health['last_snapshot'] !== ''
                ? date_i18n('d.m.Y H:i', strtotime($health['last_snapshot']))
                : __('дата не указана', 'legal-entity-marks');
            $lines[] = __('Канал доступен, последний срез: ', 'legal-entity-marks') . $snapshot;
        } else {
            $lines[] = __('Канал недоступен: ', 'legal-entity-marks') . $health['error'];
        }

        // Пробуем самый маленький реестр: выгрузки открыты, токен для них не нужен
        $probe = lem()->channel->fetch('terrorist');
        $lines[] = isset($probe['error'])
            ? __('Выгрузка не удалась: ', 'legal-entity-marks') . $probe['error']
            : __('Тестовая выгрузка: ', 'legal-entity-marks') . (int) $probe['count'] . __(' записей', 'legal-entity-marks');

        if (!lem()->channel->has_token()) {
            $lines[] = __('Токен не задан, и для этих реестров он не нужен', 'legal-entity-marks');
        }

        wp_send_json_success(['message' => implode('. ', $lines)]);
    }

    /**
     * Регистрация сайта у автора. Только по нажатию кнопки и только тем
     * составом, который человек увидел на странице настроек.
     */
    public function ajax_register_site() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        $email  = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $result = lem()->channel->register_site($email);

        if (empty($result['ok'])) {
            wp_send_json_error($result['message']);
        }
        wp_send_json_success(['message' => $result['message']]);
    }

    public function ajax_purge_cache() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        lem()->entities->flush_cache();
        $purged = lem()->cache->purge_all_marked();
        wp_send_json_success(['purged' => $purged]);
    }

    public function ajax_save_banned_site() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        $id   = absint(wp_unslash($_POST['id'] ?? 0));
        $data = [
            'domain'    => sanitize_text_field(wp_unslash($_POST['domain'] ?? '')),
            'label'     => sanitize_text_field(wp_unslash($_POST['label'] ?? '')),
            'registry'  => sanitize_text_field(wp_unslash($_POST['registry'] ?? '')),
            'entity_id' => absint(wp_unslash($_POST['entity_id'] ?? 0)) ?: null,
        ];

        if (empty($data['domain'])) {
            wp_send_json_error(__('Укажите домен', 'legal-entity-marks'));
        }

        if ($id > 0) {
            lem()->banned_sites->update($id, $data);
            wp_send_json_success(['id' => $id, 'action' => 'updated']);
        } else {
            $new_id = lem()->banned_sites->insert($data);
            if ($new_id) {
                wp_send_json_success(['id' => $new_id, 'action' => 'created']);
            } else {
                wp_send_json_error(__('Не удалось создать запись (возможно, домен уже существует)', 'legal-entity-marks'));
            }
        }
    }

    public function ajax_delete_banned_site() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        $id = absint(wp_unslash($_POST['id'] ?? 0));
        if ($id > 0) {
            lem()->banned_sites->delete($id);
            wp_send_json_success(['id' => $id]);
        } else {
            wp_send_json_error(__('Неверный ID', 'legal-entity-marks'));
        }
    }

    public function ajax_import_banned_sites() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Нет доступа', 'legal-entity-marks'));
        }

        $raw   = sanitize_textarea_field(wp_unslash($_POST['domains'] ?? ''));
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $added   = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            $parts  = array_map('trim', explode('|', $line, 2));
            $domain = LEM_Banned_Sites::normalize_domain($parts[0]);
            $label  = $parts[1] ?? '';
            if (empty($domain)) {
                $skipped++;
                continue;
            }

            $result = lem()->banned_sites->insert([
                'domain' => $domain,
                'label'  => $label,
            ]);
            if ($result) {
                $added++;
            } else {
                $skipped++;
            }
        }

        wp_send_json_success([
            'added'   => $added,
            'skipped' => $skipped,
            'message' => "Добавлено: $added, пропущено: $skipped",
        ]);
    }
}
