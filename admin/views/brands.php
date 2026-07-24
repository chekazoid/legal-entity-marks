<?php defined('ABSPATH') || exit;

$rules = lem()->brands->get_rules();
settings_errors('lem_brands');
?>
<div class="wrap">
    <h1>Бренды СМИ</h1>

    <p class="description" style="max-width:820px">
        В реестре Минюста издания записаны юридическими названиями («Интернет-издание "Вёрстка Медиа"»),
        а в статьях их называют брендом («Вёрстки»). Здесь задаётся соответствие. Список предзаполнен
        очевидными случаями, вы можете его дополнять и править под свою редакцию.
    </p>

    <table class="widefat" style="max-width:820px;margin-bottom:12px">
        <tbody>
            <tr>
                <td><strong>Искать в названии</strong></td>
                <td>Кусок официального названия из реестра, по которому находится запись.
                    Если справа стоит <em>0 записей</em>, правило не работает: текст не совпадает с реестром.</td>
            </tr>
            <tr>
                <td><strong>Обычные алиасы</strong></td>
                <td>Ловятся и с кавычками, и без. Годятся для различимых имён: DOXA, Медуза, Радио Свобода.</td>
            </tr>
            <tr>
                <td><strong>Только в кавычках</strong></td>
                <td>Ловятся, только если написаны в кавычках. Для брендов-обычных слов:
                    издание «Холод» пометится, «на улице холод» нет. Падежи внутри кавычек
                    подхватываются сами: «Вёрстки», «Вёрстке».</td>
            </tr>
        </tbody>
    </table>

    <form method="post">
        <?php wp_nonce_field('lem_save_brands', 'lem_brands_nonce'); ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:22%">Искать в названии</th>
                    <th style="width:20%">Обычные алиасы</th>
                    <th style="width:20%">Только в кавычках</th>
                    <th>Заметка</th>
                    <th style="width:80px">Записей</th>
                    <th style="width:60px">Вкл.</th>
                    <th style="width:60px">Удалить</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $i => $rule) :
                    $hits = lem()->brands->count_matches($rule['match']); ?>
                <tr<?php echo $hits === 0 ? ' style="background:#fff4f4"' : ''; ?>>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo $i; ?>][match]"
                               value="<?php echo esc_attr($rule['match']); ?>">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo $i; ?>][aliases]"
                               value="<?php echo esc_attr(implode(', ', $rule['aliases'])); ?>"
                               placeholder="через запятую">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo $i; ?>][quoted]"
                               value="<?php echo esc_attr(implode(', ', $rule['quoted'])); ?>"
                               placeholder="через запятую">
                    </td>
                    <td>
                        <input type="text" style="width:100%" name="lem_brand[<?php echo $i; ?>][note]"
                               value="<?php echo esc_attr($rule['note']); ?>">
                    </td>
                    <td style="text-align:center">
                        <?php if ($hits === 0) : ?>
                            <strong style="color:#b32d2e" title="Правило ничего не находит в реестре">0</strong>
                        <?php else : ?>
                            <?php echo (int) $hits; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <input type="checkbox" name="lem_brand[<?php echo $i; ?>][enabled]" value="1"
                               <?php checked($rule['enabled']); ?>>
                    </td>
                    <td style="text-align:center">
                        <input type="checkbox" name="lem_brand[<?php echo $i; ?>][delete]" value="1">
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php // три пустые строки для новых правил
                for ($n = 0; $n < 3; $n++) : $i = count($rules) + $n; ?>
                <tr>
                    <td><input type="text" style="width:100%" name="lem_brand[<?php echo $i; ?>][match]" placeholder="кусок названия из реестра"></td>
                    <td><input type="text" style="width:100%" name="lem_brand[<?php echo $i; ?>][aliases]" placeholder="через запятую"></td>
                    <td><input type="text" style="width:100%" name="lem_brand[<?php echo $i; ?>][quoted]" placeholder="через запятую"></td>
                    <td><input type="text" style="width:100%" name="lem_brand[<?php echo $i; ?>][note]"></td>
                    <td></td>
                    <td style="text-align:center"><input type="checkbox" name="lem_brand[<?php echo $i; ?>][enabled]" value="1" checked></td>
                    <td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <?php submit_button('Сохранить и применить'); ?>
        <p class="description">
            После сохранения запустите пересканирование в разделе «Сканер»: алиасы влияют на то,
            что находит сканер.
        </p>
    </form>
</div>
