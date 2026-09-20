### Stage 3: запуск и наблюдаемость — DONE

**Risk:** HIGH-LOCAL

**Stage base commit:** `422f431a`

**Work items:** 3.1, 3.2, 3.3

#### What was done

- Добавлен POST запуска каталога с правом записи, активной компанией, UUID в маршруте, CSRF и проверенным подключением.
- Экран подключений показывает отдельно статус, счётчики, курсор и пять последних запусков для товаров и модификаций. Запрос статусов выполняется фиксированным числом запросов на страницу, с фильтром компании.
- Кнопка повторного запроса остаётся доступной при старом `running`: если загрузка действительно активна, advisory lock пропускает повтор, а экран объясняет поведение.

#### Definition of Done

- [x] Ручной запуск и статусы видны только своей компании и с нужным правом.
- [x] CSRF, UUID маршрута, отсутствие чужих статусов, статус `running` и сообщение в очереди проверены функциональными тестами.
- [x] Twig синтаксически корректен; нет нового Vite entry, React или публичного API.

#### Checks

- `php bin/phpunit tests/Functional/MoySklad/ConnectionsControllerTest.php` — 23 tests, 158 assertions; новые проверки сначала были RED.
- `doctrine`/PHPStan по изменённым контроллерам и query — no errors; `lint:twig` — 1 файл OK.
- `npm run lint` и `npm run build` — pass; Vite исходники не менялись.
- `npm run check:ui-kit` — fail, 8977 нарушений во всём проекте; затронутый legacy Twig использует те же Tabler-классы, что и существующий блок.
- `npm run check:uikit-react-mapping` — fail, 47 отсутствующих React wrappers у существующих UI Kit ссылок; React/UI Kit в задаче не менялись.
- HTTP smoke затронутой страницы проведён через BrowserKit: подключённое/неподключённое состояние, три статуса, кнопка, CSRF, права и отсутствие токена в ответе.

#### Internal review

- Iteration 1: BLOCKER 0, IMPORTANT 0. Проверены IDOR, права, CSRF, UUID, пустые статусы, живой `running`, отсутствие N+1 и безопасный вывод ошибок. Внешний review Stage 2 обнаружил 2 UI-related MINOR, они исправлены в этом Stage.

#### External review

- Отдельный раунд Stage 3 не требуется: diff <500 lines; обязательный финальный раунд — на handoff.

#### Risks / reviewer focus

- Глобальные frontend UI Kit checks красные из-за существующей legacy-разметки и отсутствующих wrappers. Изменённый блок использует уже применяемые на этой странице классы.

#### Next

- Handoff gates, финальное внутреннее и внешнее ревью, Ready PR.
