<?php defined('ABSPATH') || exit;

$clem_settings = clem_plugin()->get_settings();
settings_errors('clem_settings');

$clem_all_post_types = get_post_types(['public' => true], 'objects');
?>
<div class="wrap">
    <h1><?php echo esc_html__('Настройки маркировки', 'chekazoid-legal-entity-marks'); ?></h1>

    <form method="post">
        <?php wp_nonce_field('clem_save_settings', 'clem_settings_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><?php echo esc_html__('Типы записей', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <?php foreach ($clem_all_post_types as $clem_pt) : ?>
                        <label style="display:block;margin-bottom:4px">
                            <input type="checkbox" name="clem_post_types[]"
                                   value="<?php echo esc_attr($clem_pt->name); ?>"
                                   <?php checked(in_array($clem_pt->name, $clem_settings['post_types'], true)); ?>>
                            <?php echo esc_html($clem_pt->label); ?> <code>(<?php echo esc_html($clem_pt->name); ?>)</code>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php echo esc_html__('Какие типы записей сканировать и маркировать.', 'chekazoid-legal-entity-marks'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Профиль сайта', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <?php foreach (CLEM_Plugin::get_presets() as $clem_key => $clem_preset) : ?>
                        <label style="display:block;margin-bottom:6px">
                            <input type="radio" name="clem_preset" value="<?php echo esc_attr($clem_key); ?>"
                                   <?php checked($clem_settings['preset'], $clem_key); ?>>
                            <strong><?php echo esc_html($clem_preset['label']); ?></strong>
                            <span class="description"><?php echo esc_html($clem_preset['hint']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Реестры', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <?php
                    $clem_registry_labels = [
                        'inoagent'    => __('Иностранные агенты', 'chekazoid-legal-entity-marks'),
                        'extremist'   => __('Экстремистские организации', 'chekazoid-legal-entity-marks'),
                        'terrorist'   => __('Террористические организации', 'chekazoid-legal-entity-marks'),
                        'undesirable' => __('Нежелательные организации', 'chekazoid-legal-entity-marks'),
                    ];
                    $clem_manual = $clem_settings['preset'] === 'manual';
                    ?>
                    <table class="widefat" style="max-width:560px">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Реестр', 'chekazoid-legal-entity-marks'); ?></th>
                                <th style="width:110px;text-align:center"><?php echo esc_html__('Маркировать', 'chekazoid-legal-entity-marks'); ?></th>
                                <th style="width:130px;text-align:center"><?php echo esc_html__('Отслеживать', 'chekazoid-legal-entity-marks'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clem_registry_labels as $clem_key => $clem_label) :
                            $clem_is_marked = in_array($clem_key, $clem_settings['mark_registries'], true); ?>
                            <tr>
                                <td><?php echo esc_html($clem_label); ?></td>
                                <td style="text-align:center">
                                    <input type="checkbox" class="clem-mark" data-registry="<?php echo esc_attr($clem_key); ?>"
                                           name="clem_mark_registries[]"
                                           value="<?php echo esc_attr($clem_key); ?>"
                                           <?php checked($clem_is_marked); ?>
                                           <?php disabled(!$clem_manual); ?>>
                                </td>
                                <td style="text-align:center">
                                    <?php // Маркируемый реестр отслеживается по определению, галочку не снять ?>
                                    <input type="checkbox" class="clem-track" data-registry="<?php echo esc_attr($clem_key); ?>"
                                           name="clem_track_registries[]"
                                           value="<?php echo esc_attr($clem_key); ?>"
                                           <?php checked(in_array($clem_key, $clem_settings['track_registries'], true)); ?>
                                           <?php disabled(!$clem_manual || $clem_is_marked); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description">
                        <strong><?php echo esc_html__('Маркировать', 'chekazoid-legal-entity-marks'); ?></strong> <?php echo esc_html__('- сноска в тексте и строка в блоке дисклеймеров.', 'chekazoid-legal-entity-marks'); ?><br>
                        <strong><?php echo esc_html__('Отслеживать', 'chekazoid-legal-entity-marks'); ?></strong> <?php echo esc_html__('- упоминания и ссылки видны в отчёте. Автоматических меток нет. Чтобы поставить метку в отдельной статье, выберите «Всегда помечать» в её редакторе.', 'chekazoid-legal-entity-marks'); ?><br>
                        <?php echo esc_html__('Маркируемый реестр отслеживается автоматически. Изменения действуют сразу,
                        пересканирование не требуется.', 'chekazoid-legal-entity-marks'); ?>
                        <br><em id="clem-preset-hint" <?php echo $clem_manual ? 'style="display:none"' : ''; ?>><?php echo esc_html__('Галочки
                        расставляет выбранный профиль. Чтобы менять вручную,
                        выберите профиль «Вручную».', 'chekazoid-legal-entity-marks'); ?></em>
                    </p>
                    <?php // Признак того, что галочки были доступны для правки: без него
                          // сохранение профиля не должно трогать сохранённые списки ?>
                    <input type="hidden" id="clem-registries-present" name="clem_registries_present"
                           value="1" <?php disabled(!$clem_manual); ?>>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Физические лица: Росфинмониторинг', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <select name="clem_fedsfm_people_mode">
                        <?php foreach (['off' => __('Выключено', 'chekazoid-legal-entity-marks'), 'track' => __('Только отслеживать', 'chekazoid-legal-entity-marks'), 'mark' => __('Маркировать', 'chekazoid-legal-entity-marks')] as $clem_value => $clem_label) : ?>
                        <option value="<?php echo esc_attr($clem_value); ?>" <?php selected($clem_settings['fedsfm_people_mode'], $clem_value); ?>><?php echo esc_html($clem_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="max-width:700px">
                        <?php echo esc_html__('Дополнительный перечень физических лиц из «Наблюдателя». По умолчанию выключен,
                        профили сайта его не включают. Для загрузки нужны включённый канал и токен канала.
                        Загрузка идёт в фоне, порциями; после завершения проверяется архив публикаций.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                    <p class="description" style="max-width:700px">
                        <?php echo esc_html__('Ищется полное ФИО, включая отчество, если оно есть в перечне.
                        Совпадения по одной фамилии, инициалам и неполному имени не маркируются.
                        У разных людей могут совпадать даже полные ФИО: такие случаи проверяет редакция.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                    <?php
                    $clem_people_state = (array) get_option(CLEM_People::STATE, []);
                    $clem_people_success = (array) get_option(CLEM_People::SUCCESS, []);
                    if ($clem_settings['fedsfm_people_mode'] !== 'off') :
                        if (!empty($clem_people_state['error'])) : ?>
                        <p class="description" style="color:#b32d2e"><?php echo esc_html($clem_people_state['error']); ?> <?php echo esc_html__('Для повторной попытки сохраните настройки или нажмите «Обновить реестры» на «Обзоре».', 'chekazoid-legal-entity-marks'); ?></p>
                        <?php elseif (!empty($clem_people_state['running'])) : ?>
                        <p class="description"><?php echo esc_html__('Идёт загрузка:', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_people_state['offset']; ?> <?php echo esc_html__('из', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_people_state['total']; ?> <?php echo esc_html__('ФИО.', 'chekazoid-legal-entity-marks'); ?></p>
                        <?php endif;
                        if (!empty($clem_people_success['synced_at'])) : ?>
                        <p class="description"><?php echo esc_html__('Последняя успешная сверка:', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_people_success['synced_at']); ?><?php echo esc_html__('. Срез канала:', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_people_success['fetched_at']); ?><?php echo esc_html__('. ФИО:', 'chekazoid-legal-entity-marks'); ?> <?php echo (int) $clem_people_success['total']; ?>.</p>
                        <?php endif;
                    endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Ссылки, подлежащие удалению', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <p class="description" style="max-width:640px;margin-bottom:8px">
                        <?php echo esc_html__('Ссылка на ресурс нежелательной организации может толковаться как
                        участие в её деятельности, такие ссылки убирают из текста.
                        Ссылка на сайт иноагента ничем не запрещена, ей достаточно маркировки.
                        Отметьте реестры, ссылки на ресурсы которых считать подлежащими удалению.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                    <?php foreach ($clem_registry_labels as $clem_key => $clem_label) : ?>
                        <label style="display:inline-block;margin-right:16px">
                            <input type="checkbox" name="clem_link_registries[]"
                                   value="<?php echo esc_attr($clem_key); ?>"
                                   <?php checked(in_array($clem_key, $clem_settings['link_registries'], true)); ?>>
                            <?php echo esc_html($clem_label); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php echo esc_html__('Найденные ссылки видны в разделе «Ссылки» в любом случае, отметка
                        влияет только на то, что удаляется из текста.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Канал реестров', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        <?php echo esc_html__('Наблюдатель получает четыре публичных перечня и отдаёт их плагину. Сервис по умолчанию ведёт автор плагина, Алексей Шляпужников. При обновлении и проверке канала сервис получает IP-адрес сервера и обычные HTTP-заголовки. Содержимое публикаций и найденные упоминания остаются на вашем сайте.', 'chekazoid-legal-entity-marks'); ?>
                        <a href="<?php echo esc_url(CLEM_Channel::SERVICE_INFO . '#purpose-and-operator'); ?>"><?php echo esc_html__('Условия использования', 'chekazoid-legal-entity-marks'); ?></a>.
                        <a href="<?php echo esc_url(CLEM_Channel::SERVICE_INFO . '#registry-requests'); ?>"><?php echo esc_html__('Конфиденциальность', 'chekazoid-legal-entity-marks'); ?></a>.
                    </p>
                    <label style="display:block;margin-bottom:8px">
                        <input type="checkbox" name="clem_channel_enabled" value="1"
                               <?php checked($clem_settings['channel_enabled']); ?>>
                        <?php echo esc_html__('Разрешить плановые и ручные запросы к каналу по указанному адресу', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                    <p class="description" style="max-width:700px">
                        <?php echo esc_html__('Пока согласие не сохранено, плагин работает с локальными данными. При смене адреса сначала сохраните новый HTTPS-адрес, затем включите запросы. Для другого сервера действуют условия его оператора.', 'chekazoid-legal-entity-marks'); ?>
                    </p>

                    <p style="margin:0 0 6px">
                        <label for="clem-channel-url" style="display:inline-block;min-width:70px"><?php echo esc_html__('Адрес', 'chekazoid-legal-entity-marks'); ?></label>
                        <input type="url" id="clem-channel-url" name="clem_channel_url" class="regular-text"
                               value="<?php echo esc_attr($clem_settings['channel_url']); ?>"
                               placeholder="<?php echo esc_attr(CLEM_Channel::DEFAULT_URL); ?>">
                    </p>

                    <?php
                    // Токен на страницу не выводим: поле всегда пустое, а сохранённый
                    // остаётся, пока не введут новый или не отметят «удалить»
                    $clem_has_token   = clem_plugin()->channel->has_token();
                    $clem_from_config = clem_plugin()->channel->config_token() !== '';
                    ?>
                    <p style="margin:0 0 6px">
                        <label for="clem-channel-token" style="display:inline-block;min-width:70px"><?php echo esc_html__('Токен', 'chekazoid-legal-entity-marks'); ?></label>
                        <input type="password" id="clem-channel-token" name="clem_channel_token"
                               class="regular-text" autocomplete="new-password"
                               placeholder="<?php echo $clem_has_token ? esc_attr__('сохранён, оставьте пустым', 'chekazoid-legal-entity-marks') : esc_attr__('не требуется', 'chekazoid-legal-entity-marks'); ?>"
                               <?php disabled($clem_from_config); ?>>
                        <?php if ($clem_has_token && !$clem_from_config) : ?>
                            <label style="margin-left:10px">
                                <input type="checkbox" name="clem_channel_token_clear" value="1">
                                <?php echo esc_html__('удалить', 'chekazoid-legal-entity-marks'); ?>
                            </label>
                        <?php endif; ?>
                    </p>
                    <p class="description" style="max-width:700px">
                        <strong><?php echo esc_html__('Для четырёх основных реестров токен не нужен', 'chekazoid-legal-entity-marks'); ?></strong><?php echo esc_html__(', выгрузки открыты.
                        Токен требуется для добровольной загрузки физических лиц из перечня
                        Росфинмониторинга. Без её включения этот перечень не скачивается.', 'chekazoid-legal-entity-marks'); ?>
                        <?php if ($clem_from_config) : ?>
                            <br><?php echo esc_html__('Сейчас токен задан константой', 'chekazoid-legal-entity-marks'); ?> <code>CLEM_CHANNEL_TOKEN</code>
                            <?php echo esc_html__('в wp-config.php и настройкой не перекрывается.', 'chekazoid-legal-entity-marks'); ?>
                        <?php else : ?>
                            <br><?php echo esc_html__('Токен хранится в настройках сайта и на страницу не выводится.
                            Его можно держать вне базы, добавив в wp-config.php строку', 'chekazoid-legal-entity-marks'); ?>
                            <code>define('CLEM_CHANNEL_TOKEN', '...');</code>
                        <?php endif; ?>
                    </p>

                    <p>
                        <button type="button" class="button" id="clem-check-channel" <?php disabled(!$clem_settings['channel_enabled']); ?>><?php echo esc_html__('Проверить канал', 'chekazoid-legal-entity-marks'); ?></button>
                        <span id="clem-channel-status" class="clem-inline-status"></span>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Обновление и удаление', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <p class="description" style="max-width:700px;margin-bottom:10px">
                        <strong><?php echo esc_html__('Для обновления в той же папке загрузите ZIP поверх установленной версии.', 'chekazoid-legal-entity-marks'); ?></strong> <?php echo esc_html__('«Плагины → Добавить новый →
                        Загрузить плагин», выбрать архив, на вопрос об уже установленной версии
                        ответить «Заменить текущей версией». Настройки, реестры и результаты
                        сканирования при этом сохраняются.', 'chekazoid-legal-entity-marks'); ?>
                        <br><?php echo esc_html__('При переходе со старого ZIP из GitHub на версию из каталога сначала деактивируйте прежний плагин. Новая версия перенесёт данные при активации.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                    <label style="display:block">
                        <input type="checkbox" name="clem_delete_data" value="1"
                               <?php checked($clem_settings['delete_data_on_uninstall']); ?>>
                        <?php echo esc_html__('Удалить все данные плагина, если его удалят из WordPress', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                    <p class="description" style="max-width:700px">
                        <?php echo esc_html__('По умолчанию выключено: при удалении плагина реестры, настройки
                        и результаты сканирования остаются в базе, и повторная установка
                        подхватывает их как были. Включайте, если убираете плагин насовсем
                        и хотите, чтобы после него ничего не осталось.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Помочь развитию плагина', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <?php
                    $clem_payload       = clem_plugin()->channel->registration_payload();
                    $clem_registered_at = get_option('clem_registered_at', '');
                    ?>
                    <p class="description" style="max-width:700px;margin-bottom:10px">
                        <?php echo esc_html__('Плагин раздаётся бесплатно, и понять, сколько людей им пользуется,
                        можно только с вашей помощью. Ничего обязательного тут нет:
                        Счётчик и регистрацию можно оставить выключенными.
                        Подключённый канал всё равно будет обновлять списки.', 'chekazoid-legal-entity-marks'); ?>
                    </p>

                    <label style="display:block;margin-bottom:6px">
                        <input type="checkbox" name="clem_stats_enabled" value="1"
                               <?php checked($clem_settings['stats_enabled']); ?>>
                        <?php echo esc_html__('Разрешить отправку случайного идентификатора и версии для подсчёта установок', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                    <p class="description" style="max-width:700px;margin-bottom:14px">
                        <?php echo esc_html__('Счётчик по умолчанию выключен. Если включить его вместе с каналом, запросы к перечням и проверка канала будут передавать случайный идентификатор установки', 'chekazoid-legal-entity-marks'); ?>
                        <code><?php echo esc_html($clem_payload['install_id']); ?></code>
                        <?php echo esc_html__('и версию плагина. Снятие галочки прекращает отправку идентификатора.', 'chekazoid-legal-entity-marks'); ?>
                        <a href="<?php echo esc_url(CLEM_Channel::SERVICE_INFO . '#optional-installation-counter'); ?>"><?php echo esc_html__('Какие данные хранит счётчик', 'chekazoid-legal-entity-marks'); ?></a>.
                    </p>

                    <p style="margin-bottom:6px"><strong><?php echo esc_html__('Зарегистрировать сайт', 'chekazoid-legal-entity-marks'); ?></strong></p>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        <?php echo esc_html__('Это уже не анонимно. Регистрация нужна, чтобы автор мог предупредить
                        о важном обновлении или о поломке в реестрах. Отправляется', 'chekazoid-legal-entity-marks'); ?>
                        <strong><?php echo esc_html__('только по нажатию кнопки', 'chekazoid-legal-entity-marks'); ?></strong> <?php echo esc_html__('и ровно в таком составе:', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                    <table class="widefat" style="max-width:640px;margin-bottom:8px">
                        <tbody>
                            <tr><td style="width:190px"><?php echo esc_html__('Адрес сайта', 'chekazoid-legal-entity-marks'); ?></td>
                                <td><code><?php echo esc_html($clem_payload['site']); ?></code></td></tr>
                            <tr><td><?php echo esc_html__('Название сайта', 'chekazoid-legal-entity-marks'); ?></td>
                                <td><?php echo esc_html($clem_payload['name']); ?></td></tr>
                            <tr><td><?php echo esc_html__('Версия плагина', 'chekazoid-legal-entity-marks'); ?></td>
                                <td><?php echo esc_html($clem_payload['plugin']); ?></td></tr>
                            <tr><td><?php echo esc_html__('Версия WordPress', 'chekazoid-legal-entity-marks'); ?></td>
                                <td><?php echo esc_html($clem_payload['wordpress']); ?></td></tr>
                            <tr><td><?php echo esc_html__('Версия PHP', 'chekazoid-legal-entity-marks'); ?></td>
                                <td><?php echo esc_html($clem_payload['php']); ?></td></tr>
                            <tr><td><?php echo esc_html__('Идентификатор установки', 'chekazoid-legal-entity-marks'); ?></td>
                                <td><code><?php echo esc_html($clem_payload['install_id']); ?></code></td></tr>
                            <tr><td><?php echo esc_html__('Почта для связи', 'chekazoid-legal-entity-marks'); ?></td>
                                <td><input type="email" id="clem-register-email" class="regular-text"
                                           placeholder="<?php echo esc_attr__('необязательно', 'chekazoid-legal-entity-marks'); ?>"></td></tr>
                        </tbody>
                    </table>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        <?php echo esc_html__('Больше ничего не уходит: ни содержимое статей, ни списки найденных
                        организаций, ни данные редакции.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                    <p>
                        <button type="button" class="button" id="clem-register-site" <?php disabled(!$clem_settings['channel_enabled']); ?>>
                            <?php echo $clem_registered_at ? esc_html__('Отправить ещё раз', 'chekazoid-legal-entity-marks') : esc_html__('Отправить', 'chekazoid-legal-entity-marks'); ?>
                        </button>
                        <span id="clem-register-status" class="clem-inline-status">
                            <?php if ($clem_registered_at) : ?>
                                <?php echo esc_html__('Сайт зарегистрирован', 'chekazoid-legal-entity-marks'); ?> <?php echo esc_html($clem_registered_at); ?>
                            <?php endif; ?>
                        </span>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Поиск имён', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <label style="display:block;margin-bottom:4px">
                        <input type="checkbox" name="clem_match_word_forms" value="1"
                               <?php checked($clem_settings['match_word_forms']); ?>>
                        <?php echo esc_html__('Искать фамилии и имена во всех падежах', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                    <p class="description" style="margin:0 0 10px">
                        <?php echo esc_html__('Без этого найдётся только «Иванов Иван» из реестра, а «по словам Иванова»
                        или «интервью с Ивановым» пройдут мимо. Учитывается род: женские фамилии
                        на согласную не склоняются.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                    <p style="margin:0 0 4px"><strong><?php echo esc_html__('Упоминание одной фамилии без имени', 'chekazoid-legal-entity-marks'); ?></strong></p>
                    <select name="clem_surname_mode" style="max-width:100%">
                        <option value="confirmed" <?php selected($clem_settings['surname_mode'], 'confirmed'); ?>>
                            <?php echo esc_html__('Только если в статье есть полное имя (рекомендуется)', 'chekazoid-legal-entity-marks'); ?>
                        </option>
                        <option value="off" <?php selected($clem_settings['surname_mode'], 'off'); ?>>
                            <?php echo esc_html__('Не искать, нужно полное «Имя Фамилия»', 'chekazoid-legal-entity-marks'); ?>
                        </option>
                        <option value="always" <?php selected($clem_settings['surname_mode'], 'always'); ?>>
                            <?php echo esc_html__('Искать всегда', 'chekazoid-legal-entity-marks'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php echo esc_html__('В статьях принято называть человека полностью при первом упоминании,
                        а дальше по фамилии. Рекомендуемый режим это учитывает: «Лев Пономарев
                        заявил… Пономарев добавил…» будет размечено целиком, а заметка про
                        однофамильца-чиновника не попадёт под маркировку.', 'chekazoid-legal-entity-marks'); ?>
                        <br>
                        <?php echo esc_html__('Режим «искать всегда» даёт ложные срабатывания на однофамильцах:
                        13 из 24 самых частых русских фамилий есть в реестре иноагентов.
                        После изменения запустите пересканирование.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Иноагенты: только в цитатах', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="clem_inoagent_context_only" value="1"
                               <?php checked($clem_settings['inoagent_context_only']); ?>>
                        <?php echo esc_html__('Маркировать иноагентов только там, где их цитируют или дают ссылку', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                    <p class="description">
                        <?php echo esc_html__('Обычное упоминание фамилии в тексте маркироваться не будет.
                        Остальные три реестра правило не затрагивает.', 'chekazoid-legal-entity-marks'); ?>
                    </p>

                    <div style="margin-top:10px;padding-left:22px">
                        <p style="margin:0 0 4px"><strong><?php echo esc_html__('Что считать поводом для маркировки:', 'chekazoid-legal-entity-marks'); ?></strong></p>
                        <?php
                        $clem_trigger_labels = [
                            'blockquote' => __('Блок цитаты (blockquote, q) вместе с подводкой и подписью', 'chekazoid-legal-entity-marks'),
                            'link'       => __('Абзац с гиперссылкой, текст и адрес ссылки', 'chekazoid-legal-entity-marks'),
                            'quotes'     => __('Абзац с прямой речью в кавычках «…»', 'chekazoid-legal-entity-marks'),
                            'embed'      => __('Встроенный пост из соцсети вместе с подводкой и подписью', 'chekazoid-legal-entity-marks'),
                        ];
                        foreach ($clem_trigger_labels as $clem_key => $clem_label) : ?>
                            <label style="display:block;margin-bottom:4px">
                                <input type="checkbox" name="clem_context_triggers[]"
                                       value="<?php echo esc_attr($clem_key); ?>"
                                       <?php checked(!empty($clem_settings['context_triggers'][$clem_key])); ?>>
                                <?php echo esc_html($clem_label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php echo esc_html__('После изменения этих условий запустите пересканирование:
                            признак «упомянут в цитате» вычисляется во время сканирования.', 'chekazoid-legal-entity-marks'); ?>
                        </p>
                    </div>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Дополнительные поля', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <?php $clem_efm = $clem_settings['extra_fields_mode']; ?>
                    <label style="display:block;margin-bottom:4px">
                        <input type="radio" name="clem_extra_fields_mode" value="off" <?php checked($clem_efm, 'off'); ?>>
                        <?php echo esc_html__('Не сканировать (только заголовок и текст записи)', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                    <label style="display:block;margin-bottom:4px">
                        <input type="radio" name="clem_extra_fields_mode" value="selected" <?php checked($clem_efm, 'selected'); ?>>
                        <?php echo esc_html__('Сканировать выбранные поля', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                    <label style="display:block;margin-bottom:8px">
                        <input type="radio" name="clem_extra_fields_mode" value="all" <?php checked($clem_efm, 'all'); ?>>
                        <?php echo esc_html__('Сканировать все произвольные поля', 'chekazoid-legal-entity-marks'); ?>
                    </label>

                    <?php $clem_found = CLEM_Scanner::discover_meta_keys(); ?>
                    <?php if (empty($clem_found)) : ?>
                        <p class="description"><?php echo esc_html__('Полей с текстом на сайте не найдено.', 'chekazoid-legal-entity-marks'); ?></p>
                    <?php else : ?>
                        <div style="max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:8px;max-width:640px">
                            <?php foreach ($clem_found as $clem_key => $clem_sample) : ?>
                                <label style="display:block;margin-bottom:6px">
                                    <input type="checkbox" name="clem_extra_fields[]"
                                           value="<?php echo esc_attr($clem_key); ?>"
                                           <?php checked(in_array($clem_key, $clem_settings['extra_fields'], true)); ?>>
                                    <code><?php echo esc_html($clem_key); ?></code>
                                    <span class="description">- <?php echo esc_html($clem_sample); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <p class="description">
                        <?php echo esc_html__('Темы часто выводят подзаголовок или лид отдельным блоком, мимо основного
                        текста записи - такие упоминания сканер не видит. Здесь показаны
                        произвольные поля этого сайта, в которых лежит текст: отметьте нужные.
                        После изменения запустите пересканирование.', 'chekazoid-legal-entity-marks'); ?>
                        <br>
                        <?php echo esc_html__('Если текст собирается темой на лету и в базе его нет, используйте фильтр', 'chekazoid-legal-entity-marks'); ?>
                        <code>clem_scan_extra_text</code>.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Исключённые из реестра', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="clem_mark_excluded" value="1"
                               <?php checked($clem_settings['mark_excluded']); ?>>
                        <?php echo esc_html__('Маркировать и тех, кого уже исключили из реестра', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                    <p class="description">
                        <?php echo esc_html__('По закону после исключения из реестра маркировка не требуется, поэтому
                        по умолчанию плагин помечает только действующих. Если включить, исключённые
                        помечаются формулировкой в прошедшем времени, например «ранее признан(а)
                        иностранным агентом (исключён из реестра 12.04.2024)».
                        После изменения запустите пересканирование.', 'chekazoid-legal-entity-marks'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="clem_filter_priority"><?php echo esc_html__('Приоритет фильтра', 'chekazoid-legal-entity-marks'); ?></label>
                </th>
                <td>
                    <input type="number" id="clem_filter_priority" name="clem_filter_priority"
                           value="<?php echo esc_attr($clem_settings['filter_priority']); ?>"
                           min="1" max="99999" class="small-text">
                    <p class="description"><?php echo esc_html__('Приоритет фильтра the_content. Чем больше - тем позже выполняется. По умолчанию: 9999.', 'chekazoid-legal-entity-marks'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="clem_accent_color"><?php echo esc_html__('Цвет маркера', 'chekazoid-legal-entity-marks'); ?></label>
                </th>
                <td>
                    <input type="color" id="clem_accent_color" name="clem_accent_color"
                           value="<?php echo esc_attr($clem_settings['accent_color']); ?>">
                    <p class="description"><?php echo esc_html__('Цвет сносок-маркеров в тексте статьи.', 'chekazoid-legal-entity-marks'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="clem_disclaimer_bg"><?php echo esc_html__('Фон дисклеймера', 'chekazoid-legal-entity-marks'); ?></label>
                </th>
                <td>
                    <input type="color" id="clem_disclaimer_bg" name="clem_disclaimer_bg"
                           value="<?php echo esc_attr($clem_settings['disclaimer_bg']); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="clem_disclaimer_border"><?php echo esc_html__('Рамка дисклеймера', 'chekazoid-legal-entity-marks'); ?></label>
                </th>
                <td>
                    <input type="color" id="clem_disclaimer_border" name="clem_disclaimer_border"
                           value="<?php echo esc_attr($clem_settings['disclaimer_border']); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="clem_cron_interval"><?php echo esc_html__('Интервал обновления', 'chekazoid-legal-entity-marks'); ?></label>
                </th>
                <td>
                    <select id="clem_cron_interval" name="clem_cron_interval">
                        <option value="daily" <?php selected($clem_settings['cron_interval'], 'daily'); ?>><?php echo esc_html__('Ежедневно', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="twicedaily" <?php selected($clem_settings['cron_interval'], 'twicedaily'); ?>><?php echo esc_html__('Дважды в день', 'chekazoid-legal-entity-marks'); ?></option>
                        <option value="weekly" <?php selected($clem_settings['cron_interval'], 'weekly'); ?>><?php echo esc_html__('Еженедельно', 'chekazoid-legal-entity-marks'); ?></option>
                    </select>
                    <p class="description"><?php echo esc_html__('Как часто автоматически проверять обновления реестров.', 'chekazoid-legal-entity-marks'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Автоскан при публикации', 'chekazoid-legal-entity-marks'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="clem_auto_scan" value="1"
                               <?php checked($clem_settings['auto_scan_on_publish']); ?>>
                        <?php echo esc_html__('Автоматически сканировать статьи при публикации.', 'chekazoid-legal-entity-marks'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Сохранить настройки', 'chekazoid-legal-entity-marks')); ?>
    </form>
</div>
