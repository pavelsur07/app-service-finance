# Этап 8 — расписание, витрина и контроль ночной проверки

Риск: HIGH-LOCAL. Код начинается после закрытия внешнего ревью этапа 7.
Базовый commit записывается в checkpoint перед первым изменением кода.
Основание: согласованные nightly-quality-plan.md и nightly-implementation-plan.md.
Лимиты: 30 ₽ в сутки и 900 ₽ в месяц; существующий ключ и маршрут моделей.

## Что делаем

1. Дополняем существующий AiServiceClient методами постановки и чтения
   ai-eval-run/v1. Используем его авторизацию, trace и короткие HTTP-таймауты.
   Старый AiServiceClientInterface и бизнес-задачи не меняются. Ответы разбирает
   отдельный строгий AiEvalV1Parser в типизированные DTO.
2. Сохраняем техническую витрину ServiceGateway по suite + московской дате.
   UUID запроса и trace долговечны до первого POST; повтор использует их же и
   ключ payment_intake:YYYY-MM-DD. Сервис может вернуть исходный canonical run_id.
   Витрина содержит только статусы, время, fingerprint и разрешённую сводку;
   сообщения пользователей, ответы модели, справочники и операции Cash запрещены.
   Это глобальная техническая таблица без company_id, не запрос данных компании.
3. Короткие команды request, pull и health работают независимо от компаний и
   их AI grants. Общие флаги AI_SERVICE_ENABLED и AI_SERVICE_EVAL_ENABLED;
   при выключении команд нет платных запросов. Ни один cron не выполняет LLM.
4. Отдельный eval-worker использует существующий образ и сетевой контур.
   API и business-worker остаются отдельными процессами. Остановка worker даёт
   завершить текущий bounded run; unknown reservations не освобождаются.
5. Выпуск идёт через существующий pipeline. Doctrine-миграция витрины требует
   ручного production_action=migrations; Python SQL 0002 применяется имеющимся
   ai-service migrate перед запуском процессов в deploy. При финальном запросе
   явно называем обе миграции, новый worker и расписание. Сейчас прод не меняем.

## Расписание и состояния

Request запускается раз в пять минут, после 03:25 МСК ставит один запуск за
текущую дату и прекращает POST после подтверждённого принятия. Повтор после
потери ответа безопасен благодаря долговечным UUID и idempotency key. Платный
failed/incomplete run в тот же день автоматически не перезапускается.
Pull раз в пять минут забирает latest, текущий fingerprint и safe result.
Health один раз в час после :45: до 04:00 ожидает вчерашнюю дату, после 04:00
сегодняшнюю; учитывает свежесть pull не более 20 минут. На первом включении
до первого ночного окна сообщает ожидание, а не выдуманный успешный прогон.

passed допустим только с непустым отчётом, complete=true, положительным числом
реальных вызовов, совпадением fingerprint отчёта/запуска/текущей конфигурации и
полным успешным покрытием всех представленных моделей. queued/running не green;
failed, unavailable, incomplete, skipped_budget, missing/stale и version_changed
различаются. Старый passed после обновления кода не становится новым passed.
Провайдер недоступен или бюджет исчерпан — проверка не выполнена, а не успешна.

Один агрегированный ERROR со стабильным текстом при новом инциденте попадает
в существующий GlitchTip. Состояние дедупликации хранится долговечно; повтор того
же инцидента не шлёт новый ERROR. Восстановление — INFO. Для quality failure
пишем только коды/имена полей и счётчики, без сырых входов/выходов/сумм платежей.
Health не перезапускает платный run, не меняет prompt/gold/модели и флаги бота.

## Интерфейсы

AiServiceClient:
- submitEvalRun(string runId, string runDate, string traceId, string key): AiEvalAccepted;
- fetchEvalRun(string runId): AiEvalRun;
- fetchLatestEvalRun(): AiEvalLatest.

DTO AiEvalAccepted: runId, status, duplicate, configurationFingerprint.
DTO AiEvalRun: runId, suite, runDate, traceId, status, attempt,
configurationFingerprint, createdAt, updatedAt, completedAt, errorCode,
report (nullable разрешённая сводка, а не произвольный сырой ответ).
DTO AiEvalLatest: enabled, configurationFingerprint nullable, run nullable.
Parser принимает wire v1 из services/_contracts/ai-eval-run/v1/schema.json;
неизвестные версии, состояния, неверные типы/UUID/даты/hash — contract_violation.
Статус passed с неполным отчётом не принимается как успех.

## Приёмка (тесты до кода)

- [ ] Client передаёт token/trace/idempotency, корректные endpoint и таймауты.
- [ ] Parser проверяет реальные wire fixtures; поддельный passed и неизвестные
      значения отклоняются; body/token не попадают в логи.
- [ ] Повтор request и конкурентный запуск оставляют одну дневную запись;
      потерянный POST-ответ повторяется с теми же UUID/key, canonical id принимается.
- [ ] Pull идемпотентен, не затирает новый день старым ответом, сохраняет текущий
      fingerprint и время контакта; временная недоступность не создаёт passed.
- [ ] Health покрывает все terminal states, missing/pending/stale/version change,
      MSK boundary и первое включение; не зависит от наличия компаний.
- [ ] Повтор инцидента даёт один ERROR; успешный следующий run даёт recovery INFO.
- [ ] Disabled mode не обращается к модели/сервису; Cash не меняется.
- [ ] Doctrine up/down/up и ORM/schema parity проверены локально.
- [ ] Compose имеет отдельный bounded worker того же образа, без ingress и новых
      секретов, с выключенным по умолчанию флагом и grace period не менее 960 с.
- [ ] Deploy применяет миграции до старта eval-worker; выключение останавливает его.
- [ ] Обновлены runbook, добавление эталонов, stage report и release request.
- [ ] Пройдены module checks и однократные полные handoff gates; независимое
      финальное внутреннее и обязательное внешнее ревью закрыты.

До выпуска нет живого nightly run и нет пятого старого root-SSH benchmark.
Флаг бизнес-пилота Telegram остаётся выключен. Регрессионные эталоны не заменяют
независимый holdout и ручную проверку реальных финансовых сообщений.
