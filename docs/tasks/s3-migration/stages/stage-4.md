## Stage 4 (PR 4): Тип C — telegram-импорт через ObjectStorageInterface — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (PR 5), сначала PR 4 на ревью/мерж Владельцем
**Ветка:** от чистого master (стек расстекан после мержа PR 1–3)

### Что сделано
- `TelegramWebhookController::handleDocument` — запись скачанного из Telegram файла:
  `mkdir + file_put_contents(var/storage/telegram-imports/...)` → `$storage->write($key, $content)`,
  ключ `telegram-imports/{fileHash}{.ext}`.
- Инъекция `ObjectStorageInterface $storage` в конструктор (перед параметрами со
  значениями по умолчанию; строковые параметры биндятся по имени → вставка безопасна).
- `fileHash` в `ImportJob` не меняется; расширение сохраняется в ключе.

### Важное наблюдение
Telegram-импорт сейчас **write-only**: webhook скачивает файл, кладёт в хранилище и
создаёт `ImportJob`, но **читателя/воркера нет** (в коде явный комментарий «В проекте
пока нет очереди/команды для автоматического импорта»). Поэтому PR 4 мигрирует только
запись; будущий импортёр восстановит ключ из `ImportJob.fileHash` + расширения имени
файла (как в cash, PR 3). Межмашинная запись теперь идёт в объектное хранилище.

### Затронутые файлы
- `src/Telegram/Controller/TelegramWebhookController.php` — modified (write + конструктор)
- `tests/Telegram/Functional/TelegramWebhookCashTransactionTest.php` — modified (новый тест + `postDocument`)

### Self-review
- [x] Scope compliance — только запись telegram-документа
- [x] Patterns / naming — ключ по схеме, как в cash
- [x] Forbidden actions — none
- [x] Security — companyId в `ImportJob`; ключ content-addressed (как было); секрет вебхука не логируется
- [x] Tests green — новый `testDocumentUploadIsStoredInObjectStorage` (webhook пишет в хранилище, читается обратно, `ImportJob` создан); весь класс `TelegramWebhookCashTransactionTest` 6/6
- [x] DI — `lint:container` OK (конструктор изменён, bind по имени)
- [x] CS-Fixer — чисто на 2 изменённых файлах (0 of 2)
- [N/A] PHPStan — в проекте не установлен

### Пред-существующее (НЕ в scope PR 4)
Полный `--testsuite telegram` даёт 16 ошибок в `BotLinkServiceTest`/`BotLinkTest`
(`TypeError` в `BotLinkService` — Company вместо string id). Проверено: **падает на
master** с застешенными правками PR 4. Deep-link токены, к хранилищу отношения не имеют.
Отдельный баг, вне scope.

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit --filter TelegramWebhookCashTransactionTest`

### Риски / на что обратить внимание ревьюеру
- Ключи telegram остаются content-addressed (без `companyId`) — сохранение поведения,
  как в cash (PR 3). Follow-up: company-scope при переходе на целевую схему.
- Web-only поток; при появлении воркера-импортёра он должен читать через
  `TemporaryLocalFile` (тип B).

### Открытые вопросы
- нет
