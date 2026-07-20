<?php
defined('ABSPATH') || exit;

class LEM_Frontend {

    /** Дисклеймеры уже добавлены в этом запросе (защита от повторного вывода). */
    private $applied = false;

    public function __construct() {
        add_action('wp', [$this, 'register_filter']);
        add_action('wp_head', [$this, 'print_css'], 30);
    }

    public function register_filter() {
        $settings = lem()->get_settings();
        $priority = (int) $settings['filter_priority'];
        add_filter('the_content', [$this, 'filter_content'], $priority);
    }

    public function filter_content($content) {
        if (is_admin() || is_feed() || $this->applied) {
            return $content;
        }

        $settings   = lem()->get_settings();
        $post_types = $settings['post_types'];
        if (!is_singular($post_types)) {
            return $content;
        }

        // Классические темы: работаем только в основном цикле.
        // Блочные темы (core/post-content) не выставляют in_the_loop(),
        // поэтому сверяем текущую запись с запрошенной.
        if (in_the_loop()) {
            if (!is_main_query()) {
                return $content;
            }
            $post_id = get_the_ID();
        } else {
            $post_id = get_queried_object_id();
            if (get_the_ID() && get_the_ID() !== $post_id) {
                return $content;
            }
        }

        if (!$post_id) {
            return $content;
        }

        $meta_raw = get_post_meta($post_id, LEM_META_KEY, true);
        if (empty($meta_raw)) {
            return $content;
        }

        $meta = json_decode($meta_raw, true);
        if (empty($meta['entities'])) {
            return $content;
        }

        $entities_db  = lem()->entities->get_all_active();
        $entities_map = [];
        foreach ($entities_db as $e) {
            $entities_map[(int) $e['id']] = $e;
        }

        $overrides      = self::get_overrides($post_id);
        $active_matches = [];
        foreach ($meta['entities'] as $match) {
            $eid = (int) $match['id'];
            if (!isset($entities_map[$eid])) {
                continue;
            }
            if (!self::should_mark($match, $settings, $overrides)) {
                continue;
            }
            $match['entity']  = $entities_map[$eid];
            $active_matches[] = $match;
        }

        if (empty($active_matches)) {
            return $content;
        }

        $symbols        = ['*', '**', '***', '****', '*****'];
        $disclaimers    = [];
        $marked_content = $content;

        foreach ($active_matches as $idx => $match) {
            $sym         = $symbols[$idx] ?? str_repeat('*', $idx + 1);
            $entity      = $match['entity'];

            // Сначала найденная сканером словоформа, затем остальные варианты
            $try = [];
            if (!empty($match['matched_as'])) {
                $try[] = preg_quote($match['matched_as'], '/');
            }
            $body = LEM_Scanner::build_pattern_body($entity, $settings);
            if ($body !== null) {
                $try[] = '(?:' . $body . ')';
            }

            $replaced = false;
            foreach ($try as $needle) {
                if ($replaced) break;
                $pattern = '/(?<=>)([^<]*?)(?<!\pL)(' . $needle . ')(?!\pL)/iu';
                $marked_content = preg_replace_callback($pattern, function ($m) use ($sym, &$replaced) {
                    if ($replaced) {
                        return $m[0];
                    }
                    $replaced = true;
                    return $m[1] . $m[2] . '<sup class="lem-ref">' . esc_html($sym) . '</sup>';
                }, $marked_content);
            }

            $disclaimers[] = '<p style="margin:4px 0"><sup>' . esc_html($sym) . '</sup> '
                . esc_html(self::disclaimer_text($entity)) . '</p>';
        }

        $s = $settings;
        $block = '<div class="lem-disclaimers" style="margin-top:24px;padding:16px 20px;'
            . 'background:' . esc_attr($s['disclaimer_bg']) . ';'
            . 'border-left:4px solid ' . esc_attr($s['disclaimer_border']) . ';'
            . 'border-radius:4px;font-size:13px;line-height:1.6;color:#555">'
            . implode("\n", $disclaimers)
            . '</div>';

        $this->applied = true;
        return $marked_content . "\n" . $block;
    }

    /* ------------------------------------------------------------------
     * Правила отбора: категории, контекст, ручные исключения
     * ------------------------------------------------------------------ */

    /**
     * Ручные решения редактора по конкретной статье.
     *
     * @return array ['excluded' => int[], 'forced' => int[]]
     */
    public static function get_overrides($post_id) {
        $raw = get_post_meta($post_id, LEM_OVERRIDES_META_KEY, true);
        $ov  = $raw ? json_decode($raw, true) : [];
        return [
            'excluded' => array_map('intval', (array) ($ov['excluded'] ?? [])),
            'forced'   => array_map('intval', (array) ($ov['forced'] ?? [])),
        ];
    }

    /**
     * Решает, маркировать ли найденное упоминание.
     *
     * Порядок: отключённая категория → снятое вручную → правило контекста
     * (для иноагентов), которое редактор может перебить вручную.
     */
    public static function should_mark($match, $settings, $overrides) {
        $type = $match['type'] ?? '';
        if (!in_array($type, $settings['registries'], true)) {
            return false;
        }

        $id = (int) ($match['id'] ?? 0);
        if (in_array($id, $overrides['excluded'], true)) {
            return false;
        }

        if ($type === 'inoagent' && !empty($settings['inoagent_context_only'])) {
            // У записей, отсканированных до версии 1.5.0, признака нет.
            // Считаем их маркируемыми, пока статью не пересканируют.
            if (array_key_exists('in_context', $match)
                && empty($match['in_context'])
                && !in_array($id, $overrides['forced'], true)) {
                return false;
            }
        }

        return true;
    }

    public function print_css() {
        $settings = lem()->get_settings();
        if (!is_singular($settings['post_types'])) {
            return;
        }
        $color = esc_attr($settings['accent_color']);
        echo '<style>.lem-ref{color:' . $color . ';font-weight:700;cursor:help;font-size:0.75em;vertical-align:super;text-decoration:none;margin-left:1px}</style>' . "\n";
    }

    public static function disclaimer_text($entity) {
        if (!empty($entity['status_text'])) {
            return $entity['status_text'];
        }
        $name = $entity['name'];
        switch ($entity['type']) {
            case 'inoagent':
                if ($entity['is_person']) {
                    return $name . ', признанный(-ая) в РФ иностранным агентом';
                }
                return $name . ', признанная в РФ иностранным агентом';
            // Без тире: wptexturize превращает « - » в среднее тире,
            // а такая типографика читается как признак автогенерации.
            // Название идёт первым: в реестре оно обычно уже содержит
            // слово «организация», и подстановка перед ним ломает падеж.
            case 'extremist':
                return $name . ', деятельность которой признана экстремистской и запрещена на территории РФ';
            case 'terrorist':
                return $name . ', признанная террористической и запрещённая на территории РФ организация';
            case 'undesirable':
                return $name . ', признанная нежелательной на территории РФ организация';
            default:
                return $name;
        }
    }
}
