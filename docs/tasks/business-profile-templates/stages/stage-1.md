# Stage 1. Профиль бизнеса в Company

Риск: HIGH-LOCAL (миграция `companies`, форма регистрации — публичный вход).
`stage_base_commit`: `3b648386b`.

## 1. Какую задачу решаем

У компании нет признака «направление бизнеса», поэтому онбординг не может выбрать
шаблоны. Нужно хранить профиль, спрашивать его при регистрации и передавать подписчикам
`CompanyCreatedEvent`. Поведение онбординга на этом этапе не меняется: шаблоны по профилю —
Stage 2.

## 2. Что будет результатом

- `App\Company\Enum\BusinessProfile`: `MARKETPLACE` («Маркетплейсы и e-com»),
  `UNIVERSAL` («Базовый»); `label()`, `description()`, `DEFAULT = MARKETPLACE`.
- Миграция `companies.business_profile VARCHAR(32) NOT NULL DEFAULT 'MARKETPLACE'`;
  обратимая; на PostgreSQL 15 `ADD COLUMN … DEFAULT` константой не перезаписывает таблицу.
- `Company`: поле (умолчание `MARKETPLACE`), `getBusinessProfile()`, `setBusinessProfile()`.
- `CompanyCreatedEvent(companyId, businessProfile)`; оба места диспатча передают профиль
  компании.
- Пути создания:
  - регистрация: поле `businessProfile` в `RegistrationFormType` (без `is_invite`), радио-
    карточки в `security/register.html.twig`, значение по умолчанию выбрано;
    `CreateCompanyOwnerAccountAction(…, BusinessProfile $businessProfile = DEFAULT)`;
  - `/company/new`: поле в `CompanyType` только при создании (опция формы), в
    `company/new.html.twig`; редактирование профиль не показывает (смена — Stage 4);
  - админка: поле в `AdminAccountCreateType`, `CreateAccountAction` и
    `CompanyFacade::createOwnerAccount(…, BusinessProfile $businessProfile = DEFAULT)`.
- Тесты: регистрация с `UNIVERSAL` сохраняет профиль; без выбора — `MARKETPLACE`;
  приглашение — без поля; `/company/new` и админка сохраняют выбор; событие несёт профиль;
  невалидное значение — ошибка формы.
- `ARCHITECTURE.md`: Company — поле, событие; `Changelog`.

## 3. Что считаем выполненной работой

Компания, созданная любым путём, хранит выбранный профиль (по умолчанию `MARKETPLACE`),
регистрация показывает вопрос «Как вы продаёте?», существующие компании после миграции —
`MARKETPLACE`, подписчики события получают профиль; тесты Company и Admin зелёные,
миграция проходит на пустой и на заполненной БД и откатывается.

## 4. Чек-лист приёмки

- [x] Enum с двумя значениями, подписи и описания на русском.
- [x] Миграция: up на БД с компаниями → у всех `MARKETPLACE`; down → колонки нет; up снова.
- [x] Регистрация: вопрос с двумя карточками, выбрано «Маркетплейсы и e-com»; `UNIVERSAL` сохраняется.
- [x] Регистрация по приглашению — без вопроса, компания не создаётся.
- [x] `/company/new` — поле есть и сохраняется; `/company/{id}/edit` — поля нет.
- [x] Админка — поле есть и сохраняется; без значения — `MARKETPLACE`.
- [x] `CompanyCreatedEvent::$businessProfile` заполнен в обоих местах диспатча.
- [x] Невалидное значение профиля → ошибка формы, компания не создаётся; без значения → `MARKETPLACE`.
- [x] Тесты Company и Admin зелёные; PHPStan и CS по изменённым файлам чистые.
- [x] `ARCHITECTURE.md` обновлён.

## Итог

Выполнено 2026-10-20.

- `BusinessProfile` (enum, `DEFAULT`), `Company::$businessProfile`, миграция
  `Version20261020180000` (`ADD COLUMN … DEFAULT 'MARKETPLACE' NOT NULL`, обратимая).
- `CompanyCreatedEvent(companyId, businessProfile)`; `CreateCompanyOwnerAccountAction`,
  `CompanyFacade::createOwnerAccount()`, `Admin\CreateAccountAction` принимают профиль
  (по умолчанию `DEFAULT`).
- Общий тип поля `Company\Form\BusinessProfileType` (EnumType, радио; `empty_data` = `DEFAULT`).
  Отклонение от плана: значение без выбора не ошибка, а `MARKETPLACE` — иначе страница
  регистрации, открытая до деплоя, отправляла бы форму без поля и получала ошибку.
- Вёрстка: регистрация — карточки на `btn-check` в стиле страницы (Bootstrap); модалка админки —
  `radio-group`/`radio` кита; `/company/new` — `form_row` (Tabler). Скриншоты регистрации,
  десктоп 1280 и мобильный 390, проверены: карточки рядом / одна под другой, выбранная выделена.
- Миграция на `app_test_onb`: компания до миграции → `MARKETPLACE=1`; down → колонки нет,
  строк 1; up снова → `MARKETPLACE=1`.
- Тесты: `BusinessProfileOnCreationTest` (8: вопрос и умолчание, сохранение `UNIVERSAL`, форма без
  поля → `MARKETPLACE`, неизвестное значение → 422 без компании, приглашение без вопроса,
  `/company/new` сохраняет и редактирование не показывает, админка, создание без выбора);
  `CompanyOnboardingTest::testEventCarriesChosenProfile`.
- Проверки: `phpunit tests/{Functional,Unit,Integration}/Company tests/{Unit,Functional}/Admin` →
  OK (772 tests, 2085 assertions); PHPStan и php-cs-fixer по 16 изменённым файлам чистые.
- Ревью: BLOCKER/IMPORTANT нет.
