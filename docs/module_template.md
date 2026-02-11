# Module Development Standard (v2) — Symfony (Ваш Финдир)

Цель: добавлять новую функциональность **только через изолированные модули**, без расползания legacy/shared и без “зоопарка” API/логики.

---

## 0) Базовые принципы

1) **Модуль = граница ответственности**, а не просто папка.
2) **React — острова**, основной UI — Twig + Tabler.
3) **Контроллеры тонкие**, use-case в Application.
4) **Бизнес-правила живут в Domain**, инфраструктура отдельно.
5) **Никаких массовых рефакторов** вне задачи.

---

## 1) Где нельзя писать код

Запрещено добавлять новый код в:
- `src/Service`
- `src/Controller`
- `src/Entity`
- `src/Repository`
- `templates/_partials`

Legacy/shared не расширяем.

---

## 2) Где живёт модуль

Весь код модуля — строго в:
- `src/<ModuleName>/...`

UI-шаблоны:
- `templates/<module_name>/...`
- общие элементы (разрешено): `templates/partials/...`

---

## 3) Слои внутри модуля (минимум)

Обязательная структура:

- `src/<Module>/Controller/` — HTTP вход (тонко)
- `src/<Module>/Application/` — use-cases (оркестрация)
- `src/<Module>/Domain/` — правила (Policy/Validator/Calculator)
- `src/<Module>/Infrastructure/` — Doctrine repos, внешние клиенты, импорты

Дополнительно при необходимости:
- `src/<Module>/Api/Request/` + `Api/Response/` (для JSON API)
- `src/<Module>/Form/` (если web-формы)
- `src/<Module>/Enum/`, `DTO/` (если нужно)

---

## 4) Правило вызовов (поток)

- `Controller → Application → (Domain + Infrastructure)`
- Контроллер **не содержит** бизнес-логики и запросов к БД.
- Domain **не знает** про Symfony/Doctrine/HTTP.
- Infrastructure **не содержит** бизнес-правил.

---

## 5) Интеграция между модулями

Запрещено:
- использовать `Repository` другого модуля напрямую
- строить QueryBuilder на чужих таблицах “изнутри” модуля

Разрешено:
- вызывать публичные сервисы другого модуля через фасад:
    - `src/<OtherModule>/Public/*` (или `Facade/*`)

---

## 6) Entity и зависимости (важно)

- `Entity` и Doctrine mapping находятся в `src/<Module>/Entity/*`
- Связи с core-сущностями допустимы:
    - `Company`, `User` (и другие явно разрешённые core сущности проекта)
- Доменные сущности других модулей **не тянуть** в логику (только через фасады)

---

## 7) Роутинг (разделение зон)

- Web UI: `/<module_name>/...`
- API: `/api/<module_name>/...`
- Backoffice: `/backoffice/...` (отдельная зона/ACL)

Только Symfony attributes routes.

---

## 8) Безопасность и мульти-тенантность

Обязательные требования:
- все операции выполняются **в контексте активной компании**
- доступы/права проверяет backend (Role/Policy/Lock-period)
- фронт **никогда** не источник истины для прав

---

## 9) API контракт (если модуль отдаёт JSON)

Обязательно соблюдать:
- `docs/api/CONTRACT.md` (единые правила money/date/errors)
- запрещено возвращать Doctrine Entity в JSON
- Request/Response DTO обязательны

---

## 10) Логирование и аудит

Для финансовых изменений/документов:
- изменения должны попадать в audit log (по принятому в проекте механизму)
- логика аудита не в контроллере

---

## 11) Тесты (минимальный DoD)

Добавлять тесты в:
- `tests/Unit/<ModuleName>/...`
- `tests/Integration/<ModuleName>/...` (если есть DB/Repo)

Минимум для нового use-case:
- 1 unit test на policy/validator/калькулятор
- 1 integration test на репозиторий (если сложные выборки)

---

## 12) Миграции / фикстуры

Если добавлены новые Entity/поля:
- создать миграцию Doctrine
- миграции должны применяться без ручных шагов

---

## 13) Что запрещено

- Массовые правки вне модуля
- Перенос файлов из других модулей “по пути”
- Изменение существующего API/контрактов без отдельной задачи
- Добавление “общих утилит” в shared/legacy ради удобства

---

## 14) Definition of Done (DoD) для новой фичи в модуле

- `composer test:smoke` проходит
- нет новых файлов в legacy/shared директориях
- контроллеры тонкие, use-case в Application
- бизнес-правила вынесены в Domain
- repos/интеграции — в Infrastructure
- (если API) соблюден `docs/api/CONTRACT.md`
