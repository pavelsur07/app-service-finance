# Окно выбора из справочника (entity picker)

Одно окно выбора элемента справочника (контрагент сейчас; проект, счёт, статья — по мере
появления поиска) в двух видах поверх общего ядра. Легаси-слой (Tabler); новый слой (React) его
не использует. Эталон пилюли — `docs/design-system/screens/Operations.dc.html`, поповер
«Фильтр по контрагенту». История и проверка — `docs/plan/entity-picker.md`.

## Из чего состоит

| Файл | Что |
|---|---|
| `site/assets/picker_core.js` | ядро: `readConfig` (`data-picker-*`), `adapters`, `createSearch` (debounce 250 мс, `AbortController`, отсев устаревших ответов), `createListbox` (рендер, группы, подсветка, `combobox`/`listbox`/`aria-activedescendant`, ↑/↓/Enter, загрузка/пусто/ошибка + «Повторить», `aria-live`), `positionPortal` (fixed, вверх/вниз), `initEach` (в т. ч. для строк, добавленных позже) |
| `site/assets/picker_field.js` | вид **«поле формы»**: `<select>` + поле поиска + список-портал в `body` |
| `site/assets/picker_pill.js` | вид **«пилюля фильтра»**: кнопка → поповер (dropdown Bootstrap) → ссылки |
| `site/templates/partials/_filter_pill_picker.html.twig` | разметка пилюли |
| `site/assets/counterparty_picker.js` | привязка вида «поле» к `CounterpartyPickerType` (`[data-cp-picker]`) |
| `site/assets/styles/vf-custom-classes.css` | классы `vf-pick-*` (раздел «Окно выбора из справочника») |

Скрипты подключены в `assets/legacy-app.js`.

### Поле формы

`<select>` остаётся источником значения и границей валидации: Symfony принимает только
`choices`, внешние скрипты читают `.value` и слушают `change`. Без JS работает обычный
`<select>`. Свободный текст без выбора не отправляется; `required`/`disabled` переносятся на
видимое поле. Если API недоступен, поле ищет по `<option>` — форма остаётся заполнимой.
Список — портал в `body` с `z-index: 1080`: выше модалки (1055) и offcanvas (1045), закрывается
по `mousedown` до того, как клик дойдёт до кнопок под ним.

Форма контрагента — только `CounterpartyPickerType`; тема
`form/counterparty_picker_theme.html.twig` и её `data-cp-*` — контракт этой формы.

### Пилюля фильтра

Кнопка `.vf-pick-pill` (32px, радиус 16, 13/500, шеврон; применённая — `#e8effd` + Action
blue, подпись «Контрагент: Название…» ≤ 22 символов) и поповер `.vf-pick-pop` 420px с
`role="dialog"`. Открытие, Esc, клик снаружи, возврат фокуса на пилюлю и удержание в экране —
dropdown Bootstrap (как у соседних пилюль страницы). Фокус при открытии — в поиск.

До ввода — `initial` из страницы (бэкенд отдаёт `[]` при `q` короче минимума) и пункт сброса
«Все …» сверху; подсказка «Начните вводить…». Выбор — ссылка на тот же маршрут с параметром
и прочими текущими параметрами без `page`. «Сбросить» в футере снимает параметр.

## Атрибутный контракт `data-picker-*`

| Атрибут | По умолчанию | Что |
|---|---|---|
| `data-picker-search-url` | — | URL поиска, `GET ?q=`; в запросе только `q`, компания — на сервере |
| `data-picker-adapter` | без адаптера | ключ в `adapters` из `picker_core.js` |
| `data-picker-min-query` | `2` | минимум символов для запроса |
| `data-picker-param` | — | имя GET-параметра фильтра (пилюля) |
| `data-picker-value` / `data-picker-label` | `''` | текущий выбор: id и название |
| `data-picker-empty-text` | «Ничего не нашли.» | текст пустого результата |
| `data-picker-multiple` | `false` | **зарезервирован**, ядро его не читает: мультивыбора нет |

Только у пилюли (ставит партиал): `data-picker-base-url` — URL списка без параметра и `page`;
`data-picker-all-label` — пункт сброса; `data-picker-initial` — строки до ввода, в форме ответа API.

## Адаптер

Ядро работает с элементом `{id, label, hint?, group?, icon?}`:

| Поле | Что | Контрагент |
|---|---|---|
| `id` | значение | `id` |
| `label` | название, подсвечивается совпадение | `name` |
| `hint` | справа, приглушённо, тоже подсвечивается | `inn` |
| `group` | заголовок группы; группы в порядке первого появления, одна группа — без заголовка | `LEGAL_ENTITY` → «Организации», остальные типы → «ИП и физические лица» |
| `icon` | имя иконки Tabler (только пилюля) | `building` / `user` |

Адаптер — чистая функция строки ответа API: те же строки идут в `data-picker-initial`.

## Подключить новый справочник

```js
// picker_core.js — адаптер ответа GET /api/project-directions/search
adapters.projectDirection = (row) => ({ id: row.id, label: row.name, hint: row.code || '', group: row.parentName || '' });
```

```twig
{% include 'partials/_filter_pill_picker.html.twig' with {
    id: 'ops-pill-project', name: 'projectDirectionId', label: 'Проект',
    route: route, params: baseQuery, value: filters.projectDirectionId ?? '', valueLabel: project ? project.name : '',
    searchUrl: path('api_project_direction_search'), adapter: 'projectDirection',
    initial: projectRows|slice(0, 20), emptyText: 'Ничего не нашли.', allLabel: 'Все проекты',
    placeholder: 'Название', searchLabel: 'Найти проект', dialogLabel: 'Фильтр по проекту', hint: 'Начните вводить название',
} only %}
```

Поле формы: тема своего форм-типа рендерит `<select>` + поле поиска, а привязка в 20 строк по
образцу `counterparty_picker.js` вызывает `initPickerField(root, parts, {searchUrl, adapter, …})`.
API поиска и фильтр на бэкенде — отдельная задача: компания из контекста, ≤ 20 строк, `[]`
при коротком `q`.

## Чего не делать

- Не копировать `picker_core.js` под новый справочник — только адаптер и URL.
- Не вставлять текст из БД через `innerHTML`; в `data-picker-initial` — только
  `|json_encode|e('html_attr')`.
- Не передавать `companyId` и прочие параметры в URL поиска.
- Не убирать `<select>` из поля формы и не писать значение мимо него.
- Не рендерить портал внутри формы и не лечить перекрытие точечным `z-index`.
- Не вешать на поповер пилюли `data-ops-pop`: скрипт страницы перехватит фокус и фильтр.
- Мультивыбор, «Недавние», «Без …» — нет бэкенда; не имитировать на фронте.
