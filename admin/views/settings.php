<?php defined('ABSPATH') || exit;

$settings = lem()->get_settings();
settings_errors('lem_settings');

$all_post_types = get_post_types(['public' => true], 'objects');
?>
<div class="wrap">
    <h1>Настройки маркировки</h1>

    <form method="post">
        <?php wp_nonce_field('lem_save_settings', 'lem_settings_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">Типы записей</th>
                <td>
                    <?php foreach ($all_post_types as $pt) : ?>
                        <label style="display:block;margin-bottom:4px">
                            <input type="checkbox" name="lem_post_types[]"
                                   value="<?php echo esc_attr($pt->name); ?>"
                                   <?php checked(in_array($pt->name, $settings['post_types'], true)); ?>>
                            <?php echo esc_html($pt->label); ?> <code>(<?php echo esc_html($pt->name); ?>)</code>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">Какие типы записей сканировать и маркировать.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_filter_priority">Приоритет фильтра</label>
                </th>
                <td>
                    <input type="number" id="lem_filter_priority" name="lem_filter_priority"
                           value="<?php echo esc_attr($settings['filter_priority']); ?>"
                           min="1" max="99999" class="small-text">
                    <p class="description">Приоритет фильтра the_content. Чем больше — тем позже выполняется. По умолчанию: 9999.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_accent_color">Цвет маркера</label>
                </th>
                <td>
                    <input type="color" id="lem_accent_color" name="lem_accent_color"
                           value="<?php echo esc_attr($settings['accent_color']); ?>">
                    <p class="description">Цвет сносок-маркеров в тексте статьи.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_disclaimer_bg">Фон дисклеймера</label>
                </th>
                <td>
                    <input type="color" id="lem_disclaimer_bg" name="lem_disclaimer_bg"
                           value="<?php echo esc_attr($settings['disclaimer_bg']); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_disclaimer_border">Рамка дисклеймера</label>
                </th>
                <td>
                    <input type="color" id="lem_disclaimer_border" name="lem_disclaimer_border"
                           value="<?php echo esc_attr($settings['disclaimer_border']); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_cron_interval">Интервал обновления</label>
                </th>
                <td>
                    <select id="lem_cron_interval" name="lem_cron_interval">
                        <option value="daily" <?php selected($settings['cron_interval'], 'daily'); ?>>Ежедневно</option>
                        <option value="twicedaily" <?php selected($settings['cron_interval'], 'twicedaily'); ?>>Дважды в день</option>
                        <option value="weekly" <?php selected($settings['cron_interval'], 'weekly'); ?>>Еженедельно</option>
                    </select>
                    <p class="description">Как часто автоматически проверять обновления реестров.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">Автоскан при публикации</th>
                <td>
                    <label>
                        <input type="checkbox" name="lem_auto_scan" value="1"
                               <?php checked($settings['auto_scan_on_publish']); ?>>
                        Автоматически сканировать статьи при публикации.
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button('Сохранить настройки'); ?>
    </form>
</div>
