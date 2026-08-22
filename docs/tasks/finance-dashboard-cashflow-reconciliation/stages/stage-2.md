## Stage 2: Точные периоды и переход к сверке в обоих UI — DONE

**Риск:** MEDIUM
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** Release Gate; отдельное решение Владельца о Ready/merge и автоматическом production deploy

### Scope Stage

- Stage base commit: `353ed3b1256bf34752f0ef1a6ca2c495d5c1fd01`.
- Work items completed: `2.1`, `2.2`, `2.3`, `2.4`.
- Definition of Done выполнена: точные текущий/предыдущий периоды, три перехода в ДДС, opt-in экран сверки и сохранение scope без изменения обычного отчёта.

### Что сделано

- Оба dashboard UI получают единые серверные подписи текущего и предыдущего 30-дневных интервалов и даты сравнения остатка.
- Диапазон внутри одного года отображается как `dd.mm–dd.mm`; при переходе через границу года год указан у обеих дат.
- «Приход», «Расход» и «Чистый поток» используют один URL сверки с одинаковыми `from`, `to`, `group`, `reconcile`, `activity`, `currency`; у «Остатка» ссылки нет.
- Opt-in ДДС показывает точный период, вид деятельности, валюту и точные `inflow/outflow/net` из server payload.
- Проекты/ЦФО в режиме сверки скрыты, scope сохраняется при submit формы и JSON-экспорте, выход открывает обычный ДДС с теми же датами и группировкой.
- Экран объясняет, почему company-wide сальдо ДДС может отличаться от active-account остатка дашборда.
- Обычный ДДС и публичные endpoints сохраняют прежнее поведение.

### Затронутые файлы

- `site/src/Finance/Controller/HomeController.php`
- `site/templates/home/index.html.twig`
- `site/templates/app/home/index.html.twig`
- `site/templates/report/cashflow.html.twig`
- `site/tests/Functional/Finance/HomeCashflowActivityTest.php`
- `site/tests/Functional/Finance/CashflowJsonExportControllerTest.php`
- task-документация.

### Self-review

- [x] Точные inclusive-даты строятся из метаданных KPI provider, без повторного вычисления бизнес-периода в Twig.
- [x] Один reconciliation query исключает расхождение трёх URL.
- [x] Режим UI определяется валидированным payload marker, а не сырым query-параметром.
- [x] Scope сохраняется формой и экспортом; normal/public режимы проверены отрицательными assertions.
- [x] Несовместимые фильтры не отправляются и не отображаются.
- [x] В diff нет новых зависимостей, CSS/UI Kit, миграций, production-действий, debug-кода или несвязанных файлов.

### External review

- Reviewer: Claude Code 2.1.238, read-only.
- Iterations: 1.
- Result: `REVIEW_GREEN`.
- BLOCKER/IMPORTANT: нет.
- Advisory MINOR: матрица Проект × ЦФО остаётся видимой при скрытых фильтрах. Оставлена осознанно как полезная детализация уже отфильтрованных строк; план запрещает несовместимые фильтры, а не матрицу.
- FOLLOW-UP: детерминированный тест cross-year форматирования; HTML-проверка текстовой ветки `activity=all`; общий activity-label mapper только при появлении дополнительных потребителей.

### Команды для проверки

- Relevant PHPUnit suite: PASS, 39 tests / 963 assertions; 2 pre-existing deprecations.
- Twig lint: PASS, 3 changed templates.
- PHP syntax: PASS, changed PHP files.
- PHP CS Fixer changed-file dry run: PASS.
- `git diff --check`: PASS.
- `npm run check:ui-kit`: pre-existing global failure, 9,085 violations in 234 legacy files; Stage не добавляет app-классов и не меняет UI Kit.

### Риски / ограничения

- Форматирование cross-year покрыто реализацией и инспекцией, но текущий functional-тест использует wall clock и детерминированно проходит эту ветку только в начале января.
- Матрица Project × ЦФО в режиме сверки показывает тот же scoped row set и не меняет финансовые итоги.

### Открытые вопросы

- нет.
