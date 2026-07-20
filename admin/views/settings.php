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
                <th scope="row">Какие реестры маркировать</th>
                <td>
                    <?php
                    $registry_labels = [
                        'inoagent'    => 'Иностранные агенты',
                        'extremist'   => 'Экстремистские организации',
                        'terrorist'   => 'Террористические организации',
                        'undesirable' => 'Нежелательные организации',
                    ];
                    foreach ($registry_labels as $key => $label) : ?>
                        <label style="display:block;margin-bottom:4px">
                            <input type="checkbox" name="lem_registries[]"
                                   value="<?php echo esc_attr($key); ?>"
                                   <?php checked(in_array($key, $settings['registries'], true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        Выключенная категория не маркируется и не попадает в блок дисклеймеров.
                        Пересканирование не требуется, изменение действует сразу.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">Поиск имён</th>
                <td>
                    <label style="display:block;margin-bottom:4px">
                        <input type="checkbox" name="lem_match_word_forms" value="1"
                               <?php checked($settings['match_word_forms']); ?>>
                        Искать фамилии и имена во всех падежах
                    </label>
                    <p class="description" style="margin:0 0 10px">
                        Без этого найдётся только «Иванов Иван» из реестра, а «по словам Иванова»
                        или «интервью с Ивановым» пройдут мимо. Учитывается род: женские фамилии
                        на согласную не склоняются.
                    </p>
                    <p style="margin:0 0 4px"><strong>Упоминание одной фамилии без имени</strong></p>
                    <select name="lem_surname_mode" style="max-width:100%">
                        <option value="confirmed" <?php selected($settings['surname_mode'], 'confirmed'); ?>>
                            Только если в статье есть полное имя (рекомендуется)
                        </option>
                        <option value="off" <?php selected($settings['surname_mode'], 'off'); ?>>
                            Не искать, нужно полное «Имя Фамилия»
                        </option>
                        <option value="always" <?php selected($settings['surname_mode'], 'always'); ?>>
                            Искать всегда
                        </option>
                    </select>
                    <p class="description">
                        В статьях принято называть человека полностью при первом упоминании,
                        а дальше по фамилии. Рекомендуемый режим это учитывает: «Лев Пономарев
                        заявил… Пономарев добавил…» будет размечено целиком, а заметка про
                        однофамильца-чиновника не попадёт под маркировку.
                        <br>
                        Режим «искать всегда» даёт ложные срабатывания на однофамильцах:
                        13 из 24 самых частых русских фамилий есть в реестре иноагентов.
                        После изменения запустите пересканирование.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">Иноагенты: только в цитатах</th>
                <td>
                    <label>
                        <input type="checkbox" name="lem_inoagent_context_only" value="1"
                               <?php checked($settings['inoagent_context_only']); ?>>
                        Маркировать иноагентов только там, где их цитируют или дают ссылку
                    </label>
                    <p class="description">
                        Обычное упоминание фамилии в тексте маркироваться не будет.
                        Остальные три реестра правило не затрагивает.
                    </p>

                    <div style="margin-top:10px;padding-left:22px">
                        <p style="margin:0 0 4px"><strong>Что считать поводом для маркировки:</strong></p>
                        <?php
                        $trigger_labels = [
                            'blockquote' => 'Блок цитаты (blockquote, q) вместе с подводкой и подписью',
                            'link'       => 'Абзац с гиперссылкой, текст и адрес ссылки',
                            'quotes'     => 'Абзац с прямой речью в кавычках «…»',
                            'embed'      => 'Встроенный пост из соцсети вместе с подводкой и подписью',
                        ];
                        foreach ($trigger_labels as $key => $label) : ?>
                            <label style="display:block;margin-bottom:4px">
                                <input type="checkbox" name="lem_context_triggers[]"
                                       value="<?php echo esc_attr($key); ?>"
                                       <?php checked(!empty($settings['context_triggers'][$key])); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            После изменения этих условий запустите пересканирование:
                            признак «упомянут в цитате» вычисляется во время сканирования.
                        </p>
                    </div>
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
                    <p class="description">Приоритет фильтра the_content. Чем больше - тем позже выполняется. По умолчанию: 9999.</p>
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
