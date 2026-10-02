# marketplace-ozon-realization-autoload: автоопрос, загрузка и обработка «Реализации»

Baseline: `make site-test-unit` — OK 3085 (02.10.2026), PHPStan level 8 без ошибок, baseline не менялся. Миграции нет (состояние в `marketplace_financial_report_sync_statuses`).

## Stage 1: окно опроса и команда-планировщик
Risk: MEDIUM
Definition of Done:
- `OzonRealizationPollWindow` (Application, чистый): по `now` (МСК) определяет отчётный месяц и открыто ли окно (с 18:00 1-го до 23:59:59 8-го), `reportMonth`, `windowEnd`.
- Команда `app:marketplace:ozon-realization-poll [--company-id] [--dry-run]`: вне окна выходит с кодом 0 и строкой причины; в окне для активных Ozon seller-подключений находит/создаёт статус (`reportType = ozon_realization`), пропускает `success`, пропускает пары с `nextRetryAt` в будущем, остальным ставит `SyncOzonRealizationMessage` (ровно одна на подключение за прогон), помечает `queued`.
- Тесты: unit окна (границы 1-е 17:59/18:00, 8-е 23:59/9-е 00:00, декабрь→январь, февраль 29, часовой пояс); integration команды (вне окна, в окне, success пропускается, retry в будущем пропускается, чужая компания, неактивное подключение, `--dry-run`).
Work items: 1.1 окно + тесты; 1.2 доступ к статусам (findOrCreate по месяцу) + тесты; 1.3 команда + integration-тесты.
Stage checks: unit/integration модуля, PHPStan и cs изменённых файлов.
Reviewer focus: границы окна и часовой пояс, одна задача на подключение, IDOR (Query с `companyId`), не создаются дубли статуса.

## Stage 2: загрузчик различает исходы, handler ведёт статус
Risk: HIGH-LOCAL (внешний API, повторы)
Definition of Done:
- `OzonRealizationFetcher`: HTTP-статусы → типизированные исключения (429 + `Retry-After`, 5xx/сетевой → временный, 401/403 → авторизация, остальные 4xx/пустой ответ → «ещё не готово»); контракт `fetch()` прежний для ручной загрузки.
- `SyncOzonRealizationHandler`: `loading` → при строках `raw_loaded` (+ `rowsHash`), при пустом ответе `empty` + `nextRetryAt = +1 ч` (внутри окна), при лимите `nextRetryAt` из `Retry-After`, при авторизации `auth_failed` без повторов, при временном сбое `RecoverableMessageHandlingException`. Логи: старт и исход попытки с `companyId` и номером попытки, без тел ответов; `warning` на повторяемое, `error` — только на неустранимое.
- Тесты handler'а на всех исходах (с фейковым HTTP-клиентом), хеш не меняется — повторная обработка не ставится.
Work items: 2.1 типизированные исключения fetcher'а; 2.2 статусы в handler; 2.3 тесты.
Stage checks: unit/integration, PHPStan, cs; проверка `debug:messenger`.
Reviewer focus: ни один исход не теряется молча, ретраи не бесконечные, ручная загрузка из интерфейса работает как раньше.

## Stage 3: автообработка и пересчёт сверки
Risk: HIGH-LOCAL (Messenger-маршрут, финансовая обработка)
Definition of Done:
- `ProcessOzonRealizationMessage(companyId, rawDocumentId)` → `async_pipeline` (`messenger.yaml`: проверить маршрутизацию, retry/failure, совместимость сообщений).
- Handler: проверка принадлежности документа компании; месяц с закрытым `SALES_RETURNS` → не обрабатывать, статус `conflict`, `warning`; иначе `ProcessOzonRealizationAction`, `markProcessing` → `markSuccess`; затем `RunOzonReconciliationAction` за месяц; ошибка обработки → `failed` + повтор (транзитные ошибки БД) / `failed_final` (неустранимые).
- Идемпотентность: повторная доставка и повторная загрузка с тем же хешем ничего не пересоздают; новая загрузка с другим хешем идёт через атомарную переобработку, `pl_document_id` сохраняются.
- Тесты: открытый месяц обрабатывается и пишет строки; закрытый — нет; повторная доставка; сбой действия → статус; пересчёт сверки вызван.
Work items: 3.1 сообщение и маршрут; 3.2 handler с guard; 3.3 интеграция со сверкой; 3.4 тесты.
Stage checks: integration, `debug:messenger` до/после (исчезнуть ничего не должно, появиться ровно одно сообщение), PHPStan, cs.
Reviewer focus: закрытые периоды не трогаются, нет двойной обработки, при гонке с ручным «Применить выручку» результат корректен (advisory-блокировка или проверка статуса).

## Stage 4: сверка читает сырой документ; статус на вкладке
Risk: MEDIUM
Definition of Done:
- `OzonRealizationTotalsQuery` считает продажи/возвраты из сырого документа `realization` за месяц (`delivery_commission.price_per_instance × quantity`, `return_commission…`), «загружена» = документ с `rows > 0`; зависимость от `records_created` убрана. Устойчив к мусору в JSON (нечисловые значения, не-массивы).
- Вкладка «Сверка Ozon»: вместо «не загружена» — «Ожидается, проверяем ежечасно (попытка N, последняя в HH:MM)» внутри окна, «Загружена, обрабатывается», «Обработана ДД.ММ ЧЧ:ММ»; после окна без отчёта — «Не получена, загрузите вручную».
- Тесты: запрос на фикстуре (суммы вручную), месяц без документа, мусор; контроллер/шаблон по состояниям; сверка на данных «загружено, не применено» (август у «Вумджой» как кейс).
Work items: 4.1 запрос по сырью; 4.2 состояние ожидания во view-модели; 4.3 шаблон; 4.4 тесты и визуальная проверка.
Stage checks: functional/integration Marketplace/Ozon, lint:twig, визуальная проверка (десктоп и 390 px).
Reviewer focus: суммы совпадают с `ProcessOzonRealizationAction` на одном и том же документе, состояние ожидания не врёт.

## Stage 5: cron, гейт конца окна, документация
Risk: MEDIUM (cron)
Definition of Done:
- `docker/cron/app.cron`: новая строка (каждый час, :17 МСК) для `ozon-realization-poll`; прежняя `0 6 5 * *` удалена; комментарий про «10-е число» убран.
- Команда-гейт `app:marketplace:ozon-realization-check` (cron ежедневно в 07:20): начиная с 9-го числа, если у активного подключения за прошлый месяц нет `success` — один агрегированный `error` со счётчиками (exit 1); до 9-го — тишина; `conflict` (закрытый месяц) — `warning`, не красное.
- Документация: `ARCHITECTURE.md` (поток, статусы, сообщение), `docs/workflow/health-gates.md`, `docs/maintenance/prod-access.md` (что запускается ночью, что делать при красном гейте — ручная загрузка/«Применить выручку»).
Work items: 5.1 гейт и тесты; 5.2 cron и документация.
Stage checks: тесты команды, `lint:container`, полные гейты на handoff, внешнее ревью по §7.2.
Reviewer focus: охват гейта равен охвату починки, нет двойной загрузки, расписание не пересекается с by-day (04:00) и гейтом сверки (07:10).

## Handoff
Полные гейты (`make site-stan`, cs-check, strict-types, test-unit, test), внутреннее ревью в свежей сессии, внешнее ревью (Codex), `handoff.md`. Деплой без миграции — стандартное «merge and deploy». После деплоя окно за сентябрь (1–8.10) уже открыто — первый опрос произойдёт в ближайший :17; приёмка: статус пары в `marketplace_financial_report_sync_statuses`, документ `realization` за сентябрь, строки в `marketplace_ozon_realizations`, снимок сверки за сентябрь.
