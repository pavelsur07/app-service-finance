### Stage 1: перенос кода Wildberries — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `f4550ada`
**Work items:** 1.1, 1.2, 1.3

#### What was done
- 59 классов и 38 тестов перенесены `git mv` в `src/Marketplace/Wildberries/…` и `tests/*/Marketplace/Wildberries/…` (сходство переименований: медиана 96 %, минимум 69 % у мелких файлов, где строка namespace — большая доля).
- Скрипт (scratchpad `move_wb.py`): явная карта FQCN, переписывание namespace, `use` для неявных ссылок на соседей, замена FQCN и путей в src/tests/config/baseline/bootstrap/docs (без docs/tasks, docs/plan); пустые каталоги удалены.
- Вручную: `routes.yaml` (ресурс контроллеров WB), `dirname(__DIR__, 4)` в `WbDeductionOperationTypeMigrationTest`, спецформы baseline (`\\\\` в строковых литералах и `\.php` в путях анонимных классов).
- `ARCHITECTURE.md`: раздел «Marketplace: раскладка по провайдерам».

#### Checks
- phpstan OK, baseline 2410 (число не изменилось); cs, strict-types OK
- unit 2978 / full 5189 — как до переноса, assertions совпадают
- lint:container OK; debug:router идентичен (416); list идентичен; debug:messenger — только FQCN обработчика; порядок калькуляторов тот же

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none
- MINOR: в `marketplace_financial_report_sync_error.error_class` / `…sync_status.last_error_class` старые строки хранят прежний FQCN `WbRawDocumentRefreshConflictException`, новые — с `Wildberries\Exception`; код значение не сравнивает, только показывает — принято, отмечено в handoff
- FOLLOW-UP: два разных теста с именем `WbSalesReportRowNormalizerTest` (разные namespace) — объединить позже

#### External review
- required: yes; rounds: 1; result: REVIEW_GREEN

#### Next
- handoff
