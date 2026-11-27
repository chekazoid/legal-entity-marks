<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <h1><?php echo esc_html__('Маркировка иноагентов и запрещённых организаций', 'chekazoid-legal-entity-marks'); ?></h1>

    <?php
    $clem_rescan_state  = clem_plugin()->rescan->status();
    $clem_update_report = clem_plugin()->rescan->last_report();
    $clem_report_url    = admin_url('admin.php?page=clem-report&fresh=update');
    ?>

    <?php if ($clem_rescan_state && empty($clem_rescan_state['done'])) :
        $clem_next_run = wp_next_scheduled(CLEM_Rescan::HOOK);
        // Судим по тому, выполняются ли задачи, а не по константе в конфиге:
        // на сервере с системным cron DISABLE_WP_CRON - это норма
        $clem_cron_off  = !CLEM_Cron::looks_alive();
        $clem_cron_last = CLEM_Cron::last_run();
    ?>
        <div class="notice notice-info inline" style="margin:0 0 16px">
            <p>
                <strong><?php echo esc_html__('Идёт проверка архива после обновления реестров.', 'chekazoid-legal-entity-marks'); ?></strong>
                <?php echo esc_html__('Просмотрено', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_rescan_state['offset']; ?>
                <?php echo esc_html__('из', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_rescan_state['total']; ?>
                (<?php echo (int) $clem_rescan_state['percent']; ?>%).
                <?php if ($clem_cron_off) : ?>
                    <br><strong><?php echo esc_html__('Планировщик не подаёт признаков жизни', 'chekazoid-legal-entity-marks'); ?></strong><?php echo esc_html__(': задачи
                    не выполнялись больше двух часов. Запустите проверку кнопкой ниже
                    или настройте системный cron на выполнение задач WordPress.', 'chekazoid-legal-entity-marks'); ?>
                <?php elseif ($clem_next_run) : ?>
                    <?php echo esc_html__('Следующая порция:', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html(date_i18n('H:i:s', $clem_next_run + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS))); ?><?php echo esc_html__('.
                    Работа идёт частями в фоне, страницу можно закрыть.', 'chekazoid-legal-entity-marks'); ?>
                    <?php if ($clem_cron_last) : ?>
                        <?php echo esc_html__('Планировщик работает, последняя задача:', 'chekazoid-legal-entity-marks'); ?>
                        <?php echo esc_html(human_time_diff($clem_cron_last) . __(' назад', 'chekazoid-legal-entity-marks')); ?>.
                    <?php endif; ?>
                <?php else : ?>
                    <?php echo esc_html__('Задача в расписании не найдена, запустите проверку вручную.', 'chekazoid-legal-entity-marks'); ?>
                <?php endif; ?>
                <?php
                // Попытки идут, а счётчик стоит: запуск не доживает до конца.
                // Чаще всего это нехватка памяти на большом материале
                $clem_attempts = (int) ($clem_rescan_state['attempts'] ?? 0);
                if ($clem_attempts >= 3 && (int) $clem_rescan_state['offset'] === 0) : ?>
                    <br><strong><?php echo esc_html__('Попыток было', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_attempts); ?><?php echo esc_html__(', а прогресса нет.', 'chekazoid-legal-entity-marks'); ?></strong>
                    <?php echo esc_html__('Похоже, проверка обрывается на первой же порции: обычно это нехватка
                    памяти. Помогает увеличение', 'chekazoid-legal-entity-marks'); ?> <code>WP_MEMORY_LIMIT</code><?php echo esc_html__(', а до того
                    маркировка продолжает работать по прежним результатам.', 'chekazoid-legal-entity-marks'); ?>
                <?php endif; ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="clem-btn-rescan">
                    <?php echo esc_html__('Проверить архив сейчас', 'chekazoid-legal-entity-marks'); ?>
                </button>
                <span id="clem-rescan-status" class="clem-inline-status"></span>
            </p>
            <div class="clem-progress-bar" id="clem-rescan-bar" style="display:none">
                <div class="clem-progress-fill" id="clem-rescan-fill" style="width:0%"></div>
            </div>
        </div>
    <?php elseif (!empty($clem_update_report['at'])
        && ($clem_update_report['trigger'] ?? '') !== 'registry-update') : ?>
        <div class="notice notice-success inline" style="margin:0 0 16px">
            <p>
                <strong><?php echo esc_html__('Проверка архива завершена', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_update_report['at']); ?>.</strong>
                <?php echo esc_html__('Просмотрено материалов:', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update_report['posts_scanned']; ?><?php echo esc_html__(',
                из них с упоминаниями:', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update_report['with_matches']; ?>.
                <?php if ((int) $clem_update_report['links'] > 0) : ?>
                    <?php echo esc_html__('Ссылок на ресурсы из реестров:', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update_report['links']; ?>
                    <?php echo esc_html__('в', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update_report['with_links']; ?> <?php echo esc_html__('материалах.', 'chekazoid-legal-entity-marks'); ?>
                <?php endif; ?>
                <br><?php echo esc_html__('Кого принесло очередным обновлением реестров, будет показано здесь же
                после ближайшего обновления.', 'chekazoid-legal-entity-marks'); ?>
            </p>
        </div>
    <?php elseif (!empty($clem_update_report['at'])) : ?>
        <div class="notice notice-<?php echo (int) $clem_update_report['mentioned'] > 0 ? 'warning' : 'success'; ?> inline"
             style="margin:0 0 16px">
            <p>
                <strong><?php echo esc_html__('Проверка архива завершена', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_update_report['at']); ?>.</strong>
                <?php echo esc_html__('Новых записей в реестрах:', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update_report['new_entities']; ?>.
                <?php if ((int) $clem_update_report['mentioned'] > 0) : ?>
                    <?php echo esc_html__('Из них встречаются на сайте:', 'chekazoid-legal-entity-marks'); ?> <strong><?php echo (int) $clem_update_report['mentioned']; ?></strong>
                    <?php echo esc_html__('в', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update_report['mentioned_in']; ?> <?php echo esc_html__('материалах.', 'chekazoid-legal-entity-marks'); ?>
                    <a href="<?php echo esc_url($clem_report_url); ?>"><?php echo esc_html__('Посмотреть', 'chekazoid-legal-entity-marks'); ?></a>
                <?php else : ?>
                    <?php echo esc_html__('В материалах сайта ни одна из них не встречается.', 'chekazoid-legal-entity-marks'); ?>
                <?php endif; ?>
                <?php if (!empty($clem_update_report['names'])) : ?>
                    <br><span style="color:#666"><?php echo esc_html__('Новички:', 'chekazoid-legal-entity-marks'); ?>
                    <?php echo esc_html(implode(', ', array_slice($clem_update_report['names'], 0, 5))); ?><?php
                        echo count($clem_update_report['names']) > 5 ? esc_html__(' и другие', 'chekazoid-legal-entity-marks') : ''; ?></span>
                <?php endif; ?>
                <?php if ((int) $clem_update_report['links'] > 0) : ?>
                    <br><?php echo esc_html__('Ссылок на ресурсы из реестров:', 'chekazoid-legal-entity-marks'); ?> <strong><?php echo (int) $clem_update_report['links']; ?></strong>
                    <?php echo esc_html__('в', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_update_report['with_links']; ?> <?php echo esc_html__('материалах.', 'chekazoid-legal-entity-marks'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=clem-banned-links')); ?>"><?php echo esc_html__('Посмотреть', 'chekazoid-legal-entity-marks'); ?></a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="clem-dashboard-grid">
        <?php
        $clem_counts     = clem_plugin()->entities->count_by_type();
        $clem_last_fetch = get_option('clem_last_fetch_time', __('никогда', 'chekazoid-legal-entity-marks'));
        $clem_version    = get_option('clem_list_version', __('не задана', 'chekazoid-legal-entity-marks'));
        $error      = get_option('clem_last_fetch_error', '');

        global $wpdb;
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $clem_marked_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''", CLEM_META_KEY
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */

        $clem_banned_sites_count = clem_plugin()->banned_sites->count();
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry storage or selected-column report/batch reads require wpdb; core post/metadata writes use WP APIs. Read current import/scan/admin state; a persistent result could be stale after an editorial update. */
        $clem_banned_links_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            CLEM_BANNED_LINKS_META_KEY
        )) ;
        /* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
        // Из них те, где ссылки действительно полагается убирать
        $clem_removable_posts = count(CLEM_Link_Scanner::posts_with_removable());
        ?>

        <div class="clem-card">
            <h2><?php echo esc_html__('Реестры', 'chekazoid-legal-entity-marks'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Тип', 'chekazoid-legal-entity-marks'); ?></th>
                        <th><?php echo esc_html__('Активных', 'chekazoid-legal-entity-marks'); ?></th>
                        <th><?php echo esc_html__('Исключённых', 'chekazoid-legal-entity-marks'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $clem_types = [
                        'inoagent'    => __('Иностранные агенты', 'chekazoid-legal-entity-marks'),
                        'extremist'   => __('Экстремистские орг.', 'chekazoid-legal-entity-marks'),
                        'terrorist'   => __('Террористические орг.', 'chekazoid-legal-entity-marks'),
                        'undesirable' => __('Нежелательные орг.', 'chekazoid-legal-entity-marks'),
                        'fedsfm_person' => __('Физлица: Росфинмониторинг', 'chekazoid-legal-entity-marks'),
                    ];
                    $clem_total_active = 0;
                    foreach ($clem_types as $clem_key => $clem_label) :
                        $clem_active  = $clem_counts[$clem_key] ?? 0;
                        $clem_removed = $clem_counts[$clem_key . '_removed'] ?? 0;
                        $clem_total_active += $clem_active;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($clem_label); ?></strong></td>
                            <td><?php echo esc_html($clem_active); ?></td>
                            <td><?php echo esc_html($clem_removed); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong><?php echo esc_html__('Итого', 'chekazoid-legal-entity-marks'); ?></strong></td>
                        <td><strong><?php echo esc_html($clem_total_active); ?></strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="clem-card">
            <h2><?php echo esc_html__('Статус', 'chekazoid-legal-entity-marks'); ?></h2>
            <ul class="clem-status-list">
                <li>
                    <span class="clem-label"><?php echo esc_html__('Статей с маркировкой:', 'chekazoid-legal-entity-marks'); ?></span>
                    <strong><?php echo esc_html($clem_marked_posts); ?></strong>
                </li>
                <li>
                    <span class="clem-label"><?php echo esc_html__('Ресурсов в реестре:', 'chekazoid-legal-entity-marks'); ?></span>
                    <strong><?php echo esc_html($clem_banned_sites_count); ?></strong>
                </li>
                <li>
                    <span class="clem-label"><?php echo esc_html__('Статей со ссылками на них:', 'chekazoid-legal-entity-marks'); ?></span>
                    <strong><?php echo esc_html($clem_banned_links_posts); ?></strong>
                    <?php if ($clem_removable_posts !== $clem_banned_links_posts) : ?>
                        <span style="color:#777"><?php echo esc_html__(', из них подлежат чистке:', 'chekazoid-legal-entity-marks'); ?>
                        <?php echo (int) $clem_removable_posts; ?></span>
                    <?php endif; ?>
                </li>
                <li>
                    <span class="clem-label"><?php echo esc_html__('Версия списков:', 'chekazoid-legal-entity-marks'); ?></span>
                    <code><?php echo esc_html($clem_version); ?></code>
                </li>
                <li>
                    <span class="clem-label"><?php echo esc_html__('Последнее обновление:', 'chekazoid-legal-entity-marks'); ?></span>
                    <?php echo esc_html($clem_last_fetch); ?>
                </li>
                <?php
                $clem_health = (array) get_option('clem_channel_health', []);
                if (!empty($clem_health['checked_at'])) : ?>
                <li>
                    <span class="clem-label"><?php echo esc_html__('Канал реестров:', 'chekazoid-legal-entity-marks'); ?></span>
                    <?php if (!empty($clem_health['ok'])) : ?>
                        <?php echo esc_html__('доступен', 'chekazoid-legal-entity-marks'); ?><?php if (!empty($clem_health['last_snapshot'])) : ?><?php echo esc_html__(',
                        срез от', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html(date_i18n('d.m.Y',
                            strtotime($clem_health['last_snapshot']))); ?><?php endif; ?>
                    <?php else : ?>
                        <span style="color:#b32d2e"><?php echo esc_html__('недоступен', 'chekazoid-legal-entity-marks'); ?></span>
                        <?php echo esc_html($clem_health['error']); ?>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
                <?php if (CLEM_People::enabled($settings ?? clem_plugin()->get_settings())) :
                    $clem_people_state = (array) get_option(CLEM_People::STATE, []);
                    $clem_people_success = (array) get_option(CLEM_People::SUCCESS, []); ?>
                <li>
                    <span class="clem-label"><?php echo esc_html__('Физлица Росфинмониторинга:', 'chekazoid-legal-entity-marks'); ?></span>
                    <?php if (!empty($clem_people_state['error'])) : ?>
                        <span class="clem-error"><?php echo esc_html($clem_people_state['error']); ?></span>
                    <?php elseif (!empty($clem_people_state['running'])) : ?>
                        <?php echo esc_html__('загружаются в фоне:', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_people_state['offset']; ?> <?php echo esc_html__('из', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_people_state['total']; ?> <?php echo esc_html__('ФИО', 'chekazoid-legal-entity-marks'); ?>
                    <?php elseif (!empty($clem_people_success['synced_at'])) : ?>
                        <?php echo esc_html__('загружены', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_people_success['synced_at']); ?><?php echo esc_html__(',
                        срез от', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_people_success['fetched_at']); ?>
                    <?php else : ?>
                        <?php echo esc_html__('ещё не загружены', 'chekazoid-legal-entity-marks'); ?>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
                <?php
                // Откуда пришли данные: важно, когда официальный реестр молчит
                $clem_sources = (array) get_option('clem_last_fetch_sources', []);
                $clem_src_names = [
                    'official' => __('официальный реестр', 'chekazoid-legal-entity-marks'),
                    'mirror'   => __('зеркало перечня', 'chekazoid-legal-entity-marks'),
                    'channel'  => __('канал реестров', 'chekazoid-legal-entity-marks'),
                    'local'    => __('локальные данные', 'chekazoid-legal-entity-marks'),
                    'bundled'  => __('встроенный перечень', 'chekazoid-legal-entity-marks'),
                    'retained' => __('прежний подтверждённый список', 'chekazoid-legal-entity-marks'),
                    'none'     => __('данных нет', 'chekazoid-legal-entity-marks'),
                ];
                $clem_non_official = array_filter($clem_sources, static function ($v) {
                    return $v !== 'official';
                });
                if (!empty($clem_sources)) : ?>
                <li>
                    <span class="clem-label"><?php echo esc_html__('Источник данных:', 'chekazoid-legal-entity-marks'); ?></span>
                    <?php if (empty($clem_non_official)) : ?>
                        <?php echo esc_html__('официальные реестры', 'chekazoid-legal-entity-marks'); ?>
                    <?php else : ?>
                        <?php $clem_parts = [];
                        foreach ($clem_non_official as $type => $clem_src) {
                            $clem_parts[] = (CLEM_Importer::REGISTRY_LABELS[$type] ?? $type)
                                . ' - ' . ($clem_src_names[$clem_src] ?? $clem_src);
                        }
                        echo esc_html(implode('; ', $clem_parts)); ?>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
                <?php
                $clem_diagnostics = (array) get_option('clem_last_fetch_diagnostics', []);
                if (!empty($clem_diagnostics)) : ?>
                <li>
                    <details>
                        <summary><?php echo esc_html__('Диагностика источников', 'chekazoid-legal-entity-marks'); ?></summary>
                        <?php foreach ($clem_diagnostics as $type => $clem_reasons) : ?>
                        <p>
                            <strong><?php echo esc_html(CLEM_Importer::REGISTRY_LABELS[$type] ?? $type); ?>:</strong>
                            <?php echo esc_html(implode('; ', (array) $clem_reasons)); ?>
                        </p>
                        <?php endforeach; ?>
                    </details>
                </li>
                <?php endif; ?>
                <?php if ($error && clem_plugin()->channel->is_enabled()) : ?>
                <li class="clem-error">
                    <span class="clem-label"><?php echo esc_html__('Последнее обновление прошло не полностью:', 'chekazoid-legal-entity-marks'); ?></span>
                    <?php echo esc_html($error); ?>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="clem-card">
            <h2><?php echo esc_html__('Быстрые действия', 'chekazoid-legal-entity-marks'); ?></h2>
            <p>
                <button type="button" class="button button-primary" id="clem-btn-fetch"
                        <?php disabled(!clem_plugin()->channel->is_enabled()); ?>>
                    <?php echo esc_html__('Обновить реестры', 'chekazoid-legal-entity-marks'); ?>
                </button>
                <span id="clem-fetch-status" class="clem-inline-status"></span>
                <?php if (!clem_plugin()->channel->is_enabled()) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=clem-settings#clem-channel-url')); ?>">
                        <?php echo esc_html__('Подключить канал обновлений', 'chekazoid-legal-entity-marks'); ?>
                    </a>
                <?php endif; ?>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=clem-scanner')); ?>" class="button">
                    <?php echo esc_html__('Запустить сканер', 'chekazoid-legal-entity-marks'); ?>
                </a>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=clem-banned-links')); ?>" class="button">
                    <?php echo esc_html__('Проверить ссылки', 'chekazoid-legal-entity-marks'); ?>
                </a>
            </p>
            <p>
                <button type="button" class="button" id="clem-btn-purge">
                    <?php echo esc_html__('Очистить кеш', 'chekazoid-legal-entity-marks'); ?>
                </button>
                <span id="clem-purge-status" class="clem-inline-status"></span>
            </p>
            <p>
                <button type="button" class="button" id="clem-btn-rescan-start">
                    <?php echo esc_html__('Проверить архив', 'chekazoid-legal-entity-marks'); ?>
                </button>
                <span id="clem-rescan-start-status" class="clem-inline-status"></span>
            </p>
        </div>
    </div>
</div>
