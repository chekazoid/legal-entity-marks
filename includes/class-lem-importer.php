<?php
defined('ABSPATH') || exit;

class LEM_Importer {

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
            // import_json понимает оба формата: name/dateIn/dateOut и fullName/dob (ФЗ-255)
            $results[$type] = $this->import_json($file, $type);
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
        if ($file === null) {
            $file = LEM_DATA_DIR . '/brand-aliases.json';
        }
        if (!file_exists($file)) {
            return ['applied' => 0];
        }
        $map = json_decode(file_get_contents($file), true);
        if (!is_array($map)) {
            return ['applied' => 0, 'error' => 'Invalid JSON'];
        }

        global $wpdb;
        $table   = $wpdb->prefix . LEM_TABLE;
        $applied = 0;

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
                "SELECT id, aliases FROM $table WHERE name LIKE %s",
                '%' . $wpdb->esc_like($match) . '%'
            ));

            foreach ($rows as $row) {
                $old    = json_decode($row->aliases, true) ?: [];
                $merged = array_values(array_unique(array_merge($old, $aliases)));
                if ($merged !== $old) {
                    $wpdb->update(
                        $table,
                        ['aliases' => wp_json_encode($merged, JSON_UNESCAPED_UNICODE)],
                        ['id' => $row->id]
                    );
                    $applied++;
                }
            }
        }

        lem()->entities->flush_cache();
        return ['applied' => $applied];
    }

    public function import_json($file, $type_override = null) {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;

        $json = file_get_contents($file);
        if ($json === false) {
            return ['error' => "Cannot read file: $file"];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['error' => "Invalid JSON in: $file"];
        }

        $added = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($data as $entry) {
            $name = trim($entry['name'] ?? $entry['fullName'] ?? '');
            if (empty($name)) {
                $skipped++;
                continue;
            }

            $type      = $type_override ?: ($entry['type'] ?? 'inoagent');
            $is_person = (int) ($entry['is_person'] ?? (!empty($entry['dob']) ? 1 : 0));
            $aliases   = $entry['aliases'] ?? [];
            if (!is_array($aliases)) {
                $aliases = [];
            }

            $date_in = $this->parse_date($entry['date_included'] ?? $entry['dateIn'] ?? null);
            $date_out = $this->parse_date($entry['date_excluded'] ?? $entry['dateOut'] ?? null);
            $is_active = empty($date_out) ? 1 : 0;

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, aliases FROM $table WHERE name = %s AND type = %s LIMIT 1",
                $name, $type
            ));

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
                ]);
                $added++;
            }
        }

        lem()->entities->flush_cache();
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($data)];
    }

    public function import_fz255($file) {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;

        $json = file_get_contents($file);
        if ($json === false) {
            return ['error' => "Cannot read: $file"];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['error' => 'Invalid JSON'];
        }

        $added = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($data as $entry) {
            $name = trim($entry['fullName'] ?? '');
            if (empty($name)) {
                $skipped++;
                continue;
            }

            if (preg_match('/^[«"]/u', $name)) {
                $name = trim(preg_replace('/^[«"]+|[»"]+$/u', '', $name));
            }

            $is_person = !empty($entry['dob']) ? 1 : 0;
            $date_in   = $this->parse_date($entry['dateIn'] ?? null);
            $date_out  = $this->parse_date($entry['dateOut'] ?? null);
            $is_active = empty($date_out) ? 1 : 0;

            $aliases = [];
            if ($is_person) {
                $parts = preg_split('/\s+/', $name);
                if (count($parts) >= 2) {
                    $aliases[] = $parts[0] . ' ' . $parts[1];
                }
            }

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, aliases FROM $table WHERE name = %s AND type = 'inoagent' LIMIT 1",
                $name
            ));

            if ($existing) {
                $old_aliases = json_decode($existing->aliases, true) ?: [];
                $aliases     = array_values(array_unique(array_merge($old_aliases, $aliases)));
                $wpdb->update($table, [
                    'aliases'       => wp_json_encode($aliases, JSON_UNESCAPED_UNICODE),
                    'is_person'     => $is_person,
                    'date_included' => $date_in,
                    'date_excluded' => $date_out,
                    'is_active'     => $is_active,
                ], ['id' => $existing->id]);
                $updated++;
            } else {
                $wpdb->insert($table, [
                    'type'          => 'inoagent',
                    'name'          => $name,
                    'aliases'       => wp_json_encode($aliases, JSON_UNESCAPED_UNICODE),
                    'is_person'     => $is_person,
                    'date_included' => $date_in,
                    'date_excluded' => $date_out,
                    'is_active'     => $is_active,
                ]);
                $added++;
            }
        }

        lem()->entities->flush_cache();
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($data)];
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

        $added   = 0;
        $skipped = 0;

        foreach ($data as $entry) {
            $domain = LEM_Banned_Sites::normalize_domain($entry['domain'] ?? '');
            if (empty($domain)) {
                $skipped++;
                continue;
            }

            $result = lem()->banned_sites->insert([
                'domain' => $domain,
                'label'  => $entry['label'] ?? '',
            ]);

            if ($result) {
                $added++;
            } else {
                $skipped++;
            }
        }

        lem()->banned_sites->flush_cache();
        return ['added' => $added, 'skipped' => $skipped];
    }

    /* ------------------------------------------------------------------
     * Registry fetchers (online sources)
     * ------------------------------------------------------------------ */

    public function fetch_all($log_callback = null) {
        $log    = $log_callback ?: function ($msg) {};
        $errors = [];

        $sources = [
            'inoagent'    => 'fetch_inoagents',
            'extremist'   => 'fetch_extremist_orgs',
            'terrorist'   => 'fetch_terrorist_orgs',
            'undesirable' => 'fetch_undesirable_orgs',
        ];

        foreach ($sources as $type => $method) {
            $log("Fetching: $type...");
            $result = $this->$method();
            if (isset($result['error'])) {
                $log("  ERROR: {$result['error']}. Trying local fallback...");
                $errors[] = "$type: {$result['error']}";
                $fallback_files = [
                    'inoagent'    => 'foreign-agents-raw.json',
                    'extremist'   => 'extremist-orgs.json',
                    'terrorist'   => 'terrorist-orgs.json',
                    'undesirable' => 'undesirable-orgs.json',
                ];
                $fallback = LEM_DATA_DIR . '/' . $fallback_files[$type];
                if (file_exists($fallback)) {
                    $fb_result = $this->import_json($fallback, $type);
                    $log("  Fallback: added={$fb_result['added']}, updated={$fb_result['updated']}");
                } else {
                    $log("  No fallback file found: $fallback");
                }
            } else {
                $log("  OK: added={$result['added']}, updated={$result['updated']}, total={$result['total']}");
            }
        }

        // Обновление запрещённых доменов из встроенного JSON
        $log('Обновление реестра запрещённых доменов...');
        $bs = $this->import_banned_sites();
        $log("  Добавлено={$bs['added']}, пропущено={$bs['skipped']}");

        // Брендовые алиасы поверх свежих официальных названий
        $ba = $this->apply_brand_aliases();
        $log("  Брендовых алиасов применено: {$ba['applied']}");

        update_option('lem_list_version', gmdate('Y-m-d H:i:s'));
        update_option('lem_last_fetch_time', current_time('mysql'));
        if (!empty($errors)) {
            update_option('lem_last_fetch_error', implode('; ', $errors));
        } else {
            delete_option('lem_last_fetch_error');
        }

        lem()->entities->flush_cache();
        return $errors;
    }

    /**
     * Иноагенты: REST API reestrs.minjust.gov.ru.
     * Fallback: GitHub fz255/foreign-agents (не обновляется с октября 2024).
     */
    public function fetch_inoagents() {
        $api_url = 'https://reestrs.minjust.gov.ru/rest/registry/39b95df9-9a68-6b6d-e1e3-e6388507067e/values';
        $entries = [];
        $offset  = 0;
        $limit   = 500;

        while (true) {
            $response = wp_remote_post($api_url, [
                'timeout'   => 30,
                'sslverify' => false,
                'headers'   => ['Content-Type' => 'application/json'],
                'body'      => wp_json_encode(['offset' => $offset, 'limit' => $limit, 'search' => '']),
            ]);
            if (is_wp_error($response)) {
                break;
            }
            if (wp_remote_retrieve_response_code($response) !== 200) {
                break;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!$data || !isset($data['values'])) {
                break;
            }

            foreach ($data['values'] as $item) {
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

                if (!empty($item['field_4_s'])) {
                    $entry['dateIn'] = $item['field_4_s'];
                }
                if (!empty($item['field_5_s'])) {
                    $entry['date_excluded'] = $item['field_5_s'];
                }

                $entries[] = $entry;
            }

            if (count($data['values']) < $limit || $offset + $limit >= ($data['size'] ?? 0)) {
                break;
            }
            $offset += $limit;
        }

        if (!empty($entries)) {
            $file = LEM_DATA_DIR . '/foreign-agents-fetched.json';
            file_put_contents($file, wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return $this->import_json($file, 'inoagent');
        }

        // Fallback: GitHub fz255/foreign-agents (не обновляется с октября 2024)
        return $this->fetch_inoagents_github();
    }

    private function fetch_inoagents_github() {
        $url    = 'https://raw.githubusercontent.com/fz255/foreign-agents/main/registry.json';
        $result = $this->fetch_url($url);
        if (isset($result['error'])) {
            return $result;
        }
        // Отдельный файл, чтобы не перезаписывать встроенные данные другим форматом
        $file = LEM_DATA_DIR . '/foreign-agents-github.json';
        file_put_contents($file, $result['body']);
        return $this->import_fz255($file);
    }

    public function fetch_extremist_orgs() {
        $url    = 'https://minjust.gov.ru/ru/documents/7822/';
        $result = $this->fetch_url($url);
        if (isset($result['error'])) {
            return $result;
        }

        $entries = $this->parse_minjust_list($result['body']);
        if (empty($entries)) {
            return ['error' => 'Failed to parse extremist orgs page, 0 entries found'];
        }

        foreach ($entries as &$e) {
            $e['type'] = 'extremist';
        }

        $file = LEM_DATA_DIR . '/extremist-orgs-fetched.json';
        file_put_contents($file, wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $this->import_json($file, 'extremist');
    }

    public function fetch_terrorist_orgs() {
        $url    = 'http://www.fsb.ru/fsb/npd/terror.htm';
        $result = $this->fetch_url($url, 45);
        if (isset($result['error'])) {
            $url    = 'https://nac.gov.ru/main/list/terroristExtremistOrganizations';
            $result = $this->fetch_url($url, 45);
        }
        if (isset($result['error'])) {
            return $result;
        }

        $entries = $this->parse_generic_list($result['body']);
        if (empty($entries)) {
            return ['error' => 'Failed to parse terrorist orgs page, 0 entries found'];
        }

        foreach ($entries as &$e) {
            $e['type'] = 'terrorist';
        }

        $file = LEM_DATA_DIR . '/terrorist-orgs-fetched.json';
        file_put_contents($file, wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $this->import_json($file, 'terrorist');
    }

    /**
     * Нежелательные организации: REST API reestrs.minjust.gov.ru.
     * Старый URL minjust.gov.ru/ru/documents/7756/ → 404 с февраля 2026.
     */
    public function fetch_undesirable_orgs() {
        $api_url = 'https://reestrs.minjust.gov.ru/rest/registry/c2d1692e-a9f6-5a79-13ee-5da5b42980df/values';
        $entries = [];
        $offset  = 0;
        $limit   = 500;

        while (true) {
            $response = wp_remote_post($api_url, [
                'timeout'  => 30,
                'sslverify' => false,
                'headers'  => ['Content-Type' => 'application/json'],
                'body'     => wp_json_encode(['offset' => $offset, 'limit' => $limit, 'search' => '']),
            ]);
            if (is_wp_error($response)) {
                break;
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                break;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!$data || !isset($data['values'])) {
                break;
            }

            foreach ($data['values'] as $item) {
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

            if (count($data['values']) < $limit || $offset + $limit >= ($data['size'] ?? 0)) {
                break;
            }
            $offset += $limit;
        }

        // Fallback: старый HTML-парсинг
        if (empty($entries)) {
            return $this->fetch_undesirable_orgs_html();
        }

        $file = LEM_DATA_DIR . '/undesirable-orgs-fetched.json';
        file_put_contents($file, wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $this->import_json($file, 'undesirable');
    }

    private function fetch_undesirable_orgs_html() {
        $url    = 'https://minjust.gov.ru/ru/documents/7756/';
        $result = $this->fetch_url($url, 30);
        if (isset($result['error'])) {
            return $result;
        }

        $entries = [];
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $result['body']);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//div[@class="doc"]//p | //div[@id="documentcontent"]//p | //table//tr/td[2] | //ol/li | //div[contains(@class,"document")]//p');

        foreach ($nodes as $node) {
            $text = trim(preg_replace('/[\x{00A0}\s]+/u', ' ', $node->textContent));
            $text = preg_replace('/^\d+[\.\)]\s*/', '', $text);
            if (mb_strlen($text) < 5 || mb_strlen($text) > 500) {
                continue;
            }
            $entries[] = [
                'name'      => trim($text),
                'type'      => 'undesirable',
                'aliases'   => [],
                'is_person' => false,
            ];
        }

        if (empty($entries)) {
            return ['error' => 'Failed to parse undesirable orgs page'];
        }

        $file = LEM_DATA_DIR . '/undesirable-orgs-fetched.json';
        file_put_contents($file, wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $this->import_json($file, 'undesirable');
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public function fetch_url($url, $timeout = 30) {
        $response = wp_remote_get($url, [
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
