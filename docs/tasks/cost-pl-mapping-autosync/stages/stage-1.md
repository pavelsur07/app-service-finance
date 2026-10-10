### Stage 1: частичное применение и ночной прогон — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `07073d9a`
**Work items:** 1.1, 1.2

#### What was done
- `ApplyDefaultCostMappingCommand::$partial` (по умолчанию false — UI и ручная
  команда без изменений). В частичном режиме MISSING_PL_CATEGORY /
  INVALID_TARGET_CATEGORY уходят в `blocked`, остальное применяется.
- `app:marketplace:cost-pl-mapping:sync-default`: обход ActiveSellerConnectionsQuery
  (ozon, wildberries), частичное применение, LockableTrait, изоляция сбоя
  компании, один агрегированный `error` + FAILURE.

#### Checks
- phpunit `SyncDefaultCostMappingCommandTest|DefaultCostMapping` — OK, 22 tests
- phpstan по изменённым файлам — No errors; php-cs-fixer dry-run — 0 файлов

#### Review
- Ручные и `include_in_pl=false` правила защищены условиями writer'а и preview — тест.
- Счётчик blocked включает правила шаблона, у категорий которых уже есть ручное
  правило (preview проверяет статью раньше правила) — подпись счётчика уточнена,
  поведение не затронуто.
- Дедупликация пар убрана: uniq_company_marketplace_type.
- Изоляция сбоя одной компании интеграционным тестом не покрыта (нет
  естественного способа уронить Action) — FOLLOW-UP не заводится, код — try/catch.
