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
                <th scope="row">Профиль сайта</th>
                <td>
                    <?php foreach (LEM_Plugin::PRESETS as $key => $preset) : ?>
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
                <th scope="row">Реестры</th>
                <td>
                    <?php
                    $registry_labels = [
                        'inoagent'    => 'Иностранные агенты',
                        'extremist'   => 'Экстремистские организации',
                        'terrorist'   => 'Террористические организации',
                        'undesirable' => 'Нежелательные организации',
                    ];
                    $manual = $settings['preset'] === 'manual';
                    ?>
                    <table class="widefat" style="max-width:560px">
                        <thead>
                            <tr>
                                <th>Реестр</th>
                                <th style="width:110px;text-align:center">Маркировать</th>
                                <th style="width:130px;text-align:center">Отслеживать</th>
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
                        <strong>Маркировать</strong> - сноска в тексте и строка в блоке дисклеймеров.<br>
                        <strong>Отслеживать</strong> - без меток на сайте, но упоминания и ссылки видны
                        в разделе «Упоминания». Нужно, когда маркировка не требуется, а знать о факте
                        упоминания надо.<br>
                        Маркируемый реестр отслеживается автоматически. Изменения действуют сразу,
                        пересканирование не требуется.
                        <br><em id="lem-preset-hint" <?php echo $manual ? 'style="display:none"' : ''; ?>>Галочки
                        расставляет выбранный профиль. Чтобы менять вручную,
                        выберите профиль «Вручную».</em>
                    </p>
                    <?php // Признак того, что галочки были доступны для правки: без него
                          // сохранение профиля не должно трогать сохранённые списки ?>
                    <input type="hidden" id="lem-registries-present" name="lem_registries_present"
                           value="1" <?php disabled(!$manual); ?>>
                </td>
            </tr>

            <tr>
                <th scope="row">Ссылки, подлежащие удалению</th>
                <td>
                    <p class="description" style="max-width:640px;margin-bottom:8px">
                        Ссылка на ресурс нежелательной организации может толковаться как
                        участие в её деятельности, такие ссылки убирают из текста.
                        Ссылка на сайт иноагента ничем не запрещена, ей достаточно маркировки.
                        Отметьте реестры, ссылки на ресурсы которых считать подлежащими удалению.
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
                        Найденные ссылки видны в разделе «Ссылки» в любом случае, отметка
                        влияет только на то, что удаляется из текста.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">Канал реестров</th>
                <td>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        Запасной источник на случай, когда официальный реестр не отвечает:
                        сайт Минюста бывает недоступен, а хостинг может резать исходящие
                        соединения. Данные там свежее снимка из поставки плагина.
                        Порядок такой: официальный реестр, потом канал, потом встроенный перечень.
                        Экстремистских организаций в канале нет, для них остаётся встроенный.
                    </p>
                    <label style="display:block;margin-bottom:8px">
                        <input type="checkbox" name="lem_channel_enabled" value="1"
                               <?php checked($settings['channel_enabled']); ?>>
                        Использовать канал реестров как запасной источник
                    </label>

                    <p style="margin:0 0 6px">
                        <label for="lem-channel-url" style="display:inline-block;min-width:70px">Адрес</label>
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
                        <label for="lem-channel-token" style="display:inline-block;min-width:70px">Токен</label>
                        <input type="password" id="lem-channel-token" name="lem_channel_token"
                               class="regular-text" autocomplete="new-password"
                               placeholder="<?php echo $has_token ? 'сохранён, оставьте пустым' : 'не требуется'; ?>"
                               <?php disabled($from_config); ?>>
                        <?php if ($has_token && !$from_config) : ?>
                            <label style="margin-left:10px">
                                <input type="checkbox" name="lem_channel_token_clear" value="1">
                                удалить
                            </label>
                        <?php endif; ?>
                    </p>
                    <p class="description" style="max-width:700px">
                        <strong>Для трёх реестров выше токен не нужен</strong>, выгрузки открыты.
                        Он понадобится, только если появится доступ к перечню Росфинмониторинга
                        (30 775 записей, из них 29 541 физлицо) - для расстановки сносок он
                        избыточен, и плагин его не берёт.
                        <?php if ($from_config) : ?>
                            <br>Сейчас токен задан константой <code>LEM_CHANNEL_TOKEN</code>
                            в wp-config.php и настройкой не перекрывается.
                        <?php else : ?>
                            <br>Токен хранится в настройках сайта и на страницу не выводится.
                            Его можно держать вне базы, добавив в wp-config.php строку
                            <code>define('LEM_CHANNEL_TOKEN', '...');</code>
                        <?php endif; ?>
                    </p>

                    <p>
                        <button type="button" class="button" id="lem-check-channel">Проверить канал</button>
                        <span id="lem-channel-status" class="lem-inline-status"></span>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">Помочь развитию плагина</th>
                <td>
                    <?php
                    $payload       = lem()->channel->registration_payload();
                    $registered_at = get_option('lem_registered_at', '');
                    ?>
                    <p class="description" style="max-width:700px;margin-bottom:10px">
                        Плагин раздаётся бесплатно, и понять, сколько людей им пользуется,
                        можно только с вашей помощью. Ничего обязательного тут нет:
                        и счётчик, и регистрацию можно не включать, плагин будет работать
                        точно так же.
                    </p>

                    <label style="display:block;margin-bottom:6px">
                        <input type="checkbox" name="lem_stats_enabled" value="1"
                               <?php checked($settings['stats_enabled']); ?>>
                        Считать эту установку в общей статистике
                    </label>
                    <p class="description" style="max-width:700px;margin-bottom:14px">
                        Вместе с обновлением реестров, которое и так уходит на сервер канала,
                        отправляются две вещи: случайный идентификатор установки
                        (<code><?php echo esc_html($payload['install_id']); ?></code>)
                        и версия плагина. Ни домена, ни адреса, ни чего-либо о содержимом
                        сайта. Идентификатор к сайту не привязан и меняется при переустановке.
                        Отдельных запросов ради статистики плагин не делает.
                    </p>

                    <p style="margin-bottom:6px"><strong>Зарегистрировать сайт</strong></p>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        Это уже не анонимно. Регистрация нужна, чтобы автор мог предупредить
                        о важном обновлении или о поломке в реестрах. Отправляется
                        <strong>только по нажатию кнопки</strong> и ровно в таком составе:
                    </p>
                    <table class="widefat" style="max-width:640px;margin-bottom:8px">
                        <tbody>
                            <tr><td style="width:190px">Адрес сайта</td>
                                <td><code><?php echo esc_html($payload['site']); ?></code></td></tr>
                            <tr><td>Название сайта</td>
                                <td><?php echo esc_html($payload['name']); ?></td></tr>
                            <tr><td>Версия плагина</td>
                                <td><?php echo esc_html($payload['plugin']); ?></td></tr>
                            <tr><td>Версия WordPress</td>
                                <td><?php echo esc_html($payload['wordpress']); ?></td></tr>
                            <tr><td>Версия PHP</td>
                                <td><?php echo esc_html($payload['php']); ?></td></tr>
                            <tr><td>Идентификатор установки</td>
                                <td><code><?php echo esc_html($payload['install_id']); ?></code></td></tr>
                            <tr><td>Почта для связи</td>
                                <td><input type="email" id="lem-register-email" class="regular-text"
                                           placeholder="необязательно"></td></tr>
                        </tbody>
                    </table>
                    <p class="description" style="max-width:700px;margin-bottom:8px">
                        Больше ничего не уходит: ни содержимое статей, ни списки найденных
                        организаций, ни данные редакции.
                    </p>
                    <p>
                        <button type="button" class="button" id="lem-register-site">
                            <?php echo $registered_at ? 'Отправить ещё раз' : 'Отправить'; ?>
                        </button>
                        <span id="lem-register-status" class="lem-inline-status">
                            <?php if ($registered_at) : ?>
                                Сайт зарегистрирован <?php echo esc_html($registered_at); ?>
                            <?php endif; ?>
                        </span>
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
                <th scope="row">Дополнительные поля</th>
                <td>
                    <?php $efm = $settings['extra_fields_mode']; ?>
                    <label style="display:block;margin-bottom:4px">
                        <input type="radio" name="lem_extra_fields_mode" value="off" <?php checked($efm, 'off'); ?>>
                        Не сканировать (только заголовок и текст записи)
                    </label>
                    <label style="display:block;margin-bottom:4px">
                        <input type="radio" name="lem_extra_fields_mode" value="selected" <?php checked($efm, 'selected'); ?>>
                        Сканировать выбранные поля
                    </label>
                    <label style="display:block;margin-bottom:8px">
                        <input type="radio" name="lem_extra_fields_mode" value="all" <?php checked($efm, 'all'); ?>>
                        Сканировать все произвольные поля
                    </label>

                    <?php $found = LEM_Scanner::discover_meta_keys(); ?>
                    <?php if (empty($found)) : ?>
                        <p class="description">Полей с текстом на сайте не найдено.</p>
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
                        Темы часто выводят подзаголовок или лид отдельным блоком, мимо основного
                        текста записи - такие упоминания сканер не видит. Здесь показаны
                        произвольные поля этого сайта, в которых лежит текст: отметьте нужные.
                        После изменения запустите пересканирование.
                        <br>
                        Если текст собирается темой на лету и в базе его нет, используйте фильтр
                        <code>lem_scan_extra_text</code>.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">Исключённые из реестра</th>
                <td>
                    <label>
                        <input type="checkbox" name="lem_mark_excluded" value="1"
                               <?php checked($settings['mark_excluded']); ?>>
                        Маркировать и тех, кого уже исключили из реестра
                    </label>
                    <p class="description">
                        По закону после исключения из реестра маркировка не требуется, поэтому
                        по умолчанию плагин помечает только действующих. Если включить, исключённые
                        помечаются формулировкой в прошедшем времени, например «ранее признан(а)
                        иностранным агентом (исключён из реестра 12.04.2024)».
                        После изменения запустите пересканирование.
                    </p>
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

<script>
/* Регистрация сайта: уходит только по нажатию и только то, что показано выше */
document.getElementById('lem-register-site')?.addEventListener('click', function () {
    var btn = this, out = document.getElementById('lem-register-status');
    var cfg = window.lemAdmin || {};
    var email = (document.getElementById('lem-register-email') || {}).value || '';
    if (!confirm('Отправить автору плагина адрес сайта, его название, версии и почту?\n'
        + 'Содержимое статей и результаты поиска не отправляются.')) { return; }

    btn.disabled = true;
    out.textContent = 'Отправляем...';
    fetch(cfg.ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=lem_register_site&nonce=' + cfg.crudNonce + '&email=' + encodeURIComponent(email)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        btn.disabled = false;
        out.textContent = d.success ? d.data.message : (d.data || 'не получилось');
    })
    .catch(function () { btn.disabled = false; out.textContent = 'Ошибка сети'; });
});

/* Проверка канала реестров: токен наружу не отдаётся, только состояние */
document.getElementById('lem-check-channel')?.addEventListener('click', function () {
    var btn = this, out = document.getElementById('lem-channel-status');
    var cfg = window.lemAdmin || {};
    btn.disabled = true;
    out.textContent = 'Проверяем...';
    fetch(cfg.ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=lem_check_channel&nonce=' + cfg.crudNonce
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        btn.disabled = false;
        out.textContent = d.success ? d.data.message : 'Ошибка проверки';
    })
    .catch(function () { btn.disabled = false; out.textContent = 'Ошибка сети'; });
});

/* Профиль сайта расставляет галочки сразу, не дожидаясь сохранения:
   иначе выбранный профиль и таблица показывают разное. */
(function () {
    var presets = <?php echo wp_json_encode(LEM_Plugin::PRESETS); ?>;
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
