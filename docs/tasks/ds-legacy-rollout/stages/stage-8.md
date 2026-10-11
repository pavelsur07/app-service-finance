### Stage 8: Marketplace — отчёты — DONE

**Risk:** MEDIUM · **База:** `feat/ds-rollout-base` (+ `vf_minor`, локальный коммит)

#### Страницы
`marketplace/wb_finance_report`, `marketplace/month_close/index`,
`marketplace/ozon_reconciliation/{index,operations,_macros}`.

#### Что сделано
- База: фильтр `vf_minor` — точный перевод копеек в десятичную строку через bcmath
  (заменяет `format_minor_currency` в отчёте WB без потери точности на крупных оборотах).
- Отчёт WB: KPI, итоги и подписи — `vf_money` с «₽»; чистые денежные колонки статей
  и удержаний — `vf_amount`, «₽» в заголовке; 31 счётчик/количество — `vf_number`;
  проценты — `vf_number(1)` (минус U+2212). Цвет: положительное — зелёный,
  отрицательное — основной цвет (синий и красный у сумм убраны); красный оставлен у
  расхождения сверки продаж, нехватки себестоимости и текста ошибки синхронизации.
- Сверка Ozon: «₽» в заголовки колонок; разница — со знаком (плюс значим), красная
  при расхождении (сигнал ошибки); сторно затрат — зелёным с U+2212.
- Закрытие месяца: суммы сверки с xlsx — `vf_money` с «₽» вместо «руб.».

#### Тесты
- `WbRawFinancialReportControllerTest`: ожидания под DS (U+00A0, «₽», без «₽» в
  колонках удержаний, U+2212 в процентах); проверка «результат SKU красный» заменена
  проверкой знака «−25,00 ₽» и отсутствия красного — по листу DS-Money.
- `OzonReconciliationControllerTest`: `118,00`, `−18,00`.
- unit `tests/Unit/Twig` ✅ 30; `phpunit tests/Functional/Marketplace tests/Functional/Inventory` ✅ 236;
  `lint:twig` ✅; PHPStan/cs-fixer ✅.
- smoke: отчёт WB, закрытие месяца, сверка Ozon — 200.
