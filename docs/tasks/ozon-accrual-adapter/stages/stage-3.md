### Stage 3: сосуществование форматов в конвейере обработки — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `fa9ff5065e1640daa49887b9dd47a2bd03ceb041`
**Work items:** 3.1, 3.2, 3.3

#### What was done
- `MarketplaceRawFormat` — поколение API сырого документа. Значения равны
  `marketplace_raw_documents.api_endpoint`; перечислены все, реально
  встречающиеся на PROD. Колонка уже заполнена, миграция не понадобилась.
- `MarketplaceRawProcessorInterface::supports()` и
  `MarketplaceRawProcessorRegistry::get()` получили четвёртый опциональный
  аргумент `?MarketplaceRawFormat $format`. `$kind` под это не годился: он занят
  и означает корзину записей, а перегрузка одного параметра двумя смыслами в
  зависимости от типа другого — мина.
- Ozon-процессоры продаж, затрат и возвратов заявляют формат явно: принимают
  `OZON_TRANSACTION_LIST_V3` либо отсутствие формата. Реестр возвращает ПЕРВЫЙ
  подошедший, поэтому без этого будущие процессоры by-day были бы затенены
  порядком сервисов в контейнере.
- `ProcessMarketplaceRawDocumentAction` выводит формат из `apiEndpoint`.
  Пустое значение — прежнее поведение; **незнакомое непустое** бросает
  `UnrecoverableMessageHandlingException` до любой обработки.
- WB не тронут: два её поколения отчёта продолжают обслуживаться теми же
  процессорами.

#### Files changed
- `site/src/Marketplace/Enum/MarketplaceRawFormat.php` — new
- `site/src/Marketplace/Application/Processor/MarketplaceRawProcessorInterface.php` — modified
- `site/src/Marketplace/Application/Processor/MarketplaceRawProcessorRegistry.php` — modified
- `site/src/Marketplace/Application/Processor/MarketplaceRawProcessorRegistryInterface.php` — modified
- `site/src/Marketplace/Application/Processor/{Ozon,Wb}{Sales,Costs,Returns}RawProcessor.php`, `MarketplaceOtherProcessor.php` — modified
- `site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php` — modified
- `site/tests/Unit/Marketplace/Enum/MarketplaceRawFormatTest.php` — new
- `site/tests/Unit/Marketplace/Application/Processor/RawProcessorFormatIsolationTest.php` — new
- `site/tests/Unit/Marketplace/ProcessMarketplaceRawDocumentActionTest.php` — modified
- `site/tests/Unit/Marketplace/MarketplaceRawProcessorRegistryTest.php` — modified
- `site/tests/Builders/Marketplace/MarketplaceRawDocumentBuilder.php` — modified
- `site/phpstan-baseline.neon` — modified (сокращён)
- `ARCHITECTURE.md` — modified (enum + контракт выбора процессора, запись 1.88)

#### Definition of Done
- [x] формат выводится из `apiEndpoint` и передаётся в реестр
- [x] существующие Ozon-процессоры заявляют формат явно; затенения нет
- [x] регрессионный тест: легаси-документ уходит тому же процессору с тем же
      результатом — на уровне Action, а не только `supports()`
- [x] поведение WB не меняется ни на одном из двух её форматов
- [x] исключено: новых процессоров Ozon и правок `OzonAdapter` нет

#### Checks
- baseline: `make site-test` до Stage — 4291 тест зелёный
- targeted: новые тесты красные до реализации (12 ошибок), затем 4 падения
  после ужесточения по ревью, зелёные после
- full: `make site-cs-check` 0/2503; `make site-cs-strict-types` 0/2503;
  `make site-stan` — **No errors**, baseline **сократился 2513 → 2510**;
  `make site-test` — 4293 теста, 23115 утверждений

#### Internal review
- iterations: 2; BLOCKER/IMPORTANT: none
- MINOR fixed: удалён мёртвый `MarketplaceRawFormat::isOzonLegacyTransactionList()`
  (вызывающих не было); удалён тавтологический тест на различие двух enum-кейсов
- прочее: `$document` был типизирован как `object`, из-за чего `getApiEndpoint()`
  не проходил PHPStan. Заменён null-check на `instanceof MarketplaceRawDocument`
  по конвенции соседних Action'ов; побочно ушли три унаследованные записи
  baseline (`object::getCompany/getMarketplace/getRawData`)
- FOLLOW-UP: нет

#### External review
- required: yes (HIGH-LOCAL)
- rounds: 1; result: fixed without re-run
- confirmed fixed, все три IMPORTANT:
  1. незнакомый непустой `apiEndpoint` больше не уходит в легаси-процессор, а
     останавливает обработку понятной unrecoverable-ошибкой; легаси-процессоры
     при переданном формате принимают только `OZON_TRANSACTION_LIST_V3`;
  2. добавлены два action-level теста: легаси-документ уходит тому же
     процессору с четырьмя ожидаемыми аргументами и тем же результатом,
     и отдельный тест на отказ по незнакомому endpoint. Прежние тесты остались
     бы зелёными, перестань Action передавать формат, — это и был пробел;
  3. `ARCHITECTURE.md` дополнен: `MarketplaceRawFormat`, семантика `null` и
     правила выбора legacy/by-day процессоров.
- reviewer limitations: состав `api_endpoint` на PROD передан фактами в промпте

#### Risks / reviewer focus
- Ужесточение вскрыло, что тестовый билдер подставлял выдуманный
  `/test/endpoint`. Дефолт заменён на реальный эндпоинт соответствующего
  маркетплейса — 9 интеграционных тестов стали честнее относительно прода.
- Новый `api_endpoint` в будущем остановит обработку до правки enum. Это
  осознанный выбор: альтернатива — молча создать финансовые записи по чужой схеме.

#### Next
- continue to Stage 4 automatically
