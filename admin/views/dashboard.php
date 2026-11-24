<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <h1><?php echo esc_html__('Маркировка иноагентов и запрещённых организаций', 'legal-entity-marks'); ?></h1>

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
                <strong><?php echo esc_html__('Идёт проверка архива после обновления реестров.', 'legal-entity-marks'); ?></strong>
                <?php echo esc_html__('Просмотрено', 'legal-entity-marks'); ?> <?php echo (int) $rescan_state['offset']; ?>
                <?php echo esc_html__('из', 'legal-entity-marks'); ?> <?php echo (int) $rescan_state['total']; ?>
                (<?php echo (int) $rescan_state['percent']; ?>%).
                <?php if ($cron_off) : ?>
                    <br><strong><?php echo esc_html__('Планировщик не подаёт признаков жизни', 'legal-entity-marks'); ?></strong><?php echo esc_html__(': задачи
                    не выполнялись больше двух часов. Запустите проверку кнопкой ниже
                    или настройте системный cron на выполнение задач WordPress.', 'legal-entity-marks'); ?>
                <?php elseif ($next_run) : ?>
                    <?php echo esc_html__('Следующая порция:', 'legal-entity-marks'); ?> <?php echo esc_html(date_i18n('H:i:s', $next_run + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS))); ?><?php echo esc_html__('.
                    Работа идёт частями в фоне, страницу можно закрыть.', 'legal-entity-marks'); ?>
                    <?php if ($cron_last) : ?>
                        <?php echo esc_html__('Планировщик работает, последняя задача:', 'legal-entity-marks'); ?>
                        <?php echo esc_html(human_time_diff($cron_last) . __(' назад', 'legal-entity-marks')); ?>.
                    <?php endif; ?>
                <?php else : ?>
                    <?php echo esc_html__('Задача в расписании не найдена, запустите проверку вручную.', 'legal-entity-marks'); ?>
                <?php endif; ?>
                <?php
                // Попытки идут, а счётчик стоит: запуск не доживает до конца.
                // Чаще всего это нехватка памяти на большом материале
                $attempts = (int) ($rescan_state['attempts'] ?? 0);
                if ($attempts >= 3 && (int) $rescan_state['offset'] === 0) : ?>
                    <br><strong><?php echo esc_html__('Попыток было', 'legal-entity-marks'); ?> <?php echo esc_html($attempts); ?><?php echo esc_html__(', а прогресса нет.', 'legal-entity-marks'); ?></strong>
                    <?php echo esc_html__('Похоже, проверка обрывается на первой же порции: обычно это нехватка
                    памяти. Помогает увеличение', 'legal-entity-marks'); ?> <code>WP_MEMORY_LIMIT</code><?php echo esc_html__(', а до того
                    маркировка продолжает работать по прежним результатам.', 'legal-entity-marks'); ?>
                <?php endif; ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="lem-btn-rescan">
                    <?php echo esc_html__('Проверить архив сейчас', 'legal-entity-marks'); ?>
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
                <strong><?php echo esc_html__('Проверка архива завершена', 'legal-entity-marks'); ?> <?php echo esc_html($update_report['at']); ?>.</strong>
                <?php echo esc_html__('Просмотрено материалов:', 'legal-entity-marks'); ?> <?php echo (int) $update_report['posts_scanned']; ?><?php echo esc_html__(',
                из них с упоминаниями:', 'legal-entity-marks'); ?> <?php echo (int) $update_report['with_matches']; ?>.
                <?php if ((int) $update_report['links'] > 0) : ?>
                    <?php echo esc_html__('Ссылок на ресурсы из реестров:', 'legal-entity-marks'); ?> <?php echo (int) $update_report['links']; ?>
                    <?php echo esc_html__('в', 'legal-entity-marks'); ?> <?php echo (int) $update_report['with_links']; ?> <?php echo esc_html__('материалах.', 'legal-entity-marks'); ?>
                <?php endif; ?>
                <br><?php echo esc_html__('Кого принесло очередным обновлением реестров, будет показано здесь же
                после ближайшего обновления.', 'legal-entity-marks'); ?>
            </p>
        </div>
    <?php elseif (!empty($update_report['at'])) : ?>
        <div class="notice notice-<?php echo (int) $update_report['mentioned'] > 0 ? 'warning' : 'success'; ?> inline"
             style="margin:0 0 16px">
            <p>
                <strong><?php echo esc_html__('Проверка архива завершена', 'legal-entity-marks'); ?> <?php echo esc_html($update_report['at']); ?>.</strong>
                <?php echo esc_html__('Новых записей в реестрах:', 'legal-entity-marks'); ?> <?php echo (int) $update_report['new_entities']; ?>.
                <?php if ((int) $update_report['mentioned'] > 0) : ?>
                    <?php echo esc_html__('Из них встречаются на сайте:', 'legal-entity-marks'); ?> <strong><?php echo (int) $update_report['mentioned']; ?></strong>
                    <?php echo esc_html__('в', 'legal-entity-marks'); ?> <?php echo (int) $update_report['mentioned_in']; ?> <?php echo esc_html__('материалах.', 'legal-entity-marks'); ?>
                    <a href="<?php echo esc_url($report_url); ?>"><?php echo esc_html__('Посмотреть', 'legal-entity-marks'); ?></a>
                <?php else : ?>
                    <?php echo esc_html__('В материалах сайта ни одна из них не встречается.', 'legal-entity-marks'); ?>
                <?php endif; ?>
                <?php if (!empty($update_report['names'])) : ?>
                    <br><span style="color:#666"><?php echo esc_html__('Новички:', 'legal-entity-marks'); ?>
                    <?php echo esc_html(implode(', ', array_slice($update_report['names'], 0, 5))); ?><?php
                        echo count($update_report['names']) > 5 ? esc_html__(' и другие', 'legal-entity-marks') : ''; ?></span>
                <?php endif; ?>
                <?php if ((int) $update_report['links'] > 0) : ?>
                    <br><?php echo esc_html__('Ссылок на ресурсы из реестров:', 'legal-entity-marks'); ?> <strong><?php echo (int) $update_report['links']; ?></strong>
                    <?php echo esc_html__('в', 'legal-entity-marks'); ?> <?php echo (int) $update_report['with_links']; ?> <?php echo esc_html__('материалах.', 'legal-entity-marks'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=lem-banned-links')); ?>"><?php echo esc_html__('Посмотреть', 'legal-entity-marks'); ?></a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="lem-dashboard-grid">
        <?php
        $counts     = lem()->entities->count_by_type();
        $last_fetch = get_option('lem_last_fetch_time', __('никогда', 'legal-entity-marks'));
        $version    = get_option('lem_list_version', __('не задана', 'legal-entity-marks'));
        $error      = get_option('lem_last_fetch_error', '');

        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $marked_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", LEM_META_KEY
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */

        $banned_sites_count = lem()->banned_sites->count();
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $banned_links_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            LEM_BANNED_LINKS_META_KEY
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
        // Из них те, где ссылки действительно полагается убирать
        $removable_posts = count(LEM_Link_Scanner::posts_with_removable());
        ?>

        <div class="lem-card">
            <h2><?php echo esc_html__('Реестры', 'legal-entity-marks'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Тип', 'legal-entity-marks'); ?></th>
                        <th><?php echo esc_html__('Активных', 'legal-entity-marks'); ?></th>
                        <th><?php echo esc_html__('Исключённых', 'legal-entity-marks'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $types = [
                        'inoagent'    => __('Иностранные агенты', 'legal-entity-marks'),
                        'extremist'   => __('Экстремистские орг.', 'legal-entity-marks'),
                        'terrorist'   => __('Террористические орг.', 'legal-entity-marks'),
                        'undesirable' => __('Нежелательные орг.', 'legal-entity-marks'),
                        'fedsfm_person' => __('Физлица: Росфинмониторинг', 'legal-entity-marks'),
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
                        <td><strong><?php echo esc_html__('Итого', 'legal-entity-marks'); ?></strong></td>
                        <td><strong><?php echo esc_html($total_active); ?></strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="lem-card">
            <h2><?php echo esc_html__('Статус', 'legal-entity-marks'); ?></h2>
            <ul class="lem-status-list">
                <li>
                    <span class="lem-label"><?php echo esc_html__('Статей с маркировкой:', 'legal-entity-marks'); ?></span>
                    <strong><?php echo esc_html($marked_posts); ?></strong>
                </li>
                <li>
                    <span class="lem-label"><?php echo esc_html__('Ресурсов в реестре:', 'legal-entity-marks'); ?></span>
                    <strong><?php echo esc_html($banned_sites_count); ?></strong>
                </li>
                <li>
                    <span class="lem-label"><?php echo esc_html__('Статей со ссылками на них:', 'legal-entity-marks'); ?></span>
                    <strong><?php echo esc_html($banned_links_posts); ?></strong>
                    <?php if ($removable_posts !== $banned_links_posts) : ?>
                        <span style="color:#777"><?php echo esc_html__(', из них подлежат чистке:', 'legal-entity-marks'); ?>
                        <?php echo (int) $removable_posts; ?></span>
                    <?php endif; ?>
                </li>
                <li>
                    <span class="lem-label"><?php echo esc_html__('Версия списков:', 'legal-entity-marks'); ?></span>
                    <code><?php echo esc_html($version); ?></code>
                </li>
                <li>
                    <span class="lem-label"><?php echo esc_html__('Последнее обновление:', 'legal-entity-marks'); ?></span>
                    <?php echo esc_html($last_fetch); ?>
                </li>
                <?php
                $health = (array) get_option('lem_channel_health', []);
                if (!empty($health['checked_at'])) : ?>
                <li>
                    <span class="lem-label"><?php echo esc_html__('Канал реестров:', 'legal-entity-marks'); ?></span>
                    <?php if (!empty($health['ok'])) : ?>
                        <?php echo esc_html__('доступен', 'legal-entity-marks'); ?><?php if (!empty($health['last_snapshot'])) : ?><?php echo esc_html__(',
                        срез от', 'legal-entity-marks'); ?> <?php echo esc_html(date_i18n('d.m.Y',
                            strtotime($health['last_snapshot']))); ?><?php endif; ?>
                    <?php else : ?>
                        <span style="color:#b32d2e"><?php echo esc_html__('недоступен', 'legal-entity-marks'); ?></span>
                        <?php echo esc_html($health['error']); ?>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
                <?php if (LEM_People::enabled($settings ?? lem()->get_settings())) :
                    $people_state = (array) get_option(LEM_People::STATE, []);
                    $people_success = (array) get_option(LEM_People::SUCCESS, []); ?>
                <li>
                    <span class="lem-label"><?php echo esc_html__('Физлица Росфинмониторинга:', 'legal-entity-marks'); ?></span>
                    <?php if (!empty($people_state['error'])) : ?>
                        <span class="lem-error"><?php echo esc_html($people_state['error']); ?></span>
                    <?php elseif (!empty($people_state['running'])) : ?>
                        <?php echo esc_html__('загружаются в фоне:', 'legal-entity-marks'); ?> <?php echo (int) $people_state['offset']; ?> <?php echo esc_html__('из', 'legal-entity-marks'); ?> <?php echo (int) $people_state['total']; ?> <?php echo esc_html__('ФИО', 'legal-entity-marks'); ?>
                    <?php elseif (!empty($people_success['synced_at'])) : ?>
                        <?php echo esc_html__('загружены', 'legal-entity-marks'); ?> <?php echo esc_html($people_success['synced_at']); ?><?php echo esc_html__(',
                        срез от', 'legal-entity-marks'); ?> <?php echo esc_html($people_success['fetched_at']); ?>
                    <?php else : ?>
                        <?php echo esc_html__('ещё не загружены', 'legal-entity-marks'); ?>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
                <?php
                // Откуда пришли данные: важно, когда официальный реестр молчит
                $sources = (array) get_option('lem_last_fetch_sources', []);
                $src_names = [
                    'official' => __('официальный реестр', 'legal-entity-marks'),
                    'mirror'   => __('зеркало перечня', 'legal-entity-marks'),
                    'channel'  => __('канал реестров', 'legal-entity-marks'),
                    'bundled'  => __('встроенный перечень', 'legal-entity-marks'),
                    'retained' => __('прежний подтверждённый список', 'legal-entity-marks'),
                    'none'     => __('данных нет', 'legal-entity-marks'),
                ];
                $non_official = array_filter($sources, static function ($v) {
                    return $v !== 'official';
                });
                if (!empty($sources)) : ?>
                <li>
                    <span class="lem-label"><?php echo esc_html__('Источник данных:', 'legal-entity-marks'); ?></span>
                    <?php if (empty($non_official)) : ?>
                        <?php echo esc_html__('официальные реестры', 'legal-entity-marks'); ?>
                    <?php else : ?>
                        <?php $parts = [];
                        foreach ($non_official as $type => $src) {
                            $parts[] = (LEM_Importer::REGISTRY_LABELS[$type] ?? $type)
                                . ' - ' . ($src_names[$src] ?? $src);
                        }
                        echo esc_html(implode('; ', $parts)); ?>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
                <?php
                $diagnostics = (array) get_option('lem_last_fetch_diagnostics', []);
                if (!empty($diagnostics)) : ?>
                <li>
                    <details>
                        <summary><?php echo esc_html__('Диагностика источников', 'legal-entity-marks'); ?></summary>
                        <?php foreach ($diagnostics as $type => $reasons) : ?>
                        <p>
                            <strong><?php echo esc_html(LEM_Importer::REGISTRY_LABELS[$type] ?? $type); ?>:</strong>
                            <?php echo esc_html(implode('; ', (array) $reasons)); ?>
                        </p>
                        <?php endforeach; ?>
                    </details>
                </li>
                <?php endif; ?>
                <?php if ($error) : ?>
                <li class="lem-error">
                    <span class="lem-label"><?php echo esc_html__('Последнее обновление прошло не полностью:', 'legal-entity-marks'); ?></span>
                    <?php echo esc_html($error); ?>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="lem-card">
            <h2><?php echo esc_html__('Быстрые действия', 'legal-entity-marks'); ?></h2>
            <p>
                <button type="button" class="button button-primary" id="lem-btn-fetch">
                    <?php echo esc_html__('Обновить реестры', 'legal-entity-marks'); ?>
                </button>
                <span id="lem-fetch-status" class="lem-inline-status"></span>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lem-scanner')); ?>" class="button">
                    <?php echo esc_html__('Запустить сканер', 'legal-entity-marks'); ?>
                </a>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lem-banned-links')); ?>" class="button">
                    <?php echo esc_html__('Проверить ссылки', 'legal-entity-marks'); ?>
                </a>
            </p>
            <p>
                <button type="button" class="button" id="lem-btn-purge">
                    <?php echo esc_html__('Очистить кеш', 'legal-entity-marks'); ?>
                </button>
                <span id="lem-purge-status" class="lem-inline-status"></span>
            </p>
            <p>
                <button type="button" class="button" id="lem-btn-rescan-start">
                    <?php echo esc_html__('Проверить архив', 'legal-entity-marks'); ?>
                </button>
                <span id="lem-rescan-start-status" class="lem-inline-status"></span>
            </p>
            <p>
                <button type="button" class="button" id="lem-btn-sources">
                    <?php echo esc_html__('Проверить источники', 'legal-entity-marks'); ?>
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
        st.textContent = <?php echo wp_json_encode(__('Обновление...', 'legal-entity-marks')); ?>;
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_fetch_registries&nonce=' + cfg.crudNonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            st.textContent = d.success ? (d.data.message || <?php echo wp_json_encode(__('Готово', 'legal-entity-marks')); ?>) : <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>;
            btn.disabled = false;
            if (d.success) setTimeout(function() { location.reload(); }, 1500);
        })
        .catch(function() { st.textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; btn.disabled = false; });
    });

    document.getElementById('lem-btn-sources')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('lem-sources-status'),
            box = document.getElementById('lem-sources-result');
        btn.disabled = true;
        st.textContent = <?php echo wp_json_encode(__('Опрашиваем...', 'legal-entity-marks')); ?>;
        box.style.display = 'none';
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_check_sources&nonce=' + cfg.crudNonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (!d.success) { st.textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; return; }
            var bad = d.data.sources.filter(function(s) { return !s.ok; }).length;
            st.textContent = bad ? <?php echo wp_json_encode(__('Недоступны: ', 'legal-entity-marks')); ?> + bad : <?php echo wp_json_encode(__('Все источники отвечают', 'legal-entity-marks')); ?>;
            var rows = d.data.sources.map(function(s) {
                return '<tr><td>' + (s.ok ? '\u2713' : '\u2717') + '</td>'
                     + '<td>' + s.name + '</td>'
                     + '<td style="color:#666">' + s.detail + '</td></tr>';
            }).join('');
            box.innerHTML = '<table class="widefat striped"><tbody>' + rows + '</tbody></table>'
                + <?php echo wp_json_encode(__('<p class="description">При недоступности официального источника плагин ', 'legal-entity-marks')); ?>
                + <?php echo wp_json_encode(__('пробует канал реестров, затем зеркало (если оно есть) и встроенный перечень. ', 'legal-entity-marks')); ?>
                + <?php echo wp_json_encode(__('Источник последней загрузки указан в блоке «Статус».</p>', 'legal-entity-marks')); ?>;
            box.style.display = '';
        })
        .catch(function() { st.textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; btn.disabled = false; });
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
                if (!d.success) { statusEl.textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; btn.disabled = false; return; }
                statusEl.textContent = d.data.offset + <?php echo wp_json_encode(__(' из ', 'legal-entity-marks')); ?> + d.data.total
                    + ' (' + d.data.percent + '%)';
                if (fill) fill.style.width = d.data.percent + '%';
                if (d.data.done) {
                    statusEl.textContent = <?php echo wp_json_encode(__('Проверка завершена', 'legal-entity-marks')); ?>;
                    setTimeout(function() { location.reload(); }, 1200);
                    return;
                }
                step(false);
            })
            .catch(function() { statusEl.textContent = <?php echo wp_json_encode(__('Ошибка сети', 'legal-entity-marks')); ?>; btn.disabled = false; });
        }
        step(start);
    }

    document.getElementById('lem-btn-rescan')?.addEventListener('click', function() {
        runRescan(this, document.getElementById('lem-rescan-status'), false);
    });

    document.getElementById('lem-btn-rescan-start')?.addEventListener('click', function() {
        if (!confirm(<?php echo wp_json_encode(__('Проверить весь архив на упоминания и ссылки на ресурсы из реестров? ', 'legal-entity-marks')); ?>
            + <?php echo wp_json_encode(__('Это может занять несколько минут, вкладку надо держать открытой.', 'legal-entity-marks')); ?>)) return;
        runRescan(this, document.getElementById('lem-rescan-start-status'), true);
    });

    document.getElementById('lem-btn-purge')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('lem-purge-status');
        btn.disabled = true;
        st.textContent = <?php echo wp_json_encode(__('Очистка...', 'legal-entity-marks')); ?>;
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_purge_cache&nonce=' + cfg.crudNonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            st.textContent = d.success ? <?php echo wp_json_encode(__('Очищено для ', 'legal-entity-marks')); ?> + d.data.purged + <?php echo wp_json_encode(__(' статей', 'legal-entity-marks')); ?> : <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>;
            btn.disabled = false;
        })
        .catch(function() { st.textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; btn.disabled = false; });
    });
})();
</script>
