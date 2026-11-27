<?php
defined('ABSPATH') || exit;

class CLEM_Cron {

    public function __construct() {
        add_filter('cron_schedules', [$this, 'add_weekly_schedule']);
        add_action('clem_fetch_registries', [$this, 'run_fetch']);
        add_action('clem_scan_updated', [$this, 'run_scan_updated']);
    }

    public function add_weekly_schedule($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Раз в неделю', 'chekazoid-legal-entity-marks'),
            ];
        }
        return $schedules;
    }

    public function schedule_events() {
        $settings = clem_plugin()->get_settings();
        $interval = $settings['cron_interval'] ?: 'weekly';

        if ($settings['channel_enabled'] && !wp_next_scheduled('clem_fetch_registries')) {
            wp_schedule_event(time(), $interval, 'clem_fetch_registries');
        }

        if (!wp_next_scheduled('clem_scan_updated')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, $interval, 'clem_scan_updated');
        }
    }

    public function clear_events() {
        wp_clear_scheduled_hook('clem_fetch_registries');
        wp_clear_scheduled_hook('clem_scan_updated');
        wp_clear_scheduled_hook('clem_import_people');
        wp_clear_scheduled_hook(CLEM_Rescan::HOOK);
        delete_transient('clem_rescan_lock'); // хвост от версий до 1.14.0
    }

    /**
     * Планировщик жив? Отметку ставят сами задачи.
     *
     * DISABLE_WP_CRON сам по себе ничего не значит: на нормально настроенном
     * сервере это признак того, что задачи запускает системный cron, а не
     * посетители сайта. Судить надо по тому, выполняются ли они на самом деле.
     */
    public static function last_run() {
        return (int) get_option('clem_cron_last_run', 0);
    }

    public static function looks_alive() {
        $last = self::last_run();
        if ($last > 0 && (time() - $last) < 2 * HOUR_IN_SECONDS) {
            return true;
        }

        // Своих отметок может не быть: плагин только обновился, а его задачи
        // ещё не подходили. Тогда смотрим на общую очередь WordPress: если
        // самая ранняя задача просрочена ненадолго, планировщик работает
        if (!function_exists('_get_cron_array')) {
            return true;
        }
        $crons = _get_cron_array();
        if (empty($crons)) {
            return true; // задач нет вовсе, судить не по чему
        }

        $earliest = min(array_keys($crons));
        return (time() - $earliest) < HOUR_IN_SECONDS;
    }

    public function run_fetch() {
        if (!clem_plugin()->channel->is_enabled()) {
            return;
        }
        update_option('clem_cron_last_run', time(), false);
        clem_plugin()->importer->fetch_all(function ($msg) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WordPress debug logging is explicitly enabled by the site administrator.
                error_log('[LEM] ' . $msg);
            }
        });
    }

    /**
     * Недельная подстраховка: пройти архив, даже если обновление реестров
     * не состоялось. Сам проход делает очередь - порциями и с оглядкой
     * на лимит выполнения, иначе на большом сайте задача обрывалась молча.
     */
    public function run_scan_updated() {
        update_option('clem_cron_last_run', time(), false);

        $state = clem_plugin()->rescan->status();
        if ($state && empty($state['done'])) {
            return; // проверка и так идёт
        }
        clem_plugin()->rescan->enqueue('weekly', current_time('mysql'));
    }
}
