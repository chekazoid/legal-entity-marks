<?php defined('ABSPATH') || exit;

$clem_state = get_transient(CLEM_Scanner::SCAN_STATE_KEY);
?>
<div class="wrap">
    <h1><?php echo esc_html__('Сканер', 'chekazoid-legal-entity-marks'); ?></h1>

    <div class="clem-card" style="max-width:600px">
        <h2><?php echo esc_html__('Поиск упоминаний в статьях', 'chekazoid-legal-entity-marks'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Сканирует все опубликованные статьи и отмечает те, в которых упоминаются сущности из реестров.
            Существующие результаты сканирования будут обновлены.', 'chekazoid-legal-entity-marks'); ?>
        </p>

        <p>
            <button type="button" class="button button-primary button-hero" id="clem-scan-all">
                <?php echo esc_html__('Сканировать все статьи', 'chekazoid-legal-entity-marks'); ?>
            </button>
            <button type="button" class="button button-hero" id="clem-scan-recent">
                <?php echo esc_html__('Только за 7 дней', 'chekazoid-legal-entity-marks'); ?>
            </button>
        </p>

        <div id="clem-scan-progress" style="display:none;margin-top:20px">
            <div class="clem-progress-bar">
                <div class="clem-progress-fill" id="clem-progress-fill" style="width:0%"></div>
            </div>
            <p id="clem-scan-status" class="description"></p>
            <p>
                <button type="button" class="button" id="clem-scan-cancel" style="display:none">
                    <?php echo esc_html__('Отменить', 'chekazoid-legal-entity-marks'); ?>
                </button>
            </p>
        </div>

        <div id="clem-scan-result" style="display:none;margin-top:20px">
            <div class="notice notice-success inline">
                <p id="clem-scan-result-text"></p>
            </div>
        </div>

        <?php if ($clem_state && $clem_state['status'] === 'complete') : ?>
        <div class="notice notice-info inline" style="margin-top:20px">
            <p>
                <?php printf(
                    /* translators: 1: scan date, 2: articles with matches, 3: total mentions. */
                    esc_html__('Последний скан: %1$s - %2$d статей с совпадениями, %3$d упоминаний всего', 'chekazoid-legal-entity-marks'),
                    esc_html($clem_state['finished_at'] ?? $clem_state['started_at']),
                    (int) $clem_state['posts_with_matches'],
                    (int) $clem_state['total_mentions']
                ); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>
