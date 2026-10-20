# Stage 2. Реестр шаблонов по профилю

Риск: MEDIUM (выбор шаблонов онбординга; содержимое шаблонов и поведение для
`MARKETPLACE` не меняются). `stage_base_commit`: `9494a45a3`.

## 1. Какую задачу решаем

Профиль хранится (Stage 1), но онбординг читает по одному фиксированному файлу:
`config/finance/default_pl_tree.json` и `config/balance/default_structure.yaml`. Нужен
реестр «профиль → шаблон», чтобы Stage 3 добавил универсальные шаблоны одной строкой
конфигурации, и гарантия, что одинаковые коды значат одно и то же во всех шаблонах.

Универсальных шаблонов ещё нет (содержание утверждает Владелец, Stage 3). До них
`UNIVERSAL` явно указывает на маркетплейсные файлы — так же, как сейчас получает любая
компания. Это запись в реестре, а не скрытый fallback в коде.

## 2. Что будет результатом

- Файлы переезжают без изменения содержимого:
  `config/finance/pl_templates/marketplace.json`, `config/balance/templates/marketplace.yaml`;
  в шаблонах — поле `profile` (`MARKETPLACE`), в ОПиУ ещё `templateVersion: 1` (поле `version`
  там — версия формата обмена).
- Реестр в `config/services.yaml`: параметры `finance.pl_templates` и `balance.structure_templates`
  (профиль → путь); для `UNIVERSAL` — маркетплейсные файлы с комментарием «до Stage 3».
- Finance: `DefaultPLCategoryTreeProvider::nodes(BusinessProfile)`; `EnsureDefaultPLTreeAction(companyId,
  dryRun, ?BusinessProfile)`; экран «Базовый шаблон ОПиУ» и `pl-tree:apply-default` берут шаблон
  профиля компании; INFO-лог применения (companyId, профиль, `templateVersion`, создано).
- Balance: `BalanceStructureTemplateReader::read(BusinessProfile)` проверяет, что `profile` в
  файле совпадает с запрошенным; `SeedBalanceStructureAction` и `EnsureBalanceAccountsAction`
  принимают `?BusinessProfile` (null — профиль компании); аудит сида пишет шаблон и версию.
- `CompanyFacade::getBusinessProfile(string $companyId): BusinessProfile` (+ `CompanyNotFoundException`).
- Подписчики `CompanyCreatedEvent` передают профиль из события; `CompanyOnboardingBackfillEvent`
  несёт профиль компании.
- Тест словаря кодов: реестр покрывает все `BusinessProfile`, файлы валидны и объявляют свой
  профиль; код ОПиУ во всех шаблонах — те же `type`, `flow`, `format`, код родителя; код Баланса —
  те же `type`, `kind`, код родителя; счёт — та же статья.
- Регрессия: `MARKETPLACE` и `UNIVERSAL` получают прежний набор (70 статей ОПиУ, 33 статьи и
  2 счёта Баланса).
- `ARCHITECTURE.md`: Finance, Balance, «Онбординг компании»; `Changelog`.

## 3. Что считаем выполненной работой

Онбординг, экран базового шаблона, ленивый сид, ручные команды и дозаполнение выбирают шаблон
по профилю компании через реестр; добавление шаблона профиля — новый файл и строка в реестре;
тест словаря кодов в CI; поведение для всех компаний сейчас прежнее; тесты Finance, Balance,
Company зелёные.

## 4. Чек-лист приёмки

- [x] Старых путей `default_pl_tree.json` / `default_structure.yaml` в коде и конфигурации нет.
- [x] Реестр: оба профиля, файлы существуют; профиль в файле — свой или `MARKETPLACE` (тест реестра).
- [x] Новая компания `MARKETPLACE` и `UNIVERSAL` → 70 статей ОПиУ, 33 статьи и 2 счёта Баланса.
- [x] Ленивый сид, `seedDefaultStructure`, `app:balance:seed-missing-structure`, фикстуры — шаблон профиля компании.
- [x] «Базовый шаблон ОПиУ» и `pl-tree:apply-default` — шаблон профиля компании.
- [x] Дозаполнение — по профилю каждой компании.
- [x] `CompanyFacade::getBusinessProfile()` — тест, неизвестная компания → `CompanyNotFoundException`.
- [x] Тест словаря кодов зелёный и краснеет на рассогласованном коде (проверено на временной правке).
- [x] Лог/аудит применения содержит профиль и версию шаблона.
- [x] `tests/*/Finance`, `tests/*/Balance`, `tests/*/Company`, `tests/*/Cash` зелёные; PHPStan и CS по изменённым файлам чистые.
- [x] `ARCHITECTURE.md` обновлён.

## Итог

Выполнено 2026-10-20.

- Файлы перенесены `git mv`: `config/finance/pl_templates/marketplace.json` (+ `profile`,
  `templateVersion: 1`), `config/balance/templates/marketplace.yaml` (+ `profile`); содержимое
  статей не менялось.
- Реестры `finance.pl_templates`, `balance.structure_templates` в `config/services.yaml`;
  `UNIVERSAL` явно указывает на маркетплейсные файлы до Stage 3.
- Finance: `DefaultPLCategoryTreeProvider::nodes(BusinessProfile)`, `templateVersion()`;
  `EnsureDefaultPLTreeAction(companyId, dryRun, ?profile)` + INFO-лог (профиль, версия, создано);
  экран «Базовый шаблон ОПиУ» и `pl-tree:apply-default` — шаблон профиля компании.
- Balance: `BalanceStructureTemplateReader::read(BusinessProfile)`; `SeedBalanceStructureAction` и
  `EnsureBalanceAccountsAction` принимают `?BusinessProfile`, иначе — `CompanyFacade::getBusinessProfile()`;
  аудит сида — `{template, version}`; фикстуры передают профиль явно (компания ещё не сохранена).
- Company: `CompanyFacade::getBusinessProfile()`, `CompanyNotFoundException`,
  `CompanyRepository::getAllActiveCompanyProfiles()` (один запрос для дозаполнения);
  `CompanyOnboardingBackfillEvent` несёт профиль.
- Отклонение от плана: проверку «профиль в файле = запрошенный» при чтении Баланса убрал — она
  конфликтует с явной записью `UNIVERSAL → marketplace.yaml`; согласие реестра и файлов держит тест.
- Тесты: `tests/Integration/Onboarding/TemplateRegistryTest` (5: реестр покрывает профили и файлы
  существуют; профиль в файле; словарь кодов ОПиУ; словарь кодов Баланса; проверка самой
  проверки). На временной правке (UNIVERSAL → копия с `REV_TOTAL` SUBTOTAL) тест краснеет:
  `REV_TOTAL: marketplace.json KPI/NONE/MONEY/-, tmp-universal.json SUBTOTAL/NONE/MONEY/-`.
  Регрессия `UNIVERSAL` → прежний набор (`CompanyOnboardingTest`); `CompanyFacadeBusinessProfileTest`;
  аудит сида с шаблоном; профиль в событии дозаполнения; провайдер ОПиУ — профиль вне реестра и
  `templateVersion`; читатель Баланса — профиль вне реестра.
- Проверки: `phpunit tests/{Unit,Integration,Functional}/{Finance,Balance,Company} tests/Integration/Cash
  tests/{Unit,Functional}/Admin tests/Integration/Onboarding` → OK (1589 tests, 7618 assertions);
  PHPStan и CS по 27 изменённым файлам чистые; `lint:container` OK.
- Ревью: BLOCKER/IMPORTANT нет.
