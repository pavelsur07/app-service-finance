### Stage 4a: загрузка by-day рядом со старым путём — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `d77337c1`
**Work items:** 4a.1, 4a.2

#### What was done
- `OzonAccrualByDayClient` — POST `/v1/finance/accrual/by-day` с пагинацией по
  `last_id`. Форма запроса и ответа подтверждена выгрузкой за июнь 2026:
  официальную документацию Ozon получить не удалось.
- Ошибки разложены по семейству `MarketplaceApiException`: 429 →
  `MarketplaceRateLimitException` с `Retry-After` из заголовка, 401/403 → Auth,
  5xx → Temporary, остальное → BadRequest. Конверт ошибки Ozon переносится в
  исключение — именно его отсутствие не дало опознать снятие v3.
- `SyncOzonAccrualByDayMessage` / `SyncOzonAccrualByDayHandler` /
  `app:marketplace:ozon-accrual:daily-sync` — загрузка дня рядом с легаси-путём.
- `OzonAccrualByDayClientInterface` — по образцу `OzonAccrualClientInterface` в
  Ingestion: реализация остаётся `final`, обработчику нужен подменяемый контракт.

#### Files changed
- `site/src/Marketplace/Infrastructure/Api/Ozon/OzonAccrualByDayClient.php` — new
- `site/src/Marketplace/Infrastructure/Api/Ozon/OzonAccrualByDayClientInterface.php` — new
- `site/src/Marketplace/Message/SyncOzonAccrualByDayMessage.php` — new
- `site/src/Marketplace/MessageHandler/SyncOzonAccrualByDayHandler.php` — new
- `site/src/Marketplace/Command/OzonAccrualByDaySyncCommand.php` — new
- `site/config/packages/messenger.yaml` — modified (routing в `async_sync`)
- три новых unit-теста

#### Definition of Done
- [x] клиент с пагинацией по `last_id`
- [x] документ с `api_endpoint = ozon::v1/finance/accrual/by-day` и
      `document_type = accrual_by_day`
- [x] пустой день сохраняется документом
- [x] повторный прогон обновляет существующий документ, дубля нет
- [x] лимит и 5xx — в retry, неустранимое — в failed-транспорт
- [x] легаси-путь не тронут
- [x] исключено: процессоры и классификатор — вынесены в Stage 4b по решению
      Владельца от 10.09.2026

#### Checks
- targeted: тесты клиента, обработчика и команды красные до реализации,
  зелёные после
- full: см. отчёт по ветке в PR — гейты прогоняются на закрытии всей ветки

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none
- по ходу исправлено: `MarketplaceCredentialsQuery` и клиент объявлены `final` и
  не подменяются — тест собран поверх мока DBAL по конвенции соседних тестов
  Ozon, а клиенту добавлен интерфейс; убрана тестовая заглушка, оставшаяся от
  правки; `RuntimeException` заменён на типизированное семейство исключений
- FOLLOW-UP: у `document_type = accrual_by_day` нет защиты от дублей на уровне
  БД — как и у WB сегодня. Идемпотентность держит загрузчик. Расширение
  частичного индекса — отдельный PR с миграцией, вне этой задачи.

#### External review
- проводится на закрытии ветки вместе со Stage 2 и Stage 3

#### Risks / reviewer focus
- Частичный уникальный индекс `uniq_marketplace_raw_documents_active_period`
  запрещает два активных документа Ozon `sales_report` на день. Отсюда
  отдельный `document_type`, а не общий с легаси.
- Загрузчик пока никем не вызывается по расписанию: строка крона вернётся
  Work item 5.4, когда обработка появится в Stage 4b.

#### Next
- Stage 4b отдельной веткой и отдельным PR
