# Единый проверенный релиз

Источник: утверждённый approved-plan.md, часть 2. Large/HIGH-LOCAL.
Base: da7cef655. Отдельный PR от master; реализация после local acceptance.
Baseline: make test-deploy-workflow test-frontend-release — 6+6 Python и shell GREEN.
Эталоны: deploy.yml rolling heredoc, frontend-release-test.py, backup-production.sh.

## Stage 1: Доверенный комплект и атомарная production-последовательность
Risk: HIGH-LOCAL. stage_base_commit: da7cef655.
DoD: SHA validation; manifest provenance по GitHub run+jobs; полный digest set;
registry ошибки fail closed; trusted reuse пропускает сборки и проверки;
неподтверждённый/неполный комплект требует новых gates/build.
Work items: 1.1 stub regression RED; 1.2 resolver/publisher + workflow.
Checks: Python stub tests, existing deployment/frontend contracts.
Reviewer focus: trust boundary, API failures, coverage of required gates/images.

## Stage 2: Репетиция, финальное review и handoff
Risk: HIGH-LOCAL. stage_base_commit: после Stage 1.
DoD: один job/production-release lock; backup→migrate→schema→deploy→health;
все services по digest override, same-SHA config, ancestry anti-downgrade;
failures stop, same release retry идемпотентен.
Work items: 2.1 production regression RED; 2.2 helper и единственный job;
2.3 PostgreSQL rehearsal, docs, checks/reviews, Ready PR.
Checks: Docker/GHCR/SSH stubs, real temporary PostgreSQL archive read,
worker/SSH/SPA contracts, YAML/expressions validation where available.
Reviewer focus: before-first-mutation high-water, backup privacy/completeness,
stdin, rolling order, no unsolicited rollback/pruning.

## Решения
- Одобрение плана явно разрешает локальное изменение CI/CD. Запуск production
и merge не выполняются до отдельного разрешения на выпуск PR.
- Manifest покрывает 9 application images и pinned digests 3 infrastructure
images, включая aliases workers/scheduler. Одна версия — один полный набор.
- Backup всех non-template прикладных БД текущего PostgreSQL, поскольку deploy
мигрирует также Ozon/WB/AI. Не заявляется backup ролей или проверка восстановления.
- High-water SHA сохраняется до первой миграции; last-success после здоровья.
Сравнение ancestry, same SHA разрешён. Bootstrap SHA из живого FPM.

- Инфраструктурные digests берутся read-only из уже запущенных контейнеров;
  app-only --no-deps исключает их неявный restart/upgrade. Это сохраняет
  существующую инфраструктуру при полном manifest из 12 digest.
- Сохранён существующий image prune 336h; upload tar и успешные previous-config
  удаляются. Backup retention и cleanup неуспешных staging — отдельный follow-up
  по утверждённому плану, без заявления о новом согласии владельца.

## Stage 3: исправление публикации hidden artifacts после первого master-run
Risk: HIGH-LOCAL. stage_base_commit: 13e657577c176f044de43a2719487f073ba98832.
DoD: четыре metadata uploads публикуют явно разрешённые hidden files;
нет расширения path allowlist; отсутствующие файлы по-прежнему блокируют выпуск.
Work items: 3.1 regression RED на всех 4 шагах; 3.2 include-hidden-files;
3.3 regression/related contracts, fresh internal + external review, отдельный PR.
Acceptance: CI → manifest → production → SHA/digest/schema/health/timings.
Owner approval: «PR в Ready; merge и production» распространяется на этот
исправляющий PR того же релиза по AGENTS §3.2.
