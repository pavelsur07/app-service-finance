### Stage 2: расширения PHPStan (Symfony, Doctrine, PHPUnit, webmozart-assert) — DONE

**Risk:** MEDIUM
**Owner gate:** yes
**Release candidate:** no
**Independently deployable:** yes
**Next action:** STOP, owner action required — решение о Stage 3

#### Stage scope
- Stage base commit: `d902c511deb2d8867d58419645b798b417e0f329`
- Work items completed: `2.1`, `2.2`, `2.3`, `2.4`, `2.5`

#### What was done
- Установлены `phpstan/extension-installer`, `phpstan/phpstan-symfony`,
  `phpstan/phpstan-doctrine`, `phpstan/phpstan-phpunit`, `phpstan/phpstan-webmozart-assert`.
- `site/tests/object-manager.php` — загрузчик EntityManager для phpstan-doctrine:
  117 маппингов, окружение `test`, соединение с БД не открывается.
- `phpstan.dist.neon`: `symfony.containerXmlPath` → test-контейнер,
  `doctrine.objectManagerLoader` → загрузчик.
- Прогрев контейнера встроен как предусловие: `site-stan-prepare` в Makefile,
  отдельный шаг `🔥 Warm Symfony container` в CI-job.
- Baseline перегенерирован: **3777 → 3525** (2358 записей, 14149 строк, 1428 `src` / 930 `tests`).

#### Files changed
- `site/composer.json`, `site/composer.lock` — 5 dev-зависимостей
- `site/tests/object-manager.php` — новый
- `site/phpstan.dist.neon`, `site/phpstan-baseline.neon` — параметры расширений и перегенерация
- `Makefile` — цель `site-stan-prepare` как предусловие двух других
- `.github/workflows/deploy.yml` — шаг прогрева контейнера
- `CLAUDE.md`, `docs/tasks/static-analysis/plan.md`, `stages/stage-2.md`, `checkpoint.md`

#### Definition of Done
- [x] Пять расширений установлены и автоматически зарегистрированы
- [x] `containerXmlPath` и `objectManagerLoader` объявлены
- [x] `tests/object-manager.php` работает без живой БД
- [x] `make site-stan` работает из чистого состояния — прогрев внутри цели
- [x] CI-job прогревает контейнер до анализа
- [x] Baseline перегенерирован, дельта разобрана по категориям
- [x] Гейт зелёный и доказан красным пробой, ловящейся именно расширением
- [x] Отсутствие контейнера даёт явный отказ, а не ложную зелень

#### Baseline и дельта
Замер «до» Stage 2 — 3777 ошибок (итог Stage 1). После расширений — **3525**.
Чистая разница −252 скрывает суть: расширения одновременно сняли слепоту
анализатора и добавили собственные правила.

Снято (анализатор больше не ошибается):

| Категория | было → стало |
|---|---|
| `method.notFound` | 742 → 559 (−183) |
| `callable.nonCallable` | 82 → 0 (−82) |
| `argument.type` | 1152 → 1071 (−81) |
| `assign.propertyType` | 106 → 28 (−78) |
| `property.onlyWritten` | 27 → 9 (−18) |
| `offsetAccess.notFound` | 45 → 30 (−15) |
| прочие категории | −19 |

Добавлено (новые правила расширений):

| Категория | было → стало |
|---|---|
| `missingType.generics` | 87 → 151 (+64) |
| `staticMethod.alreadyNarrowedType` | 21 → 70 (+49) |
| `doctrine.columnType` | 0 → 44 |
| `phpunit.assertCount` | 0 → 35 |
| `doctrine.associationType` | 0 → 17 |
| прочие категории | +15 |

Итог сходится: −457 − 19 + 209 + 15 = −252, то есть 3777 → 3525.

`doctrine.columnType` в основном про идиому `#[ORM\Column(type: 'guid')] private ?string $id = null`:
свойство nullable, колонка — нет. Это соглашение проекта, а не дефект; записи ушли в baseline.

Промежуточный замер выбора контейнера: dev → test снимает ровно 5
`symfonyContainer.serviceNotFound` (Monolog `TestHandler` и тестовые фикстуры)
и не меняет ни одной другой категории (3530 → 3525).

#### Checks
- targeted: `composer stan -- --no-progress` — `[OK] No errors`, exit 0
- targeted (краснота + доказательство расширения): временный `src/Shared/StanProbe.php`
  с `$em->getRepository(MoneyAccount::class)->find($id)->definitelyNotAMethod()` — exit 1,
  «Cannot call method definitelyNotAMethod() on `App\Cash\Entity\Accounts\MoneyAccount|null`».
  Без расширения `find()` вернул бы `object` и класс не был бы назван — проба доказывает
  и гейт, и работу расширения. Файл удалён.
- targeted (режим отказа): при отсутствующем `var/cache/test/App_KernelTestDebugContainer.xml`
  анализ падает с exit 1 и явным `hash_file(...): No such file or directory` —
  ложной зелени нет. Оттуда же видно, что XML участвует в ключе result cache PHPStan.
- targeted: загрузчик проверен отдельно — `Doctrine\ORM\EntityManager`, окружение `test`,
  117 маппингов, `App\Tests\Fixtures\Doctrine\MoneyHolder` виден
- module: `composer test:unit` — 1927 тестов, 10969 ассертов, OK; 4 deprecations (были и до Stage)
- full relevant stage: `composer cs:strict-types` — `Found 0 of 2346`, exit 0;
  `composer cs:check` — `Found 0 of 2346`, exit 0
- YAML разобран `yaml.safe_load`; `build-and-push.needs` не изменён
- `composer.lock` — только 5 новых пакетов, посторонних bump'ов нет
- `site/.dockerignore` исключает `tests/` — загрузчик в прод-образ не попадает

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: none
- FOLLOW-UP: см. ниже

#### External Codex review
- Iterations: 2
- Result: **REVIEW_GREEN** на итерации 2. После него внесены правки только
  комментариев и документации по трём MINOR-находкам итерации 2.
- Confirmed findings fixed:
  - **IMPORTANT** — загрузчик Doctrine поднимал Kernel в окружении из `APP_ENV` (= `dev`),
    тогда как `containerXmlPath` указывал на test-контейнер. Расхождение проверено
    независимо: `when@test` в `config/packages/doctrine.yaml` добавляет маппинг
    `TestFixtures` (`tests/Fixtures/Doctrine/MoneyHolder.php`), которого в dev нет —
    116 маппингов против 117. Загрузчик пришпилен к `test` и больше не зависит от
    окружения запускающего. Перегенерация baseline: 3525 → 3525, ни одна категория не
    изменилась — исправление снимает расхождение конфигурации и будущий риск
    (test-only маппинги были бы невидимы), а не текущие находки.
  - **MINOR** — комментарий обещал, что обращение из `src` к test-only сервису «поймали бы
    тесты». Гарантии нет; переписано как явно принятый false negative с ценой строгой
    альтернативы (два прогона с разными контейнерами — вдвое дороже по времени CI).
  - **MINOR** — устаревшие числа в комментариях: `var/cache/dev` → `var/cache/test`,
    2345 → 2344 анализируемых файла, 1090 → 930 тестовых записей baseline,
    116 → 117 маппингов.

  Итерация 2 — **REVIEW_GREEN**. Три MINOR исправлены:
  - комментарий называл test-контейнер надмножеством dev; на деле
    `config/services_test.yaml` подменяет реализации и теги production-сервисов —
    формулировка сужена до «на текущем наборе диагностик разницы нет»;
  - комментарий утверждал, что расширение снимает `$this->_em`; фактически эти записи
    остались в baseline и являются реальным долгом ORM 3 — см. раздел выше;
  - таблицы дельты не сходились на 4 ошибки — добавлены строки «прочие категории».
- Rejected findings with reason: none

#### Risks / reviewer focus
- Принятый false negative выбора test-контейнера описан в `phpstan.dist.neon`.
- Прогрев контейнера — отдельный падающий шаг; ошибка компиляции контейнера читается
  как ошибка контейнера, а не как ошибка анализа.
- CI-job удлинился на прогрев (~20-30 с). Job по-прежнему не входит ни в один `needs`
  цепочки `build-and-push → schema-ready → deploy`.

#### Второй реальный баг, найденный анализатором (вне scope, не исправлен)
`src/Ai/Repository/AiRunRepository.php:26,29` и `src/Ai/Repository/AiSuggestionRepository.php:25,28`
обращаются к `$this->_em`. В Doctrine ORM 3 `EntityRepository` хранит менеджер в
`private readonly EntityManagerInterface $em` — свойства `$_em` не существует
(проверено по `vendor/doctrine/orm/src/EntityRepository.php:44`). Это шаблон
MakerBundle времён ORM 2, переживший миграцию на ORM 3.

Отказ детерминированный, не гоночный: любой вызов `save()` даёт «Undefined property
`$_em`» и следом фатальную «Call to a member function persist() on null». Методы
живые — их вызывают `src/Ai/Service/AiAgentRunner.php:47`,
`src/Ai/Service/Agent/CashflowAgent.php:51,66,271`, а также консольная команда
`RunCashflowAgentsCommand`. Дополнительно это нарушает правило `CLAUDE.md`
«`flush()` только в Action, не в Repository».

Не исправлено сознательно: Stage 2 подключает расширения, а не чинит найденное;
исправление требует регрессионного теста и затрагивает модуль Ai. Отдельная задача.

#### FOLLOW-UP (вне scope Stage 2, не реализовано)
1. Баг `$roleId` из Stage 1 — `src/Company/Application/AssignCompanyMemberAccessRoleAction.php:51`.
   Отдельная задача с регрессионным тестом.
2. Переписать два теста с анонимными наследниками final-классов на `createMock`
   и убрать `excludePaths`.
3. Назначить check `🔬 Static analysis` обязательным в branch protection.
4. Жёсткий ratchet: CI-check, запрещающий рост baseline относительно merge-base.
5. `doctrine.columnType` (44) — решить как соглашение: либо `#[ORM\Column(nullable: true)]`
   на id-свойствах, либо `@phpstan-ignore` на уровне соглашения. Сейчас в baseline.
6. Stage 3 — PHPat и правила границ модулей.

#### Checkpoint
- `docs/tasks/static-analysis/checkpoint.md` обновлён
- exact next action: решение Владельца о Stage 3

#### Open questions
- none

#### Expected owner response
Recommended response:
`Начинай Stage 3`

Alternative responses, when relevant:
- `Стоп на Stage 2, PR оставь в Draft`
- `Сначала почини баг с $roleId отдельной задачей`
