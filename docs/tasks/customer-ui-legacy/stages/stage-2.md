### Stage 2: API на Tabler и завершение переключения — DONE

**Risk:** MEDIUM. **Stage base:** `fe29a8f8d`. **Work items:** 2.1–2.3.

- Шесть API-маршрутов и экраны результата/ошибок используют legacy-shell,
  Bootstrap form theme, Tabler tables/badges, общую legacy-пагинацию и empty state.
- Сохранены API JS hooks, копирование, очистка секрета, матрица и наборы прав.
- Удалены пользовательские app-layout, header/sidebar, dashboard, flash и
  переключатель. Общие kit assets и app/_ui pagination/tabs, нужные admin, сохранены.
- Админка и apps отсутствуют в diff. Auth, бюджет и Unit уже на legacy-слое,
  их код не менялся.

**Tests:** red — 3 failures (API shell и наличие переключателя); green —
30 tests / 282 assertions. Модульные Functional/Api + Functional/Finance +
UiModeControllerTest + PublicRegistrationFlowTest + InviteRegistrationFlowTest:
241 tests / 3763 assertions — OK. После правки checkbox-label повторно
ApiPermissionsControllerTest: 7 tests / 69 assertions — OK.

**Frontend:** lint/build — OK; test:unit — 10 files, 152 tests passed.
Focused twigcs — no violations; lint:twig — 8 files valid. Focused PHP fixer —
0/4 files; git diff --check — OK. Новых Vite entry и CSS нет; JS/CSS исходники
не менялись. Legacy JS после сборки 123.92 kB (gzip 37.16 kB); размер до не измерялся.

**Browser smoke:** Chromium на отрендеренных Symfony test fixtures, без
production и внешних операций. 9 экранов × 1440px/390px = 18 проверок:
legacy, отсутствие переключателя/kit markup, отсутствие горизонтального
переполнения; наборы прав, Tab, copy, pagehide clearing — OK. Секрет в fixture
заменён placeholder; DB transaction откатана. Скриншоты локальные, в ignored var/.
Это browser smoke HTML/JS; серверное поведение доказано функциональными тестами.

**Internal review:** BLOCKER 0, IMPORTANT 1 fixed — Bootstrap checkbox theme
выводил технические scope-коды; label:false сохраняет aria-label и убирает текст.
Сохранены CSRF, enabled/disabled состояния, optimistic conflict и одноразовый секрет.
Открытых BLOCKER/IMPORTANT нет.
**External:** на этом MEDIUM Stage не требуется; handoff pending.

**Tool corrections:** fixer из контейнера не имел write-доступа к исходнику;
порядок import исправлен apply_patch и проверен dry-run. Первый CDP copy тест
не имел focus/user gesture; исправлен тестовый harness, повторная проверка OK.

**Next:** Stage 3 автоматически — полные гейты, независимое review, handoff.
