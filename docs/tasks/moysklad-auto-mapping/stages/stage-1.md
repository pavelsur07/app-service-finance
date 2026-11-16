# Stage 1 — кандидаты и pure policy

Base: `0658c9fc6a3c4c3519f6e254d6669200b6a38753`. Risk HIGH-LOCAL.

## Результат и файлы
- Pure policy exact article / parent+explicit size, fingerprint, bounded SQL candidates/preview/characteristics.
- DTO/Enum в MoySklad; Marketplace source только через Facade. Shared CatalogTargetDTO::fromRow сохраняет существующую ручную гидратацию.
- Без writes, HTTP, schema или изменений финансовых контекстов.

## Проверки
- Baseline: unit4822/26773; module395/1668; локальная пустая БД319 migrations.
- TDD: policy RED35 missing class → GREEN; query RED6 missing services после исправления fixture-only ошибок.
- Targeted49/171; модуль442/1827 PASS after review fixes (439/1817 before review); focused PHPStan/CS PASS, diff --check PASS.
- Regression Cyrillic search: RED1/3 → GREEN1/9. C используется для identity; default collation только для LOWER поиска.
- Local50k product fixtures: один SQL131.78ms, EXPLAIN ANALYZE151.785ms; транзакция откатилась. Это локальный замер, не production SLA/покрытие.

## Review
- Internal complete Stage diff: BLOCKER0, IMPORTANT1 исправлен (Cyrillic search), open0/0.
- Проверены scope/company isolation, реальные child при variants_count0, archive ambiguity, truncation, нули/NBSP, explicit IDs, bounded rows/query count.
- External Claude:1 round, IMPORTANT1 confirmed/fixed without re-run; MINOR1 fixed; BLOCKER0. Parent own-article hit with no source size cannot hide an unrelated leaf target. Regression RED39/108 (2failures) → GREEN42/131 including Query truncation. Same own-parent+own-variant remains READY. Added real Query→Preview truncation assertion. Reviewer could not execute shell/tests; all commands run by implementer. Internal re-review fixes: open0/0.

## Риски и продолжение
- Поиск по нормализованному article требует полного просмотра локальных targets; на50k замер приемлем, новые индексы вне v1.
- Следующий Stage2: immutable Redis preview, TTL/binding/limits; выпуск в production не выполнялся.
