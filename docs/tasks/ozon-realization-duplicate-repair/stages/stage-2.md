### Stage 2: адресное атомарное восстановление — DONE

**Risk:** HIGH-LOCAL. **Stage base commit:** `61383f407`.
**Work items:** 2.1–2.4.

#### What was done

- Добавлены точная проверка мультимножества raw/normalized и одноразовая команда
  ремонта фиксированной компании/raw/октября. Законные одинаковые строки сохраняются.
- Default dry-run выполняет только SELECT. Execute требует исходный raw hash,
  повторяет проверки под общим lock, восстанавливает реализацию и финальное
  закрытие `sales_returns` одной транзакцией. Повтор — проверяемый no-op.
- До commit сверяются суммы, аналитики, ссылки и канонический регистр через
  новый read-only метод FinanceFacade; после commit инвалидируется кэш.
- Неполные/частичные дубли, финансовая блокировка, чужой raw, потеря аналитик
  или нестандартные операции приводят к отказу/rollback.
- Исправлены замечания review: stale Company после ожидания, проверка ссылок
  при no-op, сохранение работоспособного EM при отказе переоткрытия,
  совместимость header с ISO datetime. Маппинги и формулы не меняются.

#### Files changed

- `site/src/Marketplace/Ozon/Command/RepairOzonRealizationOctoberB57d7682Command.php` — new.
- `site/src/Marketplace/Ozon/Infrastructure/Query/OzonRealizationIntegrityQuery.php`
  и `Application/DTO/OzonRealizationIntegrity.php` — new.
- `site/src/Finance/Facade/FinanceFacade.php`, Ozon Action/Report и Reopen Action — modified.
- Integration/process/header tests и reusable synthetic fixture — new/modified.
- `ARCHITECTURE.md`, production-runbook и task docs — updated.
- `site/phpstan-baseline.neon` — удалены 7 устаревших шаблонов / 8 occurrences;
  новых подавлений нет. Logging unit получил точный array phpDoc.

#### Definition of Done

- [x] Read-only dry-run объясняет текущее состояние и изменение.
- [x] Execute локально восстанавливает полный цикл атомарно, повтор безопасен.
- [x] Проверены реальные параллельные процессы, rollback и cache after-commit.
- [x] 501 raw row проходит batch clear; WB, by-day, costs, проекты/ЦФО сохраняются.
- [x] Production не изменён; команды, backup и approvals описаны в runbook.

#### Checks

- Baseline Stage 1: 2 tests / 8 assertions PASS.
- Marketplace + Finance: 749 tests / 3614 assertions PASS.
- Команда/concurrency: 8 tests / 43 assertions PASS.
- Fresh review regressions: 2 tests / 10 assertions PASS; preservation: 2 / 12 PASS.
- External findings сначала RED, после исправлений: 11 tests / 15 assertions PASS.
- Focused PHPStan (11 changed PHP files): No errors.
- Header unit после финального phpDoc: 8 tests / 8 assertions PASS.
- Полный PHPUnit после runtime fixes: 8532 tests / 47908 assertions PASS.
- Full CS: 0 / 3909 changes; strict-types: 0 / 3909 changes. Full PHPStan — handoff gate.

#### Internal review

Полный diff проверен; итоговая независимая проверка исправлений в свежем контексте:
BLOCKER 0 / IMPORTANT 0 / MINOR 0 remaining. За task найдено и исправлено:
BLOCKER 1 / IMPORTANT 3. Исправлены safe MINOR; открытых FOLLOW-UP по коду нет.

#### External review

Обязательно: HIGH-LOCAL diff >500 lines и handoff Large. Один общий review
очищенного task diff, Claude read-only через штатный script.
Найдено BLOCKER 0 / IMPORTANT 2 / MINOR 1; всё исправлено и проверено.
Результат: **fixed without re-run** по AGENTS.md §7.2; literal REVIEW_GREEN
не получен, повтор требуется только после исправленного BLOCKER.

#### Risks / next

Production данные остаются удвоенными до отдельно одобренного execute.
Не добавлены миграции, зависимости или разрешения wrapper. Next: handoff;
полный PHPStan, документация итогов, push/Ready и единый запрос Владельцу.
