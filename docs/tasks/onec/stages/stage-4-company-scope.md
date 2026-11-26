# Stage 4 — подключение 1С принадлежит компании

**Задача.** `OneCConnection` принадлежала пользователю (`owner_id`): список показывал
подключения всех компаний владельца, карточка/перевыпуск/отключение/сырьё искались
по владельцу, AuditLog и ModuleAccess — по активной компании, участники компании
подключение не видели, рукопожатие называло все компании владельца. Посылка
«тенант = User» этапа 1 неверна (CLAUDE.md, «Entity» и «IDOR»).

**Результат.**
- `onec_connection.company_id` NOT NULL, FK `companies ON DELETE CASCADE`,
  unique `(company_id, base_id)`; `owner_id` → `created_by_user_id` (аудит, `SET NULL`).
- Миграция `Version20261127090000` берёт компанию из записи CREATE в `audit_log`;
  подключение без однозначной записи — `RAISE EXCEPTION` со списком id.
- `onec_organization.company_id` удалена: организация — компании подключения, привязка — `enabled`.
- `OneCConnectionRepository`: `listForCompany`, `getForCompany`, `lockForCompany`,
  `findRawPart(partId, companyId)`; `findRawPartAnyCompany` — только админка, `@companyScopeExempt`.
- Доступ — модуль `finance` (решение Владельца 26.11, вариант A): чтение `FINANCE_READ`,
  создание/перевыпуск/отключение `FINANCE_WRITE`. Чужое → 404.
- AuditLog — с `companyId` подключения. `CompanyFacade::activeOwnedCompanyNames` удалён.

**Почему гейты не поймали.** `RepositoryCompanyScopeRule` смотрит только классы `*Repository`
(`ConnectionQuery` — нет; та же слепая зона у 148 `Infrastructure/Query/*Query`),
у сущности не было `companyId`, `ControllerUnscopedFindRule` видит только `find()` в контроллере.
Теперь сущность принадлежит компании, репозиторий под правилом (проверено: без
`@companyScopeExempt` правило падает на admin-методе). Расширение правила на `*Query` — отдельный PR с baseline.

**Прод до миграции (26.11).** 2 подключения, оба `pending`, без частей и организаций;
компания в audit_log найдена у обоих: `01a21905…8253` → `b57d7682…9874`,
`01a21913…781e` → `0bf4d699…d174`.

## Чек-лист приёмки
- [x] в OneC нет `ownerId` и `activeOwnedCompanyNames`
- [x] все методы-запросы с `companyId`, кроме admin-метода с `@companyScopeExempt`
- [x] тесты: A/B одного владельца, участник с ролью/без, аудит в A, имена в рукопожатии, база по компании, миграция и сироты
- [x] PHPStan по каталогам, CS, схема в синхроне
- [ ] после деплоя: у обоих подключений `company_id` = компания из audit_log, колонки `owner_id` нет
