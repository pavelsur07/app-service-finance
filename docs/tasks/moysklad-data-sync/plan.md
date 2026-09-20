# МойСклад: загрузка контрагентов

**Спецификация:** [`stage-0.md` в PR #2493](https://github.com/pavelsur07/app-service-finance/pull/2493). До его слияния файл находится в соседней документационной ветке. **Ответы API:** `site/tests/Fixtures/MoySklad/Counterparty/` и соседний `README.md`.

**Цель:** загружать контрагентов из проверенного подключения МойСклад в модуль `MoySklad` без записи в другие модули или в API МойСклад.

**Baseline:** `docker compose run --rm site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/MoySklad` — 46 тестов, 227 assertions, PASS на `2d23ac36`.

## Общие ограничения

- Каждый запрос к данным ограничен `companyId`; внутренние и внешние ID сами по себе не дают доступ.
- Токен остаётся в `MoySkladConnection`; не писать в таблицы, сообщения, логи, исключения или ответы.
- Два полных прохода: `archived=false`, затем `archived=true`; отсутствие в выборке не удаляет запись.
- Курсор продвигается только после двух проходов; повторный запуск начинает с начала, upsert по `(connection_id, external_id)`.
- HTTP без транзакции БД; запись одной страницы атомарна. Нельзя логировать тела ответов, имена, контакты и реквизиты.
- Первый релиз: ручной запуск администратором через страницу подключения; расписание, обратная запись и финансовые правила вне задачи.
- Обновлять `ARCHITECTURE.md` при добавлении сущностей и новых публичных контрактов; production-миграция проводится только по отдельному dispatch после merge.

## Карта файлов

| Область | Файлы и ответственность |
| --- | --- |
| Схема | `site/src/MoySklad/Entity/MoySkladCounterparty.php`, `MoySkladSyncCursor.php`, `MoySkladSyncRun.php`; новая `site/migrations/Version*.php`; builder'ы в `site/tests/Builders/MoySklad/`. |
| Хранение | `site/src/MoySklad/Infrastructure/Repository/` — запросы с `companyId`, upsert по внешнему ID, история запусков. |
| API | Расширить `site/src/MoySklad/Infrastructure/Api/MoySkladClient.php` методом списка; нормализация в `site/src/MoySklad/Application/` без хранения произвольного JSON. |
| Проход | `site/src/MoySklad/Application/Action/` — запуск, страницы, курсор и счётчики; `site/src/MoySklad/Message/` и `MessageHandler/`; маршрутизация в `site/config/packages/messenger.yaml`. |
| Экран | Существующие `site/src/MoySklad/Controller/`, `Infrastructure/Query/` и `site/templates/moy_sklad/`; отдельный POST с CSRF и правом `MARKETPLACE_WRITE`. |
| Тесты | `site/tests/Unit/MoySklad/`, `Integration/MoySklad/`, `Functional/MoySklad/`; ответы — только из обезличенных JSON-фикстур. |

## Stage 1: схема и tenant-scoped хранение

**Risk:** HIGH-LOCAL (миграция). **stage_base_commit:** `2d23ac36f9c6504743460fc064406a5088b7464f`.

**Definition of Done:** три таблицы точно соответствуют §2 спецификации; FK запрещают каскадное удаление; уникальные ключи и индексы работают; все запросы требуют `companyId`; отключение сохраняет строки, удаление связанного подключения выдаёт существующий `connection_in_use`.

**Work items:**

- **1.1:** Написать падающие тесты инвариантов сущностей и tenant-scoped запросов; создать три Entity, builder'ы и Repository. Проверить, что другая компания не читает строки по внутреннему и внешнему ID.
- **1.2:** Написать падающий интеграционный тест сохранения, уникальности и запрета удаления; создать миграцию с FK/индексами/ограничениями; применить к локальной test-БД. Обновить раздел MoySklad в `ARCHITECTURE.md`.

**Stage checks:** модульные unit+integration тесты; `doctrine:schema:validate`; focused PHP CS и PHPStan для новых файлов; diff migration SQL. **Reviewer focus:** tenant isolation, FK без cascade, имена колонок, обратимость пустой миграции, отсутствие токена и произвольного payload.

## Stage 2: API и нормализация ответа

**Risk:** MEDIUM. **stage_base_commit:** записать перед 2.1.

**Definition of Done:** клиент читает страницу с Bearer, точным `Accept`, `limit/offset/order/filter`, без redirect и неограниченных повторов; проверка `meta/rows`, `accountId` и полей из словаря; легальные, ИП, физические и архивные ответы нормализуются; секреты и тело не попадают в исключения/логи.

**Work items:**

- **2.1:** Тесты `MockHttpClient` на параметры запроса, JSON-фикстуры, 401/403/429/5xx/redirect/некорректный JSON; расширить существующий `MoySkladClient` и безопасную классификацию ошибок.
- **2.2:** Тесты нормализации по каждому типу и отсутствующим реквизитам; реализовать DTO страницы/контрагента, UTC-преобразование `updated`, проверку UUID и границ строк. Не сохранять контакты и дополнительные поля.

**Stage checks:** `tests/Unit/MoySklad`, focused CS/PHPStan. **Reviewer focus:** отсутствие PII в логах, корректный архив, необязательные реквизиты, даты MSK→UTC, сетевые лимиты.

## Stage 3: фоновый полный проход

**Risk:** HIGH-LOCAL (Messenger и транзакции). **stage_base_commit:** записать перед 3.1.

**Definition of Done:** один запуск на подключение и тип; две выборки без дублей, страницы атомарны, курсор меняется только вместе с успешным запуском; сбой сохраняет предыдущий курсор и зафиксированные страницы; повтор с начала восстанавливает состояние; история содержит безопасную категорию и счётчики.

**Work items:**

- **3.1:** Интеграционные тесты первичной загрузки, обновления, второго прохода, повторного запуска, пропуска записи, ошибки в середине и другого `companyId`; реализовать Action с advisory lock, короткими page transaction и upsert.
- **3.2:** Тесты конкурентного запуска и временных ошибок/429; добавить Message и Handler с `companyId`, маршрут `async_sync`, ограниченный backoff и наблюдаемость. Различать 401, 403, 429, temporary, invalid response и local storage error.

**Stage checks:** модульные unit+integration тесты; focused CS/PHPStan; проверить маршрутизацию и retry. **Reviewer focus:** lock жизненный цикл, атомарность курсора, failed run при исключении, Messenger redelivery, перескок страниц при изменениях источника, отсутствие открытой транзакции на HTTP.

## Stage 4: ручной запуск и статус для администратора

**Risk:** MEDIUM. **stage_base_commit:** записать перед 4.1.

**Definition of Done:** на существующей странице доступен запуск для активного проверенного подключения, статус и счётчики последнего запуска; POST требует CSRF и `MARKETPLACE_WRITE`; другая компания и read-only роль не запускают поток и не видят чужую историю; пустой/ошибочный/успешный состояния понятны.

**Work items:**

- **4.1:** Functional тесты доступа, CSRF, отправки сообщения и отсутствия токена в ответе; добавить тонкий Controller/Action и кнопку ручного запуска.
- **4.2:** Functional тесты отображения running/succeeded/failed и last completed; расширить Query и Twig-шаблон, провести ручной smoke страницы.

**Stage checks:** модульный functional набор, focused CS/PHPStan и Twig lint. **Reviewer focus:** IDOR, право записи, доступность статуса, отсутствие PII и секрета в HTML.

## Handoff

Один раз выполнить `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`, `make site-test`; внутреннее ревью полного diff, затем обязательное внешнее ревью Large-задачи. Прочитать `docs/workflow/release.md`: PR содержит миграцию, поэтому в handoff отдельно запросить merge/deploy и ручной dispatch production-миграции. До решения Владельца production не менять.
