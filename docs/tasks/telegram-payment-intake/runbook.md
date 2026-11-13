# Payment Intake — проверка и выпуск

## До выпуска

Проверить handoff и отчёты Stage 1–5. Новый сценарий по умолчанию выключен
`TELEGRAM_PAYMENT_INTAKE_ENABLED=0`. Доступ компании к ai-service сам по себе
не является разрешением включить пилот. Сначала отдельный разрешённый live eval
на обезличенном holdout с зафиксированными моделью, промптом, latency и стоимостью.

Переключатель глобальный: при значении 1 новый путь доступен всем компаниям
с действующим существующим AI grant и подходящей Telegram-привязкой.
Отдельного task-specific company allowlist в этой версии нет. До включения
согласовать именно этот охват. Если пилот должен охватить меньшую группу без
изменения доступа остальных компаний к категоризации, сначала нужна отдельная
доработка company gate; существующие общие AI grants для этого не менять молча.

Старая Telegram-привязка без actor_user_id не используется для нового Cash
сценария. Пользователь формирует новую ссылку из своего авторизованного кабинета,
после чего бот сохраняет реального actor. Проверка финансовых прав повторяется
при действиях. Отзыв доступа не обходится старой кнопкой или сохранённой сессией.

## Локальная приёмка

- Python: `make ai-service-lint`, `make ai-service-test`, `make ai-service-evals`.
- PHP handoff gates: `make site-stan`, `make site-cs-check`,
  `make site-cs-strict-types`, `make site-test-unit`, `make site-test`.
- Использовать текущую рабочую копию. Постоянный PHP runner данной задачи:
  `docker exec vf-payment-intake-php php bin/phpunit --filter PaymentIntake`.
  Ранее запущенный общий compose-container может быть привязан к другой копии;
  его результат не доказывает корректность текущих файлов.
- Миграции запускаются только в local/test до одобрения production dispatch.
  Убедиться, что схема совпадает с Doctrine metadata.
- Во время тестов отключены реальные HTTP-вызовы модели и Telegram;
  зелёный stub не означает доказанную точность распознавания.

## Recovery

Cash хранит pending dispatch и необходимость Cash-события в БД.
Очередной запрос без результата допускает повторную постановку через 60 секунд
по server-side last_enqueued_at с теми же ID/key/payload; это не новая попытка
модели. В первом быстром цикле 30 секунд этот порог ещё не наступает, поэтому
восстановление поздней потери отправки требует последующего refresh/recovery. Повтор
постановки использует прежние payload/key/taskId. Явный новый разбор использует
новую attempt/revision, старый результат не меняет новую версию черновика.

Telegram хранит источник сообщения и состояние доставки отдельно от сохранения
денег. Короткие poll-задания ходят по taskId/company, истекают и не спят в
webhook. Быстрый цикл — до 15 ожиданий по 2 секунды (около 30 секунд плюс
доставка карточек); это не непрерывный двухминутный polling. Поздний результат
проверяется кнопкой «Обновить» или разрешённым recovery. При сбое ack результат приходит повторно, consumer идемпотентен.

Команды восстановления (processing, не read-only):

```text
app:cash:recover-payment-intake <company-uuid>
app:telegram:recover-payment-intake <company-uuid>
```

На production эти команды нельзя выполнять без отдельного именованного
одобрения и разрешённого wrapper. Их расписание также требует отдельного
согласования production scheduler; в этой задаче конфигурация прода не меняется.
Не обходить отсутствие allowlist через произвольный docker exec/SSH.

## Наблюдаемость

Использовать существующий AI journal/usage и техническое состояние черновиков;
отдельный публичный endpoint метрик не добавлялся. Сводки не должны выводить
text, items целиком, назначения, суммы платежей или названия аналитики.

| Показатель | Источник |
|---|---|
| queued / failed / needs_clarification / confirmed | Cash draft.status; для failed — error_code без исходного ответа |
| Возраст незавершённого запроса | received_at; последняя постановка — last_enqueued_at |
| Попытки и повторы транспорта | draft.attempt/revision/task_id, Messenger retry/failure и безопасные recovery warnings |
| Задержка модели и стоимость | существующие AI trace/journal/ai_usage, task_type=parse_payment_message |
| Доля черновиков с ручной правкой | item.provenance=user для финансовых/аналитических полей; исключить paid_confirmed/questions и само подтверждение |
| Недоставка карточки | session.delivery_pending, pending_poll, poll_until и lease_until; completed cold history исключать |

Доля черновиков с правкой не является числом всех событий редактирования;
полная история таких событий отдельно не сохраняется. Реальные p95 end-to-end,
стоимость и поведение под нагрузкой измеряются на разрешённом live/pilot прогоне;
offline проверки и раздельный routing не подменяют эти измерения.

## Интерактивная очередь

`async_payment_intake` использует существующую Doctrine-таблицу Messenger,
`queue_name=payment_intake`, auto_setup=false и retry 2 секунды (3 повтора).
Туда идут poll/card-сообщения и только SubmitAiTaskMessage задачи
parse_payment_message (TransportNamesStamp). Echo и категоризация сохраняют
прежний async_sync. Тестовый transport — in-memory.

До включения пилота нужен отдельный управляемый worker:

```text
php bin/console messenger:consume async_payment_intake --time-limit=3600 --memory-limit=256M
```

Команда приведена для review, на production не запускалась. Добавление worker
в production Compose/Supervisor и его запуск требуют отдельного именованного
одобрения; production-конфигурация этой задачей не изменялась. Без него нельзя
включать TELEGRAM_PAYMENT_INTAKE_ENABLED. Новый логический transport сам по себе
не гарантирует измеренную задержку модели: её worker и реальную нагрузку
проверяет live/pilot acceptance.

Локальный worker описан в dev Compose под отдельным профилем:

```text
docker compose --profile payment-intake up -d site-php-cli-worker-payment-intake
```

Профиль не запускается обычными тестовыми командами; интеграционные тесты
используют in-memory transport. Команда здесь не выполнялась, чтобы не
потреблять фоновые задания вне тестовой приёмки.

## Выпуск и пилот

1. Подтвердить зелёные gates и reviews, закрыть критические находки.
2. Проверить миграции `Version20261113120001` (actor/session Telegram) и
   `Version20261113171344` (Cash draft). Новые таблицы/nullable actor поля
   не переписывают старые денежные операции. Down удаляет новые черновики/
   сессии и actor metadata, поэтому на production не применять его как
   автоматический rollback.
3. Использовать отдельный migration dispatch по `docs/workflow/release.md`;
   затем deploy. Обычный merge с pending migrations блокирует автодеплой.
4. Именованное разрешение на включение env-переключателя, production recovery
   schedule и живые тестовые операции получать отдельно от merge/deploy.
5. После разрешённого выпуска выполнить read-only проверки через
   `vf-prod-codex` wrappers: схема, статус сервисов/очередей, возраст pending
   requests и согласованность подтверждения с созданными transaction IDs.
6. Пилот включать только после доказанного live качества и на согласованной
   группе. Наблюдать failed, retries, clarification, latency, стоимость и
   частоту ручных исправлений.

## Откат

Выключить новый сценарий разрешённым изменением переключателя. Сохранить
новые таблицы и уже подтверждённые операции: выключение не должно удалять
денежные записи или повторять их через legacy путь. Дождаться/контролируемо
завершить совместимые сообщения очереди; не удалять их без отдельного одобрения.
Неотправленный Telegram-ответ восстанавливать как доставку, не как новую оплату.

Диагностика содержит ID, stage/status/attempt и классы ошибок, но не исходный
текст, реквизиты, токены или production credentials.
