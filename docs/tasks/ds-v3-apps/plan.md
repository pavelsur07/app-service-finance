# ds-v3-apps — Phase 0 и технический план

Задание: `docs/design-system/PROMPT-ds-v3.md`, план `docs/plan/ds-v3.md`.
Эталон пока в открытом PR [#3022](https://github.com/pavelsur07/app-service-finance/pull/3022),
commit `3882c5dd9238005d16ba661fea78024400da45f8`. Прочитан из Git без переноса
чужого PR в реализацию. База реализации: `cd19c36b043746e6502ba2cdebb9b09616127b81`.
Каждый Stage — отдельная ветка и PR в master (явное решение Владельца выше
правила «одна задача — один PR»). Между Stage не требуется утверждение плана;
merge каждого PR требует решения «merge and deploy».

Решения Владельца: разряды денег — U+00A0, минус — U+2212, запятая,
₽ суффиксом; расходы обычным цветом. Иконки — `@tabler/icons-react`, 16/18/20,
stroke 2, только именованные импорты. §4 исходного плана закрыт.

## Phase 0 — результаты исследования

### Сборка и место пакета

Подтверждён `apps/packages/ds` (`@vashfindir/ds`). Standalone Compose монтирует
весь `apps/` в `/workspace/apps`; дополнительных локальных volumes не нужно.
Приложения имеют независимые package-lock и строгие tsconfig: strict,
noUncheckedIndexedAccess, exactOptionalPropertyTypes, noImplicitReturns.
Общие UI/API пакеты сейчас подключены через `file:../../site/packages/...`.
Новый пакет подключается как `file:../packages/ds`.

Production: `docker/frontend/Dockerfile` получает корневой context, сначала
копирует manifests apps и старых пакетов, делает npm ci, затем копирует исходники
и запускает npm run build; runtime Nginx получает только dist приложения.
`Dockerfile.dockerignore` сейчас разрешает только account/admin, старые пакеты,
CSS и docker/frontend. Для Stage 2 понадобятся allowlist `apps/packages/ds`,
COPY manifest до npm ci и COPY исходников до build. Пакет на Stage 1 автономен,
не подключается к приложениям и не меняет production Docker/CI.
Изменения production Docker требуют отдельного именованного одобрения по
AGENTS.md §3.3; запрос — после подготовки конкретного diff, до применения.
CI также нужно проверить: существующие spa-checks должны устанавливать/проверять
пакет через build приложений; нельзя оставить генератор только локальной проверкой.

### Потребители старого UI и повторное использование

- Оба `main.tsx`: `@vashfindir/ui/styles.css` → Stage 2.
- LoginPage, RequireSession, SessionStatus обоих apps; account CompanyPicker:
  Button → Stage 3.
- HomePage, SessionStatus обоих apps: EmptyState → Stage 3.
- Оба App.tsx: ErrorBoundary остаётся до перевода на пакетную границу ошибок;
  после удаления старого CSS его fallback нужно перевести на компоненты ДС.
- Общий API, session stores, CSRF, маршруты и права переиспользуются без изменений.
- Аналоги: Button/EmptyState/ErrorBoundary в `site/packages/ui` (только чтение),
  account CompanyPicker и существующие Vitest/MSW/Playwright fixtures.
- Не переносить исходники/стили из site. После миграции всех потребителей удалить
  @vashfindir/ui из manifests apps; @vashfindir/api продолжает использоваться.

### Контракты компонентов по листам §3.1

Все props — интерфейсы над компонентом, нативные атрибуты не теряются.
Управляемые значения: value/onChange; формы используют нативную валидацию,
disabled и readOnly. Операция — Button, навигация — Link (Decision 47).

| Компонент / лист | API и поведение |
| --- | --- |
| Button / Controls | variant primary/secondary/ghost/danger; size sm/md/lg (30/36/44); icon, iconOnly с обязательным aria-label; loading блокирует повторное действие, aria-busy; type=button по умолчанию; Enter/Space нативные |
| Link / Controls | href, variant inline/standalone/muted; external — безопасный rel, иконка и текст для скринридера; router передаёт навигацию без полной загрузки |
| FormField / Controls | id, label, required, helper, error, children; htmlFor/id, error/helper ID через aria-describedby, aria-invalid на поле |
| Input, Textarea / Controls | нативные props/ref; error, disabled/readOnly; hover/focus/error по component-токенам; фокус outline 2px, offset 2px |
| InputGroup / Controls | prefix/suffix/icon/action; action — Button с aria-label; clearable управляется value, пароль — переключение type с сохранением значения |
| Select, Checkbox, Radio, Toggle / Controls | нативные select/input; label, error, disabled; Toggle — checkbox с role=switch; Radio — единый name и нативные стрелки/Space |
| TagsInput / Controls | value: string[], onChange, label, disabled; Enter добавляет, удаление отдельной кнопкой с подписью |
| EntityPicker, TreePicker / Controls | open/onOpenChange, items, value/onChange, title, searchable; Modal со списком/деревом, disabled items; дерево выбирает только leaf, aria-expanded и клавиатура дерева |
| Badge, Chip / Data | variant для источника/фильтра/банка, children; Chip selected/onRemove; удаление отдельной доступной кнопкой |
| StatusPill / Data | tone success/danger/warning/neutral, children; точка декоративная, смысл текстом |
| AbcPill / Data | revenue/profit A/B/C; собственные abc-токены, не feedback |
| MarketplaceMark / Data | marketplace ozon/wb/yamarket/megamarket; доступная подпись; только семантические mp-токены |
| Avatar / Data | name/src, size sm/md/lg (24/32/40), shape circle/square; initials fallback |
| Card, KpiCard / Data | title, actions, children, flush; padding 16×20; KPI label/value/delta, compact/hero (22/28); цвет только delta, расходы обычным цветом |
| Table, SortHeader, TotalsRow / Table | нативные table/thead/tbody/tfoot; caption; SortHeader direction/onSort, aria-sort на th; totals передаются данными, финансовые формулы не рассчитывает ДС |
| Pagination / Table | page, pageSize, total, onPageChange; диапазон, first/last/ellipsis/prev/next; aria-current=page; пустое состояние/границы без невалидных переходов (Decision 46) |
| Tabs / Tabs | items id/label/count/disabled, value/onChange; сегментер; tablist/tab/tabpanel и roving tabindex, стрелки/Home/End |
| Toast / Feedback | tone, title, children, onClose; 4s auto-dismiss, очистка таймера; aria-live; управление фокусом не прерывает работу |
| Alert, EmptyState / Feedback | tone/title/children/actions; alert для ошибок, status для результата; EmptyState icon/title/описание/один CTA |
| Modal, Drawer / Overlays | open/onClose, title, children, footer; Modal S/M/L 440/520/640, Drawer 680; max viewport, scroll body, trap/restore focus, Esc, aria-modal, inert фон и scroll lock |
| ConfirmDialog / Feedback | Modal S, title/description, confirmLabel, onConfirm, busy, destructive; отмена безопасна, повторное подтверждение блокируется |
| AppShell, AppHeader / Layout | header/sidebar/footer/children, skip link; header 60, logo в колонке sidebar; breadcrumbs, слоты периода/поиска/уведомлений без выдуманных функций |
| Sidebar / Layout | items (group/id/label/icon/href/children), activeId, collapsed/onCollapsedChange, user/onLogout; 264/72; aria-expanded/controls/current, состояние хранится; клик группы в рейке раскрывает |
| DropdownMenu / Layout | trigger/items, open/onOpenChange; disabled/destructive/separator; menu/menuitem, стрелки/Home/End, Enter, Esc, возврат фокуса |
| PageHeader / Layout | title, description, actions; один h1 страницы |

§3.2: период/календарь, скелетоны/спиннер, ошибки страниц, tooltip/popover,
combobox, серверная валидация/dirty forms, auth-экран, bulk table не получают
нового дизайна до листов. Минимальная композиция из перечисленных компонентов
с локальным TODO(ds). Не добавлять неработающие действия в каркас.
TagsInput/Chip/AbcPill/MarketplaceMark/Avatar входят в Stage 5, хотя сокращённый
перечень §5 их не повторяет; это сохраняет полноту §3.1.

### Скриншоты и приёмка

Матрица 1440×900 для **каждого** app: `/login` default и неверный пароль,
`/` с активной сессией (account: выбранная компания + выбор компании), `/about`,
`/missing/nested`, session loading/error/403. Для каркаса — sidebar full/rail,
раскрытая группа, меню пользователя, длинная рабочая зона с обычной scrollbar.
Для Stage 5 — fixture-страница таблицы/табов/пикеров/оверлеев без backend данных.
Playwright request fixtures используют только синтетические данные. До/после
на одинаковом viewport; screenshot artifacts — локальные test-results, не Git.
Stage 1 не меняет экраны: визуальные before/after идентичны по определению;
baseline снимки готовятся для Stage 2. Контраст проверять по вычисленным
цветам: текст ≥4.5:1, границы ≥3:1; focus виден с клавиатуры.

## Baseline

- `make front-install` — PASS, независимые lock-файлы без изменений. npm сообщил
  существующие 10 vulnerabilities в каждом app (8 moderate, 2 critical);
  обновление сторонних зависимостей вне этой задачи.
- `APP=account ./bin/frontend.sh test` — PASS: 2 файла, 35 тестов.
- Проверка эталона: 361 токен JSON = 361 CSS-переменная светлого :root,
  отсутствующих ссылок и расхождений значений нет (146 primitive, 123 semantic,
  79 component, 13 deprecated). Нужна нормализация плоских `{name}` ссылок
  для Style Dictionary: группы являются слоями, имена CSS от них не зависят.

## Stage 1 — пакет и токены

Risk: MEDIUM. Stage base commit: `cd19c36b043746e6502ba2cdebb9b09616127b81`.
Definition of Done / чек-лист:
- [x] Автономный @vashfindir/ds с exports ., ./tokens.css, ./styles.css.
- [x] JSON — точная копия указанного эталона; Style Dictionary генерирует
  tokens.css и tokens.ts, только light, --ds-, детерминированно.
- [x] Vitest сначала красный без generated output, затем зелёный: все semantic
  и component токены и значения CSS-эталона, ссылки, полнота TS/CSS.
- [x] Strict TS, lint/format/test/build пакета; гейты обоих apps и preview E2E.
- [x] site/ и runtime apps не изменены; независимый internal review.
- [x] Отдельный Codex review по решению Владельца 2026-11-16 — REVIEW_GREEN;
  BLOCKER/IMPORTANT 0/0, детали в reviews/codex-stage-1.md.
Work items: 1.1 тестовый контракт и пакет; 1.2 генератор; 1.3 checks/reviews/PR.
Checks: package test/typecheck/lint/build/check:generated; make front-typecheck
front-lint front-test front-build; make front-e2e MODE=preview.
Reviewer focus: плоские ссылки, пропущенные/устаревшие токены, детерминизм,
поставка generated исходников без зависимости runtime от build tools.

## Stage 2 — Tailwind v4 и стили

Risk: HIGH-LOCAL. Stage base commit: записать после доставки Stage 1.
DoD: Tailwind/preflight в обоих apps, только тема ДС, Manrope, body 13px,
сброс default palette/type/radius/shadow/breakpoint, старый CSS не импортируется;
вход/главная/about/404 стилизованы, прежнее поведение сохранено. Production
собирает пакет; Docker diff только после отдельного одобрения §3.3.
Work items: 2.1 тест темы и screenshot baseline; 2.2 пакетный CSS/подключение;
2.3 страницы/build/review/PR. Checks: package/apps gates, preview E2E,
production build/smoke после одобрения. Reviewer focus: CSS isolation,
доступность, allowlist Docker, импорт шрифта без внешнего runtime запроса.

## Stage 3 — базовые компоненты

Risk: HIGH-LOCAL. Stage base commit: записать перед работой.
DoD: Button/Link/FormField/Input/InputGroup/Textarea/Select/Checkbox/Radio/Toggle,
Badge/StatusPill/Card/EmptyState/Alert/Toast; apps используют их вместо старых
Button/EmptyState; Vitest рендер/состояния/клавиатура/a11y; прежние auth/CSRF/401.
Work items: 3.1 тесты компонентов; 3.2 реализация; 3.3 миграция экранов/review/PR.
Checks: пакет/apps gates, auth/session fixtures, preview E2E, screenshots.
Reviewer focus: composition и ref/id, label/error, loading/disabled, lifecycle.

## Stage 4 — общий каркас

Risk: HIGH-LOCAL. Stage base commit: записать перед работой.
DoD: AppShell/Header/Sidebar/PageHeader/footer, своё меню admin, 264/72,
60px header, вложенность, persistence, skip link, scroll-stable, текущий logout.
Work items: 4.1 поведенческие тесты; 4.2 компоненты/композиция apps; 4.3 review/PR.
Checks: package/apps gates, keyboard/viewport/scrollbar E2E, screenshots.
Reviewer focus: публичные маршруты без bootstrap, доступность рейки, session race.

## Stage 5 — данные, оверлеи и форматтеры

Risk: HIGH-LOCAL. Stage base commit: записать перед работой.
DoD: Table/SortHeader/TotalsRow/Pagination/Tabs/KpiCard/Modal/Drawer/ConfirmDialog/
DropdownMenu/EntityPicker/TreePicker, оставшиеся Data/Tags компоненты §3.1,
formatMoney/Number/Percent/Date и иконки; деньги по решению Владельца; все ⛔
приёмки закрыты, отклонения/TODO(ds) перечислены.
Work items: 5.1 тесты; 5.2 данные/форматтеры; 5.3 оверлеи/пикеры;
5.4 интеграция/review/PR. Checks: package/apps gates, Playwright keyboard/focus/
scroll/background/restore/money fixtures, 1440 screenshots/contrast.
Reviewer focus: округление и Unicode, ни одной финансовой формулы в DS,
trap/restore при вложенных слоях, tree leaf-only, cleanup timers/listeners.

## Поставка и ревью

Каждый Stage: полный diff к своей master-базе, focused independent internal
review в свежем контексте; external Claude read-only на handoff, fixes по
AGENTS.md. Скрипт site/bin/external-review.sh не включает apps в paths и
штатно создаёт site/var: использовать --out вне site и передать полный diff
в --context; пустой штатный diff НЕ считать состоявшимся ревью.
Claude не установлен. По прямому поручению Владельца 2026-11-16 для handoff
Stage 1 выполнено ревью в отдельной ephemeral/read-only сессии Codex:
REVIEW_GREEN. Вторая сессия получила полный diff и эталон через stdin,
поскольку bwrap не позволяет read-only shell; ограничение и результат
записаны в reviews/codex-stage-1.md. Это согласованная замена reviewer
для данного handoff, а не изменение общих правил репозитория.
