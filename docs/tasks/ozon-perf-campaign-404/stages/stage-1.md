### Stage 1: 404 удалённой кампании Ozon Performance распознаётся без завязки на текст ответа — DONE

**Risk:** MEDIUM
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required — только для merge/деплоя и разбора 13 сообщений в failed

#### Stage scope
- Stage base commit: `63a8db2ad4d35059f43863a653a90416bccf26d2`
- Work items completed: `1.1` (регрессионные тесты), `1.2` (исправление классификации 404 + лог пропуска)

#### What was done
Прод-issues GlitchTip 230 (fatal, 49 событий) и 234/231/233/318 (36 событий) с 2026-06-27 по 2026-09-04
содержали `RuntimeException: Ozon Performance returned HTTP 404 for /api/client/campaign/<id>/objects:
{"error":"Объект не найден"}`. В `messenger_messages` на проде лежит 13 сообщений в очереди `failed` —
по 4 за ночи 2, 3 и 4 сентября 2026.

Причина подтверждена в коде, а не выведена из симптома. Коммит `cc4d475f` (2026-06-27) добавил типизированное
`OzonPerformanceCampaignNotFoundException` и путь пропуска в `OzonPerformanceReportConnector:132`, но условие
распознавания требовало подстроку `campaign not found` в теле ответа. Ozon отдаёт тело на русском, поэтому
условие не срабатывало ни разу: пропуск был физически недостижим, летел generic `RuntimeException`, Messenger
ретраил три раза и клал сообщение в `failed`. Тесты оставались зелёными, потому что фикстуры содержали
выдуманное английское тело — то есть тест закреплял несуществующий ответ API.

Влияние шире одной кампании: `RunSyncChunkHandler` гонит чанки циклом `do … while ($result->hasMore)`, и
исключение обрывало весь цикл. Кампании, идущие после сбойной в курсоре, за этот прогон не выгружались вовсе.

Исправление: 404 на эндпоинте `/api/client/campaign/{id}/objects` классифицируется как отсутствующая кампания
по статусу и форме эндпоинта, без разбора текста ответа. Эндпоинт адресует ровно одну сущность, поэтому 404
на нём не имеет другого значения. Неверный base URL или шлюзовой 404 сюда не доходят — они падают раньше, на
`/api/client/token`. Пропуск теперь пишет `warning` с `companyId`/`connectionRef`/`campaignId`, чтобы
отсутствие рекламных данных по кампании было объяснимо; уровень `warning`, а не `error`, потому что состояние
ожидаемое и обрабатывается само.

#### Files changed
- `site/src/Ingestion/Infrastructure/Api/Ozon/OzonPerformanceReportClient.php` — modified
- `site/tests/Integration/Ingestion/Infrastructure/Api/Ozon/OzonPerformanceReportClientTest.php` — modified
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonPerformanceReportConnectorTest.php` — modified

#### Definition of Done
- [x] 404 с реальным русским телом даёт `OzonPerformanceCampaignNotFoundException`, а не generic `RuntimeException`
- [x] распознавание не зависит от текста ответа (покрыто русским, английским, нейтральным и пустым телом)
- [x] 404 на любом другом эндпоинте по-прежнему generic — покрыто отдельным guard-тестом
- [x] пропуск наблюдаем в логах на уровне `warning`, без тела ответа и credentials
- [x] регрессия доказана красной на коде до правки
- [x] фикстуры тестов приведены к реальному ответу Ozon
- Исключено из Stage: разбор 13 сообщений в `failed` на проде (Production Gate), issues 274/237/2 (P1–P2)

#### Baseline
- `php bin/phpunit --testsuite integration --filter OzonPerformanceReportClientTest` — OK (8 тестов, 36 assertions)
- `php bin/phpunit --testsuite unit --filter OzonPerformanceReportConnectorTest` — OK (8 тестов, 41 assertion)
- красного baseline в репозитории нет: cs, strict-types и stan были зелёными до задачи

#### Checks
- targeted: `php bin/phpunit --testsuite integration --filter OzonPerformanceReportClientTest` — OK (13 тестов, 52 assertions)
- регрессия доказана красной: на коде до правки те же тесты дали 3 failures + 1 error; guard-тест
  `testNotFoundOnOtherEndpointStaysGenericInsteadOfBeingSkipped` был зелёным и до, и после
- module: `php bin/phpunit --testsuite unit --filter Ozon` — OK (482 теста, 4797 assertions)
- full relevant stage: `make site-test-unit` — OK (2291 тест, 4 pre-existing deprecations); `make site-test-integration` — OK (1223 теста)
- `make site-cs-check` — Found 0 of 2466 (перепроверено с `--using-cache=no`, 79 с)
- `make site-cs-strict-types` — Found 0 of 2466
- `make site-stan` — No errors; `phpstan-baseline.neon` не менялся
- CI на PR #2415 (run 33946019763) — все проверки зелёные: Unit, Integration, Functional 1–3/3,
  Static analysis, PHP code style, migrations-empty-db, Check API types sync, build-and-push

#### Internal automatic review
- Iterations: 2
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: рост PHPStan-baseline из-за `Company::getId(): ?string` в двух новых тестах — снят использованием
  локального литерала `$companyId` вместо `$company->getId()`, baseline остался нетронутым; убрана неиспользуемая
  переменная в `catch`
- FOLLOW-UP: `OzonPerformanceReportConnector` не имеет логгера, поэтому агрегированной сводки «сколько кампаний
  пропущено за прогон» нет — только поэлементный `warning` клиента и `skippedReason` в metadata сырого батча

#### External Claude Code review
- Реализация — Claude Code, внешний ревьюер — Codex (`codex exec -s read-only --ephemeral`), по таблице ролей `CLAUDE.md`
- Iterations: 1
- Result: REVIEW_GREEN
- Confirmed findings fixed: none — находок BLOCKER/IMPORTANT/MINOR нет
- Rejected findings with reason: none
- Ограничение ревьюера: шелла у него не было, команды он не запускал и результаты проверок принял из промпта.
  Факты о проде (issues GlitchTip, содержимое `messenger_messages`, поведение `RunSyncChunkHandler`) переданы
  в промпт — самостоятельно проверить их ревьюер не мог.

#### Review fixes applied
- нет: внешнее ревью вернулось зелёным с первой итерации

#### Risks / reviewer focus
- Расширение условия: 404 на этом эндпоинте с любым телом теперь трактуется как отсутствие кампании. Ошибочно
  проглотить инфраструктурный 404 нельзя — при неверном базовом URL запрос токена падает раньше и жёстко.
- Пропуск не молчаливый: `warning` в логах + `skippedReason` в metadata сырого батча.
- Изменение не трогает финансовые формулы, схему БД и авторизацию.

#### Checkpoint
- `docs/tasks/ozon-perf-campaign-404/checkpoint.md` обновлён
- exact next action: решение Владельца по merge и по 13 сообщениям в `failed`

#### Open questions
- В ночь на 2026-09-05 падений ad-sync не было ни в GlitchTip, ни в `failed`, хотя 2–4 сентября было стабильно
  по 4. Отработал ли джоб чисто или не запускался — не проверено.

#### Expected owner response
Recommended response:
`Мержи PR и скажи, что делать с 13 сообщениями в failed`

Alternative responses, when relevant:
- `Оставь PR в Draft, посмотрю сам`
- `Займись P1 (274/237) на этой же ветке`
