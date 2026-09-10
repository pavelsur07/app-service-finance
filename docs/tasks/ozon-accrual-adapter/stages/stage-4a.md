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
- full: `make site-cs-check` 0/2511; `make site-cs-strict-types` 0/2511;
  `make site-stan` — No errors; `make site-test` — 4312 тестов, 23162 утверждения

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none
- по ходу исправлено: `MarketplaceCredentialsQuery` и клиент объявлены `final` и
  не подменяются — тест собран поверх мока DBAL по конвенции соседних тестов
  Ozon, а клиенту добавлен интерфейс; убрана тестовая заглушка, оставшаяся от
  правки; `RuntimeException` заменён на типизированное семейство исключений
- FOLLOW-UP, оба вне этой задачи:
  1. У `document_type = accrual_by_day` нет защиты от дублей на уровне БД — как
     и у WB сегодня. Расширение частичного индекса требует миграции, то есть
     отдельного действия с отдельным одобрением.
  2. `RecoverableMessageHandlingException` ретраится без учёта `max_retries` во
     всех обработчиках проекта, не только в новом. Ограничение числа попыток —
     общепроектное изменение конвенции, описанной в `CLAUDE.md`.

#### External review
- required: yes (HIGH-LOCAL); round: 1, result: fixed without re-run
- **confirmed fixed, четыре из пяти IMPORTANT:**
  1. **IDOR.** Подключение загружалось `EntityManager::find()` по одному
     идентификатору, без проверки принадлежности компании. Переведено на
     `MarketplaceConnectionRepository::findByIdAndCompany()` плюс проверка
     `OZON` и `SELLER`; добавлен негативный тест на чужое подключение.
     Замечание точное: `CLAUDE.md` проверяет это в ревью первым.
  2. **Битый ответ выглядел пустым днём.** Отсутствие или не-массив `accruals`,
     повтор курсора пагинации и достижение потолка страниц с непустым курсором
     теперь бросают `MarketplaceInvalidApiResponseException`, а не сохраняются
     как успешно загруженный день. Потолок снижен с 1000 до 100 — в июньской
     выгрузке ни один день не занял больше одной страницы.
  3. **Сбой отправки оставлял день в PENDING навсегда.** Ошибка `dispatch()`
     больше не проглатывается, а охрана «идёт обработка» ограничена возрастом
     документа: PENDING старше часа означает потерянное сообщение, а не идущую
     обработку. Добавлен тест.
  4. **`Retry-After` игнорировался.** Передаётся в Messenger в миллисекундах;
     добавлен тест на величину задержки.
- **принято частично, с записанной причиной:**
  5. Безлимитность `RecoverableMessageHandlingException`. Замечание верно:
     `RecoverableExceptionInterface` обходит `isRetryable()`, поэтому постоянный
     429/5xx ретраится бесконечно. Но это не дефект нового кода: так предписывает
     `CLAUDE.md` («Логирование»: `warning` + `RecoverableMessageHandlingException`
     пока ретраим), и так устроены все существующие обработчики проекта. Менять
     общепроектную конвенцию внутри этой стадии — расширение scope. Исправлена
     часть, относящаяся к этому коду: задержка `Retry-After` передаётся.
     Ограничение числа попыток вынесено в follow-up ниже.
  6. Гарантия уникальности активного документа `accrual_by_day` на уровне БД.
     TTL блокировки поднят с 300 до 900 секунд, потолок страниц снижен до 100 —
     реалистичное окно закрыто. DB-гарантия требует миграции, а это отдельное
     действие с отдельным одобрением; вынесено в follow-up.
- reviewer limitations: состав `api_endpoint` на PROD и результат сверки с
  «Реализацией» переданы фактами в промпте — сам ревьюер их получить не может

#### Risks / reviewer focus
- Частичный уникальный индекс `uniq_marketplace_raw_documents_active_period`
  запрещает два активных документа Ozon `sales_report` на день. Отсюда
  отдельный `document_type`, а не общий с легаси.
- Загрузчик пока никем не вызывается по расписанию: строка крона вернётся
  Work item 5.4, когда обработка появится в Stage 4b.

#### Next
- Stage 4b отдельной веткой и отдельным PR
