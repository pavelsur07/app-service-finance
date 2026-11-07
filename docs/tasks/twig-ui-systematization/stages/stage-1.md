### Stage 1: гейт Twig и конвенции — DONE

**Risk:** LOW (стиль внутри Twig-тегов, конфиг линта, документация; новый
workflow вне цепочки `deploy.yml`)
**Stage base commit:** `02930481b` (master) · план `5c0b59a4d`
**Work items:** 1.0–1.5

#### What was done
- Конвенции Twig — `docs/workflow/twig-conventions.md`: три shell'а, имена
  партиалов и макросов, что в шаблоне не живёт (JS, Twig-в-JS, `confirm()`,
  `<style>`, словари подписей, `number_format`, свой flash), стиль twigcs, проверки.
  Ссылки: `AGENTS.md` (таблица документов), `CLAUDE.md` (гейты), `CLAUDE.frontend.md`,
  раздел «Twig» в `docs/maintenance/quality-gates.md`.
- Ruleset проекта `site/tests/Twigcs/Ruleset.php`: Official без `LowerCaseVariable`
  (650 ложных: переменные camelCase как свойства DTO). Конфиг `site/.twigcs.php`
  (переименован из `.twig-cs-fixer.php` — это конфиг twigcs, не фиксера), порог
  `error`, вывод только блокирующих.
- 2286 → 0 нарушений. 1636 правок пробелов внутри `{{ }}`/`{% %}` сделаны
  скриптом по отчёту twigcs (колонки — байтовые) и проверены `git diff -w`:
  по 76 файлам он пуст. 20 многострочных выражений в 10 файлах сведены в одну
  строку: twigcs требует пробел вокруг оператора и `? :` на одной строке, перенос
  допускает только внутри хэша и аргументов функции.
- Удалены 2 неиспользуемых `{% import %}` (`balance/target`,
  `marketplace/ozon_reconciliation/operations`). Остальные 74 предупреждения
  «unused variable» — ложные (переменные layout'а `active_tab`/`app_nav_active`,
  `i` циклов, import внутри блоков): скрыты порогом, в код не вмешиваемся.
- `make site-cs-twig` (twigcs + `lint:twig`); workflow
  `.github/workflows/twig-lint.yml` на `pull_request` по `site/templates/**` и
  `push` в `master` — отдельный, деплой от него не зависит, в required checks
  не входит.

#### Files changed
- `docs/workflow/twig-conventions.md` — new; `AGENTS.md`, `CLAUDE.md`,
  `CLAUDE.frontend.md`, `docs/maintenance/quality-gates.md` — ссылки и раздел
- `site/tests/Twigcs/Ruleset.php` — new; `site/.twigcs.php` — renamed + rewritten;
  `site/composer.json` — путь конфига; `Makefile` — `site-cs-twig`;
  `.github/workflows/twig-lint.yml` — new
- `site/templates/**` — 88 файлов: 76 только пробелы в тегах; 10 — перенос
  выражений в одну строку; 2 — удалён import
- `docs/tasks/twig-ui-systematization/{plan,checkpoint}.md`, этот отчёт

#### Definition of Done
- [x] `docs/workflow/twig-conventions.md` и ссылки
- [x] ruleset без lower_case; остальные нарушения исправлены
- [x] `composer cs:twig` → `No violation found`; `lint:twig` → OK (319)
- [x] `make site-cs-twig`; workflow `twig-lint.yml` вне `deploy.yml`
- [x] неиспользуемые import удалены (2 реальных), ложные предупреждения скрыты порогом
- [x] Список 41 шаблона без функционального теста — `checkpoint.md`

#### Checks
- baseline: `composer cs:twig` — 2286 violations; `lint:twig` — OK
- `composer cs:twig` — `No violation found`, exit 0
- `make site-cs-twig` — twigcs 0, `lint:twig` OK 319
- `make site-cs-check` — 0 of 3897; `make site-cs-strict-types` — 0
- `make site-stan` — `[OK] No errors`
- `make site-test` — OK (8486 tests, 47661 assertions), 6 мин 56 с
- `git diff -w --name-only -- site/templates` — ровно 12 файлов (10 reflow + 2 import)

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none. MINOR fixed: при удалении import в
  `balance/target` случайно снята строка `form_theme` — восстановлена до коммита
  (поймано по `git diff`). FOLLOW-UP: `partials/_sidebar` — пять условий подсветки
  меню стали строками по 200–400 символов; читаемость вернёт Stage 6 (меню в PHP).

#### External review
- required: yes (Large, handoff этапа)
- rounds: 1; result: REVIEW_GREEN (codex, effort medium, дифф 2176 строк,
  `site/var/external-review/02930481/review.txt`)

#### Risks / reviewer focus
- Эквивалентность 20 сведённых выражений (список файлов выше) — единственное
  место, где diff не whitespace-only.
- В prod Twig не строгий: пропавшая переменная не упадёт. Страховка этапа —
  доказательство «только пробелы в тегах», не тесты.
- Новый workflow может быть красным на bare runner из-за окружения `lint:twig`
  (переменные `.env.test`): проверить первый прогон на PR; деплой он не блокирует.

#### Next
- handoff: PR этапа, «merge and deploy»; Stage 2 — после мержа (правит те же файлы)
