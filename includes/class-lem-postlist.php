<?php
defined('ABSPATH') || exit;

/**
 * Фильтр и колонка в списке записей (wp-admin/edit.php).
 *
 * Позволяет отобрать материалы, где сканер что-то нашёл, не уходя в отдельный
 * отчёт: «покажи все статьи с маркировкой» или «где есть ссылки на ресурсы из реестров».
 */
class LEM_Postlist {

    const QUERY_VAR = 'lem_filter';

    public function __construct() {
        add_action('restrict_manage_posts', [$this, 'render_filter']);
        add_action('pre_get_posts', [$this, 'apply_filter']);
        // Настройки читаем не в конструкторе: он выполняется, пока объект
        // плагина ещё создаётся, и обращение к lem() уходит в рекурсию
        add_action('admin_init', [$this, 'register_columns']);
    }

    public function register_columns() {
        foreach (lem()->get_settings()['post_types'] as $type) {
            add_filter("manage_{$type}_posts_columns", [$this, 'add_column']);
            add_action("manage_{$type}_posts_custom_column", [$this, 'render_column'], 10, 2);
        }
    }

    private function options() {
        return [
            'marked'  => __('С маркировкой', 'legal-entity-marks'),
            'tracked' => __('С отслеживаемыми упоминаниями', 'legal-entity-marks'),
            'links'   => __('Со ссылками на ресурсы из реестров', 'legal-entity-marks'),
            'none'    => __('Без находок', 'legal-entity-marks'),
        ];
    }

    public function render_filter($post_type) {
        if (!in_array($post_type, lem()->get_settings()['post_types'], true)) {
            return;
        }
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
        $current = sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR] ?? ''));
        echo '<select name="' . esc_attr(self::QUERY_VAR) . '">';
        echo '<option value="">' . esc_html__('Маркировка: любая', 'legal-entity-marks') . '</option>';
        foreach ($this->options() as $key => $label) {
            printf('<option value="%s"%s>%s</option>',
                esc_attr($key), selected($current, $key, false), esc_html($label));
        }
        echo '</select>';
    }

    public function apply_filter($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
        $filter = sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR] ?? ''));
        if ($filter === '' || !isset($this->options()[$filter])) {
            return;
        }

        if ($filter === 'links') {
            $query->set('meta_query', [[
                'key'     => LEM_BANNED_LINKS_META_KEY,
                'compare' => 'EXISTS',
            ]]);
            return;
        }

        if ($filter === 'none') {
            $query->set('meta_query', [[
                'key'     => LEM_META_KEY,
                'compare' => 'NOT EXISTS',
            ]]);
            return;
        }

        // marked / tracked: мета есть у всех находок, различие в том,
        // выводится ли метка читателю. Считаем на PHP и фильтруем по списку ID
        $ids = $this->post_ids_by_mode($filter);
        $query->set('post__in', $ids ?: [0]);
    }

    /**
     * ID материалов, где есть маркируемые (или наоборот только отслеживаемые) находки.
     */
    private function post_ids_by_mode($mode) {
        $rows = lem()->report->get_rows(['mode' => $mode, 'limit' => 100000]);
        $ids  = [];
        foreach ($rows['items'] as $r) {
            $ids[$r['post_id']] = true;
        }
        return array_keys($ids);
    }

    public function add_column($columns) {
        $columns['lem_marks'] = __('Маркировка', 'legal-entity-marks');
        return $columns;
    }

    public function render_column($column, $post_id) {
        if ($column !== 'lem_marks') {
            return;
        }

        $raw  = get_post_meta($post_id, LEM_META_KEY, true);
        $meta = $raw ? json_decode($raw, true) : [];
        $ents = $meta['entities'] ?? [];

        $links_raw = get_post_meta($post_id, LEM_BANNED_LINKS_META_KEY, true);
        $links     = $links_raw ? json_decode($links_raw, true) : [];
        $index = lem()->banned_sites->get_index();
        $links_n = count(array_filter($links['links'] ?? [], static function ($link) use ($index) {
            return array_key_exists($link['matched_domain'] ?? '', $index);
        }));

        if (empty($ents) && !$links_n) {
            echo '<span style="color:#999">-</span>';
            return;
        }

        $settings  = lem()->get_settings();
        $overrides = LEM_Frontend::get_overrides($post_id);
        $marked = $tracked = 0;
        $current = [];
        foreach (lem()->entities->get_for_marking_ids(array_column($ents, 'id'), true) as $entity) {
            $current[(int) $entity['id']] = $entity;
        }
        foreach ($ents as $m) {
            if (!isset($current[(int) ($m['id'] ?? 0)])) { continue; }
            $m = array_merge($m, $current[(int) $m['id']]);
            if (!LEM_Scanner::is_current_mention($m, $settings)) { continue; }
            $type = $m['type'] ?? '';
            if (!in_array($type, $settings['track_registries'], true)) {
                continue;
            }
            if (LEM_Frontend::should_mark($m, $settings, $overrides)) {
                $marked++;
            } else {
                $tracked++;
            }
        }

        $out = [];
        if ($marked) {
            $out[] = __('меток: ', 'legal-entity-marks') . $marked;
        }
        if ($tracked) {
            $out[] = __('<span style="color:#996800">без меток: ', 'legal-entity-marks') . $tracked . '</span>';
        }
        if ($links_n) {
            $out[] = __('<span style="color:#b32d2e">ссылок: ', 'legal-entity-marks') . $links_n . '</span>';
        }
        echo wp_kses_post($out ? implode('<br>', $out) : '<span style="color:#999">-</span>');
    }
}
