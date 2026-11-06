# Обновление описаний файлового импорта ДДС — handoff

**Branch:** `feat/cash-import-description-update` · **PR:** [#2894](https://github.com/pavelsur07/app-service-finance/pull/2894), base master; release prerequisite согласован владельцем и реализован.

## Результат

XLSX/CSV анализируется целиком: create/unchanged/предложения old→new/conflicts/invalid. Обновления требуют явного выбора, пустой текст не очищает. Сервер проверяет immutable план, source bytes, контекст, selected rows и snapshot под блокировкой. Description-only сохраняет финансовые поля/связи; actor audit и прогресс атомарны, пачки/retry идемпотентны. Банк/1С сохраняют подтверждённый текст при повторе. UI показывает фактический итог, scoped права и CSRF сохранены.

## Этапы и файлы

- Stage1: matcher/planner/whole-file scoped scalar snapshots, единый InputRow и storage reference.
- Stage2 (`d512ea500`, base `9f6d94a81`): ApplyCashFileDescriptionsAction, StartCashFileImportJobAction, handler/importer/repository, entity marker,1С overwrite, integration tests и миграция.
- Stage3 (`88d0eb73e`, base `d512ea500`): CashFileImportController, три Twig templates, Functional tests и browser screenshots.
- Stage4 (base88d0eb73e, master integration107da28b1): full gates, fresh internal/Claude external, acceptance evidence; release BLOCKER по порядку worker/web закрыт согласованной правкой CI/CD.

Подробные результаты: [acceptance-results.md](acceptance-results.md), [baseline.md](baseline.md), [review-findings.md](review-findings.md), [stages](stages/). Изменения контракта задокументированы в ARCHITECTURE.md. PHPStan baseline совпадает с актуальным master; новых допущений нет. Новых app dependencies/assets/Vite entries нет. Новые web routes внутреннего Twig flow, внешние API не меняются.

## Проверки

- Baseline32tests/116assertions GREEN; исходный дефект воспроизведён RED (2операции вместо1).
- `make site-test-unit`:4470tests/25248assertions GREEN на интегрированном master.
- `make site-test`:8244tests/46533assertions GREEN,10m38.780s, новая локальная test БД из306 миграций. Две ошибки старой общей схемы диагностированы отдельно; две ошибки нашего write-gate исправлены.
- Full PHPStan3724files GREEN после очистки task-local result cache; baseline совпадает с master. CI95b457de8:19checks success/skipped. Canonical CS/strict-types3760files GREEN. Финальный unit на объединённом master:4470tests/25248assertions GREEN; все CI test suites/builds integration commit107da28b1 GREEN. Итог локального full rerun и окончательный SHA/check status сохраняются в описании PR.
- Twig3 GREEN; actual Chromium desktop/mobile/keyboard/empty/error/long text:0JS errors/0dialogs. npm build/lint N/A: frontend assets не изменялись, inline script проверен browser.
- Benchmark10kexisting+10kinput: preview≈0.526s,handler≈11.637s,UOW4; ограничение измерения peak memory — см. acceptance-results. Это не production SLA.

## Review

Fresh internal code review:6B/8I исправлены; release BLOCKER1 закрыт после разрешения и применения consumer-first patch; независимый review workflow PASS. External Stage2:1раунд,0B/2I fixed without re-run; handoff:3раунда,0B/1I fixed, последний точный `REVIEW_GREEN`, безопасные MINOR исправлены.

Review metrics: internal7B/8I found/fixed; open0/0; external0B/3I found/fixed. Подробности/ограничения — review-findings.md.

## Миграция и release prerequisite

Version20261106120000 — additive boolean DEFAULT FALSE NOT NULL, без финансового backfill; local UP→DOWN→UP GREEN. При code rollback оставить колонку; DOWN удаляет защитные flags и не должен запускаться автоматически.

Подтверждённая проблема: текущий deploy включает новый FPM до смены старого async_sync; старый handler игнорирует description_plan и может создать дубли. Подготовлен минимальный [release-worker-order.patch](release-worker-order.patch), `git apply --check` GREEN: обновить только sync consumer с --wait до FPM. Владелец явно разрешил изменение по AGENTS.md §3.3; patch применён, YAML/b​ash-n41scripts/order assertions GREEN, независимое focused review PASS. [Порядок и решение](release.md).

## Решение владельца и исполнение

Владелец подтвердил: «Разрешаю изменение порядка deploy: async_sync перед PHP-FPM; слить PR #2894, выполнить production-миграцию Version20261106120000 и deploy».

Release prerequisite реализован и проверен. Далее без повторных запросов: green CI →Ready/merge →pending-migration gate →migration dispatch →schema check →deploy dispatch →read-only acceptance. Итоговые merge SHA, workflow runs и acceptance фиксируются в PR и финальном отчёте после завершения; на момент этого commit pipeline выполняется. Production commands вне названной pipeline не разрешены.
