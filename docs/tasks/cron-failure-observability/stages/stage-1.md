### Stage 1: сделать ошибки cron-команд видимыми в GlitchTip — DONE

**Risk:** MEDIUM
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required — только для merge и для решения по корзине supercronic

#### Stage scope
- Stage base commit: `63a8db2ad4d35059f43863a653a90416bccf26d2`
- Work items completed: `1.1` (канал marketplace_ads в Sentry), `1.2` (код возврата transient-пути scheduler)

#### What was done

**Дефект подтверждён в данных прода до правки кода.** GlitchTip issue 2 «error running command:
exit status 1» — 553 события с 2026-02-20. Это supercronic: он превращает любой ненулевой код
возврата в одно и то же сообщение, поэтому все cron-задачи схлопываются в один issue и по нему
нельзя понять, какая задача сломана. Разложение последних 100 событий по `context["job.command"]`:

| Команда | Событий | Период |
|---|---|---|
| `app:mailer:healthcheck` | 44 | 8–10 августа |
| `app:ingestion:ozon-accrual:verify-rolling-refresh` | 22 | ежедневно |
| `app:ingestion:ozon-accrual:daily-maintenance` | 22 | ежедневно |
| `app:marketplace-ads:scheduler` | 10 | 20 авг – 4 сен |
| `app:storage:healthcheck` | 2 | эпизодически |

**Найдено при разборе:** канал Monolog `marketplace_ads` был исключён из sentry-хендлера
(`channels: ["!marketplace_ads"]`) в обеих секциях `when@dev` и `when@prod`. В модуле
MarketplaceAds 15 мест с `logger->error()` в этом канале, и ни одно не доходило до GlitchTip.
Собственного issue у `app:marketplace-ads:scheduler` в GlitchTip нет — его падения были видны
только внутри общей корзины. Объём при этом ограничивает не список каналов, а уровень хендлера
(`error`) и `SentryRateLimiter` (10 событий на ключ за 60 с), поэтому исключение канала не
экономило шум — оно выключало наблюдаемость целиком.

**Второе:** `AdBatchSchedulerCommand` на transient-сбое откатывает транзакцию, пишет `warning` с
явным комментарием «не инцидент, cron повторит», оставляет batch в состоянии PLANNED — и
возвращал `FAILURE`. Код возврата — тоже канал алертинга: supercronic превращает его в `error`.
Два решения в одном файле противоречили друг другу, и побеждал код возврата.

Сделано:
1. Канал `marketplace_ads` больше не исключён из sentry-хендлера. Инвариант закреплён тестом:
   у sentry-хендлера не должно быть никакого ограничения по каналам.
2. Transient-путь `AdBatchSchedulerCommand` возвращает `SUCCESS`. Затянувшийся простой ловит
   `app:marketplace-ads:reconcile` (в cron дважды в час, 6–23), который пишет `error` по каждому
   невосстановленному дню — и этот `error` теперь доходит до GlitchTip благодаря пункту 1.

#### Что было отвергнуто по ходу и почему
Первая версия стейджа добавляла `ConsoleFailureSubscriber` и `LoggedErrorTracker`: подписчик
сообщал бы о ненулевом коде возврата с именем команды, давая по issue на команду. Внешнее ревью
дало по этому три находки IMPORTANT, и перепроверка данных подтвердила главную: **дыры «команда
упала молча» в текущем коде нет**. `app:mailer:healthcheck` и `app:storage:healthcheck` пишут
свой `logger->error` (это записано в их докблоках), обе команды ozon-accrual тоже, а
`app:marketplace-ads:scheduler` писал `warning` намеренно. То есть корзина supercronic уже
сегодня дублирует именованные алерты, и подписчик добавлял бы третий канал поверх двух
существующих. Обе новые сущности вместе с тестами удалены из стейджа. Гипотеза без
подтверждения в данных не должна была доходить до кода.

#### Files changed
- `site/config/packages/monolog.yaml` — modified
- `site/src/MarketplaceAds/Command/AdBatchSchedulerCommand.php` — modified
- `site/tests/Unit/Shared/Infrastructure/Monolog/SentryChannelCoverageTest.php` — new
- `site/tests/Unit/MarketplaceAds/Command/AdBatchSchedulerCommandTest.php` — modified
- `site/tests/Integration/MarketplaceAds/Command/AdBatchSchedulerCommandTest.php` — modified

#### Definition of Done
- [x] ошибки канала `marketplace_ads` доходят до GlitchTip
- [x] ограничение sentry-хендлера по каналам закрыто инвариантным тестом (и исключения, и положительный allowlist)
- [x] transient-сбой scheduler не заводит инцидент
- [x] изменение поведения доказано красным на коде до правки
- Исключено из Stage: расщепление корзины supercronic (owner gate, см. ниже), issue 287 (P4)

#### Baseline
- `php bin/phpunit --filter AdBatchSchedulerCommandTest` — OK (13 тестов, 98 assertions)
- красного baseline в репозитории нет: cs, strict-types и stan были зелёными до задачи

#### Checks
- targeted: `php bin/phpunit --filter 'SentryChannelCoverageTest|AdBatchSchedulerCommandTest'` — OK (16 тестов, 118 assertions)
- full relevant stage: `make site-test-unit` — OK (2294, 4 pre-existing deprecations);
  `make site-test-integration` — OK (1218); `composer test:functional` — OK (598, 2 pre-existing deprecations)
- `make site-cs-check` — Found 0 of 2467
- `make site-cs-strict-types` — Found 0 of 2467
- `make site-stan` — No errors; `phpstan-baseline.neon` не менялся

**Красное доказательство изменения поведения.** На коде до правки из шести relevant-тестов
падают пять: `SentryChannelCoverageTest` (оба окружения) и три теста transient-пути
(`testTransientFailureRollsBackAndExitsSuccess`, `testTransientFailureIsLoggedAsWarningNotError`,
`testTransientFailureRollsBackAndLeavesBatchUnchanged`). Шестой — guard, зелёный и до, и после.

**Мутационная проверка инварианта каналов:** конфигурация `channels: [app]` роняет
`SentryChannelCoverageTest`, то есть тест ловит и положительный allowlist, а не только
исключения через «!».

#### Internal automatic review
- Iterations: 2
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: комментарий в `monolog.yaml` дублировался в dev и prod целиком — сокращён;
  ссылка на удалённый `ConsoleFailureSubscriber` убрана из комментария в команде
- FOLLOW-UP: см. «Risks / reviewer focus»

#### External Claude Code review
- Реализация — Claude Code, внешний ревьюер — Codex (`codex exec -s read-only --ephemeral`), по таблице ролей `CLAUDE.md`
- Iterations: 2
- Result: REVIEW_GREEN
- Confirmed findings fixed: итерация 1 дала три IMPORTANT и три MINOR по `ConsoleFailureSubscriber`
  и `LoggedErrorTracker` (дублирование с supercronic, протекание флага в `messenger:consume`,
  процессор фиксирует попытку логирования, а не доставку). Все сняты удалением обеих сущностей.
  MINOR по инварианту каналов принята: тест теперь отвергает и положительный allowlist,
  чувствительность доказана мутацией `channels: [app]`
- Rejected findings with reason: в первой итерации ревьюер написал «глобально превращать все
  ненулевые CLI-коды в SUCCESS нельзя» — это прочтение диффа неверно, менялся ровно один
  transient-путь одной команды; по существу же находка принята и сущность удалена
- Ограничение ревьюера: шелла у него не было, тесты и мутации он не запускал и результаты принял
  из промпта. Факты о проде (issue 2 и разложение его событий, отсутствие issue у ads:scheduler,
  докблоки healthcheck-команд, поведение supercronic 0.2.43) переданы в промпт

#### Review fixes applied
- Удалены `ConsoleFailureSubscriber`, `LoggedErrorTracker` и три их тестовых файла
- Усилен `SentryChannelCoverageTest`: требует полного отсутствия ограничения по каналам
- Проверены вызовы `app:marketplace-ads:scheduler` вне cron: команда встречается только в
  `docker/cron/app.cron:110` и в собственных тестах, другой автоматизации, читающей её код
  возврата, в репозитории нет

#### Risks / reviewer focus
- Канал `marketplace_ads` начнёт слать `error` в GlitchTip. Это и есть цель; объём ограничен
  уровнем хендлера и `SentryRateLimiter`. Ожидаемо появятся ранее невидимые issue из модуля —
  их не следует читать как новые поломки.
- Transient-сбой scheduler больше не виден в коде возврата. Сигнал сохранён: `warning` в логах,
  вывод команды, и `error` от `reconcile` при затянувшемся простое.
- **Не закрыто этим стейджем (owner gate):** корзина supercronic по-прежнему дублирует
  именованные алерты команд. Расщепить её на стороне supercronic нельзя — в 0.2.43 нет
  управления fingerprint, есть только `-sentry-dsn`, `-sentry-environment`, `-sentry-release`.
  DSN он берёт из `SENTRY_DSN`, который в том же контейнере нужен и PHP-процессам. Варианты:
  отдать supercronic отдельный проект GlitchTip через флаг `-sentry-dsn`, либо снять с него DSN
  совсем — оба меняют production-инфраструктуру и требуют проверки на проде.

#### Checkpoint
- `docs/tasks/cron-failure-observability/checkpoint.md` обновлён
- exact next action: решение Владельца по merge и по корзине supercronic

#### Open questions
- none

#### Expected owner response
Recommended response:
`Мержи; по supercronic решу отдельно`

Alternative responses, when relevant:
- `Оставь в Draft, посмотрю сам`
- `Отдай supercronic отдельный проект GlitchTip — подготовь изменение`
