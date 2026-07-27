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

                $eid = (int) ($match['id'] ?? 0);
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
                    'links'      => $links_by_post[$row->ID] ?? [],
                    'active'     => isset($entities[$eid]) ? (int) $entities[$eid]['is_active'] : 0,
                ];
            }
        }

        $total = count($items);
        return ['items' => array_slice($items, $offset, $limit), 'total' => $total];
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
        $out = [];
        foreach ($rows as $r) {
            $meta = json_decode($r->meta_value, true);
            foreach (($meta['links'] ?? []) as $l) {
                $out[(int) $r->post_id][] = [
                    'url'    => $l['url'] ?? '',
                    'anchor' => $l['anchor'] ?? '',
                    'domain' => $l['matched_domain'] ?? '',
                ];
            }
        }
        return $out;
    }

    /** Сводка по реестрам для шапки отчёта. */
    public function summary() {
        $all = $this->get_rows(['limit' => 100000]);
        $out = [];
        foreach ($all['items'] as $i) {
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
                return $l['url'] . ' (' . $l['domain'] . ')';
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
