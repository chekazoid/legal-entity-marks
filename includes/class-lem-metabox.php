<?php
defined('ABSPATH') || exit;

/**
 * Блок «Маркировка» в редакторе статьи.
 *
 * Показывает, что нашёл сканер, и позволяет редактору снять ложное
 * срабатывание или, наоборот, промаркировать упоминание, которое отсекло
 * правило контекста. Доступен ролям «Редактор» и выше.
 */
class LEM_Metabox {

    const NONCE_FIELD = 'lem_overrides_nonce';
    const NONCE_ACTION = 'lem_save_overrides';

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
        foreach (lem()->get_settings()['post_types'] as $post_type) {
            add_meta_box(
                'lem-overrides',
                'Маркировка',
                [$this, 'render'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render($post) {
        $raw      = get_post_meta($post->ID, LEM_META_KEY, true);
        $meta     = $raw ? json_decode($raw, true) : [];
        $entities = $meta['entities'] ?? [];

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        // Маркер того, что блок был на странице: иначе снятие всех галочек
        // неотличимо от сохранения из Gutenberg, где метабокса нет.
        echo '<input type="hidden" name="lem_overrides_present" value="1">';

        if (empty($entities)) {
            echo '<p>Упоминаний из реестров не найдено.</p>';
            if (empty($raw)) {
                echo '<p class="description">Статья ещё не сканировалась. Она будет проверена при сохранении.</p>';
            }
            return;
        }

        $settings     = lem()->get_settings();
        $overrides    = LEM_Frontend::get_overrides($post->ID);
        $entities_db  = lem()->entities->get_all_active();
        $active_ids   = [];
        foreach ($entities_db as $e) {
            $active_ids[(int) $e['id']] = true;
        }

        $labels = [
            'inoagent'    => 'иноагент',
            'extremist'   => 'экстремистская',
            'terrorist'   => 'террористическая',
            'undesirable' => 'нежелательная',
        ];

        // Пустые переопределения: чтобы узнать, что дало бы чистое «Автоматически»
        $no_overrides = ['excluded' => [], 'forced' => []];
        $shown        = 0;

        foreach ($entities as $match) {
            $id = (int) $match['id'];
            if (!isset($active_ids[$id])) {
                continue; // сущность исключена из реестра, маркировка не ставится
            }
            if (!in_array($match['type'], $settings['registries'], true)) {
                continue; // категория выключена в настройках
            }

            $state = 'auto';
            if (in_array($id, $overrides['excluded'], true)) {
                $state = 'exclude';
            } elseif (in_array($id, $overrides['forced'], true)) {
                $state = 'force';
            }

            $auto_marks = LEM_Frontend::should_mark($match, $settings, $no_overrides);
            $auto_hint  = $auto_marks
                ? 'Автоматически (сейчас помечается)'
                : 'Автоматически (сейчас не помечается: ' . self::skip_reason($match, $settings) . ')';

            $shown++;
            printf(
                '<p style="margin:0 0 4px"><strong>%s</strong> <span style="color:#777">(%s)</span></p>',
                esc_html($match['name']),
                esc_html($labels[$match['type']] ?? $match['type'])
            );
            printf('<select name="lem_override[%d]" style="width:100%%;margin-bottom:12px">', $id);
            foreach ([
                'auto'    => $auto_hint,
                'force'   => 'Всегда помечать',
                'exclude' => 'Не помечать',
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
            echo '<p>Упоминаний из реестров не найдено.</p>';
            return;
        }

        echo '<p class="description">«Автоматически» подчиняется общим правилам плагина. '
            . 'Два других положения действуют только для этой статьи.</p>';

        if (!empty($settings['inoagent_context_only'])) {
            echo '<p class="description">Иноагенты маркируются только в цитатах, ссылках и встроенных постах '
                . '(режим включён в настройках плагина).</p>';
        }
    }

    /**
     * Почему автоматические правила не помечают это упоминание.
     */
    private static function skip_reason($match, $settings) {
        if (($match['type'] ?? '') === 'inoagent'
            && !empty($settings['inoagent_context_only'])
            && array_key_exists('in_context', $match)
            && empty($match['in_context'])) {
            return 'нет цитаты или ссылки';
        }
        return 'по правилам плагина';
    }

    public function save($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (empty($_POST['lem_overrides_present'])) {
            return; // сохранение не из редактора с нашим блоком
        }
        if (!isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }
        if (!self::user_can_manage($post_id)) {
            return;
        }

        // Переключатель из трёх положений на каждую сущность:
        // auto (без переопределения) | force (всегда помечать) | exclude (не помечать)
        $excluded = [];
        $forced   = [];
        foreach ((array) ($_POST['lem_override'] ?? []) as $id => $value) {
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
            delete_post_meta($post_id, LEM_OVERRIDES_META_KEY);
        } else {
            update_post_meta(
                $post_id,
                LEM_OVERRIDES_META_KEY,
                wp_slash(wp_json_encode([
                    'excluded' => array_values($excluded),
                    'forced'   => $forced,
                ], JSON_UNESCAPED_UNICODE))
            );
        }

        lem()->cache->purge_post($post_id);
    }
}
