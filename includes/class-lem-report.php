<?php
defined('ABSPATH') || exit;

/**
 * Сводка находок по всем материалам.
 *
 * Нужна тем, кому маркировка не требуется, а знать о факте упоминания надо:
 * ссылка на нежелательную организацию может быть истолкована как участие
 * в её деятельности, поэтому важно видеть список таких материалов.
 */
class CLEM_Report {

    /** Разобранная мета в пределах запроса: страница отчёта строит её дважды. */
    private $rows_cache  = null;
    private $links_cache = null;
    private $overrides   = [];
    private $entity_ids  = [];

    /**
     * Сколько материалов разбирать за раз. На больших архивах отчёт иначе
     * съедает память; предел можно поднять фильтром.
     */
    private function max_posts() {
        return (int) apply_filters('clem_report_max_posts', 5000);
    }

    /**
     * Строки отчёта.
     *
     * @param array $args registry (тип или ''), mode (all|marked|tracked),
     *                    search, limit, offset
     * @return array{items: array, total: int}
     */
    public function get_rows($args = []) {
        $settings = clem_plugin()->get_settings();
        $track    = $settings['track_registries'];

        $registry = $args['registry'] ?? '';
        $mode     = $args['mode'] ?? 'all';
        $search   = trim((string) ($args['search'] ?? ''));
        // Отсечка новизны: организация попала в реестр или упоминание найдено
        // не раньше этого момента. Ради этого вопроса отчёт и открывают
        $since      = trim((string) ($args['since'] ?? ''));
        $links_only = !empty($args['links_only']);
        $limit    = max(1, (int) ($args['limit'] ?? 100));
        $offset   = max(0, (int) ($args['offset'] ?? 0));

        $rows = $this->mention_rows();
        $entities = array_column(clem_plugin()->entities->get_for_marking_ids(array_keys($this->entity_ids), true), null, 'id');

        $links_by_post = $this->links_by_post();

        $items = [];
        $automatic = CLEM_Frontend::parse_overrides(null);
        foreach ($rows as $row) {
            $links = $links_by_post[$row->ID] ?? [];
            if ($links_only && !$links) {
                continue;
            }

            $overrides = isset($this->overrides[$row->ID])
                ? CLEM_Frontend::parse_overrides($this->overrides[$row->ID]->meta_value)
                : $automatic;

            foreach ($row->mentions as $match) {
                $eid = (int) ($match['id'] ?? 0);
                if (!isset($entities[$eid])) {
                    continue;
                }
                $entity = $entities[$eid];
                $type = $entity['type'];
                if (!in_array($type, $track, true) || ($registry !== '' && $type !== $registry)) {
                    continue;
                }
                $name = $entity['name'];
                if ($search !== ''
                    && mb_stripos($name, $search) === false
                    && mb_stripos($row->post_title, $search) === false) {
                    continue;
                }

                $match = array_merge($match, $entity);
                if (!CLEM_Scanner::is_current_mention($match, $settings)) {
                    continue;
                }

                $is_marked = CLEM_Frontend::should_mark($match, $settings, $overrides);

                if ($mode === 'marked' && !$is_marked) {
                    continue;
                }
                if ($mode === 'tracked' && $is_marked) {
                    continue;
                }

                $first_seen = $match['first_seen'] ?? '';
                $in_registry_since = $entity['first_seen'] ?? '';
                if ($since !== '' && !self::is_new($first_seen, $in_registry_since, $since)) {
                    continue;
                }

                $items[] = [
                    'post_id'    => (int) $row->ID,
                    'post_title' => $row->post_title,
                    'post_type'  => $row->post_type,
                    'status'     => $row->post_status,
                    'entity_id'  => $eid,
                    'name'       => $name,
                    'type'       => $type,
                    'matched_as' => $match['matched_as'] ?? '',
                    'marked'     => $is_marked,
                    'in_context' => $match['in_context'] ?? null,
                    'links'      => $links,
                    'active'     => (int) $entity['is_active'],
                    'first_seen'    => $first_seen,
                    'date_included' => $entity['date_included'] ?? '',
                    'is_new'        => $since !== '',
                ];
            }
        }

        $items = array_merge($items, $this->link_only_rows($items, $links_by_post, [
            'registry' => $registry,
            'mode'     => $mode,
            'search'   => $search,
            'track'    => $track,
            'since'    => $since,
        ]));

        $total = count($items);
        return ['items' => array_slice($items, $offset, $limit), 'total' => $total];
    }

    private function mention_rows() {
        if ($this->rows_cache !== null) {
            return $this->rows_cache;
        }

        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Report reads only its two private metadata keys; post IDs are integers from the first query. No whole-post metadata is loaded into the object cache. */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, p.post_status, pm.meta_value
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value != ''
               AND p.post_status IN ('publish','draft','pending','private','future')
             ORDER BY p.post_date DESC
             LIMIT %d",
            CLEM_META_KEY,
            $this->max_posts()
        ));

        $this->rows_cache = [];
        foreach ((array) $rows as $row) {
            $meta = json_decode($row->meta_value, true);
            if (empty($meta['entities']) || !is_array($meta['entities'])) {
                continue;
            }
            $row->mentions = $meta['entities'];
            unset($row->meta_value);
            $this->rows_cache[] = $row;
            foreach ($row->mentions as $mention) {
                $this->entity_ids[(int) ($mention['id'] ?? 0)] = true;
            }
        }

        $in = implode(',', array_map('intval', array_column($this->rows_cache, 'ID')));
        if ($in !== '') {
            // OBJECT_K keeps the first value, as a single-value metadata read does.
            $this->overrides = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND post_id IN ($in)
                 ORDER BY meta_id",
                CLEM_OVERRIDES_META_KEY
            ), OBJECT_K);
        }
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

        return $this->rows_cache;
    }

    /**
     * Материалы, где организация по имени не названа, но есть ссылка на её ресурс.
     *
     * Такие записи не попадали в отчёт вовсе: он строился по находкам сканера
     * текста. Между тем именно ссылка на ресурс нежелательной организации и есть
     * то, что важно найти, поэтому показываем их отдельными строками.
     */
    private function link_only_rows(array $items, array $links_by_post, array $args) {
        global $wpdb;

        if (empty($links_by_post) || $args['mode'] === 'marked') {
            return [];
        }

        $already = [];
        foreach ($items as $i) {
            $already[$i['post_id']] = true;
        }
        $ids = array_diff(array_keys($links_by_post), array_keys($already));
        if (empty($ids)) {
            return [];
        }

        $in    = implode(',', array_map('intval', $ids));
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts}
             WHERE ID IN ($in)
               AND post_status IN ('publish','draft','pending','private','future')
             ORDER BY post_date DESC"
        ) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */

        $out = [];
        foreach ($posts as $p) {
            // Одна строка на организацию, а не на каждую её ссылку
            $by_owner = [];
            foreach ($links_by_post[$p->ID] as $l) {
                $key = $l['org'] !== '' ? $l['org'] : $l['domain'];
                $by_owner[$key]['type']    = $l['type'];
                $by_owner[$key]['since']   = $l['owner_since'] ?? '';
                $by_owner[$key]['included'] = $l['date_included'] ?? '';
                $by_owner[$key]['links'][] = $l;
            }

            foreach ($by_owner as $org => $data) {
                $type = $data['type'];
                if ($args['since'] !== ''
                    && !self::is_new('', $data['since'], $args['since'])) {
                    continue;
                }
                // Ресурс без связки с реестром показываем всегда: домен добавили руками
                if ($type !== '' && !in_array($type, $args['track'], true)) {
                    continue;
                }
                if ($args['registry'] !== '' && $type !== $args['registry']) {
                    continue;
                }
                if ($args['search'] !== ''
                    && mb_stripos($org, $args['search']) === false
                    && mb_stripos($p->post_title, $args['search']) === false) {
                    continue;
                }

                $out[] = [
                    'post_id'    => (int) $p->ID,
                    'post_title' => $p->post_title,
                    'post_type'  => $p->post_type,
                    'status'     => $p->post_status,
                    'entity_id'  => 0,
                    'name'       => $org,
                    'type'       => $type,
                    'matched_as' => 'только ссылка',
                    'marked'     => false,
                    'in_context' => null,
                    'links'      => $data['links'],
                    'active'     => 1,
                    'first_seen'    => '',
                    'date_included' => $data['included'] ?? '',
                    'is_new'        => $args['since'] !== '',
                ];
            }
        }
        return $out;
    }

    /**
     * Запрещённые ссылки по материалам: post_id => [ [url, anchor, domain], ... ]
     */
    private function links_by_post() {
        if ($this->links_cache !== null) {
            return $this->links_cache;
        }

        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value != '' LIMIT %d",
            CLEM_BANNED_LINKS_META_KEY, $this->max_posts()
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */

        // Чей это ресурс: домены Минюст публикует вместе с записью реестра,
        // поэтому по ссылке видно организацию и её реестр
        $owners = [];
        $sites  = $wpdb->prefix . 'clem_banned_sites';
        $ents   = $wpdb->prefix . CLEM_TABLE;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from WordPress; values are parameterized, and SQL fragments are fixed/allowlisted or generated placeholders. Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        foreach ($wpdb->get_results(
            "SELECT s.domain, s.account, s.label, e.name, e.type, e.first_seen, e.date_included
             FROM $sites s LEFT JOIN $ents e ON e.id = s.entity_id
             WHERE s.entity_id IS NULL OR s.entity_id = 0 OR e.is_active = 1"
        ) as $s) {
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            $key = $s->account !== '' ? $s->domain . '/' . $s->account : $s->domain;
            if (CLEM_Banned_Sites::requires_account($key)) {
                continue;
            }
            $owners[$key] = [
                'org'           => $s->name ?: $s->label,
                'type'          => $s->type ?: '',
                'first_seen'    => $s->first_seen ?: '',
                'date_included' => $s->date_included ?: '',
            ];
        }

        $out = [];
        foreach ($rows as $r) {
            $meta = json_decode($r->meta_value, true);
            foreach (($meta['links'] ?? []) as $l) {
                $domain = $l['matched_domain'] ?? '';
                if (!isset($owners[$domain])) { continue; }
                $out[(int) $r->post_id][] = [
                    'url'           => $l['url'] ?? '',
                    'anchor'        => $l['anchor'] ?? '',
                    'domain'        => $domain,
                    'org'           => $owners[$domain]['org'] ?? '',
                    'type'          => $owners[$domain]['type'] ?? '',
                    'owner_since'   => $owners[$domain]['first_seen'] ?? '',
                    'date_included' => $owners[$domain]['date_included'] ?? '',
                ];
            }
        }

        $this->links_cache = $out;
        return $out;
    }

    /**
     * Новое ли это для сайта.
     *
     * Две разные новизны: организацию внесли в реестр после отсечки (упоминание
     * лежало годами и вдруг стало значимым) либо упоминание впервые нашлось
     * после отсечки (текст правили). Тревожит и то, и другое.
     */
    public static function is_new($match_first_seen, $entity_first_seen, $since) {
        foreach ([$match_first_seen, $entity_first_seen] as $stamp) {
            if (!empty($stamp) && strtotime($stamp) >= strtotime($since)) {
                return true;
            }
        }
        return false;
    }

    /** Сводка по реестрам для шапки отчёта. */
    public function summary() {
        $all = $this->get_rows(['limit' => 100000]);
        $out = [];
        foreach ($all['items'] as $i) {
            if ($i['type'] === '') {
                continue; // ссылка на домен, добавленный вручную, без записи реестра
            }
            $out[$i['type']]['mentions'] = ($out[$i['type']]['mentions'] ?? 0) + 1;
            $out[$i['type']]['posts'][$i['post_id']] = true;
        }
        foreach ($out as $type => $data) {
            $out[$type]['posts'] = count($data['posts']);
        }
        return $out;
    }

    /** Выгрузка в CSV. */
    public function export_csv($args = []) {
        $rows = $this->get_rows(array_merge($args, ['limit' => 100000]));

        $labels = [
            'inoagent'    => 'иностранный агент',
            'extremist'   => 'экстремистская',
            'terrorist'   => 'террористическая',
            'undesirable' => 'нежелательная',
            'fedsfm_person' => 'Физлица: Росфинмониторинг',
        ];

        $fh = fopen('php://output', 'w');
        fprintf($fh, "\xEF\xBB\xBF"); // BOM, чтобы Excel не ломал кириллицу
        fputcsv($fh, ['Материал', 'Ссылка', 'Тип', 'Организация', 'Реестр',
                      'Найдено как', 'Маркируется', 'Ссылки на ресурсы'], ';');

        foreach ($rows['items'] as $r) {
            $links = array_map(static function ($l) {
                $who = $l['org'] !== '' ? $l['org'] : $l['domain'];
                return $l['url'] . ' -> ' . $who;
            }, $r['links']);

            fputcsv($fh, [
                $r['post_title'],
                get_permalink($r['post_id']),
                $r['post_type'],
                $r['name'],
                $labels[$r['type']] ?? $r['type'],
                $r['matched_as'],
                $r['marked'] ? 'да' : 'нет',
                implode(' | ', $links),
            ], ';');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is the CSV HTTP response, not a filesystem write.
        fclose($fh);
    }
}
