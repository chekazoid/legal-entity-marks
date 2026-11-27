(function() {
    var __ = wp.i18n.__;
    var cfg = window.clemAdmin || {};
    var modal = document.getElementById('clem-entity-modal');

    function openModal(entity) {
        document.getElementById('clem-modal-title').textContent = entity ? __( "Редактирование", 'chekazoid-legal-entity-marks' ) : __( "Добавить запись", 'chekazoid-legal-entity-marks' );
        document.getElementById('clem-entity-id').value = entity ? entity.id : 0;
        document.getElementById('clem-entity-name').value = entity ? entity.name : '';
        document.getElementById('clem-entity-type').value = entity ? entity.type : 'inoagent';
        document.getElementById('clem-entity-person').checked = entity ? !!entity.is_person : false;
        document.getElementById('clem-entity-aliases').value = entity ? (entity.aliases || []).join('\n') : '';
        document.getElementById('clem-entity-status').value = entity ? (entity.status_text || '') : '';
        document.getElementById('clem-entity-status-msg').textContent = '';
        modal.style.display = 'flex';
    }

    document.getElementById('clem-add-entity')?.addEventListener('click', function() { openModal(null); });
    document.getElementById('clem-cancel-entity')?.addEventListener('click', function() { modal.style.display = 'none'; });

    document.querySelectorAll('.clem-edit-entity').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal(JSON.parse(this.dataset.entity));
        });
    });

    document.getElementById('clem-save-entity')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('clem-entity-status-msg');
        btn.disabled = true;
        st.textContent = __( "Сохранение...", 'chekazoid-legal-entity-marks' );

        var body = new URLSearchParams({
            action: 'clem_save_entity',
            nonce: cfg.crudNonce,
            id: document.getElementById('clem-entity-id').value,
            name: document.getElementById('clem-entity-name').value,
            type: document.getElementById('clem-entity-type').value,
            is_person: document.getElementById('clem-entity-person').checked ? 1 : 0,
            aliases: document.getElementById('clem-entity-aliases').value,
            status_text: document.getElementById('clem-entity-status').value,
            is_active: 1
        });

        fetch(cfg.ajaxUrl, { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.success) { modal.style.display = 'none'; location.reload(); }
            else { st.textContent = d.data || __( "Ошибка", 'chekazoid-legal-entity-marks' ); }
        })
        .catch(function() { st.textContent = __( "Ошибка", 'chekazoid-legal-entity-marks' ); btn.disabled = false; });
    });

    document.querySelectorAll('.clem-delete-entity').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm(__( "Удалить эту запись?", 'chekazoid-legal-entity-marks' ))) return;
            var id = this.dataset.id;
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=clem_delete_entity&nonce=' + cfg.crudNonce + '&id=' + id
            })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.success) location.reload(); });
        });
    });
})();
