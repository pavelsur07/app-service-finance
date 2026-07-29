# Знаки удержаний и выплат WB

Источник задачи: явное поручение Владельца в чате от 29.07.2026:
«Исправляй знаки deduction по правилам WB, транзакции не трогать».

## Stage 1: Корректные направления raw-удержаний и выплат WB

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: `933090be2be22aa802172dee1990a20570e82416`

Definition of Done:

- Положительный raw `deduction` отображается как удержание и уменьшает
  расчётное перечисление.
- Отрицательный raw `deduction` отображается как выплата WB и увеличивает
  расчётное перечисление.
- Одно основание WB может содержать удержания и выплаты; отчёт показывает обе
  суммы и их нетто-влияние без классификации по тексту `bonusTypeName`.
- Статья, UI и CSV используют формулировку «Удержания и выплаты» и одинаковые
  суммы.
- Суммы статьи, расшифровки, строк `reportId`, операций и расчётного
  перечисления согласованы.
- Добавлены regression-тесты на оба знака, смешанное основание и добровольную
  выплату за пострадавшие товары.
- Изменение применяется при построении отчёта из уже сохранённых raw-данных;
  миграция и backfill не требуются.
- Транзакции, БД, WB ingestion/sync/API, очереди и production не изменяются.
- Документация финансовой семантики raw-отчёта обновлена.

Work items:

- 1.1 — исправить знаковую агрегацию `deduction` и регрессионные unit-тесты.
- 1.2 — обновить связанный UI/CSV и functional-тесты.
- 1.3 — обновить документацию, выполнить полные Stage checks и reviews.

Stage checks:

- Targeted unit + functional PHPUnit для raw-отчёта.
- Marketplace unit + functional PHPUnit.
- PHP syntax и changed-file PHP CS.
- Twig lint и Symfony container lint.
- `git diff --check` и scope-проверка отсутствия изменений транзакций,
  ingestion, schema и production.
- Internal automatic review полного Stage diff.
- External read-only Claude Code review до `REVIEW_GREEN`.

Reviewer focus:

- Официальное правило WB: `deduction > 0` — удержание,
  `deduction < 0` — выплата продавцу.
- Точное денежное сложение без `float` и корректность при смешанных знаках.
- Отсутствие двойного счёта в `payout_minor`, `post_product_minor` и
  `wb_costs_minor`.
- Одинаковая семантика HTML и CSV.
- Scope: raw-отчёт без транзакций и ingestion.

Release Gate:

- После зелёных checks и обоих reviews создать Draft PR и запросить решение
  Владельца о переводе в Ready и merge.

Production Gate:

- Не входит в задачу. Deploy, production migrations, backfill и production
  acceptance требуют отдельного явного поручения.
