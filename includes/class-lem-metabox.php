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

        $marked = [];
        $hidden = [];
        foreach ($entities as $match) {
            $id = (int) $match['id'];
            if (!isset($active_ids[$id])) {
                continue; // сущность исключена из реестра, маркировка не ставится
            }
            if (!in_array($match['type'], $settings['registries'], true)) {
                continue; // категория выключена в настройках
            }
            if (LEM_Frontend::should_mark($match, $settings, $overrides)) {
                $marked[] = $match;
            } else {
                $hidden[] = $match;
            }
        }

        $labels = [
            'inoagent'    => 'иноагент',
            'extremist'   => 'экстремистская',
            'terrorist'   => 'террористическая',
            'undesirable' => 'нежелательная',
        ];

        if (!empty($marked)) {
            echo '<p><strong>Маркируется</strong></p><ul style="margin:0 0 12px">';
            foreach ($marked as $match) {
                $id = (int) $match['id'];
                printf(
                    '<li style="margin-bottom:6px"><label><input type="checkbox" name="lem_excluded[]" value="%d"> '
                    . '<span>%s</span> <span style="color:#777">(%s)</span></label>'
                    . '<br><span class="description" style="margin-left:22px">снять маркировку в этой статье</span></li>',
                    $id,
                    esc_html($match['name']),
                    esc_html($labels[$match['type']] ?? $match['type'])
                );
            }
            echo '</ul>';
        }

        if (!empty($hidden)) {
            echo '<p><strong>Не маркируется</strong></p><ul style="margin:0 0 12px">';
            foreach ($hidden as $match) {
                $id        = (int) $match['id'];
                $excluded  = in_array($id, $overrides['excluded'], true);
                $reason    = $excluded
                    ? 'снято вручную'
                    : 'нет цитаты или ссылки';
                $field     = $excluded ? 'lem_excluded[]' : 'lem_forced[]';
                $checked   = $excluded ? ' checked' : '';
                $hint      = $excluded
                    ? 'снято вручную, снимите галочку чтобы вернуть'
                    : 'промаркировать всё равно';
                printf(
                    '<li style="margin-bottom:6px"><label><input type="checkbox" name="%s" value="%d"%s> '
                    . '<span>%s</span> <span style="color:#777">(%s)</span></label>'
                    . '<br><span class="description" style="margin-left:22px">%s</span></li>',
                    esc_attr($field),
                    $id,
                    $checked,
                    esc_html($match['name']),
                    esc_html($reason),
                    esc_html($hint)
                );
            }
            echo '</ul>';
        }

        if (!empty($settings['inoagent_context_only'])) {
            echo '<p class="description">Иноагенты маркируются только в цитатах, ссылках и встроенных постах '
                . '(режим включён в настройках плагина).</p>';
        }
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

        $excluded = array_values(array_unique(array_map('intval', (array) ($_POST['lem_excluded'] ?? []))));
        $forced   = array_values(array_unique(array_map('intval', (array) ($_POST['lem_forced'] ?? []))));
        $excluded = array_filter($excluded);
        $forced   = array_filter($forced);
        // Снятое вручную имеет приоритет: в обоих списках сущность не держим
        $forced   = array_values(array_diff($forced, $excluded));

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
