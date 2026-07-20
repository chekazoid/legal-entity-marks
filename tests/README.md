# Проверки

Запуск без установки PHP локально, из корня плагина:

    docker run --rm -v "$PWD":/app php:8.3-cli-alpine php /app/tests/test_morph.php
    docker run --rm -v "$PWD":/app php:8.3-cli-alpine php /app/tests/test_match.php
    docker run --rm -v "$PWD":/app php:8.3-cli-alpine php /app/tests/test_context.php

- `test_morph.php` - склонение фамилий и имён, определение рода
- `test_match.php` - поиск сущностей в тексте, режимы одиночной фамилии
- `test_context.php` - правило «только в цитатах и ссылках»
