<?php
defined('ABSPATH') || exit;

class LEM_Importer {

    /** Итоги последнего обновления реестров: домены, собранные из данных Минюста. */
    public $last_fetch_domains = 0;

    /** Человеческие названия реестров для сообщений в админке. */
    const REGISTRY_LABELS = [
        'inoagent'    => 'Иностранные агенты',
        'extremist'   => 'Экстремистские организации',
        'terrorist'   => 'Террористические организации',
        'undesirable' => 'Нежелательные организации',
    ];

    public function import_all_bundled() {
        $files = [
            'inoagent'    => LEM_DATA_DIR . '/foreign-agents-raw.json',
            'extremist'   => LEM_DATA_DIR . '/extremist-orgs.json',
            'terrorist'   => LEM_DATA_DIR . '/terrorist-orgs.json',
            'undesirable' => LEM_DATA_DIR . '/undesirable-orgs.json',
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
                lem()->brands->get_rules(),
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
        $table    = $wpdb->prefix . LEM_TABLE;
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

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, aliases, status_text FROM $table WHERE name LIKE %s",
                '%' . $wpdb->esc_like($match) . '%'
            ));

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
                    $wpdb->update($table, $update, ['id' => $row->id]);
                    $applied++;
                }
            }
        }

        lem()->entities->flush_cache();
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
     * такой алиас ищется только в кавычках, см. LEM_Scanner.
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

        return self::clean_aliases($out, $name);
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
        return LEM_Scanner::is_risky_term($alias);
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
        $table = $wpdb->prefix . LEM_TABLE;
        $fixed = 0;

        $rows = $wpdb->get_results(
            "SELECT id, name, aliases FROM $table WHERE aliases != '' AND aliases != '[]'"
        );

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
                $wpdb->update(
                    $table,
                    ['aliases' => wp_json_encode($keep, JSON_UNESCAPED_UNICODE)],
                    ['id' => $row->id]
                );
                $fixed++;
            }
        }

        if ($fixed > 0) {
            lem()->entities->flush_cache();
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
        return (bool) preg_match(
            '/^(Местн(ая|ое)|Первичн(ая|ое)|Религиозная группа|Региональн(ая|ое)\s+религиозн)/u',
            trim((string) $name)
        );
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
        'youtube.com', 'youtu.be', 'vk.com', 'ok.ru', 'facebook.com', 'fb.com',
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
            $host = LEM_Banned_Sites::normalize_domain($url);
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
            $target = LEM_Banned_Sites::split_target($url);
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
        $table = $wpdb->prefix . 'lem_banned_sites';
        $added = 0;

        foreach ($targets as $target) {
            $domain  = $target['domain'] ?? '';
            $account = $target['account'] ?? '';
            if ($domain === '') {
                continue;
            }

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, entity_id FROM $table WHERE domain = %s AND account = %s LIMIT 1",
                $domain, $account
            ));
            if ($existing) {
                // Ресурс мог быть добавлен вручную без связи с реестром
                if (empty($existing->entity_id)) {
                    $wpdb->update($table, ['entity_id' => $entity_id], ['id' => $existing->id]);
                }
                continue;
            }
            $wpdb->insert($table, [
                'domain'    => $domain,
                'account'   => $account,
                'label'     => mb_substr($label, 0, 500),
                'entity_id' => $entity_id,
            ]);
            $added++;
        }

        if ($added > 0) {
            lem()->banned_sites->flush_cache();
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
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;

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
            $aliases = array_values(array_unique(array_merge(
                $aliases,
                self::generate_aliases($name, $is_person)
            )));

            $date_in = $this->parse_date($entry['date_included'] ?? $entry['dateIn'] ?? null);
            $date_out = $this->parse_date($entry['date_excluded'] ?? $entry['dateOut'] ?? null);

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, aliases, date_excluded FROM $table WHERE name = %s AND type = %s LIMIT 1",
                $name, $type
            ));

            if ($existing) {
                $date_out = self::resolve_exclusion($existing->date_excluded, $date_out, $trusted);
            }
            $is_active = empty($date_out) ? 1 : 0;

            if ($existing) {
                // Сливаем алиасы, а не перезаписываем: ручные/курированные не должны пропадать при обновлении
                $old_aliases = json_decode($existing->aliases, true) ?: [];
                $aliases     = array_values(array_unique(array_merge($old_aliases, $aliases)));
                $wpdb->update($table, [
                    'aliases'       => wp_json_encode($aliases, JSON_UNESCAPED_UNICODE),
                    'is_person'     => $is_person,
                    'date_included' => $date_in,
                    'date_excluded' => $date_out,
                    'is_active'     => $is_active,
                ], ['id' => $existing->id]);
                $entity_id = (int) $existing->id;
                $updated++;
            } else {
                $wpdb->insert($table, [
                    'type'          => $type,
                    'name'          => $name,
                    'aliases'       => wp_json_encode($aliases, JSON_UNESCAPED_UNICODE),
                    'is_person'     => $is_person,
                    'date_included' => $date_in,
                    'date_excluded' => $date_out,
                    'is_active'     => $is_active,
                    // Когда запись появилась именно у нас: по ней считаются новички
                    'first_seen'    => current_time('mysql'),
                ]);
                $entity_id = (int) $wpdb->insert_id;
                $added++;
            }

            // Официальные ресурсы организации из реестра: домены и аккаунты идут
            // в сканер ссылок и связываются с этой записью
            if ($entity_id > 0) {
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

        lem()->entities->flush_cache();
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped,
                'domains' => $domains, 'total' => count($data)];
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
            $file = LEM_DATA_DIR . '/banned-sites.json';
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
        $table   = $wpdb->prefix . 'lem_banned_sites';
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
            $type = LEM_Banned_Sites::clean_registry($entry['type'] ?? '');

            $result = lem()->banned_sites->insert([
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
                $target = LEM_Banned_Sites::split_target($raw);
                $typed += (int) $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET registry = %s
                     WHERE domain = %s AND account = %s AND registry = '' AND entity_id IS NULL",
                    $type, $target['domain'], $target['account']
                ));
            }
        }

        if ($typed > 0) {
            lem()->banned_sites->flush_cache();
        }

        lem()->banned_sites->flush_cache();
        return ['added' => $added, 'skipped' => $skipped, 'typed' => $typed];
    }

    /* ------------------------------------------------------------------
     * Registry fetchers (online sources)
     * ------------------------------------------------------------------ */

    public function fetch_all($log_callback = null) {
        $log     = $log_callback ?: function ($msg) {};
        $errors  = [];
        $domains = 0;

        // Всё, что появится в реестре после этой отметки, считается новичком
        $started = current_time('mysql');
        $sources = [];
        global $wpdb;
        $had_entities = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . LEM_TABLE
        ) > 0;

        $fetchers = [
            'inoagent'    => 'fetch_inoagents',
            'extremist'   => 'fetch_extremist_orgs',
            'terrorist'   => 'fetch_terrorist_orgs',
            'undesirable' => 'fetch_undesirable_orgs',
        ];

        foreach ($fetchers as $type => $method) {
            $label = self::REGISTRY_LABELS[$type] ?? $type;
            $log("Fetching: $type...");
            $result = $this->$method();

            if (!isset($result['error'])) {
                $domains += (int) ($result['domains'] ?? 0);
                $sources[$type] = 'official';
                $log("  OK: added={$result['added']}, updated={$result['updated']}, total={$result['total']}");
                continue;
            }

            $log("  ERROR: {$result['error']}");

            // Цепочка запасных источников от свежего к старому. Причину отказа
            // каждого звена сохраняем: администратор должен видеть, что именно
            // не ответило, а не последнюю ошибку в цепочке
            $reasons = [$result['error']];

            // Канал: срез раз в сутки, свежее любой копии
            $channel = $this->fetch_from_channel($type, $log);
            if (!isset($channel['error'])) {
                $domains += (int) ($channel['domains'] ?? 0);
                $sources[$type] = 'channel';
                $errors[] = "$label: " . implode('; ', $reasons) . '. Данные взяты из канала реестров';
                continue;
            }
            if ($channel['error'] !== '') {
                $reasons[] = $channel['error'];
            }

            // Зеркало в репозитории плагина обновляется с выпусками
            if (isset(self::MIRRORS[$type])) {
                $mirror = $this->fetch_mirror(self::MIRRORS[$type], $type);
                if (!isset($mirror['error'])) {
                    $domains += (int) ($mirror['domains'] ?? 0);
                    $sources[$type] = 'mirror';
                    $log("  Зеркало: added={$mirror['added']}, updated={$mirror['updated']}");
                    $errors[] = "$label: " . implode('; ', $reasons) . '. Перечень взят из зеркала';
                    continue;
                }
                $reasons[] = $mirror['error'];
            }

            $fallback_files = [
                'inoagent'    => 'foreign-agents-raw.json',
                'extremist'   => 'extremist-orgs.json',
                'terrorist'   => 'terrorist-orgs.json',
                'undesirable' => 'undesirable-orgs.json',
            ];
            $fallback = LEM_DATA_DIR . '/' . $fallback_files[$type];

            // Встроенный перечень никуда не делся, поэтому сайт продолжает
            // работать. Сообщение должно говорить именно это, а не пугать
            if (file_exists($fallback)) {
                $fb_result = $this->import_json($fallback, $type, false);
                $domains  += (int) ($fb_result['domains'] ?? 0);
                $sources[$type] = 'bundled';
                $log("  Fallback: added={$fb_result['added']}, updated={$fb_result['updated']}");
                $errors[] = "$label: " . implode('; ', $reasons) . '. Применён встроенный перечень, '
                    . 'сайт работает по нему';
            } else {
                $log("  No fallback file found: $fallback");
                $sources[$type] = 'none';
                $errors[] = "$label: " . implode('; ', $reasons) . '. Встроенного перечня нет';
            }
        }

        // Обновление запрещённых доменов из встроенного JSON
        $log('Обновление реестра запрещённых доменов...');
        $bs = $this->import_banned_sites();
        $log("  Добавлено={$bs['added']}, пропущено={$bs['skipped']}");
        if ($domains > 0) {
            $log("  Из записей реестра взято новых доменов: $domains");
        }

        // Брендовые алиасы поверх свежих официальных названий
        $ba = $this->apply_brand_aliases();
        $log("  Брендовых алиасов применено: {$ba['applied']}");

        // Раз в цикл обновления проверяем канал, даже если он не понадобился.
        // Иначе о его доступности узнаёшь ровно в тот момент, когда он нужен,
        // то есть когда официальный источник уже упал
        if (lem()->channel->is_enabled()) {
            $health = lem()->channel->health();
            update_option('lem_channel_health', [
                'ok'            => !empty($health['ok']),
                'last_snapshot' => $health['last_snapshot'],
                'error'         => $health['error'],
                'checked_at'    => current_time('mysql'),
            ], false);
            $log($health['ok']
                ? '  Канал реестров доступен, срез: ' . $health['last_snapshot']
                : '  Канал реестров недоступен: ' . $health['error']);
        }

        update_option('lem_list_version', gmdate('Y-m-d H:i:s'));
        update_option('lem_last_fetch_time', current_time('mysql'));
        // Откуда пришли данные в этот раз: видно в админке
        update_option('lem_last_fetch_sources', $sources, false);
        if (!empty($errors)) {
            update_option('lem_last_fetch_error', implode('; ', $errors));
        } else {
            delete_option('lem_last_fetch_error');
        }

        lem()->entities->flush_cache();
        $this->last_fetch_domains = $domains;

        // Реестр обновился - значит, в старых публикациях могли появиться
        // упоминания и ссылки, которых вчера ещё не было. Проверяем архив.
        // При первичном наполнении новичками считался бы весь реестр,
        // поэтому отсечку берём с текущего момента
        $first_run = !get_option('lem_first_fetch_done');
        update_option('lem_first_fetch_done', 1, false);

        lem()->rescan->enqueue(
            ($first_run || !$had_entities) ? 'initial' : 'registry-update',
            $started
        );

        return $errors;
    }

    /** Гриды реестров на reestrs.minjust.gov.ru. */
    const INOAGENT_GRID    = '39b95df9-9a68-6b6d-e1e3-e6388507067e';
    const UNDESIRABLE_GRID = 'c2d1692e-a9f6-5a79-13ee-5da5b42980df';

    /** Копии перечней в репозитории плагина, см. fetch_mirror(). */
    const MIRRORS = [
        'extremist' => self::EXTREMIST_MIRROR_URL,
        'terrorist' => self::TERROR_MIRROR_URL,
    ];

    /**
     * Все записи реестра Минюста, постранично.
     *
     * Либо реестр целиком, либо ошибка с причиной. Обрыв на середине тоже
     * ошибка: половина реестра, выданная за весь, хуже честного отказа,
     * после которого плагин сходит за полным списком в канал.
     *
     * @return array{values: array}|array{error: string}
     */
    private function fetch_minjust_values($grid) {
        $url    = 'https://reestrs.minjust.gov.ru/rest/registry/' . $grid . '/values';
        $values = [];
        $offset = 0;
        $limit  = 500;

        while (true) {
            // LEM_Http, а не wp_remote_post: чужой фильтр может срезать таймаут
            // до 10 секунд, а реестр отдаёт страницу дольше
            $response = LEM_Http::post($url, [
                'timeout'   => 30,
                'sslverify' => false,
                'headers'   => ['Content-Type' => 'application/json'],
                'body'      => wp_json_encode(['offset' => $offset, 'limit' => $limit, 'search' => '']),
            ]);
            $where = $offset > 0 ? ' на записи ' . ($offset + 1) : '';

            if (is_wp_error($response)) {
                return ['error' => 'реестр Минюста не ответил' . $where . ': ' . $response->get_error_message()];
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return ['error' => "реестр Минюста ответил HTTP $code" . $where];
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data) || !isset($data['values']) || !is_array($data['values'])) {
                return ['error' => 'реестр Минюста вернул неожиданный ответ' . $where];
            }

            $values = array_merge($values, $data['values']);
            if (count($data['values']) < $limit || $offset + $limit >= ($data['size'] ?? 0)) {
                break;
            }
            $offset += $limit;
        }

        if (empty($values)) {
            return ['error' => 'реестр Минюста вернул пустой список'];
        }
        return ['values' => $values];
    }

    /** Иноагенты: REST API reestrs.minjust.gov.ru. */
    public function fetch_inoagents() {
        $result = $this->fetch_minjust_values(self::INOAGENT_GRID);
        if (isset($result['error'])) {
            return $result;
        }

        $entries = [];
        foreach ($result['values'] as $item) {
            $name = trim($item['field_2_s'] ?? '');
            if (mb_strlen($name) < 3) {
                continue;
            }
            // Убираем декоративные кавычки только у имён, обёрнутых в кавычки целиком,
            // иначе ломаются имена с псевдонимом на конце: Иванов Иван "Псевдоним"
            if (preg_match('/^[«"]/u', $name)) {
                $name = trim(preg_replace('/^[«"]+|[»"]+$/u', '', $name));
            }

            $type_str  = $item['field_7_s'] ?? '';
            $is_person = ($type_str === 'Физические лица') ? 1 : 0;
            $dob       = $item['field_12_s'] ?? '';

            // Алиасы для физлиц: "Фамилия Имя" из полного ФИО + псевдонимы из кавычек
            $aliases = [];
            if ($is_person) {
                // Псевдонимы: ФИО «Псевдоним (Alias)» → Псевдоним, Alias
                if (preg_match('/[«"](.+?)[»"]?$/u', $name, $pm)) {
                    foreach (preg_split('/[()«»"]+/u', $pm[1]) as $pseudo) {
                        $pseudo = trim($pseudo, " \t,;");
                        // Короткие чисто кириллические псевдонимы («Белый») дают ложные срабатывания
                        $distinctive = preg_match('/[\sA-Za-z0-9-]/u', $pseudo) || mb_strlen($pseudo) >= 6;
                        if (mb_strlen($pseudo) >= 3 && $distinctive) {
                            $aliases[] = $pseudo;
                        }
                    }
                }
                // Убираем псевдоним (включая незакрытую кавычку): ФИО «Псевдоним → ФИО
                $clean_name = preg_replace('/\s*[«"].*$/u', ' ', $name);
                $parts = preg_split('/\s+/', trim($clean_name));
                if (count($parts) >= 2) {
                    $aliases[] = $parts[0] . ' ' . $parts[1];
                }
            }

            $entry = [
                'name'      => $name,
                'type'      => 'inoagent',
                'aliases'   => $aliases,
                'is_person' => $is_person,
            ];

            // Минюст публикует официальные ресурсы организации (field_6_s).
            // Это лучший источник доменов для сканера ссылок: он точный,
            // обновляется вместе с реестром и не требует ручного списка
            if (!empty($item['field_6_s'])) {
                $entry['sites']    = self::extract_own_domains($item['field_6_s']);
                $entry['accounts'] = self::extract_social_accounts($item['field_6_s']);
            }

            if (!empty($item['field_4_s'])) {
                $entry['dateIn'] = $item['field_4_s'];
            }
            if (!empty($item['field_5_s'])) {
                $entry['date_excluded'] = $item['field_5_s'];
            }

            $entries[] = $entry;
        }

        if (empty($entries)) {
            return ['error' => 'реестр Минюста ответил, но записей не разобрано'];
        }
        return $this->import_entries($entries, 'inoagent');
    }

    /** Страница Минюста с перечнем экстремистских организаций. */
    const EXTREMIST_URL = 'https://minjust.gov.ru/ru/documents/7822/';

    /**
     * Зеркало перечня в репозитории плагина.
     *
     * Единственный реестр, которого нет в канале: если страница Минюста
     * не открылась, откатываться было некуда, кроме снимка из поставки.
     * Зеркало обновляется с каждым выпуском и всегда свежее его.
     */
    const EXTREMIST_MIRROR_URL = 'https://raw.githubusercontent.com/chekazoid/legal-entity-marks/main/data/extremist-orgs.json';

    public function fetch_extremist_orgs() {
        $result = $this->fetch_url(self::EXTREMIST_URL, 45);
        if (isset($result['error'])) {
            return ['error' => 'minjust.gov.ru не открылся (' . $result['error'] . ')'];
        }

        $entries = $this->parse_minjust_list($result['body']);
        if (empty($entries)) {
            return ['error' => 'minjust.gov.ru ответил, но список не разобран (получено '
                . strlen($result['body']) . ' байт)'];
        }
        foreach ($entries as &$e) {
            $e['type'] = 'extremist';
        }
        unset($e);
        return $this->import_entries($entries, 'extremist');
    }

    /**
     * Перечень из зеркала в репозитории плагина.
     *
     * Зеркало обновляется с выпусками, то есть это копия, и исключённых она
     * не возвращает. Опрашивается после канала: тот свежее.
     *
     * @return array результат импорта либо ['error' => причина]
     */
    private function fetch_mirror($url, $type) {
        $mirror = $this->fetch_url($url, 45);
        if (isset($mirror['error'])) {
            return ['error' => 'зеркало недоступно (' . $mirror['error'] . ')'];
        }

        $data = json_decode($mirror['body'], true);
        if (!is_array($data) || empty($data)) {
            return ['error' => 'зеркало вернуло неожиданный ответ'];
        }

        return $this->import_entries($data, $type, false);
    }

    /** Единый перечень ФСБ. Работает только по http: на 443 порту сайт не отвечает. */
    const TERROR_URL = 'http://www.fsb.ru/fsb/npd/terror.htm';

    /**
     * Запасной источник - копия перечня в репозитории плагина.
     * Раньше здесь стоял nac.gov.ru, но там список рисует скрипт уже в браузере:
     * страница отдавала 200, разбор давал ноль записей, и наружу вылезала
     * ошибка парсера вместо настоящей причины.
     */
    const TERROR_MIRROR_URL = 'https://raw.githubusercontent.com/chekazoid/legal-entity-marks/main/data/terrorist-orgs.json';

    public function fetch_terrorist_orgs() {
        $result = $this->fetch_url(self::TERROR_URL, 45);
        if (isset($result['error'])) {
            return ['error' => 'fsb.ru не открылся (' . $result['error'] . ')'];
        }

        $entries = $this->parse_generic_list($result['body']);
        if (empty($entries)) {
            return ['error' => 'fsb.ru ответил, но список не разобран (получено '
                . strlen($result['body']) . ' байт)'];
        }
        foreach ($entries as &$e) {
            $e['type'] = 'terrorist';
        }
        unset($e);
        return $this->import_entries($entries, 'terrorist');
    }

    /**
     * Нежелательные организации: REST API reestrs.minjust.gov.ru.
     *
     * Старую страницу minjust.gov.ru/ru/documents/7756/ здесь больше не трогаем:
     * с февраля 2026 она отвечает 404. Плагин уходил на неё всякий раз, когда
     * API не ответил, и вместо настоящей причины показывал этот 404.
     */
    public function fetch_undesirable_orgs() {
        $result = $this->fetch_minjust_values(self::UNDESIRABLE_GRID);
        if (isset($result['error'])) {
            return $result;
        }

        $entries = [];
        foreach ($result['values'] as $item) {
            $name = trim($item['field_5_s'] ?? '');
            if (mb_strlen($name) < 5 || mb_strlen($name) > 500) {
                continue;
            }
            $entry = [
                'name'      => $name,
                'type'      => 'undesirable',
                'aliases'   => [],
                'is_person' => false,
            ];
            $status = $item['field_10_s'] ?? '';
            if ($status === 'Исключена') {
                $entry['date_excluded'] = $item['field_8_s'] ?? gmdate('d.m.Y');
            }
            if (!empty($item['field_2_s'])) {
                $entry['dateIn'] = $item['field_2_s'];
            }
            $entries[] = $entry;
        }

        if (empty($entries)) {
            return ['error' => 'реестр Минюста ответил, но записей не разобрано'];
        }
        return $this->import_entries($entries, 'undesirable');
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Данные из канала реестров.
     *
     * @return array результат импорта либо ['error' => причина]. Пустая причина -
     *               канал такой реестр не ведёт, упоминать его в сообщении незачем
     */
    private function fetch_from_channel($type, $log) {
        if (!LEM_Channel::supports($type)) {
            $log('  Канал такой реестр не ведёт');
            return ['error' => ''];
        }
        if (!lem()->channel->is_enabled()) {
            $log('  Канал реестров выключен в настройках');
            return ['error' => 'канал реестров выключен в настройках'];
        }

        $log('  Пробуем канал реестров...');
        $channel = lem()->channel->fetch($type);
        if (isset($channel['error'])) {
            $log("  Канал не помог: {$channel['error']}");
            return ['error' => 'канал реестров: ' . $channel['error']];
        }

        $imported = $this->import_entries($channel['entries'], $type);
        $log("  Канал: получено {$channel['count']}, добавлено {$imported['added']}, "
            . "обновлено {$imported['updated']}");
        return $imported;
    }

    /**
     * Опрос источников: что именно видит этот сервер.
     *
     * Нужна поддержке. Хостинг может резать исходящие соединения, источник может
     * банить диапазон адресов - со стороны это выглядит как «плагин не обновляет
     * реестры», а без доступа к серверу причину не увидеть.
     *
     * @return array<int, array{name: string, url: string, ok: bool, detail: string}>
     */
    public function check_sources() {
        $out = [];

        // Реестры Минюста отвечают на POST, обычный GET там ничего не скажет
        $api = [
            'Иностранные агенты'        => self::INOAGENT_GRID,
            'Нежелательные организации' => self::UNDESIRABLE_GRID,
        ];
        foreach ($api as $name => $grid) {
            $url      = 'https://reestrs.minjust.gov.ru/rest/registry/' . $grid . '/values';
            $response = LEM_Http::post($url, [
                'timeout'   => 20,
                'sslverify' => false,
                'headers'   => ['Content-Type' => 'application/json'],
                'body'      => wp_json_encode(['offset' => 0, 'limit' => 1, 'search' => '']),
            ]);

            if (is_wp_error($response)) {
                $out[] = ['name' => $name, 'url' => $url, 'ok' => false,
                          'detail' => 'соединение не удалось: ' . $response->get_error_message()];
                continue;
            }
            $code = wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $ok   = $code === 200 && isset($data['values']);

            // Поле с общим числом записей у реестров называется по-разному,
            // а в ответе на пробный запрос его может не быть вовсе
            $total = null;
            foreach (['count', 'total', 'totalCount', 'recordsTotal'] as $key) {
                if (isset($data[$key]) && (int) $data[$key] > 0) {
                    $total = (int) $data[$key];
                    break;
                }
            }

            $out[] = [
                'name' => $name, 'url' => $url, 'ok' => $ok,
                'detail' => $ok
                    ? ('отвечает' . ($total !== null ? ', записей в реестре: ' . $total : ''))
                    : "HTTP $code, ответ не похож на реестр",
            ];
        }

        // Страницы, которые разбираются как HTML
        $pages = [
            'Экстремистские организации'   => self::EXTREMIST_URL,
            'Террористические организации' => self::TERROR_URL,
        ];
        foreach ($pages as $name => $url) {
            $result = $this->fetch_url($url, 30);
            if (isset($result['error'])) {
                $out[] = ['name' => $name, 'url' => $url, 'ok' => false,
                          'detail' => 'не открылась: ' . $result['error']];
                continue;
            }
            $parsed = $name === 'Экстремистские организации'
                ? $this->parse_minjust_list($result['body'])
                : $this->parse_generic_list($result['body']);
            $out[] = [
                'name' => $name, 'url' => $url, 'ok' => !empty($parsed),
                'detail' => 'HTTP 200, получено ' . strlen($result['body']) . ' байт, '
                    . 'разобрано записей: ' . count($parsed),
            ];
        }

        // Запасные источники: без них не понять, почему сайт ушёл на встроенный перечень
        $channel = lem()->channel->health();
        $out[]   = [
            'name'   => 'Канал реестров',
            'url'    => lem()->channel->base_url() . 'health',
            'ok'     => !empty($channel['ok']),
            'detail' => !empty($channel['ok'])
                ? 'отвечает, срез: ' . $channel['last_snapshot']
                : 'не отвечает: ' . $channel['error'],
        ];

        $mirror_names = [
            'extremist' => 'Зеркало экстремистского перечня',
            'terrorist' => 'Зеркало террористического перечня',
        ];
        foreach (self::MIRRORS as $type => $url) {
            $mirror = $this->fetch_url($url, 30);
            $data   = isset($mirror['body']) ? json_decode($mirror['body'], true) : null;
            $out[]  = [
                'name' => $mirror_names[$type],
                'url'  => $url,
                'ok'   => is_array($data) && !empty($data),
                'detail' => isset($mirror['error'])
                    ? 'не открылось: ' . $mirror['error']
                    : 'записей: ' . (is_array($data) ? count($data) : 0),
            ];
        }

        return $out;
    }

    public function fetch_url($url, $timeout = 30) {
        $response = LEM_Http::get($url, [
            'timeout'    => $timeout,
            'user-agent' => 'Mozilla/5.0 (compatible; LegalEntityMarksBot/1.0)',
            'sslverify'  => false,
        ]);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['error' => "HTTP $code"];
        }
        return ['body' => wp_remote_retrieve_body($response)];
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

    private function parse_minjust_list($html) {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath   = new DOMXPath($doc);
        // Минюст менял структуру: ol/li → div.document p → div.doc p → #documentcontent p
        $nodes   = $xpath->query('//div[@class="doc"]//p | //div[@id="documentcontent"]//p | //ol/li | //div[contains(@class,"document")]//p');
        $entries = [];

        foreach ($nodes as $node) {
            // Нормализуем пробелы (в т.ч. &nbsp;), иначе появляются дубли сущностей
            $text = trim(preg_replace('/[\x{00A0}\s]+/u', ' ', $node->textContent));
            $text = preg_replace('/^\d+[\.\)]\s*/', '', $text);
            if (mb_strlen($text) < 5) {
                continue;
            }
            $name = preg_split('/\s*[-–—]\s*(?:решени|на основании|Верх|Реш)/ui', $text)[0];
            $name = preg_replace('/\s*\(.*$/', '', $name);
            $name = trim($name);
            // Канцелярские абзацы страницы (тексты решений) - не названия организаций
            if (preg_match('/^(Решение|Приговор|Определение|Постановление)\b/u', $name)) {
                continue;
            }
            if (mb_strlen($name) >= 5) {
                $entries[] = ['name' => $name, 'aliases' => [], 'is_person' => false];
            }
        }
        return $entries;
    }

    private function parse_generic_list($html) {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath   = new DOMXPath($doc);
        // FSB таблица: td[1]=№, td[2]=название, td[3]=суд. Берём td[2].
        $nodes   = $xpath->query('//ol/li | //table//tr/td[2] | //div[contains(@class,"content")]//p');
        $entries = [];

        foreach ($nodes as $node) {
            // Нормализуем пробелы (в т.ч. &nbsp;), иначе появляются дубли сущностей
            $text = trim(preg_replace('/[\x{00A0}\s]+/u', ' ', $node->textContent));
            $text = preg_replace('/^\d+[\.\)]\s*/', '', $text);
            if (mb_strlen($text) < 5) {
                continue;
            }
            // Пропускаем заголовок таблицы
            if (preg_match('/^Наименование\s+организации/ui', $text)) {
                continue;
            }
            $name = preg_split('/\s*[-–—]\s*(?:решени|призна|Верх|на основании)/ui', $text)[0];
            $name = preg_replace('/\s*\((?:решени|призна).*$/ui', '', $name);
            $name = trim($name);
            if (mb_strlen($name) >= 5 && mb_strlen($name) < 300) {
                $entries[] = ['name' => $name, 'aliases' => [], 'is_person' => false];
            }
        }
        return $entries;
    }
}
