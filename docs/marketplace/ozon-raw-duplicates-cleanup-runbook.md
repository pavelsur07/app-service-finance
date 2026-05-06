# Runbook: продовая очистка дублей Ozon rawDoc

## 1. Цель runbook

Цель этой процедуры — безопасно убрать дубли после rolling reload в пайплайне Ozon:
- дубли в Ozon `rawDoc`;
- дубли в `processed` rows (sales/returns/costs);
- **не затрагивая закрытые строки** (`document_id IS NOT NULL`);
- сохраняя проверяемость и аудит действий на каждом шаге.

Ключевой инвариант: cleanup работает только по заданной компании и диапазону дат, с обязательной преддиагностикой и пост-проверкой.

---

## 2. Перед началом (обязательно)

Перед любыми действиями в production:

1. Остановить cron-задачу синхронизации Ozon:

```bash
php bin/console app:marketplace:ozon-daily-sync
```

> Остановите именно регулярный запуск команды (scheduler/cron), чтобы новые данные не приезжали во время cleanup.

2. Дождаться полного опустошения очередей сообщений:
   - `SyncOzonReportMessage`;
   - `ProcessDayReportMessage`;
   - `ProcessRawDocumentStepMessage`.

3. Сделать backup БД (полный backup перед операцией обязателен).

Без выполнения этих 3 пунктов запуск cleanup запрещён.

---

## 3. Диагностика (audit)

Сначала всегда запускается audit:

```bash
php bin/console app:marketplace:ozon-raw-duplicates-audit --company-id=... --from=YYYY-MM-DD --to=YYYY-MM-DD
```

Для машинного анализа/сохранения артефакта аудита используйте JSON-формат:

```bash
php bin/console app:marketplace:ozon-raw-duplicates-audit --company-id=... --from=... --to=... --format=json
```

На что смотреть в результате audit:

- `exact_raw_duplicates` — точные дубли raw-документов;
- `overlapping_raw_documents` — пересекающиеся raw-документы (по охвату данных/периодов);
- `processed_*_duplicates` — дубли в обработанных таблицах (`sales`, `returns`, `costs`);
- `has_closed_rows` — признак наличия закрытых строк (`document_id IS NOT NULL`) в affected данных.

Если `has_closed_rows=true`, любые ручные удаления закрытых данных запрещены; cleanup должен сохранять закрытые строки неизменными.

---

## 4. Dry-run cleanup (без изменений данных)

Запустить предварительный cleanup без применения изменений:

```bash
php bin/console app:marketplace:ozon-raw-duplicates-cleanup --company-id=... --from=... --to=...
```

Важно:

- без флага `--apply` команда работает только в режиме dry-run;
- данные в БД **не изменяются**;
- необходимо проверить:
  - список `affected days` (что затрагиваются только ожидаемые даты);
  - `warnings` (нет ли рисков/неожиданных условий).

Если dry-run показывает неожиданный охват дат или предупреждения — остановиться и разобрать причину до apply.

---

## 5. Apply cleanup

После успешного dry-run запустить фактическую очистку:

```bash
php bin/console app:marketplace:ozon-raw-duplicates-cleanup --company-id=... --from=... --to=... --apply
```

Если после cleanup требуется пересборка/перепроцессинг affected периода:

```bash
php bin/console app:marketplace:ozon-raw-duplicates-cleanup --company-id=... --from=... --to=... --apply --dispatch-reprocess
```

Рекомендация: `--dispatch-reprocess` использовать только при необходимости пересчёта после удаления дублей и только в пределах целевого диапазона.

---

## 6. Что запрещено

1. **Нельзя** запускать cleanup без backup.
2. **Нельзя** чистить все компании одной командой.
3. **Нельзя** вручную удалять строки, где `document_id IS NOT NULL` (closed rows).
4. **Нельзя** вручную удалять `rawDoc`, если есть ссылки из `sales` / `returns` / `costs`.
5. **Нельзя** накатывать unique index, пока audit показывает active duplicates.

Эти запреты критичны для консистентности ОПиУ и корректного аудита изменений.

---

## 7. Контроль после очистки

После apply обязательно повторно запустить audit на том же диапазоне:

```bash
php bin/console app:marketplace:ozon-raw-duplicates-audit --company-id=... --from=... --to=...
```

Проверить, что:

- `exact_raw_duplicates = 0`;
- `processed_*_duplicates = 0` для open rows;
- closed rows (`document_id IS NOT NULL`) не изменились;
- UI истории синхронизаций после следующего cron-цикла не показывает новых дублей;
- продажи / возвраты / затраты за affected days корректно пересчитаны.

Только после этого cleanup считается завершённым.

### Когда можно накатывать unique index

Unique index можно накатывать только когда:
- audit по целевому диапазону/компании не показывает active duplicates;
- пост-проверки выше пройдены;
- подтверждено, что закрытые строки не затронуты.

---

## 8. Rollback

Если результат cleanup некорректный:

1. Базовый путь — восстановление БД из полного backup.
2. Если используется табличный backup — точечное восстановление таблиц:
   - `marketplace_raw_documents`;
   - `marketplace_sales`;
   - `marketplace_returns`;
   - `marketplace_costs`.

После rollback повторить audit и убедиться, что состояние вернулось к ожидаемому.

---

## Краткая пошаговая процедура (чеклист)

1. Stop cron `app:marketplace:ozon-daily-sync`.
2. Wait queue drain (`SyncOzonReportMessage`, `ProcessDayReportMessage`, `ProcessRawDocumentStepMessage`).
3. Backup DB.
4. Audit (human + JSON).
5. Dry-run cleanup.
6. Apply cleanup (`--apply`, при необходимости `--dispatch-reprocess`).
7. Post-audit и функциональные проверки.
8. При проблемах — rollback.
9. Только после нулевых active duplicates — unique index.
