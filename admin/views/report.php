<?php defined('ABSPATH') || exit;

$settings = lem()->get_settings();
$registry = sanitize_text_field(wp_unslash($_GET['registry'] ?? ''));
$mode     = sanitize_text_field(wp_unslash($_GET['mode'] ?? 'all'));
$search   = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
$fresh    = sanitize_text_field(wp_unslash($_GET['fresh'] ?? ''));
$links_only = !empty($_GET['links_only']);

// «Новое» отсчитывается от последнего обновления реестров: именно тогда
// в старых публикациях появляются организации, которых вчера не было
$update = lem()->rescan->last_report();
$since  = '';
if ($fresh === 'update' && !empty($update['since'])) {
    $since = $update['since'];
} elseif ($fresh === '30') {
    $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
}
$paged    = max(1, (int) ($_GET['paged'] ?? 1));
$per_page = 100;

$result = lem()->report->get_rows([
    'registry' => $registry,
    'mode'     => in_array($mode, ['all', 'marked', 'tracked'], true) ? $mode : 'all',
    'search'   => $search,
    'since'    => $since,
    'links_only' => $links_only,
    'limit'    => $per_page,
    'offset'   => ($paged - 1) * $per_page,
]);

$labels = [
    'inoagent'    => 'иноагент',
    'extremist'   => 'экстремистская',
    'terrorist'   => 'террористическая',
    'undesirable' => 'нежелательная',
];
$total_pages = (int) ceil($result['total'] / $per_page);
?>
<div class="wrap">
    <h1>Упоминания</h1>

    <p class="description" style="max-width:820px">
        Все материалы, где сканер нашёл организации из отслеживаемых реестров.
        Маркируемые показаны вместе с остальными: столбец «Маркируется» показывает,
        видит ли метку читатель. Реестры, которые только отслеживаются, меток на сайте
        не оставляют, но их упоминания и ссылки видны здесь.
    </p>

    <?php if (!empty($update['at'])) : ?>
        <p class="description" style="max-width:820px">
            Последняя проверка архива: <?php echo esc_html($update['at']); ?>.
            Реестры пополнились на <?php echo (int) $update['new_entities']; ?> записей,
            из них в материалах сайта встречается <?php echo (int) $update['mentioned']; ?>.
            Отбор «новое с последнего обновления» показывает именно их.
        </p>
    <?php endif; ?>

    <?php
    $summary = lem()->report->summary();
    if (!empty($summary)) : ?>
        <p>
            <?php foreach ($summary as $type => $data) : ?>
                <span style="margin-right:18px">
                    <strong><?php echo esc_html($labels[$type] ?? $type); ?>:</strong>
                    <?php printf('%d упоминаний в %d материалах',
                        (int) $data['mentions'], (int) $data['posts']); ?>
                </span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="lem-report">
        <select name="registry">
            <option value="">Все реестры</option>
            <?php foreach ($labels as $key => $label) :
                if (!in_array($key, $settings['track_registries'], true)) continue; ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($registry, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="mode">
            <option value="all" <?php selected($mode, 'all'); ?>>Все находки</option>
            <option value="marked" <?php selected($mode, 'marked'); ?>>Только маркируемые</option>
            <option value="tracked" <?php selected($mode, 'tracked'); ?>>Только без меток</option>
        </select>
        <select name="fresh">
            <option value="">За всё время</option>
            <option value="update" <?php selected($fresh, 'update'); ?>>Новое с последнего обновления реестров</option>
            <option value="30" <?php selected($fresh, '30'); ?>>Новое за 30 дней</option>
        </select>
        <label style="margin-left:6px">
            <input type="checkbox" name="links_only" value="1" <?php checked($links_only); ?>>
            только со ссылками
        </label>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="организация или заголовок">
        <button type="submit" class="button">Фильтр</button>

        <?php
        $csv_url = wp_nonce_url(add_query_arg([
            'page'       => 'lem-report',
            'lem_export' => 'csv',
            'registry'   => $registry,
            'mode'       => $mode,
            's'          => $search,
            'fresh'      => $fresh,
            'links_only' => $links_only ? 1 : '',
        ], admin_url('admin.php')), 'lem_export_csv');
        ?>
        <a href="<?php echo esc_url($csv_url); ?>" class="button" style="margin-left:8px">Выгрузить CSV</a>
    </form>

    <p><strong>Найдено:</strong> <?php echo (int) $result['total']; ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:24%">Материал</th>
                <th style="width:24%">Организация</th>
                <th style="width:11%">Реестр</th>
                <th style="width:11%">В реестре с</th>
                <th style="width:12%">Найдено как</th>
                <th style="width:9%">Маркируется</th>
                <th>Запрещённые ссылки</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['items'])) : ?>
            <tr><td colspan="7">Ничего не найдено. Если материалы давно не сканировались,
                запустите сканирование в разделе «Сканер».</td></tr>
        <?php else : ?>
            <?php foreach ($result['items'] as $r) : ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url(get_edit_post_link($r['post_id'])); ?>">
                        <?php echo esc_html($r['post_title'] ?: '(без заголовка)'); ?>
                    </a>
                    <div class="row-actions">
                        <a href="<?php echo esc_url(get_permalink($r['post_id'])); ?>" target="_blank">открыть</a>
                        <span style="color:#777"> · <?php echo esc_html($r['post_type']); ?></span>
                        <?php if ($r['status'] !== 'publish') : ?>
                            <span style="color:#b32d2e"> · <?php echo esc_html($r['status']); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php echo esc_html($r['name']); ?>
                    <?php if (!$r['active']) : ?>
                        <span style="color:#777">(исключена из реестра)</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $r['type'] !== ''
                    ? esc_html($labels[$r['type']] ?? $r['type'])
                    : '<span style="color:#777">-</span>'; ?></td>
                <td>
                    <?php if (!empty($r['date_included'])) : ?>
                        <?php echo esc_html(date_i18n('d.m.Y', strtotime($r['date_included']))); ?>
                    <?php else : ?>
                        <span style="color:#777">-</span>
                    <?php endif; ?>
                    <?php if ($since !== '' && LEM_Report::is_new($r['first_seen'], '', $since)) : ?>
                        <br><span style="color:#b32d2e;font-size:11px">найдено недавно</span>
                    <?php endif; ?>
                </td>
                <td><code><?php echo esc_html($r['matched_as']); ?></code></td>
                <td>
                    <?php if ($r['marked']) : ?>
                        да
                    <?php else : ?>
                        <span style="color:#777">нет</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($r['links'])) : ?>
                        <span style="color:#777">-</span>
                    <?php else : ?>
                        <?php foreach ($r['links'] as $l) : ?>
                            <div>
                                <code><?php echo esc_html($l['url']); ?></code>
                                <?php if (!empty($l['org'])) : ?>
                                    <div style="color:#777;font-size:11px">
                                        <?php echo esc_html($l['org']); ?>
                                        <?php if (!empty($l['type'])) : ?>
                                            (<?php echo esc_html($labels[$l['type']] ?? $l['type']); ?>)
                                        <?php endif; ?>
                                    </div>
                                <?php else : ?>
                                    <span style="color:#777">(<?php echo esc_html($l['domain']); ?>)</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1) : ?>
    <div class="tablenav bottom"><div class="tablenav-pages">
        <?php echo paginate_links([
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'current' => $paged,
            'total'   => $total_pages,
        ]); ?>
    </div></div>
    <?php endif; ?>
</div>
