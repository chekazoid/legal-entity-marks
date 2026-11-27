<?php defined('ABSPATH') || exit;

$clem_rules = clem_plugin()->brands->get_rules();
settings_errors('clem_brands');
?>
<div class="wrap">
    <h1><?php echo esc_html__('Бренды СМИ', 'chekazoid-legal-entity-marks'); ?></h1>

    <p class="description" style="max-width:820px">
        <?php echo esc_html__('В реестре Минюста издания записаны юридическими названиями («Интернет-издание "Вёрстка Медиа"»),
        а в статьях их называют брендом («Вёрстки»). Здесь задаётся соответствие. Список предзаполнен
        очевидными случаями, вы можете его дополнять и править под свою редакцию.', 'chekazoid-legal-entity-marks'); ?>
    </p>

    <table class="widefat" style="max-width:820px;margin-bottom:12px">
        <tbody>
            <tr>
                <td><strong><?php echo esc_html__('Искать в названии', 'chekazoid-legal-entity-marks'); ?></strong></td>
                <td><?php echo esc_html__('Кусок официального названия из реестра, по которому находится запись.
                    Если справа стоит', 'chekazoid-legal-entity-marks'); ?> <em><?php echo esc_html__('0 записей', 'chekazoid-legal-entity-marks'); ?></em><?php echo esc_html__(', правило не работает: текст не совпадает с реестром.', 'chekazoid-legal-entity-marks'); ?></td>
            </tr>
            <tr>
                <td><strong><?php echo esc_html__('Обычные алиасы', 'chekazoid-legal-entity-marks'); ?></strong></td>
                <td><?php echo esc_html__('Ловятся и с кавычками, и без. Годятся для различимых имён: DOXA, Медуза, Радио Свобода.', 'chekazoid-legal-entity-marks'); ?></td>
            </tr>
            <tr>
                <td><strong><?php echo esc_html__('Только в кавычках', 'chekazoid-legal-entity-marks'); ?></strong></td>
                <td><?php echo esc_html__('Ловятся, только если написаны в кавычках. Для брендов-обычных слов:
                    издание «Холод» пометится, «на улице холод» нет. Падежи внутри кавычек
                    подхватываются сами: «Вёрстки», «Вёрстке».', 'chekazoid-legal-entity-marks'); ?></td>
            </tr>
        </tbody>
    </table>

    <form method="post">
        <?php wp_nonce_field('clem_save_brands', 'clem_brands_nonce'); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:22%"><?php echo esc_html__('Искать в названии', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:20%"><?php echo esc_html__('Обычные алиасы', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:20%"><?php echo esc_html__('Только в кавычках', 'chekazoid-legal-entity-marks'); ?></th>
                    <th><?php echo esc_html__('Заметка', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:80px"><?php echo esc_html__('Записей', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:60px"><?php echo esc_html__('Вкл.', 'chekazoid-legal-entity-marks'); ?></th>
                    <th style="width:60px"><?php echo esc_html__('Удалить', 'chekazoid-legal-entity-marks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clem_rules as $clem_i => $clem_rule) :
                    $clem_hits = clem_plugin()->brands->count_matches($clem_rule['match']); ?>
                <tr<?php echo $clem_hits === 0 ? ' style="background:#fff4f4"' : ''; ?>>
                    <td>
                        <input type="text" style="width:100%" name="clem_brand[<?php echo esc_html($clem_i); ?>][match]"
                               value="<?php echo esc_attr($clem_rule['match']); ?>">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="clem_brand[<?php echo esc_html($clem_i); ?>][aliases]"
                               value="<?php echo esc_attr(implode(', ', $clem_rule['aliases'])); ?>"
                               placeholder="<?php echo esc_attr__('через запятую', 'chekazoid-legal-entity-marks'); ?>">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="clem_brand[<?php echo esc_html($clem_i); ?>][quoted]"
                               value="<?php echo esc_attr(implode(', ', $clem_rule['quoted'])); ?>"
                               placeholder="<?php echo esc_attr__('через запятую', 'chekazoid-legal-entity-marks'); ?>">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="clem_brand[<?php echo esc_html($clem_i); ?>][note]"
                               value="<?php echo esc_attr($clem_rule['note']); ?>">
                        <input type="text" style="width:100%;margin-top:4px"
                               name="clem_brand[<?php echo esc_html($clem_i); ?>][status]"
                               value="<?php echo esc_attr($clem_rule['status']); ?>"
                               placeholder="<?php echo esc_attr__('своя формулировка сноски (необязательно)', 'chekazoid-legal-entity-marks'); ?>">
                    </td>
                    <td style="text-align:center">
                        <?php if ($clem_hits === 0) : ?>
                            <strong style="color:#b32d2e" title="<?php echo esc_attr__('Правило ничего не находит в реестре', 'chekazoid-legal-entity-marks'); ?>">0</strong>
                        <?php else : ?>
                            <?php echo (int) $clem_hits; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <input type="checkbox" name="clem_brand[<?php echo esc_html($clem_i); ?>][enabled]" value="1"
                               <?php checked($clem_rule['enabled']); ?>>
                    </td>
                    <td style="text-align:center">
                        <input type="checkbox" name="clem_brand[<?php echo esc_html($clem_i); ?>][delete]" value="1">
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php // три пустые строки для новых правил
                for ($clem_n = 0; $clem_n < 3; $clem_n++) : $clem_i = count($clem_rules) + $clem_n; ?>
                <tr>
                    <td><input type="text" style="width:100%" name="clem_brand[<?php echo esc_html($clem_i); ?>][match]" placeholder="<?php echo esc_attr__('кусок названия из реестра', 'chekazoid-legal-entity-marks'); ?>"></td>
                    <td><input type="text" style="width:100%" name="clem_brand[<?php echo esc_html($clem_i); ?>][aliases]" placeholder="<?php echo esc_attr__('через запятую', 'chekazoid-legal-entity-marks'); ?>"></td>
                    <td><input type="text" style="width:100%" name="clem_brand[<?php echo esc_html($clem_i); ?>][quoted]" placeholder="<?php echo esc_attr__('через запятую', 'chekazoid-legal-entity-marks'); ?>"></td>
                    <td>
                        <input type="text" style="width:100%" name="clem_brand[<?php echo esc_html($clem_i); ?>][note]">
                        <input type="text" style="width:100%;margin-top:4px"
                               name="clem_brand[<?php echo esc_html($clem_i); ?>][status]"
                               placeholder="<?php echo esc_attr__('своя формулировка сноски (необязательно)', 'chekazoid-legal-entity-marks'); ?>">
                    </td>
                    <td></td>
                    <td style="text-align:center"><input type="checkbox" name="clem_brand[<?php echo esc_html($clem_i); ?>][enabled]" value="1" checked></td>
                    <td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <?php submit_button(__('Сохранить и применить', 'chekazoid-legal-entity-marks')); ?>
        <p class="description">
            <?php echo esc_html__('После сохранения запустите пересканирование в разделе «Сканер»: алиасы влияют на то,
            что находит сканер.', 'chekazoid-legal-entity-marks'); ?>
        </p>
    </form>
</div>
