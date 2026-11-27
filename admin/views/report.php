<?php defined('ABSPATH') || exit;

$clem_settings = clem_plugin()->get_settings();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$clem_registry = sanitize_text_field(wp_unslash($_GET['registry'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$mode     = sanitize_text_field(wp_unslash($_GET['mode'] ?? 'all'));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$search   = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$clem_fresh    = sanitize_text_field(wp_unslash($_GET['fresh'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$clem_links_only = !empty($_GET['links_only']);

// «Новое» отсчитывается от последнего обновления реестров: именно тогда
// в старых публикациях появляются организации, которых вчера не было
$clem_update = clem_plugin()->rescan->last_report();
$clem_since  = '';
if ($clem_fresh === 'update' && !empty($clem_update['since'])) {
    $clem_since = $clem_update['since'];
} elseif ($clem_fresh === '30') {
    $clem_since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$paged    = max(1, absint(wp_unslash($_GET['paged'] ?? 1)));
$per_page = 100;

$clem_result = clem_plugin()->report->get_rows([
    'registry' => $clem_registry,
    'mode'     => in_array($mode, ['all', 'marked', 'tracked'], true) ? $mode : 'all',
    'search'   => $search,
    'since'    => $clem_since,
    'links_only' => $clem_links_only,
    'limit'    => $per_page,
    'offset'   => ($paged - 1) * $per_page,
]);

$clem_labels = [
    'inoagent'    => __('иноагент', 'chekazoid-legal-entity-marks'),
    'extremist'   => __('экстремистская', 'chekazoid-legal-entity-marks'),
    'terrorist'   => __('террористическая', 'chekazoid-legal-entity-marks'),
    'undesirable' => __('нежелательная', 'chekazoid-legal-entity-marks'),
    'fedsfm_person' => __('Физлица: Росфинмониторинг', 'chekazoid-legal-entity-marks'),
];
$clem_total_pages = (int) ceil($clem_result['total'] / $per_page);
?>
<div class="wrap">
    <h1><?php echo esc_html__('Упоминания', 'chekazoid-legal-entity-marks'); ?></h1>

    <p class="description" style="max-width:820px">
        <?php echo esc_html__('Все материалы, где сканер нашёл организации из отслеживаемых реестров.
        Маркируемые показаны вместе с остальными: столбец «Маркируется» показывает,
        видит ли метку читатель. Реестры, которые только отслеживаются, меток на сайте
        не оставляют, но их упоминания и ссылки видны здесь.', 'chekazoid-legal-entity-marks'); ?>
    </p>

    <?php if (!empty($clem_update['at'])) : ?>
        <p class="description" style="max-width:820px">
            <?php echo esc_html__('Последняя проверка архива:', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_update['at']); ?>.
            <?php if (($clem_update['trigger'] ?? '') === 'registry-update') : ?>
                <?php echo esc_html__('Реестры пополнились на', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update['new_entities']; ?> <?php echo esc_html__('записей,
                из них в материалах сайта встречается', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update['mentioned']; ?><?php echo esc_html__('.
                Отбор «новое с последнего обновления» показывает именно их.', 'chekazoid-legal-entity-marks'); ?>
            <?php else : ?>
                <?php echo esc_html__('Просмотрено материалов:', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update['posts_scanned']; ?><?php echo esc_html__('.
                Отбор «новое с последнего обновления» заработает после ближайшего
                обновления реестров.', 'chekazoid-legal-entity-marks'); ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php
    $clem_summary = clem_plugin()->report->summary();
    if (!empty($clem_summary)) : ?>
        <p>
            <?php foreach ($clem_summary as $type => $clem_data) : ?>
                <span style="margin-right:18px">
                    <strong><?php echo esc_html($clem_labels[$type] ?? $type); ?>:</strong>
                    <?php /* translators: 1: number of mentions, 2: number of articles. */
                    printf(esc_html__('%1$d упоминаний в %2$d материалах', 'chekazoid-legal-entity-marks'),
                        (int) $clem_data['mentions'], (int) $clem_data['posts']); ?>
                </span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="clem-report">
        <select name="registry">
            <option value=""><?php echo esc_html__('Все реестры', 'chekazoid-legal-entity-marks'); ?></option>
            <?php foreach ($clem_labels as $clem_key => $clem_label) :
                if (!in_array($clem_key, $clem_settings['track_registries'], true)) continue; ?>
                <option value="<?php echo esc_attr($clem_key); ?>" <?php selected($clem_registry, $clem_key); ?>>
                    <?php echo esc_html($clem_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="mode">
            <option value="all" <?php selected($mode, 'all'); ?>><?php echo esc_html__('Все находки', 'chekazoid-legal-entity-marks'); ?></option>
            <option value="marked" <?php selected($mode, 'marked'); ?>><?php echo esc_html__('Только маркируемые', 'chekazoid-legal-entity-marks'); ?></option>
            <option value="tracked" <?php selected($mode, 'tracked'); ?>><?php echo esc_html__('Только без меток', 'chekazoid-legal-entity-marks'); ?></option>
        </select>
        <select name="fresh">
            <option value=""><?php echo esc_html__('За всё время', 'chekazoid-legal-entity-marks'); ?></option>
            <option value="update" <?php selected($clem_fresh, 'update'); ?>><?php echo esc_html__('Новое с последнего обновления реестров', 'chekazoid-legal-entity-marks'); ?></option>
            <option value="30" <?php selected($clem_fresh, '30'); ?>><?php echo esc_html__('Новое за 30 дней', 'chekazoid-legal-entity-marks'); ?></option>
        </select>
        <label style="margin-left:6px">
            <input type="checkbox" name="links_only" value="1" <?php checked($clem_links_only); ?>>
            <?php echo esc_html__('только со ссылками', 'chekazoid-legal-entity-marks'); ?>
        </label>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('организация или заголовок', 'chekazoid-legal-entity-marks'); ?>">
        <button type="submit" class="button"><?php echo esc_html__('Фильтр', 'chekazoid-legal-entity-marks'); ?></button>

        <?php
        $clem_csv_url = wp_nonce_url(add_query_arg([
            'page'       => 'clem-report',
            'clem_export' => 'csv',
            'registry'   => $clem_registry,
            'mode'       => $mode,
            's'          => $search,
            'fresh'      => $clem_fresh,
            'links_only' => $clem_links_only ? 1 : '',
        ], admin_url('admin.php')), 'clem_export_csv');
        ?>
        <a href="<?php echo esc_url($clem_csv_url); ?>" class="button" style="margin-left:8px"><?php echo esc_html__('Выгрузить CSV', 'chekazoid-legal-entity-marks'); ?></a>
    </form>

    <p><strong><?php echo esc_html__('Найдено:', 'chekazoid-legal-entity-marks'); ?></strong> <?php echo (int) $clem_result['total']; ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:20%"><?php echo esc_html__('Материал', 'chekazoid-legal-entity-marks'); ?></th>
                <th style="width:20%"><?php echo esc_html__('Организация', 'chekazoid-legal-entity-marks'); ?></th>
                <th style="width:10%"><?php echo esc_html__('Реестр', 'chekazoid-legal-entity-marks'); ?></th>
                <th style="width:9%"><?php echo esc_html__('В реестре с', 'chekazoid-legal-entity-marks'); ?></th>
                <th style="width:11%"><?php echo esc_html__('Найдено как', 'chekazoid-legal-entity-marks'); ?></th>
                <th style="width:8%"><?php echo esc_html__('Маркируется', 'chekazoid-legal-entity-marks'); ?></th>
                <th><?php echo esc_html__('Ссылки на ресурсы', 'chekazoid-legal-entity-marks'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($clem_result['items'])) : ?>
            <tr><td colspan="7"><?php echo esc_html__('Ничего не найдено. Если материалы давно не сканировались,
                запустите сканирование в разделе «Сканер».', 'chekazoid-legal-entity-marks'); ?></td></tr>
        <?php else : ?>
            <?php foreach ($clem_result['items'] as $clem_r) : ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url(get_edit_post_link($clem_r['post_id'])); ?>">
                        <?php echo esc_html($clem_r['post_title'] ?: __('(без заголовка)', 'chekazoid-legal-entity-marks')); ?>
                    </a>
                    <div class="row-actions">
                        <a href="<?php echo esc_url(get_permalink($clem_r['post_id'])); ?>" target="_blank"><?php echo esc_html__('открыть', 'chekazoid-legal-entity-marks'); ?></a>
                        <span style="color:#777"> · <?php echo esc_html($clem_r['post_type']); ?></span>
                        <?php if ($clem_r['status'] !== 'publish') : ?>
                            <span style="color:#b32d2e"> · <?php echo esc_html($clem_r['status']); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php echo esc_html($clem_r['name']); ?>
                    <?php if (!$clem_r['active']) : ?>
                        <span style="color:#777"><?php echo esc_html__('(исключена из реестра)', 'chekazoid-legal-entity-marks'); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo $clem_r['type'] !== ''
                    ? esc_html($clem_labels[$clem_r['type']] ?? $clem_r['type'])
                    : '<span style="color:#777">-</span>'; ?></td>
                <td>
                    <?php if (!empty($clem_r['date_included'])) : ?>
                        <?php echo esc_html(date_i18n('d.m.Y', strtotime($clem_r['date_included']))); ?>
                    <?php else : ?>
                        <span style="color:#777">-</span>
                    <?php endif; ?>
                    <?php if ($clem_since !== '' && CLEM_Report::is_new($clem_r['first_seen'], '', $clem_since)) : ?>
                        <br><span style="color:#b32d2e;font-size:11px"><?php echo esc_html__('найдено недавно', 'chekazoid-legal-entity-marks'); ?></span>
                    <?php endif; ?>
                </td>
                <td><code><?php echo esc_html($clem_r['matched_as']); ?></code></td>
                <td>
                    <?php if ($clem_r['marked']) : ?>
                        <?php echo esc_html__('да', 'chekazoid-legal-entity-marks'); ?>
                    <?php else : ?>
                        <span style="color:#777"><?php echo esc_html__('нет', 'chekazoid-legal-entity-marks'); ?></span>
                    <?php endif; ?>
                </td>
                <td style="word-break:break-word">
                    <?php if (empty($clem_r['links'])) : ?>
                        <span style="color:#777">-</span>
                    <?php else : ?>
                        <?php foreach ($clem_r['links'] as $clem_l) : ?>
                            <div>
                                <code style="word-break:break-all"><?php echo esc_html($clem_l['url']); ?></code>
                                <?php if (!empty($clem_l['org'])) : ?>
                                    <div style="color:#777;font-size:11px">
                                        <?php echo esc_html($clem_l['org']); ?>
                                        <?php if (!empty($clem_l['type'])) : ?>
                                            (<?php echo esc_html($clem_labels[$clem_l['type']] ?? $clem_l['type']); ?>)
                                        <?php endif; ?>
                                    </div>
                                <?php else : ?>
                                    <span style="color:#777">(<?php echo esc_html($clem_l['domain']); ?>)</span>
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

    <?php if ($clem_total_pages > 1) : ?>
    <div class="tablenav bottom"><div class="tablenav-pages">
        <?php echo wp_kses_post(paginate_links([
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'current' => $paged,
            'total'   => $clem_total_pages,
        ])); ?>
    </div></div>
    <?php endif; ?>
</div>
