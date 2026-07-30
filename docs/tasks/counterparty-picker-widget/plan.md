# Выбор контрагента в legacy Twig/Symfony UI — план (Phase 0)

Источник задачи: решение Владельца 30.07.2026 — UI-выбор контрагента вынесен из
задачи `counterparty-name-normalization` отдельной задачей, подход — Symfony form
widget для legacy-страниц. Бриф: `docs/tasks/counterparty-picker-widget/TASK.md`.

Task id: `counterparty-picker-widget`
Base commit: `3cec3648d6ac480b86c88c7993eb54bc889b96d6` (master, Stage 1–4 нормализации задеплоены)
Модуль: `App\Company` (виджет и фасад), затрагиваются `Cash`, `Finance`, `Deals`.

---

## 0. Прецедент в проекте, на который опираемся

В проекте **уже есть** ровно такой виджет для другой сущности, и он используется
четырьмя формами. Не изобретаем структуру — повторяем её:

| Часть | Файл прецедента |
|---|---|
| FormType | `src/Shared/Form/Type/ProjectDirectionPickerType.php` — расширяет `EntityType`, свой `getBlockPrefix()` |
| Twig-тема | `templates/form/project_direction_picker_theme.html.twig` — блок `project_direction_picker_widget` |
| Регистрация | `config/packages/twig.yaml` → `form_themes` |
| JS | `assets/project_direction_picker.js` (320 строк), подключён через `import` в `assets/legacy-app.js` |
| Потребители | `DocumentType`, `DocumentOperationType`, `CashTransactionType`, `CashTransactionAutoRuleType` |

Что берём: расположение файлов, способ регистрации темы, подключение JS через
`legacy-app.js`, приём «держать настоящий `<select>` в DOM скрытым, чтобы
существующие скрипты, читающие `.value`, продолжали работать».

Что делаем иначе — и почему:

1. **Не грузим весь справочник в разметку.** Прецедент рендерит всё дерево проектов в
   модалку. Для контрагентов это тот самый «весь справочник в choices», от которого
   уходим: поиск идёт запросом к `GET /api/counterparties/search` (готов, Stage 3).
2. **Тесты обязательны.** У прецедента их нет (`grep` по `tests` пустой). Здесь без
   functional-теста на подстановку чужого UUID задача не закрывается — это граница IDOR.
3. **`companyId` — обязательная опция**, а не `null` по умолчанию (см. §2, дефект D-1).

---

## 1. Что проверено в коде до планирования

| # | Факт | Где | Следствие |
|---|---|---|---|
| 1 | Выбор контрагента разбросан по 5 FormType и 2 контроллерным спискам, каждый получает данные по-своему | `CashTransactionType:87` (ChoiceType+репозиторий), `DocumentType:59` и `DocumentOperationType:54` (EntityType, choices опцией), `CreateDealType:45` и `PaymentPlanType:76` (EntityType+query_builder), `CashTransactionController:63`, `CashTransactionAutoRuleController:145,209` | Виджет заменяет пять реализаций одной |
| 2 | `EntityType` с чужой Entity — в 4 формах из 5 | там же | Прямой запрет `CLAUDE.md`. Именно из-за него изменение конструктора `Counterparty` в прошлой задаче задело четыре модуля |
| 3 | **Tenant-фильтр fail-open** | `CreateDealType:55`, `PaymentPlanType:86`: `if ($company) { $qb->andWhere(...) }`, при `'company' => null` в `configureOptions` | Дефект D-1, см. §2 |
| 4 | `CashTransactionType` пишет значение вручную через слушатель `FormEvents::SUBMIT` при `mapped => false` | `CashTransactionType:96-112` | Виджет с нормальным маппингом убирает слушатель |
| 5 | Endpoint поиска готов и покрыт тестами | `src/Company/Controller/Api/CounterpartySearchController.php`, `CounterpartySearchQuery` | Серверная часть виджета уже есть, писать не нужно |
| 6 | `CounterpartyRepository::findSelectableByCompany($companyId, $keepId)` уже реализован: архивные скрыты, выбранный архивный остаётся | `src/Company/Repository/CounterpartyRepository.php` | Правило отображения не переизобретаем |
| 7 | `CompanyFacade::findCounterpartyByIdAndCompany()` существует | `src/Company/Facade/CompanyFacade.php` | Готовая проверка принадлежности для валидации выбранного id |
| 8 | `CounterpartyFacade` отсутствует, репозиторий импортируется напрямую из 7 файлов `Cash`/`Finance`/`Deals` | `ARCHITECTURE.md` §«Справочник контрагентов» | Виджет — естественный момент завести фасад: через него пойдут все формы |
| 9 | Экраны — legacy Tabler: `base.html.twig` → `_layout/legacy.html.twig`, Tabler JS с CDN; на UI-Kit-лэйауте 1 шаблон из 113 | `templates/_layout/legacy.html.twig` | Разметка виджета — Tabler/Bootstrap 5, как у прецедента, а не UI Kit |
| 10 | Stimulus-контроллеров 4, ни один не делает HTTP-запросов; React-острова только страничные | `assets/controllers/`, `assets/react/_legacy/` | JS виджета — обычный ES-модуль рядом с `project_direction_picker.js`, а не Stimulus и не React-остров |
| 11 | `CLAUDE.frontend.md:19` требует React-остров для логики с запросами к API | — | Отступление согласовано Владельцем для этой задачи; фиксируется в Stage Report |
| 12 | На PROD максимум 128 контрагентов на компанию, всего 317 | замер 30.07.2026 | Виджет нужен ради единообразия и закрытия дефектов, не ради производительности. Поэтому Stage 1 даёт работающий выбор **без** автокомплита, а поиск подключается в Stage 3 |

---

## 2. Дефект, который задача обязана закрыть

**D-1. Tenant-фильтр под условием.**

```php
// src/Deals/Form/CreateDealType.php:51-61, то же в PaymentPlanType:82-92
'query_builder' => static function (EntityRepository $repository) use ($company) {
    $qb = $repository->createQueryBuilder('counterparty')->orderBy('counterparty.name', 'ASC');
    if ($company) {                          // ← fail-open
        $qb->andWhere('counterparty.company = :company')->setParameter('company', $company);
    }
    return $qb;
},
```

`configureOptions` объявляет `'company' => null` и разрешает `null`. Сегодня оба
вызывающих контроллера компанию передают, так что утечки нет. Но забыть опцию — в
новом контроллере, во вложенной форме, в тесте — и дропдаун покажет контрагентов всех
компаний, а `EntityType` примет любой из этих id на submit.

Виджет закрывает это конструктивно: `company_id` объявляется через
`setRequired()`, поэтому форма без него не собирается, а единственный запрос за
списком живёт в одном методе репозитория.

---

## 3. Решения Владельца

**D2 — ЗАКРЫТО** (30.07.2026): Symfony form widget для legacy-страниц. React-остров и
одноразовый Stimulus в шаблоне не рассматриваются.

**Открыто, не блокирует Stage 1:**

- **W-1. Порядок вариантов.** Сортировать по названию (как сейчас) или сначала
  недавно использованные? «Недавние» полезнее fuzzy-поиска — в 90% случаев выбирают
  одного из десятка постоянных контрагентов. Данные есть (`cash_transaction.counterparty_id`,
  `documents.counterparty_id`), но это cross-module запрос через фасад. Дефолт, если
  промолчите: сортировка по названию, «Недавние» — отдельной задачей.
- **W-2. Создание контрагента из пикера.** Ссылка на форму создания (дёшево) или
  inline-создание в модалке (дороже, нужен POST-endpoint и обработка дубля ИНН).
  Дефолт: ссылка.

---

## 4. Stage 1: виджет и фасад — работающая замена одного `<select>`

Risk: 🟡 MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `3cec3648d6ac480b86c88c7993eb54bc889b96d6`

Definition of Done:
- `CounterpartyPickerType` рендерится Tabler-разметкой и работает в форме операции ДДС
  вместо текущего `ChoiceType`.
- `company_id` — обязательная опция типа `string`; форма без неё не собирается.
- Выбранное значение приходит в DTO как UUID-строка без ручного слушателя `SUBMIT`.
- Серверная валидация: id, не принадлежащий активной компании, даёт ошибку поля,
  а не 500 и не тихий `null`.
- Архивные не предлагаются, уже выбранный архивный остаётся видимым.
- В строке варианта — название и ИНН.
- Тесты: functional на форму транзакции (валидный id сохраняется, чужой отклоняется,
  пустое значение допустимо), unit на FormType (отсутствие `company_id` → исключение).

Work items:
- 1.1 — `CounterpartyFacade` (`src/Company/Facade/`) + `CounterpartyChoiceDTO`
  (id, name, inn, kpp, isArchived): `getSelectable(string $companyId, ?string $keepId)`,
  `findByIdAndCompany(string $id, string $companyId)`. Внутри — существующие
  `findSelectableByCompany()` и проверка принадлежности. Обновить `ARCHITECTURE.md`.
- 1.2 — `src/Company/Form/Type/CounterpartyPickerType.php`: `ChoiceType` с choices из
  фасада (DTO, не Entity), `choice_value` = id, `getBlockPrefix() = 'counterparty_picker'`,
  `setRequired('company_id')`, опции `keep_id`, `placeholder`, `allow_create`.
- 1.3 — `templates/form/counterparty_picker_theme.html.twig` + регистрация в
  `twig.yaml → form_themes`. Скрытый настоящий `<select>` в DOM — как у прецедента,
  чтобы не поломать существующие скрипты, читающие `.value`.
- 1.4 — `CashTransactionType`: `ChoiceType` → `CounterpartyPickerType`, удаление
  ручного маппинга контрагента из слушателя `SUBMIT`; серверная валидация выбранного id.
- 1.5 — тесты.

Stage checks: `make site-test`, `make site-cs-check`, `npm run lint`, `npm run build`,
ручной smoke формы операции ДДС (создание и правка, в том числе с архивным контрагентом).

Reviewer focus: обязательность `company_id`; отсутствие Entity в choices; поведение при
ошибке валидации (значение не теряется); что удаление слушателя не сломало остальные
поля формы.

---

## 5. Stage 2: перевод остальных форм и снятие дефекта D-1

Risk: 🟠 HIGH-LOCAL (правится legacy-код четырёх модулей, включая закрытие fail-open)
owner_gate: no
release_candidate: yes
independently_deployable: yes

Definition of Done:
- `DocumentType`, `DocumentOperationType`, `CreateDealType`, `PaymentPlanType`,
  `CashTransactionAutoRuleType` используют `CounterpartyPickerType`.
- `EntityType` с `Counterparty` не встречается в кодовой базе (grep пустой).
- Прямые импорты `CounterpartyRepository` из `Cash`, `Finance`, `Deals` в формах
  заменены на фасад.
- Fail-open `query_builder` удалён вместе с опцией `'company' => null`.
- Регрессионные тесты на D-1: форма без `company_id` не собирается; подстановка
  чужого UUID отклоняется в каждой переведённой форме.

Work items:
- 2.1 — `Finance`: `DocumentType`, `DocumentOperationType` (choices приходили опцией —
  проверить всех вызывающих контроллеров).
- 2.2 — `Deals`: `CreateDealType`, снятие fail-open.
- 2.3 — `Cash`: `PaymentPlanType`, `CashTransactionAutoRuleType`, снятие fail-open.
- 2.4 — регрессионные тесты по каждой форме.

Stage checks: `make site-test`, `make site-cs-check`, smoke каждого экрана.

Reviewer focus: ни одна форма не потеряла существующее поведение (placeholder,
`required`, сохранение выбора при ошибке); tenant-фильтр больше не под условием.

---

## 6. Stage 3: серверный поиск в виджете

Risk: 🟡 MEDIUM
owner_gate: yes (Release Gate)
release_candidate: yes
independently_deployable: yes

Выполняется, только если Владелец подтвердит, что нужен автокомплит: при 128
контрагентах нативный `<select>` уже закрывает задачу.

Definition of Done:
- `assets/counterparty_picker.js` (рядом с `project_direction_picker.js`, подключение
  через `legacy-app.js`): debounce 250 мс, `AbortController` на предыдущий запрос,
  `q` короче 2 символов не отправляется, навигация ↑/↓/Enter/Esc.
- Запрос идёт в существующий `GET /api/counterparties/search`; новых endpoint'ов нет.
- Без JS виджет остаётся работоспособным: скрытый `<select>` с предзагруженными
  вариантами — это и есть fallback, отдельного не делаем.
- В Network один запрос с `q`, а не выгрузка справочника.

Work items:
- 3.1 — JS виджета.
- 3.2 — переключение темы виджета в режим поиска при доступном JS.
- 3.3 — `npm run lint`, `npm run build`, ручной smoke на двух экранах.

Reviewer focus: отсутствие новых npm-зависимостей; отмена предыдущего запроса;
поведение при пустом ответе и при 500 от endpoint'а.

---

## 7. Карта изменений

| Слой | Файл | Действие |
|---|---|---|
| Facade | `src/Company/Facade/CounterpartyFacade.php` | new |
| DTO | `src/Company/Facade/DTO/CounterpartyChoiceDTO.php` | new |
| FormType | `src/Company/Form/Type/CounterpartyPickerType.php` | new |
| Twig | `templates/form/counterparty_picker_theme.html.twig` | new |
| Config | `config/packages/twig.yaml` (`form_themes`) | modified |
| JS | `assets/counterparty_picker.js`, `assets/legacy-app.js` | new / modified (Stage 3) |
| Формы | `Cash/Form/Transaction/CashTransactionType.php`, `CashTransactionAutoRuleType.php`, `Cash/Form/PaymentPlan/PaymentPlanType.php`, `Finance/Form/DocumentType.php`, `Finance/Form/DocumentOperationType.php`, `Deals/Form/CreateDealType.php` | modified |
| Контроллеры | `Cash/Controller/Transaction/CashTransactionController.php`, `CashTransactionAutoRuleController.php`, `Deals/Controller/DealController.php`, `Cash/Controller/PaymentPlan/PaymentCalendarController.php`, контроллеры документов | modified (передача `company_id`, отказ от списков) |
| Тесты | `tests/Functional/...` по каждой форме, `tests/Unit/Company/CounterpartyPickerTypeTest.php` | new |

## 8. Тесты — минимум

| Что | Тест |
|---|---|
| Новый Facade-метод | functional через вызывающий код + unit на фасад |
| `CounterpartyPickerType` | unit: без `company_id` — исключение; choices не содержат Entity; архивный `keep_id` присутствует |
| Каждая переведённая форма | functional: валидный id сохраняется; **чужой UUID отклоняется**; пустое значение допустимо; выбор не теряется при ошибке валидации другого поля |
| D-1 | регрессионный: форма, собранная без `company_id`, падает, а не отдаёт всех контрагентов |
| JS (Stage 3) | ручной smoke + `npm run lint`; автотестов на JS в проекте нет, новую инфраструктуру не вводим |

## 9. Записи в `ARCHITECTURE.md`

- `CounterpartyFacade` с двумя методами и `CounterpartyChoiceDTO` — в раздел
  «Справочник контрагентов», где сейчас написано, что фасада нет.
- `CounterpartyPickerType` как единственный способ выбора контрагента в формах.
- Отметить, что прямые импорты `CounterpartyRepository` из форм устранены (счётчик
  «7 файлов» уменьшится — обновить формулировку).

## 10. Границы задачи

Не входит: UI-Kit/React-вариант пикера (появится при переезде экранов на UI Kit);
секция «Недавние» (W-1); inline-создание контрагента (W-2); слияние дублей; замена
`<select>` на экранах, которых нет в §7; чистка прямых импортов
`CounterpartyRepository` вне форм (`DealManager`, `CreatePLDocumentAction`,
`FinanceFacade`, `ScoreCompanyCounterpartiesAction`) — отдельная задача.

## 11. Гейты

- Release Gate 1 — после Stage 2 (виджет во всех формах + закрытый D-1;
  самостоятельная ценность, деплоится отдельно).
- Release Gate 2 — после Stage 3, если автокомплит будет согласован.
- Production Gate — не требуется: миграций, backfill и production-операций в задаче нет.
