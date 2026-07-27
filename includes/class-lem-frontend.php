<?php
defined('ABSPATH') || exit;

class LEM_Frontend {

    /** Сколько статусов показывать под одной сноской, остальные считаем. */
    const MAX_DISCLAIMER_LINES = 3;

    /** ID постов, для которых дисклеймеры уже добавлены в этом запросе. */
    private $applied = [];

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
        if (is_admin() || is_feed()) {
            return $content;
        }

        // Не трогаем the_content, вызванный НЕ для вывода тела статьи:
        //  - генерация выдержки/мета-описания: wp_trim_excerpt прогоняет
        //    the_content по обрезанному тексту (Yoast og:description и т.п.);
        //  - любые вызовы внутри wp_head (schema, соцкарточки), тело идёт позже.
        // Иначе такой ранний вызов «съедал» право пометить настоящее тело.
        if (doing_filter('get_the_excerpt') || doing_action('wp_head')) {
            return $content;
        }

        $settings   = lem()->get_settings();
        $post_types = $settings['post_types'];
        if (!is_singular($post_types)) {
            return $content;
        }

        // Классические темы: работаем только в основном цикле.
        // Блочные темы (core/post-content) не всегда выставляют in_the_loop(),
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

        // Дисклеймеры для этого поста уже добавлены в этом запросе
        if (!empty($this->applied[$post_id])) {
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

        $entities_db  = lem()->entities->get_for_marking(!empty($settings['mark_excluded']));
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

        // Одно упоминание = одна сноска, даже если под ним несколько записей
        // реестра: DOXA это и иноагент, и (через юрлицо) нежелательная
        $groups = [];
        foreach ($active_matches as $match) {
            $key = $match['position'] . '|' . mb_strtolower($match['matched_as']);
            $groups[$key][] = $match;
        }

        $symbols        = ['*', '**', '***', '****', '*****'];
        $disclaimers    = [];
        $marked_content = $content;
        $idx            = 0;

        foreach ($groups as $group) {
            $sym   = $symbols[$idx] ?? str_repeat('*', $idx + 1);
            $first = $group[0];
            $idx++;

            // Сначала найденная сканером словоформа, затем остальные варианты
            $try = [];
            if (!empty($first['matched_as'])) {
                $try[] = preg_quote($first['matched_as'], '/');
            }
            $body = LEM_Scanner::build_pattern_body($first['entity'], $settings);
            if ($body !== null) {
                $try[] = '(?:' . $body . ')';
            }

            $replaced = false;
            foreach ($try as $needle) {
                if ($replaced) break;
                // Хвостовая группа ловит ручные звёздочки редактора («Монгайт**»)
                // сразу после имени, чтобы заменить их своей сноской, а не задваивать
                $pattern = '/(?<=>)([^<]*?)(?<!\pL)(' . $needle . ')(?!\pL)(\s*\*{1,3})?/iu';
                $marked_content = preg_replace_callback($pattern, function ($m) use ($sym, &$replaced) {
                    if ($replaced) {
                        return $m[0];
                    }
                    $replaced = true;
                    // $m[3] (ручные звёздочки) намеренно отбрасываем
                    return $m[1] . $m[2] . '<sup class="lem-ref">' . esc_html($sym) . '</sup>';
                }, $marked_content);
            }

            // Метку в текст поставить не удалось (упоминание внутри ссылки или
            // разорвано тегами) - дисклеймер без сноски только путает
            if (!$replaced) {
                $idx--;
                continue;
            }

            // Под одним названием в реестре бывает десяток разных организаций
            // («Мемориал»): показываем три, остальные считаем
            $lines = [];
            $seen  = [];
            foreach ($group as $m) {
                $text = self::disclaimer_text($m['entity']);
                if (isset($seen[$text])) {
                    continue;
                }
                $seen[$text] = true;
                $lines[]     = $text;
            }
            $rest  = count($lines) - self::MAX_DISCLAIMER_LINES;
            $lines = array_slice($lines, 0, self::MAX_DISCLAIMER_LINES);

            $html = '';
            foreach ($lines as $n => $text) {
                $html .= '<p style="margin:4px 0">'
                    . ($n === 0 ? '<sup>' . esc_html($sym) . '</sup> ' : '<span style="margin-left:14px"></span>')
                    . esc_html($text) . '</p>';
            }
            if ($rest > 0) {
                $html .= '<p style="margin:4px 0 4px 14px">'
                    . esc_html(sprintf('и ещё %d %s реестра с этим названием',
                        $rest, self::plural_records($rest)))
                    . '</p>';
            }
            $disclaimers[] = $html;
        }

        if (empty($disclaimers)) {
            return $content;
        }

        $s = $settings;
        $block = '<div class="lem-disclaimers" style="margin-top:24px;padding:16px 20px;'
            . 'background:' . esc_attr($s['disclaimer_bg']) . ';'
            . 'border-left:4px solid ' . esc_attr($s['disclaimer_border']) . ';'
            . 'border-radius:4px;font-size:13px;line-height:1.6;color:#555">'
            . implode("\n", $disclaimers)
            . '</div>';

        $this->applied[$post_id] = true;
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
        // registries - имя до 1.9.0, могло прийти из стороннего кода
        $mark = $settings['mark_registries'] ?? $settings['registries'] ?? [];
        if (!in_array($type, (array) $mark, true)) {
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
        // В реестре часть названий в прямых кавычках («Вёрстка Медиа»).
        // Приводим к ёлочкам: иначе wptexturize превращает их в мнемоники,
        // и в дисклеймере видно &#8220; вместо кавычек
        $name     = self::normalize_quotes($entity['name']);
        $excluded = isset($entity['is_active']) && !$entity['is_active'];

        if ($excluded) {
            return self::disclaimer_text_excluded($entity);
        }

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

    /**
     * Дисклеймер для сущности, исключённой из реестра (опция «маркировать бывших»).
     * Формулировка в прошедшем времени, с датой исключения.
     */
    private static function disclaimer_text_excluded($entity) {
        $name      = self::normalize_quotes($entity['name']);
        $date      = self::format_date($entity['date_excluded'] ?? '');
        $is_person = !empty($entity['is_person']);

        if ($date === '') {
            $tail = '';
        } elseif ($is_person) {
            $tail = ' (исключён(-а) из реестра ' . $date . ')';
        } else {
            $tail = ' (исключена из реестра ' . $date . ')';
        }

        switch ($entity['type']) {
            case 'inoagent':
                if ($is_person) {
                    return $name . ', ранее признанный(-ая) в РФ иностранным агентом' . $tail;
                }
                return $name . ', ранее признанная в РФ иностранным агентом' . $tail;
            case 'extremist':
                return $name . ', деятельность которой ранее была признана экстремистской' . $tail;
            case 'terrorist':
                return $name . ', ранее признанная террористической организация' . $tail;
            case 'undesirable':
                return $name . ', ранее признанная нежелательной на территории РФ организация' . $tail;
            default:
                return $name;
        }
    }

    /** «запись» / «записи» / «записей» по числу. */
    private static function plural_records($n) {
        $n10  = $n % 10;
        $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) {
            return 'запись';
        }
        if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) {
            return 'записи';
        }
        return 'записей';
    }

    /** Прямые кавычки в названии заменяем на ёлочки (см. disclaimer_text). */
    private static function normalize_quotes($name) {
        $name = (string) $name;
        if (strpos($name, '"') === false) {
            return $name;
        }
        // Пары "..." -> «...»; одиночная лишняя кавычка просто убирается
        $name = preg_replace('/"([^"]*)"/u', '«$1»', $name);
        return str_replace('"', '', $name);
    }

    /** DATE из БД (Y-m-d) в человекочитаемое d.m.Y. */
    private static function format_date($raw) {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '0000-00-00') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return "$m[3].$m[2].$m[1]";
        }
        return $raw;
    }
}
