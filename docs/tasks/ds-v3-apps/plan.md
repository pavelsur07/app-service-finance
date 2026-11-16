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

Risk: HIGH-LOCAL (apps/styles), HIGH-EXTERNAL (production Docker/CI).
Stage base commit: `fbfcc71c88984d560b4cf80e56310031cdd11eff`.

Чек-лист приёмки Stage 2:
- [x] Tailwind v4 + preflight подключены через Vite обоих apps; каждый app
  компилирует только собственные src и пакет DS (без site/tests/чужого app).
- [x] Тема по v3/tailwind/app.css, только --ds- значения; дефолтные палитра,
  размеры текста, радиусы, тени и breakpoints сброшены; compile-тест подтверждает
  отсутствие bg-blue-500/text-base и наличие DS utilities.
- [x] Manrope self-hosted, OFL сохранена, русский/латиница; body 13px,
  tabular-nums, rem=16px; страницы login/home/about/404 на DS, без старого CSS.
- [x] Классы используемых старых React wrappers получают временные стили v3
  в пакете (Stage 3 заменит wrappers); site не изменён, session/CSRF не менялись.
- [x] Скриншоты 1440 до/после, контраст текста/границ, focus/disabled/error,
  loading/empty/error и существующий mobile smoke пройдены.
- [x] front typecheck/lint/test/build + preview E2E; пакет check:generated.
- [x] Подготовлены точные production Docker/CI changes: копирование/установка
  пакета в build context, включение DS в SPA filter и npm ci/check:generated.
  До отдельного named approval §3.3 — только proposal patch, без применения
  к production config. Локально проверяются эквивалентные временные файлы.
- [x] Независимое internal review и отдельный Codex REVIEW_GREEN, отчёт.
- [x] Production patch применён после named approval 2026-11-16;
  actual release contracts и fresh admin install/typecheck/tests PASS.
- [x] Реальный CI PR #3030 зелёный (43814469343, f82ec6d78); Stage 2
  Ready/handoff. Merge+deploy #3030 выполнен, acceptancePASS.

Work items:
- 2.1 — baseline, screenshots before, тесты темы/визуального контракта RED.
- 2.2 — Tailwind package/theme/font и подключение обоих приложений.
- 2.3 — существующие экраны, states и screenshot/contrast/E2E приёмка.
- 2.4 — review, build-context proposal, Draft PR и named approval Docker/CI.

Дополнение 2026-11-16: Stage 1 merged/deployed (`fbfcc71c8`), workflow
43804985365 SUCCESS, post-deploy 19/19 HTTPS, оба контейнера healthy на SHA.
Правило автоматического Codex fallback подготовлено отдельным PR #3028.
DoD: Tailwind/preflight в обоих apps, только тема ДС, Manrope, body 13px,
сброс default palette/type/radius/shadow/breakpoint, старый CSS не импортируется;
вход/главная/about/404 стилизованы, прежнее поведение сохранено. Production
собирает пакет; Docker diff только после отдельного одобрения §3.3.
Work items: 2.1 тест темы и screenshot baseline; 2.2 пакетный CSS/подключение;
2.3 страницы/build/review/PR. Checks: package/apps gates, preview E2E,
production build/smoke после одобрения. Reviewer focus: CSS isolation,
доступность, allowlist Docker, импорт шрифта без внешнего runtime запроса.

## Stage 3 — базовые компоненты

Risk: HIGH-LOCAL. Stage base commit: `18fa02478` (master после PR3033).
DoD: Button/Link/FormField/Input/InputGroup/Textarea/Select/Checkbox/Radio/Toggle,
Badge/StatusPill/Card/EmptyState/Alert/Toast; apps используют их вместо старых
Button/EmptyState; Vitest рендер/состояния/клавиатура/a11y; прежние auth/CSRF/401.
Work items: 3.1 тесты компонентов; 3.2 реализация; 3.3 миграция экранов/review/PR.
Checks: пакет/apps gates, auth/session fixtures, preview E2E, screenshots.
Reviewer focus: composition и ref/id, label/error, loading/disabled, lifecycle.

## Stage 4 — общий каркас

Risk: HIGH-LOCAL. Stage base commit: `d99ede00de60e210d373feea8b80e371daa521e4`.
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

Обновление 2026-11-16: Владелец поручил закрепить постоянный fallback
Claude CLI → отдельная свежая read-only сессия Codex. Правило подготовлено
отдельным docs PR #3028; в текущей задаче применяется по явной инструкции.
Stage 2 reviewed: internal 0/0, MINOR1 fixed; separate Codex 0/0, REVIEW_GREEN.
Владелец одобрил patch 2026-11-16: production proposal применён побайтно,
проверяются фактические CI/release gates. PR #3028 merged (87e6a7338),
workflow43813732309 SUCCESS, build/deploy skipped для docs-only изменения.

Stage 2 handoff: approved patch f2df0325f, integration base87e6a7338,
code head f82ec6d78; actual CI43814469343 SUCCESS. После independent review
UI runtime/lock не менялись; actual config exact reviewed candidate.
Stage2 доставлен: PR3030 merged37264e03c, workflow43819052773 SUCCESS; оба apps healthy на SHA, HTTPS23/23 и браузерная приёмка PASS.

## Stage 3 — уточнённый технический план и приёмка (2026-11-16)

- 3.1: baseline обоих apps; Vitest SSR-контракты компонентов RED, браузерные
  поведенческие тесты в отдельном test-only fixture (без нового production route).
- 3.2: базовые компоненты §5.3 на native HTML/React19, semantic/component
  токенах; Tabler именованные imports16/18/20 stroke2; peer React.
- 3.3: миграция Login/Home/About/404/session/company-picker, Link через
  router adapter, локальная ErrorBoundary вместо последнего site/ui consumer.
- 3.4: app/package gates, preview E2E, 1440 before/after, fresh internal review,
  separate external review, Stage Report и отдельный PR.

Чек-лист: [x] все16 базовых компонентов exported; [x] native props/ref,
label/error/helper IDs; [x] disabled/loading/readonly; [x] external links
secure + screenreader; [x] keyboard checkbox/radio/toggle/password/clear;
[x] Toast4s с pause/cleanup; [x] site/ui consumers0; [x] auth/session tests
без изменения правил; [x] contrast/focus; [x] gates/reviews green.

SSR Vitest использует существующие ReactDOMServer и node environment; DOM
поведение проверяется настоящим Chromium/Playwright (без новых test dependencies).
Test-only fixture собирается отдельно и не попадает в dist приложений.
Нативный Select — явное требование owner brief; custom select-popup листа
не добавляется. Focus outline2px/offset2px сохраняется поверх shadow листа.

Stage3 завершён: native components/screens, fresh internal0/0(MINOR1fixed),
external0/1 IMPORTANTfixed without re-run. Scope дополняет ToastStack (fixed
viewport) и ErrorBoundary для полного удаления old UI. Отчёт stage-3.md.

## Stage 4 — технический план и приёмка (2026-11-16)

Stage3 PR3034 delivered d99ede00d; workflow43830874625 SUCCESS, оба apps
healthy SHA, HTTPS23/23 и public browser acceptance PASS.

Risk HIGH-LOCAL, branch feat/ds-v3-stage-4; base d99ede00d.
- 4.1: baseline82/78 и preview, снимки1440 до; SSR и Chromium контракты RED.
- 4.2: AppShell/AppHeader/Sidebar/PageHeader/AppFooter, useSidebarState;
  semantic/component tokens, viewport-owned scroll, keyboard/focus.
- 4.3: composition обоих apps: отдельные public/session layouts, реальные
  ссылки/realm-specific menu, текущий logout; Home/About PageHeader.
- 4.4: gates/preview/real HTTP/actual images, screenshot/scrollbar checks,
  fresh internal + external review, Stage Report, отдельный PR.

DoD: [x] header60/aside264/72; [x] вложенность aria-expanded/controls/current;
[x] rail labels sr-only + group click expands; [x] focus collapse↔expand;
[x] separate persistence account/admin + storage failure; [x] auto-rail<1280;
[x] first skip link/main focus; [x] sidebar/main scroll-stable + visible
scrollbar no jump; [x] email/current logout from real session; [x] public
about/404 no API; [x] no horizontal overflow in existing mobile smoke;
[x] package/apps tests/types/lint/build, preview/actual HTTP/CI/reviews.

Source App-Layout is canonical for composition: brand padding16, content24×32,
footer12×32. Header accepts period/search/notifications slots; apps do not
render unavailable functions. Source has user-row + logout, not dropdown: generic
DropdownMenu stays Stage5. No profile link/fictional user/role/company. Email is
identity fallback; optional role only if supplied by real data. No new API.
Group captions use existing text-tertiary to meet4.5:1 (source text-disabled
is insufficient); outline2 maintained, icons16/18/20 stroke2.

AppShell context coordinates controlled collapse/refs; app hook stores only UI
preference per realm. Responsive rail does not overwrite the desktop preference.
Public layout never mounts SessionProvider; session layout uses existing provider.
No production Docker/CI config changes or dependencies required.

Stage4 verified: SSR10, apps92/88, fixture21, nginx44PASS6skips+fixture21,
17screenshots pairs. Author IMPORTANT2fixed; fresh internal0/0/0, external
1roundREVIEW_GREEN0/0; final logout scoped fix independently checked. PR3037.

Stage4 integration based321bc79d: master PR3035/3036 merged; own apps unchanged,
site/ own diff to origin/master empty. Final delivery state/checks in PR3037.


## Stage 5 — уточнённый план и приёмка (2026-11-16)

Stage4 delivered PR3037 /558586ee0, workflow43848374373SUCCESS; both
healthy SHA, HTTPS23/23, public browser bothPASS. Stage5 base
`03a34a2db176d0ecbb67ad904f28b8a7760c57c2`, branch feat/ds-v3-stage-5.
Risk HIGH-LOCAL; отдельный PR, без site/dependencies/production config.

- 5.1 baseline92/88; contracts SSR/formatter и browser scenarios сначала RED;
  screenshots1440 existing apps before; canonical source из Git3882c5dd9.
- 5.2 data: Table/SortHeader/TotalsRow/Pagination/Tabs/KpiCard, Chip/AbcPill/
  MarketplaceMark/Avatar/TagsInput; controlled API, native attributes,
  token-only appearance. Formatters Money/Number/Percent/Date без вычислений
  бизнес-показателей; Unicode, rounding, invalid/nonfinite/large inputs tests.
- 5.3 overlays: native dialog Modal S/M/L/Drawer680/ConfirmDialog; focus trap,
  restore, Esc/top-layer/nesting, body scroll lock; DropdownMenu keyboard.
- 5.4 pickers: local synthetic lists/trees, search/disabled/clear, leaf-only
  selection, tree keyboard; composition with overlays, no new API or data.
- 5.5 test-only comprehensive fixture (not production route), apps integration
  only where an existing real consumer benefits; full module/frontend/backend
  handoff gates, screenshots, fresh internal/external review, PR Ready.

Чек-лист приёмки:
- [ ] All §3.1 components exported; strict/native props/ref, no custom design
  for missing §3.2 sheets. No fake business screens/actions.
- [ ] Money NBSP/U2212/comma/₽, zero/negative/kopecks/large values; expenses
  ordinary text, KPI only delta color; no financial calculations or API change.
- [ ] Sort aria-sort on th; totals supplied by caller; pagination bounds and
  empty state; Tabs roving focus/arrows/Home/End/disabled/panel association.
- [ ] Modal/Drawer native top layer/inert background, focus trap/restore,
  Esc only top layer, backdrop close, nested cleanup/scroll restoration.
- [ ] Menu keyboard/disabled/destructive, pickers search/leaf-only/tree keyboard.
- [ ] Both apps gates, package tests, preview/actual-nginx browser smoke,
  1440 screenshots/contrast; scope gates and all acceptance blockers.
- [ ] Fresh independent internal/external reviews, fixed B/I, Stage Report.
