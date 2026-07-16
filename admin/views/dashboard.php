<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <h1>Маркировка иноагентов и запрещённых организаций</h1>

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
                    <span class="lem-label">Запрещ. доменов:</span>
                    <strong><?php echo esc_html($banned_sites_count); ?></strong>
                </li>
                <li>
                    <span class="lem-label">Статей с запрещ. ссылками:</span>
                    <strong><?php echo esc_html($banned_links_posts); ?></strong>
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
                    <span class="lem-label">Последняя ошибка:</span>
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
