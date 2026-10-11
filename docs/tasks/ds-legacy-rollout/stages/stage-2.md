### Stage 2: Finance — DONE

**Risk:** MEDIUM · **База:** `feat/ds-rollout-base` (#2625)

#### Страницы
`document/{index,deleted_index,edit}`, `finance/report/raw`, `finance/reports/pl_raw`, `pl_category/index`.

#### Что сделано
- Документы ОПиУ: доход — зелёный, расход — основной цвет (был красным), итог — со
  знаком U+2212; суммы с двумя знаками (DS: в таблицах копейки всегда), не целые рубли.
- Правка документа: суммы распределения — `vf_money` с «₽».
- Сырые отчёты (отладочные): суммы — `vf_amount`, цвета строк — токены Warn/OK;
  колонка `amount_raw` намеренно остаётся сырой.
- Статьи ОПиУ: hover — Navy 50, KPI-строка — Navy 100.

#### Тесты
- `RawPlReportControllerTest`: проверки сумм переведены на текст страницы в формате
  DS (`200,00`); положительные и отрицательные проверки по-прежнему значимы.
- `phpunit tests/Functional/Finance tests/Functional/Cash` ✅ 191; `lint:twig` ✅;
  PHPStan/cs-fixer ✅.
- smoke: документы, удалённые, правка документа, статьи ОПиУ, сырые отчёты — 200.
