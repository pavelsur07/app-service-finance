## Current checkpoint

**Phase:** Stage 1 / Work item 1.2
**Status:** stopped — нужен запуск захвата Владельцем
**Stage base commit:** <записать перед 1.3, код ещё не менялся>

### Completed
- Phase 0 — `plan.md`: 4 Stage, границы, открытые вопросы.
- Закрыт вопрос по `marketplace_sale_mappings.operation_type`: чтением PROD
  установлено, что значений всего два, `sale` и `return`. Это внутренний домен,
  не `operation_type` из API Ozon — пользовательские маппинги P&L миграцию
  переживают, правок не требуют.
- 1.1 — `site/bin/capture-ozon-accrual.sh`: снимает `/v1/finance/accrual/types`,
  `/v1/finance/accrual/by-day` (пагинация по `last_id`) и
  `/v1/finance/accrual/postings`, пишет манифест `_meta-accrual.json`.

### Checks and baseline
- baseline: `make site-test-unit` 2356 зелёных, `make site-stan` No errors,
  `make site-cs-check` / `cs-strict-types` 0 из 2497; 4 pre-existing deprecations.
- `bash -n site/bin/capture-ozon-accrual.sh` — чисто.
- Проверено офлайн: отсутствующая и кривая `--date` отвергаются с внятным
  сообщением, неизвестный аргумент отвергается, jq-сборка `posting_numbers`
  даёт корректный JSON.

### Review status
- internal: не начиналось, кода нет
- external: не требуется до появления кода

### Exact next action
- 1.2 — Владелец запускает захват: ключи Ozon лежат зашифрованными в
  `marketplace_connections.api_key_encrypted`, агент их не извлекает (`AGENTS.md` §9).
  После появления снимка — 1.3, разбор трёх открытых вопросов.

### Files to inspect first on resume
- `docs/tasks/ozon-accrual-adapter/plan.md` — раздел «Что известно и что нет»
- `site/tests/Fixtures/Marketplace/Ozon/captured/_meta-accrual.json` — что снято
- `site/src/Marketplace/Service/Integration/OzonAdapter.php` — что переписывается
