## Current checkpoint

**Phase:** Stage 1 / Work item 1.4
**Status:** implementing — сверка пройдена, осталась обезличенная фикстура
**Stage base commit:** <записать перед 1.3, код ещё не менялся>

### Completed
- Phase 0 — `plan.md`: 4 Stage, границы, открытые вопросы.
- Закрыт вопрос по `marketplace_sale_mappings.operation_type`: чтением PROD
  установлено, что значений всего два, `sale` и `return`. Это внутренний домен,
  не `operation_type` из API Ozon — пользовательские маппинги P&L миграцию
  переживают, правок не требуют.
- План перестроен на 5 Stage по требованию Владельца «старые записи — старым
  обработчиком, новые загрузки — новым»: добавлен Stage 3 (сосуществование
  форматов через `apiEndpoint` → `$kind` реестра), `OzonAdapter` на месте не
  переписывается. Дискриминатор проверен на PROD: 967 легаси-документов Ozon
  несут `ozon::v3/finance/transaction/list`, у WB на одном `document_type` уже
  живут два формата — прецедент есть.
- 1.2 — захват выполнен Владельцем: 342 начисления за 08.09, справочник 124 услуги.
- 1.3 — разбор контракта записан в `plan.md`. Закрыт вопрос 1: возврат — это
  POSTING с отрицательным `sale_amount`, не отдельная категория. Вопрос 3:
  12 использованных `type_id`, все разрешаются справочником, поле в справочнике
  зовётся `id`. Вопрос 2 (количество) НЕ закрыт: `quantity` в выгрузке нет вовсе,
  вывод через `sale_amount / sale_price` проверен и опровергнут — отношение
  нецелое у всех 43 пар.
- Исправлены дефекты скрипта, найденные боевым прогоном: обработка 429 и
  фильтрация `unit_number` для `/postings` (Ozon рушит весь батч из-за одного
  элемента не по формату; у ITEM/NON_ITEM там не номера отправлений).
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
- 1.4 — сокращённая обезличенная фикстура в `tests/Fixtures/Marketplace/Ozon/`
  на четыре кейса: продажа, возврат (POSTING с отрицательным sale_amount),
  POSTING без выручки, ITEM/NON_ITEM. Затем Stage 2.

### Files to inspect first on resume
- `docs/tasks/ozon-accrual-adapter/plan.md` — раздел «Что известно и что нет»
- `site/tests/Fixtures/Marketplace/Ozon/captured/_meta-accrual.json` — что снято
- `site/src/Marketplace/Service/Integration/OzonAdapter.php` — что переписывается
