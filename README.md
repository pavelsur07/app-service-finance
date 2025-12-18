# App Service Finance

## Scheduler

* Запуск: `docker compose up -d scheduler`
* Логи: `docker compose logs -f scheduler`
* Проверка синтаксиса: `docker compose exec scheduler supercronic -test /etc/crontabs/app.cron`
* Ручной прогон любой команды (минуя cron): `docker compose exec -T site-php-cli php /app/bin/console app:cash:auto-rules -vvv`

## Управление пользователями

* Повысить пользователя до супер-админа: `docker compose exec -T site-php-cli php /app/bin/console security:promote user@example.com --super-admin`

Да — это хороший первый шаг. Но важно сделать **README-правила “маленькими и исполнимыми”**, иначе они превратятся в декларацию, которую никто не соблюдает.

Ниже — готовый черновик, который можно прямо вставить в `site/README.md` (или `docs/ARCHITECTURE.md`) и дальше дополнять по мере рефакторинга.

---

# Правила организации кода (MVP → порядок без поломок)

## 0) Зачем это

Проект исторически развивался смешанно: часть логики уже выделена в модули (`src/Balance`, `src/Telegram`, `src/Marketplace` и т.д.), часть лежит в общих директориях (`src/Controller`, `src/Service`, `src/Entity`).
Цель правил — **остановить рост “кучи”** и дать понятный путь миграции **маленькими PR**.

---

## 1) Модули — базовый принцип

**Новая функциональность добавляется только в модуль.**
Модуль = вертикальный срез: Controller + Service + Entity/Repository + Form + Templates + Tests (если применимо).

**Стандартная структура модуля:**

* `src/<Module>/Controller`
* `src/<Module>/Service`
* `src/<Module>/Entity`
* `src/<Module>/Repository`
* `src/<Module>/Form`
* `templates/<module>/` (или twig namespace `@<Module>/...`)
* `tests/.../<Module>/...`

Примеры уже существующих модулей: `Balance`, `Telegram`, `Loan`, `Marketplace`, `Banking`, `Finance`.

---

## 2) “Общие” папки в `src/` — только для Shared

Папки `src/Controller`, `src/Service`, `src/Entity`, `src/Repository`, `src/Form` считаются **legacy/shared**.

Правило:

* **не добавляем туда новый код**, кроме действительно общих вещей, которые:

  1. используются минимум в 2+ модулях,
  2. не относятся к конкретной предметной области,
  3. имеют нейтральное имя (например `Common`, `Shared`, `Infrastructure`).

Рекомендуемое место для общего кода:

* `src/Shared/...` (создаём постепенно)
* или `src/Infrastructure/...` (интеграции, адаптеры, клиенты, transport)

---

## 3) Контроллеры

* Контроллеры фичи живут **в модуле**: `src/<Module>/Controller/*`.
* В `src/Controller/*` остаются только:

  * “корневые” страницы (например общий dashboard/переадресации),
  * auth/onboarding (если это действительно shared),
  * healthcheck/ping.

---

## 4) Сервисы и ответственность

Принцип: **минимум “God service”**.

* `Service` внутри модуля — это *use-case / application service* (оркестрация).
* Доменная логика (правила, проверки) по возможности выделяется:

  * в небольшие классы (`Policy`, `Validator`, `Calculator`, `Resolver`)
  * и лежит рядом в модуле.

Запрещено (по возможности):

* смешивать в одном сервисе: парсинг импорта + бизнес-правила + сохранение + внешние API + отправку событий.

---

## 5) Twig-шаблоны и partials

Сейчас есть `templates/partials` и `templates/_partials`.
Правило:

* единый каталог partials: **`templates/partials/`** (один источник истины)

Рекомендуется использовать twig namespaces:

* `@Balance/...`
* `@Telegram/...`
* `@Marketplace/...`

Цель namespaces: физическое перемещение шаблонов не должно ломать `render()` и `include()`.

---

## 6) Тесты: “зелёный коридор” вместо отключения

Тесты не выключаем полностью.

* Должен существовать минимальный набор `test:smoke`, который **всегда зелёный**.
* Всё, что падает и пока не лечим — помечаем `@group legacy` (или переносим в `tests/Legacy`).

Правило PR:

* любой PR должен сохранять зелёным `test:smoke`.

---

## 7) Итерации рефакторинга (как мы двигаем код)

Только маленькими шагами:

* **1 PR = 1 атомарное изменение**

  * один модуль
  * или один перенос/правка Twig
  * или фиксация конфигов тестов
* В PR обязательно:

  * список затронутых путей
  * команда проверки (smoke)

Запрещено:

* “массовый перенос 200 файлов” без smoke и без фиксирования правил.

---

## 8) Definition of Done для рефакторинг-PR

PR считается успешным, если:

* `composer test:smoke` проходит
* маршруты/страницы, которые затронули, открываются
* не добавлены новые файлы в `src/Service` / `src/Controller` / `templates/_partials` (legacy места)

---

## 9) Текущие известные проблемы структуры (фиксируем как debt)

* phpunit запускает не все папки тестов (нужно привести suites к фактической структуре)
* в templates есть дубликаты/мусорные файлы (например `_sidebar.html.twig_`)

---
