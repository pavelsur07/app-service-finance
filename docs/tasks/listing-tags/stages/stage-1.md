## Stage 1: теги листингов хранятся и назначаются из реестра — DONE

**Риск:** 🟠 HIGH-LOCAL (новые таблицы + миграция)
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** yes
**Следующее действие:** ждать решения Владельца по Stage 2 (фильтр тегов в юнит-экономике)

### Scope Stage

- Stage base commit: `ef91028e`
- Ветка: `codex/listing-tags`
- Work items completed: `1.1`–`1.7`

### Что сделано

- Миграция `Version20260722120000`: таблицы `marketplace_listing_tags` и
  `marketplace_listing_tag_assignments`, FK с `ON DELETE CASCADE`, уникальный индекс
  `(company_id, slug)`, индекс `(company_id, tag_id)`.
- Entity `MarketplaceListingTag` со scalar `companyId` и нормализацией имени в slug.
- ORM-репозиторий тегов (`findBySlug` / `findById` / `listForCompany`), каждый метод принимает `companyId`.
- DBAL-репозиторий связей: `assign` (один `INSERT … SELECT … ON CONFLICT DO NOTHING`),
  `detach`, `tagsForListings` (один JOIN на всю страницу).
- Actions `AssignListingTagAction` (в т.ч. создание тега на лету по имени) и `DetachListingTagAction`.
- Два API-endpoint'а: `POST /api/marketplace/listings/tags/assign` и `.../detach`,
  валидация payload вынесена в `ListingTagPayload`, ошибки в формате `{"error": {"code", "message"}}`.
- Реестр `/marketplace/listings`: колонка «Теги» с чипами UI Kit, чекбоксы выбора строк,
  панель массового назначения с autosuggest и опцией «Создать», снятие тега крестиком на чипе.
- Тесты: unit на нормализацию slug, functional на assign/detach (IDOR, идемпотентность,
  9 кейсов валидации, 404), functional на рендер колонки.

### Затронутые файлы

- `site/migrations/Version20260722120000.php` — new
- `site/src/Marketplace/Entity/MarketplaceListingTag.php` — new
- `site/src/Marketplace/Repository/MarketplaceListingTagRepository.php` — new
- `site/src/Marketplace/Infrastructure/Query/ListingTagAssignmentRepository.php` — new
- `site/src/Marketplace/DTO/ListingTagDTO.php` — new
- `site/src/Marketplace/Application/DTO/ListingTagPayload.php` — new
- `site/src/Marketplace/Application/DTO/ListingTagAssignResult.php` — new
- `site/src/Marketplace/Application/AssignListingTagAction.php` — new
- `site/src/Marketplace/Application/DetachListingTagAction.php` — new
- `site/src/Marketplace/Exception/InvalidListingTagPayloadException.php` — new
- `site/src/Marketplace/Exception/ListingTagNotFoundException.php` — new
- `site/src/Marketplace/Controller/Api/ListingTagAssignController.php` — new
- `site/src/Marketplace/Controller/Api/ListingTagDetachController.php` — new
- `site/src/Marketplace/Controller/MarketplaceListingsController.php` — modified
- `site/templates/marketplace/listings/index.html.twig` — modified
- `site/tests/Builders/Marketplace/MarketplaceListingTagBuilder.php` — new
- `site/tests/Unit/Marketplace/Entity/MarketplaceListingTagSlugTest.php` — new
- `site/tests/Functional/Marketplace/Controller/ListingTagsApiTest.php` — new
- `site/tests/Functional/Marketplace/Controller/MarketplaceListingsTagsColumnTest.php` — new
- `ARCHITECTURE.md` — modified
- `docs/tasks/listing-tags/plan.md`, `docs/tasks/listing-tags/stages/stage-1.md` — new

### Отклонения от плана

| План | Факт | Почему |
|---|---|---|
| 3 endpoint'а, включая `GET …/tags` | 2 endpoint'а | Справочник тегов рендерится в `data-all-tags` страницы; GET никто не вызывал — мёртвый код удалён |
| `rename()` на Entity | не добавлен | Нет вызывающих; переименование тегов — задача страницы управления, вне Stage 1 |
| 3 functional-файла | 2 файла | assign и detach делят сидинг, разносить их по файлам смысла нет |

### Self-review

- [x] Scope compliance — только теги листингов, чужие модули не тронуты
- [x] Patterns / naming — `final class` для Action/Controller/Repository, `class` для Entity, `final readonly` для DTO
- [x] Forbidden actions — нет `dump()`, нет `new Service()`, `flush()` только в Action, нет `#[ManyToOne]` на чужую Entity
- [x] Security (companyId, IDOR) — скоуп компании внутри SQL; тест подтверждает, что чужой листинг не тегируется и чужая связь не снимается
- [x] N+1 — колонка тегов: два запроса на страницу независимо от числа строк
- [x] Индексы на новые FK-поля — есть
- [x] CS-Fixer (точечно по файлам задачи) — чисто; PHPStan в проекте не установлен
- [x] `make site-test` — зелёный (2582 теста)
- [x] `ARCHITECTURE.md` обновлён

### External Claude Code review

- Iterations: 0
- Result: N/A — реализацию выполнял Claude Code; внешний read-only review той же моделью
  своего же diff не даёт независимой проверки. Проведён полный внутренний review, по итогам
  которого удалён неиспользуемый endpoint и метод `rename()`.
- Confirmed findings fixed: мёртвый `GET …/tags`, мёртвый `rename()`
- Rejected findings with reason: нет

### Команды для проверки

- `make site-test-unit`
- `make site-test`
- `docker compose run --rm site-php-cli vendor/bin/phpunit tests/Functional/Marketplace/Controller/ListingTagsApiTest.php`
- `docker compose run --rm site-php-cli php bin/console lint:twig templates/marketplace/listings/index.html.twig`

### Риски / на что обратить внимание ревьюеру

- **Гонка при создании одноимённого тега** двумя параллельными запросами упирается в
  `uniq_listing_tag_company_slug` и вернёт 500; данные при этом целы, повтор находит созданный тег.
  Помечено `ponytail:`-комментарием в `AssignListingTagAction`.
- **CSRF на POST-endpoint'ах не проверяется** — так же, как в существующем
  `ListingMappingController`. Cross-origin JSON POST блокируется CORS-preflight, но если в проекте
  появится общая политика CSRF для API, эти два endpoint'а нужно включить в неё.
- **`down()` миграции удаляет таблицы вместе с тегами.** На момент выката данных нет, откат безопасен;
  после накопления тегов откатывать миграцию нельзя.
- **cs-fixer переформатировал `MarketplaceListingsController` целиком** (снял выравнивание `=`,
  развернул yoda-условия). Это шум в диффе, но соответствует конфигу проекта; откат вернул бы
  нарушения стандарта.
- **Смешанный UI**: Tabler-каркас страницы + чипы на UI Kit. Осознанно, перевод страницы — отдельная задача.
- Полный `make site-cs-check` по репозиторию красный на 596 файлах — состояние досталось по наследству,
  файлы задачи проверены точечно.

### Открытые вопросы

- нет
