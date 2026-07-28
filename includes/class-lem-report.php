<?php
defined('ABSPATH') || exit;

/**
 * Сводка находок по всем материалам.
 *
 * Нужна тем, кому маркировка не требуется, а знать о факте упоминания надо:
 * ссылка на нежелательную организацию может быть истолкована как участие
 * в её деятельности, поэтому важно видеть список таких материалов.
 */
class LEM_Report {

    /**
     * Строки отчёта.
     *
     * @param array $args registry (тип или ''), mode (all|marked|tracked),
     *                    search, limit, offset
     * @return array{items: array, total: int}
     */
    public function get_rows($args = []) {
        global $wpdb;

        $settings = lem()->get_settings();
        $mark     = $settings['mark_registries'];
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

        // Мета пишется сканером и содержит всё найденное, независимо от того,
        // маркируется реестр или только отслеживается
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type, p.post_status, pm.meta_value
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value != ''
               AND p.post_status IN ('publish','draft','pending','private','future')
             ORDER BY p.post_date DESC
             LIMIT 5000",
            LEM_META_KEY
        ));

        $entities = [];
        foreach (lem()->entities->get_for_marking(true) as $e) {
            $entities[(int) $e['id']] = $e;
        }

        $links_by_post = $this->links_by_post();

        $items = [];
        foreach ($rows as $row) {
            $meta = json_decode($row->meta_value, true);
            if (empty($meta['entities'])) {
                continue;
            }
            $overrides = LEM_Frontend::get_overrides($row->ID);

            foreach ($meta['entities'] as $match) {
                $type = $match['type'] ?? '';
                if (!in_array($type, $track, true)) {
                    continue; // реестр не отслеживается
                }
                if ($registry !== '' && $type !== $registry) {
                    continue;
                }

                $is_marked = in_array($type, $mark, true)
                    && LEM_Frontend::should_mark($match, $settings, $overrides);

                if ($mode === 'marked' && !$is_marked) {
                    continue;
                }
                if ($mode === 'tracked' && $is_marked) {
                    continue;
                }

                $name = $match['name'] ?? '';
                if ($search !== ''
                    && mb_stripos($name, $search) === false
                    && mb_stripos($row->post_title, $search) === false) {
                    continue;
                }

                $eid   = (int) ($match['id'] ?? 0);
                $links = $links_by_post[$row->ID] ?? [];
                if ($links_only && empty($links)) {
                    continue;
                }

                $entity     = $entities[$eid] ?? [];
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
                    'active'     => isset($entities[$eid]) ? (int) $entities[$eid]['is_active'] : 0,
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
        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts}
             WHERE ID IN ($in)
               AND post_status IN ('publish','draft','pending','private','future')
             ORDER BY post_date DESC"
        );

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
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value != '' LIMIT 5000",
            LEM_BANNED_LINKS_META_KEY
        ));

        // Чей это ресурс: домены Минюст публикует вместе с записью реестра,
        // поэтому по ссылке видно организацию и её реестр
        $owners = [];
        $sites  = $wpdb->prefix . 'lem_banned_sites';
        $ents   = $wpdb->prefix . LEM_TABLE;
        foreach ($wpdb->get_results(
            "SELECT s.domain, s.account, s.label, e.name, e.type, e.first_seen, e.date_included
             FROM $sites s LEFT JOIN $ents e ON e.id = s.entity_id"
        ) as $s) {
            $key = $s->account !== '' ? $s->domain . '/' . $s->account : $s->domain;
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
        ];

        $fh = fopen('php://output', 'w');
        fprintf($fh, "\xEF\xBB\xBF"); // BOM, чтобы Excel не ломал кириллицу
        fputcsv($fh, ['Материал', 'Ссылка', 'Тип', 'Организация', 'Реестр',
                      'Найдено как', 'Маркируется', 'Запрещённые ссылки'], ';');

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
        fclose($fh);
    }
}
