(function() {
    var __ = wp.i18n.__;
    var cfg = window.clemAdmin || {};
    var scanning = false, removing = false;

    /* --- Реестр доменов: CRUD --- */
    var siteModal = document.getElementById('clem-site-modal');
    var importModal = document.getElementById('clem-import-modal');

    function openSiteModal(site) {
        document.getElementById('clem-site-modal-title').textContent = site ? __( "Редактирование", 'chekazoid-legal-entity-marks' ) : __( "Добавить домен", 'chekazoid-legal-entity-marks' );
        document.getElementById('clem-site-id').value = site ? site.id : 0;
        // Аккаунт показываем вместе с площадкой, иначе при сохранении он потеряется
        document.getElementById('clem-site-domain').value = site
            ? (site.account ? site.domain + '/' + site.account : site.domain)
            : '';
        document.getElementById('clem-site-label').value = site ? (site.label || '') : '';
        document.getElementById('clem-site-registry').value = site ? (site.registry || '') : '';
        document.getElementById('clem-site-status-msg').textContent = '';
        siteModal.style.display = 'flex';
    }

    document.getElementById('clem-add-site')?.addEventListener('click', function() { openSiteModal(null); });
    document.getElementById('clem-cancel-site')?.addEventListener('click', function() { siteModal.style.display = 'none'; });

    document.querySelectorAll('.clem-edit-site').forEach(function(btn) {
        btn.addEventListener('click', function() { openSiteModal(JSON.parse(this.dataset.site)); });
    });

    document.getElementById('clem-save-site')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('clem-site-status-msg');
        btn.disabled = true; st.textContent = __( "Сохранение...", 'chekazoid-legal-entity-marks' );

        var body = new URLSearchParams({
            action: 'clem_save_banned_site', nonce: cfg.crudNonce,
            id: document.getElementById('clem-site-id').value,
            domain: document.getElementById('clem-site-domain').value,
            registry: document.getElementById('clem-site-registry').value,
            label: document.getElementById('clem-site-label').value
        });

        fetch(cfg.ajaxUrl, { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.success) { siteModal.style.display = 'none'; location.reload(); }
            else { st.textContent = d.data || __( "Ошибка", 'chekazoid-legal-entity-marks' ); }
        })
        .catch(function() { st.textContent = __( "Ошибка сети", 'chekazoid-legal-entity-marks' ); btn.disabled = false; });
    });

    document.querySelectorAll('.clem-delete-site').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm(__( "Удалить этот домен из реестра?", 'chekazoid-legal-entity-marks' ))) return;
            var id = this.dataset.id;
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=clem_delete_banned_site&nonce=' + cfg.crudNonce + '&id=' + id
            })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.success) location.reload(); });
        });
    });

    /* --- Массовый импорт --- */
    document.getElementById('clem-import-sites')?.addEventListener('click', function() {
        document.getElementById('clem-import-domains').value = '';
        document.getElementById('clem-import-status-msg').textContent = '';
        importModal.style.display = 'flex';
    });
    document.getElementById('clem-cancel-import')?.addEventListener('click', function() { importModal.style.display = 'none'; });

    document.getElementById('clem-do-import')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('clem-import-status-msg');
        var domains = document.getElementById('clem-import-domains').value.trim();
        if (!domains) { st.textContent = __( "Введите хотя бы один домен", 'chekazoid-legal-entity-marks' ); return; }

        btn.disabled = true; st.textContent = __( "Импорт...", 'chekazoid-legal-entity-marks' );

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_import_banned_sites&nonce=' + cfg.crudNonce + '&domains=' + encodeURIComponent(domains)
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.success) {
                st.textContent = d.data.message;
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                st.textContent = d.data || __( "Ошибка", 'chekazoid-legal-entity-marks' );
            }
        })
        .catch(function() { st.textContent = __( "Ошибка сети", 'chekazoid-legal-entity-marks' ); btn.disabled = false; });
    });

    /* --- Сканер ссылок --- */
    function startLinkScan() {
        if (scanning) return;
        scanning = true;

        var progress = document.getElementById('clem-link-scan-progress');
        var result = document.getElementById('clem-link-scan-result');
        var fill = document.getElementById('clem-link-progress-fill');
        var status = document.getElementById('clem-link-scan-status');
        var cancel = document.getElementById('clem-link-scan-cancel');

        progress.style.display = 'block';
        result.style.display = 'none';
        cancel.style.display = 'inline-block';
        fill.style.width = '0%';
        status.textContent = __( "Инициализация...", 'chekazoid-legal-entity-marks' );

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_banned_scan_init&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { status.textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + (d.data || __( "Неизвестная", 'chekazoid-legal-entity-marks' )); scanning = false; return; }
            processLinkScanBatch();
        })
        .catch(function(e) { status.textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + e.message; scanning = false; });
    }

    function processLinkScanBatch() {
        if (!scanning) return;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_banned_scan_process&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { document.getElementById('clem-link-scan-status').textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); scanning = false; return; }
            var state = d.data;
            var pct = state.total > 0 ? Math.round(state.offset / state.total * 100) : 0;
            document.getElementById('clem-link-progress-fill').style.width = pct + '%';
            document.getElementById('clem-link-scan-status').textContent =
                state.offset + ' / ' + state.total + __( " статей (", 'chekazoid-legal-entity-marks' ) + pct + '%) - ' +
                state.posts_with_links + __( " со ссылками, ", 'chekazoid-legal-entity-marks' ) + state.total_links + __( " ссылок", 'chekazoid-legal-entity-marks' );

            if (state.status === 'complete') {
                document.getElementById('clem-link-progress-fill').style.width = '100%';
                document.getElementById('clem-link-scan-cancel').style.display = 'none';
                document.getElementById('clem-link-scan-result').style.display = 'block';
                document.getElementById('clem-link-scan-result-text').textContent =
                    __( "Сканирование завершено: ", 'chekazoid-legal-entity-marks' ) + state.posts_with_links + __( " статей со ссылками, ", 'chekazoid-legal-entity-marks' ) +
                    state.total_links + __( " ссылок", 'chekazoid-legal-entity-marks' );
                scanning = false;
                setTimeout(function() { location.reload(); }, 2000);
            } else if (state.status === 'cancelled') {
                document.getElementById('clem-link-scan-status').textContent = __( "Отменено.", 'chekazoid-legal-entity-marks' );
                document.getElementById('clem-link-scan-cancel').style.display = 'none';
                scanning = false;
            } else {
                setTimeout(processLinkScanBatch, 100);
            }
        })
        .catch(function(e) { document.getElementById('clem-link-scan-status').textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + e.message; scanning = false; });
    }

    document.getElementById('clem-link-scan-all')?.addEventListener('click', function() { startLinkScan(); });
    document.getElementById('clem-link-scan-cancel')?.addEventListener('click', function() {
        scanning = false;
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_banned_scan_cancel&nonce=' + cfg.nonce
        });
    });

    /* --- Удаление ссылок из одного поста --- */
    document.querySelectorAll('.clem-remove-post-links').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var postId = this.dataset.postId;
            if (!confirm(__( "Удалить из этой статьи ссылки, подлежащие удалению? Тег <a> будет заменён текстом ссылки. Ресурсы иноагентов останутся.", 'chekazoid-legal-entity-marks' ))) return;
            btn.disabled = true; btn.textContent = '...';

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=clem_banned_remove_post&nonce=' + cfg.nonce + '&post_id=' + postId
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    var rows = document.querySelectorAll('tr[data-post-id="' + postId + '"]');
                    rows.forEach(function(row) { row.style.display = 'none'; });
                    btn.textContent = __( "Готово", 'chekazoid-legal-entity-marks' );
                } else {
                    btn.textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); btn.disabled = false;
                }
            })
            .catch(function() { btn.textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); btn.disabled = false; });
        });
    });

    /* --- Массовое удаление всех ссылок --- */
    document.getElementById('clem-remove-all-links')?.addEventListener('click', function() {
        // Считаем только то, что действительно уйдёт: ресурсы иноагентов остаются
        var total = Number(this.dataset.links);
        var posts = Number(this.dataset.posts);
        if (!confirm(__( "Будут удалены ", 'chekazoid-legal-entity-marks' ) + total + __( " ссылок из ", 'chekazoid-legal-entity-marks' ) + posts + __( " статей.\nТег <a> будет заменён текстом ссылки.\nСсылки на ресурсы иноагентов не затрагиваются.\n\nПродолжить?", 'chekazoid-legal-entity-marks' ))) return;

        if (removing) return;
        removing = true;

        var progress = document.getElementById('clem-remove-progress');
        var result = document.getElementById('clem-remove-result');
        var fill = document.getElementById('clem-remove-progress-fill');
        var status = document.getElementById('clem-remove-status');

        progress.style.display = 'block';
        result.style.display = 'none';
        fill.style.width = '0%';
        status.textContent = __( "Инициализация удаления...", 'chekazoid-legal-entity-marks' );

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_banned_remove_init&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { status.textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + (d.data || __( "Неизвестная", 'chekazoid-legal-entity-marks' )); removing = false; return; }
            processRemoveBatch(d.data.total);
        })
        .catch(function(e) { status.textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + e.message; removing = false; });
    });

    function processRemoveBatch(total) {
        if (!removing) return;

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_banned_remove_process&nonce=' + cfg.nonce
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { document.getElementById('clem-remove-status').textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); removing = false; return; }
            var state = d.data;
            var pct = total > 0 ? Math.round(state.cleaned / total * 100) : 100;
            document.getElementById('clem-remove-progress-fill').style.width = pct + '%';
            document.getElementById('clem-remove-status').textContent =
                __( "Очищено ", 'chekazoid-legal-entity-marks' ) + state.cleaned + ' / ' + total + __( " статей (", 'chekazoid-legal-entity-marks' ) + pct + '%)';

            if (state.status === 'complete') {
                document.getElementById('clem-remove-progress-fill').style.width = '100%';
                document.getElementById('clem-remove-result').style.display = 'block';
                document.getElementById('clem-remove-result-text').textContent =
                    __( "Удаление завершено: очищено ", 'chekazoid-legal-entity-marks' ) + state.cleaned + __( " статей.", 'chekazoid-legal-entity-marks' );
                removing = false;
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                setTimeout(function() { processRemoveBatch(total); }, 100);
            }
        })
        .catch(function(e) { document.getElementById('clem-remove-status').textContent = __( "Ошибка: ", 'chekazoid-legal-entity-marks' ) + e.message; removing = false; });
    }
})();
