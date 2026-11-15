### Stage 3: сценарии маппинга и read models — DONE

**Risk:** HIGH-LOCAL. **Stage base:** `fb218990a`.
**Work items:** 3.1, 3.2, 3.3, 3.4.

#### Результат
- ChangeListingMappingCommand/Action: tenant lookup, CAS, requestId replay,
  set/replace/clear, no-op без ревизии, серверные snapshots.
- Короткая транзакция: connection → mapping advisory → parent → variant;
  product FOR UPDATE блокирует FK-вставку нового variant до commit.
- Ожидаемые доменные ошибки возвращаются из транзакции и бросаются снаружи:
  EntityManager остаётся открытым для HTML-ответа 409/422.
- Локальные bounded search/suggest/findMany и current/history с DTO.
  Типизированный UUID IN сохраняет индексный поиск целей.
- Marketplace читается только Facade; Catalog/Finance/Inventory не меняются.
  HTTP, Messenger, фоновые задачи отсутствуют.

#### Проверки
- RED: 13 Action/catalog и 3 current/history cases на отсутствующих сервисах.
- GREEN до review: module 302 tests / 1160 assertions, concurrency 4/30;
  после индексации findMany targeted 18/63.
- Реальные процессы PostgreSQL: два SET с одной CAS; повтор requestId;
  одинаковый requestId на разных ключах; INSERT variant под product lock;
  архивирование родителя под parent FOR SHARE. Наличие wait_event_type=Lock
  проверяется до освобождения блокировки, это не последовательная имитация.
- Review regressions RED 3/3: EM open, uppercase batch key, invalid history ID;
  GREEN вместе с Action/current suite 26 tests / 81 assertions.
- Дополнены foreign company/connection/listing/product/variant, два подключения
  с одинаковым externalId, отсутствующие источники, точные 100 IDs, backslash,
  case/leading-zero articles и page past end.
- Первый archive race test вызвал несуществующий getId у импортированного
  товара: исправлен test lookup по company/connection/externalId;
  отдельная гонка PASS 1 test / 8 assertions.
- Focused PHPStan/CS и git diff --check PASS. Baseline не менялся.
- Итоговый module run: 316 tests / 1209 assertions — PASS.

#### Внутреннее review
- Полный diff: транзакции, stale/replay, parent change/archived states,
  connection readiness, snapshots, bounded IDs и отсутствие N+1.
- Новых BLOCKER/IMPORTANT в коде не подтверждено.
- Неполнота coverage выявлена внешним reviewer и закрыта до конца Stage.

#### Внешнее review
- Required: HIGH-LOCAL diff >500 строк.
- Claude CLI, effort medium, 1 раунд: B/I 0/1, MINOR 4.
- IMPORTANT test coverage gap подтверждён, все перечисленные сценарии добавлены.
- Все MINOR исправлены с проверками: EM lifecycle, UUID keys/history validation,
  boundary 100 IDs. Результат: fixed without re-run (BLOCKER не было).
- Reviewer получил pointer на исключённый скриптом fixture
  tests/Fixtures/MoySklad/mapping-worker.php и проверял его только чтением.

#### Next
- После финальных checks автоматически Stage 4: две легаси-вкладки и формы.
