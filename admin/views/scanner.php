<?php defined('ABSPATH') || exit;

$state = get_transient(LEM_Scanner::SCAN_STATE_KEY);
?>
<div class="wrap">
    <h1><?php echo esc_html__('Сканер', 'legal-entity-marks'); ?></h1>

    <div class="lem-card" style="max-width:600px">
        <h2><?php echo esc_html__('Поиск упоминаний в статьях', 'legal-entity-marks'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Сканирует все опубликованные статьи и отмечает те, в которых упоминаются сущности из реестров.
            Существующие результаты сканирования будут обновлены.', 'legal-entity-marks'); ?>
        </p>

        <p>
            <button type="button" class="button button-primary button-hero" id="lem-scan-all">
                <?php echo esc_html__('Сканировать все статьи', 'legal-entity-marks'); ?>
            </button>
            <button type="button" class="button button-hero" id="lem-scan-recent">
                <?php echo esc_html__('Только за 7 дней', 'legal-entity-marks'); ?>
            </button>
        </p>

        <div id="lem-scan-progress" style="display:none;margin-top:20px">
            <div class="lem-progress-bar">
                <div class="lem-progress-fill" id="lem-progress-fill" style="width:0%"></div>
            </div>
            <p id="lem-scan-status" class="description"></p>
            <p>
                <button type="button" class="button" id="lem-scan-cancel" style="display:none">
                    <?php echo esc_html__('Отменить', 'legal-entity-marks'); ?>
                </button>
            </p>
        </div>

        <div id="lem-scan-result" style="display:none;margin-top:20px">
            <div class="notice notice-success inline">
                <p id="lem-scan-result-text"></p>
            </div>
        </div>

        <?php if ($state && $state['status'] === 'complete') : ?>
        <div class="notice notice-info inline" style="margin-top:20px">
            <p>
                <?php printf(
                    /* translators: 1: scan date, 2: articles with matches, 3: total mentions. */
                    esc_html__('Последний скан: %1$s - %2$d статей с совпадениями, %3$d упоминаний всего', 'legal-entity-marks'),
                    esc_html($state['finished_at'] ?? $state['started_at']),
                    (int) $state['posts_with_matches'],
                    (int) $state['total_mentions']
                ); ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var cfg = window.lemAdmin || {};
    var scanning = false;

    function startScan(mode) {
        if (scanning) return;
        scanning = true;

        var progress = document.getElementById('lem-scan-progress');
        var result = document.getElementById('lem-scan-result');
        var fill = document.getElementById('lem-progress-fill');
        var status = document.getElementById('lem-scan-status');
        var cancel = document.getElementById('lem-scan-cancel');

        progress.style.display = 'block';
        result.style.display = 'none';
        cancel.style.display = 'inline-block';
        fill.style.width = '0%';
        status.textContent = <?php echo wp_json_encode(__('Инициализация...', 'legal-entity-marks')); ?>;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_scan_init&nonce=' + cfg.nonce + '&mode=' + mode
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { status.textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + (d.data || <?php echo wp_json_encode(__('Неизвестная', 'legal-entity-marks')); ?>); scanning = false; return; }
            processBatch();
        })
        .catch(function(e) { status.textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + e.message; scanning = false; });
    }

    function processBatch() {
        if (!scanning) return;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_scan_process&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { document.getElementById('lem-scan-status').textContent = <?php echo wp_json_encode(__('Ошибка', 'legal-entity-marks')); ?>; scanning = false; return; }
            var state = d.data;
            var pct = state.total > 0 ? Math.round(state.offset / state.total * 100) : 0;
            document.getElementById('lem-progress-fill').style.width = pct + '%';
            document.getElementById('lem-scan-status').textContent =
                state.offset + ' / ' + state.total + <?php echo wp_json_encode(__(' статей (', 'legal-entity-marks')); ?> + pct + '%) - ' +
                state.posts_with_matches + <?php echo wp_json_encode(__(' совпадений, ', 'legal-entity-marks')); ?> + state.total_mentions + <?php echo wp_json_encode(__(' упоминаний', 'legal-entity-marks')); ?>;

            if (state.status === 'complete') {
                document.getElementById('lem-progress-fill').style.width = '100%';
                document.getElementById('lem-scan-cancel').style.display = 'none';
                document.getElementById('lem-scan-result').style.display = 'block';
                document.getElementById('lem-scan-result-text').textContent =
                    <?php echo wp_json_encode(__('Сканирование завершено: ', 'legal-entity-marks')); ?> + state.posts_with_matches + <?php echo wp_json_encode(__(' статей с совпадениями, ', 'legal-entity-marks')); ?> +
                    state.total_mentions + <?php echo wp_json_encode(__(' упоминаний', 'legal-entity-marks')); ?>;
                scanning = false;
            } else if (state.status === 'cancelled') {
                document.getElementById('lem-scan-status').textContent = <?php echo wp_json_encode(__('Отменено.', 'legal-entity-marks')); ?>;
                document.getElementById('lem-scan-cancel').style.display = 'none';
                scanning = false;
            } else {
                setTimeout(processBatch, 100);
            }
        })
        .catch(function(e) { document.getElementById('lem-scan-status').textContent = <?php echo wp_json_encode(__('Ошибка: ', 'legal-entity-marks')); ?> + e.message; scanning = false; });
    }

    document.getElementById('lem-scan-all')?.addEventListener('click', function() { startScan('all'); });
    document.getElementById('lem-scan-recent')?.addEventListener('click', function() { startScan('recent'); });

    document.getElementById('lem-scan-cancel')?.addEventListener('click', function() {
        scanning = false;
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=lem_scan_cancel&nonce=' + cfg.nonce
        });
    });
})();
</script>
