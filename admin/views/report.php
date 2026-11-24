<?php defined('ABSPATH') || exit;

$settings = lem()->get_settings();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$registry = sanitize_text_field(wp_unslash($_GET['registry'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$mode     = sanitize_text_field(wp_unslash($_GET['mode'] ?? 'all'));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$search   = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$fresh    = sanitize_text_field(wp_unslash($_GET['fresh'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
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
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$paged    = max(1, absint(wp_unslash($_GET['paged'] ?? 1)));
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
    'inoagent'    => __('иноагент', 'legal-entity-marks'),
    'extremist'   => __('экстремистская', 'legal-entity-marks'),
    'terrorist'   => __('террористическая', 'legal-entity-marks'),
    'undesirable' => __('нежелательная', 'legal-entity-marks'),
    'fedsfm_person' => __('Физлица: Росфинмониторинг', 'legal-entity-marks'),
];
$total_pages = (int) ceil($result['total'] / $per_page);
?>
<div class="wrap">
    <h1><?php echo esc_html__('Упоминания', 'legal-entity-marks'); ?></h1>

    <p class="description" style="max-width:820px">
        <?php echo esc_html__('Все материалы, где сканер нашёл организации из отслеживаемых реестров.
        Маркируемые показаны вместе с остальными: столбец «Маркируется» показывает,
        видит ли метку читатель. Реестры, которые только отслеживаются, меток на сайте
        не оставляют, но их упоминания и ссылки видны здесь.', 'legal-entity-marks'); ?>
    </p>

    <?php if (!empty($update['at'])) : ?>
        <p class="description" style="max-width:820px">
            <?php echo esc_html__('Последняя проверка архива:', 'legal-entity-marks'); ?> <?php echo esc_html($update['at']); ?>.
            <?php if (($update['trigger'] ?? '') === 'registry-update') : ?>
                <?php echo esc_html__('Реестры пополнились на', 'legal-entity-marks'); ?> <?php echo (int) $update['new_entities']; ?> <?php echo esc_html__('записей,
                из них в материалах сайта встречается', 'legal-entity-marks'); ?> <?php echo (int) $update['mentioned']; ?><?php echo esc_html__('.
                Отбор «новое с последнего обновления» показывает именно их.', 'legal-entity-marks'); ?>
            <?php else : ?>
                <?php echo esc_html__('Просмотрено материалов:', 'legal-entity-marks'); ?> <?php echo (int) $update['posts_scanned']; ?><?php echo esc_html__('.
                Отбор «новое с последнего обновления» заработает после ближайшего
                обновления реестров.', 'legal-entity-marks'); ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php
    $summary = lem()->report->summary();
    if (!empty($summary)) : ?>
        <p>
            <?php foreach ($summary as $type => $data) : ?>
                <span style="margin-right:18px">
                    <strong><?php echo esc_html($labels[$type] ?? $type); ?>:</strong>
                    <?php /* translators: 1: number of mentions, 2: number of articles. */
                    printf(esc_html__('%1$d упоминаний в %2$d материалах', 'legal-entity-marks'),
                        (int) $data['mentions'], (int) $data['posts']); ?>
                </span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="lem-report">
        <select name="registry">
            <option value=""><?php echo esc_html__('Все реестры', 'legal-entity-marks'); ?></option>
            <?php foreach ($labels as $key => $label) :
                if (!in_array($key, $settings['track_registries'], true)) continue; ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($registry, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="mode">
            <option value="all" <?php selected($mode, 'all'); ?>><?php echo esc_html__('Все находки', 'legal-entity-marks'); ?></option>
            <option value="marked" <?php selected($mode, 'marked'); ?>><?php echo esc_html__('Только маркируемые', 'legal-entity-marks'); ?></option>
            <option value="tracked" <?php selected($mode, 'tracked'); ?>><?php echo esc_html__('Только без меток', 'legal-entity-marks'); ?></option>
        </select>
        <select name="fresh">
            <option value=""><?php echo esc_html__('За всё время', 'legal-entity-marks'); ?></option>
            <option value="update" <?php selected($fresh, 'update'); ?>><?php echo esc_html__('Новое с последнего обновления реестров', 'legal-entity-marks'); ?></option>
            <option value="30" <?php selected($fresh, '30'); ?>><?php echo esc_html__('Новое за 30 дней', 'legal-entity-marks'); ?></option>
        </select>
        <label style="margin-left:6px">
            <input type="checkbox" name="links_only" value="1" <?php checked($links_only); ?>>
            <?php echo esc_html__('только со ссылками', 'legal-entity-marks'); ?>
        </label>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('организация или заголовок', 'legal-entity-marks'); ?>">
        <button type="submit" class="button"><?php echo esc_html__('Фильтр', 'legal-entity-marks'); ?></button>

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
        <a href="<?php echo esc_url($csv_url); ?>" class="button" style="margin-left:8px"><?php echo esc_html__('Выгрузить CSV', 'legal-entity-marks'); ?></a>
    </form>

    <p><strong><?php echo esc_html__('Найдено:', 'legal-entity-marks'); ?></strong> <?php echo (int) $result['total']; ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:20%"><?php echo esc_html__('Материал', 'legal-entity-marks'); ?></th>
                <th style="width:20%"><?php echo esc_html__('Организация', 'legal-entity-marks'); ?></th>
                <th style="width:10%"><?php echo esc_html__('Реестр', 'legal-entity-marks'); ?></th>
                <th style="width:9%"><?php echo esc_html__('В реестре с', 'legal-entity-marks'); ?></th>
                <th style="width:11%"><?php echo esc_html__('Найдено как', 'legal-entity-marks'); ?></th>
                <th style="width:8%"><?php echo esc_html__('Маркируется', 'legal-entity-marks'); ?></th>
                <th><?php echo esc_html__('Ссылки на ресурсы', 'legal-entity-marks'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['items'])) : ?>
            <tr><td colspan="7"><?php echo esc_html__('Ничего не найдено. Если материалы давно не сканировались,
                запустите сканирование в разделе «Сканер».', 'legal-entity-marks'); ?></td></tr>
        <?php else : ?>
            <?php foreach ($result['items'] as $r) : ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url(get_edit_post_link($r['post_id'])); ?>">
                        <?php echo esc_html($r['post_title'] ?: __('(без заголовка)', 'legal-entity-marks')); ?>
                    </a>
                    <div class="row-actions">
                        <a href="<?php echo esc_url(get_permalink($r['post_id'])); ?>" target="_blank"><?php echo esc_html__('открыть', 'legal-entity-marks'); ?></a>
                        <span style="color:#777"> · <?php echo esc_html($r['post_type']); ?></span>
                        <?php if ($r['status'] !== 'publish') : ?>
                            <span style="color:#b32d2e"> · <?php echo esc_html($r['status']); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php echo esc_html($r['name']); ?>
                    <?php if (!$r['active']) : ?>
                        <span style="color:#777"><?php echo esc_html__('(исключена из реестра)', 'legal-entity-marks'); ?></span>
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
                        <br><span style="color:#b32d2e;font-size:11px"><?php echo esc_html__('найдено недавно', 'legal-entity-marks'); ?></span>
                    <?php endif; ?>
                </td>
                <td><code><?php echo esc_html($r['matched_as']); ?></code></td>
                <td>
                    <?php if ($r['marked']) : ?>
                        <?php echo esc_html__('да', 'legal-entity-marks'); ?>
                    <?php else : ?>
                        <span style="color:#777"><?php echo esc_html__('нет', 'legal-entity-marks'); ?></span>
                    <?php endif; ?>
                </td>
                <td style="word-break:break-word">
                    <?php if (empty($r['links'])) : ?>
                        <span style="color:#777">-</span>
                    <?php else : ?>
                        <?php foreach ($r['links'] as $l) : ?>
                            <div>
                                <code style="word-break:break-all"><?php echo esc_html($l['url']); ?></code>
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
        <?php echo wp_kses_post(paginate_links([
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'current' => $paged,
            'total'   => $total_pages,
        ])); ?>
    </div></div>
    <?php endif; ?>
</div>
