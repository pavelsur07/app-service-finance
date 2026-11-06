# Stage4 — сквозная приёмка — DONE

**Risk:** HIGH-LOCAL; release prerequisite HIGH-EXTERNAL по AGENTS.md §3.3.
**Stage base commit:**88d0eb73e (UI); master integration107da28b1. Последние backend/UI fixes выполнены в интеграции и проверены до финального freeze.
**Work items:**4.1–4.3 выполнены;4.4 документы и approved release prerequisite завершены; дальнейшая pipeline разрешена владельцем.

## Результат

Реализация, миграция и тесты в коммитах Stage2d512ea500/Stage3 88d0eb73e. Whole-file XLSX/CSV flow, выбранные/невыбранные updates, новые строки, old/new repeat, stale/deleted, BANK/1C, audit/autocat, source/plan integrity, chunk rollback/resume проверены. [acceptance-results.md](../acceptance-results.md) содержит evidence каждого ID и границы browser/concurrency/benchmark. Последние MINOR UUID/invalid UTF8 и rowlock regression проверены отдельно.

## Checks

- Базовый RED: legacy создаёт2операции вместо1; GREEN новый путь. Дополнительные RED→GREEN: wildcard assignment, multiplicity, actual-date drift, Unicode edges, CP N+1.
- Full handoff до последних fixes: unit4468/full8226/46454 GREEN на изолированной новой тестовой БД306 migrations. Общая старая test БД с двумя schema drift failures не пересоздавалась. Две найденные наши POST write-gate failures исправлены.
- После latestmaster интеграции unit4470tests/25248assertions GREEN, canonical CS/strict3760files GREEN, все CI test suites/builds commit107da28b1 GREEN. CI static отклонил прежнее удаление4baseline suppressions: они восстановлены точно как master; новый clean анализ/CI необходим. Окончательные результаты повторов на frozen code и SHA — в PR checks/описании, runtime /tmp/vf-cash-frozen-final-*.log и /tmp/vf-cash-frozen-clean-stan.log.
- Twig3 GREEN. Browser real app: desktop/mobile/keyboard/empty/error/long text, JS errors0/dialogs0; artifacts сохранены, fixtures/server убраны. Новых assets/npm dependencies нет; npm gates N/A.
- Row lock: отдельно закоммиченный synthetic fixture, два физических соединения, UPDATE заблокирован с55P03; после rollback тот же UPDATE проходит. Исключён false positive от uncommitted INSERT. Targeted1test/3assertions GREEN.
- Consumer-first release patch: git apply --check GREEN, активный CI/CD не изменён.

## Review

Fresh internal:6code BLOCKER/8IMPORTANT fixed; release BLOCKER1 fixed после named approval и consumer-first workflow patch. External Stage2round1:0B/2I fixed without re-run; handoffrounds1–3:0B/1I fixed, last exact REVIEW_GREEN, safe MINOR fixed. Метрики и перечень — [review-findings.md](../review-findings.md). Отсутствующий manual concurrent browser scenario не выдаётся за выполненный; DB-level account/row locks и stale tests реально выполнены.

## Definition of Done

- [x] Функциональность и обязательные локальные сценарии подтверждены.
- [x] Миграция local UP→DOWN→UP; source protection и финансовые инварианты проверены.
- [x] Internal/external code review закрыты; code B/I0.
- [x] Документы и acceptance evidence подготовлены.
- [x] Release BLOCKER снят: владелец разрешил patch, применён и независимо проверен; YAML/b​ash-n41scripts/order PASS.
- [x] Полное разрешение Ready/merge/migration/deploy получено; pipeline исполняется без новых owner gates.

Stage4 завершён: открытых B/I0; full8244/46533,unit4470/25248,PHPStan3724,CS/strict3760,Twig3 GREEN; CI95b457de8 green. Production acceptance публикуется по окончании разрешённой pipeline в PR/финальном отчёте.
