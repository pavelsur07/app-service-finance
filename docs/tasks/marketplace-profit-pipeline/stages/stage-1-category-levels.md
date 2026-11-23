# Этап 1. Категории затрат легаси Ozon → уровень каскада

**Утверждено Владельцем 13.11.2026**, включая три спорные категории (❓) — по предложениям ниже. Источник — набор F снимка (28 категорий, встретившихся
у кабинета 3f4d87cc за 08.09 – 12.11.2026). Суммы — вне git.

**Общее правило:** уровень задаёт вид операции; операция прямого вида **без листинга** уходит в
«общие» (разносится по ключу D3), вид при этом сохраняется.

| Код легаси | Название | Листинг в данных | Вид операции (канон) | Уровень |
|---|---|---|---|---|
| `ozon_sale_commission` | Комиссия за продажу | есть | `commission` | МД1 |
| `ozon_brand_commission` | Брендовая комиссия | есть | `commission_brand` | МД1 |
| `ozon_logistic_direct` | Логистика к покупателю | есть | `logistics_to` | МД1 |
| `ozon_logistic_last_mile` | Last mile | есть | `logistics_to_last_mile` | МД1 |
| `ozon_dropoff_apvz` | Дропофф АПВЗ | есть | `logistics_to_dropoff` | МД1 |
| `ozon_delivery_to_handover_place` | Доставка до места передачи | есть | `logistics_to_handover` | МД1 |
| `ozon_logistic_return` | Обратная логистика | есть | `logistics_back` | МД1 |
| `ozon_return_pvz` | Перевыставление возврата ПВЗ | есть | `logistics_back_pvz` | МД1 |
| `ozon_acquiring` | Эквайринг | есть | `acquiring` | МД1 |
| `ozon_package_labor` | Упаковка партнёрами | есть / редко нет | `packaging_labor` | МД1 (без листинга — общие) |
| `ozon_package_materials` | Упаковочные материалы | есть / редко нет | `packaging_materials` | МД1 (без листинга — общие) |
| `ozon_additional_packaging_warehouse` | Доп. упаковка на складе | нет | `packaging_warehouse` | общие |
| `ozon_storage_partner` | Временное хранение у партнёров | есть | `storage` | МД1 |
| `ozon_storage` | Хранение на складе | нет | `storage` | общие |
| `ozon_return_from_stock` | Комплектация для вывоза продавцом | есть | `stock_removal` | МД1 ❓ |
| `ozon_disposal` | Утилизация товара | есть | `disposal` | МД1 ❓ |
| `ozon_cpc` | Оплата за клик | нет (учёт) | `ads_cpc` | МД2 — сумма из статистики по SKU, учётная строка не суммируется |
| `ozon_marketing_action` | Маркетинговые акции (оплата за заказ) | нет (учёт) | `ads_pay_per_order` | МД2 — часть под статистикой по SKU; остаток вне статистики — общие |
| `ozon_stars_membership` | Звёздные товары | есть | `promo_stars` | МД2 ❓ |
| `ozon_premium_promotion` | Продвижение Premium | нет | `promo_premium` | общие |
| `ozon_seller_bonus` | Бонусы продавца (рассылки) | нет | `promo_seller_bonus` | общие |
| `ozon_reviews` | Приобретение отзывов | нет | `promo_reviews` | общие |
| `ozon_analytics_premium` | Подписка Premium-аналитика | нет | `subscription` | общие |
| `ozon_defect_rate_fine` | Превышение индекса ошибок | нет | `fine` | общие |
| `ozon_fines_customer_complaint` | Жалобы покупателей | нет | `fine` | общие |
| `ozon_fines_shipment_delay_rated` | Штраф за нерекомендованный слот | нет | `fine` | общие |
| `ozon_service_correction` | Корректировка стоимости услуг | нет | `service_correction` | общие |
| `ozon:service:10`, `ozon:service:25` | Компенсация / Товарная компенсация (14.11, вид добавлен для подтверждения администратором) | нет | `compensation` | общие (+, прочий доход) |
| `ozon:service:7` и разовые удержания без своего вида | Благотворительное пожертвование и т. п. (14.11) | нет | `mp_other_deductions` | общие |
| `ozon_unknown_119` | Неразобранная услуга: CustomerChatPoints | нет | — | **карантин** ❓ |

## Решения по спорным категориям (❓) — утверждены 13.11.2026

1. `ozon_return_from_stock`, `ozon_disposal` — вывоз и утилизация остатка: затрата товара, но не
   продажи. Предложение: МД1 (прямая затрата SKU), отдельной колонкой «вывоз и утилизация», чтобы
   не искажать логистику продажи.
2. `ozon_stars_membership` — предложение: МД2 (продвижение конкретного SKU).
3. `ozon_unknown_119` (CustomerChatPoints) — предложение: вид `promo_chat_points`, общие.

## Не в этом списке

Категории, которых у кабинета за период не было (остальные коды `OzonCostCategory`), получают
уровень на этапе 4 при переносе справочника: правило то же, спорные — списком на утверждение.

## WB: поле / операция строки → вид → уровень (этап 8.0, 14.11.2026)

Эталон легаси WB: 3 кабинета, 16.09 – 13.11.2026, Σ затрат по категориям (`~/vf-measurements/.../2026-11-14-stage8/baseline-wb`).
`source_type` — по W4 (`stages/stage-8.md`). Строки с ❓ — предложение, решение администратора на экране кодов
WB (как у Ozon); новые виды канона — только по этому решению.

| `source_type` | Легаси-категория | Σ легаси, ₽ | Вид канона | Уровень |
|---|---|---:|---|---|
| `wb:field:sale` (`retailPriceWithDisc × quantity`, «Продажа») | продажа | — | `sale` | выручка |
| `wb:field:return` (то же, «Возврат») | возврат | — | `return` | выручка |
| `wb:calc:spp` (выручка − `retailAmount`) | — (легаси не хранит) | — | `spp` | справочно |
| `wb:calc:commission` (выручка − `forPay` − `acquiringFee`) | `commission` | 1 993 268,79 | `commission` | СМ1 |
| `wb:field:acquiringFee` | `acquiring` | 120 065,37 | `acquiring` | СМ1 |
| `wb:field:deliveryService` (доставка) + коррекция логистики | `logistics_delivery`, `logistics_correction` | 351 811,35 | `logistics_to` | СМ1 |
| `wb:field:deliveryService` (возврат) | `logistics_return` | 111 642,56 | `logistics_back` | СМ1 |
| `wb:field:rebillLogisticCost` | `warehouse_logistics` | 35 660,27 | ❓ `logistics_to` | СМ1 |
| `wb:field:ppvzReward` | `pvz_processing` | 9 555,07 | ❓ `logistics_to_last_mile` | СМ1 |
| `wb:field:paidAcceptance` | `product_processing` | 13 510,00 | ❓ новый вид `acceptance` «Приёмка и обработка товара» | СМ1 (как хранение) |
| `wb:field:paidStorage` | `storage` | 826,53 | `storage` | СМ1 |
| `wb:field:penalty` | `penalty` | 9 904,44 | `fine` | СМ3 |
| `wb:deduction:Оказание услуг «WB Продвижение»` | `wb_okazanie_uslug_wb_prodvizhenie` (под статистикой рекламы) | 520 729,00 | ❓ новый вид `ads_settlement` «Списание рекламы (справочно)» — W6 | справочно |
| `wb:deduction:Предоставление услуг по подписке «Джем»` | `wb_predostavlenie_uslug_po_podpiske_dzhem` | 45 980,00 | `subscription` | СМ3 |
| `wb:deduction:Утилизация товара…` | `wb_warehouse_disposal` | 88,00 | `disposal` | СМ1 |
| `wb:field:cashbackDiscount` | `wb_loyalty_discount_compensation` | 5 901,58 | `compensation` | СМ3 |
| `wb:field:cashbackCommissionChange` | `wb_loyalty_program_fee` | 182,39 | ❓ `promo_seller_bonus` | СМ3 |
| `wb:field:cashbackAmount` | `wb_review_points_withheld` | 2 050,00 | `promo_reviews` | СМ3 |
| `wb:field:additionalPayment` | (Ingestion: доплата) | — | `compensation` | СМ3 |
| `wb:deduction:<другая причина>` | `wb_<slug>` создаётся сам | — | карантин → черновик ИИ → администратор | — |

Реклама WB в каноне — из `wb.ads.daily` (расход `updSum` по дням, вид `ads_cpc`, СМ2, разнесение по
`fullstats`), а не из удержания «WB Продвижение»: удержание приходит нерегулярно (19 строк за 2 месяца)
и уже оплаченными суммами. Чтобы не считать рекламу дважды и сохранить `total = canon + quarantine`,
удержание идёт в справочный вид (W6).
