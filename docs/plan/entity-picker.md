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
