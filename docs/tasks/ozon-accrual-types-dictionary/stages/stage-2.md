# Stage 2: статья из справочника

## 1. Какую задачу решаем

Тип, которого нет ни в маппингах БД, ни в каталоге `OzonAccrualCategory`, сейчас
получает `ozon_unknown_<type_id>` и название «Неизвестная категория Ozon: <name>».
После Stage 1 у нас есть справочник с `name` и русским `description` — статью для
такого типа можно построить из него без правки кода.

## 2. Что будет результатом

- `OzonAccrualDictionaryCategoryFactory` (`Application/Source/Ozon`):
  - `code` — `ozon_` + snake_case(`name`): границы слов по регистру и цифрам,
    пробелы и дефисы → `_` (`AnalyticsPlus` → `ozon_analytics_plus`,
    `Drop-Off Agent` → `ozon_drop_off_agent`, `B2CContainerPacking` →
    `ozon_b2c_container_packing`);
  - `label` — `description`, пусто → `name`;
  - `group` — «Требует классификации» (группы — Stage 3);
  - `transactionType` — тот же fallback, что у `unknown()` (по блоку by-day).
    Тип транзакции **не меняется** — иначе сменится естественный ключ
    `(companyId, source, externalId, type)` (урок #2440);
  - `known = false`: тип по-прежнему попадает в очередь разбора
    (`ExternalCategory`, health-check `unclassified`), пока его не подтвердят;
  - `sortOrder` 9000, как у неизвестных.
- Звено в `OzonAccrualCategoryTaxonomyResolver::forTypedFee()`: маппинги БД →
  каталог → **справочник** → `unknown()`. Справочник читается один раз на прогон
  (`resetPerPreviewState` сбрасывает кэш); тип с `removed_at` тоже используется —
  по нему размечены прошлые периоды.
- `docs/ingestion/ozon-mapping.md` — новый порядок разбора, правило кода;
  `ARCHITECTURE.md` — Changelog.

Не входит: чтение имени по `type_id` из таблицы вместо сырья
(`StoredOzonAccrualTypeNameResolver` остаётся как есть — меньше риска для разбора),
группы и тип транзакции по группе (Stage 3).

## 3. Что считаем выполненной работой

- Unit-тесты фабрики: генерация кода на граничных именах; label из `description` и
  fallback на `name`; `known=false`; тип транзакции = fallback.
- Тест на фикстуре справочника (126 типов): сгенерированные коды уникальны, не
  совпадают с кодами каталога и укладываются в 100 символов.
- Integration-тест резолвера: тип из каталога и тип с маппингом в БД не меняются
  (регрессия #2717: 29 → `ozon_delivery_to_pickup_partners`); тип только из
  справочника → `ozon_analytics_plus` / label `AnalyticsPlus`; тип вне справочника →
  `ozon_unknown_<id>`; неизвестный тип из справочника всё равно записывается в
  очередь (`ExternalCategory`).
- Гейты: PHPStan, CS, strict_types, Ingestion + Architecture.

## 4. Чек-лист приёмки

- [x] фабрика + unit-тесты
- [x] тест уникальности кодов на фикстуре
- [x] звено в резолвере + integration-тесты (каталог/маппинг не тронуты, справочник,
      вне справочника, запись в очередь)
- [x] тип транзакции не меняется ни в одной ветке
- [x] документация
- [x] гейты, ревью
- [ ] прод (после handoff): dry-run `daily-maintenance` — меняются только строки 126/127
      (и прочих типов вне каталога), 47 известных типов — без изменений

## Итог

**DONE 2026-10-18** (кроме проверки на проде — после handoff).

- `OzonAccrualDictionaryCategoryFactory` + звено «справочник» в
  `OzonAccrualCategoryTaxonomyResolver::forTypedFee()` (маппинги → каталог →
  справочник → `unknown()`); `OzonAccrualCategory::UNKNOWN_GROUP` — public.
- Из ветки справочника всегда `known = false` и запасной тип транзакции. Если
  сгенерированный код занят каталогом — суффикс `_<type_id>` (на фикстуре одно такое
  совпадение: `SaleCommission` → `ozon_sale_commission_69`).
- Тесты: unit фабрики 11 (коды, label, совпадение с каталогом, 126 типов фикстуры:
  уникальность, `known=false`, тип = запасной, ни один код не занят каталогом);
  integration резолвера +7 (справочник, тип транзакции, каталог по имени, маппинг БД
  сильнее, вне справочника, `removed_at`, запись в очередь `type:127`).
  Ingestion + Architecture — 941 OK; PHPStan — No errors; CS, strict_types — 0.
- Внутреннее ревью: BLOCKER 0 / IMPORTANT 3 / MINOR 5. Исправлено: подстановка имени
  из таблицы до маппингов делала тип известным и меняла тип транзакции (ключ) — убрана,
  как и было в плане («Не входит»); совпадение кода с каталогом отдавало статью
  каталога с её типом — заменено суффиксом; добавлены тесты на `type`, маппинг БД,
  `removed_at`; документация и docblock приведены в соответствие. MINOR про ключ
  очереди и запись известного типа в очередь сняты вместе с подстановкой.
- Эффект на проде (по Stage 0): меняются код и название только у 126/127 (по 2 строки,
  `ozon_analytics_premium` / `ozon_analytics_plus`), после ночного `daily-maintenance`.
