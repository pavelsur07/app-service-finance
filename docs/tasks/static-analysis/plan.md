# Задача: статический анализ типизации (PHPStan)

**Источник задачи:** брифинг Владельца в чате от 2026-08-30 — «Необходимо добавить
анализатор проверку типизации, предложи бэст практики» → предложение принято,
команда «начинай Stage 1».

**Базовый коммит задачи:** `015d92e088b71050a8b89fc6d26fb8b2f64b3caf`
**Ветка:** `chore/phpstan-static-analysis`

## Контекст

В репозитории нет никакого статического анализа: ни PHPStan, ни Psalm, ни Rector.
Единственные PHP-гейты — `composer cs:check` и `composer cs:strict-types`, оба
зелёные (`Found 0 of 2345`, exit 0; cs:check перепроверен с `--using-cache=no`).
`CLAUDE.md` описывал `cs:check` как хронически красный (506 из 2342) — это
утверждение устарело: коммит `73a37a22`, предок `stage_base_commit`, его вычистил.
База:
`site/src` — 1677 файлов, `site/tests` — 668, PHP 8.4.24, Symfony 7.4, Doctrine ORM 3.6.

Выбран PHPStan 2.x, а не Psalm: живая экосистема расширений под Symfony/Doctrine/
PHPUnit/webmozart-assert и `--generate-baseline`, спроектированный ровно под
большую базу без предшествующего анализа. Два анализатора одновременно не ставим.

## Ключевое ограничение

`CLAUDE.md`: «Хронически красный гейт — отдельный дефект: либо сделать его
достижимо зелёным, либо удалить». `cs:check` уже проходил через это состояние
и был вычищен отдельной работой; входить в него повторно нельзя. Поэтому
гейт вводится сразу зелёным через закоммиченный `phpstan-baseline.neon`, а весь
новый код с первого дня проверяется на полном уровне.

---

## Stage 1: PHPStan установлен, гейт достижимо зелёный, baseline измерен
Risk: MEDIUM
owner_gate: yes
release_candidate: no
independently_deployable: yes
stage_base_commit: `015d92e088b71050a8b89fc6d26fb8b2f64b3caf`

Обоснование риска: добавляется только dev-зависимость и **дополнительный**
CI-job. Runtime-код, схема БД, Messenger, контракты не затрагиваются. Новый job
не добавляется в `needs` job'а `build-and-push` — он гейтит PR, но не блокирует
сборку и деплой, то есть поведение Production-пайплайна не меняется.

Обоснование `owner_gate: yes`: Владелец санкционировал именно Stage 1; Stage 2-4
требуют отдельного решения.

### Definition of Done
- `composer stan` завершается с кодом 0 на текущем `master` + Stage-диффе.
- Уровень анализа — 8, `phpVersion: 80400`, охват `src` + `tests`, `migrations` исключены.
- `phpstan-baseline.neon` закоммичен; число подавленных ошибок зафиксировано в Stage Report как замер «до».
- Внесена искусственная типовая ошибка → гейт краснеет; ошибка убрана → гейт зеленеет (доказательство, что гейт не декоративный).
- `make site-stan` и `make site-stan-baseline` работают.
- CI-job `🔬 Static analysis` добавлен, ничего не гейтит кроме себя, использует тот же dev-образ и кэш.
- `CLAUDE.md` больше не утверждает «`make stan` в проекте не существует».
- `composer.json` объявляет целевую версию PHP (`require.php`, `config.platform.php`).

### Явно вне scope Stage 1
- Расширения phpstan-symfony / phpstan-doctrine / phpstan-phpunit / webmozart-assert (Stage 2).
- PHPat и правила границ модулей (Stage 3).
- Собственные правила (`companyId` в Repository), `spaze/phpstan-disallowed-calls` (Stage 4).
- Любое исправление уже существующих ошибок типизации — это сжатие baseline, не Stage 1.
- Rector.

### Work items
- 1.1 — `composer require --dev phpstan/phpstan`; объявить `require.php` и `config.platform.php`. (`phpstan/extension-installer` перенесён в Stage 2 по находке внешнего ревью: в Stage 1 у него нет функции.)
- 1.2 — `site/phpstan.dist.neon` + composer-скрипты `stan`, `stan:baseline`.
- 1.3 — сгенерировать и закоммитить `phpstan-baseline.neon`; проверить exit 0 и красноту на искусственной ошибке.
- 1.4 — `make site-stan`, `make site-stan-baseline`; правка `CLAUDE.md`.
- 1.5 — CI-job `🔬 Static analysis` в `.github/workflows/deploy.yml`.

### Stage checks
- `composer stan` — exit 0.
- Негативная проверка гейта на искусственной ошибке — ненулевой exit.
- `composer cs:strict-types` — сравнение с baseline (был зелёный).
- `composer cs:check` — сравнение с baseline (зелёный), только по изменённым файлам.
- `composer test:unit` — не должен измениться.
- `actionlint` или ручная проверка YAML-синтаксиса workflow.

### Reviewer focus
- Новый CI-job не меняет условия запуска и `needs` существующих job'ов, не влияет на deploy.
- Baseline сгенерирован, а не подогнан; уровень не занижен ради зелёного.
- Кэш PHPStan пишется в `site/var/`, не в репозиторий; `.gitignore` покрывает его.
- `config.platform.php` не расходится с версией PHP в Docker-образах (8.4.24).

---

## Stage 2: расширения PHPStan (Symfony, Doctrine, PHPUnit, webmozart-assert)
Risk: MEDIUM
owner_gate: yes
release_candidate: no
independently_deployable: yes
stage_base_commit: `d902c511deb2d8867d58419645b798b417e0f329`

Обоснование риска: dev-зависимости и правка конфига анализатора плюс один
дополнительный шаг в CI-job `stan`. Runtime-код, схема БД, Messenger и контракты
не затрагиваются; цепочка деплоя по-прежнему не зависит от job'а `stan`.

Смысл Stage: без расширений PHPStan не понимает Symfony DI и Doctrine, и
значительная часть из 3777 записей baseline — не долг, а слепота анализатора.
В топе baseline Stage 1: `method.notFound` — 330, `property.notFound` (в основном
`$_em` в Repository), `missingType.generics` — 87. Расширения должны их снять,
одновременно добавив собственные правила (валидность service id, DQL, маппинг).

### Definition of Done
- Установлены `phpstan/extension-installer`, `phpstan/phpstan-symfony`,
  `phpstan/phpstan-doctrine`, `phpstan/phpstan-phpunit`, `phpstan/phpstan-webmozart-assert`.
- `phpstan.dist.neon` объявляет `containerXmlPath` и `objectManagerLoader`.
- Создан `site/tests/object-manager.php`; он не требует живой БД.
- `make site-stan` работает «из чистого состояния»: прогрев контейнера — часть цели,
  а не отдельное знание в голове разработчика.
- CI-job `stan` прогревает контейнер до анализа.
- Baseline перегенерирован; дельта «до/после» зафиксирована с разбивкой по категориям.
- Гейт остаётся достижимо зелёным (exit 0) и доказан красным на искусственной ошибке,
  причём проба должна ловиться именно новым расширением, а не общим правилом.
- Отсутствие прогретого контейнера даёт понятный отказ, а не ложную зелень.

### Явно вне scope Stage 2
- PHPat и правила границ модулей (Stage 3).
- Собственные правила и `spaze/phpstan-disallowed-calls` (Stage 4).
- Сжатие baseline сверх того, что расширения снимают автоматически.
- Исправление найденного в Stage 1 бага с `$roleId` — отдельная задача с регрессионным тестом.
- `consoleApplicationLoader` расширения Symfony — не нужен для текущего охвата.

### Work items
- 2.1 — установить extension-installer и четыре расширения.
- 2.2 — `site/tests/object-manager.php`.
- 2.3 — параметры `symfony.containerXmlPath` и `doctrine.objectManagerLoader` в конфиге.
- 2.4 — прогрев контейнера в `make site-stan`/`site-stan-baseline` и в CI-job.
- 2.5 — перегенерация baseline, замер дельты, доказательство гейта зелёным и красным.

### Stage checks
- `composer stan` — exit 0.
- Негативная проверка правилом расширения — ненулевой exit.
- Проверка отказа без прогретого контейнера — понятная ошибка, не ложная зелень.
- `composer test:unit` — без изменений.
- `composer cs:strict-types` — новый PHP-файл `tests/object-manager.php` обязан пройти гейт.
- YAML workflow разбирается, цепочка деплоя не изменена.

### Reviewer focus
- `tests/object-manager.php` не тянет живую БД и не попадает в прод-образ.
- Дельта baseline объяснена: что снято расширениями, что добавлено их правилами.
- Прогрев контейнера не маскирует ошибку компиляции контейнера.
- Кэш PHPStan в CI инвалидируется при изменении конфига.

## Stage 3: PHPat — границы модулей и Facade
Risk: MEDIUM · owner_gate: yes · release_candidate: no · independently_deployable: yes
Не начат.

## Stage 4: ratchet — запрещённые вызовы, правило `companyId`, сжатие baseline
Risk: MEDIUM · owner_gate: yes · release_candidate: no · independently_deployable: yes
Не начат.
