# Multi-Tenant архитектура (single DB, company_id)

## Что уже реализовано

- **Сущности и доступы**
    - `Company`, `User`, `UserCompany` (роль, доступ).
    - Переключатель активной компании в UI.
    - `CompanyContextService` — источник текущей компании.

- **Финансовые сущности**
    - `MoneyAccount`, `CashTransaction`, `Counterparty` и др. с полем `company_id`.

- **Коммуникации**
    - `TelegramBot`, `Client`, `Message` — поддержка нескольких ботов в рамках компании.
    - Webhook → Redis → Socket.IO → Chat Center.

- **API и авторизация**
    - Session-based auth, `ROLE_USER`.
    - Часть API ограничена активной компанией через контроллеры/сервисы.

- **Инфраструктура**
    - Docker + Traefik + HTTPS.
    - Redis Pub/Sub для real-time.
    - Продакшен-домен и вебхуки.

---

## Что необходимо доработать

### 1. Изоляция данных
- Включить **Row-Level Security (RLS)** во всех бизнес-таблицах.
- Настроить `current_setting('app.current_company')`.
- Политики `USING` и `WITH CHECK` на `company_id`.

### 2. Doctrine-фильтр
- Добавить `CompanyFilter` (см. `src/Doctrine/Filter/CompanyFilter.php`).
- Включать фильтр на каждый запрос (`kernel.request`).
- В воркерах — bootstrap для установки фильтра.

### 3. Единообразие схемы БД
- Убедиться, что **во всех таблицах** есть поле `company_id UUID NOT NULL`.
- Индексы `btree(company_id)` + составные `(company_id, created_at)` и т.п.
- Пересобрать уникальные ключи как `(company_id, external_id)`.

### 4. Сервисный слой
- В create-операциях `company_id` проставляется только из `CompanyContextService`.
- Не принимать `company_id` от клиента.
- Unit-тесты: проверка автопроставления.

### 5. Валидации и права
- Constraint «unique-in-company».
- Централизованная проверка ролей в `UserCompany(role)`.

### 6. Фоновые задания
- В начале джобы выставлять `CompanyContext` + Doctrine-фильтр + `set_config`.
- Внешние интеграции хранить как `(company_id, provider, account_id)`.

### 7. Кэш / Очереди / Файлы
- Ключи Redis: `company:{uuid}:...`.
- Routing-keys: `jobs.company.{uuid}....`.
- Файлы: `/companies/{uuid}/...`.

### 8. Аудит
- Таблица `audit_log(company_id, user_id, entity, entity_id, action, changes, occurred_at)`.

### 9. Тестовые данные
- Починить фикстуры (ошибка `hasReference()`).
- Добавить минимум 2 компании для проверки изоляции.

### 10. Документация
- Runbook: где ставится `app.current_company`, как дебажить RLS и фильтр Doctrine.
- Памятка по созданию миграций (`company_id`, индекс, политика).

---

## План внедрения

1. Добавить RLS + RequestListener (установка `set_config`).
2. Подключить Doctrine `CompanyFilter`.
3. Миграции: добавить `company_id`, индексы, уникальные ключи.
4. Sweep репозиториев — убрать выборки без скоупа.
5. Bootstrap для воркеров/cron.
6. Constraint на уникальность в пределах компании.
7. Префиксы кэш/очереди/файлы через хелпер.
8. Добавить `audit_log`.
9. Написать интеграционные тесты на изоляцию.
10. Зафиксировать всё в документации.

---
