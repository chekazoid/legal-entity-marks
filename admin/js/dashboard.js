(function() {
    var __ = wp.i18n.__;
    var cfg = window.clemAdmin || {};

    document.getElementById('clem-btn-fetch')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('clem-fetch-status');
        btn.disabled = true;
        st.textContent = __( "Обновление...", 'chekazoid-legal-entity-marks' );
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_fetch_registries&nonce=' + cfg.crudNonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            st.textContent = d.success ? (d.data.message || __( "Готово", 'chekazoid-legal-entity-marks' )) : (d.data?.message || __( "Ошибка", 'chekazoid-legal-entity-marks' ));
            btn.disabled = false;
            if (d.success) setTimeout(function() { location.reload(); }, 1500);
        })
        .catch(function() { st.textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); btn.disabled = false; });
    });

    /* Прогон очереди из браузера: WP-Cron срабатывает только при заходах
       на сайт, а где-то отключён совсем */
    function runRescan(btn, statusEl, start) {
        var bar  = document.getElementById('clem-rescan-bar');
        var fill = document.getElementById('clem-rescan-fill');
        btn.disabled = true;
        if (bar) bar.style.display = '';

        function step(first) {
            var body = 'action=clem_rescan_step&nonce=' + cfg.crudNonce + (first ? '&start=1' : '');
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) { statusEl.textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); btn.disabled = false; return; }
                statusEl.textContent = d.data.offset + __( " из ", 'chekazoid-legal-entity-marks' ) + d.data.total
                    + ' (' + d.data.percent + '%)';
                if (fill) fill.style.width = d.data.percent + '%';
                if (d.data.done) {
                    statusEl.textContent = __( "Проверка завершена", 'chekazoid-legal-entity-marks' );
                    setTimeout(function() { location.reload(); }, 1200);
                    return;
                }
                step(false);
            })
            .catch(function() { statusEl.textContent = __( "Ошибка сети", 'chekazoid-legal-entity-marks' ); btn.disabled = false; });
        }
        step(start);
    }

    document.getElementById('clem-btn-rescan')?.addEventListener('click', function() {
        runRescan(this, document.getElementById('clem-rescan-status'), false);
    });

    document.getElementById('clem-btn-rescan-start')?.addEventListener('click', function() {
        if (!confirm(__( "Проверить весь архив на упоминания и ссылки на ресурсы из реестров? ", 'chekazoid-legal-entity-marks' )
            + __( "Это может занять несколько минут, вкладку надо держать открытой.", 'chekazoid-legal-entity-marks' ))) return;
        runRescan(this, document.getElementById('clem-rescan-start-status'), true);
    });

    document.getElementById('clem-btn-purge')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('clem-purge-status');
        btn.disabled = true;
        st.textContent = __( "Очистка...", 'chekazoid-legal-entity-marks' );
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_purge_cache&nonce=' + cfg.crudNonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            st.textContent = d.success ? __( "Очищено для ", 'chekazoid-legal-entity-marks' ) + d.data.purged + __( " статей", 'chekazoid-legal-entity-marks' ) : __( "Ошибка", 'chekazoid-legal-entity-marks' );
            btn.disabled = false;
        })
        .catch(function() { st.textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); btn.disabled = false; });
    });
})();
