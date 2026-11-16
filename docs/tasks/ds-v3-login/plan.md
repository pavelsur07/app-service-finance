# ds-v3-login — экран входа account/admin по ДС v3.4

## Phase 0

Задача Large; один branch `feat/ds-v3-login`, один Draft PR, три внутренних Stage.
База: `92698d36a976d41500942ba58eda0206df7abd0c` (PR #3022 смержен).
Источники: `v3/screens/Login.html` (таблица «Спецификация» выше иллюстративного CSS),
`AUTH-SPEC.md` §2–4, §6–8 (только тексты/поведение), `CLAUDE.frontend.md` apps,
`ARCHITECTURE.md` раздел SPA session, существующие LoginPage/CompanyPicker/InputGroup.
Baseline: `make front-test` — account 140, admin 136, всё зелёное.

## Технические решения и границы

- Общие AuthLayout/LoginForm/Cookie UI в `@vashfindir/ds`; только существующие атомы
  пакета, Tabler stroke 2. Размеры auth задаются локальным вариантом/областью CSS;
  другие экраны не меняются. Логотип копируется в общий Vite public/brand.
- `/login` вне AppShell, но внутри прежнего SessionProvider. Raw session stores,
  `store.login({ email, password })`, safeReturnPath и серверные контракты сохраняются.
  UI provenance различает реальный 401 после authenticated и обычный logout/bootstrap.
- Chooser после успешного входа account: GET существующего companies API, при >1
  радио + явное подтверждение; при 0/1 прежний safeReturnPath. Последняя активная
  компания предвыбрана, если присутствует на загруженной странице. Пагинация и
  обработка 401/422 сохраняются; поиск от 8 компаний по текущей странице (API не
  имеет поиска по всему каталогу, ограничение обозначить пользователю).
- В API только email/password: remember не выводить. В SPA нет forgot/register/legal
  маршрутов; не придумывать ссылки, юридические названия — текстом, registration и
  forgot скрыть. В apps нет реквизитов: блок компании скрыть до конфигурации.
  ИНН/роль отсутствуют в companies: не подставлять фиктивные значения. Все — TODO(ds).
- Cookie necessary/all, 180 дней, document event `vf:cookie-consent`, detail.level,
  SameSite=Lax, Secure для HTTPS. Аналитики в apps сейчас нет, не добавлять загрузчиков.
- Для обязательных DOM Vitest тестов минимальный dev-only jsdom (без Testing Library),
  native React act + DOM; существующий node environment для остальных тестов остаётся.
- Никаких изменений `site/`, backend, CI/CD, production config или session API.

## Stage 1 — общее представление входа и состояния

Risk: HIGH-LOCAL (UI auth); stage_base_commit: `92698d36a976d41500942ba58eda0206df7abd0c`.
Definition of Done: общий экран/форма/cookie, корректные размеры, валидация/focus,
loading/401/eye; обе страницы используют пакет, API stores не изменены.
Work items: 1.1 DOM regression tests RED; 1.2 shared UI и auth variants; 1.3 интеграция
account/admin + expiry provenance, тесты GREEN.
Checks: focused Vitest → module Vitest + typecheck/lint. Reviewer focus: generic login
error, clear/focus, logout false positives, доступность, отсутствие ложных URL.

## Stage 2 — выбор компании

Risk: MEDIUM; stage_base_commit: `62fbe9d82`.
Definition of Done: card 520/radio/initials/Continue/logout, search≥8, pagination,
после входа company choice не теряет returnTo, прежние 401/422/reconciliation работают.
Work items: 2.1 tests RED; 2.2 CompanyPicker presentation + post-login UI; 2.3 checks.
Checks: focused Vitest + existing session/company E2E. Reviewer focus: scope/company,
page changes, no forced company mutation, race/cancellation.

## Stage 3 — браузерная и визуальная приёмка, handoff

Risk: MEDIUM; stage_base_commit: записать перед началом.
Definition of Done: account/admin 1440/390, loading/invalid password/cookie/expired,
парные скриншоты с эталоном, contrast/focus, full frontend gates, свежий internal и
external review, Ready PR + handoff с отклонениями/TODO.
Work items: 3.1 E2E tests first; 3.2 screenshot/contrast acceptance + fixes;
3.3 full gates/reviews/report/commit/push/Ready.
Checks: `make front-typecheck front-lint front-test front-build`,
`make front-e2e MODE=preview`, `git diff --stat origin/master -- site/` (пуст).
Reviewer focus: оба приложения/ширины, форма и keyboard, source fidelity, no dead links.
External review: один общий handoff; если Stage 1 auth diff >500 строк, отдельный
review этого Stage по AGENTS §7.2. Fresh internal reviewer read-only.

## Чек-лист приёмки

- [ ] Logo 32, header 20×40/16×20, card 440/520, mobile ≤560 square/full width.
- [ ] h1 28/34, fields 40/text14, eye32/icon18, CTA44, cookie actions32.
- [ ] Пустая форма: обе ошибки, focus email; email blur/invalid; aria-describedby.
- [ ] Loading disabled/aria-busy/spinner; 401 generic alert/clear/focus; other error.
- [ ] Expired only after genuine prior-auth 401; logout/initial guest без info;
      safeReturnPath unchanged, returnTo respected.
- [ ] account chooser >1, radio/selected/disabled, search≥8, pagination/401/422.
- [ ] Admin «Вход для сотрудников», без регистрации; нет вымышленных ссылок/API.
- [ ] Footer legal text/note/copy, no fake company data; cookie persists180days/event.
- [ ] Скриншоты 1440/390 рядом с эталоном; text≥4.5, field≥3, visible focus.
- [ ] Full frontend gates + preview E2E; internal/external no open BLOCKER/IMPORTANT.
- [ ] site diff пуст; один PR, merge/deploy только после handoff решения Владельца.
