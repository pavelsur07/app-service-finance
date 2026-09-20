### Stage 4: ручной запуск и статус — CLOSED

**Risk:** MEDIUM. **Stage base commit:** `0d52c55c31a99342a8f5a75378690b7cb89c492b`. **Work items:** 4.1, 4.2.

#### What was done

- На странице подключения доступна кнопка загрузки для активного проверенного подключения. POST проверяет `MARKETPLACE_WRITE`, активную компанию, принадлежность подключения и CSRF; отправляет только ID в `async_sync`.
- Карточка показывает последний запуск, пять последних запусков, счётчики, безопасную категорию ошибки и время последнего полного прохода. Чтение статусов страницы выполняется двумя tenant-scoped запросами без N+1.
- Пустое, успешное и ошибочное состояния проверены; старый cursor виден после сбоя нового запуска.

#### Definition of Done

- [x] Чужая компания и read-only роль не запускают загрузку.
- [x] GET и неверный CSRF отклоняются, выключенное и непроверенное подключение не отправляют сообщение.
- [x] Секрет не попадает в сообщение, статус или HTML.
- [x] История и cursor ограничены активной компанией.
- [x] История ограничена пятью запусками на подключение и видна на странице.

#### Checks and review

- `tests/Functional/MoySklad` — 18 tests / 123 assertions, PASS.
- Focused PHPStan — no errors; focused CS Fixer — 0 fixable files; Twig lint — PASS.
- Internal review: 0 BLOCKER, 1 IMPORTANT fixed — статус списка читается пачкой с проверкой компании вместо запроса на каждую карточку. 0 open findings.
- External review: не требуется для Stage MEDIUM; обязательный общий review — на handoff.

#### Next

- Handoff: полный набор гейтов, внутреннее ревью полного diff, внешний read-only review, готовый PR и запрос Владельцу на production migration dispatch с deploy.
