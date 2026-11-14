# PaymentIntake — handoff

**Ветка:** docs/telegram-payment-intake-examples.
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2985, base master.
**Код финальной приёмки:** c3a38a5e57d15e78160fb6a81ff9afef8075942c.
Полные проверки этой версии: https://github.com/pavelsur07/app-service-finance/actions/runs/43589885600.
Ready выставляется только после успешных обязательных checks.

## Результат

Текст обрабатывает отдельная Python-задача parse_payment_message внутри текущего
ai-service. Модель разбивает сообщение на кандидаты и предлагает ДДС, проект,
ЦФО из переданных справочников. Код проверяет суммы/evidence, валюты, даты,
направления, допустимые ID/пары и приватность. Неизвестные данные остаются
вопросами; допустимая подготовка только убирает неподтверждённые факты.
Сохранение денег — ручной Confirm с повторной проверкой прав и закрытых периодов.
Фото не входят в v1.

Cash принимает sourceKey независимо от канала. Telegram-адаптер сохраняет старые
ключи и actor/company binding. Операции общего модуля имеют import_source
payment_intake. Явные текстовые правки направления/валюты/суммы/даты/аналитики
работают без нового платного разбора. Confirm требует решить судьбу каждой
строки: подтвердить или явно исключить. Повтор не создаёт дубли.

Ночной контроль использует отдельные технические API/очередь/ledger и eval-worker
того же образа. Все модели рабочего маршрута и все datasets делят лимиты **30 ₽ в сутки и 900 ₽ в месяц**. Резерв фиксируется до HTTP, неизвестный расход сохраняется;
прерванный платный run не воспроизводится автоматически. Symfony коротко
ставит задание после 03:25 МСК, получает результат и проверяет версию/свежесть.
PASSED требует полного покрытия. FAILED, UNAVAILABLE, INCOMPLETE и SKIPPED_BUDGET
различаются. В GlitchTip идут дедуплицированные ошибки с безопасными case IDs/
метриками/счётчиками; бюджет — WARNING, восстановление — INFO.

## Этапы

| Этап | Итог |
|---|---|
| 1–2 | Контракт, анонимизированные source cases и Python TaskSpec/verifier |
| 3 | Cash drafts, правки, атомарный идемпотентный Confirm |
| 4 | Telegram binding, карточки, вопросы и восстановление доставки |
| 5 | Локальная интеграция и четыре согласованных live прохода; production pilot acceptance остаётся открытой |
| 6 | Общий source boundary и проверяемый runtime pack, workflow новых примеров |
| 7 | Technical eval API/store, shared budget, worker и строгий отчёт |
| 8 | Symfony client/read model/cron/health, deploy wiring и финальные исправления |

Подробные ТЗ, чек-листы и результаты — stages/stage-N*.md. Каноническая текущая
рабочая копия /tmp/vf-payment-intake-release-dc96632b; исходный checkout владельца
не менялся при синхронизации с master. Его незакоммиченные AGENTS/CLAUDE и
посторонние файлы сохранены.

## Проверки

- Python: 603 PASS / 1 skip; evals: 9 PASS / 1 skip; Ruff/format: 121 файл, mypy: 68 файлов — PASS.
- Последний PHP module:380 тестов / 2067 assertions — PASS (Cash/Telegram/Gateway/schema).
- Регрессии финального ревью доказаны RED→GREEN: неизвестное направление,
  future intent независимо от paid, ручные direction/currency без AI,
  запрет silent partial Confirm, neutral ledger source и explicit refresh.
- Canonical composer cs:check иcs:strict-types:0 из 4240 файлов требуют правок; focused PHPStan — No errors.
- Doctrine up/down/up и schema parity PASS; Symfony wire fixtures прошли
  опубликованную ai-eval-run/v1 JSON schema; Compose/shell/runtime wiring PASS.
- Локальные fullStan/fullsuite и отдельный integration ранее завершались с кодом 137
  при RAM/swap pressure; это НЕ зелёные проверки. Unit: 4773 теста / 26504 assertions прошёл на
  тогдашнем источнике. Полное подтверждение — CI точного runtime SHA выше.
- Исторический полный CI 43585432214 (ab6c3ace5) SUCCESS. CI 43588394992 завершился
  с одним style FAIL: выбранный по ошибке .dist config выравнивал PHPDoc иначе,
  чем действующий .php config. Основной конфиг применён и перепроверен.

## Ревью

Свежий независимый whole-task review: весь очищенный diff, B0/I3. Все 3 IMPORTANT
исправлены и повторно проверены рецензентом; INTERNAL REVIEW_GREEN.

Formal external: Claude medium, один раунд, 16 426 строк, exact REVIEW_GREEN,
B0/I0/M4. Все 4 MINOR адресованы: общий import_source, комментарий caller-owned
транзакции, явный refresh и документированные business-pilot prerequisites.
Регрессии: 2 FAIL → 2 PASS / 12 assertions; повторный внешний раунд не нужен по AGENTS.md §7.2.
Рецензент не получал env, secrets, raw source dataset или production data.

Review metrics последней точки: internal B0/I3 fixed; external B0/I0/M4
addressed, rounds 1. Результаты более ранних этапов хранятся в их stage reports.

## Миграции и выпуск

Собственные Doctrine-миграции:

- Version20261113120001 — Telegram session/delivery/replay metadata.
- Version20261113171344 — Cash drafts/reply receipts.
- Version20261114220000 — global technical quality monitor/read model.

Они аддитивны, не преобразуют существующие финансовые данные. Down удаляет новые
таблицы и историю: это не автоматический production rollback. Применение —
отдельный manual production_action=migrations, затем deploy. SQL ai-service
0002_eval_runs.sql forward-only применяет существующий migrate перед стартом
нового процесса. Никакого startup auto-migrate вне pipeline.

Включение nightly требует AI_SERVICE_EVAL_ENABLED=true в GitHub variable;
используются существующий ключ и маршруты, worker/cron подготовлены. По умолчанию
флаг false. Откат — false и обычный deploy, без удаления данных/резервов.
Production пока не изменён. В release approval явно входят миграции, этот флаг,
отдельный eval-worker, расписание и автоматический deploy после merge.

## Ограничения и follow-ups

- Business flag TELEGRAM_PAYMENT_INTAKE_ENABLED остаётся 0. До пилота нужны
  согласованные охват компаний, production worker async_payment_intake и
  company-scoped расписание ОБЕИХ recovery-команд; подробности runbook.md.
- Live Telegram end-to-end и независимый holdout не приняты. Три последних
  прохода v5 совпали на известных development 41 / synthetic 12, что не доказывает
  точность на любых сообщениях. Проверки последующих verifier fixes — offline;
  source/semantic gold и runtime pack не подгонялись под модель.
- Четыре старых прохода завершены: 375 вызовов / 32,74599378 ₽ estimate в отдельном согласованном
  benchmark budget; нового пятого ручного benchmark не запускали.
- Учёт 30/900 ведётся по фиксированным тарифам проекта; invoice провайдера при
  изменении курса/прайса ими не гарантируется. Лимиты автоматически не растут.
- При выпуске проверить доступность существующего scheduler heartbeat и
  доставку GlitchTip. Без heartbeat внутренний cron не обнаруживает смерть
  всего scheduler; ложного обещания полной защиты нет.
- Новые данные: adding-examples.md. Только scrubbed candidates → human-reviewed
  gold → immutable version → offline validate/export/check → обычный выпуск.
  Ответы модели и правки пользователя сами по себе не становятся gold.

## Решение владельца

После успешных latest-head checks и Ready PR требуется одно согласование:
merge в master, production migrations, включение nightly 30/900 и deploy.
Бизнес-пилот не входит в эту активацию. Шаблон ответа:

merge and deploy #2985; выполнить миграции и включить ночной контроль
