# Технический бэклог

Задачи упорядочены по приоритету (сверху — важнее).

---

## [1] UI не должен показывать «Переоткрыт», если в БД status = CLOSED

**Модуль:** Marketplace / MonthClose  
**Файлы для разбора:**
- `templates/marketplace/month_close/index.html.twig` — рендер бейджа в истории закрытий
- `src/Marketplace/Entity/MarketplaceMonthClose.php` — логика `getStageStatus()`
- `src/Marketplace/Enum/MonthCloseStageStatus.php`

**Суть:**  
Разобраться, по какой логике рисуется бейдж «Переоткрыт» в истории закрытий.
Если это вычисляемое поле — либо убрать его, либо синхронизировать с реальным статусом
из БД. Если статус `CLOSED` — бейдж «Переоткрыт» показываться не должен.
Также: явно показывать кнопку «Переоткрыть» только там, где статус это допускает.

---

## [2] DomainException из handler должен возвращаться пользователю

**Модуль:** Marketplace / MonthClose  
**Файлы для изменения:**
- `src/Marketplace/MessageHandler/CloseMonthStageHandler.php` — сейчас `DomainException` только в лог
- `src/Marketplace/Entity/MarketplaceMonthClose.php` — добавить поле `last_error`
- `templates/marketplace/month_close/index.html.twig` — показывать ошибку в карточке этапа
- Миграция для нового поля

**Суть:**  
Сейчас `[MonthClose] Domain error — no retry` пишется только в лог. Пользователь видит
flash «Закрытие запущено» и тишину — этап так и остаётся незакрытым без объяснения причин.

Минимальный фикс:
1. При `DomainException` в `CloseMonthStageHandler` писать текст ошибки в
   `MarketplaceMonthClose.last_error` (новое nullable-поле `string|null`).
2. В карточке этапа на странице `/marketplace/month-close` показывать `last_error`,
   если он есть и статус не `closed`.

---

## [3] Удалить оба debug-контроллера — дедлайн 2026-05-04

**Файлы для удаления:**
- `src/Marketplace/Controller/Debug/SaleGrossDebugController.php`
  (endpoint: `GET /_debug/marketplace/sale-gross`) — добавлен в PR #1603
- `src/Marketplace/Controller/Debug/OrphanDocumentIdDebugController.php`
  (endpoint: `GET /_debug/marketplace/orphan-document-ids`) — добавлен в PR #1604

**Суть:**  
Оба контроллера помечены `@deprecated` с датой удаления 2026-05-04.
После применения миграции `Version20260420160500` на prod и подтверждения
`orphan_rows=0` — удалить оба файла.

---

## [4] Исправить семантику price_per_unit в OzonSalesRawProcessor

**Модуль:** Marketplace / Sales  
**Файлы для разбора:**
- `src/Marketplace/Application/Source/OzonSalesRawProcessor.php` — строки ~216-229
- `src/Marketplace/Infrastructure/Query/UnprocessedSalesQuery.php` — использует `total_revenue` для Ozon после фикса PR #1603

**Суть:**  
В `OzonSalesRawProcessor` поле `price_per_unit` содержит `accrual` (начисление за
posting целиком), а не цену за единицу товара. PR #1603 замаскировал проблему на
уровне SQL — `SALE_GROSS` для Ozon теперь читает `total_revenue` вместо
`price_per_unit × quantity`. Семантическая ошибка в самой записи данных осталась.

Требует:
1. Бэкфилл исторических записей `marketplace_sales` для Ozon
   (`price_per_unit = total_revenue / quantity` для multi-item записей).
2. Правку `OzonSalesRawProcessor` для новых записей.
3. Тесты, подтверждающие корректность после бэкфилла.
