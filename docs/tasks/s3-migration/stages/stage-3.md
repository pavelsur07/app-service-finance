## Stage 3 (PR 3): Тип C — cash-импорт через ObjectStorageInterface — DONE

**Риск:** 🔴 HIGH (money-path + воркер + изменение персистируемых данных)
**Следующее действие:** 🛑 STOP, ревью Владельцем перед PR 4
**Ветка:** стек поверх PR 2

### Что сделано (снят межмашинный блокер web↔worker)
- **`TemporaryLocalFile`** — временный файл получает расширение ключа (readers
  OpenSpout/PhpSpreadsheet выбирают формат по расширению пути). Аддитивно.
- **`CashFileImportController`**:
  - запись загрузки: `file_put_contents(var/storage/...)` → `$storage->write($key, $content)`,
    ключ `cash-file-imports/{fileHash}{.ext}`;
  - web-чтения (`mapping`, `preview`): `is_file()`+путь → `$storage->exists($key)` +
    чтение через `TemporaryLocalFile::with()`;
  - `commit`: персистит `stored_ext` в `job.options` (было `[]`), чтобы воркер строил
    точный ключ (без `glob`, который на S3 невозможен).
- **`CashFileImportService`** (воркер):
  - `resolveFilePath()` (путь+`is_file`+`glob`) → `resolveStorageKey()` (кандидаты
    `stored_ext` → расширение из имени файла → без расширения, первый существующий
    через `exists()`);
  - `import()` скачивает файл во временную копию через `TemporaryLocalFile::with()`
    и делегирует в новый `readAndPersist()` — **тело цикла импорта байт-в-байт
    неизменно** (переименование + вынос, без переноса логики);
  - убран `projectDir` из конструктора (был только в удалённом `resolveFilePath`).

### In-flight совместимость
Job'ы, созданные до деплоя (options без `stored_ext`), находятся по кандидату
«расширение из имени файла». `glob` убран осознанно (S3 его не умеет; под драйвером
`local` кандидаты покрывают реальные случаи).

### Затронутые файлы
- `src/Shared/Service/Storage/TemporaryLocalFile.php` — modified (расширение)
- `src/Cash/Controller/Import/CashFileImportController.php` — modified
- `src/Cash/Service/Import/File/CashFileImportService.php` — modified
- `tests/Unit/Shared/Service/Storage/TemporaryLocalFileTest.php` — modified (ext-тест)
- `tests/Unit/Cash/Service/Import/File/CashFileImportServiceTest.php` — modified (конструктор)
- `tests/Integration/Cash/Service/Import/File/CashFileImportWorkerStorageTest.php` — new

### Self-review
- [x] Scope compliance — только cash-импорт (тип C) + необходимая доработка хелпера
- [x] Patterns / naming — `TemporaryLocalFile` переиспользован (не дублирован cleanup-примитив)
- [x] Forbidden actions — none (нет new Service, flush только в существующих местах)
- [x] Security — companyId в путях сохранён; ключи детерминированы; path-traversal прикрыт `storeBytes`
- [x] Money-path — цикл импорта не изменён (byte-identical), только источник файла
- [x] Tests green — worker integration (читает из хранилища → 1 транзакция), TemporaryLocalFile ext, cash unit 48/48
- [x] DI — `lint:container` OK (2 конструктора изменены)
- [x] CS-Fixer — чисто на 3 изменённых файлах (0 of 3)
- [N/A] PHPStan — в проекте не установлен

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite integration --filter CashFileImportWorkerStorageTest`
- `docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter "Cash|Storage"`

### Риски / на что обратить внимание ревьюеру
- **Money-path**: изменён только способ получения файла (хранилище вместо диска);
  логика парсинга/дедупликации/баланса не тронута. Проверить diff `readAndPersist`.
- **Персист `stored_ext` в `job.options`** — новое поведение записи в БД (без миграции
  схемы, поле уже JSON). Одобрено Владельцем.
- **Покрытие**: web-визард (upload→mapping) функциональным тестом не покрыт — крайне
  многошаговый (CSRF + сессия). Крайняя точка (воркер) покрыта интеграционно, web-write
  использует тот же `write()`; unit + lint дополняют. Осознанный gap.

### Открытые вопросы
- нет
