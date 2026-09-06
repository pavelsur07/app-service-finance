# Stage 5 — `costPriceUnit`: неизвестно вместо нуля

`owner_gate: yes` · `release_candidate: yes` · `independently_deployable: no` (продолжает Stage 3)
Риск: 🟠 HIGH-LOCAL — денежное поле, сквозной контракт PHP → JSON → XLSX → React.

- `stage_base_commit`: `ad042788`
- Ветка: `feat/inventory-stock-freshness`

## Зачем

FOLLOW-UP из Stage 3. `costPriceUnit` равнялся `0.0` в двух разных случаях:
себестоимость единицы действительно нулевая **и** делить было не на что. Ноль в
денежной колонке — утверждение о факте, и во втором случае оно было ложным.

В Stage 3 различие уже вводилось, но только внутри расчёта: локальный
`?float $knownCostPriceUnit` управлял денежной оценкой остатка, а публичное поле
сохраняло прежний контракт с `0.0`. Теперь различие доведено до контракта.

## Что сделано

- `UnitExtendedQuery`: `costPriceUnit` стал `?float`; внутренний дубль
  `$knownCostPriceUnit` убран, `stockCapitalRub` считается от него же.
- `unitExtended.types.ts`: `costPriceUnit: number | null`.

## Чего не потребовалось

- **XLSX** — `buildTypedCell()` уже отдаёт пустую ячейку на `null`, а `costPriceUnit`
  уже исключён из строки «ИТОГО». Кода не менялось.
- **React-рендер** — `formatMoney(row.costPriceUnit)` уже даёт прочерк на `null`,
  сортировка уже использует `?? -Infinity`. Изменился только тип.
- **Агрегаты** — поле не входит ни в `totals`, ни в свод по тегам. Проверено grep по
  `src/`, `tests/`, `assets/`: все места использования вошли в дифф.

## Различие двух нулей проверяется тестами

| Тест | Условие | Ожидание |
|---|---|---|
| `testStockCapitalIsUnknownWhenUnitCostIsUnknown` | `costPriceQuantity = 0` | `costPriceUnit === null` |
| `testStockCapitalIsZeroWhenUnitCostIsGenuinelyZero` | `costPriceQuantity = 2`, `costPriceTotal = 0` | `costPriceUnit === 0.0` |

Первый прогнан **красным** на коде с прежним `?? 0.0`:
`Failed asserting that 0.0 is null`. В XLSX-тесте добавлены утверждения на обе
ячейки: известное значение выгружается числом, неизвестное — пустой ячейкой.

## Проверки

| Проверка | Результат |
|---|---|
| `php-cs-fixer --dry-run --using-cache=no` | `Found 0 of 2481`, exit 0 |
| `make site-stan` (level 8) | `[OK] No errors` |
| `composer test:unit` | 2320 тестов |
| Полный integration | 1252 теста, 5770 утверждений |
| Полный functional | 599 тестов |
| `npm run lint` | зелёный (`--max-warnings=0`) |
| `npx tsc --noEmit` | **0 ошибок в файлах `marketplace-analytics`** (унаследованные 6 в трёх нетронутых файлах остаются) |
| `npm run build` | успешно |

PHPStan-baseline не рос.

## Внешнее ревью

Codex, один круг, `REVIEW_GREEN`. Одна MINOR — принята и исправлена: мой комментарий
описывал `null` как «продаж за период не было», что неточно. `costPriceQuantity`
считает не все продажи, а только единицы с **известной** себестоимостью, поэтому
`null` возможен и при наличии продаж. Формулировка исправлена в PHP и в типах фронта.

Подтверждено ревьюером: при продажах и нулевой себестоимости сохраняется `0.0`;
nullable проверяется до умножения и `round`; молчаливого превращения `null` в ноль нет;
тип TypeScript соответствует контракту.

## Закрывает

FOLLOW-UP «`costPriceUnit` остаётся `0.0` при неизвестной себестоимости» из Stage 3 и
чекпоинта. Незакрытым остаётся второй FOLLOW-UP — миграция `marketplace-analytics` из
`_legacy` в `modules/`.
