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

        add_action('wp_ajax_lem_save_banned_site', [$this, 'ajax_save_banned_site']);
        add_action('wp_ajax_lem_delete_banned_site', [$this, 'ajax_delete_banned_site']);
        add_action('wp_ajax_lem_import_banned_sites', [$this, 'ajax_import_banned_sites']);
    }

    public function register_menu() {
        add_menu_page(
            'Маркировка',
            'Маркировка',
            'manage_options',
            'lem-dashboard',
            [$this, 'page_dashboard'],
            'dashicons-warning',
            80
        );

        add_submenu_page('lem-dashboard', 'Обзор', 'Обзор', 'manage_options', 'lem-dashboard', [$this, 'page_dashboard']);
        add_submenu_page('lem-dashboard', 'Реестр', 'Реестр', 'manage_options', 'lem-entities', [$this, 'page_entities']);
        add_submenu_page('lem-dashboard', 'Сканер', 'Сканер', 'manage_options', 'lem-scanner', [$this, 'page_scanner']);
        add_submenu_page('lem-dashboard', 'Ссылки', 'Ссылки', 'manage_options', 'lem-banned-links', [$this, 'page_banned_links']);
        add_submenu_page('lem-dashboard', 'Бренды СМИ', 'Бренды СМИ', 'manage_options', 'lem-brands', [$this, 'page_brands']);
        add_submenu_page('lem-dashboard', 'Настройки', 'Настройки', 'manage_options', 'lem-settings', [$this, 'page_settings']);
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

    public function page_brands() {
        include LEM_DIR . 'admin/views/brands.php';
    }

    /**
     * Сохранение брендовых правил со страницы «Бренды СМИ».
     */
    private function handle_brand_actions() {
        if (!isset($_POST['lem_brands_nonce'])
            || !wp_verify_nonce($_POST['lem_brands_nonce'], 'lem_save_brands')
            || !current_user_can('manage_options')) {
            return;
        }

        $rules = [];
        foreach ((array) ($_POST['lem_brand'] ?? []) as $row) {
            $match = sanitize_text_field(wp_unslash($row['match'] ?? ''));
            if ($match === '' || !empty($row['delete'])) {
                continue;
            }
            $split = static function ($raw) {
                return array_filter(array_map('trim', explode(',', sanitize_text_field(wp_unslash($raw)))));
            };
            $rules[] = [
                'match'   => $match,
                'aliases' => $split($row['aliases'] ?? ''),
                'quoted'  => $split($row['quoted'] ?? ''),
                'note'    => sanitize_text_field(wp_unslash($row['note'] ?? '')),
                'enabled' => !empty($row['enabled']),
            ];
        }

        lem()->brands->save_rules($rules);

        // Правила меняют алиасы сущностей, поэтому сразу применяем
        $result = lem()->importer->apply_brand_aliases();

        add_settings_error('lem_brands', 'saved',
            'Правила сохранены. Алиасов применено: ' . (int) $result['applied']
            . '. Не забудьте пересканировать статьи.', 'success');

        if (!empty($result['unmatched'])) {
            add_settings_error('lem_brands', 'unmatched',
                'Не нашли ни одной записи в реестре (проверьте текст в «Искать в названии»): '
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
            echo '<div class="notice notice-warning"><p><strong>Маркировка:</strong> '
                . 'Ошибка обновления реестров: ' . esc_html($error)
                . ($last ? ' (' . esc_html($last) . ')' : '')
                . '</p></div>';
        }

        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        if (lem()->database->table_exists()) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_active = 1");
            if ($count === 0) {
                echo '<div class="notice notice-error"><p><strong>Маркировка:</strong> '
                    . 'Реестр сущностей пуст! Перейдите в Маркировка &rarr; Обзор для импорта данных.'
                    . '</p></div>';
            }
        }

        $this->show_stale_notice();
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
                $message = 'Реестры ни разу не обновлялись из источников, а плагин установлен '
                    . human_time_diff($since, $now)
                    . ' назад. Скорее всего не работает WP-Cron, и списки останутся на дате сборки плагина.';
            }
        } else {
            $ts = strtotime($last);
            if ($ts && ($now - $ts) > $stale_days * DAY_IN_SECONDS) {
                $message = 'Реестры не обновлялись ' . human_time_diff($ts, $now)
                    . '. Обычно это значит, что не работает WP-Cron.';
            }
        }

        if ($message === '') {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>Маркировка:</strong> %s '
            . '<a href="%s">Обновить сейчас</a> или запустите <code>wp lem fetch --source=all</code>.</p></div>',
            esc_html($message),
            esc_url($dashboard)
        );
    }

    public function handle_actions() {
        $this->handle_brand_actions();

        if (!isset($_POST['lem_settings_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['lem_settings_nonce'], 'lem_save_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = lem()->get_settings();

        if (isset($_POST['lem_post_types'])) {
            $settings['post_types'] = array_map('sanitize_text_field', (array) $_POST['lem_post_types']);
        }
        if (isset($_POST['lem_filter_priority'])) {
            $settings['filter_priority'] = (int) $_POST['lem_filter_priority'];
        }
        if (isset($_POST['lem_accent_color'])) {
            $settings['accent_color'] = sanitize_hex_color($_POST['lem_accent_color']) ?: '#f88c00';
        }
        if (isset($_POST['lem_disclaimer_bg'])) {
            $settings['disclaimer_bg'] = sanitize_hex_color($_POST['lem_disclaimer_bg']) ?: '#fff9f0';
        }
        if (isset($_POST['lem_disclaimer_border'])) {
            $settings['disclaimer_border'] = sanitize_hex_color($_POST['lem_disclaimer_border']) ?: '#f88c00';
        }
        if (isset($_POST['lem_cron_interval'])) {
            $settings['cron_interval'] = sanitize_text_field(wp_unslash($_POST['lem_cron_interval']));
        }
        $settings['auto_scan_on_publish'] = !empty($_POST['lem_auto_scan']);

        // Категории реестров: отсутствие ключа означает «все сняты»
        $posted_registries = array_map('sanitize_text_field', (array) ($_POST['lem_registries'] ?? []));
        $settings['registries'] = array_values(array_intersect(
            LEM_Plugin::REGISTRY_TYPES,
            $posted_registries
        ));

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

        // Маркировка исключённых из реестра
        $old_excluded = $settings['mark_excluded'];
        $settings['mark_excluded'] = !empty($_POST['lem_mark_excluded']);
        if ($old_excluded !== $settings['mark_excluded']) {
            $morphology_changed = true; // требует пересканирования
        }

        // Контекстный режим для иноагентов
        $settings['inoagent_context_only'] = !empty($_POST['lem_inoagent_context_only']);
        $posted_triggers = array_map('sanitize_text_field', (array) ($_POST['lem_context_triggers'] ?? []));
        $old_triggers    = $settings['context_triggers'];
        $new_triggers    = [];
        foreach (LEM_Plugin::CONTEXT_TRIGGERS as $trigger) {
            $new_triggers[$trigger] = in_array($trigger, $posted_triggers, true);
        }
        $settings['context_triggers'] = $new_triggers;

        lem()->update_settings($settings);

        lem()->cron->clear_events();
        lem()->cron->schedule_events();

        // Кеш страниц держит старую разметку дисклеймеров
        lem()->cache->purge_all_marked();

        add_settings_error('lem_settings', 'saved', 'Настройки сохранены.', 'success');

        if ($morphology_changed
            || ($new_triggers !== $old_triggers && $settings['inoagent_context_only'])) {
            add_settings_error(
                'lem_settings',
                'rescan',
                'Правила поиска изменились. Запустите пересканирование в разделе «Сканер», '
                . 'иначе для ранее отсканированных статей останется прежний результат.',
                'warning'
            );
        }

        if (empty($settings['registries'])) {
            add_settings_error(
                'lem_settings',
                'no_registries',
                'Не выбрана ни одна категория: маркировка не будет выводиться нигде.',
                'warning'
            );
        }
    }

    public function ajax_save_entity() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $id   = (int) ($_POST['id'] ?? 0);
        $data = [
            'type'        => sanitize_text_field(wp_unslash($_POST['type'] ?? 'inoagent')),
            'name'        => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'aliases'     => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['aliases'] ?? ''))))),
            'is_person'   => (int) ($_POST['is_person'] ?? 0),
            'status_text' => sanitize_text_field(wp_unslash($_POST['status_text'] ?? '')),
            'is_active'   => (int) ($_POST['is_active'] ?? 1),
        ];

        if (empty($data['name'])) {
            wp_send_json_error('Укажите название');
        }

        if ($id > 0) {
            lem()->entities->update($id, $data);
            wp_send_json_success(['id' => $id, 'action' => 'updated']);
        } else {
            $new_id = lem()->entities->insert($data);
            if ($new_id) {
                wp_send_json_success(['id' => $new_id, 'action' => 'created']);
            } else {
                wp_send_json_error('Не удалось создать запись');
            }
        }
    }

    public function ajax_delete_entity() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            lem()->entities->delete($id);
            wp_send_json_success(['id' => $id]);
        } else {
            wp_send_json_error('Неверный ID');
        }
    }

    public function ajax_fetch_registries() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $errors = lem()->importer->fetch_all();
        if (empty($errors)) {
            wp_send_json_success(['message' => 'Все реестры обновлены']);
        } else {
            wp_send_json_success(['message' => 'Завершено с ошибками', 'errors' => $errors]);
        }
    }

    public function ajax_purge_cache() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        lem()->entities->flush_cache();
        $purged = lem()->cache->purge_all_marked();
        wp_send_json_success(['purged' => $purged]);
    }

    public function ajax_save_banned_site() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $id   = (int) ($_POST['id'] ?? 0);
        $data = [
            'domain'    => sanitize_text_field(wp_unslash($_POST['domain'] ?? '')),
            'label'     => sanitize_text_field(wp_unslash($_POST['label'] ?? '')),
            'entity_id' => (int) ($_POST['entity_id'] ?? 0) ?: null,
        ];

        if (empty($data['domain'])) {
            wp_send_json_error('Укажите домен');
        }

        if ($id > 0) {
            lem()->banned_sites->update($id, $data);
            wp_send_json_success(['id' => $id, 'action' => 'updated']);
        } else {
            $new_id = lem()->banned_sites->insert($data);
            if ($new_id) {
                wp_send_json_success(['id' => $new_id, 'action' => 'created']);
            } else {
                wp_send_json_error('Не удалось создать запись (возможно, домен уже существует)');
            }
        }
    }

    public function ajax_delete_banned_site() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            lem()->banned_sites->delete($id);
            wp_send_json_success(['id' => $id]);
        } else {
            wp_send_json_error('Неверный ID');
        }
    }

    public function ajax_import_banned_sites() {
        check_ajax_referer('lem_crud_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Нет доступа');
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
