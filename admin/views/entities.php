<?php defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$search      = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$clem_type_filter = sanitize_text_field(wp_unslash($_GET['type'] ?? ''));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin search/filter; sanitized input, no state changes.
$paged       = max(1, absint(wp_unslash($_GET['paged'] ?? 1)));
$per_page    = 50;

$clem_result = clem_plugin()->entities->search([
    'search'    => $search,
    'type'      => $clem_type_filter,
    'is_active' => 1,
    'limit'     => $per_page,
    'offset'    => ($paged - 1) * $per_page,
    'orderby'   => 'name',
    'order'     => 'ASC',
]);

$clem_items       = $clem_result['items'];
$clem_total       = $clem_result['total'];
$clem_total_pages = ceil($clem_total / $per_page);

$clem_type_labels = [
    'inoagent'    => __('Иноагент', 'chekazoid-legal-entity-marks'),
    'extremist'   => __('Экстремист.', 'chekazoid-legal-entity-marks'),
    'terrorist'   => __('Террорист.', 'chekazoid-legal-entity-marks'),
    'undesirable' => __('Нежелат.', 'chekazoid-legal-entity-marks'),
    'fedsfm_person' => __('Физлица: Росфинмониторинг', 'chekazoid-legal-entity-marks'),
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html__('Реестр сущностей', 'chekazoid-legal-entity-marks'); ?></h1>
    <button type="button" class="page-title-action" id="clem-add-entity"><?php echo esc_html__('Добавить', 'chekazoid-legal-entity-marks'); ?></button>

    <form method="get" class="clem-filter-form">
        <input type="hidden" name="page" value="clem-entities">
        <select name="type">
            <option value=""><?php echo esc_html__('Все типы', 'chekazoid-legal-entity-marks'); ?></option>
            <option value="inoagent" <?php selected($clem_type_filter, 'inoagent'); ?>><?php echo esc_html__('Иностранный агент', 'chekazoid-legal-entity-marks'); ?></option>
            <option value="extremist" <?php selected($clem_type_filter, 'extremist'); ?>><?php echo esc_html__('Экстремистская орг.', 'chekazoid-legal-entity-marks'); ?></option>
            <option value="terrorist" <?php selected($clem_type_filter, 'terrorist'); ?>><?php echo esc_html__('Террористическая орг.', 'chekazoid-legal-entity-marks'); ?></option>
            <option value="undesirable" <?php selected($clem_type_filter, 'undesirable'); ?>><?php echo esc_html__('Нежелательная орг.', 'chekazoid-legal-entity-marks'); ?></option>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Поиск...', 'chekazoid-legal-entity-marks'); ?>">
        <button type="submit" class="button"><?php echo esc_html__('Фильтр', 'chekazoid-legal-entity-marks'); ?></button>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:40px">ID</th>
                <th><?php echo esc_html__('Название', 'chekazoid-legal-entity-marks'); ?></th>
                <th style="width:120px"><?php echo esc_html__('Тип', 'chekazoid-legal-entity-marks'); ?></th>
                <th style="width:70px"><?php echo esc_html__('Физлицо', 'chekazoid-legal-entity-marks'); ?></th>
                <th><?php echo esc_html__('Алиасы', 'chekazoid-legal-entity-marks'); ?></th>
                <th style="width:140px"><?php echo esc_html__('Действия', 'chekazoid-legal-entity-marks'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($clem_items)) : ?>
                <tr><td colspan="6"><?php echo esc_html__('Записи не найдены.', 'chekazoid-legal-entity-marks'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($clem_items as $clem_item) : ?>
                <tr data-id="<?php echo esc_attr($clem_item['id']); ?>">
                    <td><?php echo esc_html($clem_item['id']); ?></td>
                    <td><strong><?php echo esc_html($clem_item['name']); ?></strong></td>
                    <td><span class="clem-badge clem-badge-<?php echo esc_attr($clem_item['type']); ?>"><?php echo esc_html($clem_type_labels[$clem_item['type']] ?? $clem_item['type']); ?></span></td>
                    <td><?php echo $clem_item['is_person'] ? '&#10003;' : ''; ?></td>
                    <td><small><?php echo esc_html(implode(', ', $clem_item['aliases'])); ?></small></td>
                    <td>
                        <button type="button" class="button button-small clem-edit-entity" data-entity="<?php echo esc_attr(wp_json_encode($clem_item)); ?>"><?php echo esc_html__('Изменить', 'chekazoid-legal-entity-marks'); ?></button>
                        <button type="button" class="button button-small button-link-delete clem-delete-entity" data-id="<?php echo esc_attr($clem_item['id']); ?>"><?php echo esc_html__('Удалить', 'chekazoid-legal-entity-marks'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($clem_total_pages > 1) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php printf(/* translators: Formatted number of registry entries. */ esc_html__('%1$s записей', 'chekazoid-legal-entity-marks'), esc_html(number_format_i18n($clem_total))); ?></span>
            <?php
            echo wp_kses_post(paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $paged,
                'total'   => $clem_total_pages,
            ]));
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Модальное окно -->
<div id="clem-entity-modal" class="clem-modal" style="display:none">
    <div class="clem-modal-content">
        <h2 id="clem-modal-title"><?php echo esc_html__('Добавить запись', 'chekazoid-legal-entity-marks'); ?></h2>
        <input type="hidden" id="clem-entity-id" value="0">
        <table class="form-table">
            <tr>
                <th><label for="clem-entity-name"><?php echo esc_html__('Название', 'chekazoid-legal-entity-marks'); ?></label></th>
                <td><input type="text" id="clem-entity-name" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="clem-entity-type"><?php echo esc_html__('Тип', 'chekazoid-legal-entity-marks'); ?></label></th>
                <td>
                    <select id="clem-entity-type">
                        <option value="inoagent"><?php echo esc_html__('Иностранный агент', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="extremist"><?php echo esc_html__('Экстремистская орг.', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="terrorist"><?php echo esc_html__('Террористическая орг.', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="undesirable"><?php echo esc_html__('Нежелательная орг.', 'chekazoid-legal-entity-marks'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="clem-entity-person"><?php echo esc_html__('Физлицо', 'chekazoid-legal-entity-marks'); ?></label></th>
                <td><input type="checkbox" id="clem-entity-person" value="1"></td>
            </tr>
            <tr>
                <th><label for="clem-entity-aliases"><?php echo esc_html__('Алиасы (по одному в строке)', 'chekazoid-legal-entity-marks'); ?></label></th>
                <td><textarea id="clem-entity-aliases" rows="4" class="large-text"></textarea></td>
            </tr>
            <tr>
                <th><label for="clem-entity-status"><?php echo esc_html__('Свой текст дисклеймера', 'chekazoid-legal-entity-marks'); ?></label></th>
                <td><input type="text" id="clem-entity-status" class="large-text"></td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="clem-save-entity"><?php echo esc_html__('Сохранить', 'chekazoid-legal-entity-marks'); ?></button>
            <button type="button" class="button" id="clem-cancel-entity"><?php echo esc_html__('Отмена', 'chekazoid-legal-entity-marks'); ?></button>
            <span id="clem-entity-status-msg" class="clem-inline-status"></span>
        </p>
    </div>
</div>
