<?php
defined('ABSPATH') || exit;

class LEM_Cron {

    public function __construct() {
        add_filter('cron_schedules', [$this, 'add_weekly_schedule']);
        add_action('lem_fetch_registries', [$this, 'run_fetch']);
        add_action('lem_scan_updated', [$this, 'run_scan_updated']);
    }

    public function add_weekly_schedule($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => 'Раз в неделю',
            ];
        }
        return $schedules;
    }

    public function schedule_events() {
        $settings = lem()->get_settings();
        $interval = $settings['cron_interval'] ?: 'weekly';

        if (!wp_next_scheduled('lem_fetch_registries')) {
            wp_schedule_event(time(), $interval, 'lem_fetch_registries');
        }

        if (!wp_next_scheduled('lem_scan_updated')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, $interval, 'lem_scan_updated');
        }
    }

    public function clear_events() {
        wp_clear_scheduled_hook('lem_fetch_registries');
        wp_clear_scheduled_hook('lem_scan_updated');
        wp_clear_scheduled_hook(LEM_Rescan::HOOK);
    }

    public function run_fetch() {
        lem()->importer->fetch_all(function ($msg) {
            if (function_exists('error_log')) {
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
        $state = lem()->rescan->status();
        if ($state && empty($state['done'])) {
            return; // проверка и так идёт
        }
        lem()->rescan->enqueue('weekly', current_time('mysql'));
    }
}
