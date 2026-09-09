### Stage 2: автостоп и автоснятие — DONE

**Risk:** HIGH-LOCAL (поведение планировщика)
**Stage base commit:** `804c40fb`
**Work items:** 2.1, 2.2, 2.3, 2.4, 2.5

#### Что сделано

Состояние из Stage 1 подключено к конвейеру, и цикл замкнулся: отказ доезжает
от обработчика до подключения, сломанное подключение выпадает из выборки крона,
обновление ключа возвращает его обратно.

- **2.1** `RunSyncChunkHandler` пишет исход через `MarketplaceFacade`: отказ в
  ветке `ConnectorAuthException`, успех после `markJobCompleted`.
- **2.2** `ActiveSellerConnectionsQuery::executeSyncable()` исключает сломанные
  подключения; на него переключён только `RunIncrementalCommand`.
- **2.3** `UpdateMarketplaceConnectionApiKeyController` снимает состояние; ключ
  и снятие — одна транзакция.
- **2.4** `warning` на каждый отказ, один `error` в момент перехода.
- **2.5** тесты обработчика, команды крона, выборки и обновления ключа.

#### Почему переключён только `RunIncrementalCommand`

Тот же реестр читают ещё четверо, и всем фильтр не подходит.
`MonthPreliminaryRebuildCommand` пересобирает ОПиУ по локальным данным — для
него сломанный ключ не значит ничего, а тихий пропуск превратил бы проблему
аутентификации в расхождение отчётности. `MarketplaceSyncFacade` обслуживает
ручную синхронизацию по действию пользователя: там нужна внятная ошибка, а не
молчаливое бездействие. Две команды Ozon accrual идут своим путём и в
наблюдавшемся инциденте не участвовали — все 51 отказ пришли
`RunSyncChunkMessage`, то есть из `run-incremental`.

#### Файлы

- `site/src/Ingestion/MessageHandler/RunSyncChunkHandler.php` — запись исхода,
  уровни логирования, предохранитель на неUUID-ссылку
- `site/src/Ingestion/Command/RunIncrementalCommand.php` — `executeSyncable()`
- `site/src/Marketplace/Infrastructure/Query/ActiveSellerConnectionsQuery.php` —
  `executeSyncable()`
- `site/src/Marketplace/Controller/UpdateMarketplaceConnectionApiKeyController.php` —
  снятие состояния в одной транзакции с ключом
- `site/tests/Integration/Ingestion/MessageHandler/RunSyncChunkHandlerAuthStateTest.php` — новый
- `site/tests/Integration/Ingestion/Command/RunIncrementalCommandTest.php` — два теста
- `site/tests/Integration/Marketplace/Infrastructure/ActiveSellerConnectionsQuerySyncableTest.php` — новый
- `site/tests/Functional/Marketplace/Controller/UpdateMarketplaceConnectionApiKeyControllerTest.php` — один тест

#### Definition of Done

- [x] отказ из обработчика доезжает до подключения
- [x] сломанное подключение не попадает в выборку крона
- [x] обновление ключа возвращает подключение в выборку
- [x] уровни логирования по правилу `CLAUDE.md`
- [x] остальные потребители выборки не тронуты
- [x] исключённое (интерфейс) не тронуто

#### Проверки

- `make site-cs-check` и `make site-cs-strict-types` — `Found 0 of 2496`
- `make site-stan` — `No errors`; PHPStan-baseline не изменён
- `make site-test-unit` — 2353 теста / 12070 утверждений / 4 deprecation
- `RunSyncChunkHandlerAuthStateTest` — 4 теста / 20 утверждений
- `RunIncrementalCommandTest` — 14 тестов / 64 утверждения
- `ActiveSellerConnectionsQuerySyncableTest` — 2 теста / 6 утверждений
- `UpdateMarketplaceConnectionApiKeyControllerTest` — 25 тестов / 100 утверждений
- интеграционный набор целиком — см. ниже

#### Внутреннее ревью

Итераций: 2. Найдено и исправлено самим:

- **Ложный алерт на каждой удачной синхронизации.** Путь успеха проходит на
  каждом завершённом чанке, а задания со ссылкой вроде `connection-1`
  существуют (так устроен предсуществующий тест обработчика, а
  `StartIncrementalCommand` требует от ссылки лишь непустоты). Строгая проверка
  UUID в фасаде на таком значении бросала, обёртка ловила и писала `error` —
  то есть по ложному алерту в GlitchTip на каждый успешный чанк. Исправлено
  предохранителем `Uuid::isValid` до вызова; покрыто тестом, проверенным
  красным без предохранителя.

#### Внешнее ревью

Раундов: 1, ревьюер — Codex, effort high. Четыре находки IMPORTANT, все
подтверждены и все закрыты:

1. **Уровни логирования не давали обещанной тишины.** Проверено по коду:
   `SendFailedMessageForRetryListener` пишет `critical` на неретраящемся сбое, а
   Sentry при `capture_soft_fails: false` ловит именно жёсткие сбои. То есть
   отказ доезжал до GlitchTip помимо моих строк, и комментарий, обещавший
   обратное, был неверен. Комментарий исправлен на правдивый: шум убирает не
   уровень записи, а остановка планировщика. Само поведение не менялось —
   `critical` от Messenger существовал и до задачи.
2. **Контроллер молча врал при сбое сброса.** `recordSuccess()` подавлял ошибку
   БД и возвращал `false`, контроллер результат игнорировал и показывал
   «Подключение активно», хотя ключ уже сохранён, а состояние осталось
   сломанным — крон продолжал бы обходить подключение. Исправлено: ключ и
   снятие состояния выполняются одной транзакцией через `wrapInTransaction`,
   запись строгая, сбой виден.
3. **Не было теста на главную регрессию.** Возврат вызова к `execute()` оставил
   бы набор зелёным. Добавлены два теста на `RunIncrementalCommand`: сломанное
   подключение не даёт ни одного задания, здоровое рядом продолжает
   обходиться.
4. **Не было теста на снятие состояния успешным чанком.** Удаление
   `recordConnectorAuthSuccess()` прошло бы незамеченным. Добавлен тест: два
   отказа, затем успешный чанк с настоящим UUID — счётчик обнулён, статус `OK`,
   отметка снята.

#### Риски и на что смотреть дальше

- Сломанное подключение больше не синхронизируется вовсе. Это заявленное
  поведение, но до Stage 3 пользователь об этом нигде не узнает — интерфейс
  появится следующим Stage. Между деплоем Stage 2 и Stage 3 состояние видно
  только в базе.
- Порог 3 привязан к часовой каденции крона; при её изменении смысл порога
  изменится.

#### Next

Continue to Stage 3 automatically: пилюля и кнопка на странице подключений,
блок «Интеграции» на дашборде, тексты без HTTP-кодов и фрагментов ключа.
