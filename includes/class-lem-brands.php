<?php
defined('ABSPATH') || exit;

/**
 * Брендовые правила: как называют издание в статьях против того, как оно
 * записано в реестре Минюста.
 *
 * Правила живут в опции и редактируются пользователем. Файл
 * data/brand-aliases.json это только стартовое наполнение: при установке и
 * при обновлении плагина оттуда доезжают новые правила, но правки
 * пользователя не перетираются.
 */
class LEM_Brands {

    const OPTION = 'lem_brand_rules';

    /**
     * Все правила. При первом обращении наполняются из комплектного файла.
     *
     * @return array<int, array{match:string, aliases:string[], quoted:string[], note:string, enabled:bool}>
     */
    public function get_rules() {
        $rules = get_option(self::OPTION, null);
        if (!is_array($rules)) {
            $rules = $this->bundled();
            update_option(self::OPTION, $rules);
        }
        return array_values(array_filter(array_map([$this, 'normalize'], $rules)));
    }

    public function save_rules($rules) {
        $clean = array_values(array_filter(array_map([$this, 'normalize'], (array) $rules)));
        update_option(self::OPTION, $clean);
        lem()->entities->flush_cache();
        return $clean;
    }

    /**
     * Правила из комплектного файла (стартовое наполнение).
     */
    public function bundled() {
        $file = LEM_DATA_DIR . '/brand-aliases.json';
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter(array_map([$this, 'normalize'], $data)));
    }

    /**
     * Довозит новые комплектные правила, не трогая правки пользователя.
     * Правило, удалённое пользователем, вернётся только если оно новое по match.
     *
     * @return int сколько добавлено
     */
    public function sync_bundled() {
        $current = get_option(self::OPTION, null);
        if (!is_array($current)) {
            update_option(self::OPTION, $this->bundled());
            return 0;
        }
        $have  = [];
        foreach ($current as $r) {
            if (!empty($r['match'])) {
                $have[$r['match']] = true;
            }
        }
        $added = 0;
        foreach ($this->bundled() as $rule) {
            if (!isset($have[$rule['match']])) {
                $current[] = $rule;
                $added++;
            }
        }
        if ($added > 0) {
            update_option(self::OPTION, $current);
            lem()->entities->flush_cache();
        }
        return $added;
    }

    /**
     * Сколько записей реестра попадает под правило. Ноль означает опечатку в
     * match или изменившееся название в реестре, и правило молча не работает.
     */
    public function count_matches($match) {
        global $wpdb;
        $match = trim((string) $match);
        if ($match === '') {
            return 0;
        }
        $table = $wpdb->prefix . LEM_TABLE;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE name LIKE %s",
            '%' . $wpdb->esc_like($match) . '%'
        ));
    }

    private function normalize($rule) {
        if (!is_array($rule)) {
            return null;
        }
        $match = trim((string) ($rule['match'] ?? ''));
        if ($match === '') {
            return null;
        }
        $clean = static function ($list) {
            $out = [];
            foreach ((array) $list as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
            return array_values(array_unique($out));
        };
        $aliases = $clean($rule['aliases'] ?? []);
        $quoted  = $clean($rule['quoted'] ?? []);
        if (empty($aliases) && empty($quoted)) {
            return null;
        }
        return [
            'match'   => $match,
            'aliases' => $aliases,
            'quoted'  => $quoted,
            'note'    => trim((string) ($rule['note'] ?? '')),
            'enabled' => !array_key_exists('enabled', $rule) || (bool) $rule['enabled'],
        ];
    }
}
