# Этап 8 — расписание, витрина и контроль качества — DONE

**Риск:** HIGH-LOCAL. **База:** 38981c1ae5c756c28627a079e16914fc07d82bf9.
ТЗ и чек-лист до кода: stage-8-spec.md. Код приёмки: c3a38a5e57d15e78160fb6a81ff9afef8075942c.
Полный [CI 43589885600](https://github.com/pavelsur07/app-service-finance/actions/runs/43589885600)
этого SHA завершился SUCCESS. Production migration/deploy не выполнялись.

## Результат

- Совместимые typed методы AiServiceClient и строгий AiEvalV1Parser.
  Сохранены старый interface и пять аргументов конструктора.
- Глобальные технические AiQualityMonitor/AiQualityRun и миграция
  Version20261114220000. Долговечный UUID/trace до POST, canonical remote ID,
  дневная уникальность, pull revision до HTTP и защита remote_updated_at.
- Короткие request/pull/health: постановка после 03:25 МСК, запросы каждые пять
  минут, health в :45. До 04:00 ожидается вчерашний run, затем сегодняшний;
  свежесть pull — 20 минут. Старый fingerprint и неполный run не PASS.
- Безопасная сводка до 100 failures: case IDs, имена метрик и счётчики.
  Дедупликация ERROR, budget skip — WARNING, recovery — INFO.
- Отдельный eval-worker того же immutable image, default off, 256M,
  restart:on-failure и grace 960s. Один ключ, общий бюджет 30/900, без ingress.
  Deploy выполняет существующий ai-service migrate до старта процесса.
- Финальные исправления всего PaymentIntake: неподтверждённое направление
  обнуляется с вопросом, явные будущие глаголы сохраняют planned/outflow без
  paid-факта, ручные direction/currency работают без нового AI-запроса,
  Confirm требует все неисключённые строки. import_source стал payment_intake.
  Явный refresh восстанавливает потерянный polling через новое поколение.

Файлы: ServiceGateway DTO/parser/client/Action/Entity/commands и тесты;
миграция, Compose, cron, deploy workflow, bounded nightly timeout и config tests;
Cash/Telegram/Python review fixes; ARCHITECTURE, runbook и operator guide.
Внешний code review не получал raw source data, env или secrets.

## Проверки

Baseline: client 8/32, health 6/13, runtime config 25 PASS. Регрессии перед
исправлениями RED: parser/contract, reviewed dataset key, terminal evidence,
safe failures, old-fingerprint race, partial Confirm, manual direction, unanchored
and future-intent direction, neutral source и lost poll. Финальные GREEN:

- Python 603 PASS / 1 skip; evals 9 PASS / 1 skip; Ruff/format 121 и mypy 68 PASS.
- PHP module 380 тестов / 2067 assertions PASS (Cash/Telegram/Gateway/schema).
- Canonical composer cs:check и cs:strict-types: 0/4240; focused PHPStan — No errors.
- Doctrine up/down/up, schema parity, четыре wire fixtures по JSON schema,
  Compose dev/prod, shell/deploy/config tests — PASS.
- После двух синхронизаций с master сохранены обе AI-задачи и независимые
  nightly/weekly guards: 131 targeted Python + offline smoke и 18 config checks PASS.
- **Полный CI точного c3a38a5e5 — SUCCESS:** static analysis, unit, integration,
  три functional группы, Python, style/strict-types, API types, empty DB, SPA.

Локальные монолитные fullStan/fullsuite и отдельный integration завершались 137
при RAM/swap pressure. Это не GREEN; unit 4773/26504 был отдельной исторической
проверкой. CI 337269f72 выявил единственный styleFAIL: focused CS ошибочно выбрал
.dist config вместо active .php. Основной конфиг применён, локальные полные style
checks и новый полный CI выше зелёные. Baseline не расширялся.

Offline verifier vector short-1 больше не утверждает direction/category без
источника; исходное запрещённое предложение сохранено отдельным negative case.
Source 41, semantic 12 и reviewed runtime pack/gold не изменялись. Никакого
нового пятого live benchmark после согласованных четырёх проходов.

## Ревью

Self-review: архитектура, компания/actor/source, атомарность/идемпотентность,
технический scope бюджета, private logs, default-off и миграции. Обнаруженные
локальные IMPORTANT исправлены до независимого handoff review.

Fresh independent whole-task review (полный очищенный diff, без истории
реализации): B0/I3. Все три исправлены с RED→GREEN; тот же независимый reviewer
проверил дельту и подтвердил INTERNAL REVIEW_GREEN, открытых B/I нет.

External Claude medium, **один раунд**, 16 426 строк, точная строка
**REVIEW_GREEN**, B0/I0/M4. MINOR адресованы: нейтральный import_source,
caller-owned transaction doc, explicit refresh и обязательные business-pilot
worker/recovery prerequisites в runbook/ARCHITECTURE. Две кодовые регрессии:
2 FAIL → 2 PASS / 12 assertions. Повтор не требуется по AGENTS.md §7.2;
финальные небольшие дельты дополнительно проверены self-review и CI.

Review metrics последней точки: internal B0/I3 fixed; external B0/I0/M4
addressed, rounds 1. Открытых BLOCKER/IMPORTANT нет.

## Ограничения и выпуск

Код готов к выпуску, но production не менялся. Nightly flag false, business
Telegram flag 0. В handoff указан единый запрос на merge, три аддитивные
Doctrine-миграции, Python SQL 0002, активацию nightly flag/worker/cron и deploy.
Существующие финансовые данные не преобразуются. Down не автоматический rollback.

Business-пилот отдельно не принят: до него нужны согласованный охват, worker
async_payment_intake и company-scoped расписание обеих recovery-команд.
Независимый holdout и живой Telegram E2E остаются follow-up; известные regression
fixtures не доказывают точность любого сообщения. Учёт стоимости оценочный по
фиксированным тарифам. При выпуске проверить существующий scheduler heartbeat.

Далее: handoff, Ready PR и единственное решение владельца о выпуске.
