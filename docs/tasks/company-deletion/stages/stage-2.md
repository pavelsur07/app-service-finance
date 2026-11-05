# Этап 2 — окончательная очистка данных компании (dry-run)

## Задача

Через 30 дней после запроса данные компании нужно удалить полностью и
безопасно: все таблицы с `company_id`, дочерние строки без `company_id`,
объекты S3, затем строку `companies`. Журнал биллинга остаётся. Новая
таблица с `company_id` не должна молча выпадать из очистки.

Факты схемы (05.11.2026, локальная БД после всех миграций): 126 таблиц с
`company_id`. Дочерние таблицы без `company_id`, ссылающиеся на таблицы
компании через NO ACTION / RESTRICT: `document_operations` (→ `documents`,
`counterparty`, `project_directions`, `pl_categories`,
`financial_responsibility_centers`), `cash_transaction_auto_rule_condition`
(→ `money_account`, `counterparty`), `deal_charges` (→ `deal_charge_types`).
Между таблицами с `company_id`: CASCADE 15, NO ACTION 17, RESTRICT 36,
SET NULL 26.

## Результат

Ветка от `master` (этап 1 влит, #2888), отдельный PR с миграцией — снятие
FK биллинга; релиз по `docs/workflow/release.md`.

1. **Реестр** `Company\Infrastructure\Deletion\CompanyDataRegistry`:
   - `RETAIN` — `billing_payments`, `billing_checkouts` (журнал оплат);
   - `CHILDREN` — дочерние таблицы без `company_id` с SQL выбора по
     родителю;
   - остальные таблицы с `company_id` удаляются; список берётся из
     `information_schema` при каждом запуске.
2. **План удаления** `CompanyPurgePlanner`: топологический порядок по FK
   (NO ACTION / RESTRICT — ребёнок раньше родителя; CASCADE / SET NULL не
   ограничивают); неизвестная таблица без `company_id`, ссылающаяся на
   удаляемую через NO ACTION / RESTRICT, — отказ с ERROR до первого DELETE.
3. **Очистка** `PurgeCompanyDataAction` по одной компании: проверка срока
   (`deletion_requested_at + 30 дней ≤ now`) под `FOR UPDATE`, удаление
   порциями (`ctid … LIMIT 5000`), commit каждой порции — повтор продолжает;
   объекты S3 по префиксу компании; последней — строка `companies`.
   Восстановление после наступления срока запрещено (Action и кнопка).
4. **Команда** `app:company:purge-deleted [--company=] [--execute]` — по
   умолчанию dry-run: счётчики строк по таблицам и объектов S3, ничего не
   меняет. Логи: INFO старт/финиш с `companyId`, ERROR с таблицей при
   сбое.
5. **Миграция**: FK `billing_payments`/`billing_checkouts` → `companies`
   снимается, `company_id` остаётся ссылкой-снимком. Обратима.
6. **Гейт** `CompanyDataRegistryTest`: каждая таблица с `company_id`
   либо удаляется, либо в `RETAIN`; каждая дочерняя ссылка NO ACTION /
   RESTRICT покрыта `CHILDREN`; план строится без цикла.
7. `Admin\Service\UserDeletionService` (ручной список из 18 таблиц) — на
   общий реестр, если укладывается в этап; иначе follow-up в `TASK.md`.

Cron и запуск `--execute` на проде — этап 3, отдельное согласие (§3.3).

## Что считаем выполненным

- Компания после онбординга с данными во всех основных модулях удаляется
  командой `--execute` целиком: 0 строк по `company_id` во всех таблицах,
  дочерние строки удалены, оплаты сохранены, строки `companies` нет.
- Dry-run ничего не меняет и показывает те же счётчики.
- Компания до срока и восстановленная не трогаются; повтор после сбоя
  продолжает.
- Гейт реестра красный на неклассифицированной таблице.

## Чек-лист приёмки

- [ ] гейт реестра и плана (статическая схема)
- [ ] functional: онбординг + данные → `--execute` → ноль строк, оплаты целы
- [ ] dry-run без изменений; до срока — пропуск; восстановление после срока — отказ
- [ ] повтор после сбоя посреди порций
- [ ] объекты S3 компании удалены, чужие целы
- [ ] миграция up/down, `SchemaInSyncWithMigrationsTest`
- [ ] гейты, внутреннее и внешнее ревью
- [ ] `ARCHITECTURE.md`, `TASK.md`
