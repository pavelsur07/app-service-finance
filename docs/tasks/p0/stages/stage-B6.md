## Stage B6: App\Finance + PnlFacade — verify + document — DONE

**Риск:** 🔴 HIGH (граница модулей / документирование исключения) — 🛑 STOP перед merge
**Следующее действие:** 🛑 STOP перед merge; далее Phase Final (handoff).

### Контекст (решение Владельца: verify + document only)
Разведка показала: модуль `App\Finance` и `PnlFacade` **уже существуют**; write-path
(`NormalizationCompletedSubscriber` → `MarkPnlPeriodDirtyMessage` → `MarkPnlPeriodDirtyHandler`
→ `MarkPnlPeriodDirtyAction`) уже декомпозирован. Спека-премиса «переключить подписчика с
репозитория на Facade» неприменима. Владелец выбрал минимальный объём: verify + document,
без правок write-path.

### Что сделано
- **Verify:** подтверждено, что `PnlFacade::markPeriodDirty(MarkPnlPeriodDirtyCommand)` —
  единственная точка входа для пометки `pnl_dirty_periods` извне `App\Ingestion`, пригодная для
  TASK-FIX-06. Добавлен тонкий integration-тест `PnlFacadeTest` (create → reopen на терминальном
  статусе) — прямое покрытие фасада (Action уже покрыт `MarkPnlPeriodDirtyActionTest`).
- **Document:** `ARCHITECTURE.md`:
  - `PnlFacade::markPeriodDirty` — помечен как единственная точка входа + Variant-B исключение.
  - Добавлена явная запись о **Variant-B компромиссе**: `App\Finance` импортирует
    `App\Ingestion\Entity\PLDirtyPeriod` и `App\Ingestion\Repository\PLDirtyPeriodRepository`
    напрямую; план миграции — перенос Entity+Repo в `App\Finance` (отдельная задача).
  - **Исправлена неточность** в описании идемпотентности `MarkPnlPeriodDirtyAction`: фактически
    `DONE`/`FAILED`/`BLOCKED_BY_CLOSE` → reopen в `PENDING` (старый текст ошибочно утверждал, что
    blocked не трогается). Зафиксировано расхождение со спекой §4.5 как follow-up вне Phase 0.

### Затронутые файлы
- `ARCHITECTURE.md` — modified (PnlFacade + Variant-B + исправление идемпотентности)
- `site/tests/Integration/Finance/Facade/PnlFacadeTest.php` — new

### Self-review
- [x] Scope compliance — только verify + document (write-path не тронут)
- [x] Forbidden actions — none; миграций нет; messenger.yaml/doctrine.yaml не тронуты
- [x] Security — companyId явный в команде/тесте
- [x] CS-Fixer — green на новом тесте
- [x] Tests — integration green: PnlFacadeTest + MarkPnlPeriodDirtyActionTest +
      NormalizationCompletedSubscriberTest 3/3
- [x] PHPStan — N/A (не установлен)
- [x] ARCHITECTURE.md — обновлён (Variant-B + точная идемпотентность)

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite integration --filter 'PnlFacadeTest|MarkPnlPeriodDirtyActionTest'`

### Риски / на что обратить внимание ревьюеру
- Variant-B (Finance → Ingestion repo) — осознанный временный компромисс, зафиксирован в
  `ARCHITECTURE.md`, whitelisted в `EntityBoundaryTest`.
- Расхождение со спекой §4.5 (BLOCKED_BY_CLOSE reopened, а спека хотела «не трогать») — НЕ
  исправлялось (verify-only). Если поведение нужно менять — отдельная задача с обновлением
  `MarkPnlPeriodDirtyActionTest`.

### Открытые вопросы
- Нужно ли реализовывать «не трогать BLOCKED_BY_CLOSE» из §4.5 спеки? (follow-up, не Phase 0)
