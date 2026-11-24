<?php
/** Synthetic full-snapshot checks on real WordPress/MySQL, with complete restoration. */
defined('ABSPATH') || exit;
global $wpdb;
$tables = [$wpdb->prefix . LEM_TABLE, $wpdb->prefix . 'lem_banned_sites'];
$backups = [];
$options = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'lem\\_%' OR option_name LIKE '\\_transient\\_lem\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lem\\_%' OR option_name = 'cron'", ARRAY_A);
foreach ($tables as $i => $table) {
    $backup = 'lem_sync_test_' . $i;
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
$original_channel = lem()->channel;
try {
    $settings = lem()->get_settings();
    $settings['fedsfm_people_mode'] = 'off'; $settings['stats_enabled'] = false;
    $settings['mark_registries'] = ['inoagent','extremist','terrorist','undesirable'];
    $settings['track_registries'] = $settings['mark_registries'];
    $settings['mark_excluded'] = true;
    lem()->update_settings($settings);
    foreach (LEM_Registry_Sync::TYPES as $type) {
        delete_option(LEM_Registry_Sync::OPTION_PREFIX . $type);
        $prefix = 'SyntheticSync_' . $type . '_';
        $a = $entry($prefix . 'Alpha'); $b = $entry($prefix . 'Beta'); $c = $entry($prefix . 'Gamma');
        $first = [$a,$b];
        $result = lem()->importer->import_snapshot($first, $type, $meta($first));
        $assert($type . ': initial full snapshot applied', !isset($result['error']));
        $alpha = $row($a['name'], $type);
        $manual_id = lem()->entities->insert(['type'=>$type,'name'=>$prefix.'Manual','is_active'=>1]);
        $site = $type . '-sync.example';
        lem()->banned_sites->insert(['domain'=>$site, 'label'=>'Synthetic owner', 'entity_id'=>$alpha['id']]);
        $assert($type . ': linked resource initially eligible', array_key_exists($site, lem()->banned_sites->get_index()));
        $second = [$b,$c];
        $result = lem()->importer->import_snapshot($second, $type, $meta($second, '2', 1));
        $alpha = $row($a['name'], $type);
        $assert($type . ': absent row retained but inactive without invented date', !isset($result['error']) && $alpha && !$alpha['is_active'] && !$alpha['registry_present'] && $alpha['date_excluded'] === null);
        $assert($type . ': manual row and current rows remain active', (int)lem()->entities->get_by_id($manual_id)['is_active'] === 1 && (int)$row($b['name'],$type)['is_active'] === 1 && (int)$row($c['name'],$type)['is_active'] === 1);
        $assert($type . ': absent row ineligible even when former entries enabled', lem()->entities->get_for_marking_ids([$alpha['id']], true) === []);
        $assert($type . ': forced old match cannot mark absent row', !LEM_Frontend::should_mark($alpha, $settings, ['excluded'=>[], 'forced'=>[(int)$alpha['id']]]));
        $assert($type . ': linked domains and old removable metadata no longer apply', !in_array($site, lem()->banned_sites->get_all_domains(), true) && !array_key_exists($site, lem()->banned_sites->get_index()) && !LEM_Link_Scanner::link_is_removable(['matched_domain'=>$site,'removable'=>true]));
        $partial = lem()->importer->import_entries([$a,$entry($prefix.'CopyOnly')], $type, true);
        $assert($type . ': old copy cannot reactivate or add vanished entries', !$row($a['name'],$type)['is_active'] && $row($prefix.'CopyOnly',$type) === null && !empty($partial['retained_verified_snapshot']));
        $state = get_option(LEM_Registry_Sync::OPTION_PREFIX . $type);
        foreach (['count','empty','mixed','date','older','incomplete'] as $bad) {
            $payload = [$a]; $info = $meta($payload, '3', 2);
            if ($bad === 'count') { $info['count'] = 2; }
            if ($bad === 'empty') { $payload = []; $info['count'] = 0; }
            if ($bad === 'mixed') { $payload[0]['type'] = 'unsupported'; }
            if ($bad === 'date') { $payload[0]['date_excluded'] = '31.02.2026'; }
            if ($bad === 'older') { $info = $meta($payload, 'old', -3600); }
            if ($bad === 'incomplete') { $info['complete'] = false; }
            $result = lem()->importer->import_snapshot($payload, $type, $info);
            $assert($type . ': rejects ' . $bad . ' payload without changing state', isset($result['error']) && !$row($a['name'],$type)['is_active'] && get_option(LEM_Registry_Sync::OPTION_PREFIX . $type) === $state);
        }
        $payload = [$a,$b,$c];
        $result = lem()->importer->import_snapshot($payload, $type, $meta($payload,'4',3));
        $assert($type . ': complete fresh snapshot can restore a reappearing row', !isset($result['error']) && $row($a['name'],$type)['is_active'] && $row($a['name'],$type)['registry_present']);
        $excluded = $entry($prefix.'Excluded', '2026-11-10');
        $payload[] = $excluded;
        $result = lem()->importer->import_snapshot($payload, $type, $meta($payload,'5',4));
        $former = $row($excluded['name'],$type);
        $assert($type . ': explicit legal exclusion remains available as former entry', !isset($result['error']) && !$former['is_active'] && $former['registry_present'] && count(lem()->entities->get_for_marking_ids([$former['id']],true)) === 1);
    }
    $type = 'extremist';
    $long = $entry(trim('SyntheticLong_' . str_repeat('Наименование ', 90)));
    $payload = [$long,$long];
    $result = lem()->importer->import_snapshot($payload, $type, $meta($payload,'long',10));
    $assert('Long names and exact duplicates survive without truncation', !isset($result['error']) && $row($long['name'],$type)['name'] === $long['name'] && $result['total'] === 1);
    $state = get_option(LEM_Registry_Sync::OPTION_PREFIX . $type);
    $failure = static function ($query) use ($table) {
        return strpos($query, "UPDATE `$table`") === 0 && strpos($query, 'SyntheticLong_') !== false ? 'INVALID SYNTHETIC SNAPSHOT WRITE' : $query;
    };
    add_filter('query', $failure);
    $result = lem()->importer->import_snapshot([$long],$type,$meta([$long],'fail',11));
    remove_filter('query',$failure);
    $assert('Failed row write rolls back snapshot state and statuses', isset($result['error']) && $row($long['name'],$type)['is_active'] && get_option(LEM_Registry_Sync::OPTION_PREFIX.$type) === $state);
    $lock_name = 'lem_sync_' . md5(DB_NAME . '|' . $wpdb->prefix);
    $other = new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock_name));
    $result = lem()->importer->import_snapshot([$long],$type,$meta([$long],'busy',12));
    $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock_name)); $other->close();
    $assert('Concurrent refresh cannot change a locked snapshot', !empty($result['busy']) && get_option(LEM_Registry_Sync::OPTION_PREFIX.$type) === $state);

    // Official-source contract: total, exact pages, and unchanged before/after revision.
    $mode = 'valid'; $info_calls = 0;
    $http = static function ($pre, $args, $url) use (&$mode, &$info_calls) {
        if (strpos($url,'https://reestrs.minjust.gov.ru/rest/registry/') !== 0) { return new WP_Error('synthetic_no_network','Network disabled in integration test'); }
        if (substr($url,-5) === '/info') {
            $info_calls++;
            $data = ['lastModified'=>1795510800000 + ($mode === 'changed' && $info_calls % 2 === 0 ? 1000 : 0)];
        } else {
            $body = json_decode($args['body'],true);
            $entries = [['field_2_s'=>'SyntheticOfficial Alpha','field_7_s'=>'Организации']];
            if ($mode === 'short' || $mode === 'repeated') {
                $entries = [];
                if ($body['offset'] === 0) { for($i=0;$i<500;$i++) { $entries[] = ['field_2_s'=>'SyntheticOfficial '.$i]; } }
                elseif ($mode === 'repeated') { $entries[] = ['field_2_s'=>'SyntheticOfficial 0']; }
            }
            if ($mode === 'malformed') { $entries[0]['field_2_s'] = ['invalid']; }
            if (strpos($url,LEM_Importer::UNDESIRABLE_GRID) !== false) { $entries = [['field_5_s'=>'SyntheticUndesirable Alpha','field_2_s'=>'01.11.2026','field_7_s'=>'10.11.2026','field_8_s'=>'1234','field_10_s'=>'Исключена']]; }
            $data = ['values'=>$entries,'size'=>in_array($mode,['short','repeated'],true) ? 501 : 1];
            if ($mode === 'missing-total') { unset($data['size']); }
        }
        return ['headers'=>[], 'body'=>wp_json_encode($data), 'response'=>['code'=>200], 'cookies'=>[]];
    };
    add_filter('pre_http_request',$http,10,3);
    foreach (['missing-total','short','changed','repeated','malformed'] as $mode) {
        $info_calls = 0; $old = get_option(LEM_Registry_Sync::OPTION_PREFIX.'inoagent');
        $result = lem()->importer->fetch_inoagents();
        $assert('Official source rejects '.$mode.' without reconciliation', isset($result['error']) && get_option(LEM_Registry_Sync::OPTION_PREFIX.'inoagent') === $old);
    }
    // A complete official list is accepted; choose a revision newer than prior synthetic snapshots.
    $mode = 'valid'; $info_calls = 0;
    delete_option(LEM_Registry_Sync::OPTION_PREFIX.'inoagent');
    $result = lem()->importer->fetch_inoagents();
    $assert('Official complete list creates a verified snapshot', !isset($result['error']) && get_option(LEM_Registry_Sync::OPTION_PREFIX.'inoagent')['source'] === 'official');
    delete_option(LEM_Registry_Sync::OPTION_PREFIX.'extremist');
    $result = lem()->importer->fetch_extremist_orgs();
    $assert('Extremist official REST list is reconciled as a full snapshot', !isset($result['error']) && get_option(LEM_Registry_Sync::OPTION_PREFIX.'extremist')['source'] === 'official');
    delete_option(LEM_Registry_Sync::OPTION_PREFIX.'undesirable');
    $result = lem()->importer->fetch_undesirable_orgs();
    $excluded_org = $row('SyntheticUndesirable Alpha','undesirable');
    $assert('Undesirable legal exclusion uses date field rather than order number', !isset($result['error']) && $excluded_org['date_excluded'] === '2026-11-10' && !$excluded_org['is_active']);
    $parser = new ReflectionMethod(LEM_Importer::class,'parse_generic_list'); $parser->setAccessible(true);
    $html = '<html><body><table><tr><td>1</td><td>Synthetic Alpha</td><td>Court</td></tr><tr><td>2</td><td>Synthetic Beta</td><td>Court</td></tr></table></body></html>';
    $assert('FSB parser accepts a complete numbered table', count($parser->invoke(lem()->importer,$html)) === 2);
    $assert('FSB parser rejects truncated HTML and numbering gaps', $parser->invoke(lem()->importer,substr($html,0,-10)) === [] && $parser->invoke(lem()->importer,str_replace('<td>2</td>','<td>3</td>',$html)) === []);
    remove_filter('pre_http_request',$http,10); $http = null;
    $settings['fedsfm_people_mode'] = 'track'; lem()->update_settings($settings);
    $people_previous_success = get_option(LEM_People::SUCCESS);
    $old_name = 'SyntheticOld Иван Петрович';
    lem()->importer->import_verified_entries([['name'=>$old_name,'is_person'=>1]],LEM_People::TYPE,'old-generation');
    $manual_person = lem()->entities->insert(['name'=>'SyntheticManual Иван Петрович','type'=>LEM_People::TYPE,'is_person'=>1]);
    lem()->channel = new class {
        public $broken = false;
        public function is_enabled() { return true; }
        public function has_token() { return true; }
        public function fetch_people_page($offset,$version) {
            if ($this->broken) { return ['error'=>'Synthetic interruption']; }
            $entries = [];
            for ($i=$offset;$i<min($offset+500,501);$i++) { $entries[] = ['name'=>'SyntheticNew'.$i.' Иван Петрович','type'=>LEM_People::TYPE,'is_person'=>1]; }
            return ['entries'=>$entries,'offset'=>$offset,'total'=>501,'version'=>'synthetic-people-v1','fetched_at'=>gmdate('c')];
        }
    };
    lem()->people->queue(true); lem()->people->run_import();
    $assert('Physical people: first page does not activate new rows or retire previous rows', !$row('SyntheticNew0 Иван Петрович',LEM_People::TYPE)['is_active'] && $row($old_name,LEM_People::TYPE)['is_active']);
    lem()->channel->broken = true; lem()->people->run_import();
    $assert('Physical people: interrupted snapshot preserves previous active set', !$row('SyntheticNew0 Иван Петрович',LEM_People::TYPE)['is_active'] && $row($old_name,LEM_People::TYPE)['is_active'] && !get_option(LEM_People::STATE)['running']);
    lem()->channel->broken = false; lem()->people->queue(true); lem()->people->run_import();
    $physical_failure = static function ($query) use ($table) {
        return strpos($query, "UPDATE $table SET is_active = IF(") === 0 ? 'INVALID SYNTHETIC PEOPLE PUBLICATION' : $query;
    };
    add_filter('query',$physical_failure); lem()->people->run_import(); remove_filter('query',$physical_failure);
    $assert('Physical people: failed final publication rolls back active statuses and success metadata', !$row('SyntheticNew0 Иван Петрович',LEM_People::TYPE)['is_active'] && $row($old_name,LEM_People::TYPE)['is_active'] && get_option(LEM_People::SUCCESS) === $people_previous_success);
    lem()->people->queue(true); lem()->people->run_import(); lem()->people->run_import();
    $assert('Physical people: completed generation activates new rows and retires absent rows', $row('SyntheticNew0 Иван Петрович',LEM_People::TYPE)['is_active'] && $row('SyntheticNew500 Иван Петрович',LEM_People::TYPE)['is_active'] && !$row($old_name,LEM_People::TYPE)['is_active'] && !$row($old_name,LEM_People::TYPE)['registry_present']);
    $assert('Physical people: full-name matching, opt-in and manual records are preserved', (int)lem()->entities->get_by_id($manual_person)['is_active'] === 1 && lem()->get_settings()['fedsfm_people_mode'] === 'track' && $row('SyntheticNew0 Иван Петрович',LEM_People::TYPE)['aliases'] === '[]');
    echo 'Registry synchronization integration checks passed: '.$checks."\n";
} finally {
    lem()->channel = $original_channel;
    if ($http) { remove_filter('pre_http_request',$http,10); }
    foreach ($backups as $table => $backup) {
        $wpdb->query("DELETE FROM $table"); $wpdb->query("INSERT INTO $table SELECT * FROM $backup"); $wpdb->query("DROP TEMPORARY TABLE $backup");
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lem\\_%' OR option_name LIKE '\\_transient\\_lem\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lem\\_%' OR option_name = 'cron'");
    foreach ($options as $option) { $wpdb->insert($wpdb->options,$option); }
    wp_cache_flush(); lem()->entities->flush_cache();
}
