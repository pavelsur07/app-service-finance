# WB: «Стоимость участия в программе лояльности»

## Задача

Гейт `wb-costs:unrecognized-check` 30.10.2026: новая операция WB
«Стоимость участия в программе лояльности» не поддерживается ни одним
калькулятором. Кабинет 3f4d87cc, отчёт за 28.10, 2 строки, 154,33 ₽.
`bonusTypeName` — «Комиссия ВБ за участие в программе лояльности "Баллы за
отзывы"»; сумма только в `cashbackCommissionChange` (42,09 и 112,24, обе > 0),
`forPay`/`deduction` нулевые. Других строк с ненулевым
`cashbackCommissionChange` с 01.09 нет.

Решение Владельца 30.10: статья «Раздачи / самовыкупы / отзывы»
(`PROMO_GIVEAWAYS`) — рядом с удержанием баллов за отзывы (#2724).

## Результат

`WbLoyaltyProgramFeeCalculator` (priority 98), категория
`wb_loyalty_program_fee` («Продвижение и реклама»), правило шаблона →
`PROMO_GIVEAWAYS`. Знак: `> 0` CHARGE, `< 0` STORNO, как у #2724.

## Что считаем выполненным

PR с тестами, гейты зелёные; после деплоя `app:marketplace:reprocess` по
3f4d87cc за 28.10 даёт 2 затраты = сырью, гейт exit 0, правило ОПиУ создано
ночным `sync-default`.

## Чек-лист приёмки

- [x] калькулятор: camel/snake, сторно, ноль, варианты имени, чужие операции
- [x] строку поддерживает ровно один калькулятор (`WbCostsRawProcessorTest`)
- [x] категория в `WbCostCategory`, правило в `default_cost_mapping.yaml`, `ARCHITECTURE.md` 2.90
- [x] unit 4219, integration + functional Marketplace 785, PHPStan, CS — зелёные
- [ ] после деплоя: reprocess 3f4d87cc 28.10, 2 затраты 154,33 ₽, гейт зелёный
- [ ] правило `wb_loyalty_program_fee` → «Раздачи» у 3f4d87cc

## Замечание

`saleDt` второй строки — 28.08.2026: как у #2724, затрата ляжет датой продажи
в закрытый август (`operationDate`).
