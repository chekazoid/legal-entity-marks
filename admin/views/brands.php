<?php defined('ABSPATH') || exit;

$rules = lem()->brands->get_rules();
settings_errors('lem_brands');
?>
<div class="wrap">
    <h1><?php echo esc_html__('Бренды СМИ', 'legal-entity-marks'); ?></h1>

    <p class="description" style="max-width:820px">
        <?php echo esc_html__('В реестре Минюста издания записаны юридическими названиями («Интернет-издание "Вёрстка Медиа"»),
        а в статьях их называют брендом («Вёрстки»). Здесь задаётся соответствие. Список предзаполнен
        очевидными случаями, вы можете его дополнять и править под свою редакцию.', 'legal-entity-marks'); ?>
    </p>

    <table class="widefat" style="max-width:820px;margin-bottom:12px">
        <tbody>
            <tr>
                <td><strong><?php echo esc_html__('Искать в названии', 'legal-entity-marks'); ?></strong></td>
                <td><?php echo esc_html__('Кусок официального названия из реестра, по которому находится запись.
                    Если справа стоит', 'legal-entity-marks'); ?> <em><?php echo esc_html__('0 записей', 'legal-entity-marks'); ?></em><?php echo esc_html__(', правило не работает: текст не совпадает с реестром.', 'legal-entity-marks'); ?></td>
            </tr>
            <tr>
                <td><strong><?php echo esc_html__('Обычные алиасы', 'legal-entity-marks'); ?></strong></td>
                <td><?php echo esc_html__('Ловятся и с кавычками, и без. Годятся для различимых имён: DOXA, Медуза, Радио Свобода.', 'legal-entity-marks'); ?></td>
            </tr>
            <tr>
                <td><strong><?php echo esc_html__('Только в кавычках', 'legal-entity-marks'); ?></strong></td>
                <td><?php echo esc_html__('Ловятся, только если написаны в кавычках. Для брендов-обычных слов:
                    издание «Холод» пометится, «на улице холод» нет. Падежи внутри кавычек
                    подхватываются сами: «Вёрстки», «Вёрстке».', 'legal-entity-marks'); ?></td>
            </tr>
        </tbody>
    </table>

    <form method="post">
        <?php wp_nonce_field('lem_save_brands', 'lem_brands_nonce'); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:22%"><?php echo esc_html__('Искать в названии', 'legal-entity-marks'); ?></th>
                    <th style="width:20%"><?php echo esc_html__('Обычные алиасы', 'legal-entity-marks'); ?></th>
                    <th style="width:20%"><?php echo esc_html__('Только в кавычках', 'legal-entity-marks'); ?></th>
                    <th><?php echo esc_html__('Заметка', 'legal-entity-marks'); ?></th>
                    <th style="width:80px"><?php echo esc_html__('Записей', 'legal-entity-marks'); ?></th>
                    <th style="width:60px"><?php echo esc_html__('Вкл.', 'legal-entity-marks'); ?></th>
                    <th style="width:60px"><?php echo esc_html__('Удалить', 'legal-entity-marks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $i => $rule) :
                    $hits = lem()->brands->count_matches($rule['match']); ?>
                <tr<?php echo $hits === 0 ? ' style="background:#fff4f4"' : ''; ?>>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo esc_html($i); ?>][match]"
                               value="<?php echo esc_attr($rule['match']); ?>">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo esc_html($i); ?>][aliases]"
                               value="<?php echo esc_attr(implode(', ', $rule['aliases'])); ?>"
                               placeholder="<?php echo esc_attr__('через запятую', 'legal-entity-marks'); ?>">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo esc_html($i); ?>][quoted]"
                               value="<?php echo esc_attr(implode(', ', $rule['quoted'])); ?>"
                               placeholder="<?php echo esc_attr__('через запятую', 'legal-entity-marks'); ?>">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo esc_html($i); ?>][note]"
                               value="<?php echo esc_attr($rule['note']); ?>">
                        <input type="text" style="width:100%;margin-top:4px"
                               name="lem_brand[<?php echo esc_html($i); ?>][status]"
                               value="<?php echo esc_attr($rule['status']); ?>"
                               placeholder="<?php echo esc_attr__('своя формулировка сноски (необязательно)', 'legal-entity-marks'); ?>">
                    </td>
                    <td style="text-align:center">
                        <?php if ($hits === 0) : ?>
                            <strong style="color:#b32d2e" title="<?php echo esc_attr__('Правило ничего не находит в реестре', 'legal-entity-marks'); ?>">0</strong>
                        <?php else : ?>
                            <?php echo (int) $hits; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <input type="checkbox" name="lem_brand[<?php echo esc_html($i); ?>][enabled]" value="1"
                               <?php checked($rule['enabled']); ?>>
                    </td>
                    <td style="text-align:center">
                        <input type="checkbox" name="lem_brand[<?php echo esc_html($i); ?>][delete]" value="1">
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php // три пустые строки для новых правил
                for ($n = 0; $n < 3; $n++) : $i = count($rules) + $n; ?>
                <tr>
                    <td><input type="text" style="width:100%" name="lem_brand[<?php echo esc_html($i); ?>][match]" placeholder="<?php echo esc_attr__('кусок названия из реестра', 'legal-entity-marks'); ?>"></td>
                    <td><input type="text" style="width:100%" name="lem_brand[<?php echo esc_html($i); ?>][aliases]" placeholder="<?php echo esc_attr__('через запятую', 'legal-entity-marks'); ?>"></td>
                    <td><input type="text" style="width:100%" name="lem_brand[<?php echo esc_html($i); ?>][quoted]" placeholder="<?php echo esc_attr__('через запятую', 'legal-entity-marks'); ?>"></td>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo esc_html($i); ?>][note]">
                        <input type="text" style="width:100%;margin-top:4px"
                               name="lem_brand[<?php echo esc_html($i); ?>][status]"
                               placeholder="<?php echo esc_attr__('своя формулировка сноски (необязательно)', 'legal-entity-marks'); ?>">
                    </td>
                    <td></td>
                    <td style="text-align:center"><input type="checkbox" name="lem_brand[<?php echo esc_html($i); ?>][enabled]" value="1" checked></td>
                    <td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <?php submit_button(__('Сохранить и применить', 'legal-entity-marks')); ?>
        <p class="description">
            <?php echo esc_html__('После сохранения запустите пересканирование в разделе «Сканер»: алиасы влияют на то,
            что находит сканер.', 'legal-entity-marks'); ?>
        </p>
    </form>
</div>
