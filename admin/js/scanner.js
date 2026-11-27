(function() {
    var __ = wp.i18n.__;
    var cfg = window.clemAdmin || {};
    var scanning = false;

    function startScan(mode) {
        if (scanning) return;
        scanning = true;

        var progress = document.getElementById('clem-scan-progress');
        var result = document.getElementById('clem-scan-result');
        var fill = document.getElementById('clem-progress-fill');
        var status = document.getElementById('clem-scan-status');
        var cancel = document.getElementById('clem-scan-cancel');

        progress.style.display = 'block';
        result.style.display = 'none';
        cancel.style.display = 'inline-block';
        fill.style.width = '0%';
        status.textContent = __( "Инициализация...", 'chekazoid-legal-entity-marks' );

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_scan_init&nonce=' + cfg.nonce + '&mode=' + mode
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { status.textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + (d.data || __( "Неизвестная", 'chekazoid-legal-entity-marks' )); scanning = false; return; }
            processBatch();
        })
        .catch(function(e) { status.textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + e.message; scanning = false; });
    }

    function processBatch() {
        if (!scanning) return;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_scan_process&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { document.getElementById('clem-scan-status').textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); scanning = false; return; }
            var state = d.data;
            var pct = state.total > 0 ? Math.round(state.offset / state.total * 100) : 0;
            document.getElementById('clem-progress-fill').style.width = pct + '%';
            document.getElementById('clem-scan-status').textContent =
                state.offset + ' / ' + state.total + __( " статей (", 'chekazoid-legal-entity-marks' ) + pct + '%) - ' +
                state.posts_with_matches + __( " совпадений, ", 'chekazoid-legal-entity-marks' ) + state.total_mentions + __( " упоминаний", 'chekazoid-legal-entity-marks' );

            if (state.status === 'complete') {
                document.getElementById('clem-progress-fill').style.width = '100%';
                document.getElementById('clem-scan-cancel').style.display = 'none';
                document.getElementById('clem-scan-result').style.display = 'block';
                document.getElementById('clem-scan-result-text').textContent =
                    __( "Сканирование завершено: ", 'chekazoid-legal-entity-marks' ) + state.posts_with_matches + __( " статей с совпадениями, ", 'chekazoid-legal-entity-marks' ) +
                    state.total_mentions + __( " упоминаний", 'chekazoid-legal-entity-marks' );
                scanning = false;
            } else if (state.status === 'cancelled') {
                document.getElementById('clem-scan-status').textContent = __( "Отменено.", 'chekazoid-legal-entity-marks' );
                document.getElementById('clem-scan-cancel').style.display = 'none';
                scanning = false;
            } else {
                setTimeout(processBatch, 100);
            }
        })
        .catch(function(e) { document.getElementById('clem-scan-status').textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + e.message; scanning = false; });
    }

    document.getElementById('clem-scan-all')?.addEventListener('click', function() { startScan('all'); });
    document.getElementById('clem-scan-recent')?.addEventListener('click', function() { startScan('recent'); });

    document.getElementById('clem-scan-cancel')?.addEventListener('click', function() {
        scanning = false;
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_scan_cancel&nonce=' + cfg.nonce
        });
    });
})();
