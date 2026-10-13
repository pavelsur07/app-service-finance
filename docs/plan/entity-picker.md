# Окно выбора справочника (entity picker): поле формы + пилюля фильтра — план и отчёт

Дата: 2026-10-13 · ветка `feat/entity-picker` · база `18369f97` (#2661)
Поручение — бриф Владельца в чате («переиспользуемое окно выбора „Контрагент“»).
Эталон: `docs/design-system/screens/Operations.dc.html`, поповер «Фильтр по контрагенту».
Компонент: `docs/design-system/components/entity-picker.md`.

**Класс — Small** (один ограниченный фронтовый блок: JS + Twig + CSS; схема, API,
Messenger, auth не меняются). Этапы — по брифу, одним PR. Внешнее ревью §7.2 не
требуется: HIGH-LOCAL-зон нет.

## Stage 0. Инвентаризация

### Где живёт пикер контрагента

| Что | Где |
|---|---|
| Форм-тип | `src/Company/Form/Type/CounterpartyPickerType.php` (block prefix `counterparty_picker`) |
| Тема | `templates/form/counterparty_picker_theme.html.twig` (глобальная, `config/packages/twig.yaml`) |
| JS | `assets/counterparty_picker.js`, подключён в `assets/legacy-app.js` |
| CSS | `assets/styles/legacy-app.css` — `.cp-results`, `.cp-option`, `.cp-mark`, `.cp-loading`, `.cp-selected-mark` |
| API | `GET /api/counterparties/search?q=` → `[{id, name, inn, kpp, type}]`, `q` < 2 → `[]`, ≤ 20, архивные не отдаёт |

Формы с `CounterpartyPickerType` (все с `search_url: '/api/counterparties/search'`):
`CashTransactionType` (`counterpartyId`), `CashTransactionAutoRuleType` (`counterparty`),
`CashTransactionAutoRuleConditionType` (`counterparty`, коллекция — строки добавляются
динамически), `PaymentPlanType`, `Finance/DocumentType`, `Finance/DocumentOperationType`
(коллекция), `Deals/CreateDealType` (`counterpartyId`).

`data-cp-*` читают только тема и `counterparty_picker.js`; тестов на них нет
(`tests/Integration/Company/CounterpartyPickerTypeTest.php` проверяет choices и
трансформер, не разметку). Тема и `data-cp-*` **не меняются** — формы не трогаются.

Пилюля «Контрагент» на «Операциях ДДС» уже есть (#2661, `transaction/_filter_bar.html.twig`):
dropdown Bootstrap со **всем** списком `counterparties` в HTML и локальным фильтром
(скрипт `[data-ops-pop]` в `index.html.twig`). Она заменяется новым видом «пилюля».

### Что общего у двух пикеров

| | `counterparty_picker.js` | `project_direction_picker.js` |
|---|---|---|
| Источник значения | скрытый `<select>` | скрытый `<select>` |
| Выбор | поиск на сервере + портал-список | модалка Bootstrap с деревом, выбор листа |
| Поиск | debounce, `AbortController`, отсев устаревших ответов | локальный фильтр узлов дерева |
| Клавиатура | ↑/↓/Enter/Esc по списку | нет |

Общего у двух пикеров — только «`<select>` — источник значения». Дерево проектов,
раскрытие веток и модалка к комбобоксу отношения не имеют, а API поиска проектов нет.
Поэтому в ядро уходит механика **комбобокса** из `counterparty_picker.js`, а
`project_direction_picker.js` не трогается; когда для проектов появится поиск
(API или плоский список), он подключается к ядру своим адаптером — без копии кода.

### План выноса

```
assets/picker_core.js      ядро: конфиг из data-picker-*, адаптеры, поиск (debounce 250,
                            AbortController, отсев устаревших), listbox (рендер, группы,
                            подсветка через textContent, aria, ↑/↓/Enter), состояния
                            (загрузка/пусто/ошибка + «Повторить»), портал (fixed, вверх/вниз)
assets/picker_field.js     вид «поле»: <select> + input + портал; поведение формы как было
assets/picker_pill.js      вид «пилюля»: кнопка → поповер (dropdown Bootstrap) → ссылки
assets/counterparty_picker.js  привязка вида «поле» к [data-cp-picker] + адаптер counterparty
templates/partials/_filter_pill_picker.html.twig   разметка пилюли
```

### Риски регрессии форм

1. **Портал в модалке/offcanvas** (правило автоправила): список должен оставаться
   поверх модалки (z-index 1080), закрываться по `mousedown` до клика по кнопкам под ним.
2. **Коллекции** (условия автоправила, операции документа): инициализация новых строк
   через `MutationObserver` — сохраняется.
3. **Валидация**: свободный текст без выбора блокирует submit; выбор id вне `choices` →
   `setCustomValidity`; `required`/`disabled` переносятся на видимый input.
4. **Ошибка API в форме** — по-прежнему локальный поиск по `<option>` (no-regression),
   а не экран ошибки: форма должна оставаться заполнимой.
5. Внешние скрипты читают `select.value` и слушают `change` — событие диспатчится как раньше.

## Stage 1–3. Что сделано

- **Stage 1.** Ядро `picker_core.js`, вид «поле» `picker_field.js`; `counterparty_picker.js` —
  привязка к `[data-cp-picker]`. Тема формы, `data-cp-*`, `<select>`-контракт не менялись.
  CSS `.cp-results/.cp-option/.cp-mark/.cp-loading` → `vf-pick-*` в `vf-custom-classes.css`.
- **Stage 2.** Вид «пилюля» `picker_pill.js` + `partials/_filter_pill_picker.html.twig`, подключён
  в «Операциях ДДС» вместо пилюли #2661 (`docs/plan/ds-operations-dds.md` §10).
- **Stage 3.** `docs/design-system/components/entity-picker.md`, строка в `CLAUDE.frontend.md`.

### Отличия поля формы от прежнего поведения (по ревью)

Esc и «×» отменяют ждущий запрос (раньше список мог открыться после закрытия); Esc в поле внутри
модалки закрывает только список, а не модалку; во время debounce сразу «Поиск…», а не прежний
список; подпись «устаревшего» выбора — «Название · ИНН»; ARIA combobox/listbox; оформление на
токенах DS; в узком портале длинное название переносится, ИНН — строкой ниже.

### Изменение пилюли контрагента, о котором решает Владелец

Поиск теперь серверный (бриф: бэкенд не менять): ИНН — по началу, название — по нормализованному
ядру, архивные не отдаются. Локальный фильтр #2661 находил середину ИНН и архивных. Применённый
контрагент (в т. ч. архивный) показывается первым в списке до ввода и помечен выбранным; выбрать
архивного заново через поиск нельзя.

### Проверка

- `npm run lint`, `npm run build` — зелёные; `lint:twig` — ок. `check:ui-kit` /
  `check:uikit-react-mapping` в проекте нет (удалены 2026-10-11). PHP не менялся — `make site-stan`
  не прогонялся.
- `tests/Functional/Cash` + `CounterpartyPickerTypeTest` — 92 OK.
- Smoke, headless Chromium, стенд из worktree, 31 проверка, два прогона подряд: пилюля (открытие,
  `role=dialog`, фокус в поиск, 20 строк + «Все контрагенты» без запроса, группы, поиск по названию
  и ИНН, в запросе только `q`, пусто, обрыв сети → «Повторить», ↓/Enter → переход с
  `counterpartyId` без `page`, подпись и цвет применённой, Esc → фокус на пилюлю, «Сбросить»,
  архивный применённый — первым и выбран, чужой id — без подсветки, 390px); поле в форме операции
  (поиск, клавиатура, свободный текст → validity), автоправило (новая строка условия, клик по
  подсказке не удаляет условие), модалка платёжного календаря (список поверх, выбор, Esc закрывает
  только список); соседняя пилюля «Статья» и выбор строки с панелью «Удалить».
- На master и до задачи: ошибка инициализации Stimulus на «Новом автоправиле»; горизонтальный
  скролл 402px на 390px из-за кнопки «Добавить ▾» в шапке «Операций ДДС».
- Внутреннее ревью в свежем контексте: BLOCKER 0, IMPORTANT 1 (архивные/семантика поиска —
  смягчено и вынесено Владельцу), MINOR 9 — исправлены; FOLLOW-UP: общий `MutationObserver`
  с реестром селекторов, утечка порталов удалённых строк коллекции, закрытие списка по Tab,
  контроллер «Операций ДДС» по-прежнему гидрирует весь справочник контрагентов.
