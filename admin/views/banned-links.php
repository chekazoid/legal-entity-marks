<?php defined('ABSPATH') || exit;

$sites  = lem()->banned_sites->get_all();
$search = sanitize_text_field($_GET['s'] ?? '');

// Результаты сканирования
global $wpdb;
$flagged_posts = $wpdb->get_results($wpdb->prepare(
    "SELECT p.ID, p.post_title, pm.meta_value
     FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
     WHERE pm.meta_key = %s AND pm.meta_value != ''
     ORDER BY p.ID DESC
     LIMIT 1000",
    LEM_BANNED_LINKS_META_KEY
));

$total_flagged_posts = count($flagged_posts);
$total_flagged_links = 0;
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
        ];
        $total_flagged_links++;
    }
}

$scan_state = get_transient(LEM_Link_Scanner::SCAN_STATE_KEY);
?>
<div class="wrap">
    <h1>Запрещённые ссылки</h1>

    <!-- Секция 1: Реестр доменов -->
    <div class="lem-card" style="margin-bottom:20px">
        <h2>Реестр запрещённых доменов</h2>
        <p class="description">Домены сайтов экстремистских, террористических и нежелательных организаций.</p>

        <p>
            <button type="button" class="button button-primary" id="lem-add-site">Добавить домен</button>
            <button type="button" class="button" id="lem-import-sites">Массовый импорт</button>
            <span style="margin-left:10px;color:#666">Всего доменов: <strong><?php echo count($sites); ?></strong></span>
        </p>

        <?php if (!empty($sites)) : ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:10px">
            <thead>
                <tr>
                    <th style="width:40px">ID</th>
                    <th>Домен</th>
                    <th>Название организации</th>
                    <th style="width:140px">Добавлен</th>
                    <th style="width:140px">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $site) : ?>
                <tr data-id="<?php echo esc_attr($site['id']); ?>">
                    <td><?php echo esc_html($site['id']); ?></td>
                    <td><code><?php echo esc_html($site['domain']); ?></code></td>
                    <td><?php echo esc_html($site['label']); ?></td>
                    <td><?php echo esc_html($site['added_at'] ? date('d.m.Y', strtotime($site['added_at'])) : ''); ?></td>
                    <td>
                        <button type="button" class="button button-small lem-edit-site"
                                data-site="<?php echo esc_attr(wp_json_encode($site)); ?>">Изменить</button>
                        <button type="button" class="button button-small button-link-delete lem-delete-site"
                                data-id="<?php echo esc_attr($site['id']); ?>">Удалить</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p>Реестр пуст. Добавьте домены вручную или через массовый импорт.</p>
        <?php endif; ?>
    </div>

    <!-- Секция 2: Сканер ссылок -->
    <div class="lem-card" style="margin-bottom:20px">
        <h2>Сканер ссылок</h2>
        <p class="description">
            Сканирует все опубликованные статьи на наличие ссылок на запрещённые домены.
        </p>

        <p>
            <button type="button" class="button button-primary button-hero" id="lem-link-scan-all"
                    <?php echo empty($sites) ? 'disabled' : ''; ?>>
                Сканировать все статьи
            </button>
        </p>

        <div id="lem-link-scan-progress" style="display:none;margin-top:20px">
            <div class="lem-progress-bar">
                <div class="lem-progress-fill" id="lem-link-progress-fill" style="width:0%"></div>
            </div>
            <p id="lem-link-scan-status" class="description"></p>
            <p>
                <button type="button" class="button" id="lem-link-scan-cancel" style="display:none">Отменить</button>
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
                    'Последний скан: %s &mdash; %d статей со ссылками, %d ссылок всего',
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
    <div class="lem-card">
        <h2>Найденные запрещённые ссылки</h2>
        <p>
            Найдено <strong><?php echo $total_flagged_links; ?></strong> запрещённых ссылок
            в <strong><?php echo $total_flagged_posts; ?></strong> статьях.
        </p>

        <p>
            <button type="button" class="button button-danger button-hero" id="lem-remove-all-links">
                Удалить все запрещённые ссылки
            </button>
        </p>

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
                    <th style="width:250px">Статья</th>
                    <th>URL ссылки</th>
                    <th style="width:200px">Текст ссылки</th>
                    <th style="width:150px">Совпавший домен</th>
                    <th style="width:120px">Действие</th>
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
                        <?php if ($is_first) : ?>
                        <button type="button" class="button button-small lem-remove-post-links"
                                data-post-id="<?php echo esc_attr($row['post_id']); ?>">Удалить ссылки</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($scan_state && $scan_state['status'] === 'complete') : ?>
    <div class="lem-card">
        <h2>Результаты</h2>
        <p>Запрещённых ссылок не найдено.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Модалка: добавить/редактировать домен -->
<div id="lem-site-modal" class="lem-modal" style="display:none">
    <div class="lem-modal-content">
        <h2 id="lem-site-modal-title">Добавить домен</h2>
        <input type="hidden" id="lem-site-id" value="0">
        <table class="form-table">
            <tr>
                <th><label for="lem-site-domain">Домен</label></th>
                <td>
                    <input type="text" id="lem-site-domain" class="regular-text" placeholder="example.org" required>
                    <p class="description">Можно указать URL - домен извлечётся автоматически.</p>
                </td>
            </tr>
            <tr>
                <th><label for="lem-site-label">Название организации</label></th>
                <td><input type="text" id="lem-site-label" class="regular-text"></td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="lem-save-site">Сохранить</button>
            <button type="button" class="button" id="lem-cancel-site">Отмена</button>
            <span id="lem-site-status-msg" class="lem-inline-status"></span>
        </p>
    </div>
</div>

<!-- Модалка: массовый импорт -->
<div id="lem-import-modal" class="lem-modal" style="display:none">
    <div class="lem-modal-content">
        <h2>Массовый импорт доменов</h2>
        <p class="description">По одному домену на строку. Формат: <code>домен | Название организации</code> (название необязательно).</p>
        <textarea id="lem-import-domains" rows="10" class="large-text" placeholder="example.org | Организация X&#10;another-site.com"></textarea>
        <p>
            <button type="button" class="button button-primary" id="lem-do-import">Импортировать</button>
            <button type="button" class="button" id="lem-cancel-import">Отмена</button>
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
        document.getElementById('lem-site-modal-title').textContent = site ? 'Редактирование' : 'Добавить домен';
        document.getElementById('lem-site-id').value = site ? site.id : 0;
        document.getElementById('lem-site-domain').value = site ? site.domain : '';
        document.getElementById('lem-site-label').value = site ? (site.label || '') : '';
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
        btn.disabled = true; st.textContent = 'Сохранение...';

        var body = new URLSearchParams({
            action: 'lem_save_banned_site', nonce: cfg.crudNonce,
            id: document.getElementById('lem-site-id').value,
            domain: document.getElementById('lem-site-domain').value,
            label: document.getElementById('lem-site-label').value
        });

        fetch(cfg.ajaxUrl, { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.success) { siteModal.style.display = 'none'; location.reload(); }
            else { st.textContent = d.data || 'Ошибка'; }
        })
        .catch(function() { st.textContent = 'Ошибка сети'; btn.disabled = false; });
    });

    document.querySelectorAll('.lem-delete-site').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Удалить этот домен из реестра?')) return;
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
        if (!domains) { st.textContent = 'Введите хотя бы один домен'; return; }

        btn.disabled = true; st.textContent = 'Импорт...';

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
                st.textContent = d.data || 'Ошибка';
            }
        })
        .catch(function() { st.textContent = 'Ошибка сети'; btn.disabled = false; });
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
        status.textContent = 'Инициализация...';

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_banned_scan_init&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { status.textContent = 'Ошибка: ' + (d.data || 'Неизвестная'); scanning = false; return; }
            processLinkScanBatch();
        })
        .catch(function(e) { status.textContent = 'Ошибка: ' + e.message; scanning = false; });
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
            if (!d.success) { document.getElementById('lem-link-scan-status').textContent = 'Ошибка'; scanning = false; return; }
            var state = d.data;
            var pct = state.total > 0 ? Math.round(state.offset / state.total * 100) : 0;
            document.getElementById('lem-link-progress-fill').style.width = pct + '%';
            document.getElementById('lem-link-scan-status').textContent =
                state.offset + ' / ' + state.total + ' статей (' + pct + '%) \u2014 ' +
                state.posts_with_links + ' со ссылками, ' + state.total_links + ' ссылок';

            if (state.status === 'complete') {
                document.getElementById('lem-link-progress-fill').style.width = '100%';
                document.getElementById('lem-link-scan-cancel').style.display = 'none';
                document.getElementById('lem-link-scan-result').style.display = 'block';
                document.getElementById('lem-link-scan-result-text').textContent =
                    'Сканирование завершено: ' + state.posts_with_links + ' статей со ссылками, ' +
                    state.total_links + ' ссылок';
                scanning = false;
                setTimeout(function() { location.reload(); }, 2000);
            } else if (state.status === 'cancelled') {
                document.getElementById('lem-link-scan-status').textContent = 'Отменено.';
                document.getElementById('lem-link-scan-cancel').style.display = 'none';
                scanning = false;
            } else {
                setTimeout(processLinkScanBatch, 100);
            }
        })
        .catch(function(e) { document.getElementById('lem-link-scan-status').textContent = 'Ошибка: ' + e.message; scanning = false; });
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
            if (!confirm('Удалить запрещённые ссылки из этой статьи? Тег <a> будет заменён текстом ссылки.')) return;
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
                    btn.textContent = 'Готово';
                } else {
                    btn.textContent = 'Ошибка'; btn.disabled = false;
                }
            })
            .catch(function() { btn.textContent = 'Ошибка'; btn.disabled = false; });
        });
    });

    /* --- Массовое удаление всех ссылок --- */
    document.getElementById('lem-remove-all-links')?.addEventListener('click', function() {
        var total = <?php echo $total_flagged_links; ?>;
        var posts = <?php echo $total_flagged_posts; ?>;
        if (!confirm('Будут удалены ' + total + ' ссылок из ' + posts + ' статей.\nТег <a> будет заменён текстом ссылки.\n\nПродолжить?')) return;

        if (removing) return;
        removing = true;

        var progress = document.getElementById('lem-remove-progress');
        var result = document.getElementById('lem-remove-result');
        var fill = document.getElementById('lem-remove-progress-fill');
        var status = document.getElementById('lem-remove-status');

        progress.style.display = 'block';
        result.style.display = 'none';
        fill.style.width = '0%';
        status.textContent = 'Инициализация удаления...';

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_banned_remove_init&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { status.textContent = 'Ошибка: ' + (d.data || 'Неизвестная'); removing = false; return; }
            processRemoveBatch(d.data.total);
        })
        .catch(function(e) { status.textContent = 'Ошибка: ' + e.message; removing = false; });
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
            if (!d.success) { document.getElementById('lem-remove-status').textContent = 'Ошибка'; removing = false; return; }
            var state = d.data;
            var pct = total > 0 ? Math.round(state.cleaned / total * 100) : 100;
            document.getElementById('lem-remove-progress-fill').style.width = pct + '%';
            document.getElementById('lem-remove-status').textContent =
                'Очищено ' + state.cleaned + ' / ' + total + ' статей (' + pct + '%)';

            if (state.status === 'complete') {
                document.getElementById('lem-remove-progress-fill').style.width = '100%';
                document.getElementById('lem-remove-result').style.display = 'block';
                document.getElementById('lem-remove-result-text').textContent =
                    'Удаление завершено: очищено ' + state.cleaned + ' статей.';
                removing = false;
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                setTimeout(function() { processRemoveBatch(total); }, 100);
            }
        })
        .catch(function(e) { document.getElementById('lem-remove-status').textContent = 'Ошибка: ' + e.message; removing = false; });
    }
})();
</script>
