<?php
defined('ABSPATH') || exit;

class LEM_Banned_Sites {

    const TRANSIENT_KEY  = 'lem_banned_sites_all';
    const ACCOUNTS_KEY   = 'lem_banned_sites_accounts';
    const TRANSIENT_TTL  = HOUR_IN_SECONDS;

    /**
     * Площадки, где первый сегмент пути это имя аккаунта.
     * Значение - служебные сегменты перед именем: youtube.com/channel/UC..., t.me/s/name.
     *
     * Домен такой площадки в реестр не попадает: запретить t.me целиком значит
     * пометить любую ссылку на телеграм. Запрещается конкретный аккаунт.
     */
    const HANDLE_HOSTS = [
        't.me'          => ['s'],
        'telegram.me'   => ['s'],
        'vk.com'        => [],
        'ok.ru'         => ['group', 'profile'],
        'facebook.com'  => ['pages', 'groups', 'people'],
        'fb.com'        => ['pages', 'groups'],
        'instagram.com' => [],
        'threads.net'   => [],
        'threads.com'   => [],
        'twitter.com'   => [],
        'x.com'         => [],
        'youtube.com'   => ['c', 'channel', 'user'],
        'tiktok.com'    => [],
        'rutube.ru'     => ['channel'],
        'dzen.ru'       => [],
        'boosty.to'     => [],
        'patreon.com'   => [],
        'soundcloud.com'=> [],
        'medium.com'    => [],
        'github.com'    => [],
        'linkedin.com'  => ['company', 'in', 'school'],
    ];

    /** Сегменты пути, которые именем аккаунта не бывают. */
    const NOT_HANDLES = [
        'watch', 'playlist', 'shorts', 'embed', 'results', 'search', 'reel', 'reels',
        'video', 'videos', 'story', 'stories', 'hashtag', 'explore', 'tag', 'topic',
        'home', 'about', 'login', 'help', 'terms', 'privacy', 'share', 'post', 'posts',
        'media', 'feed', 'wall', 'photo', 'album', 'event', 'live', 'joinchat',
    ];

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'lem_banned_sites';
    }

    /**
     * Домены, запрещённые целиком (плоский массив строк). Кеш через transient.
     * Записи с аккаунтом сюда не попадают, для них есть get_accounts().
     */
    public function get_all_domains() {
        $domains = get_transient(self::TRANSIENT_KEY);
        if ($domains !== false) {
            return $domains;
        }

        global $wpdb;
        $domains = $wpdb->get_col(
            "SELECT domain FROM {$this->table()} WHERE account = '' ORDER BY domain"
        );
        set_transient(self::TRANSIENT_KEY, $domains, self::TRANSIENT_TTL);
        return $domains;
    }

    /**
     * Запрещённые аккаунты на чужих площадках.
     *
     * @return array<int, array{domain: string, account: string}>
     */
    public function get_accounts() {
        $rows = get_transient(self::ACCOUNTS_KEY);
        if ($rows !== false) {
            return $rows;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT domain, account FROM {$this->table()} WHERE account != '' ORDER BY domain",
            ARRAY_A
        );
        set_transient(self::ACCOUNTS_KEY, $rows, self::TRANSIENT_TTL);
        return $rows;
    }

    /**
     * Все записи (полные строки).
     */
    public function get_all() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY domain", ARRAY_A);
    }

    public function get_by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d", $id
        ), ARRAY_A);
    }

    public function insert($data) {
        global $wpdb;
        // Ссылку вида t.me/doxajournal разбираем на площадку и аккаунт: иначе
        // в реестр попал бы весь телеграм
        $target  = self::split_target($data['domain'] ?? '');
        $domain  = $target['domain'];
        $account = isset($data['account']) ? mb_strtolower(trim($data['account'])) : $target['account'];
        if (empty($domain)) {
            return false;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE domain = %s AND account = %s LIMIT 1",
            $domain, $account
        ));
        if ($existing) {
            return false;
        }

        $result = $wpdb->insert($this->table(), [
            'domain'    => $domain,
            'account'   => $account,
            'label'     => $data['label'] ?? '',
            'entity_id' => !empty($data['entity_id']) ? (int) $data['entity_id'] : null,
        ]);

        if ($result) {
            $this->flush_cache();
            return $wpdb->insert_id;
        }
        return false;
    }

    public function update($id, $data) {
        global $wpdb;
        $update = [];

        if (isset($data['domain'])) {
            $target             = self::split_target($data['domain']);
            $update['domain']   = $target['domain'];
            $update['account']  = $target['account'];
        }
        if (isset($data['account'])) {
            $update['account'] = mb_strtolower(trim($data['account']));
        }
        if (array_key_exists('label', $data)) {
            $update['label'] = $data['label'];
        }
        if (array_key_exists('entity_id', $data)) {
            $update['entity_id'] = !empty($data['entity_id']) ? (int) $data['entity_id'] : null;
        }

        $result = $wpdb->update($this->table(), $update, ['id' => $id]);
        if ($result !== false) {
            $this->flush_cache();
        }
        return $result;
    }

    public function delete($id) {
        global $wpdb;
        $result = $wpdb->delete($this->table(), ['id' => $id]);
        if ($result) {
            $this->flush_cache();
        }
        return $result;
    }

    public function count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
    }

    /**
     * Поиск с пагинацией для админки.
     */
    public function search($args = []) {
        global $wpdb;
        $table = $this->table();

        $where  = [];
        $params = [];

        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[]  = '(domain LIKE %s OR account LIKE %s OR label LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit     = (int) ($args['limit'] ?? 50);
        $offset    = (int) ($args['offset'] ?? 0);

        $sql = "SELECT * FROM $table $where_sql ORDER BY domain ASC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        $count_sql = "SELECT COUNT(*) FROM $table $where_sql";
        if (!empty($where)) {
            $count_params = array_slice($params, 0, -2);
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$count_params));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }

        return ['items' => $rows, 'total' => $total];
    }

    public function flush_cache() {
        delete_transient(self::TRANSIENT_KEY);
        delete_transient(self::ACCOUNTS_KEY);
    }

    /**
     * Нормализация домена: убирает протокол, www., приводит к lowercase.
     * "https://www.Example.ORG/path" → "example.org"
     */
    public static function normalize_domain($input) {
        $input = trim($input);
        if (empty($input)) {
            return '';
        }
        if (preg_match('#^https?://#i', $input)) {
            $parsed = parse_url($input);
            $input  = $parsed['host'] ?? $input;
        }
        $input = preg_replace('/^www\./i', '', $input);
        return mb_strtolower(trim($input, '/.'));
    }

    /**
     * Проверяет, совпадает ли хост URL с запрещённым доменом.
     * Поддерживает поддомены: banned "example.org" → совпадает "sub.example.org".
     * НЕ совпадает "myexample.org".
     *
     * @return string|null Совпавший домен или null.
     */
    public static function is_domain_banned($url_host, $banned_domains) {
        $host = mb_strtolower(preg_replace('/^www\./i', '', $url_host));
        foreach ($banned_domains as $banned) {
            if ($host === $banned || str_ends_with($host, '.' . $banned)) {
                return $banned;
            }
        }
        return null;
    }

    /**
     * Разбирает то, что ввёл пользователь или отдал Минюст, на площадку и аккаунт.
     *
     * «https://t.me/doxajournal» -> t.me + doxajournal
     * «https://doxa.team/articles/1» -> doxa.team, запрещён весь домен
     *
     * @return array{domain: string, account: string}
     */
    public static function split_target($input) {
        $input = trim((string) $input);
        if ($input === '') {
            return ['domain' => '', 'account' => ''];
        }
        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://' . ltrim($input, '/');
        }

        $parsed = parse_url($input);
        $domain = self::normalize_domain($parsed['host'] ?? '');
        if ($domain === '' || strpos($domain, '.') === false) {
            return ['domain' => '', 'account' => ''];
        }

        return [
            'domain'  => $domain,
            'account' => self::extract_handle($domain, $parsed['path'] ?? ''),
        ];
    }

    /**
     * Имя аккаунта из пути ссылки. Для обычных сайтов возвращает пустую строку:
     * там путь это раздел, а не организация.
     */
    public static function extract_handle($host, $path) {
        $host = mb_strtolower(preg_replace('/^www\./i', '', (string) $host));

        $skip = null;
        foreach (self::HANDLE_HOSTS as $known => $service_segments) {
            if ($host === $known || str_ends_with($host, '.' . $known)) {
                $skip = $service_segments;
                break;
            }
        }
        if ($skip === null) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', (string) $path), 'strlen'));
        while (!empty($segments) && in_array(mb_strtolower($segments[0]), $skip, true)) {
            array_shift($segments);
        }
        if (empty($segments)) {
            return '';
        }

        $handle = mb_strtolower(trim(ltrim(rawurldecode($segments[0]), '@')));
        if (mb_strlen($handle) < 3 || in_array($handle, self::NOT_HANDLES, true)) {
            return '';
        }
        // Латиница с цифрами и разделителями: так выглядят имена аккаунтов
        // на всех перечисленных площадках, остальное это раздел сайта
        if (!preg_match('/^[a-z0-9._-]+$/', $handle)) {
            return '';
        }
        return $handle;
    }

    /**
     * Ссылка ведёт на запрещённый аккаунт?
     *
     * @return string|null «t.me/doxajournal» или null
     */
    public static function match_account($url_host, $url, $accounts) {
        if (empty($accounts)) {
            return null;
        }
        $host   = mb_strtolower(preg_replace('/^www\./i', '', $url_host));
        $handle = self::extract_handle($host, (string) parse_url($url, PHP_URL_PATH));
        if ($handle === '') {
            return null;
        }

        foreach ($accounts as $a) {
            $domain = $a['domain'] ?? '';
            if ($handle !== ($a['account'] ?? '')) {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $domain . '/' . $handle;
            }
        }
        return null;
    }
}
