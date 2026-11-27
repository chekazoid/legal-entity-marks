<?php
defined('ABSPATH') || exit;

/**
 * Блок «Маркировка» в редакторе статьи.
 *
 * Показывает, что нашёл сканер, и позволяет редактору снять ложное
 * срабатывание или, наоборот, промаркировать упоминание, которое отсекло
 * правило контекста. Доступен ролям «Редактор» и выше.
 */
class CLEM_Metabox {

    const NONCE_FIELD = 'clem_overrides_nonce';
    const NONCE_ACTION = 'clem_save_overrides';

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('save_post', [$this, 'save'], 10, 2);
    }

    /**
     * Право менять маркировку: «Редактор» и выше (edit_others_posts),
     * плюс обычная проверка прав на конкретную запись.
     */
    public static function user_can_manage($post_id = null) {
        if (!current_user_can('edit_others_posts')) {
            return false;
        }
        if ($post_id !== null && !current_user_can('edit_post', $post_id)) {
            return false;
        }
        return true;
    }

    public function register() {
        if (!self::user_can_manage()) {
            return;
        }
        foreach (clem_plugin()->get_settings()['post_types'] as $post_type) {
            add_meta_box(
                'clem-overrides',
                __('Маркировка', 'chekazoid-legal-entity-marks'),
                [$this, 'render'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render($post) {
        $raw      = get_post_meta($post->ID, CLEM_META_KEY, true);
        $meta     = $raw ? json_decode($raw, true) : [];
        $entities = $meta['entities'] ?? [];

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        // Маркер того, что блок был на странице: иначе снятие всех галочек
        // неотличимо от сохранения из Gutenberg, где метабокса нет.
        echo '<input type="hidden" name="clem_overrides_present" value="1">';

        if (empty($entities)) {
            echo '<p>' . esc_html__('Упоминаний из реестров не найдено.', 'chekazoid-legal-entity-marks') . '</p>';
            if (empty($raw)) {
                echo '<p class="description">' . esc_html__('Статья ещё не сканировалась. Она будет проверена при сохранении.', 'chekazoid-legal-entity-marks') . '</p>';
            }
            return;
        }

        $settings     = clem_plugin()->get_settings();
        $overrides    = CLEM_Frontend::get_overrides($post->ID);
        $entities_db  = clem_plugin()->entities->get_all_active();
        $active_ids   = [];
        foreach ($entities_db as $e) {
            $active_ids[(int) $e['id']] = $e;
        }

        $labels = [
            'inoagent'    => __('иноагент', 'chekazoid-legal-entity-marks'),
            'extremist'   => __('экстремистская', 'chekazoid-legal-entity-marks'),
            'terrorist'   => __('террористическая', 'chekazoid-legal-entity-marks'),
            'undesirable' => __('нежелательная', 'chekazoid-legal-entity-marks'),
            'fedsfm_person' => __('Физлица: Росфинмониторинг', 'chekazoid-legal-entity-marks'),
        ];

        // Пустые переопределения: чтобы узнать, что дало бы чистое «Автоматически»
        $no_overrides = ['excluded' => [], 'forced' => []];
        $shown        = 0;

        foreach ($entities as $match) {
            $id = (int) $match['id'];
            if (!isset($active_ids[$id])) {
                continue; // сущность исключена из реестра, маркировка не ставится
            }
            $match = array_merge($match, $active_ids[$id]);
            if (!CLEM_Scanner::is_current_mention($match, $settings)) { continue; }
            if (!in_array($match['type'], $settings['track_registries'], true)) {
                continue; // реестр не маркируется и не отслеживается
            }

            $state = 'auto';
            if (in_array($id, $overrides['excluded'], true)) {
                $state = 'exclude';
            } elseif (in_array($id, $overrides['forced'], true)) {
                $state = 'force';
            }

            $auto_marks = CLEM_Frontend::should_mark($match, $settings, $no_overrides);
            $auto_hint  = $auto_marks
                ? __('Автоматически (сейчас помечается)', 'chekazoid-legal-entity-marks')
                : __('Автоматически (сейчас не помечается: ', 'chekazoid-legal-entity-marks') . self::skip_reason($match, $settings) . ')';

            $shown++;
            printf(
                '<p style="margin:0 0 4px"><strong>%s</strong> <span style="color:#777">(%s)</span></p>',
                esc_html($match['name']),
                esc_html($labels[$match['type']] ?? $match['type'])
            );
            printf('<select name="clem_override[%d]" style="width:100%%;margin-bottom:12px">', (int) $id);
            foreach ([
                'auto'    => $auto_hint,
                'force'   => __('Всегда помечать', 'chekazoid-legal-entity-marks'),
// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Editor override option named exclude; this is not a WP_Query argument.
                'exclude' => __('Не помечать', 'chekazoid-legal-entity-marks'),
            ] as $value => $label) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($value),
                    selected($state, $value, false),
                    esc_html($label)
                );
            }
            echo '</select>';
        }

        if ($shown === 0) {
            echo '<p>' . esc_html__('Упоминаний из реестров не найдено.', 'chekazoid-legal-entity-marks') . '</p>';
            return;
        }

        echo '<p class="description">' . esc_html__('«Автоматически» подчиняется общим правилам плагина. Ручной выбор действует только в этой статье. Для четырёх основных реестров «Всегда помечать» работает и при включённом отслеживании без автоматической маркировки.', 'chekazoid-legal-entity-marks') . '</p>';

        if (!empty($settings['inoagent_context_only'])) {
            echo '<p class="description">' . esc_html__('Иноагенты маркируются только в цитатах, ссылках и встроенных постах (режим включён в настройках плагина).', 'chekazoid-legal-entity-marks') . '</p>';
        }
    }

    /**
     * Почему автоматические правила не помечают это упоминание.
     */
    private static function skip_reason($match, $settings) {
        if (!in_array($match['type'] ?? '', (array) $settings['mark_registries'], true)) {
            return __('автоматическая маркировка реестра выключена', 'chekazoid-legal-entity-marks');
        }
        if (($match['type'] ?? '') === 'inoagent'
            && !empty($settings['inoagent_context_only'])
            && array_key_exists('in_context', $match)
            && empty($match['in_context'])) {
            return __('нет цитаты или ссылки', 'chekazoid-legal-entity-marks');
        }
        return __('по правилам плагина', 'chekazoid-legal-entity-marks');
    }

    public function save($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (empty($_POST['clem_overrides_present'])) {
            return; // сохранение не из редактора с нашим блоком
        }
        if (!isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            return;
        }
        if (!self::user_can_manage($post_id)) {
            return;
        }

        // Переключатель из трёх положений на каждую сущность:
        // auto (без переопределения) | force (всегда помечать) | exclude (не помечать)
        $excluded = [];
        $forced   = [];
        foreach (array_map('sanitize_text_field', (array) wp_unslash($_POST['clem_override'] ?? [])) as $id => $value) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $value = sanitize_text_field($value);
            if ($value === 'exclude') {
                $excluded[] = $id;
            } elseif ($value === 'force') {
                $forced[] = $id;
            }
            // 'auto' не записываем: пустое переопределение и есть автомат
        }
        $excluded = array_values(array_unique($excluded));
        $forced   = array_values(array_diff(array_unique($forced), $excluded));

        if (empty($excluded) && empty($forced)) {
            delete_post_meta($post_id, CLEM_OVERRIDES_META_KEY);
        } else {
            update_post_meta(
                $post_id,
                CLEM_OVERRIDES_META_KEY,
                wp_slash(wp_json_encode([
                    'excluded' => array_values($excluded),
                    'forced'   => $forced,
                ], JSON_UNESCAPED_UNICODE))
            );
        }

        clem_plugin()->cache->purge_post($post_id);
    }
}
