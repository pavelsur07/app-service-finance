### Stage 2: полный снимок и отказоустойчивость — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `06ef7968e61216f4b5898ce382e884bfbc737b1a`
**Work items:** 2.1, 2.2, 2.3

#### What was done

- Добавлены последовательные проходы активных и архивных складов с отдельным
  `store` run/cursor и tenant-scoped upsert.
- Добавлен полный сырой `/report/stock/bystore` с точными decimal, проверкой
  стабильного `meta.size`, уникальности assortment и покрытия active-складов.
- Страницы пишутся отдельными транзакциями при HTTP вне транзакции; snapshot,
  stock run и cursor публикуются одной финальной транзакцией.
- Advisory lock защищает весь flow; зависшие runs/building snapshots получают
  terminal status перед новым запуском.
- Добавлены Message/Handler на `async_sync` с bounded retry для
  `rate_limited`/`temporary` и безопасным журналом.

#### Files changed

- `site/src/MoySklad/Application/` — action и два runner.
- `site/src/MoySklad/Infrastructure/Api/MoySkladClient.php` — Store и Stock API.
- `site/src/MoySklad/Message/`, `MessageHandler/` — async message и retry.
- `site/config/packages/messenger.yaml` — маршрут `async_sync`.
- `site/tests/Unit/MoySklad/`, `site/tests/Integration/MoySklad/` — HTTP,
  orchestration, recovery, tenant, pagination и Messenger coverage.
- `ARCHITECTURE.md` — внутренний sync contract.

#### Definition of Done

- [x] Active и archived store проходят все страницы, дубликаты не продвигают cursor.
- [x] Snapshot публикуется только после полного стабильного отчёта.
- [x] Unknown references, missing active-store coverage и source drift временные.
- [x] Нулевые/отрицательные decimal сохраняются без изменения.
- [x] Store success сохраняется при последующей ошибке stock.
- [x] Retry ограничен attempts 0..3 и только временными категориями.
- [x] Токены, response bodies и названия объектов не журналируются.
- [x] Новых миграций, endpoint и UI нет.

#### Checks

- baseline MoySklad unit — 143 tests, 499 assertions, PASS.
- baseline MoySklad integration — 60 tests, 302 assertions, PASS.
- final MoySklad unit+integration — 246 tests, 1003 assertions, PASS.
- `make site-stan` — 2686 files, no errors.
- `make site-cs-check` — 2701 files, PASS.
- `make site-cs-strict-types` — 2701 files, PASS.
- `make site-test-unit` — 3023 tests, 16503 assertions, PASS;
  4 existing deprecations.
- `make site-test` — 5221 tests, 29540 assertions, PASS;
  6 existing deprecations.

#### Internal review

- implementation self-review: 0 BLOCKER, 1 IMPORTANT fixed (unexpected
  Throwable is normalized to permanent `internal`).
- fresh-context review: 0 BLOCKER, 2 IMPORTANT and 1 MINOR found and fixed.
- fixes: store duplicate/completeness guard, exact active-store coverage and a
  real successful retry after a failed second stock page.
- open BLOCKER/IMPORTANT: none.

#### External review

- required: yes (Large handoff and HIGH-LOCAL Messenger/integration flow).
- rounds: 1; 0 BLOCKER, 2 IMPORTANT, 3 MINOR.
- result: fixed without re-run; both IMPORTANT and two MINOR fixed and verified
  by targeted, module and full gates.
- rejected MINOR: cross-page assortment key remains external ID without type,
  because the task explicitly prohibits a repeated assortment ID.

#### Risks / reviewer focus

- Snapshot is source-consistent only to the documented pagination guards; the
  remote API exposes no single report timestamp.
- A lost DB session can prevent failure-status persistence, but the original
  retry category is preserved so a temporary message is not acknowledged.
- Production synchronization remains a separately approved data-processing
  action and is not run by this Stage.

#### Next

- handoff for Stage 2; Stage 3 endpoint/UI remains separate work.
