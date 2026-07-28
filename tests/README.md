# Проверки

Запуск без установки PHP локально, из корня плагина:

    for f in tests/*.php; do docker run --rm -v "$PWD":/app php:8.3-cli-alpine php /app/$f; done

Или по одной:

    docker run --rm -v "$PWD":/app php:8.3-cli-alpine php /app/tests/test_morph.php

- `test_morph.php` - склонение фамилий и имён, определение рода
- `test_match.php` - поиск сущностей в тексте, режимы одиночной фамилии
- `test_context.php` - правило «только в цитатах и ссылках»
- `test_quoted.php` - алиасы, которые ищутся только в кавычках
- `test_separator.php` - разделители между словами названия
- `test_generic.php` - защита от общеупотребительных оборотов
- `test_asterisk.php` - звёздочки, поставленные редактором вручную
- `test_import.php` - разбор записей реестра: хвосты названий, алиасы, домены
