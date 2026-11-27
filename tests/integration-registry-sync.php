<?php
/** Synthetic full-snapshot checks on real WordPress/MySQL, with complete restoration. */
defined('ABSPATH') || exit;
global $wpdb;
$tables = [$wpdb->prefix . CLEM_TABLE, $wpdb->prefix . 'clem_banned_sites'];
$backups = [];
$options = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'clem\\_%' OR option_name LIKE '\\_transient\\_clem\\_%' OR option_name LIKE '\\_transient\\_timeout\\_clem\\_%' OR option_name = 'cron'", ARRAY_A);
foreach ($tables as $i => $table) {
    $backup = 'clem_sync_test_' . $i;
    $wpdb->query("CREATE TEMPORARY TABLE $backup LIKE $table");
    $wpdb->query("INSERT INTO $backup SELECT * FROM $table");
    $backups[$table] = $backup;
}
$checks = 0;
$assert = static function ($label, $value) use (&$checks) {
    if (!$value) { throw new RuntimeException($label); }
    $checks++; echo 'PASS ' . $label . "\n";
};
$meta = static function ($entries, $version = '1', $seconds = 0) {
    return ['source'=>'channel', 'complete'=>true, 'count'=>count($entries), 'version'=>$version,
        'fetched_at'=>gmdate('c', time() + $seconds), 'source_modified'=>gmdate('c', time() + $seconds)];
};
$entry = static function ($name, $out = null) { return ['name'=>$name, 'aliases'=>[], 'is_person'=>0, 'date_excluded'=>$out]; };
$table = $tables[0];
$row = static function ($name, $type) use ($wpdb, $table) {
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE name=%s AND type=%s", $name, $type), ARRAY_A);
};
$http = null;
$original_channel = clem_plugin()->channel;
try {
    $settings = clem_plugin()->get_settings();
    $settings['fedsfm_people_mode'] = 'off'; $settings['stats_enabled'] = false;
    $settings['mark_registries'] = ['inoagent','extremist','terrorist','undesirable'];
    $settings['track_registries'] = $settings['mark_registries'];
    $settings['mark_excluded'] = true;
    clem_plugin()->update_settings($settings);
    foreach (CLEM_Registry_Sync::TYPES as $type) {
        delete_option(CLEM_Registry_Sync::OPTION_PREFIX . $type);
        $prefix = 'SyntheticSync_' . $type . '_';
        $a = $entry($prefix . 'Alpha'); $b = $entry($prefix . 'Beta'); $c = $entry($prefix . 'Gamma');
        $first = [$a,$b];
        $result = clem_plugin()->importer->import_snapshot($first, $type, $meta($first));
        $assert($type . ': initial full snapshot applied', !isset($result['error']));
        $alpha = $row($a['name'], $type);
        $manual_id = clem_plugin()->entities->insert(['type'=>$type,'name'=>$prefix.'Manual','is_active'=>1]);
        $site = $type . '-sync.example';
        clem_plugin()->banned_sites->insert(['domain'=>$site, 'label'=>'Synthetic owner', 'entity_id'=>$alpha['id']]);
        $assert($type . ': linked resource initially eligible', array_key_exists($site, clem_plugin()->banned_sites->get_index()));
        $second = [$b,$c];
        $result = clem_plugin()->importer->import_snapshot($second, $type, $meta($second, '2', 1));
        $alpha = $row($a['name'], $type);
        $assert($type . ': absent row retained but inactive without invented date', !isset($result['error']) && $alpha && !$alpha['is_active'] && !$alpha['registry_present'] && $alpha['date_excluded'] === null);
        $assert($type . ': manual row and current rows remain active', (int)clem_plugin()->entities->get_by_id($manual_id)['is_active'] === 1 && (int)$row($b['name'],$type)['is_active'] === 1 && (int)$row($c['name'],$type)['is_active'] === 1);
        $assert($type . ': absent row ineligible even when former entries enabled', clem_plugin()->entities->get_for_marking_ids([$alpha['id']], true) === []);
        $assert($type . ': forced old match cannot mark absent row', !CLEM_Frontend::should_mark($alpha, $settings, ['excluded'=>[], 'forced'=>[(int)$alpha['id']]]));
        $assert($type . ': linked domains and old removable metadata no longer apply', !in_array($site, clem_plugin()->banned_sites->get_all_domains(), true) && !array_key_exists($site, clem_plugin()->banned_sites->get_index()) && !CLEM_Link_Scanner::link_is_removable(['matched_domain'=>$site,'removable'=>true]));
        $partial = clem_plugin()->importer->import_entries([$a,$entry($prefix.'CopyOnly')], $type, true);
        $assert($type . ': old copy cannot reactivate or add vanished entries', !$row($a['name'],$type)['is_active'] && $row($prefix.'CopyOnly',$type) === null && !empty($partial['retained_verified_snapshot']));
        $state = get_option(CLEM_Registry_Sync::OPTION_PREFIX . $type);
        foreach (['count','empty','mixed','date','older','incomplete'] as $bad) {
            $payload = [$a]; $info = $meta($payload, '3', 2);
            if ($bad === 'count') { $info['count'] = 2; }
            if ($bad === 'empty') { $payload = []; $info['count'] = 0; }
            if ($bad === 'mixed') { $payload[0]['type'] = 'unsupported'; }
            if ($bad === 'date') { $payload[0]['date_excluded'] = '31.02.2026'; }
            if ($bad === 'older') { $info = $meta($payload, 'old', -3600); }
            if ($bad === 'incomplete') { $info['complete'] = false; }
            $result = clem_plugin()->importer->import_snapshot($payload, $type, $info);
            $assert($type . ': rejects ' . $bad . ' payload without changing state', isset($result['error']) && !$row($a['name'],$type)['is_active'] && get_option(CLEM_Registry_Sync::OPTION_PREFIX . $type) === $state);
        }
        $payload = [$a,$b,$c];
        $result = clem_plugin()->importer->import_snapshot($payload, $type, $meta($payload,'4',3));
        $assert($type . ': complete fresh snapshot can restore a reappearing row', !isset($result['error']) && $row($a['name'],$type)['is_active'] && $row($a['name'],$type)['registry_present']);
        $excluded = $entry($prefix.'Excluded', '2026-11-10');
        $payload[] = $excluded;
        $result = clem_plugin()->importer->import_snapshot($payload, $type, $meta($payload,'5',4));
        $former = $row($excluded['name'],$type);
        $assert($type . ': explicit legal exclusion remains available as former entry', !isset($result['error']) && !$former['is_active'] && $former['registry_present'] && count(clem_plugin()->entities->get_for_marking_ids([$former['id']],true)) === 1);
    }
    $type = 'extremist';
    $long = $entry(trim('SyntheticLong_' . str_repeat('Наименование ', 90)));
    $payload = [$long,$long];
    $result = clem_plugin()->importer->import_snapshot($payload, $type, $meta($payload,'long',10));
    $assert('Long names and exact duplicates survive without truncation', !isset($result['error']) && $row($long['name'],$type)['name'] === $long['name'] && $result['total'] === 1);
    $state = get_option(CLEM_Registry_Sync::OPTION_PREFIX . $type);
    $failure = static function ($query) use ($table) {
        return strpos($query, "UPDATE `$table`") === 0 && strpos($query, 'SyntheticLong_') !== false ? 'INVALID SYNTHETIC SNAPSHOT WRITE' : $query;
    };
    add_filter('query', $failure);
    $result = clem_plugin()->importer->import_snapshot([$long],$type,$meta([$long],'fail',11));
    remove_filter('query',$failure);
    $assert('Failed row write rolls back snapshot state and statuses', isset($result['error']) && $row($long['name'],$type)['is_active'] && get_option(CLEM_Registry_Sync::OPTION_PREFIX.$type) === $state);
    $lock_name = 'clem_sync_' . md5(DB_NAME . '|' . $wpdb->prefix);
    $other = new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock_name));
    $result = clem_plugin()->importer->import_snapshot([$long],$type,$meta([$long],'busy',12));
    $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock_name)); $other->close();
    $assert('Concurrent refresh cannot change a locked snapshot', !empty($result['busy']) && get_option(CLEM_Registry_Sync::OPTION_PREFIX.$type) === $state);

    $settings['channel_enabled'] = true;
    $settings['channel_url'] = CLEM_Channel::DEFAULT_URL;
    $settings['channel_consent_url'] = CLEM_Channel::DEFAULT_URL;
    $settings['channel_token'] = 'protected-test-token';
    clem_plugin()->update_settings($settings);

    $mode = 'complete';
    $calls = [];
    $http = static function ($pre, $args, $url) use (&$mode, &$calls) {
        $calls[] = [$url, $args['headers'], $args['redirection']];
        foreach (CLEM_Channel::REGISTRY_MAP as $type => $route) {
            if ($url === CLEM_Channel::DEFAULT_URL . $route . '/export/lem?format=snapshot') {
                if ($mode === 'offline') {
                    return new WP_Error('synthetic_timeout', 'Network disabled in integration test');
                }
                $data = [
                    'registry' => $type, 'complete' => $mode === 'complete', 'count' => 1,
                    'version' => 'observer-' . $type, 'fetched_at' => gmdate('c'),
                    'entries' => [['name' => 'SyntheticObserver ' . ucfirst($type)]],
                ];
                return ['headers' => [], 'body' => wp_json_encode($data), 'response' => ['code' => 200], 'cookies' => []];
            }
        }
        return new WP_Error('synthetic_unexpected_url', 'Only the Observer channel is permitted.');
    };
    add_filter('pre_http_request', $http, 10, 3);
    foreach (CLEM_Registry_Sync::TYPES as $type) {
        delete_option(CLEM_Registry_Sync::OPTION_PREFIX . $type);
    }
    $errors = clem_plugin()->importer->fetch_all();
    $assert('Observer complete update succeeds without a warning', !$errors && count($calls) === 4);
    $assert('Registry downloads send neither the people token nor statistics and do not follow redirects',
        array_filter($calls, static function ($call) { return $call[1] || $call[2] !== 0; }) === []);
    foreach (CLEM_Registry_Sync::TYPES as $type) {
        $snapshot = get_option(CLEM_Registry_Sync::OPTION_PREFIX . $type);
        $assert($type . ': Observer created a verified snapshot', $snapshot['source'] === 'channel');
        foreach (['incomplete', 'offline'] as $mode) {
            $result = clem_plugin()->importer->fetch_registry($type);
            $assert($type . ': ' . $mode . ' update retains verified statuses',
                isset($result['error']) && get_option(CLEM_Registry_Sync::OPTION_PREFIX . $type) === $snapshot
                && $row('SyntheticObserver ' . ucfirst($type), $type)['is_active']);
        }
    }
    remove_filter('pre_http_request', $http, 10);
    $http = null;
    $settings['fedsfm_people_mode'] = 'track'; clem_plugin()->update_settings($settings);
    $people_previous_success = get_option(CLEM_People::SUCCESS);
    $old_name = 'SyntheticOld Иван Петрович';
    clem_plugin()->importer->import_verified_entries([['name'=>$old_name,'is_person'=>1]],CLEM_People::TYPE,'old-generation');
    $manual_person = clem_plugin()->entities->insert(['name'=>'SyntheticManual Иван Петрович','type'=>CLEM_People::TYPE,'is_person'=>1]);
    clem_plugin()->channel = new class {
        public $broken = false;
        public function is_enabled() { return true; }
        public function has_token() { return true; }
        public function fetch_people_page($offset,$version) {
            if ($this->broken) { return ['error'=>'Synthetic interruption']; }
            $entries = [];
            for ($i=$offset;$i<min($offset+500,501);$i++) { $entries[] = ['name'=>'SyntheticNew'.$i.' Иван Петрович','type'=>CLEM_People::TYPE,'is_person'=>1]; }
            return ['entries'=>$entries,'offset'=>$offset,'total'=>501,'version'=>'synthetic-people-v1','fetched_at'=>gmdate('c')];
        }
    };
    clem_plugin()->people->queue(true); clem_plugin()->people->run_import();
    $assert('Physical people: first page does not activate new rows or retire previous rows', !$row('SyntheticNew0 Иван Петрович',CLEM_People::TYPE)['is_active'] && $row($old_name,CLEM_People::TYPE)['is_active']);
    clem_plugin()->channel->broken = true; clem_plugin()->people->run_import();
    $assert('Physical people: interrupted snapshot preserves previous active set', !$row('SyntheticNew0 Иван Петрович',CLEM_People::TYPE)['is_active'] && $row($old_name,CLEM_People::TYPE)['is_active'] && !get_option(CLEM_People::STATE)['running']);
    clem_plugin()->channel->broken = false; clem_plugin()->people->queue(true); clem_plugin()->people->run_import();
    $physical_failure = static function ($query) use ($table) {
        return strpos($query, "UPDATE $table SET is_active = IF(") === 0 ? 'INVALID SYNTHETIC PEOPLE PUBLICATION' : $query;
    };
    add_filter('query',$physical_failure); clem_plugin()->people->run_import(); remove_filter('query',$physical_failure);
    $assert('Physical people: failed final publication rolls back active statuses and success metadata', !$row('SyntheticNew0 Иван Петрович',CLEM_People::TYPE)['is_active'] && $row($old_name,CLEM_People::TYPE)['is_active'] && get_option(CLEM_People::SUCCESS) === $people_previous_success);
    clem_plugin()->people->queue(true); clem_plugin()->people->run_import(); clem_plugin()->people->run_import();
    $assert('Physical people: completed generation activates new rows and retires absent rows', $row('SyntheticNew0 Иван Петрович',CLEM_People::TYPE)['is_active'] && $row('SyntheticNew500 Иван Петрович',CLEM_People::TYPE)['is_active'] && !$row($old_name,CLEM_People::TYPE)['is_active'] && !$row($old_name,CLEM_People::TYPE)['registry_present']);
    $assert('Physical people: full-name matching, opt-in and manual records are preserved', (int)clem_plugin()->entities->get_by_id($manual_person)['is_active'] === 1 && clem_plugin()->get_settings()['fedsfm_people_mode'] === 'track' && $row('SyntheticNew0 Иван Петрович',CLEM_People::TYPE)['aliases'] === '[]');
    echo 'Registry synchronization integration checks passed: '.$checks."\n";
} finally {
    clem_plugin()->channel = $original_channel;
    if ($http) { remove_filter('pre_http_request',$http,10); }
    foreach ($backups as $table => $backup) {
        $wpdb->query("DELETE FROM $table"); $wpdb->query("INSERT INTO $table SELECT * FROM $backup"); $wpdb->query("DROP TEMPORARY TABLE $backup");
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'clem\\_%' OR option_name LIKE '\\_transient\\_clem\\_%' OR option_name LIKE '\\_transient\\_timeout\\_clem\\_%' OR option_name = 'cron'");
    foreach ($options as $option) { $wpdb->insert($wpdb->options,$option); }
    wp_cache_flush(); clem_plugin()->entities->flush_cache();
}
