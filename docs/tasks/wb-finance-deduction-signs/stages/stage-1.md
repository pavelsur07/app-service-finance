# Stage 1: Корректные направления raw-удержаний и выплат WB — DONE

**Риск:** HIGH-LOCAL
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Stage base commit:** `933090be2be22aa802172dee1990a20570e82416`

## Результат

- Положительный raw `deduction` учитывается как удержание: уменьшает
  расчётное перечисление продавцу.
- Отрицательный raw `deduction` учитывается как выплата WB: увеличивает
  расчётное перечисление.
- Текст `bonusTypeName` используется только как точное основание операции и не
  определяет её направление.
- Для одного основания отдельно показываются «Удержано», «Выплачено WB» и
  знаковое «Влияние».
- Статья переименована в «Удержания и выплаты», а показатель расходов уточнён
  как нетто.
- Итоги расшифровки считаются в builder через Money-safe агрегацию и одинаково
  используются HTML и CSV.
- Суммы статьи, расшифровки, строк `reportId`, операций и расчётного
  перечисления согласованы.
- Изменение применяется к уже сохранённым raw-отчётам без миграции и backfill.
- Транзакции, транзакционные проекции, ingestion, БД, очереди и production не
  изменялись.

## Изменённые области

- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/templates/marketplace/wb_finance_report.html.twig`
- unit- и functional-тесты raw-отчёта
- `site/src/Marketplace/WB_API_V5_FIELDS.md`
- `docs/tasks/wb-finance-raw-report/report.md`
- `docs/tasks/wb-finance-deduction-signs/`

## Definition of Done

- [x] `deduction > 0` уменьшает перечисление.
- [x] `deduction < 0` увеличивает перечисление.
- [x] Смешанные знаки одного основания остаются видимыми раздельно.
- [x] Добровольная выплата за пострадавшие товары покрыта regression-тестом.
- [x] HTML и CSV используют одинаковые суммы и явную строку «Итого».
- [x] Агрегаты `reportId` и операций учитывают правильный знак.
- [x] Транзакции и их проекции не изменены.
- [x] Финансовая документация обновлена.

## Проверки

- Baseline targeted: 15 тестов, 84 assertions — green.
- Regression red до исправления: 10 тестов, 2 ожидаемых failures.
- Final targeted: 15 тестов, 116 assertions — green.
- Final Marketplace unit + functional: 554 теста, 4475 assertions — green.
- Final full unit suite: 1640 тестов, 9528 assertions — green.
- Changed-file PHP CS — green.
- Все 218 Twig-файлов — green; финальный изменённый Twig — green.
- Symfony container lint — green.
- `git diff --check` — green.
- Глобальный `make site-cs-check` остаётся pre-existing red на 582
  несвязанных файлах; четыре task-owned PHP-файла проверены отдельно и clean.

## Reviews

- Internal review: финальная итерация без BLOCKER, IMPORTANT и MINOR.
- External Claude Code review выполнялся в read-only `--safe-mode`.
- Пять внешних итераций завершились `REVIEW_GREEN`; BLOCKER и IMPORTANT не
  обнаружены, подтверждённые безопасные MINOR исправлены.
- Финальное изменение после пятой итерации — только устранение найденной
  неоднозначности в документации; executable diff не менялся.
- Замечание о невозможности проверить URL инструкции WB отклонено: сохранён
  фактический официальный URL страницы WB.

## Follow-up

- Транзакционная проекция пока продолжает считать `abs(deduction)` расходом.
  Это сознательно оставлено вне scope по явному поручению Владельца и требует
  отдельной задачи с анализом совместимости/backfill.
- Знаки остальных WB cost-полей следует менять только отдельной задачей после
  проверки официальной семантики каждого поля.
- При необходимости отдельно добавить UI-пояснение для отрицательного значения
  «Расходы WB (нетто)», когда выплаты WB превышают удержания и другие расходы.
- Исторические task-отчёты предыдущей реализации не переписывались.

## Production Gate

Production-check, deploy, миграции, backfill, обработка очередей и другие
production-действия не выполнялись и этим Stage не разрешены.

## Delivery

- Branch: `agent/wb-finance-deduction-signs`.
- Commit и Draft PR создаются после фиксации этого Stage Report.
- Следующее действие: Release Gate — решение Владельца о Ready и merge.
