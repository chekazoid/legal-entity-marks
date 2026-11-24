<?php defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$paged = max(1, absint(wp_unslash($_GET['paged'] ?? 1)));
$per_page = 100;
$sites_result = lem()->banned_sites->search(['search' => $search, 'limit' => $per_page, 'offset' => ($paged - 1) * $per_page]);
$sites = $sites_result['items'];
$total_pages = (int) ceil($sites_result['total'] / $per_page);

$type_labels = [
    'inoagent'    => __('иноагент', 'legal-entity-marks'),
    'extremist'   => __('экстремистская', 'legal-entity-marks'),
    'terrorist'   => __('террористическая', 'legal-entity-marks'),
    'undesirable' => __('нежелательная', 'legal-entity-marks'),
    'manual'      => __('добавлен вручную', 'legal-entity-marks'),
];
$by_type   = lem()->banned_sites->count_by_type();
$site_index = lem()->banned_sites->get_index();

// Результаты сканирования
global $wpdb;
/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
$flagged_posts = $wpdb->get_results($wpdb->prepare(
    "SELECT p.ID, p.post_title, pm.meta_value
     FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
     WHERE pm.meta_key = %s AND pm.meta_value != ''
     ORDER BY p.ID DESC
     LIMIT 1000",
    LEM_BANNED_LINKS_META_KEY
)) ;
/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */

$total_flagged_posts = count($flagged_posts);
$total_flagged_links = 0;
$removable_links = 0;
$removable_posts = 0;
$results_rows = [];
foreach ($flagged_posts as $fp) {
    $meta = json_decode($fp->meta_value, true);
    if (empty($meta['links'])) continue;
    foreach ($meta['links'] as $link) {
        $results_rows[] = [
            'post_id'        => $fp->ID,
            'post_title'     => $fp->post_title,
            'url'            => $link['url'],
            'anchor'         => $link['anchor'],
            'matched_domain' => $link['matched_domain'],
            // Мета, записанная до разделения реестров, отметки не содержит:
            // тип берём из текущего списка ресурсов
            'registry'       => $link['registry'] ?? ($site_index[$link['matched_domain']] ?? ''),
            'removable'      => LEM_Link_Scanner::link_is_removable($link),
        ];
        $total_flagged_links++;
    }
}

$scan_state = get_transient(LEM_Link_Scanner::SCAN_STATE_KEY);
?>
<div class="wrap">
    <h1><?php echo esc_html__('Ссылки на ресурсы из реестров', 'legal-entity-marks'); ?></h1>

    <!-- Секция 1: Реестр доменов -->
    <div class="lem-card" style="margin-bottom:20px">
        <h2><?php echo esc_html__('Реестр ресурсов', 'legal-entity-marks'); ?></h2>
        <p class="description" style="max-width:820px">
            <?php echo esc_html__('Ресурсы организаций из реестров. Часть приезжает автоматически: у записей
            об иноагентах Минюст публикует ссылки на их сайты и аккаунты.
            Для площадок, где у организации только аккаунт, указывайте ссылку целиком
            (', 'legal-entity-marks'); ?><code>t.me/doxajournal</code>, <code>youtube.com/@channel</code><?php echo esc_html__('): помечен
            будет этот аккаунт, а не весь телеграм или YouTube.', 'legal-entity-marks'); ?>
        </p>
        <p class="description" style="max-width:820px">
            <strong><?php echo esc_html__('Удаляются из текста не все.', 'legal-entity-marks'); ?></strong> <?php echo esc_html__('Ссылка на ресурс нежелательной
            организации может толковаться как участие в её деятельности, её убирают.
            Ссылка на сайт иноагента ничем не запрещена, ей достаточно маркировки,
            и чистка её не трогает. Список удаляемых реестров задаётся в настройках.', 'legal-entity-marks'); ?>
        </p>
        <?php if (!empty($by_type)) : ?>
            <p>
                <?php foreach ($by_type as $type => $count) : ?>
                    <span style="margin-right:16px">
                        <?php echo esc_html($type_labels[$type] ?? $type); ?>:
                        <strong><?php echo (int) $count; ?></strong>
                    </span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <p>
            <button type="button" class="button button-primary" id="lem-add-site"><?php echo esc_html__('Добавить ресурс', 'legal-entity-marks'); ?></button>
            <button type="button" class="button" id="lem-import-sites"><?php echo esc_html__('Массовый импорт', 'legal-entity-marks'); ?></button>
            <span style="margin-left:10px;color:#666"><?php echo esc_html__('Всего ресурсов:', 'legal-entity-marks'); ?> <strong><?php echo esc_html(number_format_i18n($sites_result['total'])); ?></strong></span>
        </p>

        <form method="get" style="margin:12px 0">
            <input type="hidden" name="page" value="lem-banned-links">
            <label class="screen-reader-text" for="lem-site-search"><?php echo esc_html__('Найти ресурс', 'legal-entity-marks'); ?></label>
            <input type="search" id="lem-site-search" name="s" value="<?php echo esc_attr($search); ?>">
            <?php submit_button(__('Найти ресурс', 'legal-entity-marks'), 'secondary', '', false); ?>
        </form>
        <?php if (!empty($sites)) : ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:10px">
            <thead>
                <tr>
                    <th style="width:40px">ID</th>
                    <th><?php echo esc_html__('Ресурс', 'legal-entity-marks'); ?></th>
                    <th style="width:130px"><?php echo esc_html__('Реестр', 'legal-entity-marks'); ?></th>
                    <th><?php echo esc_html__('Название организации', 'legal-entity-marks'); ?></th>
                    <th style="width:140px"><?php echo esc_html__('Добавлен', 'legal-entity-marks'); ?></th>
                    <th style="width:140px"><?php echo esc_html__('Действия', 'legal-entity-marks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $site) : ?>
                <tr data-id="<?php echo esc_attr($site['id']); ?>">
                    <td><?php echo esc_html($site['id']); ?></td>
                    <?php
                    $site_key  = $site['domain'] . (!empty($site['account']) ? '/' . $site['account'] : '');
                    $site_type = $site_index[$site_key] ?? '';
                    ?>
                    <td><code><?php echo esc_html($site_key); ?></code></td>
                    <td>
                        <?php echo esc_html($type_labels[$site_type ?: 'manual'] ?? $site_type); ?>
                        <?php if (!lem()->banned_sites->is_removable($site_type)) : ?>
                            <br><span style="color:#777;font-size:11px"><?php echo esc_html__('не удаляется', 'legal-entity-marks'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($site['label']); ?></td>
                    <td><?php echo esc_html($site['added_at'] ? date_i18n('d.m.Y', strtotime($site['added_at'])) : ''); ?></td>
                    <td>
                        <button type="button" class="button button-small lem-edit-site"
                                data-site="<?php echo esc_attr(wp_json_encode($site)); ?>"><?php echo esc_html__('Изменить', 'legal-entity-marks'); ?></button>
                        <button type="button" class="button button-small button-link-delete lem-delete-site"
                                data-id="<?php echo esc_attr($site['id']); ?>"><?php echo esc_html__('Удалить', 'legal-entity-marks'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1) : ?>
            <div class="tablenav"><div class="tablenav-pages">
            <?php echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%'), 'format' => '',
                'current' => $paged, 'total' => $total_pages,
            ])); ?>
            </div></div>
        <?php endif; ?>
        <?php else : ?>
        <p><?php echo esc_html__('Ресурсы не найдены. Измените поиск или добавьте записи вручную.', 'legal-entity-marks'); ?></p>
        <?php endif; ?>
    </div>

    <!-- Секция 2: Сканер ссылок -->
    <div class="lem-card" style="margin-bottom:20px">
        <h2><?php echo esc_html__('Сканер ссылок', 'legal-entity-marks'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Сканирует все опубликованные статьи и отмечает ссылки на ресурсы организаций из реестров.', 'legal-entity-marks'); ?>
        </p>

        <p>
            <button type="button" class="button button-primary button-hero" id="lem-link-scan-all"
                    <?php echo empty($sites) ? 'disabled' : ''; ?>>
                <?php echo esc_html__('Сканировать все статьи', 'legal-entity-marks'); ?>
            </button>
        </p>

        <div id="lem-link-scan-progress" style="display:none;margin-top:20px">
            <div class="lem-progress-bar">
                <div class="lem-progress-fill" id="lem-link-progress-fill" style="width:0%"></div>
            </div>
            <p id="lem-link-scan-status" class="description"></p>
            <p>
                <button type="button" class="button" id="lem-link-scan-cancel" style="display:none"><?php echo esc_html__('Отменить', 'legal-entity-marks'); ?></button>
            </p>
        </div>

        <div id="lem-link-scan-result" style="display:none;margin-top:20px">
            <div class="notice notice-success inline">
                <p id="lem-link-scan-result-text"></p>
            </div>
        </div>

        <?php if ($scan_state && $scan_state['status'] === 'complete') : ?>
        <div class="notice notice-info inline" style="margin-top:20px">
            <p>
                <?php printf(
                    /* translators: 1: scan date, 2: articles with links, 3: total links. */
                    esc_html__('Последний скан: %1$s - %2$d статей со ссылками, %3$d ссылок всего', 'legal-entity-marks'),
                    esc_html($scan_state['finished_at'] ?? $scan_state['started_at']),
                    (int) $scan_state['posts_with_links'],
                    (int) $scan_state['total_links']
                ); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Секция 3: Результаты и удаление -->
    <?php if ($total_flagged_links > 0) : ?>
    <?php
    $removable_rows  = array_filter($results_rows, static function ($r) { return $r['removable']; });
    $removable_links = count($removable_rows);
    $removable_posts = count(array_unique(array_column($removable_rows, 'post_id')));
    ?>
    <div class="lem-card">
        <h2><?php echo esc_html__('Найденные ссылки на ресурсы из реестров', 'legal-entity-marks'); ?></h2>
        <p>
            <?php echo esc_html__('Найдено', 'legal-entity-marks'); ?> <strong><?php echo esc_html($total_flagged_links); ?></strong> <?php echo esc_html__('ссылок
            в', 'legal-entity-marks'); ?> <strong><?php echo esc_html($total_flagged_posts); ?></strong> <?php echo esc_html__('статьях.
            Из них подлежат удалению:', 'legal-entity-marks'); ?> <strong><?php echo esc_html($removable_links); ?></strong>
            <?php echo esc_html__('в', 'legal-entity-marks'); ?> <?php echo esc_html($removable_posts); ?> <?php echo esc_html__('статьях.', 'legal-entity-marks'); ?>
            <?php if ($removable_links < $total_flagged_links) : ?>
                <?php echo esc_html__('Остальные - ресурсы иноагентов, ссылаться на них не запрещено,
                чистка их не тронет.', 'legal-entity-marks'); ?>
            <?php endif; ?>
        </p>

        <?php if ($removable_links > 0) : ?>
        <p>
            <button type="button" class="button button-danger button-hero" id="lem-remove-all-links">
                <?php echo esc_html__('Удалить ссылки, подлежащие удалению (', 'legal-entity-marks'); ?><?php echo esc_html($removable_links); ?>)
            </button>
        </p>
        <?php endif; ?>

        <div id="lem-remove-progress" style="display:none;margin-top:20px">
            <div class="lem-progress-bar">
                <div class="lem-progress-fill" id="lem-remove-progress-fill" style="width:0%"></div>
            </div>
            <p id="lem-remove-status" class="description"></p>
        </div>

        <div id="lem-remove-result" style="display:none;margin-top:20px">
            <div class="notice notice-success inline">
                <p id="lem-remove-result-text"></p>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped" style="margin-top:15px" id="lem-results-table">
            <thead>
                <tr>
                    <th style="width:250px"><?php echo esc_html__('Статья', 'legal-entity-marks'); ?></th>
                    <th><?php echo esc_html__('URL ссылки', 'legal-entity-marks'); ?></th>
                    <th style="width:200px"><?php echo esc_html__('Текст ссылки', 'legal-entity-marks'); ?></th>
                    <th style="width:150px"><?php echo esc_html__('Совпавший ресурс', 'legal-entity-marks'); ?></th>
                    <th style="width:120px"><?php echo esc_html__('Реестр', 'legal-entity-marks'); ?></th>
                    <th style="width:120px"><?php echo esc_html__('Действие', 'legal-entity-marks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $prev_post_id = 0;
                foreach ($results_rows as $row) :
                    $is_first = ($row['post_id'] !== $prev_post_id);
                    $prev_post_id = $row['post_id'];
                ?>
                <tr data-post-id="<?php echo esc_attr($row['post_id']); ?>">
                    <td>
                        <?php if ($is_first) : ?>
                        <a href="<?php echo esc_url(get_edit_post_link($row['post_id'])); ?>" target="_blank">
                            <?php echo esc_html($row['post_title']); ?>
                        </a>
                        <?php endif; ?>
                    </td>
                    <td><span class="lem-banned-link-url"><?php echo esc_html($row['url']); ?></span></td>
                    <td><?php echo esc_html($row['anchor']); ?></td>
                    <td><code><?php echo esc_html($row['matched_domain']); ?></code></td>
                    <td>
                        <?php echo esc_html($type_labels[$row['registry'] ?: 'manual'] ?? $row['registry']); ?>
                        <?php if (!$row['removable']) : ?>
                            <br><span style="color:#777;font-size:11px"><?php echo esc_html__('не удаляется', 'legal-entity-marks'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_first) : ?>
                        <button type="button" class="button button-small lem-remove-post-links"
                                data-post-id="<?php echo esc_attr($row['post_id']); ?>"><?php echo esc_html__('Удалить ссылки', 'legal-entity-marks'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($scan_state && $scan_state['status'] === 'complete') : ?>
    <div class="lem-card">
        <h2><?php echo esc_html__('Результаты', 'legal-entity-marks'); ?></h2>
        <p><?php echo esc_html__('Ссылок на ресурсы из реестров не найдено.', 'legal-entity-marks'); ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- Модалка: добавить/редактировать домен -->
<div id="lem-site-modal" class="lem-modal" style="display:none">
    <div class="lem-modal-content">
        <h2 id="lem-site-modal-title"><?php echo esc_html__('Добавить домен', 'legal-entity-marks'); ?></h2>
        <input type="hidden" id="lem-site-id" value="0">
        <table class="form-table">
            <tr>
                <th><label for="lem-site-domain"><?php echo esc_html__('Домен или аккаунт', 'legal-entity-marks'); ?></label></th>
                <td>
                    <input type="text" id="lem-site-domain" class="regular-text" placeholder="<?php echo esc_attr__('example.org или t.me/doxajournal', 'legal-entity-marks'); ?>" required>
                    <p class="description">
                        <?php echo esc_html__('Можно указать URL целиком. Для обычного сайта помечается весь домен,
                        для телеграма, YouTube и соцсетей - только указанный аккаунт.', 'legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="lem-site-registry"><?php echo esc_html__('Реестр', 'legal-entity-marks'); ?></label></th>
                <td>
                    <select id="lem-site-registry">
                        <option value=""><?php echo esc_html__('Не указан', 'legal-entity-marks'); ?></option>
                        <option value="inoagent"><?php echo esc_html__('Иностранные агенты', 'legal-entity-marks'); ?></option>
                        <option value="extremist"><?php echo esc_html__('Экстремистские организации', 'legal-entity-marks'); ?></option>
                        <option value="terrorist"><?php echo esc_html__('Террористические организации', 'legal-entity-marks'); ?></option>
                        <option value="undesirable"><?php echo esc_html__('Нежелательные организации', 'legal-entity-marks'); ?></option>
                    </select>
                    <p class="description">
                        <?php echo esc_html__('От реестра зависит, убирать ли такие ссылки из текста.
                        Без указания ссылки на домен считаются подлежащими удалению: раз внесли руками,
                        значит, ссылаться на него не собираетесь.', 'legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="lem-site-label"><?php echo esc_html__('Название организации', 'legal-entity-marks'); ?></label></th>
                <td><input type="text" id="lem-site-label" class="regular-text"></td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="lem-save-site"><?php echo esc_html__('Сохранить', 'legal-entity-marks'); ?></button>
            <button type="button" class="button" id="lem-cancel-site"><?php echo esc_html__('Отмена', 'legal-entity-marks'); ?></button>
            <span id="lem-site-status-msg" class="lem-inline-status"></span>
        </p>
    </div>
</div>

<!-- Модалка: массовый импорт -->
<div id="lem-import-modal" class="lem-modal" style="display:none">
    <div class="lem-modal-content">
        <h2><?php echo esc_html__('Массовый импорт доменов', 'legal-entity-marks'); ?></h2>
        <p class="description"><?php echo esc_html__('По одному домену на строку. Формат:', 'legal-entity-marks'); ?> <code><?php echo esc_html__('домен | Название организации', 'legal-entity-marks'); ?></code> <?php echo esc_html__('(название необязательно).', 'legal-entity-marks'); ?></p>
        <textarea id="lem-import-domains" rows="10" class="large-text" placeholder="<?php echo esc_attr__('example.org | Организация X
another-site.com', 'legal-entity-marks'); ?>"></textarea>
        <p>
            <button type="button" class="button button-primary" id="lem-do-import"><?php echo esc_html__('Импортировать', 'legal-entity-marks'); ?></button>
            <button type="button" class="button" id="lem-cancel-import"><?php echo esc_html__('Отмена', 'legal-entity-marks'); ?></button>
            <span id="lem-import-status-msg" class="lem-inline-status"></span>
        </p>
    </div>
</div>

<script>
(function() {
    var cfg = window.lemAdmin || {};
    var scanning = false, removing = false;

    /* --- Реестр доменов: CRUD --- */
    var siteModal = document.getElementById('lem-site-modal');
    var importModal = document.getElementById('lem-import-modal');

    function openSiteModal(site) {
        document.getElementById('lem-site-modal-title').textContent = site ? <?php echo wp_json_encode(__('Редактирование', 'legal-entity-marks')); ?> : <?php echo wp_json_encode(__('Добавить домен', 'legal-entity-marks')); ?>;
        document.getElementById('lem-site-id').value = site ? site.id : 0;
        // Аккаунт показываем вместе с площадкой, иначе при сохранении он потеряется
        document.getElementById('lem-site-domain').value = site
            ? (site.account ? site.domain + '/' + site.account : site.domain)
            : '';
        document.getElementById('lem-site-label').value = site ? (site.label || '') : '';
        document.getElementById('lem-site-registry').value = site ? (site.registry || '') : '';
        document.getElementById('lem-site-status-msg').textContent = '';
        siteModal.style.display = 'flex';
    }

    document.getElementById('lem-add-site')?.addEventListener('click', function() { openSiteModal(null); });
    document.getElementById('lem-cancel-site')?.addEventListener('click', function() { siteModal.style.display = 'none'; });

    document.querySelectorAll('.lem-edit-site').forEach(function(btn) {
        btn.addEventListener('click', function() { openSiteModal(JSON.parse(this.dataset.site)); });
    });

    document.getElementById('lem-save-site')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('lem-site-status-msg');
        btn.disabled = true; st.textContent = <?php echo wp_json_encode(__('Сохранение...', 'legal-entity-marks')); ?>;

        var body = new URLSearchParams({
            action: 'lem_save_banned_site', nonce: cfg.crudNonce,
            id: document.getElementById('lem-site-id').value,
            domain: document.getElementById('lem-site-domain').value,
            registry: document.getElementById('lem-site-registry').value,
            label: document.getElementById('lem-site-label').value
        });

        fetch(cfg.ajaxUrl, { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.success) { siteModal.style.display = 'none'; location.reload(); }
            else { st.textContent = d.data || <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; }
        })
        .catch(function() { st.textContent = <?php echo wp_json_encode(__('Ошибка сети', 'legal-entity-marks')); ?>; btn.disabled = false; });
    });

    document.querySelectorAll('.lem-delete-site').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm(<?php echo wp_json_encode(__('Удалить этот домен из реестра?', 'legal-entity-marks')); ?>)) return;
            var id = this.dataset.id;
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=lem_delete_banned_site&nonce=' + cfg.crudNonce + '&id=' + id
            })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.success) location.reload(); });
        });
    });

    /* --- Массовый импорт --- */
    document.getElementById('lem-import-sites')?.addEventListener('click', function() {
        document.getElementById('lem-import-domains').value = '';
        document.getElementById('lem-import-status-msg').textContent = '';
        importModal.style.display = 'flex';
    });
    document.getElementById('lem-cancel-import')?.addEventListener('click', function() { importModal.style.display = 'none'; });

    document.getElementById('lem-do-import')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('lem-import-status-msg');
        var domains = document.getElementById('lem-import-domains').value.trim();
        if (!domains) { st.textContent = <?php echo wp_json_encode(__('Введите хотя бы один домен', 'legal-entity-marks')); ?>; return; }

        btn.disabled = true; st.textContent = <?php echo wp_json_encode(__('Импорт...', 'legal-entity-marks')); ?>;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_import_banned_sites&nonce=' + cfg.crudNonce + '&domains=' + encodeURIComponent(domains)
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.success) {
                st.textContent = d.data.message;
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                st.textContent = d.data || <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>;
            }
        })
        .catch(function() { st.textContent = <?php echo wp_json_encode(__('Ошибка сети', 'legal-entity-marks')); ?>; btn.disabled = false; });
    });

    /* --- Сканер ссылок --- */
    function startLinkScan() {
        if (scanning) return;
        scanning = true;

        var progress = document.getElementById('lem-link-scan-progress');
        var result = document.getElementById('lem-link-scan-result');
        var fill = document.getElementById('lem-link-progress-fill');
        var status = document.getElementById('lem-link-scan-status');
        var cancel = document.getElementById('lem-link-scan-cancel');

        progress.style.display = 'block';
        result.style.display = 'none';
        cancel.style.display = 'inline-block';
        fill.style.width = '0%';
        status.textContent = <?php echo wp_json_encode(__('Инициализация...', 'legal-entity-marks')); ?>;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_banned_scan_init&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { status.textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + (d.data || <?php echo wp_json_encode(__('Неизвестная', 'legal-entity-marks')); ?>); scanning = false; return; }
            processLinkScanBatch();
        })
        .catch(function(e) { status.textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + e.message; scanning = false; });
    }

    function processLinkScanBatch() {
        if (!scanning) return;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_banned_scan_process&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { document.getElementById('lem-link-scan-status').textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; scanning = false; return; }
            var state = d.data;
            var pct = state.total > 0 ? Math.round(state.offset / state.total * 100) : 0;
            document.getElementById('lem-link-progress-fill').style.width = pct + '%';
            document.getElementById('lem-link-scan-status').textContent =
                state.offset + ' / ' + state.total + <?php echo wp_json_encode(__(' статей (', 'legal-entity-marks')); ?> + pct + '%) - ' +
                state.posts_with_links + <?php echo wp_json_encode(__(' со ссылками, ', 'legal-entity-marks')); ?> + state.total_links + <?php echo wp_json_encode(__(' ссылок', 'legal-entity-marks')); ?>;

            if (state.status === 'complete') {
                document.getElementById('lem-link-progress-fill').style.width = '100%';
                document.getElementById('lem-link-scan-cancel').style.display = 'none';
                document.getElementById('lem-link-scan-result').style.display = 'block';
                document.getElementById('lem-link-scan-result-text').textContent =
                    <?php echo wp_json_encode(__('Сканирование завершено: ', 'legal-entity-marks')); ?> + state.posts_with_links + <?php echo wp_json_encode(__(' статей со ссылками, ', 'legal-entity-marks')); ?> +
                    state.total_links + <?php echo wp_json_encode(__(' ссылок', 'legal-entity-marks')); ?>;
                scanning = false;
                setTimeout(function() { location.reload(); }, 2000);
            } else if (state.status === 'cancelled') {
                document.getElementById('lem-link-scan-status').textContent = <?php echo wp_json_encode(__('Отменено.', 'legal-entity-marks')); ?>;
                document.getElementById('lem-link-scan-cancel').style.display = 'none';
                scanning = false;
            } else {
                setTimeout(processLinkScanBatch, 100);
            }
        })
        .catch(function(e) { document.getElementById('lem-link-scan-status').textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + e.message; scanning = false; });
    }

    document.getElementById('lem-link-scan-all')?.addEventListener('click', function() { startLinkScan(); });
    document.getElementById('lem-link-scan-cancel')?.addEventListener('click', function() {
        scanning = false;
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_banned_scan_cancel&nonce=' + cfg.nonce
        });
    });

    /* --- Удаление ссылок из одного поста --- */
    document.querySelectorAll('.lem-remove-post-links').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var postId = this.dataset.postId;
            if (!confirm(<?php echo wp_json_encode(__('Удалить из этой статьи ссылки, подлежащие удалению? Тег <a> будет заменён текстом ссылки. Ресурсы иноагентов останутся.', 'legal-entity-marks')); ?>)) return;
            btn.disabled = true; btn.textContent = '...';

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=lem_banned_remove_post&nonce=' + cfg.nonce + '&post_id=' + postId
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    var rows = document.querySelectorAll('tr[data-post-id="' + postId + '"]');
                    rows.forEach(function(row) { row.style.display = 'none'; });
                    btn.textContent = <?php echo wp_json_encode(__('Готово', 'legal-entity-marks')); ?>;
                } else {
                    btn.textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; btn.disabled = false;
                }
            })
            .catch(function() { btn.textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; btn.disabled = false; });
        });
    });

    /* --- Массовое удаление всех ссылок --- */
    document.getElementById('lem-remove-all-links')?.addEventListener('click', function() {
        // Считаем только то, что действительно уйдёт: ресурсы иноагентов остаются
        var total = <?php echo esc_html($removable_links); ?>;
        var posts = <?php echo esc_html($removable_posts); ?>;
        if (!confirm(<?php echo wp_json_encode(__('Будут удалены ', 'legal-entity-marks')); ?> + total + <?php echo wp_json_encode(__(' ссылок из ', 'legal-entity-marks')); ?> + posts + <?php echo wp_json_encode(__(' статей.
Тег <a> будет заменён текстом ссылки.
Ссылки на ресурсы иноагентов не затрагиваются.

Продолжить?', 'legal-entity-marks')); ?>)) return;

        if (removing) return;
        removing = true;

        var progress = document.getElementById('lem-remove-progress');
        var result = document.getElementById('lem-remove-result');
        var fill = document.getElementById('lem-remove-progress-fill');
        var status = document.getElementById('lem-remove-status');

        progress.style.display = 'block';
        result.style.display = 'none';
        fill.style.width = '0%';
        status.textContent = <?php echo wp_json_encode(__('Инициализация удаления...', 'legal-entity-marks')); ?>;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_banned_remove_init&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { status.textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + (d.data || <?php echo wp_json_encode(__('Неизвестная', 'legal-entity-marks')); ?>); removing = false; return; }
            processRemoveBatch(d.data.total);
        })
        .catch(function(e) { status.textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + e.message; removing = false; });
    });

    function processRemoveBatch(total) {
        if (!removing) return;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_banned_remove_process&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { document.getElementById('lem-remove-status').textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; removing = false; return; }
            var state = d.data;
            var pct = total > 0 ? Math.round(state.cleaned / total * 100) : 100;
            document.getElementById('lem-remove-progress-fill').style.width = pct + '%';
            document.getElementById('lem-remove-status').textContent =
                <?php echo wp_json_encode(__('Очищено ', 'legal-entity-marks')); ?> + state.cleaned + ' / ' + total + <?php echo wp_json_encode(__(' статей (', 'legal-entity-marks')); ?> + pct + '%)';

            if (state.status === 'complete') {
                document.getElementById('lem-remove-progress-fill').style.width = '100%';
                document.getElementById('lem-remove-result').style.display = 'block';
                document.getElementById('lem-remove-result-text').textContent =
                    <?php echo wp_json_encode(__('Удаление завершено: очищено ', 'legal-entity-marks')); ?> + state.cleaned + <?php echo wp_json_encode(__(' статей.', 'legal-entity-marks')); ?>;
                removing = false;
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                setTimeout(function() { processRemoveBatch(total); }, 100);
            }
        })
        .catch(function(e) { document.getElementById('lem-remove-status').textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + e.message; removing = false; });
    }
})();
</script>
