## Stage 2: Ядро MCP — DONE

**Риск:** 🟡 MEDIUM (новый изолированный модуль, существующий код не затронут)
**Следующее действие:** continue autonomously → Stage 3

### Что сделано

- `McpServeCommand` — stdio-транспорт. Читает построчно STDIN, пишет протокол в
  STDOUT через `fwrite`, всё остальное — в STDERR. Опции `--company-id` (обязательная,
  UUID) и `--allow-write` (по умолчанию сервер только на чтение).
- `McpServer` — обработчик одного JSON-RPC сообщения: `initialize`, `ping`,
  `tools/list`, `tools/call`, уведомления без ответа. Протокол отделён от транспорта,
  поэтому тестируется без запуска процесса.
- `McpToolInterface` + `ToolRegistry` — инструменты регистрируются автоматически
  по тегу `app.mcp_tool` (`#[AutoconfigureTag]` + `#[AutowireIterator]`), YAML не нужен.
- `McpToolNotFoundException`.

Инструментов пока нет — `tools/list` возвращает пустой список. Они появятся в Stage 3.

### Решения

- **Своя реализация протокола вместо SDK.** MVP нужны четыре метода; цикл занял
  ~90 строк. Зависимость окупится, когда понадобятся resources/prompts/sampling.
- **Ожидаемый отказ инструмента — не протокольная ошибка.** `\DomainException` и
  `\InvalidArgumentException` возвращаются как `isError: true` с текстом, чтобы модель
  могла исправить аргументы. JSON-RPC error остаётся для «нет такого инструмента»,
  битого JSON и внутренних сбоев.
- **Запись отключена по умолчанию.** Без `--allow-write` пишущие инструменты не видны
  в `tools/list` и отклоняются в `tools/call` — модель не увидит того, чем не может
  воспользоваться.
- **Версия протокола эхом.** Отвечаем версией клиента, если она из поддерживаемых
  (`2025-06-18`, `2025-03-26`, `2024-11-05`), иначе своей по умолчанию.

### Затронутые файлы

- `site/src/Mcp/Application/McpServer.php` — new
- `site/src/Mcp/Application/McpToolInterface.php` — new
- `site/src/Mcp/Application/ToolRegistry.php` — new
- `site/src/Mcp/Exception/McpToolNotFoundException.php` — new
- `site/src/Mcp/Infrastructure/Console/McpServeCommand.php` — new
- `site/tests/Unit/Mcp/Application/McpServerTest.php` — new

Миграций нет. Изменений в существующих файлах нет.

### Self-review

- [x] Scope compliance — только ядро, инструментов нет
- [x] Patterns / naming — `final class`, отдельный Exception модуля
- [x] Forbidden actions — none
- [x] Security — `companyId` из аргумента процесса, недоступен модели для подмены; запись под флагом
- [x] CS-Fixer по новым файлам — чисто
- [x] `--testsuite unit` — 1539 тестов зелёные (10 новых)
- [x] `bin/console lint:container` — OK
- [x] Smoke: `initialize` → `notifications/initialized` → `tools/list` → `ping` через реальный процесс
- [x] ARCHITECTURE.md — N/A, новых Facade/Enum/Entity нет

### External Claude Code review

- N/A — задача выполняется Claude Code напрямую по поручению Владельца, а не Codex.
  Проведён внутренний review полного diff.

### Команды для проверки

```
docker compose run --rm -T site-php-cli php bin/phpunit --testsuite unit --filter McpServer
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | docker compose run --rm -T site-php-cli php bin/console app:mcp:serve \
      --company-id=11111111-1111-1111-1111-111111111111 2>/dev/null
```

### Риски / на что обратить внимание ревьюеру

- STDOUT принадлежит протоколу. Monolog `ConsoleHandler` пишет в error output
  (Symfony подменяет output на stderr сам), поэтому логи поток не ломают, но любой
  будущий `echo`/`dump` в коде инструмента сломает сессию.
- Существование компании не проверяется, только формат UUID: проверка потребовала бы
  зависимости от чужого модуля в обход Facade. С опечаткой в `--company-id` инструменты
  вернут пустые списки. Кандидат на фасадную проверку в Stage 3.
- Ограничения на размер ответа инструмента нет — вводится в Stage 3 вместе с пагинацией.

### Открытые вопросы

- нет
