# Stage4: общий каркас — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** d99ede00de60e210d373feea8b80e371daa521e4
**Integration base:** `d321bc79d7a104ccd01acfb51c94beb84ae95393` (master PR3035/3036, own apps unchanged)
**Branch/PR:** feat/ds-v3-stage-4 — https://github.com/pavelsur07/app-service-finance/pull/3037
**Work items:**4.1baseline/tests;4.2components;4.3apps;4.4checks/reviews/handoff.

## Что сделано

- AppShell/AppHeader/Sidebar/PageHeader/AppFooter + useSidebarState в DS.
  Header60, sidebar264/72, controlled collapse, nested disclosure/active state.
- Viewport-owned shell: main и navigation scroll независимо, stable gutter,
  footer после flex1 content. Source geometry16brand/28header/24×32content.
- First skip link/main focus; native buttons/anchors, aria controls/expanded/
  current, labels sr-only в rail. Toggle focus и responsive focus сохранены.
- Separate account/admin localStorage preferences, <1280auto rail без
  перезаписи desktop preference; storage failures безопасны.
- Public/session layouts разделены: about/404 не запрашивают сессию/API.
  Только реальные home/about routes, разные меню/realm context; identity email
  fallback и текущий logout. Никаких fake profile/role/company/actions.
- Home/About используют PageHeader; удалён прежний session card с дублирующим
  logout. Auth/session/CSRF/store и CompanyPicker business logic не менялись.
- Native attrs/ref сохраняются. Новые Tabler named icons18/stroke2.
- SSR и Chromium fixtures, новые app E2E, адаптация прежнего first-Tab smoke
  к skip link; CLI test:e2e/post lifecycle из Stage3 сохранён.

## Файлы

apps/packages/ds/src/components/{AppShell,AppHeader,Sidebar,PageHeader,AppFooter},
icons/index, styles.css/index; apps/*/src/app и Home/About; apps/*/tests/e2e;
account/tests/components/layout fixture; apps/DS README/task docs.
Site/, Docker/CI config, dependencies/locks, generated tokens не изменены.

## Definition of Done

- [x] Geometry, typography, full/rail/sidebar nesting, real app menus/footer.
- [x] A11y controls/labels/focus/skip/active state, persistence/resize/storage.
- [x] Ordinary scrollbar gutter >0, content x/width не прыгают, nav/main
  scroll independently, document не прокручивается; existing mobile smoke.
- [x] Existing auth/company/logout contracts, public no-bootstrap.
- [x] Screenshots before/after, module/full gates, fresh reviews/open0.
- [x] Separate PR; merge/deploy только по решению Владельца.

## Проверки

- Baseline front-test82/78PASS, preview26PASS8skips+base fixture12.
- New SSR10REDmissing exports→GREEN10; apps92/88, package57PASS.
- Front typecheck/lint/test/build обоих apps PASS; generated PASS.
- Browser21PASS:12base+9layout. Контраст caption/header/nav/footer≥4.5:1;
  base controls border≥3:1; focus2px; реальные reserved scrollbar widths>0.
- Preview migrated apps36PASS10skips+fixture20 до последних targeted fixes;
  final extra logout regression4PASS. Final actual nginx both builds PASS;
  final smoke44PASS6skips +fixture21PASS, включая logout-during-refresh.
- PHPStan/CS/strict-types PASS; unit4889tests/27145assertions (stage base).
- Real dev/preview/HTTPS/backend+final images также проверяются в PR CI.
- 17pairs screenshots1440 (login/error/home/company/about/404/sessionerror/
  403/loading) +full/rail screenshots обоих apps и fixture. Loading baseline
  собран из exact git d99 archive с неизменёнными locks и теми же dependencies.
  Private GHCR baseline pull вернул unauthorized; auth не расширяли, успешный
  альтернативный baseline — local exact-source build. Артефакты /tmp/vf-ds-v3-stage4.

## Review

Fresh independent internal0/0/0GREEN, последующий focused full diff GREEN.
Author IMPORTANT2(resizefocus,refresh/logout) fixed с RED→GREEN.
External Codex1round exact REVIEW_GREEN, B/I0/0, read-only stdin-only.
После scoped logout fix — independent focused review и4regression PASS;
повтор внешнего раунда не требуется. Детали ../reviews/codex-stage-4.md.

## Отклонения и follow-up

- Source fake period/search/bell/profile/role/company/ИНН не добавлялись;
  Header предоставляет slots. Public routes не узнают текущую user session.
- User-row/logout соответствует App-Layout. Generic dropdown/Avatar — Stage5;
  неподтверждённые identity fields не выдумываются.
- Source caption text-disabled заменён text-tertiary для4.5:1. Icon14/17
  заменены разрешёнными16/18/20stroke2. Header brand padding16 по screen,
  не20 из отдельного sheet. No dark mode/CDN.
- Existing TODO(ds) auth/loading/error/404 и error-border из Stage3 сохранены.
- JSaccount284181→297639B(+4.74%), admin281951→295293B(+4.73%);
  CSS32014→36022B(+12.52%) — utilities/layout/scroll/rail для нового каркаса.
  Manrope164700B неизменён; новых dependencies/security audit изменений нет.
- Миграций/ручных production mutations нет. Stage5 ещё не начат.

## Next

Latest PR CI→Ready→единственное merge+deploy #3037; после доставки Stage5.

### Integration with current master

Master advanced via approved PR3035/3036; merged into task branch before
handoff. No edits to incoming site/financial code. Own task diff against
origin/master has no site/Docker/CI/services changes; apps byte-identical
to reviewed/tested b0350c8df. Complete task review applies to this unchanged
owned diff. Integrated PHP gates rechecked; latest CI tracked in PR.
