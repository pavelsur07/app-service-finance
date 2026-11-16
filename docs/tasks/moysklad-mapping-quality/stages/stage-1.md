### Stage 1: хранение и импорт — DONE

Risk: HIGH-LOCAL. Stage base: `7423a1562`. Work items: 1.1, 1.2.

- Nullable barcode arrays в product/variant snapshots/entities; parser сохраняет строки и нули, отклоняет numeric payload. Повторный импорт идемпотентен.
- MarketplaceFacade batch <=100 ID: listingId → list<string>, scoped company и marketplace join.
- Scoped WB settings/default off; аддитивная миграция Version20261116120000, назначения не меняет.
- Подтверждения и диагностика создаются вместе с потребителями в Stages 2/3 (решение в checkpoint).

Проверки: parser RED (2 failures) → GREEN 16 tests/46 assertions; module 414 tests/1616 assertions, 2 timeouts concurrency worker cold boot (не SQL), отдельный повтор соответствующего сценария зелёный. Targeted storage/import: итог в checkpoint. Focused PHPStan: тестовые типы исправлены, итог в checkpoint. Style проверен с явным config.
Internal review: 1 проход, BLOCKER 0 / IMPORTANT 0. Проверены tenant joins, null/empty, отсутствие преобразования barcode, schema FK и additive migration.
External review: не требуется на Stage (<500 строк); обязательное review полного diff на handoff.
Next: Stage 2 автоматически.
