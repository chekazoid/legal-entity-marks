<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <h1>Маркировка иноагентов и запрещённых организаций</h1>

    <?php
    $rescan_state  = lem()->rescan->status();
    $update_report = lem()->rescan->last_report();
    $report_url    = admin_url('admin.php?page=lem-report&fresh=update');
    ?>

    <?php if ($rescan_state && empty($rescan_state['done'])) :
        $next_run = wp_next_scheduled(LEM_Rescan::HOOK);
        // Судим по тому, выполняются ли задачи, а не по константе в конфиге:
        // на сервере с системным cron DISABLE_WP_CRON - это норма
        $cron_off  = !LEM_Cron::looks_alive();
        $cron_last = LEM_Cron::last_run();
    ?>
        <div class="notice notice-info inline" style="margin:0 0 16px">
            <p>
                <strong>Идёт проверка архива после обновления реестров.</strong>
                Просмотрено <?php echo (int) $rescan_state['offset']; ?>
                из <?php echo (int) $rescan_state['total']; ?>
                (<?php echo (int) $rescan_state['percent']; ?>%).
                <?php if ($cron_off) : ?>
                    <br><strong>Планировщик не подаёт признаков жизни</strong>: задачи
                    не выполнялись больше двух часов. Запустите проверку кнопкой ниже
                    или настройте системный cron на выполнение задач WordPress.
                <?php elseif ($next_run) : ?>
                    Следующая порция: <?php echo esc_html(date_i18n('H:i:s', $next_run + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS))); ?>.
                    Работа идёт частями в фоне, страницу можно закрыть.
                    <?php if ($cron_last) : ?>
                        Планировщик работает, последняя задача:
                        <?php echo esc_html(human_time_diff($cron_last) . ' назад'); ?>.
                    <?php endif; ?>
                <?php else : ?>
                    Задача в расписании не найдена, запустите проверку вручную.
                <?php endif; ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="lem-btn-rescan">
                    Проверить архив сейчас
                </button>
                <span id="lem-rescan-status" class="lem-inline-status"></span>
            </p>
            <div class="lem-progress-bar" id="lem-rescan-bar" style="display:none">
                <div class="lem-progress-fill" id="lem-rescan-fill" style="width:0%"></div>
            </div>
        </div>
    <?php elseif (!empty($update_report['at'])
        && ($update_report['trigger'] ?? '') !== 'registry-update') : ?>
        <div class="notice notice-success inline" style="margin:0 0 16px">
            <p>
                <strong>Проверка архива завершена <?php echo esc_html($update_report['at']); ?>.</strong>
                Просмотрено материалов: <?php echo (int) $update_report['posts_scanned']; ?>,
                из них с упоминаниями: <?php echo (int) $update_report['with_matches']; ?>.
                <?php if ((int) $update_report['links'] > 0) : ?>
                    Ссылок на ресурсы из реестров: <?php echo (int) $update_report['links']; ?>
                    в <?php echo (int) $update_report['with_links']; ?> материалах.
                <?php endif; ?>
                <br>Кого принесло очередным обновлением реестров, будет показано здесь же
                после ближайшего обновления.
            </p>
        </div>
    <?php elseif (!empty($update_report['at'])) : ?>
        <div class="notice notice-<?php echo (int) $update_report['mentioned'] > 0 ? 'warning' : 'success'; ?> inline"
             style="margin:0 0 16px">
            <p>
                <strong>Проверка архива завершена <?php echo esc_html($update_report['at']); ?>.</strong>
                Новых записей в реестрах: <?php echo (int) $update_report['new_entities']; ?>.
                <?php if ((int) $update_report['mentioned'] > 0) : ?>
                    Из них встречаются на сайте: <strong><?php echo (int) $update_report['mentioned']; ?></strong>
                    в <?php echo (int) $update_report['mentioned_in']; ?> материалах.
                    <a href="<?php echo esc_url($report_url); ?>">Посмотреть</a>
                <?php else : ?>
                    В материалах сайта ни одна из них не встречается.
                <?php endif; ?>
                <?php if (!empty($update_report['names'])) : ?>
                    <br><span style="color:#666">Новички:
                    <?php echo esc_html(implode(', ', array_slice($update_report['names'], 0, 5))); ?><?php
                        echo count($update_report['names']) > 5 ? ' и другие' : ''; ?></span>
                <?php endif; ?>
                <?php if ((int) $update_report['links'] > 0) : ?>
                    <br>Ссылок на ресурсы из реестров: <strong><?php echo (int) $update_report['links']; ?></strong>
                    в <?php echo (int) $update_report['with_links']; ?> материалах.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=lem-banned-links')); ?>">Посмотреть</a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="lem-dashboard-grid">
        <?php
        $counts     = lem()->entities->count_by_type();
        $last_fetch = get_option('lem_last_fetch_time', 'никогда');
        $version    = get_option('lem_list_version', 'не задана');
        $error      = get_option('lem_last_fetch_error', '');

        global $wpdb;
        $marked_posts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '" . LEM_META_KEY . "' AND meta_value != ''"
        );

        $banned_sites_count = lem()->banned_sites->count();
        $banned_links_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            LEM_BANNED_LINKS_META_KEY
        ));
        // Из них те, где ссылки действительно полагается убирать
        $removable_posts = count(LEM_Link_Scanner::posts_with_removable());
        ?>

        <div class="lem-card">
            <h2>Реестры</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Тип</th>
                        <th>Активных</th>
                        <th>Исключённых</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $types = [
                        'inoagent'    => 'Иностранные агенты',
                        'extremist'   => 'Экстремистские орг.',
                        'terrorist'   => 'Террористические орг.',
                        'undesirable' => 'Нежелательные орг.',
                    ];
                    $total_active = 0;
                    foreach ($types as $key => $label) :
                        $active  = $counts[$key] ?? 0;
                        $removed = $counts[$key . '_removed'] ?? 0;
                        $total_active += $active;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($label); ?></strong></td>
                            <td><?php echo esc_html($active); ?></td>
                            <td><?php echo esc_html($removed); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong>Итого</strong></td>
                        <td><strong><?php echo esc_html($total_active); ?></strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="lem-card">
            <h2>Статус</h2>
            <ul class="lem-status-list">
                <li>
                    <span class="lem-label">Статей с маркировкой:</span>
                    <strong><?php echo esc_html($marked_posts); ?></strong>
                </li>
                <li>
                    <span class="lem-label">Ресурсов в реестре:</span>
                    <strong><?php echo esc_html($banned_sites_count); ?></strong>
                </li>
                <li>
                    <span class="lem-label">Статей со ссылками на них:</span>
                    <strong><?php echo esc_html($banned_links_posts); ?></strong>
                    <?php if ($removable_posts !== $banned_links_posts) : ?>
                        <span style="color:#777">, из них подлежат чистке:
                        <?php echo (int) $removable_posts; ?></span>
                    <?php endif; ?>
                </li>
                <li>
                    <span class="lem-label">Версия списков:</span>
                    <code><?php echo esc_html($version); ?></code>
                </li>
                <li>
                    <span class="lem-label">Последнее обновление:</span>
                    <?php echo esc_html($last_fetch); ?>
                </li>
                <?php if ($error) : ?>
                <li class="lem-error">
                    <span class="lem-label">Последнее обновление прошло не полностью:</span>
                    <?php echo esc_html($error); ?>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="lem-card">
            <h2>Быстрые действия</h2>
            <p>
                <button type="button" class="button button-primary" id="lem-btn-fetch">
                    Обновить реестры
                </button>
                <span id="lem-fetch-status" class="lem-inline-status"></span>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lem-scanner')); ?>" class="button">
                    Запустить сканер
                </a>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lem-banned-links')); ?>" class="button">
                    Проверить ссылки
                </a>
            </p>
            <p>
                <button type="button" class="button" id="lem-btn-purge">
                    Очистить кеш
                </button>
                <span id="lem-purge-status" class="lem-inline-status"></span>
            </p>
            <p>
                <button type="button" class="button" id="lem-btn-rescan-start">
                    Проверить архив
                </button>
                <span id="lem-rescan-start-status" class="lem-inline-status"></span>
            </p>
            <p>
                <button type="button" class="button" id="lem-btn-sources">
                    Проверить источники
                </button>
                <span id="lem-sources-status" class="lem-inline-status"></span>
            </p>
            <div id="lem-sources-result" style="display:none;margin-top:8px"></div>
        </div>
    </div>
</div>

<script>
(function() {
    var cfg = window.lemAdmin || {};

    document.getElementById('lem-btn-fetch')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('lem-fetch-status');
        btn.disabled = true;
        st.textContent = 'Обновление...';
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_fetch_registries&nonce=' + cfg.crudNonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            st.textContent = d.success ? (d.data.message || 'Готово') : 'Ошибка';
            btn.disabled = false;
            if (d.success) setTimeout(function() { location.reload(); }, 1500);
        })
        .catch(function() { st.textContent = 'Ошибка'; btn.disabled = false; });
    });

    document.getElementById('lem-btn-sources')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('lem-sources-status'),
            box = document.getElementById('lem-sources-result');
        btn.disabled = true;
        st.textContent = 'Опрашиваем...';
        box.style.display = 'none';
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_check_sources&nonce=' + cfg.crudNonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (!d.success) { st.textContent = 'Ошибка'; return; }
            var bad = d.data.sources.filter(function(s) { return !s.ok; }).length;
            st.textContent = bad ? 'Недоступны: ' + bad : 'Все источники отвечают';
            var rows = d.data.sources.map(function(s) {
                return '<tr><td>' + (s.ok ? '\u2713' : '\u2717') + '</td>'
                     + '<td>' + s.name + '</td>'
                     + '<td style="color:#666">' + s.detail + '</td></tr>';
            }).join('');
            box.innerHTML = '<table class="widefat striped"><tbody>' + rows + '</tbody></table>'
                + '<p class="description">Если источник недоступен, плагин работает по '
                + 'встроенному перечню. Причина обычно на стороне хостинга: '
                + 'закрытые исходящие соединения или блокировка адреса.</p>';
            box.style.display = '';
        })
        .catch(function() { st.textContent = 'Ошибка'; btn.disabled = false; });
    });

    /* Прогон очереди из браузера: WP-Cron срабатывает только при заходах
       на сайт, а где-то отключён совсем */
    function runRescan(btn, statusEl, start) {
        var bar  = document.getElementById('lem-rescan-bar');
        var fill = document.getElementById('lem-rescan-fill');
        btn.disabled = true;
        if (bar) bar.style.display = '';

        function step(first) {
            var body = 'action=lem_rescan_step&nonce=' + cfg.crudNonce + (first ? '&start=1' : '');
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) { statusEl.textContent = 'Ошибка'; btn.disabled = false; return; }
                statusEl.textContent = d.data.offset + ' из ' + d.data.total
                    + ' (' + d.data.percent + '%)';
                if (fill) fill.style.width = d.data.percent + '%';
                if (d.data.done) {
                    statusEl.textContent = 'Проверка завершена';
                    setTimeout(function() { location.reload(); }, 1200);
                    return;
                }
                step(false);
            })
            .catch(function() { statusEl.textContent = 'Ошибка сети'; btn.disabled = false; });
        }
        step(start);
    }

    document.getElementById('lem-btn-rescan')?.addEventListener('click', function() {
        runRescan(this, document.getElementById('lem-rescan-status'), false);
    });

    document.getElementById('lem-btn-rescan-start')?.addEventListener('click', function() {
        if (!confirm('Проверить весь архив на упоминания и ссылки на ресурсы из реестров? '
            + 'Это может занять несколько минут, вкладку надо держать открытой.')) return;
        runRescan(this, document.getElementById('lem-rescan-start-status'), true);
    });

    document.getElementById('lem-btn-purge')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('lem-purge-status');
        btn.disabled = true;
        st.textContent = 'Очистка...';
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_purge_cache&nonce=' + cfg.crudNonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            st.textContent = d.success ? 'Очищено для ' + d.data.purged + ' статей' : 'Ошибка';
            btn.disabled = false;
        })
        .catch(function() { st.textContent = 'Ошибка'; btn.disabled = false; });
    });
})();
</script>
