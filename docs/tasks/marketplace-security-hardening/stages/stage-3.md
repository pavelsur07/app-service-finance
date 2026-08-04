### Stage 3: M5 + M10 — пагинация productsIndex и транзакция тегов — DONE

**Risk:** MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously (Stage 4)

#### Stage scope
- Stage base commit: `2d30d0cd`
- Work items completed: 3.1, 3.2, 3.3, 3.4

#### What was done
- `MarketplaceController::productsIndex` — Pagerfanta (ORM QueryAdapter, 50/стр., паттерн costsIndex того же контроллера) вместо `getResult()` всех листингов компании
- `products.html.twig` — `pager.currentPageResults`, empty-state по `nbResults`, счётчики «Активных/Неактивных на странице» (честная маркировка per-page), навигация через общий `partials/_pagerfanta.html.twig`
- `AssignListingTagAction::__invoke` — `em->wrapInTransaction`: создание тега (flush) + DBAL-вставка назначений атомарны, тег-сирота невозможен
- Новый `MarketplaceProductsPaginationTest` (55 листингов: page1=50, page2=5, empty-state)

#### Files changed
- `site/src/Marketplace/Controller/MarketplaceController.php` — modified
- `site/templates/marketplace/products.html.twig` — modified
- `site/src/Marketplace/Application/AssignListingTagAction.php` — modified
- `site/tests/Functional/Marketplace/Controller/MarketplaceProductsPaginationTest.php` — new

#### Definition of Done
- [x] `/marketplace/products` отдаёт страницу через Pagerfanta, шаблон рендерит pager
- [x] Создание тега + назначения атомарны
- [x] `ListingTagsApiTest` зелёные (17 тестов); новый тест пагинации зелёный

#### Baseline
- Stage 2 final: unit 1722 OK; integration+functional Marketplace 337 OK

#### Checks
- targeted: `MarketplaceProductsPaginationTest` + `ListingTagsApiTest` — OK (19 tests)
- module: `tests/Functional/Marketplace tests/Integration/Marketplace tests/Functional/Admin` — OK (348 tests)
- full stage: `make site-test-unit` — OK (1722 tests); `lint:twig` — OK

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: none
- FOLLOW-UP: createdAt tiebreaker в orderBy пагинации (ниже)

#### External Claude Code review
- Iterations: 2 (первая обрезала вывод; focused re-check снял ложный IMPORTANT)
- Result: REVIEW_GREEN
- Confirmed findings fixed: none
- Rejected findings with reason: «IMPORTANT: hoist requireTag out of wrapInTransaction» — отклонено по результатам focused re-check: 404-маппинг ListingTagNotFoundException работает через wrapInTransaction, locks не расширяются; максимум MINOR-оптимизация одного round-trip на by-id пути
- FOLLOW-UP записан: сортировка пагинации по `createdAt` без уникального tiebreaker (pre-existing паттерн costsIndex/index; добавить `l.id ASC` отдельной задачей)

#### Review fixes applied
- none (все находки — FOLLOW-UP/отклонённые)

#### Risks / reviewer focus
- leftJoin to-one без addSelect — count корректен, fan-out отсутствует
- DBAL insert в `ListingTagAssignmentRepository` на том же connection — физически одна транзакция (use_savepoints: true)

#### Checkpoint
- `docs/tasks/marketplace-security-hardening/checkpoint.md` updated
- exact next action: Stage 4 — H1 шифрование apiKey (expand: codec + миграция + readers/writers)

#### Open questions
- none

#### Expected owner response
- not required; continuing autonomously
