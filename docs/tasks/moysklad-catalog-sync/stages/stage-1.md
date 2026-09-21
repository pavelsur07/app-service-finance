### Stage 1: схема и контракт преобразования — DONE

**Risk:** HIGH-LOCAL

**Stage base commit:** `30b18441ae28681e08325b07cf4cd5bec0f6b447`

**Work items:** 1.1, 1.2, 1.3

#### What was done

- Добавлены tenant-scoped товары и модификации с FK модификации на товар той же компании и подключения.
- Парсер проверяет аккаунт, архив, тип, время, обязательные значения, ссылку родителя и характеристики. Непустая модификация проверяется синтетической фикстурой по документации; тестовый аккаунт её не содержит.
- Зафиксированы поля, которые не импортируются, и обновлена архитектура.

#### Definition of Done

- [x] Сущности и миграция согласованы с контрактом и валидны для Doctrine.
- [x] Парсер отвергает неверные строки; тест RED → GREEN.
- [x] Источник непустой фикстуры отмечен явно.

#### Checks

- baseline: `make site-test-unit` — 2945 tests, 16289 assertions, 4 existing deprecations.
- `php bin/phpunit tests/Unit/MoySklad/Application/CatalogPageParserTest.php` — RED 12 ошибок отсутствующих классов, затем GREEN 12 tests, 32 assertions.
- `php bin/phpunit tests/Unit/MoySklad` — 91 tests, 360 assertions.
- `make site-test-migrations` — latest `Version20260920160000`; `doctrine:schema:validate --skip-sync` — mapping OK.
- focused PHPStan — no errors; focused PHP CS Fixer — 2 файла приведены к стилю.

#### Internal review

- Iteration 1: BLOCKER 0, IMPORTANT 0. Исправлена неточная подпись синтетической фикстуры в README. Связь и уникальные ключи проверены по миграции. После внешнего ревью 3 MINOR исправлены тестами RED→GREEN (15 tests, 38 assertions).

#### External review

- HIGH-LOCAL diff 751 lines: 1 round, `REVIEW_GREEN`. BLOCKER 0, IMPORTANT 0, MINOR 3 подтверждены и исправлены без повторного раунда.

#### Risks / reviewer focus

- У тестового аккаунта отсутствуют модификации, поэтому непустой ответ пока только документированный синтетический образец.

#### Next

- Continue to Stage 2 automatically.
