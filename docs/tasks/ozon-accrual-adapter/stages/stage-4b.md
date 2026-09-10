### Stage 4b: обработка by-day в продажи, затраты и возвраты — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `0504ffff`
**Work items:** 4b.1 … 4b.6

#### What was done
- Классификатор строк выбирается с учётом формата: у его реестра была та же
  проблема затенения, что была у реестра процессоров.
- `OzonAccrualByDayRowClassifier` — продажа и возврат по знаку `sale_amount`;
  начисления без выручки, ITEM и NON_ITEM в эти корзины не попадают.
- Три процессора рядом с легаси: продажи, затраты, возвраты.
- Затраты разбираются через `OzonAccrualCategoryFacade`. Справочник услуг
  забирается вместе с днём и хранится внутри документа.
- `OzonTransactionTotalsClient` удалён: снят Ozon тем же решением, ссылок не было.

#### Инварианты, закреплённые тестами
- ~~Выручка из `sale_price`, не из `sale_amount`: последний завысил бы её вдвое.~~
  **Инвариант был неверен и отменён.** Выручка идёт по базе продавца —
  `sale_amount` (= `seller_price`), как её и считает легаси-путь. Проверено на
  ПРОД: `marketplace_sales.total_revenue` за июнь 2026 по компании `19621cff` =
  1 950 549.00 — ровно сумма `sale_amount`; `sale_price` дал бы 866 631.91.
  `sale_price` — цена покупателя со скидкой Ozon, и в `marketplace_sales` она не
  попадает вообще: СПП-выручка приходит отдельным источником
  `sale_realization` из месячного отчёта «Реализация». Подробности — раздел
  «Поправка Stage 5» в `plan.md`.
- Количество = `sale_amount / seller_price`; нецелое частное пропускается с
  warning, а не округляется.
- Возврат — POSTING с отрицательным `sale_amount`; количество остаётся
  положительным, потому что обе величины отрицательны.
- Неразобранная услуга всё равно становится затратой, в очереди «Требует
  классификации»: потерянная затрата занижает расходы.

#### Files changed
- `site/src/Marketplace/Infrastructure/Normalizer/` — контракт классификатора,
  реестр, легаси-классификаторы, новый `OzonAccrualByDayRowClassifier`
- `site/src/Marketplace/Application/Processor/OzonAccrual{Sales,Costs,Returns}RawProcessor.php` — new
- `site/src/Marketplace/Infrastructure/Api/Ozon/OzonAccrualByDayClient*.php` — справочник услуг
- `site/src/Marketplace/MessageHandler/SyncOzonAccrualByDayHandler.php` — конверт документа
- `site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php` — разворачивание `accruals`
- `site/src/Marketplace/Infrastructure/Api/Ozon/OzonTransactionTotalsClient.php` — deleted
- `site/phpstan-baseline.neon` — сокращён
- `ARCHITECTURE.md` — контракт классификатора, устройство разбора, запись 1.89

#### Checks
- `make site-cs-check` 0/2517; `make site-cs-strict-types` 0/2517
- `make site-stan` — No errors, baseline сокращён 2510 → 2509
- `make site-test` — 4336 тестов, 23221 утверждение
- все тесты писались до реализации и были красными

#### Internal review
- iterations: 2; BLOCKER/IMPORTANT: none
- по ходу исправлено: пять ошибок PHPStan (лишняя проверка, неиспользуемая
  зависимость, типы), удалена тестовая заглушка, `git add site/` захватил файлы
  Владельца — состав коммита выправлен поимённо
- FOLLOW-UP: загрузка by-day по расписанию не включена; строка крона
  возвращается в Stage 5 вместе с восстановлением истории

#### External review
- required: yes (HIGH-LOCAL); round: 1, result: fixed without re-run
- **confirmed fixed, все четыре:**
  1. **Себестоимость возврата не сторнировала себестоимость продажи.**
     `resolveForReturn()` получал `null` вместо исходной продажи, и при
     изменении себестоимости между продажей и возвратом ОПиУ сторнировал бы не
     ту сумму. Ключ продажи переведён с `accrual_id` на **номер отправления** —
     он общий у продажи и её возврата, а `accrual_id` у них разные. Возврат
     теперь находит продажу через `findByMarketplaceOrderAndSku()`.
  2. **Документ без конверта молча давал ноль затрат.** Документ более ранней
     версии загрузчика разобрать нечем — справочника в нём нет. Теперь падает с
     внятным «refetch the day», а не записывает шаг затрат успешным.
  3. **Замена затрат шла без транзакции.** Вызывающий удаляет прежние затраты
     до вызова; сбой после удаления оставлял бы документ вовсе без затрат.
     Удаление и вставка обёрнуты в одну транзакцию, как в легаси-процессоре.
  4. **MINOR: пустой sku.** Создал бы общий листинг-пустышку, к которому
     прицепились бы несвязанные финансовые записи. Отвергается с warning в обоих
     процессорах.
- reviewer limitations: результат сверки с «Реализацией» и покрытие каталогов
  переданы фактами в промпте

#### Risks / reviewer focus
- Ключ продажи на номере отправления: два положительных начисления по одному
  отправлению и товару дали бы один ключ, и второе было бы пропущено как уже
  существующее. На проверенной выгрузке такого нет; легаси решает это версиями
  `_vN`. Записано как наблюдение.
- Три услуги (`LastMileCourier`, `PickUpPointReturnAcceptance`, `SupplyInbound`)
  не разбираются ни одним каталогом и требуют разового ручного сопоставления.

#### Next
- Stage 5: восстановление истории, сверка с Ingestion, возврат строки крона
