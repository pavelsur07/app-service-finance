# ТЗ Frontend — SHC-001-F: Storage Health (Admin UI)

> Платформа VashFinDir · Admin-панель · Twig + Tabler IO + vanilla JS
> Исполнитель: Claude Code (автономный режим). ТЗ не содержит разметки и кода — только контракты, состояния и намерения.
> Backend ТЗ: `TASK_storage_health_backend.md` (SHC-001)

---

## 0. Сводка (1 экран)

- **Бизнес-цель:** Администратор должен видеть в Admin-панели статус объектного хранилища (local / S3) без доступа к серверу. MVP — одна страница с карточкой состояния и кнопкой ручного пинга.
- **Шаблоны:** `templates/admin/storage/` (новая директория)
- **Тип:** новая страница в существующем Admin-разделе
- **Ветка:** `feature/shc-001-storage-health-admin` (общая с backend)
- **Подзадачи:** F1 · F2 · F3
- **Новый Vite entry / React mount:** нет — только Twig + vanilla JS
- **Затрагивает shared Twig-компоненты:** нет (используем готовые Tabler-классы)
- **Зависит от backend API:**
  - `GET /admin/storage` — готов в SHC-001 B4
  - `POST /admin/storage/ping` — готов в SHC-001 B4

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- Существует Admin firewall с базовым layout `templates/admin/layout.html.twig`
- Существуют другие Admin-страницы — конвенции уже заданы (extend, blocks, nav-active)
- Левый сайдбар рендерится через `templates/admin/partials/_sidebar.html.twig`
- Tabler IO подключён глобально (CSS + иконки `ti ti-*`)
- CSRF-токены доступны через `csrf_token('...')` в Twig

### 1.2 Желаемое состояние

Администратор открывает `/admin/storage`:
- Видит карточку с текущим состоянием хранилища (провайдер, статус-бейдж, latency, timestamp проверки)
- Если Redis-ключ устарел (TTL истёк) — видит статус «Не проверялось» с подсказкой
- Нажимает «Проверить сейчас» — кнопка показывает спиннер, после ответа сервера карточка обновляется без перезагрузки страницы
- При ошибке хранилища видит красный блок с текстом исключения
- При S3 без credentials видит жёлтый бейдж «Не настроено»

### 1.3 In scope

- Шаблон страницы `status.html.twig`
- Partial-шаблон карточки `_health_card.html.twig`
- Добавление пункта «Хранилище» в сайдбар
- Inline JS для POST-пинга (fetch + обновление DOM)
- Все состояния экрана (§2.2)

### 1.4 Out of scope (явно НЕ делаем)

- История проверок (таблица прошлых результатов)
- Переключение провайдера из UI
- Алерты / уведомления при деградации
- React-компоненты, Alpine.js, новые npm-пакеты
- Страницы настройки S3-credentials
- Автообновление по polling (нет Scheduler в MVP)

### 1.5 Допущения и открытые вопросы

**Допущения:**
- `templates/admin/layout.html.twig` содержит блоки `{% block title %}`, `{% block content %}`, `{% block javascripts %}`
- Сайдбар подключается через `include` или `embed` и принимает переменную `activeSection`
- Tabler v1.x — классы `badge`, `card`, `btn`, `spinner-border` доступны глобально
- Иконки через `<i class="ti ti-*">` (webfont, не SVG-спрайты)
- CSRF работает через Symfony `csrf_token()` в Twig

**Открытые вопросы:**
- Как именно активируется пункт сайдбара: через переменную `activeSection` или `app.request.pathInfo`? → нужно уточнить у владельца шаблона
- Есть ли общий Twig-макрос для бейджа статусов (используемый в других разделах Admin)? Если да — переиспользовать
- Какой `{% block javascripts %}` — с `{{ parent() }}` или заменяет? → не трогать родительский, только добавлять

---

## 2. UX / поведение

### 2.1 Пользовательский сценарий

1. Администратор кликает «Хранилище» в левом сайдбаре
2. Браузер открывает `/admin/storage` (GET) — Symfony рендерит шаблон с данными из Redis
3. Страница показывает карточку статуса со статическими данными из `HealthResult`
4. Администратор нажимает кнопку «Проверить сейчас»
5. Кнопка мгновенно становится disabled, текст меняется на «Проверяю…» + спиннер
6. JS делает `fetch POST /admin/storage/ping` с CSRF-заголовком
7. Сервер выполняет `StorageHealthChecker::check()`, сохраняет в Redis, возвращает JSON
8. JS обновляет DOM внутри карточки (статус-бейдж, latency, timestamp, блок ошибки)
9. Кнопка возвращается в idle-состояние

### 2.2 Состояния экрана

| Состояние | Что показываем | Tabler-паттерн |
|---|---|---|
| **Loading (initial)** | N/A — страница серверная, данные уже есть в HTML | — |
| **ok** | Зелёный бейдж «Доступно», latency в мс, timestamp, нет блока ошибки | `badge bg-success-lt` |
| **fail** | Красный бейдж «Недоступно», latency = «—», блок с текстом ошибки | `badge bg-danger-lt` + `alert alert-danger` |
| **not_configured** | Жёлтый бейдж «Не настроено», latency = «—», информационный текст | `badge bg-warning-lt` |
| **unknown** (TTL истёк) | Серый бейдж «Не проверялось», подсказка «Нажмите Проверить сейчас» | `badge bg-secondary-lt` |
| **Пинг pending** | Кнопка disabled + `spinner-border spinner-border-sm` внутри, текст «Проверяю…» | `btn disabled` + `spinner-border` |
| **Пинг success** | Карточка обновилась данными из JSON-ответа, кнопка снова активна | анимация fade (опционально) |
| **Пинг error (сеть/500)** | `alert alert-danger` под кнопкой: «Не удалось выполнить проверку. Попробуйте ещё раз.» Кнопка снова активна | `alert alert-danger` |
| **Пинг CSRF ошибка (422)** | Страница перезагружается (CSRF-ошибка — форс-мажор) | hard reload |
| **Нет доступа (403)** | Symfony redirect на Admin login — не обрабатываем в JS | — |

### 2.3 Адаптивность / a11y

- Страница не адаптируется под мобиль (Admin-панель — desktop-only)
- Кнопка пинга: `aria-busy="true"` в pending-состоянии, `aria-label="Проверить хранилище"`
- Бейдж статуса: текст не только цвет (значение всегда есть текстом)
- Блок ошибки: `role="alert"` для screen readers

---

## 3. Структура шаблонов

> Вместо дерева React-компонентов — иерархия Twig-шаблонов. Разделение: `status.html.twig` — страница (смарт, получает переменные от контроллера), `_health_card.html.twig` — partial (тупой, только рендер переменных).

```
templates/admin/storage/
  status.html.twig          [PAGE]     — основная страница, extends layout
  _health_card.html.twig    [PARTIAL]  — карточка состояния, include из status.html.twig
```

---

### 3.1 `status.html.twig` [PAGE]

**Назначение:** страница `/admin/storage` — рендерит заголовок, карточку и кнопку пинга.

**Переменные от контроллера:**

| Переменная | Тип PHP | Описание |
|---|---|---|
| `health` | `HealthResult` | текущий результат или `HealthResult::unknown()` |
| `driver` | `string` | значение `STORAGE_DRIVER` ENV (`local` / `s3`) |

**Что делает:**
- `{% extends 'admin/layout.html.twig' %}`
- Заполняет `{% block title %}Хранилище — Admin{% endblock %}`
- В `{% block content %}`:
  - Заголовок страницы: «Хранилище файлов» + subtitle «Статус и диагностика объектного хранилища»
  - Строка с 3 stat-карточками (провайдер, latency, TTL кэша)
  - `{% include 'admin/storage/_health_card.html.twig' with { health: health } %}`
  - Кнопка «Проверить сейчас» с CSRF (`csrf_token('storage_ping')`)
  - Контейнер `<div id="ping-error" class="alert alert-danger d-none">` для JS-ошибок
- В `{% block javascripts %}` — `{{ parent() }}` + `<script>` с логикой пинга (§4)

**Stat-карточки (3 штуки):**

| Карточка | Что показывает | Значение |
|---|---|---|
| Провайдер | driver ENV | `{{ driver }}` |
| Задержка | latency в мс или «—» | `{% if health.latencyMs > 0 %}{{ health.latencyMs }} мс{% else %}—{% endif %}` |
| TTL кэша | фиксированный текст | «10 мин» |

---

### 3.2 `_health_card.html.twig` [PARTIAL]

**Назначение:** карточка состояния хранилища — рендерит текущие данные `HealthResult`. Используется при начальной загрузке и пересобирается JS после пинга.

**Переменные на входе:**

| Переменная | Тип PHP | Описание |
|---|---|---|
| `health` | `HealthResult` | объект состояния |

**Структура карточки:**

```
card
  card-header
    title «Статус хранилища»  +  бейдж статуса (id="storage-status-badge")
  card-body
    grid 2 колонки:
      поле «Драйвер»           (id="storage-driver")
      поле «Последняя проверка» (id="storage-checked-at")
      поле «Задержка»          (id="storage-latency")
      поле «Probe-файл»        — статичный текст «__health__/probe»
    блок ошибки               (id="storage-error-block", d-none если error = null)
      alert alert-danger с текстом ошибки (id="storage-error-text")
  card-footer
    текст «Данные актуальны · TTL 10 мин» или «Кэш устарел» (id="storage-ttl-note")
```

**ID-атрибуты** (обязательны — JS обновляет их после пинга):

| id | Содержимое |
|---|---|
| `storage-status-badge` | `<span class="badge ...">Текст статуса</span>` |
| `storage-driver` | значение driver |
| `storage-checked-at` | дата/время или «—» |
| `storage-latency` | latency + «мс» или «—» |
| `storage-error-block` | div с `d-none` если нет ошибки |
| `storage-error-text` | текст ошибки |
| `storage-ttl-note` | текст под карточкой |

---

## 4. Inline JS (логика пинга)

> Весь JS — один `<script>` блок в `{% block javascripts %}`. Без внешних зависимостей. Без jQuery.

**Файл:** встроен в `status.html.twig` в блоке `{% block javascripts %}`

### 4.1 Что делает скрипт

**Инициализация:**
- Найти кнопку `#ping-btn`, навесить `click`-обработчик

**При клике на кнопку:**
1. Перевести кнопку в pending: `disabled = true`, заменить innerHTML на спиннер + «Проверяю…», `aria-busy="true"`
2. Скрыть блок ошибки пинга `#ping-error` (добавить `d-none`)
3. Выполнить `fetch('{{ path('admin_storage_ping') }}', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' } })`
4. При успешном ответе (`response.ok`):
   - Распарсить JSON
   - Вызвать `updateCard(data)` (см. ниже)
5. При HTTP-ошибке или сетевой ошибке:
   - Показать `#ping-error` (убрать `d-none`), текст «Не удалось выполнить проверку. Попробуйте ещё раз.»
6. В блоке `finally`: вернуть кнопку в idle — `disabled = false`, вернуть исходный innerHTML, `aria-busy="false"`

**Функция `updateCard(data)`:**
- Обновить `#storage-status-badge`: class + текст на основе `data.status` (маппинг — §4.2)
- Обновить `#storage-driver`: `data.driver`
- Обновить `#storage-checked-at`: отформатировать `data.checkedAt` через `Intl.DateTimeFormat` (`ru-RU`, дата + время)
- Обновить `#storage-latency`: `data.latencyMs > 0 ? data.latencyMs + ' мс' : '—'`
- Блок ошибки: если `data.error` — убрать `d-none` у `#storage-error-block`, вставить текст в `#storage-error-text`; иначе — добавить `d-none`
- Обновить stat-карточку latency (опционально, если есть `#stat-latency`)
- Обновить `#storage-ttl-note`: «Данные актуальны · TTL 10 мин»

**CSRF-токен:**
- Передаётся как `data-`атрибут на кнопке: `data-csrf="{{ csrf_token('storage_ping') }}"`
- JS читает: `const csrfToken = document.getElementById('ping-btn').dataset.csrf`

### 4.2 Маппинг `status → badge`

| `data.status` (от API) | CSS-классы бейджа | Текст |
|---|---|---|
| `ok` | `badge bg-success-lt text-success` | Доступно |
| `fail` | `badge bg-danger-lt text-danger` | Недоступно |
| `not_configured` | `badge bg-warning-lt text-warning` | Не настроено |
| `unknown` | `badge bg-secondary-lt text-secondary` | Не проверялось |

> Маппинг описан как JS-объект (константа), не через if/else.

---

## 5. API-интеграция

### 5.1 Эндпоинты

| Метод + путь | Назначение | Body | Ответ | Коды ошибок | Статус |
|---|---|---|---|---|---|
| `GET /admin/storage` | рендер страницы с текущим состоянием | — | HTML | 403 (redirect) | готов в B4 |
| `POST /admin/storage/ping` | запустить проверку, вернуть JSON | пустой POST + CSRF-заголовок | JSON (ниже) | 422, 500, 403 | готов в B4 |

**Контракт ответа `POST /admin/storage/ping`:**
```json
{
  "status": "ok",
  "driver": "local",
  "latencyMs": 8,
  "error": null,
  "checkedAt": "2026-06-18T12:41:05+00:00"
}
```

**Форма ошибки:**
```json
{
  "error": {
    "code": "storage_health_redis_error",
    "message": "Не удалось сохранить результат проверки хранилища"
  }
}
```

**Коды ошибок:**

| Код | HTTP | Обработка в JS |
|---|---|---|
| `invalid_csrf_token` | 422 | hard reload страницы |
| `storage_health_redis_error` | 500 | показать `#ping-error` |
| — | 403 | hard reload (redirect на login) |
| сетевая ошибка | — | показать `#ping-error` |

### 5.2 Хуки данных

**N/A** — нет React/TanStack Query. Данные при первой загрузке приходят через Twig-переменные от контроллера. Обновление — через fetch в §4.

---

## 6. Формы (RHF + Zod)

**N/A** — нет форм с полями ввода. POST-пинг — кнопка без payload, защита через CSRF-заголовок.

---

## 7. Mount-контракт

**N/A** — нет React-виджетов, нет Vite entry, нет JS-mount. Страница рендерится сервером (Symfony → Twig).

---

## 8. UI / стиль (Tabler IO)

### Готовые Tabler-компоненты (не создаём новые)

| Компонент | Классы | Где используем |
|---|---|---|
| Card | `card`, `card-header`, `card-body`, `card-footer` | карточка статуса |
| Badge | `badge bg-{color}-lt text-{color}` | статус хранилища |
| Alert | `alert alert-danger` | блок ошибки, ошибка пинга |
| Button | `btn btn-primary btn-sm` | кнопка пинга |
| Spinner | `spinner-border spinner-border-sm` | pending-состояние кнопки |
| Stat card | `card`, внутри label + крупное значение | 3 stat-карточки вверху |
| Page header | `page-header` / `container-xl` | заголовок страницы |
| Grid | `row`, `col-md-4`, `col-md-6` | расположение карточек |

### Иконки (Tabler webfont, `ti ti-*`)

| Иконка | Где |
|---|---|
| `ti-database` | пункт сайдбара «Хранилище», заголовок карточки |
| `ti-refresh` | кнопка «Проверить сейчас» |
| `ti-plug` | поле «Драйвер» |
| `ti-clock` | поле «Последняя проверка» |
| `ti-speedboat` | поле «Задержка» |
| `ti-file-check` | поле «Probe-файл» |
| `ti-alert-triangle` | блок ошибки |
| `ti-circle-check` | (опционально) бейдж ok |

### Цвета — только семантические токены Tabler

| Статус | Классы |
|---|---|
| ok | `bg-success-lt text-success` |
| fail | `bg-danger-lt text-danger` |
| not_configured | `bg-warning-lt text-warning` |
| unknown | `bg-secondary-lt text-secondary` |

> Hex-цвета запрещены. Inline `style="color: ..."` запрещён.

### Новые компоненты/CSS

- Новых компонентов не создаём — всё через Tabler utility-классы
- CSS Modules: не нужны
- Глобальные `:root` overrides: не трогаем
- Дополнительный CSS-файл: не нужен

---

## 9. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| **F1** | Пункт «Хранилище» в сайдбаре + базовый шаблон страницы (extends, blocks, заголовок) | backend B4 (маршрут) | 🟡 MEDIUM | открыть `/admin/storage` → 200 |
| **F2** | `_health_card.html.twig`: карточка с id-атрибутами, все 4 состояния бейджа, блок ошибки | F1 | 🟡 MEDIUM | ручная проверка всех состояний через Twig-переменные |
| **F3** | Inline JS: fetch-пинг, `updateCard()`, маппинг статусов, обработка ошибок | F2 + backend B4 (ping endpoint) | 🟡 MEDIUM | клик кнопки → DOM обновился без reload; ошибка сети → alert |

---

### F1: Сайдбар + базовый шаблон

- **Цель:** добавить страницу в навигацию и создать пустой шаблон, расширяющий layout.
- **Создаёт файлы:**
  - `templates/admin/storage/status.html.twig` (скелет)
- **Меняет файлы:**
  - `templates/admin/partials/_sidebar.html.twig` — добавить пункт «Хранилище» с иконкой `ti-database` и ссылкой `path('admin_storage_status')`; активный класс при `activeSection == 'storage'` (или эквивалент)
- **DoD:**
  - Пункт «Хранилище» виден в сайдбаре
  - Клик открывает `/admin/storage` без 404/500
  - Страница использует корректный Admin layout

---

### F2: Partial карточки + все состояния

- **Цель:** реализовать визуальную карточку со всеми состояниями через Twig-переменные.
- **Создаёт файлы:**
  - `templates/admin/storage/_health_card.html.twig`
- **Меняет файлы:**
  - `templates/admin/storage/status.html.twig` — включить partial, добавить stat-карточки
- **DoD:**
  - При `health.status.value == 'ok'` — зелёный бейдж, latency, timestamp
  - При `health.status.value == 'fail'` — красный бейдж, блок ошибки виден
  - При `health.status.value == 'not_configured'` — жёлтый бейдж, блок ошибки скрыт
  - При `health.status.value == 'unknown'` — серый бейдж, подсказка про TTL
  - Все `id`-атрибуты (§3.2) присутствуют в разметке

---

### F3: Inline JS — fetch-пинг

- **Цель:** реализовать AJAX-пинг без перезагрузки страницы.
- **Создаёт файлы:** нет (JS встроен в `status.html.twig`)
- **Меняет файлы:**
  - `templates/admin/storage/status.html.twig` — добавить кнопку `#ping-btn` с `data-csrf`, блок `#ping-error`, `{% block javascripts %}` со скриптом
- **DoD:**
  - Клик на кнопку → спиннер, кнопка disabled
  - POST уходит с CSRF-заголовком
  - После ответа — `updateCard()` обновляет DOM без reload
  - При сетевой ошибке или 500 — `#ping-error` показывается, кнопка активна
  - При 422 (CSRF fail) — hard reload
  - При 403 — hard reload

---

## 10. Ограничения и запреты

**Не ломать:**
- Существующие Admin-страницы и их навигацию
- Базовый layout `admin/layout.html.twig` — только `{% block %}` использование, не менять файл

**Не трогать:**
- `apiClient.ts`, `queryClient.ts`, `vite.config.ts` — страница не React
- Глобальные Tabler CSS-overrides (`assets/css/tabler-overrides.css` или аналог)
- Другие `_sidebar.html.twig` пункты — только добавить новый пункт

**Запрещено в коде шаблона:**
- Inline `style="color: #..."` — только Tabler utility-классы
- `<script src="...bootstrap.bundle.js">` отдельно — используем уже подключённый в layout
- jQuery (если не используется в Admin глобально)
- Прямой вывод `health.error` без `|e` Twig-фильтра (XSS)
- Прямой вывод пользовательских данных без `|e`

**Security:**
- CSRF-токен передаётся через `data-`атрибут кнопки, читается в JS — не вставлять в inline-строку через Twig внутри JS-кода (XSS-риск при неправильном экранировании)
- Все выводимые переменные в HTML: `{{ variable|e }}`
- `health.error` — потенциально содержит paths/credentials из stack trace: выводить как есть (ROLE_ADMIN видит), но не логировать в browser console

**Performance:**
- Нет polling (нет `setInterval`)
- Нет загрузки внешних JS-библиотек ради этой страницы
- Inline JS — минимальный, без сборки

---

## 11. Критерии приёмки

**Функциональные:**
- [ ] `/admin/storage` открывается, показывает текущий `HealthResult` из Redis
- [ ] Все 4 статуса (`ok`, `fail`, `not_configured`, `unknown`) визуально корректны (бейдж, цвет, текст)
- [ ] Кнопка «Проверить сейчас»: спиннер в pending, обновление DOM после ответа, без reload страницы
- [ ] Блок ошибки показывается только при `status = fail`
- [ ] Ошибка пинга (сеть/500) показывает `#ping-error` без потери состояния
- [ ] CSRF-токен передаётся в заголовке запроса
- [ ] Пункт «Хранилище» в сайдбаре активен при нахождении на `/admin/storage`

**Технические:**
- [ ] Twig-шаблоны валидны (`bin/console lint:twig templates/admin/storage/`)
- [ ] Все переменные экранированы через `|e`
- [ ] Нет hex-цветов, нет inline `style="color:..."`
- [ ] JS без синтаксических ошибок в DevTools Console
- [ ] Страница проходит Admin firewall (403 без ROLE_ADMIN)
- [ ] Handoff Report заполнен

---

## 12. План отката

- **Стратегия:** revert PR — все изменения аддитивны (новые файлы + одна строка в сайдбаре)
- Удалить 2 файла + откатить строку в `_sidebar.html.twig`
- Никаких миграций, никаких изменений существующих шаблонов, кроме сайдбара

---

## 13. Чек-лист качества ТЗ

- [x] Путь файла указан для каждого нового шаблона
- [x] Разделение Page / Partial обозначено (аналог Smart/Dumb)
- [x] Полная таблица переменных Twig для каждого шаблона (аналог props)
- [x] Все состояния экрана описаны (§2.2) — 9 состояний, включая pending и ошибки пинга
- [x] Статусы enum расписаны: value от API, UI-метка, Tabler-класс (§4.2 JS)
- [x] Values совпадают с backend enum `StorageStatus` из SHC-001 §2.3
- [x] Оба API-эндпоинта описаны: метод, путь, ответ (JSON), коды ошибок (§5.1)
- [x] Inline JS описан пошагово: инициализация, pending, fetch, updateCard, обработка ошибок (§4)
- [x] CSRF-механизм описан конкретно: data-атрибут → JS → заголовок
- [x] id-атрибуты всех DOM-узлов, которые обновляет JS, перечислены в таблице (§3.2)
- [x] Tabler-компоненты: что берём готовым (список в §8), ничего нового не создаём
- [x] Раздел «Out of scope» заполнен (§1.4)
- [x] Все открытые вопросы вынесены в §1.5
- [x] В ТЗ нет HTML-разметки и JS-кода — только контракты и намерения
- [x] XSS-риски отмечены явно (§10): `|e` фильтр, CSRF data-атрибут
