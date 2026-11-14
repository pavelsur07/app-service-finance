# Этап 8 — интеграция ночного контроля

**Риск:** HIGH-LOCAL. **База:** 38981c1ae5c756c28627a079e16914fc07d82bf9.
ТЗ/приёмка: stage-8-spec.md до кода. Статус: реализация и module checks готовы,
полные handoff gates и финальные независимые ревью выполняются.

## Что сделано

- AiServiceClient дополнен совместимыми typed методами ai-eval-run/v1; старый
  interface и пять аргументов конструктора сохранены. Строгий parser проверяет
  конверт и проецирует только технические поля; report raw input/output исключён.
- Две глобальные технические сущности/таблицы, UUID/trace фиксируются до POST.
  Единственная дневная запись, canonical remote ID, короткий request/pull/health.
  Доступ компаний и справочники не используются. Confirm/Cash не изменяются.
- Pull revision до HTTP защищает от старых ответов/ошибок; remote_updated_at
  не даёт вернуть предыдущий статус. PASSED требует завершения, attempt>=1,
  положительных вызовов, полного model coverage и отсутствия safe failures.
- Health различает качество, provider outage, отсутствие/устаревание, новую
  версию, incomplete и exhausted budget. Инциденты дедуплицируются в БД;
  ERROR содержит до 100 safe case/metric/count entries, budget skip — WARNING,
  recovery — INFO. Нет сырых сообщений, сумм платежей, ПДн и ответов модели.
- Отдельный eval-worker одного immutable image и env, defaultoff, restart:on-failure,
  grace960s и 256M. Короткие cron request/pull каждые5мин, окно после03:25МСК;
  health ежечасно :45, окно приёмки после04:00 и свежесть pull≤20мин.
- Deploy экспортирует валидируемый не-секретный flag, применяет существующий
  ai-service migrate до старта worker и останавливает отдельный worker при off.
  Существующий ключ, общий бюджет30/900, никаких новых secrets/dependencies.

## Файлы

ServiceGateway: Action, три commands, AiEval DTO/parser/client, две Entity и
unit/integration tests + synthetic wire fixtures; миграция Version20261114220000.
Compose dev/prod, cron, deploy workflow, nightly CLI timeout cap60s и config tests;
site/.env defaultfalse. ARCHITECTURE, README, runbook и чек-листы обновлены.

## Проверки

Baseline client8/32 и health6/13 PASS; runtime config baseline25 PASS.
Parser/client RED→GREEN, reviewed dataset grammar RED→GREEN,
terminal evidence и safe failures RED→GREEN. Финальный client каталог136tests/
657assertions PASS, focused CS/Stan PASS. Витрина/commands сначала RED class
missing, затем identity/old-fingerprint races RED→GREEN и budget warning RED→GREEN.
Финальный ServiceGateway + schema regex: **254tests/1140assertions PASS**.
Прямой Action до финальной диагностики23/76 PASS; safe diagnostics покрыты общим
последним module run. Первые два focusedStan ошибки только в test callback
inference; после ArrayObject/null-coalesce финальный focusedStan8files Noerrors.

Миграция локально up/down/up PASS; schema gate PASS, console dump показывает
только прежнюю fixture test_money_holder, без ai_quality drift.
Runtime wiring: RED14FAIL→GREEN42PASS; Compose dev/prod config, shell deploy
инварианты и collector unittest PASS. Общий Python module: **571PASS/1skip**,
evals **9PASS/1skip**, Ruff/format115 и mypy63 PASS. Четыре Symfony wire fixtures
валидированы опубликованной JSON schema ai-eval-run/v1.

Handoff style: make site-cs-check и site-cs-strict-types PASS,0/4168.
Полный make site-stan: cache warmup PASS; analysis прерван на1540/4132 из-за
давления памяти хоста (~5.5/5.9GiB и2GiBswap full), exit137 после остановки
собственного контейнера. Это НЕ зелёный анализ и не доказанный source regression.
Такое ограничение уже было в baseline. Baseline не расширялся. Точечные проверки
всех новых PHP файлов зелёные; эквивалентный ограниченный запуск/CI ещё впереди.
make site-test-unit и make site-test: RUNNING, итог пока не принят.

## Внутреннее ревью

Полный Stage diff перечитан root: company isolation/no business writes, DBAL
транзакции без HTTP, idempotency/CAS, safe projection, defaultoff и миграции.
Подтверждённые IMPORTANT: reviewed dataset grammar, потеря actionable metrics,
terminal evidence guard, обработка changed remote identity и stale fingerprint
race — исправлены и проверены. Budget warning — локальный MINOR, исправлен.
Открытых BLOCKER/IMPORTANT в self-review нет. Финальный свежий whole-task review
ещё не запускался; self-review его не заменяет.

## Внешнее ревью

Обязательно (Large handoff, Stage HIGH-LOCAL>500). Pending после полного
внутреннего handoff review. Этот последний Stage и handoff используют одну точку
внешнего ревью полного очищенного task diff; повторять одинаковое ревью не нужно.
Предыдущий Stage7: Claude medium round1 B0/I1/M4, fixed without re-run.

## Ограничения выпуска

Production не изменён. AI_SERVICE_EVAL_ENABLED=false и Telegram business flag0.
Ночной реальный canary не запускался; synthetic/regression не независимый holdout.
Статус доставки GlitchTip heartbeat на проде проверяется после согласованного
выпуска; если heartbeat отсутствует, о полной защите от немоты scheduler не заявлять.
Документация описывает оценочные тарифы, не гарантию provider invoice при смене
курса/прайса. Повышение30/900 и новые платные ручные прогоны не разрешены.

Дальше: закончить handoff gates, свежий internal whole-task review, внешний
read-only review, исправления/отчёт/Ready PR и единственный согласованный запрос
merge + production migrations + activation nightly flag/worker/scheduler + deploy.
