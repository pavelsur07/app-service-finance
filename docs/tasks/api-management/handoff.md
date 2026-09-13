# api-management — handoff

Branch: `feat/api-management`. PR: https://github.com/pavelsur07/app-service-finance/pull/2474 (Ready, base master).

## Результат

Реализованы все четыре Stage: публичный номер компании и Bearer-ключи; интерфейс управления; каталог подготовленных прав и атомарное сохранение с версией; матрица прав и обработка конфликтов. Владелец создаёт независимые ключи на 90 суток, проверяет подключение без cookies, переименовывает, отзывает и подготавливает 16 конкретных прав. Секрет показывается один раз. Все предметные ресурсы пока отключены, действующие права пусты. Старые ключи отчётов и выгрузки сохраняют контракт.

## Файлы и контракт

- `site/src/Api/` — Entity/Actions, явный аудит Shared, аутентификация/principal/context, политики, настройки/Forms и README.
- `site/src/Company/Entity/Company.php`, `Facade/CompanyFacade.php`, Repository — публичный ID, разрешение во внутренний UUID и проверка фактического владельца.
- `site/config/`, `site/templates/api/`, навигация, Vite entry, OpenAPI types, тесты и архитектурные документы.
- `site/phpstan.dist.neon` — обнаружение символа новой миграции для regression test; baseline не расширен.
- Проверка: `GET /api/external/v1/auth/check`, `Authorization: Bearer <API_KEY>`, `X-Company-Id` — публичный номер. Ответ содержит company/key ID, expiry, prepared/effective scopes; ошибки Problem Details: 401/403/422/429; no-store, без сессии.

## Проверки

| Проверка | Результат |
|---|---|
| Api module + route coverage | PASS: 351 тест / 1458 проверок |
| `make site-stan` | PASS: 2550 файлов, 0 ошибок; после symbol-discovery fix |
| `make site-cs-check` | PASS: 0/2565; canonical `.php-cs-fixer.php` |
| `make site-cs-strict-types` | PASS: 0/2565 |
| `make site-test-unit` | PASS: 2839 тестов / 15897 проверок, 4 deprecations |
| `make site-test` | 4815 тестов / 27782 проверки; 2 существующих падения, 2 warnings, 6 deprecations |
| `npm run lint`, `npm run build`, Twig/YAML | PASS |
| `make api-types-check` | PASS |
| `npm run check:ui-kit` | 9108 нарушений:9107baseline +1явное legacy-исключение |
| `npm run check:uikit-react-mapping` | 47 baseline нарушений, новых 0 |
| Chromium desktop/mobile | PASS: issue/copy/history/storage/check/rename/revoke; presets/409/reload/mobile390 |

Makefile выполнялся с локальным `DOCKER_COMPOSE=/tmp/api-management-compose-exec`: адаптер передаёт неизменённые команды targets в уже работающие контейнеры изолированного worktree. Новых зависимостей нет. Миграции применены только в локальном TEST; CI подтвердил migration-empty-db и API types на Stage3.

Два падения общего набора — `VerificationApiControllerTest`: фиксированная дата fetchedAt 2026-06-15 13:00MSK вышла за окно `now -90days` (cutoff 2026-06-15 14:53MSK при диагностике). Query/facade/controller/tests не менялись от 22399152; исходный класс воспроизводит 2 failures (6tests/68assertions). Временная копия с единственным изменением даты на now проходит оба метода (2tests/37assertions), затем удалена. Это отдельный FOLLOW-UP: стабилизировать часы/фикстуры, без изменения финансовых правил.

Браузер подтвердил 201/no-store, копирование с чтением clipboard, очистку секрета при уходе и возврате назад, отсутствие localStorage/sessionStorage, невозврат вставленного ключа. Матрица: наборы 16/10, все 10 ресурсов отключены, auth/check показывает prepared при effective=[], две вкладки дают 409 и перечитывание без потери нового выбора. Мобильный overflow исправлен и воспроизведение стало зелёным: document/workzone/viewport 390 px. Временные account/session config удалены до итоговых тестов.

## Review

- Stage 1: internal 1 BLOCKER / 4 IMPORTANT исправлены; external round 1:0 BLOCKER / 2 IMPORTANT / 3 MINOR исправлены, fixed without re-run.
- Stage 2: fresh internal 0; implementation 2 MINOR исправлены. External не требовался.
- Stage 3: fresh internal 0; external round 1 REVIEW_GREEN,2 MINOR исправлены и проверены.
- Stage 4:1 MINOR mobile overflow исправлен. Полное независимое internal review в свежем контексте:0 BLOCKER / 0 IMPORTANT / 0 MINOR, whitespace PASS.
- Handoff external round 1: 0 BLOCKER / 1 IMPORTANT / 3 MINOR. IMPORTANT отклонён: установленный Symfony RequestEvent::setResponse вызывает stopPropagation(), ExceptionEvent наследует его. Реальный EventDispatcher с ErrorListener и logger-never проверен для всех пяти ошибок: 5 тестов /25 проверок, логирование не вызывается. Три MINOR исправлены: fresh row lock при rename; только Retry-After на HTML-проверке; dropdown-item из существующего Tabler-меню вместо кнопки. Две регрессии показаны red→green; совместная проверка исправлений25тестов/188проверок, PHPStan/CS/Twig PASS. Результат: fixed without re-run по §7.2, BLOCKER не было.

Review metrics на текущий момент: internally found BLOCKER 1 / IMPORTANT 4, externally reported BLOCKER 0 / IMPORTANT 3 (2 подтверждены и исправлены в Stage1, 1 отклонён с доказательством на handoff); подтверждённых открытых 0. Всего 3 внешних раунда: по одному в Stage1, Stage3 и handoff.

## Миграции и выпуск

- `Version20260913090000`: sequence/public_id, backfill компаний, уникальный индекс.
- `Version20260913090100`: api_keys.
- `Version20260913090200`: selected/enabled JSON с пустыми defaults.

Миграции expand-only, совместимы со старыми INSERT. UUID и финансовые строки не меняются. Публичные номера не переиспользуются. Down запрещён: после публикации ID и выдачи ключей — forward-fix. Backfill/index может блокировать companies; объём production пока не измерялся, production-доступ не выполнялся.

Перед production dispatch нужен проверенный актуальный бэкап БД, включая companies; для уже существующих ключей — api_keys. В текущем запуске бэкап не создавался. Migration workflow не содержит backup, поэтому бэкап выполняется владельцем/существующим утверждённым процессом; расширение wrapper-полномочий не планируется.

После разрешения: проверить исходные счётчики companies/контроль UUID и наличие бэкапа; merge; отдельно dispatch migrations; проверить актуальность схемы; dispatch deploy. Read-only acceptance: число компаний и UUID сохранены, public_id не NULL/уникален/>=100000, sequence/defaults присутствуют, pending migrations нет; auth/check без ключа 401+Bearer+no-store; контейнеры/приложение работают. Секреты и payload не выводятся. При расхождении — расследование без автоматического SQL repair.

DBAL schema diff для non-PK nextval public_id документирован: не удалять default и не применять слепо auto-generated migration.

## Ограничения и решение владельца

Предметные endpoints, финансовые изменения, история каналов/операций и бейджи выполняются отдельным планом. Подготовленные права не открывают доступ. Новый выпуск не отзывает предыдущий ключ автоматически.

Ready: PR #2474 «feat(api): управление Bearer-ключами компании и подготовленными правами» несёт три необратимые миграции (forward-fix). Перед dispatch требуется проверенный бэкап БД/companies. Разрешение запрашивается совместно на production migrations и deploy по `docs/workflow/release.md`, ответ: `run the migration and deploy #2474`. До этого merge/deploy не выполняются.

Legacy-совместимость: старый layout загружает Tabler, а не UI Kit menu.css. Ссылка API использует тот же dropdown-item, что соседние пункты. Это одно явное дополнительное срабатывание UI Kit checker (9108 вместо9107), без нового CSS/зависимостей. Основание: root AGENTS.md §1 — существующий паттерн при интеграции; новый интерфейс API остаётся на UI Kit. Попытка menu-item выявлена scoped review как MINOR и исправлена; открытых замечаний0.

Полные gates выполнены до последних review-исправлений; после них повторены относящиеся к изменениям проверки, включая весь Api module351/1458, focused PHPStan/CS, Twig и UI Kit.

Публикация: Stage4 implementation958d0167 отправлен в ветку; PR2474 отмечен Ready, base master проверен. CI проверяет финальный head; его актуальный статус доступен в PR и повторно проверяется перед merge.
