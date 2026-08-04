<?php
defined('ABSPATH') || exit;

/**
 * Канал реестров: запасной источник данных.
 *
 * Официальные реестры отвечают не всегда: сайт Минюста бывает недоступен,
 * хостинг может резать исходящие соединения, адрес сервера могут забанить
 * после массовых выгрузок. Раньше в таких случаях плагин откатывался
 * на снимок из своей поставки, который стареет с каждым днём.
 *
 * Канал стоит между ними: данные свежие, формат тот же, что у import_json.
 * Экстремистских организаций в канале нет - для них остаётся прежняя схема.
 */
class LEM_Channel {

    const DEFAULT_URL = 'https://api.shliapuzhnikov.com/v1/';

    /**
     * Тип в плагине -> реестр в канале. Соответствие не полное:
     * экстремистских в канале нет, а перечень Росфинмониторинга (fedsfm)
     * для расстановки сносок избыточен - 30 775 записей, из них 29 541
     * физлицо. Он же единственный, для выгрузки которого нужен токен.
     */
    const REGISTRY_MAP = [
        'inoagent'    => 'inoagent',
        'undesirable' => 'undesirable',
        'terrorist'   => 'terrorist',
    ];

    /** Ответ канала может быть большим (иноагенты - четверть мегабайта). */
    const TIMEOUT = 45;

    /**
     * Идентификатор установки: случайный, к сайту не привязан.
     *
     * Нужен, чтобы отличить сто сайтов, обновляющих реестры раз в неделю,
     * от одного, делающего это сто раз. По нему нельзя узнать ни домен,
     * ни владельца, а при переустановке плагина он меняется.
     */
    public function install_id() {
        $id = get_option('lem_install_id', '');
        if ($id === '') {
            $id = wp_generate_password(16, false, false);
            update_option('lem_install_id', $id, false);
        }
        return $id;
    }

    /** Считать установки разрешено? */
    public function stats_enabled() {
        $settings = lem()->get_settings();
        return !empty($settings['stats_enabled']);
    }

    /**
     * Заголовки счётчика. Уходят с теми запросами, которые и так идут в канал:
     * отдельного обращения ради статистики плагин не делает.
     */
    private function stats_headers() {
        if (!$this->stats_enabled()) {
            return [];
        }
        return [
            'X-LEM-Install' => $this->install_id(),
            'X-LEM-Version' => LEM_VERSION,
        ];
    }

    public function base_url() {
        $settings = lem()->get_settings();
        $url      = trim((string) ($settings['channel_url'] ?? ''));
        if ($url === '') {
            $url = self::DEFAULT_URL;
        }
        return trailingslashit($url);
    }

    /**
     * Токен. Константа в wp-config.php имеет приоритет над настройкой:
     * так его можно держать вне базы данных и вне резервных копий сайта.
     */
    public function token() {
        if (defined('LEM_CHANNEL_TOKEN') && LEM_CHANNEL_TOKEN !== '') {
            return (string) LEM_CHANNEL_TOKEN;
        }
        $settings = lem()->get_settings();
        return trim((string) ($settings['channel_token'] ?? ''));
    }

    public function has_token() {
        return $this->token() !== '';
    }

    /**
     * Канал включён. Токен для этого не нужен: выгрузки открыты,
     * он требуется только перечню Росфинмониторинга, который плагин не берёт.
     */
    public function is_enabled() {
        $settings = lem()->get_settings();
        return !empty($settings['channel_enabled']);
    }

    /** Канал умеет отдавать этот реестр? */
    public static function supports($type) {
        return isset(self::REGISTRY_MAP[$type]);
    }

    /**
     * Данные реестра из канала.
     *
     * @return array{entries: array, count: int}|array{error: string}
     */
    public function fetch($type) {
        if (!self::supports($type)) {
            return ['error' => 'канал такой реестр не ведёт'];
        }

        // Заголовок отправляем, только если токен задан: выгрузки открыты,
        // а лишний заголовок незачем гонять по сети
        $headers = $this->stats_headers();
        if ($this->has_token()) {
            $headers['Authorization'] = 'Bearer ' . $this->token();
        }

        $args = [
            'timeout'    => self::TIMEOUT,
            'user-agent' => 'LegalEntityMarks/' . LEM_VERSION,
            'headers'    => $headers,
        ];

        $url      = $this->base_url() . self::REGISTRY_MAP[$type] . '/export/lem';
        $response = wp_remote_get($url, $args);

        // Ограничение частоты на стороне канала: обновление берёт три реестра
        // подряд, и на общем адресе (офис, хостинг) счётчик может быть уже занят.
        // Одна повторная попытка дешевле, чем неделя на встроенном перечне
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 429) {
            sleep(3);
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return ['error' => 'соединение не удалось: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['error' => self::explain_code($code, wp_remote_retrieve_body($response))];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data)) {
            return ['error' => 'канал вернул неожиданный ответ'];
        }

        return ['entries' => $data, 'count' => count($data)];
    }

    /**
     * Понятная причина отказа: администратору важно различать «не настроено»
     * и «доступ отозван» - лечится это по-разному.
     */
    private static function explain_code($code, $body) {
        $detail = '';
        $json   = json_decode((string) $body, true);
        if (is_array($json) && !empty($json['detail'])) {
            $detail = ' (' . mb_substr((string) $json['detail'], 0, 120) . ')';
        }

        switch ((int) $code) {
            case 401:
                return 'канал требует токен для этого реестра: укажите его в настройках' . $detail;
            case 403:
                return 'токен не признан или отозван: нужен новый' . $detail;
            case 404:
                return 'в канале нет такого реестра' . $detail;
            case 429:
                return 'слишком частые запросы к каналу, попробуйте позже' . $detail;
            default:
                return "канал ответил HTTP $code" . $detail;
        }
    }

    /**
     * Что уйдёт при регистрации сайта. Ровно этот состав показан в настройках:
     * человек видит список до нажатия, а не после.
     */
    public function registration_payload($email = '') {
        return [
            'install_id'  => $this->install_id(),
            'site'        => home_url('/'),
            'name'        => get_bloginfo('name'),
            'plugin'      => LEM_VERSION,
            'wordpress'   => get_bloginfo('version'),
            'php'         => PHP_VERSION,
            'email'       => sanitize_email($email),
        ];
    }

    /**
     * Регистрация сайта у автора плагина.
     *
     * Отправляется только по явному нажатию кнопки. Сам по себе плагин домен
     * никуда не сообщает: список сайтов, которые маркируют иноагентов, - вещь,
     * которую отдают осознанно или не отдают вовсе.
     *
     * @return array{ok: bool, message: string}
     */
    public function register_site($email = '') {
        $response = wp_remote_post($this->base_url() . 'register', [
            'timeout'    => 30,
            'user-agent' => 'LegalEntityMarks/' . LEM_VERSION,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => wp_json_encode($this->registration_payload($email)),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => 'соединение не удалось: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return ['ok' => false, 'message' => 'сервер пока не принимает регистрации, попробуйте позже'];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'message' => self::explain_code($code, wp_remote_retrieve_body($response))];
        }

        update_option('lem_registered_at', current_time('mysql'), false);
        return ['ok' => true, 'message' => 'сайт зарегистрирован'];
    }

    /**
     * Живость канала. Токена не требует, поэтому годится для проверки
     * настроек и для показа даты последнего среза в админке.
     *
     * @return array{ok: bool, last_snapshot: string, error: string}
     */
    public function health() {
        $response = wp_remote_get($this->base_url() . 'health', [
            'timeout'    => 20,
            'user-agent' => 'LegalEntityMarks/' . LEM_VERSION,
            'headers'    => $this->stats_headers(),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'last_snapshot' => '', 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            return ['ok' => false, 'last_snapshot' => '', 'error' => "HTTP $code"];
        }

        return [
            'ok'            => !empty($data['ok']),
            'last_snapshot' => (string) ($data['last_snapshot'] ?? ''),
            'error'         => '',
        ];
    }
}
