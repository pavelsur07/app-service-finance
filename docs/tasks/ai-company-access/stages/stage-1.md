# Этап 1. Доступ компании к ИИ — в БД

## Задача
Убрать список компаний из env: включение должно меняться без деплоя.

## Результат
- Таблица `ai_company_access` (модуль ServiceGateway): строка — ИИ компании включён; unique
  `company_id`, кто и когда включил.
- `AiServiceAccess::isEnabledForCompany()` = `AI_SERVICE_ENABLED` + URL + токен + строка в таблице;
  пакетный `enabledCompanyIds()` для cron и админки (без N+1).
- Миграция создаёт таблицу и переносит Вумджой (если компания есть) — ИИ у неё не прерывается.
- `AI_SERVICE_COMPANY_ALLOWLIST` удалён из кода, `.env`, `docker-compose.prod.yml`,
  `deploy.yml`, документации.

## Что считаем выполненным
Код и миграция в ветке `feat/ai-company-access`, тесты зелёные, `ARCHITECTURE.md` обновлён.

## Чек-лист приёмки
- [x] миграция up/down на тестовой БД, схема в синхроне (`SchemaInSyncWithMigrationsTest`)
- [x] перенос Вумджой идемпотентен, на БД без компании не падает
- [x] unit `AiServiceAccessTest`: выключатель, нет строки, есть строка, пакетная выборка
- [x] cron `ai-categorize --all-enabled` берёт компании одним запросом
- [x] `grep -r AI_SERVICE_COMPANY_ALLOWLIST` пуст вне истории задач
- [x] PHPStan, CS, strict_types зелёные; baseline не вырос

## Итог
Выполнено 05.11.2026, коммит в PR #2891. Отклонение от плана: отдельного Repository нет —
`AiServiceAccess` читает таблицу через DBAL (стаб final-репозитория в unit-тестах ломал PHPStan),
Entity — только для схемы. Миграция `Version20261105120000` down/up локально; PHPStan без новых
ошибок; CI #2891 зелёный (unit, integration, functional, static, migrations-empty-db).
