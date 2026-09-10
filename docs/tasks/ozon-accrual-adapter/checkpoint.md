## Current checkpoint

**Phase:** Stage 4b закрыт, дальше Stage 5
**Status:** handoff — ветка Stage 4b готова к ревью
**Stage base commit:** `0504ffff` (Stage 4b)

### Completed
- Stage 4b — классификатор by-day, три процессора, удаление мёртвого клиента.
  Отчёт — `stages/stage-4b.md`.
- Stage 4a — клиент by-day, загрузчик, команда-диспетчер. Отчёт —
  `stages/stage-4a.md`. Обработка вынесена в Stage 4b.
- Stage 3 — `MarketplaceRawFormat`, выбор процессора по формату, громкий отказ
  на незнакомом `api_endpoint`. Отчёт — `stages/stage-3.md`.
- Stage 2 — `OzonAccrualCategoryFacade` + `OzonAccrualCategoryView`, разрешение
  по имени из справочника, неизвестное уходит в видимую очередь. Отчёт —
  `stages/stage-2.md`.
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
- 1.5 — сверка за июнь пройдена: продажи и возвраты сходятся с «Реализацией»
  до копейки, расхождение счётчиков объяснено нулевыми ценами.
- 1.4 — обезличенные фикстуры `accrual_by_day_cases.json` (5 кейсов) и
  `accrual_types.json` (4 использованных типа). Проверено: ни один
  идентификатор фикстуры не встречается в снимке продавца.
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
- Ветка `feat/ozon-accrual-by-day-processors` уходит в merge.
- Stage 5: сверка Marketplace против Ingestion за день, перезалив одного
  кабинета за один день, затем 08.09 и далее по всем; Work item 5.4 —
  вернуть строку `ozon-financial-reports:sync` в `docker/cron/app.cron`.

### Files to inspect first on resume
- `docs/tasks/ozon-accrual-adapter/plan.md` — раздел «Что известно и что нет»
- `site/tests/Fixtures/Marketplace/Ozon/captured/_meta-accrual.json` — что снято
- `site/src/Marketplace/Service/Integration/OzonAdapter.php` — что переписывается
