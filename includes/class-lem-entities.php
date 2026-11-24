<?php
defined('ABSPATH') || exit;

class LEM_Entities {

    const TRANSIENT_KEY = 'lem_entities_active';
    const TRANSIENT_TTL = HOUR_IN_SECONDS;

    private static $request_cache = [];

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
        $people = in_array(lem()->get_settings()['fedsfm_people_mode'] ?? 'off', ['track', 'mark'], true);
        $key = self::TRANSIENT_KEY . ($include_excluded ? '_all' : '') . ($people ? '_people' : '');
        if (array_key_exists($key, self::$request_cache)) {
            return self::$request_cache[$key];
        }
        // Большой перечень не помещаем одной записью в wp_options. Для одного
        // прохода архива достаточно кеша в памяти текущего запроса.
        $entities = $people ? false : get_transient($key);
        if ($entities !== false) {
            self::$request_cache[$key] = $entities;
            return $entities;
        }

        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        $where = ($include_excluded ? '1=1' : 'is_active = 1')
            . ' AND (registry_present = 1 OR date_excluded IS NOT NULL OR is_manual = 1)';
        $where .= $people
            ? " AND (type <> 'fedsfm_person' OR is_active = 1)"
            : " AND type <> 'fedsfm_person'";
        /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Cached by the surrounding request/transient layer. */
        $rows  = $wpdb->get_results(
            "SELECT id, type, name, aliases, is_person, status_text, is_active,
                    date_included, date_excluded, first_seen, registry_present, is_manual
             FROM $table WHERE $where",
            ARRAY_A
        ) ;
        /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

        $entities = [];
        foreach ($rows as $row) {
            $row['aliases']   = json_decode($row['aliases'], true) ?: [];
            $row['is_person'] = (int) $row['is_person'];
            $row['is_active'] = (int) $row['is_active'];
            $entities[] = $row;
        }

        if (!$people) {
            set_transient($key, $entities, self::TRANSIENT_TTL);
        }
        self::$request_cache[$key] = $entities;
        return $entities;
    }

    /** Для вывода статьи нужны только найденные ID, а не весь большой перечень. */
    public function get_for_marking_ids(array $ids, $include_excluded = false) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $where = ($include_excluded ? '1=1' : 'is_active = 1')
            . ' AND (registry_present = 1 OR date_excluded IS NOT NULL OR is_manual = 1)';
        $people = in_array(lem()->get_settings()['fedsfm_people_mode'] ?? 'off', ['track', 'mark'], true);
        $where .= $people
            ? " AND (type <> 'fedsfm_person' OR is_active = 1)"
            : " AND type <> 'fedsfm_person'";
        /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, type, name, aliases, is_person, status_text, is_active,
                    date_included, date_excluded, first_seen, registry_present, is_manual
             FROM $table WHERE $where AND id IN ($in)", ...$ids
        ), ARRAY_A) ;
        /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare */
        foreach ($rows as &$row) {
            $row['aliases'] = json_decode($row['aliases'], true) ?: [];
            $row['is_person'] = (int) $row['is_person'];
            $row['is_active'] = (int) $row['is_active'];
        }
        unset($row);
        return $rows;
    }

    public function flush_cache() {
        self::$request_cache = [];
        delete_transient(self::TRANSIENT_KEY);
        delete_transient(self::TRANSIENT_KEY . '_all');
        delete_transient(self::TRANSIENT_KEY . '_people');
        delete_transient(self::TRANSIENT_KEY . '_all_people');
        if (isset(lem()->banned_sites)) { lem()->banned_sites->flush_cache(); }
    }

    public function get_by_id($id) {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        if ($row) {
            $row['aliases'] = json_decode($row['aliases'], true) ?: [];
        }
        return $row;
    }

    public function insert($data) {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. */
        $result = $wpdb->insert($table, [
            'type'          => $data['type'],
            'name'          => $data['name'],
            'aliases'       => wp_json_encode($data['aliases'] ?? [], JSON_UNESCAPED_UNICODE),
            'is_person'     => (int) ($data['is_person'] ?? 0),
            'status_text'   => $data['status_text'] ?? '',
            'source_url'    => $data['source_url'] ?? '',
            'is_manual'     => 1,
            'registry_present' => 1,
            'date_included' => $data['date_included'] ?? null,
            'date_excluded' => $data['date_excluded'] ?? null,
            'is_active'     => (int) ($data['is_active'] ?? 1),
        ]) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery */

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

        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
        $result = $wpdb->update($table, $update, ['id' => $id]) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
        if ($result !== false) {
            $this->flush_cache();
        }
        return $result;
    }

    public function delete($id) {
        global $wpdb;
        $table  = $wpdb->prefix . LEM_TABLE;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Writes invalidate the plugin caches; migration writes run before readers. */
        $result = $wpdb->delete($table, ['id' => $id]) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
        if ($result) {
            $this->flush_cache();
        }
        return $result;
    }

    public function count_by_type() {
        global $wpdb;
        $table = $wpdb->prefix . LEM_TABLE;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $rows  = $wpdb->get_results(
            "SELECT type, is_active, COUNT(*) as cnt FROM $table GROUP BY type, is_active ORDER BY type",
            ARRAY_A
        ) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
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

        $type = (string) ($args['type'] ?? '');
        $has_active = isset($args['is_active']);
        $active = (int) ($args['is_active'] ?? 0);
        $search = (string) ($args['search'] ?? '');
        $like = '%' . $wpdb->esc_like($search) . '%';
        $limit = max(1, (int) ($args['limit'] ?? 50));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        // SQL keywords are selected from this fixed allowlist, never from raw input.
        $orderby = in_array($args['orderby'] ?? '', ['name', 'type', 'date_included', 'id'], true)
            ? $args['orderby'] : 'id';
        $order = strtoupper($args['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        /* phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE (%s = '' OR type = %s)
               AND (%d = 0 OR is_active = %d)
               AND (%s = '' OR name LIKE %s OR aliases LIKE %s)
             ORDER BY $orderby $order LIMIT %d OFFSET %d",
            $type, $type, (int) $has_active, $active, $search, $like, $like, $limit, $offset
        ), ARRAY_A) ;
        /* phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
        foreach ($rows as &$row) {
            $row['aliases'] = json_decode($row['aliases'], true) ?: [];
        }
        unset($row);
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE (%s = '' OR type = %s)
               AND (%d = 0 OR is_active = %d)
               AND (%s = '' OR name LIKE %s OR aliases LIKE %s)",
            $type, $type, (int) $has_active, $active, $search, $like, $like
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

        return ['items' => $rows, 'total' => $total];
    }
}
