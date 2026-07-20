<?php
defined('ABSPATH') || exit;

/**
 * Склонение русских фамилий и имён по падежам.
 *
 * Нужно, чтобы сканер находил упоминания вида «по словам Иванова»,
 * «интервью с Ивановым», «письмо Иванову», а не только именительный падеж
 * из реестра. Правила покрывают продуктивные типы русских фамилий;
 * заведомо несклоняемые типы (-ко, -ых, -аго, на гласную) отдаются как есть.
 *
 * Род определяется по отчеству, а при его отсутствии по окончанию фамилии.
 * Это важно: женские фамилии на согласную не склоняются
 * («Апахончич Дарья» → «Апахончич» во всех падежах).
 */
class LEM_Morphology {

    /** Шипящие и заднеязычные: после них в окончании «и», а не «ы». */
    const HUSHING = 'гкхжчшщ';

    /**
     * Фамилии, совпадающие с обиходными словами: искать их по одной только
     * фамилии нельзя, будут ложные срабатывания на каждом шагу.
     */
    const RISKY_SURNAMES = [
        'белый', 'белая', 'красный', 'молодой', 'мороз', 'соловей', 'сорока',
        'муха', 'коса', 'рыба', 'весна', 'зима', 'лето', 'дорога', 'город',
        'бык', 'волк', 'заяц', 'лебедь', 'орёл', 'орел', 'сокол', 'петух',
        'король', 'царь', 'князь', 'поп', 'дьяк', 'казак', 'кузнец', 'мельник',
        'пастух', 'столяр', 'бондарь', 'мясник', 'рыбак', 'шевченко',
        'звезда', 'туча', 'буря', 'гроза', 'радуга', 'заря', 'ветер',
        'сила', 'воля', 'память', 'слава', 'вера', 'надежда', 'любовь',
        'мир', 'свет', 'день', 'ночь', 'год', 'час', 'путь', 'дом', 'сад',
    ];

    /* ------------------------------------------------------------------
     * Разбор имени
     * ------------------------------------------------------------------ */

    /**
     * Разбирает «Фамилия Имя Отчество» с отбрасыванием псевдонима в кавычках.
     *
     * @return array{surname:string, first:string, patronymic:string}|null
     */
    public static function split_name($full_name) {
        $clean = preg_replace('/\s*[«"„][^»"“]*[»"“]\s*/u', ' ', (string) $full_name);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean));
        if ($clean === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $clean);
        // Отбрасываем инициалы и мусор
        $parts = array_values(array_filter($parts, function ($p) {
            return mb_strlen($p) >= 2 && preg_match('/^\pL/u', $p);
        }));
        if (empty($parts)) {
            return null;
        }

        return [
            'surname'    => $parts[0],
            'first'      => $parts[1] ?? '',
            'patronymic' => $parts[2] ?? '',
        ];
    }

    /**
     * Род по отчеству, иначе по окончанию фамилии, иначе по имени.
     *
     * @return string 'm'|'f'
     */
    public static function detect_gender($full_name) {
        $p = self::split_name($full_name);
        if (!$p) {
            return 'm';
        }

        if ($p['patronymic'] !== '') {
            $patr = mb_strtolower($p['patronymic']);
            if (preg_match('/(вна|чна|нична)$/u', $patr)) {
                return 'f';
            }
            if (preg_match('/(вич|ьич|ич)$/u', $patr)) {
                return 'm';
            }
        }

        $sur = mb_strtolower($p['surname']);
        if (preg_match('/(ова|ева|ёва|ина|ына|ская|цкая|ая|яя)$/u', $sur)) {
            return 'f';
        }

        $first = mb_strtolower($p['first']);
        if ($first !== '' && preg_match('/[ая]$/u', $first)
            && !preg_match('/(никита|илья|фома|кузьма|савва|лука|данила|гаврила)$/u', $first)) {
            return 'f';
        }

        return 'm';
    }

    /* ------------------------------------------------------------------
     * Склонение
     * ------------------------------------------------------------------ */

    /**
     * Словоформы фамилии во всех падежах (включая именительный).
     *
     * @return string[]
     */
    public static function surname_forms($surname, $gender = 'm') {
        $s = trim((string) $surname);
        if (mb_strlen($s) < 3) {
            return [$s];
        }

        $low   = mb_strtolower($s);
        $forms = [$s];

        // Заведомо несклоняемые типы
        if (preg_match('/(ых|их|ово|аго|яго)$/u', $low)) {
            return $forms;
        }
        // На гласную, кроме -а/-я: Шевченко, Гёте, Неру, Шоу
        if (preg_match('/[оеиуыэюё]$/u', $low)) {
            return $forms;
        }

        if ($gender === 'f') {
            // Иванова, Медведева, Пугачёва, Ильина, Птицына
            if (preg_match('/(ова|ева|ёва|ина|ына)$/u', $low)) {
                $stem = mb_substr($s, 0, -1);
                return self::add($forms, $stem, ['ой', 'у', 'ою']);
            }
            // Достоевская, Троцкая, Толстая
            if (preg_match('/(ская|цкая|ая)$/u', $low)) {
                $stem = mb_substr($s, 0, -2);
                return self::add($forms, $stem, ['ой', 'ую', 'ою']);
            }
            if (preg_match('/яя$/u', $low)) {
                $stem = mb_substr($s, 0, -2);
                return self::add($forms, $stem, ['ей', 'юю']);
            }
            // Сковорода, Кучма - склоняются в обоих родах
            if (preg_match('/[ая]$/u', $low)) {
                return self::decline_a_stem($forms, $s);
            }
            // Женская фамилия на согласную не склоняется: Апахончич, Шмидт
            return $forms;
        }

        // Мужские: Иванов, Медведев, Пугачёв, Ильин, Птицын
        if (preg_match('/(ов|ев|ёв|ин|ын)$/u', $low)) {
            // Творительный -ым у русских, -ом у заимствованных (Чаплином)
            return self::add($forms, $s, ['а', 'у', 'ым', 'ом', 'е']);
        }
        // Достоевский, Троцкий, Синий
        if (preg_match('/(ский|цкий|ий)$/u', $low)) {
            $stem = mb_substr($s, 0, -2);
            return self::add($forms, $stem, ['ого', 'ому', 'им', 'ом']);
        }
        // Толстый
        if (preg_match('/ый$/u', $low)) {
            $stem = mb_substr($s, 0, -2);
            return self::add($forms, $stem, ['ого', 'ому', 'ым', 'ом']);
        }
        // Толстой, Лановой
        if (preg_match('/ой$/u', $low)) {
            $stem = mb_substr($s, 0, -2);
            return self::add($forms, $stem, ['ого', 'ому', 'ым', 'ом']);
        }
        // Гай, Шелестей
        if (preg_match('/й$/u', $low)) {
            $stem = mb_substr($s, 0, -1);
            return self::add($forms, $stem, ['я', 'ю', 'ем', 'е']);
        }
        // Гоголь, Врубель
        if (preg_match('/ь$/u', $low)) {
            $stem = mb_substr($s, 0, -1);
            return self::add($forms, $stem, ['я', 'ю', 'ем', 'е']);
        }
        // Сковорода, Кучма
        if (preg_match('/[ая]$/u', $low)) {
            return self::decline_a_stem($forms, $s);
        }

        // На согласную: Шмидт, Ковальчук, Гурвич
        $last = mb_substr($low, -1);
        $ends = ['а', 'у', 'е'];
        // После шипящих ударное -ом, безударное -ем: допускаем оба
        if (mb_strpos('жчшщц', $last) !== false) {
            $ends[] = 'ем';
            $ends[] = 'ом';
        } else {
            $ends[] = 'ом';
        }
        return self::add($forms, $s, $ends);
    }

    /**
     * Словоформы личного имени.
     *
     * @return string[]
     */
    public static function first_name_forms($name, $gender = 'm') {
        $s = trim((string) $name);
        if (mb_strlen($s) < 2) {
            return [$s];
        }

        $low   = mb_strtolower($s);
        $forms = [$s];

        // Мария, Наталия, Ксения
        if (preg_match('/ия$/u', $low)) {
            $stem = mb_substr($s, 0, -1);
            return self::add($forms, $stem, ['и', 'ю', 'ей', 'ею']);
        }
        // Дарья, Наталья, Илья
        if (preg_match('/ья$/u', $low)) {
            $stem = mb_substr($s, 0, -1);
            return self::add($forms, $stem, ['и', 'е', 'ю', 'ей']);
        }
        // Женя, Аня, Костя
        if (preg_match('/я$/u', $low)) {
            $stem = mb_substr($s, 0, -1);
            return self::add($forms, $stem, ['и', 'е', 'ю', 'ей']);
        }
        // Анна, Ольга, Никита
        if (preg_match('/а$/u', $low)) {
            return self::decline_a_stem($forms, $s);
        }

        if ($gender === 'f') {
            // Любовь, Нинель
            if (preg_match('/ь$/u', $low)) {
                $stem = mb_substr($s, 0, -1);
                return self::add($forms, $stem, ['и', 'ью']);
            }
            return $forms;
        }

        // Андрей, Сергей
        if (preg_match('/й$/u', $low)) {
            $stem = mb_substr($s, 0, -1);
            return self::add($forms, $stem, ['я', 'ю', 'ем', 'е']);
        }
        // Игорь
        if (preg_match('/ь$/u', $low)) {
            $stem = mb_substr($s, 0, -1);
            return self::add($forms, $stem, ['я', 'ю', 'ем', 'е']);
        }
        if (preg_match('/[оиуыэюё]$/u', $low)) {
            return $forms;
        }

        // Иван, Пётр, Александр
        $last = mb_substr($low, -1);
        $ends = ['а', 'у', 'е'];
        $ends[] = (mb_strpos('жчшщц', $last) !== false) ? 'ем' : 'ом';
        return self::add($forms, $s, $ends);
    }

    /**
     * Можно ли искать человека по одной фамилии без имени.
     * Короткие и совпадающие с обиходными словами фамилии отсекаем.
     */
    public static function surname_is_searchable($surname) {
        $s = trim((string) $surname);
        if (mb_strlen($s) < 5) {
            return false;
        }
        return !in_array(mb_strtolower($s), self::RISKY_SURNAMES, true);
    }

    /* ------------------------------------------------------------------
     * Внутреннее
     * ------------------------------------------------------------------ */

    private static function add(array $forms, $stem, array $endings) {
        foreach ($endings as $e) {
            $forms[] = $stem . $e;
        }
        return array_values(array_unique($forms));
    }

    /** Склонение основы на -а/-я: Сковорода, Анна, Ольга. */
    private static function decline_a_stem(array $forms, $word) {
        $stem = mb_substr($word, 0, -1);
        $last = mb_strtolower(mb_substr($stem, -1));
        $gen  = (mb_strpos(self::HUSHING, $last) !== false) ? 'и' : 'ы';
        return self::add($forms, $stem, [$gen, 'е', 'у', 'ой', 'ою']);
    }
}
