<?php
defined('ABSPATH') || exit;

class LEM_Entities {

    const TRANSIENT_KEY = 'lem_entities_active';
    const TRANSIENT_TTL = HOUR_IN_SECONDS;

    public function get_all_active() {
        return $this->get_for_marking(false);
    }

    /**
     * Сущности для сканирования и маркировки.
     *
     * @param bool $include_excluded Включать исключённые из реестра (is_active=0).
     *                               Нужно для опции «маркировать бывших».
     */
    public function get_for_marking($include_excluded = false) {
        $key = self::TRANSIENT_KEY . ($include_excluded ? '_all' : '');
        $entities = get_transient($key);
        if ($entities !== false) {
            return $entities;
        }

        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        $where = $include_excluded ? '1=1' : 'is_active = 1';
        $rows  = $wpdb->get_results(
            "SELECT id, type, name, aliases, is_person, status_text, is_active, date_excluded
             FROM $table WHERE $where",
            ARRAY_A
        );

        $entities = [];
        foreach ($rows as $row) {
            $row['aliases']   = json_decode($row['aliases'], true) ?: [];
            $row['is_person'] = (int) $row['is_person'];
            $row['is_active'] = (int) $row['is_active'];
            $entities[] = $row;
        }

        set_transient($key, $entities, self::TRANSIENT_TTL);
        return $entities;
    }

    public function flush_cache() {
        delete_transient(self::TRANSIENT_KEY);
        delete_transient(self::TRANSIENT_KEY . '_all');
    }

    public function get_by_id($id) {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
        if ($row) {
            $row['aliases'] = json_decode($row['aliases'], true) ?: [];
        }
        return $row;
    }

    public function insert($data) {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;

        $result = $wpdb->insert($table, [
            'type'          => $data['type'],
            'name'          => $data['name'],
            'aliases'       => wp_json_encode($data['aliases'] ?? [], JSON_UNESCAPED_UNICODE),
            'is_person'     => (int) ($data['is_person'] ?? 0),
            'status_text'   => $data['status_text'] ?? '',
            'source_url'    => $data['source_url'] ?? '',
            'date_included' => $data['date_included'] ?? null,
            'date_excluded' => $data['date_excluded'] ?? null,
            'is_active'     => (int) ($data['is_active'] ?? 1),
        ]);

        if ($result) {
            $this->flush_cache();
        }
        return $result ? $wpdb->insert_id : false;
    }

    public function update($id, $data) {
        global $wpdb;
        $table  = $wpdb->prefix . LEM_TABLE;
        $update = [];

        foreach (['type', 'name', 'is_person', 'status_text', 'source_url', 'date_included', 'date_excluded', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (array_key_exists('aliases', $data)) {
            $update['aliases'] = wp_json_encode($data['aliases'], JSON_UNESCAPED_UNICODE);
        }

        $result = $wpdb->update($table, $update, ['id' => $id]);
        if ($result !== false) {
            $this->flush_cache();
        }
        return $result;
    }

    public function delete($id) {
        global $wpdb;
        $table  = $wpdb->prefix . LEM_TABLE;
        $result = $wpdb->delete($table, ['id' => $id]);
        if ($result) {
            $this->flush_cache();
        }
        return $result;
    }

    public function count_by_type() {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        $rows  = $wpdb->get_results(
            "SELECT type, is_active, COUNT(*) as cnt FROM $table GROUP BY type, is_active ORDER BY type",
            ARRAY_A
        );
        $result = [];
        foreach ($rows as $row) {
            $key = $row['type'] . ($row['is_active'] ? '' : '_removed');
            $result[$key] = (int) $row['cnt'];
        }
        return $result;
    }

    public function search($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;

        $where  = [];
        $params = [];

        if (!empty($args['type'])) {
            $where[]  = 'type = %s';
            $params[] = $args['type'];
        }
        if (isset($args['is_active'])) {
            $where[]  = 'is_active = %d';
            $params[] = (int) $args['is_active'];
        }
        if (!empty($args['search'])) {
            $where[]  = '(name LIKE %s OR aliases LIKE %s)';
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit     = (int) ($args['limit'] ?? 50);
        $offset    = (int) ($args['offset'] ?? 0);
        $orderby   = in_array($args['orderby'] ?? '', ['name', 'type', 'date_included', 'id']) ? $args['orderby'] : 'id';
        $order     = strtoupper($args['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $sql = "SELECT * FROM $table $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        foreach ($rows as &$row) {
            $row['aliases'] = json_decode($row['aliases'], true) ?: [];
        }

        $count_sql = "SELECT COUNT(*) FROM $table $where_sql";
        if (!empty($params)) {
            $count_params = array_slice($params, 0, -2);
            $total = !empty($count_params)
                ? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$count_params))
                : (int) $wpdb->get_var($count_sql);
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }

        return ['items' => $rows, 'total' => $total];
    }
}
