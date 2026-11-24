# Удаление ИИ-разбора платежей (PaymentIntake AI)

## Задача

Владелец 23.11.2026: удалить весь оставшийся код ИИ-разбора платежей после
отключения бота (#3119). Объём — «весь ИИ-разбор»; Telegram-черновики и
сессии на проде удалить с бэкапом. Черновики Cash, которыми пользуется MCP
(`createMcpDraft`, `confirmMcp`, `cancelMcp`, страницы `cash/mcp-drafts`),
остаются рабочими.

## Факт до начала (прод, 23.11)

- `telegram_payment_intake_session` 7; `cash_payment_intake_draft` 7 — все
  из Telegram (`needs_clarification` 6, `cancelled` 1), MCP-черновиков 0,
  проведённых нет; `client_bindings.payment_intake_selected=true` — 1.
- `messenger_messages` с `queue_name='payment_intake'` — 0.
- `vars.AI_SERVICE_EVAL_ENABLED=false`, `ai-service-eval-worker` остановлен.

## Результат

1. Нет кода: Telegram-доставки карточек, сессий, recovery-команды, транспорта
   `async_payment_intake` и воркера; ИИ-половины Cash (постановка задачи,
   приём результата, переотправка, amend/confirm/cancel не-MCP, ответы);
   ночного eval в Symfony (`AiQuality*`) и ai-service (eval-worker, eval API,
   store); задачи `parse_payment_message`, её evals и контрактов.
2. Повторная отправка `CashTransactionsChanged` после confirm MCP-черновика
   сохранена: cron `app:cash:recover-payment-intake` вместо Telegram-команды.
3. Схема: удалены `telegram_payment_intake_session`,
   `cash_payment_intake_reply_receipt`, `ai_quality_monitor`, `ai_quality_run`,
   `client_bindings.payment_intake_selected`, ИИ-колонки черновика; Telegram-
   черновики удалены; в ai-service удалены `ai_eval_*`.

## Поставка — два PR

Release выполняет миграции до выката кода: удаление таблиц вместе с кодом
дало бы окно, где старый cron/воркер читает пропавшую таблицу. Поэтому:

- **PR A (без миграций):** весь код, cron, воркеры, compose, deploy. Entity
  удаляемых таблиц и поле `paymentIntakeSelected` остаются до PR B —
  `SchemaInSyncWithMigrationsTest` требует совпадения маппинга со схемой.
- **PR B (миграции, после деплоя A):** Doctrine-миграция удаления и
  ai-service `0003`; удаление оставшихся Entity/полей. Необратима; release
  делает полный backup.

## Этапы PR A

| Stage | Что | Риск |
|---|---|---|
| 1 | Telegram: доставка, presenter, poll, message+handler, recovery-команда, транспорт и воркер, guard `hasPaymentIntakeSource`, `TelegramPaymentIntakeSource`, кнопки `pi:` и меню черновика; переименование `PaymentIntakeTelegramAction` → `TelegramBindingAction` | HIGH-LOCAL (Messenger, workers) |
| 2 | Cash: ИИ-половина `PaymentIntakeAction`/`CashFacade`/entity/repository, `PaymentIntakeResultConsumer`, `PaymentIntakeCommonFields`, reply receipts; `app:cash:recover-payment-intake` только события; cron; `AiTaskFacade` без отдельного транспорта | HIGH-LOCAL (финансовый путь MCP-confirm) |
| 3 | Ночной eval Symfony: `AiQuality*`, eval-клиент/парсер/DTO, cron, compose, deploy, release-bundle, shell-тесты, env | HIGH-LOCAL (CI/CD) |
| 4 | ai-service: `parse_payment_message`, evals payment_intake, eval-worker/API/store/настройки, routes, stub, контракты | MEDIUM |
| 5 | Документация: `ARCHITECTURE.md`, maintenance-доки | LOW |

## PR B

Doctrine-миграция: удалить Telegram-черновики (`source_key NOT LIKE 'mcp:%'`),
`DROP` сессий, ответов, `ai_quality_*`, колонки `payment_intake_selected` и
ИИ-колонок черновика (`task_id`, `attempt`, `task_revision`, `dispatch_pending`,
`last_enqueued_at`, `error_code`, `payload`, `questions`); `down()` —
`throwIrreversibleMigrationException`. `UserDeletionService` и его тест — без
удалённых таблиц. ai-service `0003`: DROP `ai_eval_*` и удаление строк
`ai_task`/`ai_result`/`ai_trace` с `task_type='parse_payment_message'` (без
потребителя неподтверждённый результат давал бы ERROR импорта каждые 5 минут;
роль `codex_ro` БД `ai_service` не читает, проверено косвенно: все 7 черновиков
результат получили).
Entity `PaymentIntakeSession`, `AiQualityMonitor`, `AiQualityRun`, поле
`ClientBinding::paymentIntakeSelected`, ИИ-поля черновика.

## Чек-лист приёмки

- [ ] Тесты MCP-черновиков зелёные без изменений смысла (`McpCashDraftTest`,
      `McpCashDraftPageTest`, `McpCashToolsTest`, MCP-часть concurrency).
- [ ] Тест: залипшее событие MCP-confirm переотправляет
      `app:cash:recover-payment-intake`.
- [ ] Бот: операции регулярками; кнопки `pi:` старых карточек только отвечают
      «разбор отключён» (решение #3119), черновик и ДДС не меняют.
- [ ] `git grep parse_payment_message|AiQuality|async_payment_intake|eval-worker|AI_SERVICE_EVAL`
      пуст вне docs/tasks и миграций.
- [ ] Гейты: PHPStan (baseline только −), CS, strict_types, полный phpunit,
      ai-service ruff/mypy/pytest, `make test-release-pipeline test-deploy-workflow`.
- [x] После деплоя A (24.11): воркер payment-intake и eval-worker не запущены, cron
      без ошибок, MCP-страница открывается; удалена `vars.AI_SERVICE_EVAL_ENABLED`;
      в GlitchTip нет «AI results without a consumer».
- [ ] После release B: таблиц нет, `cash_payment_intake_draft` = 0 строк
      Telegram, MCP-черновики целы, `schema_migrations` ai-service содержит 0003.
