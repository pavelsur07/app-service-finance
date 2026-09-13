# Замеры «до» на проде — снято 2026-09-13, перед миграцией

Источник: `codex-psql-ro` (read-only wrapper).

## Счётчики

| Метрика | Значение |
|---|---|
| строк в `marketplace_reconciliation_sessions` | 12 |
| первая сессия | 2026-04-10 12:12:41 |
| последняя сессия | 2026-04-11 04:03:39 |

Отчётом не пользовались с 11 апреля 2026 — пять месяцев до удаления.

## Сверка с «Закрытием месяца» — коллизий нет

```sql
SELECT s.stored_file_path
FROM marketplace_reconciliation_sessions s
JOIN marketplace_month_closes mc
  ON mc.settings -> 'costs_reconciliation' ->> 'file_path' = s.stored_file_path;
```

Результат: `0 rows`. Ни один файл сверки не используется «Закрытием месяца»,
все 12 объектов принадлежат только удаляемому отчёту.

## Пути объектов в хранилище (нужны для последующего удаления файлов)

Все под `marketplace/reconciliation/ozon/`, 11 в периоде `2026-01`, 1 в `2026-02`.

```
marketplace/reconciliation/ozon/2026-01/5150c989-8ee4-43e5-92cc-4ef63da4343a.xlsx
marketplace/reconciliation/ozon/2026-01/f1c7aff4-dedc-4422-9bfa-c9beddc6839f.xlsx
marketplace/reconciliation/ozon/2026-01/f03db83b-04b7-4b48-8115-be71cf048caf.xlsx
marketplace/reconciliation/ozon/2026-01/73fb7983-e6e2-4c8f-9317-54ce251f775d.xlsx
marketplace/reconciliation/ozon/2026-01/3efdb154-c267-469f-90f9-fd3eef49639b.xlsx
marketplace/reconciliation/ozon/2026-01/2ae9c389-aeba-4499-b018-0969874310e1.xlsx
marketplace/reconciliation/ozon/2026-01/9cf33dca-9b1a-4dc2-80ad-2926c3eaf5b2.xlsx
marketplace/reconciliation/ozon/2026-01/a0209fba-8f7f-43cb-a24c-5d409360a260.xlsx
marketplace/reconciliation/ozon/2026-01/7366e8fe-8c9d-4e4f-9531-6c6ae77f5959.xlsx
marketplace/reconciliation/ozon/2026-01/998ee518-e13f-479f-986d-9fa1b47e2db0.xlsx
marketplace/reconciliation/ozon/2026-01/ddedd36d-1cdf-420c-b091-fee379280469.xlsx
marketplace/reconciliation/ozon/2026-02/829caac4-26e8-46c1-92bc-3f131fdd2658.xlsx
```

## Статус удаления файлов

**НЕ удалены.** Владелец одобрил «run the migration and deploy #2468»; удаление
объектов в хранилище — отдельное действие §3.3, отдельного одобрения не было.
Список выше сохранён именно поэтому: после дропа таблицы восстановить его нечем.

Окно гонки (`stage-2.md`) практически закрыто: последняя загрузка была в апреле,
новых писателей в отчёт нет. После деплоя контроллер загрузки исчезает совсем.
