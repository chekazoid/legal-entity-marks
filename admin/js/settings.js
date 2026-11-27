(function() {
    var __ = wp.i18n.__;
    /* Регистрация сайта: уходит только по нажатию и только то, что показано выше */
    document.getElementById('clem-register-site')?.addEventListener('click', function () {
        var btn = this, out = document.getElementById('clem-register-status');
        var cfg = window.clemAdmin || {};
        var email = (document.getElementById('clem-register-email') || {}).value || '';
        if (!confirm(__( "Отправить автору плагина адрес сайта, его название, версии и почту?\n", 'chekazoid-legal-entity-marks' )
            + __( "Содержимое статей и результаты поиска не отправляются.", 'chekazoid-legal-entity-marks' ))) { return; }

        btn.disabled = true;
        out.textContent = __( "Отправляем...", 'chekazoid-legal-entity-marks' );
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_register_site&nonce=' + cfg.crudNonce + '&email=' + encodeURIComponent(email)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            out.textContent = d.success ? d.data.message : (d.data || __( "не получилось", 'chekazoid-legal-entity-marks' ));
        })
        .catch(function () { btn.disabled = false; out.textContent = __( "Ошибка сети", 'chekazoid-legal-entity-marks' ); });
    });

    /* Проверка канала реестров: токен наружу не отдаётся, только состояние */
    document.getElementById('clem-check-channel')?.addEventListener('click', function () {
        var btn = this, out = document.getElementById('clem-channel-status');
        var cfg = window.clemAdmin || {};
        btn.disabled = true;
        out.textContent = __( "Проверяем...", 'chekazoid-legal-entity-marks' );
        fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clem_check_channel&nonce=' + cfg.crudNonce
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            out.textContent = d.success ? d.data.message : __( "Ошибка проверки", 'chekazoid-legal-entity-marks' );
        })
        .catch(function () { btn.disabled = false; out.textContent = __( "Ошибка сети", 'chekazoid-legal-entity-marks' ); });
    });

    /* Профиль сайта расставляет галочки сразу, не дожидаясь сохранения:
       иначе выбранный профиль и таблица показывают разное. */
    (function () {
        var presets = window.clemAdmin.presets;
        var marks   = document.querySelectorAll('.clem-mark');
        var tracks  = document.querySelectorAll('.clem-track');
        var hint    = document.getElementById('clem-preset-hint');
        var present = document.getElementById('clem-registries-present');
        if (!marks.length || !present) { return; }

        function trackBox(registry) {
            return document.querySelector('.clem-track[data-registry="' + registry + '"]');
        }

        // Маркируемый реестр отслеживается по определению: галочку ставим и запираем
        function syncTrack(manual) {
            marks.forEach(function (m) {
                var t = trackBox(m.dataset.registry);
                if (!t) { return; }
                if (m.checked) {
                    t.checked  = true;
                    t.disabled = true;
                } else {
                    t.disabled = !manual;
                }
            });
        }

        function apply(key) {
            var preset = presets[key];
            var manual = !preset || preset.mark === null;

            if (!manual) {
                marks.forEach(function (m) {
                    m.checked = preset.mark.indexOf(m.dataset.registry) !== -1;
                });
                tracks.forEach(function (t) {
                    t.checked = preset.track.indexOf(t.dataset.registry) !== -1
                        || preset.mark.indexOf(t.dataset.registry) !== -1;
                });
            }

            marks.forEach(function (m) { m.disabled = !manual; });
            tracks.forEach(function (t) { t.disabled = !manual; });
            syncTrack(manual);

            // Отключённое поле браузер не отправляет - сервер поймёт, что галочки
            // правил профиль, и не станет читать их из формы
            present.disabled = !manual;
            if (hint) { hint.style.display = manual ? 'none' : ''; }
        }

        document.querySelectorAll('input[name="clem_preset"]').forEach(function (radio) {
            radio.addEventListener('change', function () { apply(this.value); });
        });
        marks.forEach(function (m) {
            m.addEventListener('change', function () { syncTrack(true); });
        });
    })();
})();
