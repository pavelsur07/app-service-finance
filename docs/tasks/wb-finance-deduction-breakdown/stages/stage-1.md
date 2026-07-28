## Stage 1: Расшифровка raw-удержаний по основанию WB — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP на Release Gate, ждать решения Владельца по merge

### Scope Stage

- Stage base commit:
  `baf8a12b7e5570a8f0e0f846293d9a5316ae8b8a`.
- Добавлена агрегированная расшифровка ненулевых raw-полей `deduction` по
  точному `bonusTypeName` / `bonus_type_name`.
- Для пустого основания используется «Без расшифровки WB».
- Агрегат содержит период, число строк, число фактических `reportId` и сумму
  удержания; отрицательное влияние выводится как производное от суммы.
- Статья «Удержания», все финансовые итоги и действующее правило абсолютного
  значения `deduction` не изменены.
- Добавлены связанный UI-блок и идентичный spreadsheet-safe раздел CSV.
- Агрегация хранит только уникальные основания и наборы их `reportId`, без
  сохранения всех raw-строк.
- Транзакции, query, схема БД, WB sync/API, очереди и production не изменялись.

### Files changed

- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/templates/marketplace/wb_finance_report.html.twig`
- `site/tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php`
- `site/tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
- `docs/tasks/wb-finance-raw-report/report.md`
- `docs/tasks/wb-finance-deduction-breakdown/`

### Definition of Done

- [x] Общая сумма статьи и расчётное перечисление не изменены.
- [x] Расшифровка использует только ненулевые `deduction`.
- [x] Поддержаны camelCase и snake_case основания.
- [x] Пустое основание имеет явную fallback-строку.
- [x] Период, `reportId`-фильтр и активная компания применяются до агрегации.
- [x] Сумма групп равна `articles.deduction.accrual_minor`.
- [x] Страница и CSV используют один агрегат.
- [x] CSV защищает внешние основания от spreadsheet formulas.
- [x] Нет новой БД, dependency, транзакционной или sync-логики.

### Checks

- Baseline:
  - host PHP отсутствует — окружение недостаточно;
  - Docker runtime: 13 тестов, 63 assertions — green.
- Final targeted: 15 тестов, 84 assertions — green.
- Marketplace unit + functional: 554 теста, 4446 assertions — green.
- PHP syntax изменённых PHP-файлов — green.
- PHP CS для четырёх изменённых PHP-файлов — green.
- Twig syntax изменённого шаблона — green.
- Symfony container lint — green.
- `git diff --check` — green.
- Scope grep: в изменённом executable scope нет обращений к финансовым
  транзакциям — green.

### Internal review

- Итерация 1: BLOCKER/IMPORTANT отсутствуют.
- Исправлены безопасные MINOR:
  - защищено точное строковое группирование от numeric-key coercion;
  - исправлена HTML-структура `<summary>`;
  - добавлены regression-проверки `reportId` и tenant isolation;
  - тип ключа `reportId` сделан `int|string`;
  - удалено дублируемое поле `impact_minor`;
  - уточнена подпись количества фактических `reportId`;
  - исключён лишний lookup основания для нулевого `deduction`;
  - checkpoint приведён к обязательному формату.
- Финальная итерация: BLOCKER/IMPORTANT/MINOR отсутствуют.

### External Claude Code review

- Read-only review выполнялся с `--safe-mode`, ограниченным набором
  `Read/Glob/Grep/git diff` и без web/MCP/write-инструментов.
- Итерация 1: `REVIEW_GREEN`, четыре MINOR; безопасные замечания исправлены.
- Итерация 2: `REVIEW_GREEN`, два локальных MINOR; исправлены.
- Итерация 3: `REVIEW_GREEN`, BLOCKER/IMPORTANT отсутствуют.

### Follow-up

- Рассмотреть top-N/ограничение длины основания только если реальные данные за
  93 дня покажут чрезмерную кардинальность `bonusTypeName`.
- Фиксировать позиции разделов CSV только при появлении машинных потребителей.
- Положительный raw-знак `deduction` по действующему правилу также считается
  расходом через абсолютное значение; изменение этой семантики требует
  отдельного решения Владельца.

### Production Gate

- Production-check, deploy, миграции, обработка очередей и другие production
  действия не выполнялись и этим Stage не разрешены.

### Delivery

- Executable Stage commit:
  `cd8fb00672e0b4889093fa8271ea912b5ffa4e22`.
- Branch: `agent/wb-finance-deduction-breakdown`.
- Draft PR:
  `https://github.com/pavelsur07/app-service-finance/pull/2253`.
- GitHub CI для executable commit: detect changes, unit tests,
  migrations-empty-db, API types sync и три image builds — green.
- Deploy и production migrations — skipped.
