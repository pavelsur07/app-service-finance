# Checkpoint

- **Ветка:** `chore/remove-marketplace-reconciliation`
- **stage_base_commit:** `260765fc`
- **Состояние:** Stage 1 и Stage 2 закрыты, гейты зелёные. Следующее действие — commit → push → Draft PR → handoff.

## Сделано

| Stage | Статус |
|---|---|
| 1 — код (backend + frontend + навигация + baseline + доки) | ✅ |
| 2 — миграция DROP TABLE | ✅ (применена локально) |

## Не сделано намеренно

- Удаление префикса `marketplace/reconciliation/` в объектном хранилище — §3.3,
  запрашивается у владельца вместе с миграцией.
- Замеры «до» на проде — снимаются перед прогоном миграции, после одобрения.
