<?php
defined('ABSPATH') || exit;

class LEM_CLI {

    public function __construct() {
        WP_CLI::add_command('lem import', [$this, 'cmd_import']);
        WP_CLI::add_command('lem fetch', [$this, 'cmd_fetch']);
        WP_CLI::add_command('lem scan', [$this, 'cmd_scan']);
        WP_CLI::add_command('lem status', [$this, 'cmd_status']);
        WP_CLI::add_command('lem list', [$this, 'cmd_list']);
        WP_CLI::add_command('lem purge-cache', [$this, 'cmd_purge_cache']);
        WP_CLI::add_command('lem brand-aliases', [$this, 'cmd_brand_aliases']);

        WP_CLI::add_command('lem banned-sites', [$this, 'cmd_banned_sites']);
        WP_CLI::add_command('lem banned-sites-add', [$this, 'cmd_banned_sites_add']);
        WP_CLI::add_command('lem banned-links-scan', [$this, 'cmd_banned_links_scan']);
        WP_CLI::add_command('lem banned-links-clean', [$this, 'cmd_banned_links_clean']);
    }

    /**
     * Импорт сущностей из встроенных JSON-файлов.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Импортировать все встроенные файлы данных.
     *
     * [--file=<path>]
     * : Импорт из указанного JSON-файла.
     *
     * [--type=<type>]
     * : Тип сущности при импорте из файла.
     */
    public function cmd_import($args, $assoc_args) {
        if (isset($assoc_args['all'])) {
            $results = lem()->importer->import_all_bundled();
            foreach ($results as $type => $result) {
                if (isset($result['error'])) {
                    WP_CLI::warning("$type: {$result['error']}");
                } elseif (isset($result['updated'])) {
                    WP_CLI::log("$type: добавлено={$result['added']}, обновлено={$result['updated']}, пропущено={$result['skipped']}");
                } else {
                    WP_CLI::log("$type: добавлено={$result['added']}, пропущено={$result['skipped']}");
                }
            }
            WP_CLI::success('Импорт завершён.');
            return;
        }

        $file = $assoc_args['file'] ?? null;
        if ($file) {
            if (!file_exists($file)) {
                WP_CLI::error("Файл не найден: $file");
            }
            $type   = $assoc_args['type'] ?? null;
            $result = lem()->importer->import_json($file, $type);
            if (isset($result['error'])) {
                WP_CLI::error($result['error']);
            }
            WP_CLI::success("Импортировано: добавлено={$result['added']}, обновлено={$result['updated']}, пропущено={$result['skipped']}");
            return;
        }

        WP_CLI::error('Укажите --all или --file=path');
    }

    /**
     * Загрузка реестров из онлайн-источников.
     *
     * ## OPTIONS
     *
     * [--source=<source>]
     * : Какой источник обновить (all|inoagent|extremist|terrorist|undesirable).
     * ---
     * default: all
     * ---
     */
    public function cmd_fetch($args, $assoc_args) {
        $source = $assoc_args['source'] ?? 'all';
        $log    = function ($msg) { WP_CLI::log($msg); };

        if ($source === 'all') {
            $errors = lem()->importer->fetch_all($log);
            if (empty($errors)) {
                WP_CLI::success('Все реестры успешно обновлены.');
            } else {
                WP_CLI::warning('Завершено с ошибками: ' . implode('; ', $errors));
            }
        } else {
            $methods = [
                'inoagent'    => 'fetch_inoagents',
                'extremist'   => 'fetch_extremist_orgs',
                'terrorist'   => 'fetch_terrorist_orgs',
                'undesirable' => 'fetch_undesirable_orgs',
            ];
            if (!isset($methods[$source])) {
                WP_CLI::error("Неизвестный источник: $source");
            }
            $method = $methods[$source];
            $result = lem()->importer->$method();
            if (isset($result['error'])) {
                WP_CLI::error($result['error']);
            }
            WP_CLI::success("Готово: добавлено={$result['added']}, обновлено={$result['updated']}");
        }
    }

    /**
     * Сканирование статей на упоминание сущностей.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Сканировать все опубликованные статьи.
     *
     * [--post_id=<id>]
     * : Сканировать одну статью.
     *
     * [--recent=<days>]
     * : Сканировать статьи за последние N дней.
     *
     * [--batch=<size>]
     * : Размер батча для сканирования.
     * ---
     * default: 500
     * ---
     *
     * [--dry-run]
     * : Показать результаты без сохранения.
     */
    public function cmd_scan($args, $assoc_args) {
        $entities = lem()->entities->get_all_active();
        WP_CLI::log('Загружено ' . count($entities) . ' активных сущностей.');

        $post_id = $assoc_args['post_id'] ?? null;
        if ($post_id) {
            $found = lem()->scanner->scan_post((int) $post_id);
            if (empty($found)) {
                WP_CLI::log("Совпадений в статье #$post_id не найдено.");
            } else {
                WP_CLI::log('Найдено ' . count($found) . " сущностей в статье #$post_id:");
                foreach ($found as $f) {
                    WP_CLI::log("  - [{$f['type']}] {$f['name']} (совпадение: \"{$f['matched_as']}\", позиция: {$f['position']})");
                }
            }
            return;
        }

        $result = lem()->scanner->batch_scan([
            'batch'   => (int) ($assoc_args['batch'] ?? 500),
            'dry_run' => isset($assoc_args['dry-run']),
            'recent'  => $assoc_args['recent'] ?? null,
            'log'     => function ($msg) { WP_CLI::log($msg); },
        ]);

        WP_CLI::log("Статей с совпадениями: {$result['posts_with_matches']}");
        WP_CLI::log("Всего упоминаний: {$result['total_mentions']}");

        $dry_run = isset($assoc_args['dry-run']);
        if (!$dry_run && $result['posts_with_matches'] > 0) {
            WP_CLI::log('Очистка кеша помеченных статей...');
            $purged = lem()->cache->purge_all_marked();
            WP_CLI::log("Кеш очищен для $purged статей.");
        }

        WP_CLI::success($dry_run ? 'Пробный запуск завершён.' : 'Сканирование завершено.');
    }

    /**
     * Статус плагина и статистика.
     */
    public function cmd_status($args, $assoc_args) {
        WP_CLI::log('=== Маркировка: статус ===');

        $counts = lem()->entities->count_by_type();
        WP_CLI::log("\nСущности:");
        foreach ($counts as $key => $cnt) {
            WP_CLI::log("  $key: $cnt");
        }

        global $wpdb;
        $marked = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '" . LEM_META_KEY . "' AND meta_value != ''"
        );
        WP_CLI::log("\nСтатей с маркировкой: $marked");

        $version = get_option('lem_list_version', 'не задана');
        WP_CLI::log("Версия списков: $version");

        $last_fetch = get_option('lem_last_fetch_time', 'никогда');
        WP_CLI::log("Последнее обновление: $last_fetch");

        $error = get_option('lem_last_fetch_error', '');
        if ($error) {
            WP_CLI::warning("Последняя ошибка: $error");
        }
    }

    /**
     * Список сущностей в реестре.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Фильтр по типу.
     *
     * [--search=<term>]
     * : Поиск по имени или алиасу.
     *
     * [--limit=<n>]
     * : Максимум записей.
     * ---
     * default: 50
     * ---
     *
     * [--include-removed]
     * : Показать также исключённые сущности.
     */
    public function cmd_list($args, $assoc_args) {
        $result = lem()->entities->search([
            'type'      => $assoc_args['type'] ?? '',
            'search'    => $assoc_args['search'] ?? '',
            'is_active' => isset($assoc_args['include-removed']) ? null : 1,
            'limit'     => (int) ($assoc_args['limit'] ?? 50),
            'orderby'   => 'type',
            'order'     => 'ASC',
        ]);

        if (empty($result['items'])) {
            WP_CLI::log('Записи не найдены.');
            return;
        }

        $items = array_map(function ($row) {
            return [
                'id'            => $row['id'],
                'type'          => $row['type'],
                'name'          => $row['name'],
                'is_person'     => $row['is_person'],
                'is_active'     => $row['is_active'],
                'date_included' => $row['date_included'] ?? '',
            ];
        }, $result['items']);

        $formatter = new \WP_CLI\Formatter($assoc_args, ['id', 'type', 'name', 'is_person', 'is_active', 'date_included']);
        $formatter->display_items($items);
    }

    /**
     * Очистить кеш (transient сущностей + страничный кеш помеченных статей).
     */
    public function cmd_purge_cache($args, $assoc_args) {
        lem()->entities->flush_cache();
        WP_CLI::log('Кеш сущностей очищен.');

        $purged = lem()->cache->purge_all_marked();
        WP_CLI::log("Страничный кеш очищен для $purged статей.");

        WP_CLI::success('Кеш очищен.');
    }

    /**
     * Применить курируемые брендовые алиасы (data/brand-aliases.json)
     * поверх текущих записей реестра.
     */
    public function cmd_brand_aliases($args, $assoc_args) {
        $result = lem()->importer->apply_brand_aliases();
        if (isset($result['error'])) {
            WP_CLI::error($result['error']);
        }
        WP_CLI::success("Брендовых алиасов применено: {$result['applied']}");
    }

    /**
     * Найти индекс колонки по возможным именам заголовка.
     */
    private function find_csv_column($header, $candidates) {
        foreach ($header as $i => $col) {
            foreach ($candidates as $c) {
                if ($col === $c) {
                    return $i;
                }
            }
        }
        return false;
    }

    /* ==================================================================
     * Запрещённые сайты и ссылки
     * ================================================================== */

    /**
     * Список запрещённых доменов.
     *
     * ## OPTIONS
     *
     * [--search=<term>]
     * : Поиск по домену или названию.
     *
     * [--limit=<n>]
     * : Максимум записей.
     * ---
     * default: 100
     * ---
     */
    public function cmd_banned_sites($args, $assoc_args) {
        $result = lem()->banned_sites->search([
            'search' => $assoc_args['search'] ?? '',
            'limit'  => (int) ($assoc_args['limit'] ?? 100),
        ]);

        if (empty($result['items'])) {
            WP_CLI::log('Запрещённых доменов не найдено.');
            return;
        }

        $formatter = new \WP_CLI\Formatter($assoc_args, ['id', 'domain', 'label', 'added_at']);
        $formatter->display_items($result['items']);
        WP_CLI::log("Всего: {$result['total']}");
    }

    /**
     * Добавить запрещённый домен.
     *
     * ## OPTIONS
     *
     * [--domain=<domain>]
     * : Домен для добавления.
     *
     * [--label=<label>]
     * : Название организации.
     *
     * [--file=<path>]
     * : Файл с доменами (TXT: домен | название; CSV: колонки domain,label через , или ;).
     *
     * [--json-file=<path>]
     * : JSON-файл в формате [{domain, label}, ...].
     */
    public function cmd_banned_sites_add($args, $assoc_args) {
        // JSON-файл
        $json_file = $assoc_args['json-file'] ?? null;
        if ($json_file) {
            if (!file_exists($json_file)) {
                WP_CLI::error("Файл не найден: $json_file");
            }
            $result = lem()->importer->import_banned_sites($json_file);
            if (isset($result['error'])) {
                WP_CLI::error($result['error']);
            }
            WP_CLI::success("Добавлено: {$result['added']}, пропущено: {$result['skipped']}");
            return;
        }

        // TXT или CSV файл
        $file = $assoc_args['file'] ?? null;
        if ($file) {
            if (!file_exists($file)) {
                WP_CLI::error("Файл не найден: $file");
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $added   = 0;
            $skipped = 0;

            if ($ext === 'csv') {
                // CSV: auto-detect separator, find domain/label columns
                $handle = fopen($file, 'r');
                $first_line = fgets($handle);
                rewind($handle);

                $sep = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';
                $header = fgetcsv($handle, 0, $sep);
                $header = array_map(function ($h) { return mb_strtolower(trim($h)); }, $header);

                $domain_col = $this->find_csv_column($header, ['domain', 'домен', 'url', 'сайт', 'site']);
                $label_col  = $this->find_csv_column($header, ['label', 'название', 'name', 'организация', 'org']);

                if ($domain_col === false) {
                    fclose($handle);
                    WP_CLI::error('Не найдена колонка с доменом (domain/домен/url/сайт).');
                }

                while (($row = fgetcsv($handle, 0, $sep)) !== false) {
                    $domain = LEM_Banned_Sites::normalize_domain($row[$domain_col] ?? '');
                    $label  = ($label_col !== false) ? trim($row[$label_col] ?? '') : '';
                    if (empty($domain)) {
                        $skipped++;
                        continue;
                    }
                    $result = lem()->banned_sites->insert(['domain' => $domain, 'label' => $label]);
                    if ($result) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
                fclose($handle);
            } else {
                // TXT: domain | label (one per line)
                $lines = array_filter(array_map('trim', file($file)));
                foreach ($lines as $line) {
                    if (empty($line) || $line[0] === '#') {
                        continue;
                    }
                    $parts  = array_map('trim', explode('|', $line, 2));
                    $domain = LEM_Banned_Sites::normalize_domain($parts[0]);
                    $label  = $parts[1] ?? '';
                    if (empty($domain)) {
                        $skipped++;
                        continue;
                    }
                    $result = lem()->banned_sites->insert(['domain' => $domain, 'label' => $label]);
                    if ($result) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
            }

            WP_CLI::success("Добавлено: $added, пропущено: $skipped");
            return;
        }

        $domain = $assoc_args['domain'] ?? null;
        if (empty($domain)) {
            WP_CLI::error('Укажите --domain=example.org или --file=path.txt');
        }

        $result = lem()->banned_sites->insert([
            'domain' => $domain,
            'label'  => $assoc_args['label'] ?? '',
        ]);
        if ($result) {
            $normalized = LEM_Banned_Sites::normalize_domain($domain);
            WP_CLI::success("Добавлен домен: $normalized (ID: $result)");
        } else {
            WP_CLI::error('Не удалось добавить (возможно, домен уже существует).');
        }
    }

    /**
     * Сканировать статьи на наличие ссылок на запрещённые домены.
     *
     * ## OPTIONS
     *
     * [--post_id=<id>]
     * : Сканировать одну статью.
     *
     * [--batch=<size>]
     * : Размер батча.
     * ---
     * default: 500
     * ---
     */
    public function cmd_banned_links_scan($args, $assoc_args) {
        $result = lem()->link_scanner->batch_scan([
            'batch'   => (int) ($assoc_args['batch'] ?? 500),
            'post_id' => $assoc_args['post_id'] ?? null,
            'log'     => function ($msg) { WP_CLI::log($msg); },
        ]);

        WP_CLI::log("Статей с запрещёнными ссылками: {$result['posts_with_links']}");
        WP_CLI::log("Всего запрещённых ссылок: {$result['total_links']}");
        WP_CLI::success('Сканирование ссылок завершено.');
    }

    /**
     * Удалить запрещённые ссылки из статей.
     *
     * ## OPTIONS
     *
     * [--post_id=<id>]
     * : Очистить одну статью.
     *
     * [--batch=<size>]
     * : Размер батча.
     * ---
     * default: 100
     * ---
     *
     * [--dry-run]
     * : Показать результаты без применения изменений.
     */
    public function cmd_banned_links_clean($args, $assoc_args) {
        $result = lem()->link_scanner->batch_remove([
            'batch'   => (int) ($assoc_args['batch'] ?? 100),
            'post_id' => $assoc_args['post_id'] ?? null,
            'dry_run' => isset($assoc_args['dry-run']),
            'log'     => function ($msg) { WP_CLI::log($msg); },
        ]);

        if (isset($assoc_args['dry-run'])) {
            WP_CLI::log("Будет очищено статей: " . ($result['would_clean'] ?? 0));
            WP_CLI::success('Пробный запуск завершён.');
        } else {
            WP_CLI::log("Очищено статей: {$result['cleaned']}");
            WP_CLI::success('Удаление ссылок завершено.');
        }
    }
}
