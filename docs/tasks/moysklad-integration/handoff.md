# МойСклад — handoff

Branch: `feat/moysklad-integration` · PR: https://github.com/pavelsur07/app-service-finance/pull/2478 · Base: master.
Implementation commit:335acaca (plus prior7100b57b, ccf6bf9f and upstream mergec1ce6e5c).
CI on335acaca: all checks passed (run34823718485; eslint34823718476). Local full-run reconciliation pending before Ready.

## Результат

Старый интерфейс Tabler: МойСклад → Интеграция. Несколько подключений компании; проверка API до сохранения, понятные статусы, безопасная замена ключа, отключение/повторное включение, удаление отключённого подключения. Токены зашифрованы и не возвращаются в формы/логи/profiler. CSRF, READ/POST WRITE, company scope, лимит повторных проверок и защита от гонок. Отдельная идемпотентная команда переноса прежних секретов. Импорта, синхронизации и фоновых задач в Stage0 нет.

Files: site/src/MoySklad; site/templates/moy_sklad; templates/partials/_sidebar.html.twig; assets/legacy-app.js; config services/rate_limiter; migration; Unit/Integration/Functional/Builders MoySklad; ARCHITECTURE.md; docs/tasks/moysklad-integration.

Legacy URLs create/edit/delete сохранены; добавлены POST check/replace-token/disable/enable и безопасное управление version/CSRF. Публичный JSON/OpenAPI контракт не изменён.

## Проверки

Команды Makefile выполнены через эквивалентные Composer scripts внутри изолированной копии `/tmp/moysklad-task` контейнера site-php-cli: Docker /app не отражает рабочее дерево инструментов. Зависимости скопированы внутри контейнера, без local secrets. Долгие проверки имеют сохранённые exit-code/log.

- Baseline Shared encryption18tests45assertions; Marketplace functional25/100; Twig5templates — PASS.
- Модуль73tests356assertions — PASS до последнего regression-test READ GET/POST.
- `composer stan` (=make site-stan):2620files PASS; focused final MoySklad static PASS.
- `composer cs:check` и `composer cs:strict-types`:PASS после исправления unused import; CI также проверяет финальный код.
- `composer test:unit`:2911tests16192assertions, exit0;4deprecations.
- CI full suite in canonical shards:2911unit/16192assertions,1410integration/6324,214functional/3370,265functional/1484,216functional/1331: total5016tests28701assertions, all jobs PASS. Unit4 and functional shard1 two deprecation notices. Local combined run of the pre-permission-fix snapshot:5015tests,4failures. Two known route failures fixed; two Cash failures caused by temporary source sync omitting a Cyrillic fixture. Sync corrected; local focused reconciliation recorded below. All four pass in final CI; local combined run is not claimed green.
- Frontend `npm run lint` и `npm run build`:PASS после синхронизации upstream и существующего UX Turbo vendor package; build5.34s, legacy JS11.16kB/gzip4.20kB.
- Chromium desktop1440/mobile375: cards/no overflow, modals/Escape, empty token fields, submit spinner/duplicate prevention, no JS errors. Six screenshots `/tmp/moysklad-browser-artifacts`. Browser used rendered authenticated fixture; HTTP mutation behavior covered by functional tests.
- UI Kit scanners remain red: classes8958 vs baseline8964; mapping47 vs baseline47. They include legacy Tabler and outdated React mappings. No UI Kit used per owner instruction; unrelated scanners/components left intact.

## Review

Internal/fresh incremental review: no open BLOCKER/IMPORTANT. External Claude Code round1: exact REVIEW_GREEN, five MINOR fixed without re-run; later CI permissionIMPORTANT fixed and fresh-review accepted. Metrics: internal/CI2BLOCKER/3IMPORTANT found and fixed; external0BLOCKER/0IMPORTANT. Details: review-findings.md.

## Выпуск и ограничения

Migration `Version20260914143000` adds columns/index only; legacy tokens unchanged by schema migration. Down intentionally irreversible to preserve encrypted secrets/account bindings. Before production migration confirm recoverable backup of moysklad_connections. Production migration uses separate workflow dispatch and blocks deploy until schema is current. After approved backfill old code cannot read cleared plaintext: use forward-fix or separately approved recovery.

Backfill requires separately named approval, one company per command, dry-run then execute then reconciliation. New command may need a narrowly allowed production wrapper or owner-run execution; no bypass or permission broadening performed. Full steps/invariants in operations.md.

No production reads/writes or live MoySklad calls performed. Real-account acceptance, production counts/backup/lock duration and encryption backfill remain release work after approval. UI uses mocked API outcomes in tests. Shared mobile sidebar collapse remains unrelated FOLLOW-UP. Owner untracked files untouched.

Workflow deviation: logical Stage scopes developed concurrently and committed as integrated checkpoints; Stage2/3 bases not recorded separately before work. Stage reports disclose this. All scopes are covered by final task review and acceptance.

## Owner decision

Ready only after final test evidence: PR #2478. Requires named approval for production migration+deploy and, separately, existing-secret encryption. No merge/deploy authorized yet.
