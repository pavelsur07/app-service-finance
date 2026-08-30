### Stage 1: PHPStan установлен, гейт достижимо зелёный, baseline измерен — DONE

**Risk:** MEDIUM
**Owner gate:** yes
**Release candidate:** no
**Independently deployable:** yes
**Next action:** STOP, owner action required — решение о Stage 2

#### Stage scope
- Stage base commit: `015d92e088b71050a8b89fc6d26fb8b2f64b3caf`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`

#### What was done
- PHPStan 2.2.9 в `require-dev`; `composer stan` и `composer stan:baseline`.
- `site/phpstan.dist.neon`: level 8, охват `src` + `tests` — анализируется 2343 из 2345
  файлов, два теста временно исключены (см. FOLLOW-UP 2),
  `phpVersion: 80400`, `tmpDir: var/cache/phpstan`, `reportUnmatchedIgnoredErrors: true`.
- `site/phpstan-baseline.neon`: **3777 ошибок в 2412 записях, 14473 строки** — замер «до».
- `make site-stan` и `make site-stan-baseline`.
- CI-job `🔬 Static analysis`, не входящий ни в один `needs` цепочки деплоя.
- Отдельный path-фильтр `static_analysis` (`site/phpstan.dist.neon`, `site/phpstan-baseline.neon`):
  правка конфига или baseline поднимает анализ, но не тянет сборку production-образов
  и деплой. Dev-образ php-cli при этом может собраться — это среда прогона анализа.
- `composer.json` объявляет `php: ^8.4` и `config.platform.php: 8.4.24`.

#### Files changed
- `Makefile` — 2 таргета
- `CLAUDE.md` — раздел «Красный baseline»: утверждение «`make stan` не существует» больше не верно
- `.github/workflows/deploy.yml` — новый job `stan`, path-фильтр и output `static_analysis`,
  условие запуска `dev-image`
- `site/composer.json`, `site/composer.lock` — зависимость, целевая версия PHP, скрипты
- `site/symfony.lock`, `site/.gitignore` — рецепт Flex (игнор локального `phpstan.neon`)
- `site/phpstan.dist.neon` — новый
- `site/phpstan-baseline.neon` — новый, сгенерирован
- `docs/tasks/static-analysis/{plan,checkpoint}.md`, `stages/stage-1.md` — новые

#### Definition of Done
- [x] `composer stan` — exit 0
- [x] level 8, phpVersion 80400, охват src+tests (2343 из 2345; два теста временно исключены), migrations исключены
- [x] baseline закоммичен, число подавленных ошибок зафиксировано
- [x] гейт доказан красным на искусственной ошибке
- [x] `make site-stan` / `make site-stan-baseline` работают
- [x] CI-job добавлен и ничего не гейтит кроме себя
- [x] `CLAUDE.md` не противоречит реальности
- [x] `composer.json` объявляет целевую версию PHP

#### Baseline
- Статического анализа в репозитории не было — сравнивать не с чем; замер «до» и есть результат Stage.
- `composer cs:strict-types` — до и после: `Found 0 of 2345`, exit 0.
- `composer cs:check` — **зелёный**: `Found 0 of 2345`, exit 0. `CLAUDE.md` описывал его как
  хронически красный (506 из 2342, exit 8) — утверждение устарело: коммит `73a37a22`
  («apply five risky @Symfony rules, clearing site-cs-check») его вычистил. Проверено дважды:
  через `composer cs:check` и повторно через `vendor/bin/php-cs-fixer fix --dry-run --using-cache=no`,
  потому что тёплый `var/cache/.php-cs-fixer.cache` даёт ложную зелень. Конфиг не сужен —
  `.php-cs-fixer.php` держит полный `@Symfony` + `@Symfony:risky` на `src` + `tests`.
  Утверждения в `CLAUDE.md` исправлены в этом же Stage (см. «Побочные исправления»).
- `composer validate --strict` — exit 1 до и после Stage из-за constraint'ов
  `openspout/openspout` (точная версия) и `phpoffice/phpspreadsheet` (`>=5.7`). К Stage не относится.

#### Checks
- targeted: `composer stan -- --no-progress` — `[OK] No errors`, exit 0
- targeted (негативная): временный `src/Shared/StanProbe.php` с `?DateTimeImmutable->format()` — exit 1, `method.nonObject`; файл удалён
- targeted: `composer stan -- --error-format=github` — exit 0, формат для CI валиден
- module: `composer test:unit` — 1927 тестов, 10969 ассертов, OK; 4 deprecations, они были и до Stage
- full relevant stage: `composer cs:strict-types` — exit 0; `composer validate` — «valid, but with a few warnings» (pre-existing)
- структурный аудит baseline: 2412/2412 записей канонической формы `message+identifier+count+path`,
  0 сообщений без якорей `#^…$#`, 0 путей вне `src/`|`tests/` или несуществующих; распределение 1322 `src` / 1090 `tests`
- YAML workflow разобран `yaml.safe_load`; `stan.needs = [changes, dev-image]`,
  `build-and-push.needs = [changes, unit-tests, cs-check]` — не изменён

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: блок таргетов Makefile перемещён — он вклинился между `site-cs-fix` и его комментариями
- FOLLOW-UP: см. ниже

#### External Codex review
Ревьюером выступает Codex: реализацию вёл Claude Code (`CLAUDE.md`, «Внешнее ревью»).
  Итерация 1:
- Iterations: 3
- Result: **REVIEW_GREEN** на итерации 3 (BLOCKER и IMPORTANT — нет). После него внесены
  только правки комментариев и документации по трём MINOR-находкам итерации 3; исполняемое
  поведение не менялось, YAML повторно разобран.
- Confirmed findings fixed:
  - **IMPORTANT** — `treatPhpDocTypesAsCertain: false` глобально ослаблял level 8, включая новый код,
    и противоречил замыслу «зеленеем через baseline, а не через ослабление правил»; комментарий
    в конфиге описывал настройку наоборот. Параметр удалён, baseline перегенерирован:
    3641 → **3777** ошибок (+136 — ровно те диагностики, что настройка глушила).
  - **IMPORTANT** — тело baseline не было представлено ревьюеру. Во второй итерации приложены
    полный файл и машинный структурный аудит.
  - **MINOR** — `php: ">=8.4"` заявлял совместимость с PHP 9 → заменено на `^8.4`.
  - **MINOR** — комментарий к `phpVersion: 80400` утверждал «анализ под 8.4.24» → исправлен:
    80400 — нижняя граница объявленного диапазона.
  - **MINOR** — `phpstan/extension-installer` в Stage 1 не имеет функции (расширения в Stage 2)
    и попадал под запрет `AGENTS.md` на установку зависимостей, не требуемых задачей. Удалён
    вместе с записью в `allow-plugins`; вернётся в Stage 2, где будет работать.
  - **MINOR** — `checkpoint.md` перечислял ещё не существовавший `stages/stage-1.md`.
  Итерация 2 (после исправлений выше, ревьюер читал файлы с диска сам):
  - **IMPORTANT** — job `stan` запускался только по фильтру `backend`, который не включает
    `site/phpstan.dist.neon` и `site/phpstan-baseline.neon`. PR, ослабивший конфиг или
    перегенерировавший baseline «начисто», обходил гейт целиком — ratchet был обходим.
    Добавлен отдельный фильтр и output `static_analysis`; `dev-image` и `stan` запускаются
    при `backend || static_analysis`. В deployable-матрицу пути не добавлены: правка baseline
    не должна тянуть сборку образов и деплой.
  - **MINOR** — `plan.md` Work item 1.1 всё ещё требовал `extension-installer` → исправлено.
  - **MINOR** — формулировка «новый код проверяется полностью» не учитывала два исключённых
    файла → в `CLAUDE.md` и шапке `phpstan.dist.neon` указан фактический охват 2343 из 2345.
  - **MINOR** — `CLAUDE.md` указывал `Found 0 of 2342` при фактических 2345 → исправлено.
  - Аудит baseline ревьюером подтверждён независимо: 2412 канонических записей, сумма
    `count` 3777, 1322 `src` / 1090 `tests`, широких regex, glob-путей, дубликатов и
    несуществующих файлов нет.

  Итерация 3 — **REVIEW_GREEN**. Три MINOR исправлены:
  - `plan.md` и `checkpoint.md` всё ещё называли `cs:check` хронически красным;
  - Stage Report заявлял охват 2345 файлов вместо фактических 2343 из 2345;
  - формулировка «не тянет сборку образов» была шире правды: `dev-image` при
    static-analysis-only изменении собирается, если тега нет в ghcr; production-образы
    и деплой при этом не запускаются.
  Правку `CLAUDE.md` про зелёный `cs:check` ревьюер признал обоснованной и не выходящей
  за допустимый scope: она исправляет состояние, существовавшее до Stage.
- Rejected findings with reason: none

#### Побочные исправления в CLAUDE.md (вне первоначального scope, с доказательством)
Stage правит абзац «Красный baseline», поэтому проверены и соседние утверждения о гейтах.
Два оказались устаревшими и исправлены, потому что иначе Stage Report цитировал бы
как факт то, что измерение опровергает:
- «`make site-cs-check` хронически красный (506 из 2342), exit 8» → гейт зелёный,
  `Found 0 of 2345`, exit 0 (перепроверено без кэша).
- «`Found 0 of 2342`» для `cs-strict-types` → фактически 2345 файлов.
Обоснование сохранения отдельного `site-cs-strict-types` переписано: оно опиралось на
красноту `cs:check`, которой больше нет.

#### Review fixes applied
- Удалён `treatPhpDocTypesAsCertain: false`, baseline перегенерирован, гейт повторно доказан
  зелёным и красным на пробе.
- `php: ">=8.4"` → `^8.4`; `phpstan/extension-installer` удалён.
- Побочный эффект отката: `composer update --lock` при `bump-after-update: true` поднял
  constraint'ы посторонних пакетов (`league/flysystem` 3.30→3.34, `twig/twig` 3.24→3.27.1).
  Это выход за scope; откачено, лок пересобран при временно выключенном `bump-after-update`,
  значение возвращено в `true`. Итоговый дифф `composer.json` содержит только изменения Stage.

#### Risks / reviewer focus
- `config.platform.php: "8.4.24"` дублирует пин версии PHP из Dockerfile. При апгрейде
  PHP-образа это значение нужно поднять вместе с ним, иначе `composer update` продолжит
  резолвить под 8.4 — отказ консервативный и заметный, но требует ручной синхронизации.
- `reportUnmatchedIgnoredErrors: true` — по замыслу: PR, исправляющий ошибку из baseline,
  обязан обновить baseline (`make site-stan-baseline`), иначе гейт красный.
- Холодный прогон 2345 файлов на 2 ядрах ~7 мин, тёплый ~5 с. На раннере GitHub (4 ядра)
  первый прогон ожидается около 3-4 мин; job идёт параллельно и критический путь не удлиняет.

#### FOLLOW-UP (вне scope Stage 1, не реализовано)
1. **Реальный баг, найденный анализатором в первый прогон.**
   `src/Company/Application/AssignCompanyMemberAccessRoleAction.php:51` — `$roleId`
   не захвачен в `use (...)` замыкания на строке 39, но используется внутри неё
   (`identifier: variable.undefined`). На ветке «шаблон роли удалён до блокировки»
   в `CompanyRoleNotAvailableException::__construct(string $roleId, ...)` уйдёт `null`;
   файл под `strict_types=1`, значит вместо осмысленного доменного исключения будет
   `TypeError` → 500. Требует отдельной задачи с регрессионным тестом.
2. Переписать два теста с анонимными наследниками final-классов на `createMock`
   и убрать `excludePaths` из `phpstan.dist.neon`.
3. Назначить check `🔬 Static analysis` обязательным в branch protection — иначе job
   виден в PR, но не блокирует merge. Это настройка репозитория, не изменение кода;
   подтверждена находкой внешнего ревью в обеих итерациях.
4. Жёсткий ratchet. Path-фильтр гарантирует, что PHPStan прогонится при правке baseline,
   но не запрещает росту baseline: заново сгенерированный расширенный файл подавит и новые
   ошибки. Сейчас запрет роста — правило ревью, а не машинная проверка. Отдельным CI-check
   можно сравнивать записи и `count` с merge-base и падать на росте.
5. Stage 2 расширения дадут наибольшее сжатие baseline: `method.notFound` (330) и
   `property.notFound` — в основном Doctrine `getRepository()`/`$_em`, которые
   `phpstan-doctrine` типизирует.

#### Checkpoint
- `docs/tasks/static-analysis/checkpoint.md` обновлён
- exact next action: решение Владельца о Stage 2

#### Open questions
- none

#### Expected owner response
Recommended response:
`Начинай Stage 2`

Alternative responses, when relevant:
- `Стоп на Stage 1, PR оставь в Draft`
- `Сначала почини баг с $roleId отдельной задачей`
