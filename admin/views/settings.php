<?php defined('ABSPATH') || exit;

$settings = lem()->get_settings();
settings_errors('lem_settings');

$all_post_types = get_post_types(['public' => true], 'objects');
?>
<div class="wrap">
    <h1><?php echo esc_html__('Настройки маркировки', 'legal-entity-marks'); ?></h1>

    <form method="post">
        <?php wp_nonce_field('lem_save_settings', 'lem_settings_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><?php echo esc_html__('Типы записей', 'legal-entity-marks'); ?></th>
                <td>
                    <?php foreach ($all_post_types as $pt) : ?>
                        <label style="display:block;margin-bottom:4px">
                            <input type="checkbox" name="lem_post_types[]"
                                   value="<?php echo esc_attr($pt->name); ?>"
                                   <?php checked(in_array($pt->name, $settings['post_types'], true)); ?>>
                            <?php echo esc_html($pt->label); ?> <code>(<?php echo esc_html($pt->name); ?>)</code>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php echo esc_html__('Какие типы записей сканировать и маркировать.', 'legal-entity-marks'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Профиль сайта', 'legal-entity-marks'); ?></th>
                <td>
                    <?php foreach (LEM_Plugin::get_presets() as $key => $preset) : ?>
                        <label style="display:block;margin-bottom:6px">
                            <input type="radio" name="lem_preset" value="<?php echo esc_attr($key); ?>"
                                   <?php checked($settings['preset'], $key); ?>>
                            <strong><?php echo esc_html($preset['label']); ?></strong>
                            <span class="description"><?php echo esc_html($preset['hint']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Реестры', 'legal-entity-marks'); ?></th>
                <td>
                    <?php
                    $registry_labels = [
                        'inoagent'    => __('Иностранные агенты', 'legal-entity-marks'),
                        'extremist'   => __('Экстремистские организации', 'legal-entity-marks'),
                        'terrorist'   => __('Террористические организации', 'legal-entity-marks'),
                        'undesirable' => __('Нежелательные организации', 'legal-entity-marks'),
                    ];
                    $manual = $settings['preset'] === 'manual';
                    ?>
                    <table class="widefat" style="max-width:560px">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Реестр', 'legal-entity-marks'); ?></th>
                                <th style="width:110px;text-align:center"><?php echo esc_html__('Маркировать', 'legal-entity-marks'); ?></th>
                                <th style="width:130px;text-align:center"><?php echo esc_html__('Отслеживать', 'legal-entity-marks'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($registry_labels as $key => $label) :
                            $is_marked = in_array($key, $settings['mark_registries'], true); ?>
                            <tr>
                                <td><?php echo esc_html($label); ?></td>
                                <td style="text-align:center">
                                    <input type="checkbox" class="lem-mark" data-registry="<?php echo esc_attr($key); ?>"
                                           name="lem_mark_registries[]"
                                           value="<?php echo esc_attr($key); ?>"
                                           <?php checked($is_marked); ?>
                                           <?php disabled(!$manual); ?>>
                                </td>
                                <td style="text-align:center">
                                    <?php // Маркируемый реестр отслеживается по определению, галочку не снять ?>
                                    <input type="checkbox" class="lem-track" data-registry="<?php echo esc_attr($key); ?>"
                                           name="lem_track_registries[]"
                                           value="<?php echo esc_attr($key); ?>"
                                           <?php checked(in_array($key, $settings['track_registries'], true)); ?>
                                           <?php disabled(!$manual || $is_marked); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description">
                        <strong><?php echo esc_html__('Маркировать', 'legal-entity-marks'); ?></strong> <?php echo esc_html__('- сноска в тексте и строка в блоке дисклеймеров.', 'legal-entity-marks'); ?><br>
                        <strong><?php echo esc_html__('Отслеживать', 'legal-entity-marks'); ?></strong> <?php echo esc_html__('- упоминания и ссылки видны в отчёте. Автоматических меток нет. Чтобы поставить метку в отдельной статье, выберите «Всегда помечать» в её редакторе.', 'legal-entity-marks'); ?><br>
                        <?php echo esc_html__('Маркируемый реестр отслеживается автоматически. Изменения действуют сразу,
                        пересканирование не требуется.', 'legal-entity-marks'); ?>
                        <br><em id="lem-preset-hint" <?php echo $manual ? 'style="display:none"' : ''; ?>><?php echo esc_html__('Галочки
                        расставляет выбранный профиль. Чтобы менять вручную,
                        выберите профиль «Вручную».', 'legal-entity-marks'); ?></em>
                    </p>
                    <?php // Признак того, что галочки были доступны для правки: без него
                          // сохранение профиля не должно трогать сохранённые списки ?>
                    <input type="hidden" id="lem-registries-present" name="lem_registries_present"
                           value="1" <?php disabled(!$manual); ?>>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Физические лица: Росфинмониторинг', 'legal-entity-marks'); ?></th>
                <td>
                    <select name="lem_fedsfm_people_mode">
                        <?php foreach (['off' => __('Выключено', 'legal-entity-marks'), 'track' => __('Только отслеживать', 'legal-entity-marks'), 'mark' => __('Маркировать', 'legal-entity-marks')] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['fedsfm_people_mode'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="max-width:700px">
                        <?php echo esc_html__('Дополнительный перечень физических лиц из «Наблюдателя». По умолчанию выключен,
                        профили сайта его не включают. Для загрузки нужны включённый канал и токен канала.
                        Загрузка идёт в фоне, порциями; после завершения проверяется архив публикаций.', 'legal-entity-marks'); ?>
                    </p>
                    <p class="description" style="max-width:700px">
                        <?php echo esc_html__('Ищется полное ФИО, включая отчество, если оно есть в перечне.
                        Совпадения по одной фамилии, инициалам и неполному имени не маркируются.
                        У разных людей могут совпадать даже полные ФИО: такие случаи проверяет редакция.', 'legal-entity-marks'); ?>
                    </p>
                    <?php
                    $people_state = (array) get_option(LEM_People::STATE, []);
                    $people_success = (array) get_option(LEM_People::SUCCESS, []);
                    if ($settings['fedsfm_people_mode'] !== 'off') :
                        if (!empty($people_state['error'])) : ?>
                        <p class="description" style="color:#b32d2e"><?php echo esc_html($people_state['error']); ?> <?php echo esc_html__('Для повторной попытки сохраните настройки или нажмите «Обновить реестры» на «Обзоре».', 'legal-entity-marks'); ?></p>
                        <?php elseif (!empty($people_state['running'])) : ?>
                        <p class="description"><?php echo esc_html__('Идёт загрузка:', 'legal-entity-marks'); ?> <?php echo (int) $people_state['offset']; ?> <?php echo esc_html__('из', 'legal-entity-marks'); ?> <?php echo (int) $people_state['total']; ?> <?php echo esc_html__('ФИО.', 'legal-entity-marks'); ?></p>
                        <?php endif;
                        if (!empty($people_success['synced_at'])) : ?>
                        <p class="description"><?php echo esc_html__('Последняя успешная сверка:', 'legal-entity-marks'); ?> <?php echo esc_html($people_success['synced_at']); ?><?php echo esc_html__('. Срез канала:', 'legal-entity-marks'); ?> <?php echo esc_html($people_success['fetched_at']); ?><?php echo esc_html__('. ФИО:', 'legal-entity-marks'); ?> <?php echo (int) $people_success['total']; ?>.</p>
                        <?php endif;
                    endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Ссылки, подлежащие удалению', 'legal-entity-marks'); ?></th>
                <td>
                    <p class="description" style="max-width:640px;margin-bottom:8px">
                        <?php echo esc_html__('Ссылка на ресурс нежелательной организации может толковаться как
                        участие в её деятельности, такие ссылки убирают из текста.
                        Ссылка на сайт иноагента ничем не запрещена, ей достаточно маркировки.
                        Отметьте реестры, ссылки на ресурсы которых считать подлежащими удалению.', 'legal-entity-marks'); ?>
                    </p>
                    <?php foreach ($registry_labels as $key => $label) : ?>
                        <label style="display:inline-block;margin-right:16px">
                            <input type="checkbox" name="lem_link_registries[]"
                                   value="<?php echo esc_attr($key); ?>"
                                   <?php checked(in_array($key, $settings['link_registries'], true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php echo esc_html__('Найденные ссылки видны в разделе «Ссылки» в любом случае, отметка
                        влияет только на то, что удаляется из текста.', 'legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Канал реестров', 'legal-entity-marks'); ?></th>
                <td>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        <?php echo esc_html__('Запасной источник на случай, когда официальный реестр не отвечает:
                        сайт Минюста бывает недоступен, а хостинг может резать исходящие
                        соединения. Данные там свежее снимка из поставки плагина.
                        Порядок такой: официальный реестр, потом канал, зеркало (если оно есть)
                        и встроенный перечень. Канал поддерживает все четыре реестра плагина.', 'legal-entity-marks'); ?>
                    </p>
                    <label style="display:block;margin-bottom:8px">
                        <input type="checkbox" name="lem_channel_enabled" value="1"
                               <?php checked($settings['channel_enabled']); ?>>
                        <?php echo esc_html__('Использовать канал реестров как запасной источник', 'legal-entity-marks'); ?>
                    </label>

                    <p style="margin:0 0 6px">
                        <label for="lem-channel-url" style="display:inline-block;min-width:70px"><?php echo esc_html__('Адрес', 'legal-entity-marks'); ?></label>
                        <input type="url" id="lem-channel-url" name="lem_channel_url" class="regular-text"
                               value="<?php echo esc_attr($settings['channel_url']); ?>"
                               placeholder="<?php echo esc_attr(LEM_Channel::DEFAULT_URL); ?>">
                    </p>

                    <?php
                    // Токен на страницу не выводим: поле всегда пустое, а сохранённый
                    // остаётся, пока не введут новый или не отметят «удалить»
                    $has_token   = lem()->channel->has_token();
                    $from_config = defined('LEM_CHANNEL_TOKEN') && LEM_CHANNEL_TOKEN !== '';
                    ?>
                    <p style="margin:0 0 6px">
                        <label for="lem-channel-token" style="display:inline-block;min-width:70px"><?php echo esc_html__('Токен', 'legal-entity-marks'); ?></label>
                        <input type="password" id="lem-channel-token" name="lem_channel_token"
                               class="regular-text" autocomplete="new-password"
                               placeholder="<?php echo $has_token ? esc_attr__('сохранён, оставьте пустым', 'legal-entity-marks') : esc_attr__('не требуется', 'legal-entity-marks'); ?>"
                               <?php disabled($from_config); ?>>
                        <?php if ($has_token && !$from_config) : ?>
                            <label style="margin-left:10px">
                                <input type="checkbox" name="lem_channel_token_clear" value="1">
                                <?php echo esc_html__('удалить', 'legal-entity-marks'); ?>
                            </label>
                        <?php endif; ?>
                    </p>
                    <p class="description" style="max-width:700px">
                        <strong><?php echo esc_html__('Для четырёх основных реестров токен не нужен', 'legal-entity-marks'); ?></strong><?php echo esc_html__(', выгрузки открыты.
                        Токен требуется для добровольной загрузки физических лиц из перечня
                        Росфинмониторинга. Без её включения этот перечень не скачивается.', 'legal-entity-marks'); ?>
                        <?php if ($from_config) : ?>
                            <br><?php echo esc_html__('Сейчас токен задан константой', 'legal-entity-marks'); ?> <code>LEM_CHANNEL_TOKEN</code>
                            <?php echo esc_html__('в wp-config.php и настройкой не перекрывается.', 'legal-entity-marks'); ?>
                        <?php else : ?>
                            <br><?php echo esc_html__('Токен хранится в настройках сайта и на страницу не выводится.
                            Его можно держать вне базы, добавив в wp-config.php строку', 'legal-entity-marks'); ?>
                            <code>define('LEM_CHANNEL_TOKEN', '...');</code>
                        <?php endif; ?>
                    </p>

                    <p>
                        <button type="button" class="button" id="lem-check-channel"><?php echo esc_html__('Проверить канал', 'legal-entity-marks'); ?></button>
                        <span id="lem-channel-status" class="lem-inline-status"></span>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Обновление и удаление', 'legal-entity-marks'); ?></th>
                <td>
                    <p class="description" style="max-width:700px;margin-bottom:10px">
                        <strong><?php echo esc_html__('Обновляйте загрузкой поверх.', 'legal-entity-marks'); ?></strong> <?php echo esc_html__('«Плагины → Добавить новый →
                        Загрузить плагин», выбрать архив, на вопрос об уже установленной версии
                        ответить «Заменить текущей версией». Настройки, реестры и результаты
                        сканирования при этом сохраняются.', 'legal-entity-marks'); ?>
                        <br><?php echo esc_html__('Удалять плагин перед установкой новой версии не нужно: раньше так
                        приходилось делать, и каждый такой круг стирал всё нажитое.', 'legal-entity-marks'); ?>
                    </p>
                    <label style="display:block">
                        <input type="checkbox" name="lem_delete_data" value="1"
                               <?php checked($settings['delete_data_on_uninstall']); ?>>
                        <?php echo esc_html__('Удалить все данные плагина, если его удалят из WordPress', 'legal-entity-marks'); ?>
                    </label>
                    <p class="description" style="max-width:700px">
                        <?php echo esc_html__('По умолчанию выключено: при удалении плагина реестры, настройки
                        и результаты сканирования остаются в базе, и повторная установка
                        подхватывает их как были. Включайте, если убираете плагин насовсем
                        и хотите, чтобы после него ничего не осталось.', 'legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Помочь развитию плагина', 'legal-entity-marks'); ?></th>
                <td>
                    <?php
                    $payload       = lem()->channel->registration_payload();
                    $registered_at = get_option('lem_registered_at', '');
                    ?>
                    <p class="description" style="max-width:700px;margin-bottom:10px">
                        <?php echo esc_html__('Плагин раздаётся бесплатно, и понять, сколько людей им пользуется,
                        можно только с вашей помощью. Ничего обязательного тут нет:
                        и счётчик, и регистрацию можно не включать, плагин будет работать
                        точно так же.', 'legal-entity-marks'); ?>
                    </p>

                    <label style="display:block;margin-bottom:6px">
                        <input type="checkbox" name="lem_stats_enabled" value="1"
                               <?php checked($settings['stats_enabled']); ?>>
                        <?php echo esc_html__('Разрешить отправку случайного идентификатора и версии для подсчёта установок', 'legal-entity-marks'); ?>
                    </label>
                    <p class="description" style="max-width:700px;margin-bottom:14px">
                        <?php echo esc_html__('По умолчанию счётчик выключен. Чтобы разрешить отправку, отметьте галочку и сохраните настройки.
                        Раз в неделю, вместе с обновлением реестров, плагин проверяет
                        доступность канала - это нужно и само по себе, чтобы знать
                        о запасном источнике заранее, а не в тот момент, когда официальный
                        реестр уже упал. С этим запросом уходят две вещи: случайный
                        идентификатор установки
                        (', 'legal-entity-marks'); ?><code><?php echo esc_html($payload['install_id']); ?></code><?php echo esc_html__(')
                        и версия плагина. Ни домена, ни адреса, ни чего-либо о содержимом
                        сайта. Идентификатор к сайту не привязан и сохраняется вместе с настройками плагина.
                        Со снятой галочкой проверка канала остаётся, но уходит без этих
                        заголовков, и посчитать установку по ней нельзя.', 'legal-entity-marks'); ?>
                    </p>

                    <p style="margin-bottom:6px"><strong><?php echo esc_html__('Зарегистрировать сайт', 'legal-entity-marks'); ?></strong></p>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        <?php echo esc_html__('Это уже не анонимно. Регистрация нужна, чтобы автор мог предупредить
                        о важном обновлении или о поломке в реестрах. Отправляется', 'legal-entity-marks'); ?>
                        <strong><?php echo esc_html__('только по нажатию кнопки', 'legal-entity-marks'); ?></strong> <?php echo esc_html__('и ровно в таком составе:', 'legal-entity-marks'); ?>
                    </p>
                    <table class="widefat" style="max-width:640px;margin-bottom:8px">
                        <tbody>
                            <tr><td style="width:190px"><?php echo esc_html__('Адрес сайта', 'legal-entity-marks'); ?></td>
                                <td><code><?php echo esc_html($payload['site']); ?></code></td></tr>
                            <tr><td><?php echo esc_html__('Название сайта', 'legal-entity-marks'); ?></td>
                                <td><?php echo esc_html($payload['name']); ?></td></tr>
                            <tr><td><?php echo esc_html__('Версия плагина', 'legal-entity-marks'); ?></td>
                                <td><?php echo esc_html($payload['plugin']); ?></td></tr>
                            <tr><td><?php echo esc_html__('Версия WordPress', 'legal-entity-marks'); ?></td>
                                <td><?php echo esc_html($payload['wordpress']); ?></td></tr>
                            <tr><td><?php echo esc_html__('Версия PHP', 'legal-entity-marks'); ?></td>
                                <td><?php echo esc_html($payload['php']); ?></td></tr>
                            <tr><td><?php echo esc_html__('Идентификатор установки', 'legal-entity-marks'); ?></td>
                                <td><code><?php echo esc_html($payload['install_id']); ?></code></td></tr>
                            <tr><td><?php echo esc_html__('Почта для связи', 'legal-entity-marks'); ?></td>
                                <td><input type="email" id="lem-register-email" class="regular-text"
                                           placeholder="<?php echo esc_attr__('необязательно', 'legal-entity-marks'); ?>"></td></tr>
                        </tbody>
                    </table>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        <?php echo esc_html__('Больше ничего не уходит: ни содержимое статей, ни списки найденных
                        организаций, ни данные редакции.', 'legal-entity-marks'); ?>
                    </p>
                    <p>
                        <button type="button" class="button" id="lem-register-site">
                            <?php echo $registered_at ? esc_html__('Отправить ещё раз', 'legal-entity-marks') : esc_html__('Отправить', 'legal-entity-marks'); ?>
                        </button>
                        <span id="lem-register-status" class="lem-inline-status">
                            <?php if ($registered_at) : ?>
                                <?php echo esc_html__('Сайт зарегистрирован', 'legal-entity-marks'); ?> <?php echo esc_html($registered_at); ?>
                            <?php endif; ?>
                        </span>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Поиск имён', 'legal-entity-marks'); ?></th>
                <td>
                    <label style="display:block;margin-bottom:4px">
                        <input type="checkbox" name="lem_match_word_forms" value="1"
                               <?php checked($settings['match_word_forms']); ?>>
                        <?php echo esc_html__('Искать фамилии и имена во всех падежах', 'legal-entity-marks'); ?>
                    </label>
                    <p class="description" style="margin:0 0 10px">
                        <?php echo esc_html__('Без этого найдётся только «Иванов Иван» из реестра, а «по словам Иванова»
                        или «интервью с Ивановым» пройдут мимо. Учитывается род: женские фамилии
                        на согласную не склоняются.', 'legal-entity-marks'); ?>
                    </p>
                    <p style="margin:0 0 4px"><strong><?php echo esc_html__('Упоминание одной фамилии без имени', 'legal-entity-marks'); ?></strong></p>
                    <select name="lem_surname_mode" style="max-width:100%">
                        <option value="confirmed" <?php selected($settings['surname_mode'], 'confirmed'); ?>>
                            <?php echo esc_html__('Только если в статье есть полное имя (рекомендуется)', 'legal-entity-marks'); ?>
                        </option>
                        <option value="off" <?php selected($settings['surname_mode'], 'off'); ?>>
                            <?php echo esc_html__('Не искать, нужно полное «Имя Фамилия»', 'legal-entity-marks'); ?>
                        </option>
                        <option value="always" <?php selected($settings['surname_mode'], 'always'); ?>>
                            <?php echo esc_html__('Искать всегда', 'legal-entity-marks'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php echo esc_html__('В статьях принято называть человека полностью при первом упоминании,
                        а дальше по фамилии. Рекомендуемый режим это учитывает: «Лев Пономарев
                        заявил… Пономарев добавил…» будет размечено целиком, а заметка про
                        однофамильца-чиновника не попадёт под маркировку.', 'legal-entity-marks'); ?>
                        <br>
                        <?php echo esc_html__('Режим «искать всегда» даёт ложные срабатывания на однофамильцах:
                        13 из 24 самых частых русских фамилий есть в реестре иноагентов.
                        После изменения запустите пересканирование.', 'legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Иноагенты: только в цитатах', 'legal-entity-marks'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="lem_inoagent_context_only" value="1"
                               <?php checked($settings['inoagent_context_only']); ?>>
                        <?php echo esc_html__('Маркировать иноагентов только там, где их цитируют или дают ссылку', 'legal-entity-marks'); ?>
                    </label>
                    <p class="description">
                        <?php echo esc_html__('Обычное упоминание фамилии в тексте маркироваться не будет.
                        Остальные три реестра правило не затрагивает.', 'legal-entity-marks'); ?>
                    </p>

                    <div style="margin-top:10px;padding-left:22px">
                        <p style="margin:0 0 4px"><strong><?php echo esc_html__('Что считать поводом для маркировки:', 'legal-entity-marks'); ?></strong></p>
                        <?php
                        $trigger_labels = [
                            'blockquote' => __('Блок цитаты (blockquote, q) вместе с подводкой и подписью', 'legal-entity-marks'),
                            'link'       => __('Абзац с гиперссылкой, текст и адрес ссылки', 'legal-entity-marks'),
                            'quotes'     => __('Абзац с прямой речью в кавычках «…»', 'legal-entity-marks'),
                            'embed'      => __('Встроенный пост из соцсети вместе с подводкой и подписью', 'legal-entity-marks'),
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
                            <?php echo esc_html__('После изменения этих условий запустите пересканирование:
                            признак «упомянут в цитате» вычисляется во время сканирования.', 'legal-entity-marks'); ?>
                        </p>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Дополнительные поля', 'legal-entity-marks'); ?></th>
                <td>
                    <?php $efm = $settings['extra_fields_mode']; ?>
                    <label style="display:block;margin-bottom:4px">
                        <input type="radio" name="lem_extra_fields_mode" value="off" <?php checked($efm, 'off'); ?>>
                        <?php echo esc_html__('Не сканировать (только заголовок и текст записи)', 'legal-entity-marks'); ?>
                    </label>
                    <label style="display:block;margin-bottom:4px">
                        <input type="radio" name="lem_extra_fields_mode" value="selected" <?php checked($efm, 'selected'); ?>>
                        <?php echo esc_html__('Сканировать выбранные поля', 'legal-entity-marks'); ?>
                    </label>
                    <label style="display:block;margin-bottom:8px">
                        <input type="radio" name="lem_extra_fields_mode" value="all" <?php checked($efm, 'all'); ?>>
                        <?php echo esc_html__('Сканировать все произвольные поля', 'legal-entity-marks'); ?>
                    </label>

                    <?php $found = LEM_Scanner::discover_meta_keys(); ?>
                    <?php if (empty($found)) : ?>
                        <p class="description"><?php echo esc_html__('Полей с текстом на сайте не найдено.', 'legal-entity-marks'); ?></p>
                    <?php else : ?>
                        <div style="max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:8px;max-width:640px">
                            <?php foreach ($found as $key => $sample) : ?>
                                <label style="display:block;margin-bottom:6px">
                                    <input type="checkbox" name="lem_extra_fields[]"
                                           value="<?php echo esc_attr($key); ?>"
                                           <?php checked(in_array($key, $settings['extra_fields'], true)); ?>>
                                    <code><?php echo esc_html($key); ?></code>
                                    <span class="description">- <?php echo esc_html($sample); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <p class="description">
                        <?php echo esc_html__('Темы часто выводят подзаголовок или лид отдельным блоком, мимо основного
                        текста записи - такие упоминания сканер не видит. Здесь показаны
                        произвольные поля этого сайта, в которых лежит текст: отметьте нужные.
                        После изменения запустите пересканирование.', 'legal-entity-marks'); ?>
                        <br>
                        <?php echo esc_html__('Если текст собирается темой на лету и в базе его нет, используйте фильтр', 'legal-entity-marks'); ?>
                        <code>lem_scan_extra_text</code>.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Исключённые из реестра', 'legal-entity-marks'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="lem_mark_excluded" value="1"
                               <?php checked($settings['mark_excluded']); ?>>
                        <?php echo esc_html__('Маркировать и тех, кого уже исключили из реестра', 'legal-entity-marks'); ?>
                    </label>
                    <p class="description">
                        <?php echo esc_html__('По закону после исключения из реестра маркировка не требуется, поэтому
                        по умолчанию плагин помечает только действующих. Если включить, исключённые
                        помечаются формулировкой в прошедшем времени, например «ранее признан(а)
                        иностранным агентом (исключён из реестра 12.04.2024)».
                        После изменения запустите пересканирование.', 'legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_filter_priority"><?php echo esc_html__('Приоритет фильтра', 'legal-entity-marks'); ?></label>
                </th>
                <td>
                    <input type="number" id="lem_filter_priority" name="lem_filter_priority"
                           value="<?php echo esc_attr($settings['filter_priority']); ?>"
                           min="1" max="99999" class="small-text">
                    <p class="description"><?php echo esc_html__('Приоритет фильтра the_content. Чем больше - тем позже выполняется. По умолчанию: 9999.', 'legal-entity-marks'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_accent_color"><?php echo esc_html__('Цвет маркера', 'legal-entity-marks'); ?></label>
                </th>
                <td>
                    <input type="color" id="lem_accent_color" name="lem_accent_color"
                           value="<?php echo esc_attr($settings['accent_color']); ?>">
                    <p class="description"><?php echo esc_html__('Цвет сносок-маркеров в тексте статьи.', 'legal-entity-marks'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_disclaimer_bg"><?php echo esc_html__('Фон дисклеймера', 'legal-entity-marks'); ?></label>
                </th>
                <td>
                    <input type="color" id="lem_disclaimer_bg" name="lem_disclaimer_bg"
                           value="<?php echo esc_attr($settings['disclaimer_bg']); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_disclaimer_border"><?php echo esc_html__('Рамка дисклеймера', 'legal-entity-marks'); ?></label>
                </th>
                <td>
                    <input type="color" id="lem_disclaimer_border" name="lem_disclaimer_border"
                           value="<?php echo esc_attr($settings['disclaimer_border']); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="lem_cron_interval"><?php echo esc_html__('Интервал обновления', 'legal-entity-marks'); ?></label>
                </th>
                <td>
                    <select id="lem_cron_interval" name="lem_cron_interval">
                        <option value="daily" <?php selected($settings['cron_interval'], 'daily'); ?>><?php echo esc_html__('Ежедневно', 'legal-entity-marks'); ?></option>
                        <option value="twicedaily" <?php selected($settings['cron_interval'], 'twicedaily'); ?>><?php echo esc_html__('Дважды в день', 'legal-entity-marks'); ?></option>
                        <option value="weekly" <?php selected($settings['cron_interval'], 'weekly'); ?>><?php echo esc_html__('Еженедельно', 'legal-entity-marks'); ?></option>
                    </select>
                    <p class="description"><?php echo esc_html__('Как часто автоматически проверять обновления реестров.', 'legal-entity-marks'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Автоскан при публикации', 'legal-entity-marks'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="lem_auto_scan" value="1"
                               <?php checked($settings['auto_scan_on_publish']); ?>>
                        <?php echo esc_html__('Автоматически сканировать статьи при публикации.', 'legal-entity-marks'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Сохранить настройки', 'legal-entity-marks')); ?>
    </form>
</div>

<script>
/* Регистрация сайта: уходит только по нажатию и только то, что показано выше */
document.getElementById('lem-register-site')?.addEventListener('click', function () {
    var btn = this, out = document.getElementById('lem-register-status');
    var cfg = window.lemAdmin || {};
    var email = (document.getElementById('lem-register-email') || {}).value || '';
    if (!confirm(<?php echo wp_json_encode(__('Отправить автору плагина адрес сайта, его название, версии и почту?
', 'legal-entity-marks')); ?>
        + <?php echo wp_json_encode(__('Содержимое статей и результаты поиска не отправляются.', 'legal-entity-marks')); ?>)) { return; }

    btn.disabled = true;
    out.textContent = <?php echo wp_json_encode(__('Отправляем...', 'legal-entity-marks')); ?>;
    fetch(cfg.ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=lem_register_site&nonce=' + cfg.crudNonce + '&email=' + encodeURIComponent(email)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        btn.disabled = false;
        out.textContent = d.success ? d.data.message : (d.data || <?php echo wp_json_encode(__('не получилось', 'legal-entity-marks')); ?>);
    })
    .catch(function () { btn.disabled = false; out.textContent = <?php echo wp_json_encode(__('Ошибка сети', 'legal-entity-marks')); ?>; });
});

/* Проверка канала реестров: токен наружу не отдаётся, только состояние */
document.getElementById('lem-check-channel')?.addEventListener('click', function () {
    var btn = this, out = document.getElementById('lem-channel-status');
    var cfg = window.lemAdmin || {};
    btn.disabled = true;
    out.textContent = <?php echo wp_json_encode(__('Проверяем...', 'legal-entity-marks')); ?>;
    fetch(cfg.ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=lem_check_channel&nonce=' + cfg.crudNonce
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        btn.disabled = false;
        out.textContent = d.success ? d.data.message : <?php echo wp_json_encode(__('Ошибка проверки', 'legal-entity-marks')); ?>;
    })
    .catch(function () { btn.disabled = false; out.textContent = <?php echo wp_json_encode(__('Ошибка сети', 'legal-entity-marks')); ?>; });
});

/* Профиль сайта расставляет галочки сразу, не дожидаясь сохранения:
   иначе выбранный профиль и таблица показывают разное. */
(function () {
    var presets = <?php echo wp_json_encode(LEM_Plugin::get_presets()); ?>;
    var marks   = document.querySelectorAll('.lem-mark');
    var tracks  = document.querySelectorAll('.lem-track');
    var hint    = document.getElementById('lem-preset-hint');
    var present = document.getElementById('lem-registries-present');
    if (!marks.length || !present) { return; }

    function trackBox(registry) {
        return document.querySelector('.lem-track[data-registry="' + registry + '"]');
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

    document.querySelectorAll('input[name="lem_preset"]').forEach(function (radio) {
        radio.addEventListener('change', function () { apply(this.value); });
    });
    marks.forEach(function (m) {
        m.addEventListener('change', function () { syncTrack(true); });
    });
})();
</script>
