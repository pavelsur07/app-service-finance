# Stage 2 — миграция

- **Риск:** HIGH-LOCAL (необратимое удаление данных)

## Definition of Done

Миграция применяется локально, `doctrine:schema:validate` не показывает
расхождений по удалённой таблице.

## Миграция

`site/migrations/Version20260912120000.php` — `DROP TABLE marketplace_reconciliation_sessions`.
`down()` восстанавливает структуру (копия `up()` из `Version20260410000000`), но
не данные — это указано в докблоке.

## Проверки

- `make site-migrations` → ✅ `Successfully migrated to version: Version20260912120000`.
- `doctrine:schema:validate` → mapping ✅; база «не в синхроне», но это
  **унаследованный** дрейф: 257 операций в `schema:update --dump-sql`, из них
  reconciliation-related только `marketplace_reconciliation_log`
  (мёртвая таблица вне scope, см. FOLLOW-UP). Удалённой
  `marketplace_reconciliation_sessions` в diff нет — дроп консистентен маппингу.
- `make site-test` → ✅ 4452 теста, включая `ModuleWriteGateCoverageTest`
  (порог `marketplace => 50` выдержан) и `ModuleMixedRouteGateTest`.

## Замеры «до» на проде — снять перед прогоном миграции

```sql
SELECT count(*), min(created_at), max(created_at) FROM marketplace_reconciliation_sessions;
SELECT stored_file_path FROM marketplace_reconciliation_sessions ORDER BY created_at;
```

Список путей нужен до дропа: после него связь «сессия → файл» восстановить нечем.

## Файлы в объектном хранилище

Префикс `marketplace/reconciliation/{marketplace}/{period}/{uuid}.xlsx`.
`ObjectStorageInterface` не умеет листать префикс, поэтому удаление — ручная
операция на хранилище (§3.3), а не код. Порядок: замеры → удаление префикса →
сверка числа объектов со счётчиком строк → дроп таблицы.
