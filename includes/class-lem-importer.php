<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-lem-registry-sync.php';

class CLEM_Importer {
    private $verified_generation = '';
    private $defer_activation = false;

    /** Итоги последнего обновления реестров: домены, собранные из данных Минюста. */
    public $last_fetch_updated = false;
    public $last_fetch_domains = 0;

    /** Человеческие названия реестров для сообщений в админке. */
    const REGISTRY_LABELS = [
        'inoagent'    => 'Иностранные агенты',
        'extremist'   => 'Экстремистские организации',
        'terrorist'   => 'Террористические организации',
        'undesirable' => 'Нежелательные организации',
        'fedsfm_person' => 'Физические лица из перечня Росфинмониторинга',
    ];

    public function import_all_bundled() {
        $files = [
            'inoagent'    => CLEM_DATA_DIR . '/foreign-agents-raw.json',
            'extremist'   => CLEM_DATA_DIR . '/extremist-orgs.json',
            'terrorist'   => CLEM_DATA_DIR . '/terrorist-orgs.json',
            'undesirable' => CLEM_DATA_DIR . '/undesirable-orgs.json',
        ];

        $results = [];
        foreach ($files as $type => $file) {
            if (!file_exists($file)) {
                $results[$type] = ['error' => "File not found: $file"];
                continue;
            }
            // import_json понимает оба формата: name/dateIn/dateOut и fullName/dob (ФЗ-255).
            // Перечень из поставки старше базы, исключённых он не возвращает
            $results[$type] = $this->import_json($file, $type, false);
        }

        // Импорт встроенных запрещённых доменов (экстремистские, террористические, нежелательные)
        $results['banned_sites'] = $this->import_banned_sites();

        // Курируемые брендовые алиасы поверх официальных названий из реестра
        $results['brand_aliases'] = $this->apply_brand_aliases();

        return $results;
    }

    /**
     * Добавляет курируемые брендовые алиасы к записям реестра.
     *
     * В реестре Минюста организации записаны официальными юридическими
     * названиями (SIA «Medusa Project»), а в статьях их называют брендом
     * («Медуза»). Файл data/brand-aliases.json сопоставляет бренд с юрлицом,
     * и эти алиасы доклеиваются к записям после каждого импорта или обновления,
     * поэтому не теряются при обновлении реестров из онлайн-источников.
     *
     * @return array ['applied' => int]
     */
    public function apply_brand_aliases($file = null) {
        // Источник правил - редактируемый пользователем список (при первом
        // обращении он наполняется из комплектного data/brand-aliases.json).
        // Явно переданный файл нужен только для разовых импортов из CLI.
        if ($file === null) {
            $map = array_values(array_filter(
                clem_plugin()->brands->get_rules(),
                static function ($r) {
                    return !empty($r['enabled']);
                }
            ));
        } else {
            if (!file_exists($file)) {
                return ['applied' => 0];
            }
            $map = json_decode(file_get_contents($file), true);
            if (!is_array($map)) {
                return ['applied' => 0, 'error' => 'Invalid JSON'];
            }
        }

        global $wpdb;
        $table    = $wpdb->prefix . CLEM_TABLE;
        $applied  = 0;
        $unmatched = [];

        foreach ($map as $rule) {
            $match   = trim($rule['match'] ?? '');
            $aliases = (array) ($rule['aliases'] ?? []);

            // Бренды-общеупотребительные слова матчатся только в кавычках:
            // храним их обёрнутыми в ёлочки, матчер это распознаёт
            foreach ((array) ($rule['quoted'] ?? []) as $q) {
                $q = trim($q);
                if ($q !== '') {
                    $aliases[] = '«' . $q . '»';
                }
            }

            if ($match === '' || empty($aliases)) {
                continue;
            }

            /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, aliases, status_text FROM $table WHERE name LIKE %s",
                '%' . $wpdb->esc_like($match) . '%'
            )) ;
            /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

            // Правило, не нашедшее ни одной записи, раньше молча ничего не делало:
            // так брендовые алиасы «Вёрстки» не работали из-за кавычек в match
            if (empty($rows)) {
                $unmatched[] = $match;
                continue;
            }

            $status = trim((string) ($rule['status'] ?? ''));

            foreach ($rows as $row) {
                $old    = json_decode($row->aliases, true) ?: [];
                $merged = array_values(array_unique(array_merge($old, $aliases)));

                $update = [];
                if ($merged !== $old) {
                    $update['aliases'] = wp_json_encode($merged, JSON_UNESCAPED_UNICODE);
                }
                // Своя формулировка сноски вместо общей фразы реестра
                if ($status !== '' && $status !== $row->status_text) {
                    $update['status_text'] = $status;
                }

                if (!empty($update)) {
                    /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
                    $wpdb->update($table, $update, ['id' => $row->id]) ;
                    /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
                    $applied++;
                }
            }
        }

        clem_plugin()->entities->flush_cache();
        return ['applied' => $applied, 'unmatched' => $unmatched];
    }

    /**
     * Алиасы из официального названия реестра.
     *
     * Минюст пишет альтернативные наименования внутри самой записи:
     *   Nodibinājums «Helpdesk Media Foundation» («Медиа-фонд «Служба поддержки»)
     *   Проект «Центр «Досье»
     * Вытаскиваем содержимое кавычек и имя без внешних кавычек. Для физлиц
     * даём короткую форму «Фамилия Имя».
     *
     * Опасные фрагменты (общие слова, одиночные короткие) оборачиваем в ёлочки:
     * такой алиас ищется только в кавычках, см. CLEM_Scanner.
     *
     * @return string[]
     */
    public static function generate_aliases($name, $is_person = false) {
        $name = trim((string) $name);
        if ($name === '') {
            return [];
        }

        $out = [];

        if ($is_person) {
            // Псевдоним в кавычках отбрасываем: ФИО «Псевдоним» -> ФИО
            $clean = preg_replace('/\s*[«"„].*?[»"“]\s*/u', ' ', $name);
            $parts = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
            $short = count($parts) >= 2 ? $parts[0] . ' ' . $parts[1] : '';

            // Псевдоним тоже пригодится: Оксимирон, Монеточка
            $nicks = [];
            if (preg_match_all('/[«"„]([^«»"“”]{3,40})[»"“”]/u', $name, $m)) {
                foreach ($m[1] as $frag) {
                    $nicks[] = trim($frag);
                }
            }
            $aliases = self::clean_aliases($nicks, $name);
            // «Фамилия Имя» кавычек не требует: это имя человека, а не бренд
            if ($short !== '' && mb_strlen($short) >= 5) {
                array_unshift($aliases, $short);
            }
            return array_values(array_unique($aliases));
        }

        // Организации: содержимое кавычек. У местных отделений там город,
        // а не название, поэтому такие фрагменты пропускаем
        if (!self::is_local_branch($name)
            && preg_match_all('/[«"„]([^«»"“”]{3,60})[»"“”]/u', $name, $m)) {
            foreach ($m[1] as $frag) {
                $out[] = trim($frag);
            }
        }

        // Аббревиатура латинского названия: в текстах пишут GIJN, а не
        // Global Investigative Journalism Network
        $acronym = self::acronym($name);
        if ($acronym !== '') {
            $out[] = $acronym;
        }
        // Название без хвоста страны и организационно-правовой формы:
        // «Eurasianet, США» -> «Eurasianet», «Hidemy.network Ltd.» -> «Hidemy.network».
        // В статьях пишут именно так, а поиск шёл по строке целиком
        $trimmed = self::strip_legal_suffix($name);
        if ($trimmed !== $name && mb_strlen($trimmed) >= 4) {
            $out[] = $trimmed;
        }

        // Название целиком в кавычках: «Конгресс народов Ичкерии» -> без кавычек.
        // Только когда кавычки с обеих сторон, иначе получается обрывок
        // вроде «Новостной портал «DOXA» без закрывающей кавычки
        if (preg_match('/^[«"„](.+)[»"“”]$/u', $name, $m)) {
            $inner = trim($m[1]);
            if (mb_strpos($inner, '«') === false && mb_strpos($inner, '"') === false) {
                $out[] = $inner;
            }
        }

        return self::clean_aliases(CLEM_Scanner::organization_aliases($name, $out), $name);
    }

    /**
     * Отсев и защита сгенерированных алиасов.
     */
    private static function clean_aliases(array $list, $name) {
        $out  = [];
        $seen = [mb_strtolower($name) => true];

        foreach ($list as $alias) {
            $alias = trim($alias, " \t\n\r\0\x0B.,;:");
            if (mb_strlen($alias) < 3) {
                continue;
            }
            $low = mb_strtolower($alias);
            if (isset($seen[$low])) {
                continue;
            }
            $seen[$low] = true;

            // Страна регистрации это не название организации
            if (self::is_country($alias)) {
                continue;
            }
            $out[] = self::is_risky_alias($alias) ? '«' . $alias . '»' : $alias;
        }

        return $out;
    }

    /**
     * Алиас, который без кавычек ловил бы обычную речь.
     * Правило живёт в сканере: там же оно применяется при поиске.
     */
    public static function is_risky_alias($alias) {
        return CLEM_Scanner::is_risky_term($alias);
    }

    /**
     * Чистка алиасов, накопленных прежними версиями.
     *
     * Импорт алиасы только доклеивает, поэтому городá из записей местных
     * отделений («Калининград», «Йошкар-Ола») остаются в базе навсегда, даже
     * когда генератор их больше не делает. Убираем то, что было вырезано
     * из кавычек в названии самой записи.
     *
     * @return int сколько записей поправлено
     */
    public function prune_aliases() {
        global $wpdb;
        $table = $wpdb->prefix . CLEM_TABLE;
        $fixed = 0;

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $rows = $wpdb->get_results(
            "SELECT id, name, aliases FROM $table WHERE aliases != '' AND aliases != '[]'"
        ) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

        foreach ($rows as $row) {
            $aliases = json_decode($row->aliases, true);
            if (!is_array($aliases) || empty($aliases)) {
                continue;
            }

            // У местного отделения алиасов не бывает: генератор их больше не
            // делает, а накопленные - это город («Калининград») или название
            // головной организации, из-за которого одно упоминание давало
            // сразу две сноски
            $keep = self::is_local_branch($row->name) ? [] : $aliases;

            // Хвост страны алиасом не бывает: «Латвийская Республика»
            $keep = array_values(array_filter($keep, static function ($a) {
                $bare = trim(preg_replace('/^[«"„“]+|[»"”“]+$/u', '', trim((string) $a)));
                return $bare !== '' && !self::is_country($bare);
            }));

            if ($keep !== $aliases) {
                /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
                $wpdb->update(
                    $table,
                    ['aliases' => wp_json_encode($keep, JSON_UNESCAPED_UNICODE)],
                    ['id' => $row->id]
                ) ;
                /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
                $fixed++;
            }
        }

        if ($fixed > 0) {
            clem_plugin()->entities->flush_cache();
        }
        return $fixed;
    }

    /**
     * Запись местного отделения: «Местная религиозная организация Свидетелей
     * Иеговы «Калининград»».
     *
     * Кавычки в таких названиях содержат город, а не имя организации. Алиас
     * из них делать нельзя: упоминание всё равно ловится по головной записи,
     * а город даёт ложные срабатывания в любом тексте.
     */
    public static function is_local_branch($name) {
        return CLEM_Scanner::is_local_branch($name);
    }

    /**
     * Аббревиатура латинского названия: Global Investigative Journalism Network
     * -> GIJN. В текстах организацию чаще называют именно так, а в реестре
     * аббревиатуры нет вовсе.
     */
    public static function acronym($name) {
        $name = trim((string) preg_replace('/\s*\(.*$/u', '', (string) $name));
        $words = preg_split('/[\s\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);

        $letters = '';
        foreach ($words as $w) {
            $w = preg_replace('/^[«"„“(]+|[»"”“).,]+$/u', '', $w);
            if ($w === '') {
                continue;
            }
            // Служебные слова в аббревиатуру не входят: Reporters without Borders -> RWB
            if (mb_strlen($w) <= 3 && mb_strtolower($w) === $w) {
                continue;
            }
            if (!preg_match('/^[A-Za-z]/', $w)) {
                return ''; // не латинское название
            }
            $letters .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        return (mb_strlen($letters) >= 3 && mb_strlen($letters) <= 6) ? $letters : '';
    }

    /**
     * Площадки, на которых организация только ведёт аккаунт.
     * Их домены в реестр запрещённых не попадают: иначе любая ссылка
     * на YouTube или телеграм считалась бы ссылкой на иноагента.
     */
    const SOCIAL_HOSTS = [
        'youtube.com', 'youtu.be', 'vk.com', 'vk.ru', 'max.ru', 'ok.ru', 'facebook.com', 'fb.com',
        'instagram.com', 'threads.com', 'threads.net', 'twitter.com', 'x.com',
        't.me', 'telegram.me', 'telegram.org', 'tiktok.com', 'soundcloud.com',
        'podcasts.apple.com', 'music.apple.com', 'open.spotify.com', 'spotify.com',
        'patreon.com', 'boosty.to', 'linkedin.com', 'medium.com', 'github.com',
        'rutube.ru', 'dzen.ru', 'zen.yandex.ru', 'yandex.ru', 'google.com',
        'castbox.fm', 'anchor.fm', 'flipboard.com', 'tumblr.com', 'pinterest.com',
    ];

    /**
     * Собственные домены организации из поля со ссылками.
     *
     * @param string $raw «https://doxa.team/; https://twitter.com/doxa_journal; ...»
     * @return string[] только свои сайты, без соцсетей
     */
    public static function extract_own_domains($raw) {
        $out = [];
        foreach (preg_split('/[;,\s]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $url) {
            $url = trim($url);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $host = CLEM_Banned_Sites::normalize_domain($url);
            if ($host === '' || strpos($host, '.') === false) {
                continue;
            }
            foreach (self::SOCIAL_HOSTS as $social) {
                if ($host === $social || substr($host, -strlen('.' . $social)) === '.' . $social) {
                    continue 2;
                }
            }
            $out[$host] = true;
        }
        return array_keys($out);
    }

    /**
     * Аккаунты организации на чужих площадках.
     *
     * Домен телеграма или YouTube запрещать нельзя, а конкретный канал можно:
     * «https://t.me/doxajournal» -> t.me + doxajournal.
     *
     * @return array<int, array{domain: string, account: string}>
     */
    public static function extract_social_accounts($raw) {
        $out = [];
        foreach (preg_split('/[;,\s]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $url) {
            $url = trim($url);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $target = CLEM_Banned_Sites::split_target($url);
            if ($target['domain'] === '' || $target['account'] === '') {
                continue;
            }
            $out[$target['domain'] . '/' . $target['account']] = $target;
        }
        return array_values($out);
    }

    /**
     * Ресурсы организации в реестр запрещённых, со связкой с записью реестра.
     * Связь нужна отчёту: по ссылке видно, чей это ресурс и какого он реестра.
     *
     * @param array $targets ['domain' => ..., 'account' => ''] - пустой аккаунт
     *                       означает, что запрещён весь домен
     */
    private function sync_entity_targets($entity_id, array $targets, $label) {
        global $wpdb;
        $table = $wpdb->prefix . 'clem_banned_sites';
        $added = 0;

        foreach ($targets as $target) {
            $domain  = $target['domain'] ?? '';
            $account = $target['account'] ?? '';
            if ($domain === '') {
                continue;
            }

            /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, entity_id FROM $table WHERE domain = %s AND account = %s LIMIT 1",
                $domain, $account
            )) ;
            /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            if ($this->verified_generation !== '' && $wpdb->last_error !== '') { throw new RuntimeException('snapshot resource read failed'); }
            if ($existing) {
                // Ресурс мог быть добавлен вручную без связи с реестром
                if (empty($existing->entity_id)) {
                    /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
                    $written = $wpdb->update($table, ['entity_id' => $entity_id], ['id' => $existing->id]);
                    if ($written === false && $this->verified_generation !== '') { throw new RuntimeException('snapshot resource update failed'); }
                    /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
                }
                continue;
            }
            /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. */
            $written = $wpdb->insert($table, [
                'domain'    => $domain,
                'account'   => $account,
                'label'     => mb_substr($label, 0, 500),
                'entity_id' => $entity_id,
            ]) ;
            /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery */
            if ($written === false && $this->verified_generation !== '') { throw new RuntimeException('snapshot resource insert failed'); }
            $added++;
        }

        if ($added > 0) {
            clem_plugin()->banned_sites->flush_cache();
        }
        return $added;
    }

    /** Страна регистрации в хвосте названия: «, США», «(ФРГ)», «, Чешская Республика». */
    const COUNTRY_TAIL = '(?:США|ФРГ|Канада|Великобритания|Германия|Франция|Нидерланды|Латвия|Литва|Эстония|Польша|Чехия|Грузия|Украина|Израиль|Швейцария|Швеция|Норвегия|Финляндия|Испания|Италия|Бельгия|Австрия|Дания|Армения|Казахстан|Молдова|Азербайджан|(?:Федеративная|Чешская|Литовская|Латвийская|Эстонская|Словацкая|Французская)\s+Республика|Соединённые\s+Штаты\s+Америки|Соединенные\s+Штаты\s+Америки)';

    /** Организационно-правовая форма в хвосте: Inc., Ltd., gGmbH, e.V., z.s. */
    const LEGAL_TAIL = '(?:Inc\.?|LLC|Ltd\.?|Limited|gGmbH|GmbH|mbH|e\.\s?V\.|z\.\s?s\.|o\.\s?p\.\s?s\.|s\.\s?r\.\s?o\.|SIA|A\/S|N\.?V\.?|B\.?V\.?|PLC|Corp\.?)';

    /**
     * Убирает хвост страны и организационно-правовой формы.
     * «Cultural Vistas (США)» -> «Cultural Vistas»
     */
    public static function strip_legal_suffix($name) {
        $s = trim((string) $name);
        $before = null;
        // Хвостов может быть несколько: «Dekoder gGmbH, ФРГ»
        while ($before !== $s) {
            $before = $s;
            $s = preg_replace('/[\s,]*\(\s*' . self::COUNTRY_TAIL . '\s*\)\s*$/u', '', $s);
            $s = preg_replace('/\s*,\s*' . self::COUNTRY_TAIL . '\s*$/u', '', $s);
            $s = preg_replace('/[\s,]*' . self::LEGAL_TAIL . '\s*$/u', '', $s);
            $s = rtrim($s, " \t,;");
        }
        return trim($s);
    }

    /**
     * Название - это не организация, а обрывок описания со страницы Минюста.
     *
     * Парсер берёт абзацы подряд, поэтому в реестр экстремистских попадают
     * куски вроде «эмблема Партии представляет собой...» или сноска
     * «Организация исключена в связи с ликвидацией...».
     */
    public static function is_junk_name($name) {
        $n = trim((string) $name);
        if ($n === '') {
            return true;
        }
        $starts = '/^(Организация исключена|Организация ликвидирована|Решение[мс]?\s|Признан[оаы]?\s|Указанн|Согласно\s|В соответствии\s|в соответствии\s|Деятельность организации призна|[Ээ]мблема|[Фф]лаг\s|Символика|а также\s|при этом\s|кроме того)/u';
        if (preg_match($starts, $n)) {
            return true;
        }
        if (preg_match('/(представляет собой|имеет свои символы|по основаниям, предусмотренным)/u', $n)) {
            return true;
        }
        return false;
    }

    /** Страны и их официальные формы, встречающиеся в записях реестра. */
    public static function is_country($text) {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/^(федеративная|чешская|литовская|латвийская|эстонская|словацкая|французская)\s+/u', '', $t);
        $countries = [
            'республика', 'соединенные штаты америки', 'соединённые штаты америки',
            'великобритания', 'соединенное королевство', 'германия', 'франция',
            'нидерланды', 'канада', 'литва', 'латвия', 'эстония', 'польша',
            'чехия', 'грузия', 'украина', 'израиль', 'швейцария', 'швеция',
            'норвегия', 'финляндия', 'испания', 'италия', 'бельгия', 'австрия',
        ];
        return in_array($t, $countries, true);
    }

    public function import_json($file, $type_override = null, $trusted = true) {
        $json = file_get_contents($file);
        if ($json === false) {
            return ['error' => "Cannot read file: $file"];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['error' => "Invalid JSON in: $file"];
        }
        return $this->import_entries($data, $type_override, $trusted);
    }

    /**
     * Импорт уже разобранных записей.
     * Отдельно от import_json: сетевой источник не обязан ложиться на диск,
     * папка плагина на части хостингов доступна только на чтение.
     *
     * @param bool $trusted источник свежий (реестр, канал). Копии - встроенный
     *                      перечень и зеркало - исключённых не возвращают,
     *                      см. resolve_exclusion()
     */
    public function import_entries(array $data, $type_override = null, $trusted = true) {
        return CLEM_Registry_Sync::with_lock(function () use ($data, $type_override, $trusted) {
            return $this->import_entries_locked($data, $type_override, $trusted);
        });
    }

    public function import_verified_entries(array $data, $type, $generation, $defer_activation = false) {
        $old = [$this->verified_generation, $this->defer_activation];
        $this->verified_generation = $generation;
        $this->defer_activation = $defer_activation;
        try { return $this->import_entries($data, $type, true); }
        finally { [$this->verified_generation, $this->defer_activation] = $old; }
    }

    public function import_snapshot(array $data, $type, array $meta) {
        return CLEM_Registry_Sync::apply($this, $data, $type, $meta);
    }

    private function import_entries_locked(array $data, $type_override, $trusted) {
        global $wpdb;
        $table = $wpdb->prefix . CLEM_TABLE;

        $added = 0;
        $skipped = 0;
        $updated = 0;
        $domains = 0;

        foreach ($data as $entry) {
            $name = trim($entry['name'] ?? $entry['fullName'] ?? '');
            if (empty($name) || self::is_junk_name($name)) {
                $skipped++;
                continue;
            }

            $type      = $type_override ?: ($entry['type'] ?? 'inoagent');
            $is_person = (int) ($entry['is_person'] ?? (!empty($entry['dob']) ? 1 : 0));
            $aliases   = $entry['aliases'] ?? [];
            if (!is_array($aliases)) {
                $aliases = [];
            }
            // Онлайн-загрузчики Минюста алиасов не дают вообще, поэтому строим их
            // сами: иначе организации, попавшие в реестр после сборки плагина,
            // находились бы только по полному юридическому названию
            $aliases = $type === 'fedsfm_person' ? [] : array_values(array_unique(array_merge(
                $aliases,
                self::generate_aliases($name, $is_person)
            )));

            if (!$is_person) {
                $aliases = CLEM_Scanner::organization_aliases($name, $aliases);
            }

            $date_in = $this->parse_date($entry['date_included'] ?? $entry['dateIn'] ?? null);
            $date_out = $this->parse_date($entry['date_excluded'] ?? $entry['dateOut'] ?? null);

            /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, aliases, date_excluded, is_active, registry_present, is_manual FROM $table WHERE name = %s AND type = %s LIMIT 1",
                $name, $type
            )) ;
            /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

            $verified = $this->verified_generation !== '';
            if ($verified && $wpdb->last_error !== '') { throw new RuntimeException('snapshot row read failed'); }
            // A file/partial import is not a complete snapshot, even if its caller
            // used the old default trusted=true flag. Never roll back a verified set.
            $copy_only = !$verified && CLEM_Registry_Sync::has_snapshot($type);
            if ($copy_only && !$existing) { $skipped++; continue; }
            if ($existing) {
                $date_out = self::resolve_exclusion($existing->date_excluded, $date_out, $verified && $trusted);
            }
            $is_active = empty($date_out) && (!array_key_exists('is_active', $entry) || !empty($entry['is_active'])) ? 1 : 0;
            if ($existing && (!$verified || $this->defer_activation) && !(int) $existing->is_active) {
                $is_active = 0;
            }
            if ($this->defer_activation && !$existing) { $is_active = 0; }
            if ($copy_only) {
                $is_active = (int) $existing->is_active;
                $date_out = $existing->date_excluded;
            }

            if ($existing) {
                // Сливаем алиасы, а не перезаписываем: ручные/курированные не должны пропадать при обновлении
                $old_aliases = json_decode($existing->aliases, true) ?: [];
                $aliases     = array_values(array_unique(array_merge($old_aliases, $aliases)));
                /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
                $changes = [
                    'aliases'       => wp_json_encode($aliases, JSON_UNESCAPED_UNICODE),
                    'is_person'     => $is_person,
                    'date_included' => $date_in,
                    'date_excluded' => $date_out,
                    'is_active'     => $is_active,
                ];
                if ($copy_only) { $changes = ['aliases' => $changes['aliases']]; }
                else { $changes['name'] = $name; }
                if ($verified) {
                    $changes['sync_generation'] = $this->verified_generation;
                    $changes['registry_present'] = 1;
                }
                $written = $wpdb->update($table, $changes, ['id' => $existing->id]);
                if ($written === false && $verified) { throw new RuntimeException('snapshot row update failed'); }
                /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
                $entity_id = (int) $existing->id;
                $updated++;
            } else {
                /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. */
                $written = $wpdb->insert($table, [
                    'type'          => $type,
                    'name'          => $name,
                    'aliases'       => wp_json_encode($aliases, JSON_UNESCAPED_UNICODE),
                    'is_person'     => $is_person,
                    'date_included' => $date_in,
                    'date_excluded' => $date_out,
                    'is_active'     => $is_active,
                    'registry_present' => 1,
                    'is_manual' => 0,
                    'sync_generation' => $this->verified_generation,
                    // Когда запись появилась именно у нас: по ней считаются новички
                    'first_seen'    => current_time('mysql'),
                ]) ;
                if ($written === false && $verified) { throw new RuntimeException('snapshot row insert failed'); }
                /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery */
                $entity_id = (int) $wpdb->insert_id;
                $added++;
            }

            // Официальные ресурсы организации из реестра: домены и аккаунты идут
            // в сканер ссылок и связываются с этой записью
            if ($entity_id > 0 && !$copy_only) {
                $targets = [];
                foreach ((array) ($entry['sites'] ?? []) as $site) {
                    $targets[] = ['domain' => $site, 'account' => ''];
                }
                foreach ((array) ($entry['accounts'] ?? []) as $account) {
                    if (!empty($account['domain']) && !empty($account['account'])) {
                        $targets[] = $account;
                    }
                }
                if (!empty($targets)) {
                    $domains += $this->sync_entity_targets($entity_id, $targets, $name);
                }
            }
        }

        clem_plugin()->entities->flush_cache();
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped,
                'domains' => $domains, 'total' => count($data),
                'retained_verified_snapshot' => !$this->verified_generation && $type_override && CLEM_Registry_Sync::has_snapshot($type_override)];
    }

    /**
     * Дата исключения, которую записать в базу.
     *
     * Копия реестра старше базы: человека, которого Минюст исключил на прошлой
     * неделе, она ещё числит действующим. Поверить ей - снова пометить иноагентом
     * того, кто им уже не является. Поэтому копия может исключить запись,
     * а вернуть исключённую не может. Свежему источнику верим во всём.
     */
    public static function resolve_exclusion($current, $incoming, $trusted) {
        if (!$trusted && !empty($current)) {
            return $current;
        }
        return $incoming;
    }

    /* ------------------------------------------------------------------
     * Banned sites import
     * ------------------------------------------------------------------ */

    /**
     * Импорт запрещённых доменов из встроенного JSON-файла.
     *
     * @param string|null $file Путь к JSON-файлу. По умолчанию - data/banned-sites.json.
     * @return array ['added' => int, 'skipped' => int]
     */
    public function import_banned_sites($file = null) {
        if ($file === null) {
            $file = CLEM_DATA_DIR . '/banned-sites.json';
        }
        if (!file_exists($file)) {
            return ['added' => 0, 'skipped' => 0, 'error' => "File not found: $file"];
        }

        $json = file_get_contents($file);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['added' => 0, 'skipped' => 0, 'error' => 'Invalid JSON'];
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'clem_banned_sites';
        $added   = 0;
        $skipped = 0;
        $typed   = 0;

        foreach ($data as $entry) {
            // insert() сам разберёт t.me/doxajournal на площадку и аккаунт
            $raw = trim((string) ($entry['domain'] ?? ''));
            if ($raw === '') {
                $skipped++;
                continue;
            }
            $type = CLEM_Banned_Sites::clean_registry($entry['type'] ?? '');

            $result = clem_plugin()->banned_sites->insert([
                'domain'   => $raw,
                'label'    => $entry['label'] ?? '',
                'registry' => $type,
            ]);

            if ($result) {
                $added++;
                continue;
            }

            $skipped++;
            // Домен уже заведён. До версии 1.12.0 тип из файла не сохранялся,
            // и такие домены выглядели как добавленные вручную
            if ($type !== '') {
                $target = CLEM_Banned_Sites::split_target($raw);
                /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
                $typed += (int) $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET registry = %s
                     WHERE domain = %s AND account = %s AND registry = '' AND entity_id IS NULL",
                    $type, $target['domain'], $target['account']
                )) ;
                /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            }
        }

        if ($typed > 0) {
            clem_plugin()->banned_sites->flush_cache();
        }

        clem_plugin()->banned_sites->flush_cache();
        return ['added' => $added, 'skipped' => $skipped, 'typed' => $typed];
    }

    public function fetch_all($log_callback = null) {
        $this->last_fetch_domains = 0;
        $this->last_fetch_updated = false;
        if (!clem_plugin()->channel->is_enabled()) {
            return [CLEM_Channel::disabled_message()];
        }
        $result = CLEM_Registry_Sync::with_lock(function () use ($log_callback) {
            return $this->fetch_all_locked($log_callback);
        });
        return isset($result['error']) ? [$result['error']] : $result;
    }

    private function fetch_all_locked($log_callback) {
        $log = $log_callback ?: static function ($message) {};
        $started = current_time('mysql');
        $errors = [];
        $diagnostics = [];
        $sources = [];
        $domains = 0;
        $updated = 0;

        foreach (CLEM_Registry_Sync::TYPES as $type) {
            $result = $this->fetch_from_channel($type);
            if (isset($result['error'])) {
                $sources[$type] = CLEM_Registry_Sync::has_snapshot($type) ? 'retained' : 'local';
                $diagnostics[$type] = [$result['error']];
                $errors[] = self::REGISTRY_LABELS[$type] . ': ' . $result['error'];
                $log(end($errors));
                continue;
            }
            $sources[$type] = 'channel';
            $domains += (int) ($result['domains'] ?? 0);
            $updated++;
            $log(self::REGISTRY_LABELS[$type] . ": added={$result['added']}, updated={$result['updated']}");
        }

        $this->last_fetch_domains = $domains;
        $this->last_fetch_updated = $updated > 0;
        update_option('clem_last_fetch_sources', $sources, false);
        if ($errors) {
            update_option('clem_last_fetch_diagnostics', $diagnostics, false);
            update_option('clem_last_fetch_error', implode('; ', $errors), false);
        } else {
            delete_option('clem_last_fetch_diagnostics');
            delete_option('clem_last_fetch_error');
        }

        if ($updated) {
            $this->import_banned_sites();
            $this->apply_brand_aliases();
            update_option('clem_list_version', gmdate('Y-m-d H:i:s'));
            update_option('clem_last_fetch_time', current_time('mysql'));
            clem_plugin()->entities->flush_cache();
            clem_plugin()->cache->purge_all_marked();

            $first_run = !get_option('clem_first_fetch_done');
            update_option('clem_first_fetch_done', 1, false);
            clem_plugin()->rescan->enqueue($first_run ? 'initial' : 'registry-update', $started);
        }

        clem_plugin()->people->queue();
        return $errors;
    }

    public function fetch_registry($type) {
        return CLEM_Registry_Sync::with_lock(function () use ($type) {
            return $this->fetch_from_channel($type);
        });
    }

    private function fetch_from_channel($type) {
        $channel = clem_plugin()->channel->fetch($type);
        if (isset($channel['error'])) {
            return $channel;
        }
        return $this->import_snapshot($channel['entries'], $type, $channel['meta']);
    }

    private function parse_date($raw) {
        if (empty($raw)) {
            return null;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            return "$m[3]-$m[2]-$m[1]";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return null;
    }

}
