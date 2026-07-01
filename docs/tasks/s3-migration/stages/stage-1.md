## Stage 1 (PR 1): Расширить контракт ObjectStorageInterface — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (PR 2), но сначала PR 1 на ревью/мерж Владельцем

### Что сделано
- В `ObjectStorageInterface` добавлены `readStream(): resource` и `delete(): void`.
- Реализованы в `LocalObjectStorage` (через `getAbsolutePath` + `fopen`/`unlink`) и
  `FlysystemS3ObjectStorage` (через `Filesystem::readStream`/`delete`).
- `delete()` идемпотентен: удаление отсутствующего объекта — не ошибка (для cleanup).
- Новый сервис `TemporaryLocalFile::with()` — скачивает объект во временный файл,
  отдаёт путь в callable, **гарантированно удаляет tmp через `finally`** (в т.ч. при
  исключении из парсера — защита от утечки `/tmp`).
- Additive-изменение: новые методы ещё не вызываются, рантайм прод-поведения не меняется.

### Затронутые файлы
- `src/Shared/Service/Storage/ObjectStorageInterface.php` — modified (2 метода)
- `src/Shared/Service/Storage/LocalObjectStorage.php` — modified
- `src/Shared/Service/Storage/FlysystemS3ObjectStorage.php` — modified
- `src/Shared/Service/Storage/TemporaryLocalFile.php` — new
- `tests/Unit/Shared/Service/Storage/LocalObjectStorageTest.php` — modified (readStream, delete ×2)
- `tests/Unit/Shared/Service/Storage/TemporaryLocalFileTest.php` — new

### Self-review
- [x] Scope compliance — только контракт + хелпер, вызовов не трогал
- [x] Patterns / naming — `final readonly class`, `declare(strict_types=1)`, интерфейс-порт чистый
- [x] Forbidden actions — none (нет new Service, нет flush, нет dump/dd)
- [x] Security — path traversal уже прикрыт в `StorageService::storeBytes`; `delete` идемпотентен
- [x] Tests green — 7 tests / 20 assertions OK
- [x] CS-Fixer — чисто (0 из 6 файлов к правке)
- [x] DI — `lint:container` OK, `TemporaryLocalFile` autowired
- [N/A] PHPStan — в проекте не установлен (только php-cs-fixer)
- [x] ARCHITECTURE.md — интерфейс расширен, не новый Facade/Enum; отдельная запись не требуется

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter 'LocalObjectStorageTest|TemporaryLocalFileTest'`
- `docker compose run --rm -T site-php-cli php bin/console lint:container`

### Риски / на что обратить внимание ревьюеру
- `readStream()` без нативного type-hint (PHP не типизирует `resource`) — тип задан phpdoc `@return resource`.
- `TemporaryLocalFile` — критичная точка: проверь `finally`-чистку (тест
  `testTemporaryFileRemovedEvenWhenConsumerThrows` красный, если убрать `@unlink`).

### Открытые вопросы
- нет
