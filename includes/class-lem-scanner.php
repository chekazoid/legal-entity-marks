<?php
defined('ABSPATH') || exit;

class LEM_Scanner {

    const SCAN_STATE_KEY = 'lem_scan_state';

    public function __construct() {
        add_action('transition_post_status', [$this, 'on_post_publish'], 20, 3);
        add_action('wp_ajax_lem_scan_init', [$this, 'ajax_scan_init']);
        add_action('wp_ajax_lem_scan_process', [$this, 'ajax_scan_process']);
        add_action('wp_ajax_lem_scan_cancel', [$this, 'ajax_scan_cancel']);
    }

    /* ------------------------------------------------------------------
     * Core matching
     * ------------------------------------------------------------------ */

    /** Готовые регулярные выражения сущностей в пределах запроса. */
    private static $pattern_cache = [];

    public static function word_match($text, $term) {
        $quoted  = preg_quote($term, '/');
        $pattern = '/(?<!\pL)' . $quoted . '(?!\pL)/ui';
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return mb_strlen(substr($text, 0, $m[0][1]));
        }
        return false;
    }

    /** Альтернатива из словоформ, длинные варианты первыми. */
    private static function alternation(array $forms) {
        $forms = array_values(array_unique(array_filter($forms)));
        usort($forms, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });
        return '(?:' . implode('|', array_map(function ($f) {
            return preg_quote($f, '/');
        }, $forms)) . ')';
    }

    /**
     * Одно регулярное выражение на сущность: точные названия и алиасы, а для
     * людей ещё и словоформы фамилии и имени в обоих порядках слов.
     *
     * Возвращает null, если искать нечего.
     */
    public static function build_pattern($entity, $settings = null, $variant = 'strict') {
        $body = self::build_pattern_body($entity, $settings, $variant);
        return $body === null ? null : '/(?<!\pL)(?:' . $body . ')(?!\pL)/ui';
    }

    /**
     * Тело выражения без делимитеров и граничных проверок.
     *
     * $variant = 'strict' - точные названия, алиасы и пары «Имя Фамилия»;
     * $variant = 'bare'   - только одиночная фамилия во всех падежах.
     */
    public static function build_pattern_body($entity, $settings = null, $variant = 'strict') {
        if ($settings === null) {
            $settings = lem()->get_settings();
        }
        $key = ($entity['id'] ?? $entity['name'])
            . '|' . $variant . '|' . (int) !empty($settings['match_word_forms']);
        if (array_key_exists($key, self::$pattern_cache)) {
            return self::$pattern_cache[$key];
        }

        $frags     = [];
        $is_person = !empty($entity['is_person']) && !empty($settings['match_word_forms']);
        $parts     = $is_person ? LEM_Morphology::split_name($entity['name']) : null;

        $sur_alt = $first_alt = null;
        $bare_ok = false;
        if ($parts && $parts['surname'] !== '') {
            $gender    = LEM_Morphology::detect_gender($entity['name']);
            $sur_alt   = self::alternation(LEM_Morphology::surname_forms($parts['surname'], $gender));
            $first_alt = $parts['first'] !== ''
                ? self::alternation(LEM_Morphology::first_name_forms($parts['first'], $gender))
                : null;
            $bare_ok = LEM_Morphology::surname_is_searchable($parts['surname'])
                && !in_array(mb_strtolower($parts['surname']), self::TERM_BLACKLIST, true);
        }

        if ($variant === 'bare') {
            $body = ($sur_alt !== null && $bare_ok) ? $sur_alt : null;
            self::$pattern_cache[$key] = $body;
            return $body;
        }

        // Оба порядка слов: «Лев Пономарев» и «Пономарев Лев»
        if ($sur_alt !== null && $first_alt !== null) {
            $frags[] = $first_alt . '\s+' . $sur_alt;
            $frags[] = $sur_alt . '\s+' . $first_alt;
        }

        // 'all': одинокая фамилия (для звёздочного подтверждения, см. asterisk_hit)
        if ($variant === 'all' && $sur_alt !== null && $bare_ok) {
            $frags[] = $sur_alt;
        }

        // Алиасы, обёрнутые в кавычки («Дождь»), в обычном режиме матчатся
        // ТОЛЬКО в кавычках: издание «Дождь» помечаем, «шёл дождь» нет.
        $quoted_terms  = [];
        $plain_entity  = $entity;
        if (!empty($entity['aliases'])) {
            $plain_aliases = [];
            foreach ($entity['aliases'] as $a) {
                $inner = self::quoted_brand_inner($a);
                if ($inner !== null) {
                    $quoted_terms[] = $inner;
                } else {
                    $plain_aliases[] = $a;
                }
            }
            $plain_entity['aliases'] = $plain_aliases;
        }

        // Точные названия из реестра и обычные алиасы (со словесными границами)
        foreach (self::search_terms($plain_entity) as $term) {
            $frags[] = str_replace('\ ', '\s+', preg_quote($term, '/'));
        }

        // Брендовые алиасы: в 'strict' требуют кавычек, в 'all' матчатся и без
        // (звёздочка редактора заменяет кавычки как подтверждение)
        foreach ($quoted_terms as $q) {
            $inner = str_replace('\ ', '\s+', preg_quote($q, '/'));
            $frags[] = ($variant === 'all')
                ? $inner
                : '[«"„“]\s*' . $inner . '\s*[»"”“]';
        }

        $body = empty($frags) ? null : implode('|', $frags);

        self::$pattern_cache[$key] = $body;
        return $body;
    }

    /**
     * Совпадение, подтверждённое ручной звёздочкой редактора: «Монгайт**».
     *
     * Редакции по традиции ставят * / ** / *** сразу после имени или названия
     * как пометку иноагента. Это сильный человеческий сигнал, поэтому такой
     * термин матчим в обход анти-однофамилец и требования кавычек.
     *
     * @return array{matched_as:string, position:int}|null
     */
    public static function asterisk_hit($text, $entity, $settings = null) {
        $body = self::build_pattern_body($entity, $settings, 'all');
        if ($body === null) {
            return null;
        }
        $re = '/(?<!\pL)(' . $body . ')(?!\pL)\s*\*{1,3}/ui';
        if (!preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return [
            'matched_as' => $m[1][0],
            'position'   => mb_strlen(substr($text, 0, $m[1][1])),
        ];
    }

    public static function flush_pattern_cache() {
        self::$pattern_cache = [];
    }

    /**
     * Если алиас целиком обёрнут в кавычки («Дождь», "Проект") и короткий,
     * возвращает внутренний текст (это брендовый алиас «только в кавычках»),
     * иначе null. Автогенерируемые алиасы всегда без внешних кавычек, поэтому
     * обёртка однозначно помечает брендовый маркер.
     */
    private static function quoted_brand_inner($alias) {
        $a = trim((string) $alias);
        if (preg_match('/^[«"„“](.{2,40})[»"”“]$/u', $a, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Ищет сущность в тексте. Возвращает найденную словоформу и позицию.
     *
     * Одиночная фамилия ищется по правилу из настройки surname_mode:
     *   off       - не искать, нужно полное «Имя Фамилия»;
     *   confirmed - искать, только если полное имя встречается в тексте
     *               (обычная журналистская подача: первое упоминание полное,
     *               дальше одна фамилия). Отсекает однофамильцев;
     *   always    - искать всегда.
     *
     * @return array{matched_as:string, position:int}|null
     */
    public static function match_entity($text, $entity, $settings = null, $allow_bare = null) {
        if ($settings === null) {
            $settings = lem()->get_settings();
        }

        $best = self::first_hit($text, self::build_pattern($entity, $settings, 'strict'));

        if ($allow_bare === null) {
            $mode       = $settings['surname_mode'] ?? 'confirmed';
            $allow_bare = ($mode === 'always') || ($mode === 'confirmed' && $best !== null);
        }

        if ($allow_bare) {
            $bare = self::first_hit($text, self::build_pattern($entity, $settings, 'bare'));
            if ($bare !== null && ($best === null || $bare['position'] < $best['position'])) {
                $best = $bare;
            }
        }

        return $best;
    }

    private static function first_hit($text, $pattern) {
        if ($pattern === null || !preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return [
            'matched_as' => $m[0][0],
            'position'   => mb_strlen(substr($text, 0, $m[0][1])),
        ];
    }

    /**
     * Words too generic to be used as standalone search terms.
     * These cause massive false positives when extracted from entity names.
     */
    const TERM_BLACKLIST = [
        'вместе', 'движение', 'фирма', 'братство', 'независимость',
        'мемориал', 'эхо', 'сирена', 'правда', 'победа', 'свобода',
        'родина', 'союз', 'центр', 'община', 'партия', 'фонд',
        'армия', 'мост', 'единство', 'весна', 'прогресс', 'возрождение',
        'справедливость', 'держава', 'путь', 'воля', 'честь',
        'поколение', 'агора', 'зимин',
        'голос', 'агентство', 'белый', 'атака', 'акцент', 'арктика',
        'башня', 'таганрог',
        // Countries
        'швейцария', 'финляндия', 'норвегия', 'германия', 'франция',
        'великобритания', 'нидерланды', 'турция', 'канада', 'литва',
        'латвия', 'эстония', 'польша', 'болгария', 'чехия', 'украина',
        'грузия', 'сша', 'япония', 'израиль', 'испания', 'италия',
        'азербайджан', 'молдова', 'казахстан',
        // Regions
        'донбасс', 'крым', 'кавказ', 'сибирь',
    ];

    public static function search_terms($entity) {
        $terms = [$entity['name']];
        if (!empty($entity['aliases'])) {
            $terms = array_merge($terms, $entity['aliases']);
        }

        $cleaned = [];
        foreach ($terms as $term) {
            $t = trim($term);
            if (mb_strlen($t) < 3) {
                continue;
            }
            // Skip blacklisted single-word terms
            if (in_array(mb_strtolower($t), self::TERM_BLACKLIST, true)) {
                continue;
            }
            $cleaned[] = $t;
            $stripped = preg_replace('/^[«"\']+|[»"\']+$/u', '', $t);
            if ($stripped !== $t && mb_strlen($stripped) >= 3) {
                if (!in_array(mb_strtolower($stripped), self::TERM_BLACKLIST, true)) {
                    $cleaned[] = $stripped;
                }
            }
        }
        return array_unique($cleaned);
    }

    /* ------------------------------------------------------------------
     * Контекст: цитаты, ссылки, прямая речь, встроенные посты
     * ------------------------------------------------------------------ */

    /**
     * Блочные элементы, внутри которых упоминание считается «в контексте»
     * целиком, вместе с подводкой и подписью в соседних блоках.
     */
    private static function context_element_patterns($triggers) {
        $patterns = [];
        if (!empty($triggers['blockquote'])) {
            $patterns[] = '<blockquote\b[^>]*>.*?<\/blockquote>';
            $patterns[] = '<q\b[^>]*>.*?<\/q>';
        }
        if (!empty($triggers['embed'])) {
            $patterns[] = '<figure\b[^>]*class=["\'][^"\']*wp-block-embed[^"\']*["\'][^>]*>.*?<\/figure>';
            $patterns[] = '<!--\s*wp:(?:core-)?embed\b.*?<!--\s*\/wp:(?:core-)?embed\s*-->';
            $patterns[] = '<iframe\b[^>]*>.*?<\/iframe>';
        }
        return $patterns;
    }

    /**
     * Делит HTML на блоки по границам абзацев, пунктов списка и заголовков.
     */
    private static function split_blocks($html) {
        $parts = preg_split(
            '/<\/(?:p|li|div|h[1-6]|td|th|section|article|figcaption|cite|dd|dt)>|<br\s*\/?>/i',
            $html
        );
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * Собирает текст «контекстных» участков статьи: содержимое цитат и
     * встроенных постов вместе с соседними блоками (там обычно стоит подводка
     * «как заявил Иванов» и подпись), абзацы с гиперссылками, абзацы с прямой
     * речью в кавычках, а также адреса самих ссылок.
     *
     * Упоминание считается сделанным в контексте, если имя нашлось в этом тексте.
     */
    public static function extract_context_text($html, $triggers) {
        $blocks    = [];
        $elem_pats = self::context_element_patterns($triggers);

        if (!empty($elem_pats)) {
            $split = preg_split(
                '/(' . implode('|', $elem_pats) . ')/is',
                $html, -1, PREG_SPLIT_DELIM_CAPTURE
            );
            foreach ($split as $i => $part) {
                if ($i % 2 === 1) {
                    $blocks[] = ['html' => $part, 'anchor' => true];
                    continue;
                }
                foreach (self::split_blocks($part) as $b) {
                    $blocks[] = ['html' => $b, 'anchor' => false];
                }
            }
        } else {
            foreach (self::split_blocks($html) as $b) {
                $blocks[] = ['html' => $b, 'anchor' => false];
            }
        }

        $qualify = [];
        foreach ($blocks as $i => $b) {
            $ok = $b['anchor'];
            if (!$ok && !empty($triggers['link']) && preg_match('/<a\s[^>]*href=/i', $b['html'])) {
                $ok = true;
            }
            // Прямая речь, а НЕ закавыченный термин. «иностранным агентом»,
            // «нежелательной» - это термины в кавычках, из-за них абзац раньше
            // ошибочно считался цитатой и любой иноагент в нём получал метку.
            // Требуем фрагмент, похожий на фразу: длинный и из нескольких слов.
            if (!$ok && !empty($triggers['quotes'])
                && preg_match_all('/[«"„“]\s*([^«»"„“]{25,})\s*[»"”“]/u', strip_tags($b['html']), $qm)) {
                foreach ($qm[1] as $quoted) {
                    if (preg_match_all('/\pL+/u', $quoted) >= 4) {
                        $ok = true;
                        break;
                    }
                }
            }
            $qualify[$i] = $ok;
        }

        // Соседи цитат и врезок: подводка перед и подпись после
        foreach ($blocks as $i => $b) {
            if (!empty($b['anchor'])) {
                if (isset($blocks[$i - 1])) {
                    $qualify[$i - 1] = true;
                }
                if (isset($blocks[$i + 1])) {
                    $qualify[$i + 1] = true;
                }
            }
        }

        $chunks = [];
        foreach ($blocks as $i => $b) {
            if (!empty($qualify[$i])) {
                $chunks[] = $b['html'];
            }
        }
        $text = strip_tags(implode("\n\n", $chunks));

        // Адреса ссылок: название может стоять в самом URL (bellingcat.com)
        if (!empty($triggers['link'])
            && preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $m)) {
            $text .= "\n" . implode("\n", $m[1]);
        }

        return $text;
    }

    public function scan_text($text, $entities = null) {
        $settings = lem()->get_settings();
        if ($entities === null) {
            $entities = lem()->entities->get_for_marking(!empty($settings['mark_excluded']));
        }
        $plain    = strip_tags($text);
        $context  = null;
        $found    = [];

        $mode     = $settings['surname_mode'] ?? 'confirmed';
        $has_star = mb_strpos($plain, '*') !== false;

        foreach ($entities as $entity) {
            // Полное имя ищем по всей статье: этим подтверждается, что
            // дальнейшие упоминания одной фамилии относятся к тому же человеку
            $strict     = self::first_hit($plain, self::build_pattern($entity, $settings, 'strict'));
            $allow_bare = ($mode === 'always') || ($mode === 'confirmed' && $strict !== null);

            $hit = self::match_entity($plain, $entity, $settings, $allow_bare);
            // Ручная звёздочка редактора («Монгайт**») подтверждает сущность
            // даже там, где обычные правила её пропустили бы
            if ($hit === null && $has_star) {
                $hit = self::asterisk_hit($plain, $entity, $settings);
            }
            if ($hit === null) {
                continue;
            }

            $match = [
                'id'         => (int) $entity['id'],
                'name'       => $entity['name'],
                'type'       => $entity['type'],
                'matched_as' => $hit['matched_as'],
                'position'   => $hit['position'],
            ];

            // Признак «упомянут в цитате или ссылке» считаем всегда, независимо
            // от настройки: тогда её переключение работает без пересканирования.
            // Право искать одну фамилию берём от всей статьи, а не от вырезки:
            // подводка «По словам Пономарева:» стоит рядом с цитатой, а полное
            // имя может быть абзацем выше.
            if ($entity['type'] === 'inoagent') {
                if ($context === null) {
                    $context = self::extract_context_text($text, $settings['context_triggers']);
                }
                $match['in_context'] =
                    self::match_entity($context, $entity, $settings, $allow_bare) !== null;
            }

            $found[] = $match;
        }

        usort($found, fn($a, $b) => $a['position'] - $b['position']);

        // Дедуп: один и тот же фрагмент текста может совпасть с несколькими
        // записями реестра (дубли-регистрации одного издания, брендовые алиасы
        // на нескольких юрлицах). Оставляем одну пометку на упоминание.
        $seen   = [];
        $unique = [];
        foreach ($found as $f) {
            $key = $f['position'] . '|' . mb_strtolower($f['matched_as']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[]   = $f;
        }
        return $unique;
    }

    public function scan_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $settings   = lem()->get_settings();
        $post_types = $settings['post_types'];
        if (!in_array($post->post_type, $post_types, true)) {
            return [];
        }

        $found = $this->scan_text($post->post_title . "\n\n" . $post->post_content);
        $meta  = [
            'entities'     => $found,
            'scanned_at'   => current_time('mysql'),
            'list_version' => get_option('lem_list_version', ''),
        ];
        // wp_slash: update_post_meta снимает слэши и иначе ломает экранирование кавычек в JSON
        update_post_meta($post_id, LEM_META_KEY, wp_slash(wp_json_encode($meta, JSON_UNESCAPED_UNICODE)));

        return $found;
    }

    /* ------------------------------------------------------------------
     * Auto-scan on publish
     * ------------------------------------------------------------------ */

    public function on_post_publish($new_status, $old_status, $post) {
        $settings = lem()->get_settings();
        if (!$settings['auto_scan_on_publish']) {
            return;
        }
        if ($new_status !== 'publish') {
            return;
        }
        if (!in_array($post->post_type, $settings['post_types'], true)) {
            return;
        }
        $this->scan_post($post->ID);
    }

    /* ------------------------------------------------------------------
     * AJAX batch scan
     * ------------------------------------------------------------------ */

    /**
     * Бюджет времени на один AJAX-запрос, безопасно ниже max_execution_time.
     *
     * Сканирование 1.5 тяжелее прежнего (словоформы), и фиксированные 50 статей
     * на медленном хостинге упирались в лимит выполнения PHP: запрос падал с
     * фатальной ошибкой, а браузер получал HTML вместо JSON. Поэтому за запрос
     * обрабатываем не фиксированное число статей, а сколько успеем за этот бюджет.
     */
    public static function time_budget() {
        $max = (int) ini_get('max_execution_time');
        // Пытаемся приподнять лимит, если хостинг разрешает
        if ($max > 0 && $max < 60) {
            @set_time_limit(60);
            $max = (int) ini_get('max_execution_time');
        }
        if ($max <= 0) {
            $budget = 15.0; // лимита нет (CLI/некоторые хостинги)
        } else {
            // Половина лимита, но не больше 15 c и всегда с запасом ниже лимита
            $budget = min(15.0, $max * 0.5);
            $budget = max(2.0, min($budget, $max - 3.0));
        }
        return (float) apply_filters('lem_scan_time_budget', $budget, $max);
    }

    public function ajax_scan_init() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $settings   = lem()->get_settings();
        $post_types = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $mode = sanitize_text_field($_POST['mode'] ?? 'all');

        $where = "post_type IN ($placeholders) AND post_status = 'publish'";
        $params = $post_types;

        if ($mode === 'recent') {
            $where   .= ' AND post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where",
            ...$params
        ));

        $state = [
            'status'              => 'running',
            'mode'                => $mode,
            'total'               => $total,
            'offset'              => 0,
            'posts_with_matches'  => 0,
            'total_mentions'      => 0,
            'started_at'          => current_time('mysql'),
        ];
        set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);

        wp_send_json_success($state);
    }

    public function ajax_scan_process() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $state = get_transient(self::SCAN_STATE_KEY);
        if (!$state || $state['status'] !== 'running') {
            wp_send_json_error('No active scan');
        }

        global $wpdb;
        $settings     = lem()->get_settings();
        $post_types   = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        // Выборка из БД ограничена сверху; сколько реально обработаем,
        // решает бюджет времени ниже
        $batch_size = 25;
        $offset     = (int) $state['offset'];

        $where  = "post_type IN ($placeholders) AND post_status = 'publish'";
        $params = $post_types;

        if ($state['mode'] === 'recent') {
            $where .= ' AND post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        }

        $params[] = $batch_size;
        $params[] = $offset;

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE $where ORDER BY ID ASC LIMIT %d OFFSET %d",
            ...$params
        ));

        $entities = lem()->entities->get_for_marking(!empty($settings['mark_excluded']));
        $list_ver = get_option('lem_list_version', '');
        $now      = current_time('mysql');

        $budget    = self::time_budget();
        $start     = microtime(true);
        $processed = 0;

        wp_suspend_cache_addition(true);

        try {
            foreach ($posts as $post) {
                $found = $this->scan_text($post->post_title . "\n\n" . $post->post_content, $entities);
                if (!empty($found)) {
                    $meta_json = wp_json_encode([
                        'entities'     => $found,
                        'scanned_at'   => $now,
                        'list_version' => $list_ver,
                    ], JSON_UNESCAPED_UNICODE);

                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                        $post->ID, LEM_META_KEY
                    ));
                    if ($existing) {
                        $wpdb->update($wpdb->postmeta, ['meta_value' => $meta_json], ['meta_id' => $existing]);
                    } else {
                        $wpdb->insert($wpdb->postmeta, [
                            'post_id'    => $post->ID,
                            'meta_key'   => LEM_META_KEY,
                            'meta_value' => $meta_json,
                        ]);
                    }
                    $state['posts_with_matches']++;
                    $state['total_mentions'] += count($found);
                } else {
                    $wpdb->delete($wpdb->postmeta, [
                        'post_id'  => $post->ID,
                        'meta_key' => LEM_META_KEY,
                    ]);
                }

                $processed++;
                // Отдаём управление, не дожидаясь лимита выполнения PHP.
                // Хотя бы одну статью за запрос обрабатываем всегда, прогресс идёт.
                if (microtime(true) - $start > $budget) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            wp_suspend_cache_addition(false);
            $state['offset'] = $offset + $processed;
            set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
            wp_send_json_error('Ошибка при сканировании: ' . $e->getMessage());
        }

        wp_suspend_cache_addition(false);
        wp_cache_flush_runtime();

        $state['offset'] = $offset + $processed;

        // complete только когда дошли до конца, а не когда прервались по бюджету
        if ($state['offset'] >= $state['total'] || empty($posts)) {
            $state['status']      = 'complete';
            $state['finished_at'] = current_time('mysql');
            try {
                wp_cache_flush();
            } catch (\Throwable $e) {
                // Ignore cache flush errors
            }
        }

        set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success($state);
    }

    public function ajax_scan_cancel() {
        check_ajax_referer('lem_scan_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $state = get_transient(self::SCAN_STATE_KEY);
        if ($state) {
            $state['status'] = 'cancelled';
            set_transient(self::SCAN_STATE_KEY, $state, HOUR_IN_SECONDS);
        }

        wp_send_json_success(['status' => 'cancelled']);
    }

    /* ------------------------------------------------------------------
     * Batch scan for WP-CLI / Cron
     * ------------------------------------------------------------------ */

    public function batch_scan($args = []) {
        global $wpdb;

        $settings     = lem()->get_settings();
        $post_types   = $settings['post_types'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $batch_size = (int) ($args['batch'] ?? 500);
        $dry_run    = !empty($args['dry_run']);
        $log        = $args['log'] ?? function ($msg) {};

        $where  = "p.post_type IN ($placeholders) AND p.post_status = 'publish'";
        $params = $post_types;

        if (!empty($args['post_id'])) {
            $found = $this->scan_post((int) $args['post_id']);
            return ['posts_with_matches' => empty($found) ? 0 : 1, 'total_mentions' => count($found)];
        }

        if (!empty($args['recent'])) {
            $where .= ' AND p.post_date >= DATE_SUB(NOW(), INTERVAL ' . (int) $args['recent'] . ' DAY)';
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where",
            ...$params
        ));
        $log("Posts to scan: $total (batch size: $batch_size)");

        if ($total === 0) {
            return ['posts_with_matches' => 0, 'total_mentions' => 0];
        }

        $entities = lem()->entities->get_for_marking(!empty($settings['mark_excluded']));
        $list_ver = get_option('lem_list_version', '');
        $now      = current_time('mysql');
        $offset   = 0;
        $posts_with_matches = 0;
        $total_mentions     = 0;

        wp_suspend_cache_addition(true);

        while ($offset < $total) {
            $batch_params = array_merge($params, [$batch_size, $offset]);
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_content FROM {$wpdb->posts} p WHERE $where ORDER BY p.ID ASC LIMIT %d OFFSET %d",
                ...$batch_params
            ));
            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                $found = $this->scan_text($post->post_title . "\n\n" . $post->post_content, $entities);
                if (!empty($found)) {
                    if (!$dry_run) {
                        $meta_json = wp_json_encode([
                            'entities'     => $found,
                            'scanned_at'   => $now,
                            'list_version' => $list_ver,
                        ], JSON_UNESCAPED_UNICODE);
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                            $post->ID, LEM_META_KEY
                        ));
                        if ($existing) {
                            $wpdb->update($wpdb->postmeta, ['meta_value' => $meta_json], ['meta_id' => $existing]);
                        } else {
                            $wpdb->insert($wpdb->postmeta, [
                                'post_id'    => $post->ID,
                                'meta_key'   => LEM_META_KEY,
                                'meta_value' => $meta_json,
                            ]);
                        }
                    }
                    $posts_with_matches++;
                    $total_mentions += count($found);
                } else {
                    if (!$dry_run) {
                        $wpdb->delete($wpdb->postmeta, [
                            'post_id'  => $post->ID,
                            'meta_key' => LEM_META_KEY,
                        ]);
                    }
                }
            }

            $offset += $batch_size;
            wp_cache_flush_runtime();
            $log("Processed $offset / $total ...");
        }

        wp_suspend_cache_addition(false);

        try {
            wp_cache_flush();
        } catch (\Throwable $e) {
            // Ignore
        }

        return [
            'posts_with_matches' => $posts_with_matches,
            'total_mentions'     => $total_mentions,
            'total_posts'        => $total,
        ];
    }
}
