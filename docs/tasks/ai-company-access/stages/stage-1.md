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
- [ ] миграция up/down на тестовой БД, схема в синхроне (`SchemaInSyncWithMigrationsTest`)
- [ ] перенос Вумджой идемпотентен, на БД без компании не падает
- [ ] unit `AiServiceAccessTest`: выключатель, нет строки, есть строка, пакетная выборка
- [ ] cron `ai-categorize --all-enabled` берёт компании одним запросом
- [ ] `grep -r AI_SERVICE_COMPANY_ALLOWLIST` пуст вне истории задач
- [ ] PHPStan, CS, strict_types зелёные; baseline не вырос

## Итог
_дописывается после выполнения_
