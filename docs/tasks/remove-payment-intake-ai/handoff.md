# Handoff — PR A (код без миграций)

## Что сделано

1. Telegram: удалены доставка карточек, сессии, poll/recovery, транспорт
   `async_payment_intake` и воркер `site-messenger-worker-payment-intake`;
   `PaymentIntakeTelegramAction` → `TelegramBindingAction` (касса и право записи).
2. Cash: из `PaymentIntakeAction`, `CashFacade`, черновика и репозитория удалена
   ИИ-половина; MCP-черновики без изменений смысла. `app:cash:recover-payment-intake`
   без аргументов переотправляет залипшие `CashTransactionsChanged` по всем
   компаниям (cron */5), сбой — один ERROR, exit 1.
3. Ночной eval: `app:ai-service:quality:*`, eval-клиент/парсер/DTO, cron,
   eval-воркеры в compose/deploy, `AI_SERVICE_EVAL_*`.
4. ai-service: задача `parse_payment_message`, наборы и маршруты, eval-worker,
   eval API/store, хук `prepare_output`; контракты `ai-parse-payment-message`,
   `ai-eval-run`. В Symfony — неиспользуемый `AiTaskResultFacade` и одиночный pull.
5. `ARCHITECTURE.md` 2.244, эксплуатационные доки.

Entity удаляемых таблиц и поле `paymentIntakeSelected` остаются до PR B
(миграции идут до выката кода).

## Проверки

- phpunit полный: 9657 тестов OK (до последней правки команды; затем Cash MCP 13 OK).
- PHPStan полный — 0 ошибок, baseline −6; CS и strict_types — 0.
- ai-service: ruff, mypy, pytest (unit+integration 186+18, evals 37).
- `make test-release-pipeline test-deploy-workflow` — OK.
- Внутреннее ревью: замечания закрыты (команда восстановления, хук, план PR B).
- Codex: повтор старого Telegram-апдейта невозможен (webhook отвечал 200);
  неподтверждённых результатов `parse_payment_message` нет — потребитель жив до
  выката, cron импорта подтверждает их каждые 5 минут, в GlitchTip ошибок
  потребителя нет; ответ на `pi:` сохранён по решению #3119.

## Прод до выката

Сообщений Messenger про разбор — 0 (включая `failed`); 7 черновиков, все с
результатом; eval-worker остановлен.

## После деплоя A

- `--remove-orphans` убрал `site-messenger-worker-payment-intake`,
  `ai-service-eval-worker`; cron `app:cash:recover-payment-intake` без ошибок.
- GlitchTip: нет «AI results without a consumer».
- Удалить переменную `vars.AI_SERVICE_EVAL_ENABLED`.
- Затем PR B (миграции) — см. plan.md.
