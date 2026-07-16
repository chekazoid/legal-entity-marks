<?php
defined('ABSPATH') || exit;

class LEM_Banned_Sites {

    const TRANSIENT_KEY = 'lem_banned_sites_all';
    const TRANSIENT_TTL = HOUR_IN_SECONDS;

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'lem_banned_sites';
    }

    /**
     * Все запрещённые домены (плоский массив строк). Кеш через transient.
     */
    public function get_all_domains() {
        $domains = get_transient(self::TRANSIENT_KEY);
        if ($domains !== false) {
            return $domains;
        }

        global $wpdb;
        $domains = $wpdb->get_col("SELECT domain FROM {$this->table()} ORDER BY domain");
        set_transient(self::TRANSIENT_KEY, $domains, self::TRANSIENT_TTL);
        return $domains;
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
        $domain = self::normalize_domain($data['domain'] ?? '');
        if (empty($domain)) {
            return false;
        }

        // Проверяем дубликат перед вставкой (UNIQUE KEY на domain)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE domain = %s LIMIT 1", $domain
        ));
        if ($existing) {
            return false;
        }

        $result = $wpdb->insert($this->table(), [
            'domain'    => $domain,
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
            $update['domain'] = self::normalize_domain($data['domain']);
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
            $where[]  = '(domain LIKE %s OR label LIKE %s)';
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
}
