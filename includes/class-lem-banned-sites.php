<?php
defined('ABSPATH') || exit;

class LEM_Banned_Sites {

    const TRANSIENT_KEY  = 'lem_banned_sites_all';
    const ACCOUNTS_KEY   = 'lem_banned_sites_accounts';
    const INDEX_KEY      = 'lem_banned_sites_index';
    const TRANSIENT_TTL  = HOUR_IN_SECONDS;

    /**
     * Реестры, ссылки на ресурсы которых считаются запрещёнными по умолчанию.
     *
     * Иноагента сюда не включаем: ссылка на его сайт законом не запрещена,
     * её достаточно промаркировать. А ссылка на ресурс нежелательной
     * организации может толковаться как участие в её деятельности.
     */
    const REMOVABLE_TYPES = ['extremist', 'terrorist', 'undesirable'];

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

    /** Индекс ресурсов в пределах запроса. */
    private $index_cache = null;

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
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Cached in the surrounding transient. */
        $domains = $wpdb->get_col(
            "SELECT s.domain FROM {$this->table()} s LEFT JOIN {$wpdb->prefix}lem_entities e ON e.id = s.entity_id
             WHERE s.account = '' AND (s.entity_id IS NULL OR s.entity_id = 0 OR e.is_active = 1) ORDER BY s.domain"
        ) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
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
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Cached in the surrounding transient. */
        $rows = $wpdb->get_results(
            "SELECT s.domain, s.account FROM {$this->table()} s LEFT JOIN {$wpdb->prefix}lem_entities e ON e.id = s.entity_id
             WHERE s.account != '' AND (s.entity_id IS NULL OR s.entity_id = 0 OR e.is_active = 1) ORDER BY s.domain",
            ARRAY_A
        ) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        set_transient(self::ACCOUNTS_KEY, $rows, self::TRANSIENT_TTL);
        return $rows;
    }

    /**
     * Все записи (полные строки).
     */
    public function get_all() {
        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        return $wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY domain", ARRAY_A) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
    }

    public function get_by_id($id) {
        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d", $id
        ), ARRAY_A) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
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

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE domain = %s AND account = %s LIMIT 1",
            $domain, $account
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        if ($existing) {
            return false;
        }

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. */
        $result = $wpdb->insert($this->table(), [
            'domain'    => $domain,
            'account'   => $account,
            'label'     => $data['label'] ?? '',
            'registry'  => self::clean_registry($data['registry'] ?? ''),
            'entity_id' => !empty($data['entity_id']) ? (int) $data['entity_id'] : null,
        ]) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery */

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
        if (array_key_exists('registry', $data)) {
            $update['registry'] = self::clean_registry($data['registry']);
        }
        if (array_key_exists('entity_id', $data)) {
            $update['entity_id'] = !empty($data['entity_id']) ? (int) $data['entity_id'] : null;
        }

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
        $result = $wpdb->update($this->table(), $update, ['id' => $id]) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
        if ($result !== false) {
            $this->flush_cache();
        }
        return $result;
    }

    public function delete($id) {
        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
        $result = $wpdb->delete($this->table(), ['id' => $id]) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
        if ($result) {
            $this->flush_cache();
        }
        return $result;
    }

    public function count() {
        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}") ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
    }

    /**
     * Поиск с пагинацией для админки.
     */
    public function search($args = []) {
        global $wpdb;
        $table = $this->table();

        $search = (string) ($args['search'] ?? '');
        $like = '%' . $wpdb->esc_like($search) . '%';
        $limit = max(1, (int) ($args['limit'] ?? 50));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE (%s = '' OR domain LIKE %s OR account LIKE %s OR label LIKE %s)
             ORDER BY domain ASC LIMIT %d OFFSET %d",
            $search, $like, $like, $like, $limit, $offset
        ), ARRAY_A) ;
        /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE (%s = '' OR domain LIKE %s OR account LIKE %s OR label LIKE %s)",
            $search, $like, $like, $like
        )) ;
        /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

        return ['items' => $rows, 'total' => $total];
    }

    public function flush_cache() {
        delete_transient(self::TRANSIENT_KEY);
        delete_transient(self::ACCOUNTS_KEY);
        delete_transient(self::INDEX_KEY);
        $this->index_cache = null;
    }

    /**
     * Ресурс -> реестр его владельца.
     *
     * Ключ такой же, каким сканер помечает найденную ссылку: «example.org»
     * или «t.me/channel». Домены, добавленные руками, реестра не имеют
     * и считаются запрещёнными: их внесли осознанно.
     *
     * @return array<string, string> ключ => тип реестра ('' для ручных)
     */
    public function get_index() {
        if ($this->index_cache !== null) {
            return $this->index_cache;
        }
        $index = get_transient(self::INDEX_KEY);
        if ($index !== false) {
            $this->index_cache = $index;
            return $index;
        }

        global $wpdb;
        $ents  = $wpdb->prefix . LEM_TABLE;
        $index = [];
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        foreach ($wpdb->get_results(
            "SELECT s.domain, s.account, s.registry, e.type
             FROM {$this->table()} s LEFT JOIN $ents e ON e.id = s.entity_id
             WHERE s.entity_id IS NULL OR s.entity_id = 0 OR e.is_active = 1"
        ) as $row) {
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            $key = $row->account !== '' ? $row->domain . '/' . $row->account : $row->domain;
            // Комплектные домены реестра не связаны с записью, но свой тип знают
            $index[$key] = $row->type ?: ($row->registry ?: '');
        }

        set_transient(self::INDEX_KEY, $index, self::TRANSIENT_TTL);
        $this->index_cache = $index;
        return $index;
    }

    /** Только известные реестры, иначе пустая строка. */
    public static function clean_registry($type) {
        $type = sanitize_key((string) $type);
        return in_array($type, LEM_Plugin::REGISTRY_TYPES, true) ? $type : '';
    }

    /** Реестр ресурса по ключу, каким его пометил сканер. */
    public function type_of($key) {
        $index = $this->get_index();
        return $index[$key] ?? '';
    }

    /**
     * Ссылку на этот ресурс полагается убирать из текста?
     * Настройка позволяет расширить список, если сайт хочет строже.
     */
    public function is_removable($type) {
        $settings = lem()->get_settings();
        $allowed  = $settings['link_registries'] ?? self::REMOVABLE_TYPES;
        return $type === '' || in_array($type, $allowed, true);
    }

    /** Сводка реестра доменов по типам: для страницы «Ссылки». */
    public function count_by_type() {
        $out = [];
        foreach ($this->get_index() as $type) {
            $key = $type ?: 'manual';
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        return $out;
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
            $parsed = wp_parse_url($input);
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

        $parsed = wp_parse_url($input);
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
        $handle = self::extract_handle($host, (string) wp_parse_url($url, PHP_URL_PATH));
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
