<?php defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$paged = max(1, absint(wp_unslash($_GET['paged'] ?? 1)));
$per_page = 100;
$clem_sites_result = clem_plugin()->banned_sites->search(['search' => $search, 'limit' => $per_page, 'offset' => ($paged - 1) * $per_page]);
$clem_sites = $clem_sites_result['items'];
$clem_total_pages = (int) ceil($clem_sites_result['total'] / $per_page);

$clem_type_labels = [
    'inoagent'    => __('иноагент', 'chekazoid-legal-entity-marks'),
    'extremist'   => __('экстремистская', 'chekazoid-legal-entity-marks'),
    'terrorist'   => __('террористическая', 'chekazoid-legal-entity-marks'),
    'undesirable' => __('нежелательная', 'chekazoid-legal-entity-marks'),
    'manual'      => __('добавлен вручную', 'chekazoid-legal-entity-marks'),
];
$clem_by_type   = clem_plugin()->banned_sites->count_by_type();
$clem_site_index = clem_plugin()->banned_sites->get_index();

// Результаты сканирования
global $wpdb;
/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
$clem_flagged_posts = $wpdb->get_results($wpdb->prepare(
    "SELECT p.ID, p.post_title, pm.meta_value
     FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
     WHERE pm.meta_key = %s AND pm.meta_value != ''
     ORDER BY p.ID DESC
     LIMIT 1000",
    CLEM_BANNED_LINKS_META_KEY
)) ;
/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */

$clem_total_flagged_posts = 0;
$clem_total_flagged_links = 0;
$clem_removable_links = 0;
$clem_removable_posts = 0;
$clem_results_rows = [];
foreach ($clem_flagged_posts as $clem_fp) {
    $clem_meta = json_decode($clem_fp->meta_value, true);
    if (empty($clem_meta['links'])) continue;
    $clem_has_flagged_links = false;
    foreach ($clem_meta['links'] as $link) {
        if (!array_key_exists($link['matched_domain'] ?? '', $clem_site_index)) {
            continue;
        }
        $clem_has_flagged_links = true;
        $clem_results_rows[] = [
            'post_id'        => $clem_fp->ID,
            'post_title'     => $clem_fp->post_title,
            'url'            => $link['url'],
            'anchor'         => $link['anchor'],
            'matched_domain' => $link['matched_domain'],
            // Мета, записанная до разделения реестров, отметки не содержит:
            // тип берём из текущего списка ресурсов
            'registry'       => $link['registry'] ?? ($clem_site_index[$link['matched_domain']] ?? ''),
            'removable'      => CLEM_Link_Scanner::link_is_removable($link),
        ];
        $clem_total_flagged_links++;
    }
    if ($clem_has_flagged_links) {
        $clem_total_flagged_posts++;
    }
}

$clem_scan_state = get_transient(CLEM_Link_Scanner::SCAN_STATE_KEY);
?>
<div class="wrap">
    <h1><?php echo esc_html__('Ссылки на ресурсы из реестров', 'chekazoid-legal-entity-marks'); ?></h1>

    <!-- Секция 1: Реестр доменов -->
    <div class="clem-card" style="margin-bottom:20px">
        <h2><?php echo esc_html__('Реестр ресурсов', 'chekazoid-legal-entity-marks'); ?></h2>
        <p class="description" style="max-width:820px">
            <?php echo esc_html__('Ресурсы организаций из реестров. Часть приезжает автоматически: у записей
            об иноагентах Минюст публикует ссылки на их сайты и аккаунты.
            Для площадок, где у организации только аккаунт, указывайте ссылку целиком
            (', 'chekazoid-legal-entity-marks'); ?><code>t.me/doxajournal</code>, <code>youtube.com/@channel</code><?php echo esc_html__('): помечен
            будет этот аккаунт, а не весь телеграм или YouTube.', 'chekazoid-legal-entity-marks'); ?>
        </p>
        <p class="description" style="max-width:820px">
            <strong><?php echo esc_html__('Удаляются из текста не все.', 'chekazoid-legal-entity-marks'); ?></strong> <?php echo esc_html__('Ссылка на ресурс нежелательной
            организации может толковаться как участие в её деятельности, её убирают.
            Ссылка на сайт иноагента ничем не запрещена, ей достаточно маркировки,
            и чистка её не трогает. Список удаляемых реестров задаётся в настройках.', 'chekazoid-legal-entity-marks'); ?>
        </p>
        <?php if (!empty($clem_by_type)) : ?>
            <p>
                <?php foreach ($clem_by_type as $type => $clem_count) : ?>
                    <span style="margin-right:16px">
                        <?php echo esc_html($clem_type_labels[$type] ?? $type); ?>:
                        <strong><?php echo (int) $clem_count; ?></strong>
                    </span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <p>
            <button type="button" class="button button-primary" id="clem-add-site"><?php echo esc_html__('Добавить ресурс', 'chekazoid-legal-entity-marks'); ?></button>
            <button type="button" class="button" id="clem-import-sites"><?php echo esc_html__('Массовый импорт', 'chekazoid-legal-entity-marks'); ?></button>
            <span style="margin-left:10px;color:#666"><?php echo esc_html__('Всего ресурсов:', 'chekazoid-legal-entity-marks'); ?> <strong><?php echo esc_html(number_format_i18n($clem_sites_result['total'])); ?></strong></span>
        </p>

        <form method="get" style="margin:12px 0">
            <input type="hidden" name="page" value="clem-banned-links">
            <label class="screen-reader-text" for="clem-site-search"><?php echo esc_html__('Найти ресурс', 'chekazoid-legal-entity-marks'); ?></label>
            <input type="search" id="clem-site-search" name="s" value="<?php echo esc_attr($search); ?>">
            <?php submit_button(__('Найти ресурс', 'chekazoid-legal-entity-marks'), 'secondary', '', false); ?>
        </form>
        <?php if (!empty($clem_sites)) : ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:10px">
            <thead>
                <tr>
                    <th style="width:40px">ID</th>
                    <th><?php echo esc_html__('Ресурс', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:130px"><?php echo esc_html__('Реестр', 'chekazoid-legal-entity-marks'); ?></th>
                    <th><?php echo esc_html__('Название организации', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:140px"><?php echo esc_html__('Добавлен', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:140px"><?php echo esc_html__('Действия', 'chekazoid-legal-entity-marks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clem_sites as $clem_site) : ?>
                <tr data-id="<?php echo esc_attr($clem_site['id']); ?>">
                    <td><?php echo esc_html($clem_site['id']); ?></td>
                    <?php
                    $clem_site_key  = $clem_site['domain'] . (!empty($clem_site['account']) ? '/' . $clem_site['account'] : '');
                    $clem_site_type = $clem_site_index[$clem_site_key] ?? '';
                    ?>
                    <td><code><?php echo esc_html($clem_site_key); ?></code></td>
                    <td>
                        <?php echo esc_html($clem_type_labels[$clem_site_type ?: 'manual'] ?? $clem_site_type); ?>
                        <?php if (!clem_plugin()->banned_sites->is_removable($clem_site_type)) : ?>
                            <br><span style="color:#777;font-size:11px"><?php echo esc_html__('не удаляется', 'chekazoid-legal-entity-marks'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($clem_site['label']); ?></td>
                    <td><?php echo esc_html($clem_site['added_at'] ? date_i18n('d.m.Y', strtotime($clem_site['added_at'])) : ''); ?></td>
                    <td>
                        <button type="button" class="button button-small clem-edit-site"
                                data-site="<?php echo esc_attr(wp_json_encode($clem_site)); ?>"><?php echo esc_html__('Изменить', 'chekazoid-legal-entity-marks'); ?></button>
                        <button type="button" class="button button-small button-link-delete clem-delete-site"
                                data-id="<?php echo esc_attr($clem_site['id']); ?>"><?php echo esc_html__('Удалить', 'chekazoid-legal-entity-marks'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($clem_total_pages > 1) : ?>
            <div class="tablenav"><div class="tablenav-pages">
            <?php echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'), 'format' => '',
                'current' => $paged, 'total' => $clem_total_pages,
            ])); ?>
            </div></div>
        <?php endif; ?>
        <?php else : ?>
        <p><?php echo esc_html__('Ресурсы не найдены. Измените поиск или добавьте записи вручную.', 'chekazoid-legal-entity-marks'); ?></p>
        <?php endif; ?>
    </div>

    <!-- Секция 2: Сканер ссылок -->
    <div class="clem-card" style="margin-bottom:20px">
        <h2><?php echo esc_html__('Сканер ссылок', 'chekazoid-legal-entity-marks'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Сканирует все опубликованные статьи и отмечает ссылки на ресурсы организаций из реестров.', 'chekazoid-legal-entity-marks'); ?>
        </p>

        <p>
            <button type="button" class="button button-primary button-hero" id="clem-link-scan-all"
                    <?php echo empty($clem_sites) ? 'disabled' : ''; ?>>
                <?php echo esc_html__('Сканировать все статьи', 'chekazoid-legal-entity-marks'); ?>
            </button>
        </p>

        <div id="clem-link-scan-progress" style="display:none;margin-top:20px">
            <div class="clem-progress-bar">
                <div class="clem-progress-fill" id="clem-link-progress-fill" style="width:0%"></div>
            </div>
            <p id="clem-link-scan-status" class="description"></p>
            <p>
                <button type="button" class="button" id="clem-link-scan-cancel" style="display:none"><?php echo esc_html__('Отменить', 'chekazoid-legal-entity-marks'); ?></button>
            </p>
        </div>

        <div id="clem-link-scan-result" style="display:none;margin-top:20px">
            <div class="notice notice-success inline">
                <p id="clem-link-scan-result-text"></p>
            </div>
        </div>

        <?php if ($clem_scan_state && $clem_scan_state['status'] === 'complete') : ?>
        <div class="notice notice-info inline" style="margin-top:20px">
            <p>
                <?php printf(
                    /* translators: 1: scan date, 2: articles with links, 3: total links. */
                    esc_html__('Последний скан: %1$s - %2$d статей со ссылками, %3$d ссылок всего', 'chekazoid-legal-entity-marks'),
                    esc_html($clem_scan_state['finished_at'] ?? $clem_scan_state['started_at']),
                    (int) $clem_scan_state['posts_with_links'],
                    (int) $clem_scan_state['total_links']
                ); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Секция 3: Результаты и удаление -->
    <?php if ($clem_total_flagged_links > 0) : ?>
    <?php
    $clem_removable_rows  = array_filter($clem_results_rows, static function ($r) { return $r['removable']; });
    $clem_removable_links = count($clem_removable_rows);
    $clem_removable_posts = count(array_unique(array_column($clem_removable_rows, 'post_id')));
    ?>
    <div class="clem-card">
        <h2><?php echo esc_html__('Найденные ссылки на ресурсы из реестров', 'chekazoid-legal-entity-marks'); ?></h2>
        <p>
            <?php echo esc_html__('Найдено', 'chekazoid-legal-entity-marks'); ?> <strong><?php echo esc_html($clem_total_flagged_links); ?></strong> <?php echo esc_html__('ссылок
            в', 'chekazoid-legal-entity-marks'); ?> <strong><?php echo esc_html($clem_total_flagged_posts); ?></strong> <?php echo esc_html__('статьях.
            Из них подлежат удалению:', 'chekazoid-legal-entity-marks'); ?> <strong><?php echo esc_html($clem_removable_links); ?></strong>
            <?php echo esc_html__('в', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_removable_posts); ?> <?php echo esc_html__('статьях.', 'chekazoid-legal-entity-marks'); ?>
            <?php if ($clem_removable_links < $clem_total_flagged_links) : ?>
                <?php echo esc_html__('Остальные - ресурсы иноагентов, ссылаться на них не запрещено,
                чистка их не тронет.', 'chekazoid-legal-entity-marks'); ?>
            <?php endif; ?>
        </p>

        <?php if ($clem_removable_links > 0) : ?>
        <p>
            <button type="button" class="button clem-button-danger button-hero" id="clem-remove-all-links"
                    data-links="<?php echo esc_attr($clem_removable_links); ?>" data-posts="<?php echo esc_attr($clem_removable_posts); ?>">
                <?php echo esc_html__('Удалить ссылки, подлежащие удалению (', 'chekazoid-legal-entity-marks'); ?><?php echo esc_html($clem_removable_links); ?>)
            </button>
        </p>
        <?php endif; ?>

        <div id="clem-remove-progress" style="display:none;margin-top:20px">
            <div class="clem-progress-bar">
                <div class="clem-progress-fill" id="clem-remove-progress-fill" style="width:0%"></div>
            </div>
            <p id="clem-remove-status" class="description"></p>
        </div>

        <div id="clem-remove-result" style="display:none;margin-top:20px">
            <div class="notice notice-success inline">
                <p id="clem-remove-result-text"></p>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped" style="margin-top:15px" id="clem-results-table">
            <thead>
                <tr>
                    <th style="width:250px"><?php echo esc_html__('Статья', 'chekazoid-legal-entity-marks'); ?></th>
                    <th><?php echo esc_html__('URL ссылки', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:200px"><?php echo esc_html__('Текст ссылки', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:150px"><?php echo esc_html__('Совпавший ресурс', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:120px"><?php echo esc_html__('Реестр', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:120px"><?php echo esc_html__('Действие', 'chekazoid-legal-entity-marks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $clem_prev_post_id = 0;
                foreach ($clem_results_rows as $clem_row) :
                    $clem_is_first = ($clem_row['post_id'] !== $clem_prev_post_id);
                    $clem_prev_post_id = $clem_row['post_id'];
                ?>
                <tr data-post-id="<?php echo esc_attr($clem_row['post_id']); ?>">
                    <td>
                        <?php if ($clem_is_first) : ?>
                        <a href="<?php echo esc_url(get_edit_post_link($clem_row['post_id'])); ?>" target="_blank">
                            <?php echo esc_html($clem_row['post_title']); ?>
                        </a>
                        <?php endif; ?>
                    </td>
                    <td><span class="clem-banned-link-url"><?php echo esc_html($clem_row['url']); ?></span></td>
                    <td><?php echo esc_html($clem_row['anchor']); ?></td>
                    <td><code><?php echo esc_html($clem_row['matched_domain']); ?></code></td>
                    <td>
                        <?php echo esc_html($clem_type_labels[$clem_row['registry'] ?: 'manual'] ?? $clem_row['registry']); ?>
                        <?php if (!$clem_row['removable']) : ?>
                            <br><span style="color:#777;font-size:11px"><?php echo esc_html__('не удаляется', 'chekazoid-legal-entity-marks'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($clem_is_first) : ?>
                        <button type="button" class="button button-small clem-remove-post-links"
                                data-post-id="<?php echo esc_attr($clem_row['post_id']); ?>"><?php echo esc_html__('Удалить ссылки', 'chekazoid-legal-entity-marks'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($clem_scan_state && $clem_scan_state['status'] === 'complete') : ?>
    <div class="clem-card">
        <h2><?php echo esc_html__('Результаты', 'chekazoid-legal-entity-marks'); ?></h2>
        <p><?php echo esc_html__('Ссылок на ресурсы из реестров не найдено.', 'chekazoid-legal-entity-marks'); ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- Модалка: добавить/редактировать домен -->
<div id="clem-site-modal" class="clem-modal" style="display:none">
    <div class="clem-modal-content">
        <h2 id="clem-site-modal-title"><?php echo esc_html__('Добавить домен', 'chekazoid-legal-entity-marks'); ?></h2>
        <input type="hidden" id="clem-site-id" value="0">
        <table class="form-table">
            <tr>
                <th><label for="clem-site-domain"><?php echo esc_html__('Домен или аккаунт', 'chekazoid-legal-entity-marks'); ?></label></th>
                <td>
                    <input type="text" id="clem-site-domain" class="regular-text" placeholder="<?php echo esc_attr__('example.org или t.me/doxajournal', 'chekazoid-legal-entity-marks'); ?>" required>
                    <p class="description">
                        <?php echo esc_html__('Можно указать URL целиком. Для обычного сайта помечается весь домен,
                        для телеграма, YouTube и соцсетей - только указанный аккаунт.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="clem-site-registry"><?php echo esc_html__('Реестр', 'chekazoid-legal-entity-marks'); ?></label></th>
                <td>
                    <select id="clem-site-registry">
                        <option value=""><?php echo esc_html__('Не указан', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="inoagent"><?php echo esc_html__('Иностранные агенты', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="extremist"><?php echo esc_html__('Экстремистские организации', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="terrorist"><?php echo esc_html__('Террористические организации', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="undesirable"><?php echo esc_html__('Нежелательные организации', 'chekazoid-legal-entity-marks'); ?></option>
                    </select>
                    <p class="description">
                        <?php echo esc_html__('От реестра зависит, убирать ли такие ссылки из текста.
                        Без указания ссылки на домен считаются подлежащими удалению: раз внесли руками,
                        значит, ссылаться на него не собираетесь.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="clem-site-label"><?php echo esc_html__('Название организации', 'chekazoid-legal-entity-marks'); ?></label></th>
                <td><input type="text" id="clem-site-label" class="regular-text"></td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="clem-save-site"><?php echo esc_html__('Сохранить', 'chekazoid-legal-entity-marks'); ?></button>
            <button type="button" class="button" id="clem-cancel-site"><?php echo esc_html__('Отмена', 'chekazoid-legal-entity-marks'); ?></button>
            <span id="clem-site-status-msg" class="clem-inline-status"></span>
        </p>
    </div>
</div>

<!-- Модалка: массовый импорт -->
<div id="clem-import-modal" class="clem-modal" style="display:none">
    <div class="clem-modal-content">
        <h2><?php echo esc_html__('Массовый импорт доменов', 'chekazoid-legal-entity-marks'); ?></h2>
        <p class="description"><?php echo esc_html__('По одному домену на строку. Формат:', 'chekazoid-legal-entity-marks'); ?> <code><?php echo esc_html__('домен | Название организации', 'chekazoid-legal-entity-marks'); ?></code> <?php echo esc_html__('(название необязательно).', 'chekazoid-legal-entity-marks'); ?></p>
        <textarea id="clem-import-domains" rows="10" class="large-text" placeholder="<?php echo esc_attr__('example.org | Организация X
another-site.com', 'chekazoid-legal-entity-marks'); ?>"></textarea>
        <p>
            <button type="button" class="button button-primary" id="clem-do-import"><?php echo esc_html__('Импортировать', 'chekazoid-legal-entity-marks'); ?></button>
            <button type="button" class="button" id="clem-cancel-import"><?php echo esc_html__('Отмена', 'chekazoid-legal-entity-marks'); ?></button>
            <span id="clem-import-status-msg" class="clem-inline-status"></span>
        </p>
    </div>
</div>
