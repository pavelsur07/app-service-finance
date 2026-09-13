# Управление API — этапы 0–2

Источник: утверждённый владельцем план в чате, 2026-09-13. Класс Large.
Создать модуль Api и «Настройки → API» на Twig + Symfony Forms + существующем UI Kit.

## Контракт
- Постоянный публичный числовой ID компании (пример 100025), внутренний UUID сохраняется. PostgreSQL sequence с 100000, unique, backfill существующих, DB default для старых сценариев создания; номера не переиспользуются.
- Несколько независимых именованных Bearer-ключей на компанию. Управление только фактическим владельцем соответствующей компании, проверяется внутри Actions, включая случай владелец A / участник B.
- UUID v7; companyId; открытый уникальный key identifier; 32 случайных байта секрета, хранится SHA-256, constant-time comparison. Имя trim 1–255 символов. UTC срок ровно 90 суток, now >= expiresAt недействителен. Переименование/права не продлевают. Отзыв необратим и идемпотентен.
- Секрет показывается лишь в непосредственном ответе создания, no-store, копирование. Никаких URL/flash/log/localStorage. UI мутации CSRF. Списки scoped активной компанией, пагинация.
- Отдельные Actions create/rename/revoke/save permissions. Явный Shared AuditLog с allowlist полей и атомарной транзакцией; без секрета/хеша; без событий при no-op.
- Stateless firewall /api/external/v1/, GET auth/check. Только Authorization: Bearer; X-Company-Id обязателен. Отдельный principal и доверенный контекст, без ActiveCompanyService/сессии/входа от имени автора. Bootstrap поиск по public key identifier документирован; после него companyId обязателен.
- auth/check: public company ID, key UUID, expiresAt, prepared/effective scopes. Без финансов/секрета, no-store. Problem Details: 401 invalid/missing key + WWW-Authenticate Bearer; 403 company mismatch/permissions; 422 invalid required parameter; 429 rate limit. 60/min/key + отдельный failed-auth IP limiter. Без долговременного кеша отзыва/прав.
- UI: public ID + curl, список name/mask/expiry/last-used/status, create/rename/check/revoke, empty/errors/mobile. Встроенная проверка вставленного ключа тем же validator; ключ не сохраняется.
- Каталог явных scopes: accounts, counterparties, cash categories, P&L categories, projects, responsibility centers — read; cash transactions и P&L operations — read/create/update/soft_delete; cashflow и P&L reports — read. Справочники не получают write.
- selected scopes + отдельно enabled resources. Effective = selected ∩ connected ∩ owner-enabled. Все предметные ресурсы сейчас disconnected. Подключение в будущем не включает существующим ключам доступ; новые scopes не выдаются автоматически. Пресеты сохраняют конкретные scopes, wildcard запрещён. Connection check не требует предметных scopes. Missing policy/scope deny; тестовые endpoints только test env.
- Матрица разрешает подготовку и показывает «Права подготовлены. Доступ появится после подключения раздела и вашего включения». Save action: owner+audit+optimistic version; конфликт 409 с перечитыванием.
- Старые report keys/выгрузки без изменения контрактов, новые ключи старые маршруты не принимают.

Финансовые endpoints, подготовка предметных модулей, бейджи и история финансовых операций — отдельная задача. README фиксирует будущий контракт каналов/аудита.

## Обязательные проверки
Уникальность/конкурентная выдача public IDs и backfill; несколько ключей; граница 90 дней; отзыв; stateless cookies; tenant spoofing; owner A/member B; одноразовый секрет и отсутствие secret/hash в списке и аудите; prepared ineffective и future-connect не активирует; scopes независимы и неизвестные запрещены; атомарный rollback audit; concurrent version conflict; legacy exports; CSRF/empty/errors/mobile/reopen secret response.

## Доставка
4 технических Stage из plan.md. Внешний review HIGH-LOCAL Stage >500 строк и handoff. Full gates при handoff: stan, cs-check, strict-types, unit, full tests + UI. Независимый internal review в свежем контексте. Одна Draft PR, Ready на handoff. Production migration dispatch и merge+deploy требуют совместного запроса по release.md. Никаких production действий до этого разрешения.
