# Stage 3: базовые компоненты ДС — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `18fa0247850e17b7ade692b26c7403385b248a38`
**Branch:** feat/ds-v3-stage-3
**Work items:** 3.1 tests/baseline; 3.2 components; 3.3 migration; 3.4 gates/reviews/handoff.

## Что изменено

- 16 базовых компонентов §5.3: Button/Link/FormField/Input/InputGroup/Textarea/
Select/Checkbox/Radio/Toggle/Badge/StatusPill/Card/EmptyState/Alert/Toast.
- ErrorBoundary для удаления последнего old-UI consumer; ToastStack закрывает
  positioning/stack contract. IconPlus — начальный named export Tabler3.52.0
  с size16/18/20, stroke2; каталог расширяется в Stage5.
- Props interfaces, native attrs/ref/required/disabled/readonly; field
  helper/error associations; controlled clear/password reveal/native reset.
- Toast4s с pause hover/focus, timer cleanup, one close; region stack
  fixed24px, gap10px,z200. Никакого переноса фокуса.
- Login/Home/About/404/session/company picker используют DS. NavigationLink
  сохраняет SPA/history/modifier clicks. Auth/session/CSRF logic unchanged.
- Apps удалили @vashfindir/ui и include старого UI из tsconfig. DS strict
  tsconfig автономен: admin image не загружает отсутствующий account config.
- Vitest SSR контракты и Chromium test-only fixture, собираемый в test-results
  (не app dist/production route). Account test:e2e включает fixture suite.

## Файлы

apps/packages/ds/src/components, icons, styles.css, index.ts, tsconfig/manifests;
apps/account и apps/admin src/manifests/tsconfig/vitest; account/tests/components;
apps/README.md, DS README и task docs. site/, Docker/CI config, backend,
миграции и generated токены не изменены.

## Definition of Done

- [x] Все компоненты §5.3 и native API экспортированы; refs/ARIA/keyboard.
- [x] Screens migration, old UI consumers/dependency0.
- [x] Timer, disabled/loading/readonly/error, contrast/focus и reset проверены.
- [x] Screenshots1440, module/full gates и reviews.
- [x] Отдельный Stage PR; deploy только по решению Владельца.

## Проверки

Baseline: make front-test48/44PASS; preview26PASS/8skips,4fresh screenshots.
SSR33 contracts RED missing exports→GREEN; с ToastStack34 contracts.
Package tests47; apps82/78 (последний вывод — в логах/PR).

- front-install/typecheck/lint/test/build обоих apps PASS, generated PASS.
- package typecheck и test PASS; новые .test.tsx включены и при package --root.
- Preview apps26PASS/8skips + fixture11PASS до ToastStack; финальный fixture
  с stack12PASS. Actual nginx builds/smoke обоих apps PASS30/4skips +fixture11;
  последние stack/action26px проверены targeted12PASS; final actual images — PR CI.
- PHPStan, CS check, strict-types PASS; site-test-unit4889 tests/27145assertions.
  Backend/integration contracts не менялись; real HTTP/HTTPS CI также проверяется.
- 15 снимков after;15before взяты из exact delivered Stage2 screenshots
  (git diff37264e03c..18fa02478 -- apps пуст) +4fresh baseline до разработки.
  /tmp/vf-ds-v3-stage3/{before-delivered-stage2,before,after}.
- Border default≥3:1; error RED2.803→GREEN с semantic danger-text;
  feedback/primary text≥4.5:1; focus outline2px+offset2px.

Проверки выявили и закрыли: label star ломал exact getByLabel (native
required сохранён); InputGroup suffix/action overlap и action disabled;
source error-border contrast; admin tsconfig extends. RED/green outputs
сохранены локально. Ошибочный запуск fixture с uid1000 исправлен явным uid.
Toast clock test использует paused clock и учитывает минимум timer browser.

## Review

Fresh internal: B/I0/0; MINOR2 fixed: Toast tones и custom InputGroup action26px (RED36→GREEN26), open0.
External separate Codex1round: B/I0/1; IMPORTANT Toast positioning fixed,
verified12browser + fresh internal, **fixed without re-run**, open0.
Ограничения и детали: ../reviews/codex-stage-3.md.

## Отклонения, риски, follow-up

- Native Select по owner brief; custom popup из листа не реализуется.
- Outline2px/offset2px поверх focus shadow — accessibility contract.
- TODO(ds): component error-border token danger-dot даёт2.80:1; runtime
  использует semantic danger-text. Исправить эталон отдельной design-правкой;
  generated токены здесь не меняются.
- TODO(ds) auth/loading/error/404 sheets остаются: минимальные композиции.
- Icon sizes в листах11/12/14 заменены разрешёнными16/18/20 stroke2.
- CSS растёт с23.19KB до~32KB: включён весь базовый DS component surface;
  JS~+2% (Tabler selected icons/new wrappers), Manrope unchanged164700B.
- DS audit baseline4moderate/2low build-only, high/critical0; apps baseline
  зависимости не обновлялись. Нет новых production mutations/migrations.

## Next

Handoff отдельного PR Stage3; после merge/deploy — Stage4 каркас.
