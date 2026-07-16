<?php defined('ABSPATH') || exit;

$search      = sanitize_text_field($_GET['s'] ?? '');
$type_filter = sanitize_text_field($_GET['type'] ?? '');
$paged       = max(1, (int) ($_GET['paged'] ?? 1));
$per_page    = 50;

$result = lem()->entities->search([
    'search'    => $search,
    'type'      => $type_filter,
    'is_active' => 1,
    'limit'     => $per_page,
    'offset'    => ($paged - 1) * $per_page,
    'orderby'   => 'name',
    'order'     => 'ASC',
]);

$items       = $result['items'];
$total       = $result['total'];
$total_pages = ceil($total / $per_page);

$type_labels = [
    'inoagent'    => 'Иноагент',
    'extremist'   => 'Экстремист.',
    'terrorist'   => 'Террорист.',
    'undesirable' => 'Нежелат.',
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Реестр сущностей</h1>
    <button type="button" class="page-title-action" id="lem-add-entity">Добавить</button>

    <form method="get" class="lem-filter-form">
        <input type="hidden" name="page" value="lem-entities">
        <select name="type">
            <option value="">Все типы</option>
            <option value="inoagent" <?php selected($type_filter, 'inoagent'); ?>>Иностранный агент</option>
            <option value="extremist" <?php selected($type_filter, 'extremist'); ?>>Экстремистская орг.</option>
            <option value="terrorist" <?php selected($type_filter, 'terrorist'); ?>>Террористическая орг.</option>
            <option value="undesirable" <?php selected($type_filter, 'undesirable'); ?>>Нежелательная орг.</option>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Поиск...">
        <button type="submit" class="button">Фильтр</button>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:40px">ID</th>
                <th>Название</th>
                <th style="width:120px">Тип</th>
                <th style="width:70px">Физлицо</th>
                <th>Алиасы</th>
                <th style="width:140px">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)) : ?>
                <tr><td colspan="6">Записи не найдены.</td></tr>
            <?php else : ?>
                <?php foreach ($items as $item) : ?>
                <tr data-id="<?php echo esc_attr($item['id']); ?>">
                    <td><?php echo esc_html($item['id']); ?></td>
                    <td><strong><?php echo esc_html($item['name']); ?></strong></td>
                    <td><span class="lem-badge lem-badge-<?php echo esc_attr($item['type']); ?>"><?php echo esc_html($type_labels[$item['type']] ?? $item['type']); ?></span></td>
                    <td><?php echo $item['is_person'] ? '&#10003;' : ''; ?></td>
                    <td><small><?php echo esc_html(implode(', ', $item['aliases'])); ?></small></td>
                    <td>
                        <button type="button" class="button button-small lem-edit-entity" data-entity="<?php echo esc_attr(wp_json_encode($item)); ?>">Изменить</button>
                        <button type="button" class="button button-small button-link-delete lem-delete-entity" data-id="<?php echo esc_attr($item['id']); ?>">Удалить</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php printf('%d записей', $total); ?></span>
            <?php
            echo paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $paged,
                'total'   => $total_pages,
            ]);
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Модальное окно -->
<div id="lem-entity-modal" class="lem-modal" style="display:none">
    <div class="lem-modal-content">
        <h2 id="lem-modal-title">Добавить запись</h2>
        <input type="hidden" id="lem-entity-id" value="0">
        <table class="form-table">
            <tr>
                <th><label for="lem-entity-name">Название</label></th>
                <td><input type="text" id="lem-entity-name" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="lem-entity-type">Тип</label></th>
                <td>
                    <select id="lem-entity-type">
                        <option value="inoagent">Иностранный агент</option>
                        <option value="extremist">Экстремистская орг.</option>
                        <option value="terrorist">Террористическая орг.</option>
                        <option value="undesirable">Нежелательная орг.</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="lem-entity-person">Физлицо</label></th>
                <td><input type="checkbox" id="lem-entity-person" value="1"></td>
            </tr>
            <tr>
                <th><label for="lem-entity-aliases">Алиасы (по одному в строке)</label></th>
                <td><textarea id="lem-entity-aliases" rows="4" class="large-text"></textarea></td>
            </tr>
            <tr>
                <th><label for="lem-entity-status">Свой текст дисклеймера</label></th>
                <td><input type="text" id="lem-entity-status" class="large-text"></td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="lem-save-entity">Сохранить</button>
            <button type="button" class="button" id="lem-cancel-entity">Отмена</button>
            <span id="lem-entity-status-msg" class="lem-inline-status"></span>
        </p>
    </div>
</div>

<script>
(function() {
    var cfg = window.lemAdmin || {};
    var modal = document.getElementById('lem-entity-modal');

    function openModal(entity) {
        document.getElementById('lem-modal-title').textContent = entity ? 'Редактирование' : 'Добавить запись';
        document.getElementById('lem-entity-id').value = entity ? entity.id : 0;
        document.getElementById('lem-entity-name').value = entity ? entity.name : '';
        document.getElementById('lem-entity-type').value = entity ? entity.type : 'inoagent';
        document.getElementById('lem-entity-person').checked = entity ? !!entity.is_person : false;
        document.getElementById('lem-entity-aliases').value = entity ? (entity.aliases || []).join('\n') : '';
        document.getElementById('lem-entity-status').value = entity ? (entity.status_text || '') : '';
        document.getElementById('lem-entity-status-msg').textContent = '';
        modal.style.display = 'flex';
    }

    document.getElementById('lem-add-entity')?.addEventListener('click', function() { openModal(null); });
    document.getElementById('lem-cancel-entity')?.addEventListener('click', function() { modal.style.display = 'none'; });

    document.querySelectorAll('.lem-edit-entity').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal(JSON.parse(this.dataset.entity));
        });
    });

    document.getElementById('lem-save-entity')?.addEventListener('click', function() {
        var btn = this, st = document.getElementById('lem-entity-status-msg');
        btn.disabled = true;
        st.textContent = 'Сохранение...';

        var body = new URLSearchParams({
            action: 'lem_save_entity',
            nonce: cfg.crudNonce,
            id: document.getElementById('lem-entity-id').value,
            name: document.getElementById('lem-entity-name').value,
            type: document.getElementById('lem-entity-type').value,
            is_person: document.getElementById('lem-entity-person').checked ? 1 : 0,
            aliases: document.getElementById('lem-entity-aliases').value,
            status_text: document.getElementById('lem-entity-status').value,
            is_active: 1
        });

        fetch(cfg.ajaxUrl, { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d.success) { modal.style.display = 'none'; location.reload(); }
            else { st.textContent = d.data || 'Ошибка'; }
        })
        .catch(function() { st.textContent = 'Ошибка'; btn.disabled = false; });
    });

    document.querySelectorAll('.lem-delete-entity').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Удалить эту запись?')) return;
            var id = this.dataset.id;
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=lem_delete_entity&nonce=' + cfg.crudNonce + '&id=' + id
            })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.success) location.reload(); });
        });
    });
})();
</script>
