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
    }

    public function run_fetch() {
        lem()->importer->fetch_all(function ($msg) {
            if (function_exists('error_log')) {
                error_log('[LEM] ' . $msg);
            }
        });
    }

    public function run_scan_updated() {
        $result = lem()->scanner->batch_scan([
            'batch' => 200,
            'log'   => function ($msg) {
                if (function_exists('error_log')) {
                    error_log('[LEM] ' . $msg);
                }
            },
        ]);

        if ($result['posts_with_matches'] > 0) {
            lem()->cache->purge_all_marked();
        }
    }
}
