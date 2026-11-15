# Удаление шести переменных — технический план

> Для исполнителя: применять `executing-plans` либо согласованный
> `subagent-driven-development`; workflow и расположение документов — по
> корневому `AGENTS.md`. Этот PR содержит только план, не реализацию.

**Цель:** постоянно включить выпущенные сервисы и SourceFacts, оставить выдачу
ИИ-доступа кабинетам в существующей админке, удалить шесть временных Variables.

**Архитектура:** существующие Facade и `ai_company_access`; SourceFacts без
списка кабинетов; штатный Compose/deploy без временных ветвей запуска.
Telegram сохраняет отдельную семантику новых платных разборов и ручной работы
с существующими черновиками. Новых настроек и интерфейсов доступа не вводить.

**Стек:** Symfony/PHP, Doctrine DBAL, Messenger, Docker Compose, GitHub Actions;
PHPUnit и существующие shell/Python проверки deployment.

**Spec:** [TASK.md](TASK.md). **Класс реализации:** Large, 3 Stage,
1 implementation branch и 1 Draft PR от актуального `master`.

## Ограничения и baseline

- Scope — только шесть имён из TASK.md; никаких заменяющих env-флагов.
- Порядок: тесты RED → минимальное изменение → GREEN → focused review.
- Проверки company/actor/binding/financial write, Confirm, лимиты, идемпотентность
  и защита от legacy replay обязательны. Админский grant не выдаётся автоматически.
- Dev-профили не включать постоянно; удалить только устаревшие флаги и ожидания.
- Не вызывать провайдеров, Telegram или production-команды обработки для тестов.
- Перед кодом перечитать актуальный `master`, root/ближайшие AGENTS, CLAUDE,
  нужные разделы ARCHITECTURE/PATTERNS, service-gateway и health-gates.
- Перед Stage записывать `stage_base_commit`, после Work item — checkpoint;
  после Stage — модульные проверки, полный Stage diff review, Stage Report,
  commit/push/обновление Draft PR. Между Stage нет owner gate.
- Baseline реализации пока **не запускался**: текущая поставка — документация.
  В Phase 0 выполнить `make test-deploy-workflow` и узкие PHP-наборы из Stage 1–2
  на неизменном коде; сохранить команды, результаты и существующие ошибки.
  Проверить, что контейнер PHP смонтирован именно на implementation worktree.
- Именованные разрешения на CI/CD/production-конфигурацию и финальную очистку
  GitHub проверяются по TASK.md; планирование их не предоставляет.

## Review focus

1. Новый ИИ-кабинет вне прежнего Telegram pilot получает доступ без deploy;
   кабинет без grant не получает платный разбор автоматически.
2. Отзыв grant запрещает новые LLM-вызовы, сохраняя ручную работу с уже созданным
   черновиком и доставку; отзыв финансовых прав по-прежнему запрещает Confirm.
3. Recovery продолжает работу после сбоя одного кабинета/канала, не вызывает
   legacy parser и не создаёт финансовых операций.
4. Обязательные миграции/проверки секретов/consumer-first/закрытый stdin остаются
   перед запуском; обычный `up` не запускает nightly при выключенном его флаге.
5. SourceFacts принимает разные кабинеты, не доверяя чужому connection/company;
   неизвестное остаётся в карантине, ack — после commit.

## Stage 1 — ИИ-доступ и SourceFacts без временных флагов

**Risk:** HIGH-LOCAL. **stage_base_commit:** записать перед первым Work item.
**DoD:** доступ компаний задаёт админка; SourceFacts работает для всех действительных
подключений; существующие проверки и health-check не зависят от удаляемого scope.

**Файлы:**

- `site/src/ServiceGateway/Application/Service/AiServiceAccess.php`
- `site/src/ServiceGateway/Facade/AiTaskFacade.php`, `AiTaskFacadeInterface.php`
- `site/src/SourceFacts/Application/Action/PullSourceFactsAction.php`
- `site/src/SourceFacts/Command/SourceFactsPullCommand.php`,
  `SourceFactsHealthCheckCommand.php`
- Тесты: `site/tests/Unit/ServiceGateway/Application/Service/AiServiceAccessTest.php`,
  `site/tests/Unit/ServiceGateway/Facade/AiTaskFacadeTest.php`,
  `site/tests/Unit/ServiceGateway/MessageHandler/SubmitAiTaskHandlerTest.php`,
  `site/tests/Integration/ServiceGateway/AiServiceHealthCheckCommandTest.php`,
  `AiQualityActionTest.php`, `site/tests/Integration/SourceFacts/PullSourceFactsActionTest.php`.

**1.1 — ИИ**

- [ ] RED: `testConfiguredServiceWorksWithoutRolloutFlag` — URL+token включают
  технический сервис при отсутствующем старом env; grant A допускает A, не B.
  Проверить пустой URL/token, выдачу/отзыв grant, существующий platform exception.
- [ ] Удалить bool `$enabled` из конструктора `AiServiceAccess`, оставить
  `isEnabled(): bool` по существующим URL/token. Сигнатуры Facade сохранить;
  обновить все прямые конструкторы в тестах и комментарии о флаге.
- [ ] GREEN: указанные Unit/Integration и
  `site/tests/Functional/ServiceGateway/AdminAiCompanyAccessTest.php`.

**1.2 — SourceFacts**

- [ ] RED: `testPullWithoutScopeAcceptsTwoCompaniesIndependently` — без env принять
  пачки двух валидных компаний; ошибочная принадлежность/unknown connection
  не применяется и не ack; повторная доставка не создаёт дублей.
- [ ] Удалить env/CSV/`ALL_COMPANIES` и фильтрацию scope; удалить `isEnabled()`
  и ветви «disabled» в двух командах. Удаление из общего services/env — Stage 3.
- [ ] GREEN: весь `PullSourceFactsActionTest`, включая карантин, ошибки клиента,
  commit-before-ack и проверку health при отсутствии удалённого env.

**Проверки:** PHP CLI нужного worktree, `vendor/bin/phpunit` с путями выше,
из `/app` в контейнере; запуск через `docker compose exec -T -e XDEBUG_MODE=off
site-php-cli …` только после проверки bind. Focused PHPStan/CS изменённых файлов.
**Review:** изоляция кабинетов, platform exception, отсутствие grant bypass,
достижимость health после удаления ветвей выключения.

## Stage 2 — Telegram и восстановление без pilot scope

**Risk:** HIGH-LOCAL. **stage_base_commit:** записать перед первым Work item.
**DoD:** новый разбор выбирается по admin grant; прежние черновики и recovery
работают без двух Telegram env; Confirm и защита от повторов сохранены.

**Файлы:** удалить `site/src/Telegram/Application/PaymentIntakeAccess.php`;
изменить `PaymentIntakeTelegramAction.php`, `QueuePaymentIntakePollAction.php`,
`site/src/Telegram/MessageHandler/RefreshPaymentIntakeMessageHandler.php`,
`site/src/Telegram/Command/RecoverPaymentIntakeDeliveryCommand.php`.
Тесты: существующие `site/tests/Functional/Telegram/TelegramPaymentIntakeTest.php`,
`site/tests/Unit/Telegram/PaymentIntakeAccessTest.php` (заменить устаревшие
scope-тесты поведенческими), новый
`site/tests/Integration/Telegram/RecoverPaymentIntakeAllCompaniesTest.php`.

**2.1 — Доступ и lifecycle**

- [ ] RED: `testAdminGrantedNonpilotCompanyUsesPaymentIntakeWithoutEnv` — кабинет
  вне прежнего списка создаёт один черновик, без финансовой операции до Confirm.
- [ ] RED: нет grant + never-selected binding → прежний legacy; отзыв grant у
  sticky binding/известного source → новый текст не попадает в legacy и не
  запрашивает LLM. Сохранить RED/GREEN проверки новых requests/edit/retry.
- [ ] Зафиксировать существующий
  `testManualFieldReplyWorksWithoutAiGrantAndDoesNotRequestNewParse` как инвариант:
  не добавлять общий grant-check перед manual reply/callback/poll.
  Чужой callback, actorless/revoked binding и finance read-only остаются запрещены.
- [ ] Удалить pilot-проверки/инъекции класса; адаптировать выбор binding к grant
  только для нового ИИ-разбора. Сохранить early source/reply guards, sticky state,
  старые ключи, leases, revision/token/CAS и отдельные проверки на новый LLM-вызов.
- [ ] GREEN: весь Telegram functional-набор и Cash PaymentIntake интеграции;
  старые тесты отключённого rollout заменить тестами соответствующих прав.

**2.2 — Recovery**

- [ ] RED: `testAllRecoveryCoversGrantsAndPendingUngrantDelivery` — две подключённые
  компании, третья без grant с незавершённой доставкой, четвёртая без grant/сессий.
  Первые две получают Cash+Telegram recovery; третья только существующую доставку;
  четвёртая не обрабатывается. Ни одного Confirm или нового LLM для третьей.
- [ ] RED: ошибка Cash A не мешает доставке A и обработке B; ошибка доставки A
  не мешает B, итоговый exit ненулевой. Повтор не создаёт вторую задачу/операцию.
- [ ] Расширить существующую команду `app:telegram:recover-payment-intake` флагом
  `--all`; `company-id` сделать необязательным только для этого режима.
  Без обоих или при их сочетании — `Command::INVALID`. Сохранить старый вызов
  с UUID и `--skip-cash-recovery`, проверяя доступ к LLM внутри Cash как прежде.
- [ ] Для `--all` объединить существующий
  `AiTaskFacadeInterface::connectedCompanyIds(): array` и company IDs своих
  Telegram-сессий с тем же предикатом кандидатов, что в текущем recovery:
  `delivery_pending = true OR pending_poll IS NOT NULL OR poll_generation IS NOT NULL`.
  Перебирать отсортированно, без дублей, с существующими порциями по 100 сессий.
  Глобальный технический SELECT возвращает только IDs, дальнейшая работа scoped;
  чужие таблицы доступа из Telegram не читать.
- [ ] Cash recovery вызывать только при `isEnabledForCompany()`; доставку старых
  сессий разрешать без grant с сохранением binding/actor/financial-write проверок.
  Повторно проверять grant перед Cash recovery; не отменять/не удалять черновики.
  Обрабатывать сбои каналов отдельно; закрытый EM не продолжать как живой.
- [ ] GREEN: новый integration-тест и Telegram functional, включая недоступный
  черновик, активную lease, истёкший poll и повтор после сбоя брокера.

**Проверки:** целевые PHP-наборы Stage 2 через проверенный контейнер как в Stage 1;
focused PHPStan/CS. **Review:** отзыв ИИ отдельно от финансовых прав, manual-flow,
legacy replay, отсутствие cross-module query и неограниченного накопления сессий.

## Stage 3 — Конфигурация, постоянный deployment и handoff

**Risk:** HIGH-EXTERNAL для изменения CI/CD/production-конфигурации; начать после
проверки именованного разрешения. **stage_base_commit:** записать перед работой.
**DoD:** обычный deploy/cron работает без шести Variables; профиль dev сохранён;
nightly по-прежнему запускается только при `AI_SERVICE_EVAL_ENABLED=true`.

**Файлы:** `.github/workflows/deploy.yml`, `docker-compose.prod.yml`,
`docker-compose.yml` (только ссылки на удаляемые env), `docker/cron/app.cron`,
`site/.env`, `site/config/services.yaml`; удалить `site/bin/recover-payment-intake.sh`
после переключения cron на PHP-команду. Тесты:
`tests/shell/deploy-workflow-test.sh`, `site/tools/tests/test_payment_intake_runtime.py`,
`services/ai-service/tests/unit/test_nightly_runtime_config.py` и, если затронут,
`test_prod_config.py`. Обновить актуальные runbooks
`docs/maintenance/{ai-service,ozon-fetcher,wb-fetcher}-prod.md`,
`docs/workflow/health-gates.md`, `docs/architecture/service-gateway.md`,
`ARCHITECTURE.md`; архивные task docs оставить историей.

- [ ] RED: fake-docker runtime-тесты deployment без шести env и с их старыми
  значениями `false`/пустым scope — одинаковый постоянный запуск Ozon/WB/ИИ и
  payment-intake. Отдельно пропущенный обязательный секрет → ранняя ошибка.
- [ ] RED: nightly=false → eval-worker остановлен и не поднимается финальным
  `up`; nightly=true → миграции до запуска. Business workers работают в обоих случаях.
- [ ] RED: запуск нового cron recovery без env доходит до PHP `--all`;
  запросы через fake PHP/docker, никакой сети или production.
- [ ] Удалить шесть экспортов, env/DI/defaults и устаревшие проверки значений.
  В production убрать профили, делавшие Ozon/WB/ИИ/payment-intake опциональными;
  отдельный профиль `ai-service-eval` оставить только eval-worker. При true
  включать этот профиль явно, при false — явно stop и не включать в финальный `up`.
  Dev-профиль `services` сохранить; учесть новые ожидания в Python config-тестах.
- [ ] Сохранить bootstrap БД/ролей, migrate, gateway-check и проверку секретов
  сервисов, выполняя их теперь безусловно до старта соответствующего сервиса.
  Сохранить consumer-first, stdin redirect и порядок schema-ready/deploy.
- [ ] Заменить shell recovery в том же cron-слоте на
  `php bin/console app:telegram:recover-payment-intake --all --no-interaction --quiet`.
- [ ] GREEN: `make test-deploy-workflow`, указанные Python runtime-config тесты
  через существующий контейнер `services-ai-service-dev`; `docker compose
  --env-file <synthetic-env> -f docker-compose.prod.yml --profile '*' config`.
  Секреты тестовые, конфигурацию со значениями в вывод не печатать.
- [ ] Просмотреть все совпадения шести имён через `rg`: активные источники,
  templates/env, Makefile, DI, cron и workflow без чтения этих настроек;
  допустимы архивные документы, этот план и тесты игнорирования старого env.
- [ ] На handoff один раз выполнить `make site-stan`, `make site-cs-check`,
  `make site-cs-strict-types`, `make site-test-unit`, `make site-test`
  (затронуты интеграционные пути); применимые Python gates по services/python.md.
  Сетевые интеграции в этих проверках только stub/fake.
- [ ] Свежий внутренний review полного task diff; внешнее Claude read-only review
  по `docs/workflow/external-review.md`. На HIGH-LOCAL Stage diff >500 строк —
  дополнительный Stage review; на handoff внешний review обязателен в любом случае.
  Исправить BLOCKER/IMPORTANT, выполнить релевантные проверки, записать метрики.
- [ ] Stage Report, handoff, commit/push, обновление единственного implementation
  Draft PR, затем Ready. Миграций не ожидать; если понадобились — пересмотреть
  scope и применять `docs/workflow/release.md`.

## Согласованный выпуск: сначала код, затем GitHub Variables

Эти шаги не выполняются при подготовке плана и не являются четвёртым Stage.

1. На handoff запросить merge/deploy и отдельными пунктами назвать постоянный
   запуск контейнеров/профили/новый cron и удаление ровно шести GitHub Variables.
   После получения покрывающего их разрешения не запрашивать подтверждения по шагам.
2. До выпуска сохранить исходные шесть значений только в защищённом операторском
   канале для отката; в git/логи/ответы значения и UUID не копировать.
3. Выпустить код с ещё существующими Variables. Дождаться CI и deploy; новые
   значения игнорируются, старый код до обновления продолжает их получать.
4. Read-only приёмка через `vf-prod-codex` wrappers: статусы/состав контейнеров,
   текущие grants и журналы recovery/SourceFacts/ИИ агрегатами, актуальность
   nightly витрины; никаких ручных сообщений боту, recovery или live LLM-вызовов.
   Если нужной диагностики нет в wrapper — не обходить ограничения, оформить
   точный пробел; использовать доступную витрину и следующий штатный cron.
5. Удалить **ровно** шесть разрешённых repository Actions Variables, не Secrets.
   Перечитать список: эти имена отсутствуют, `AI_SERVICE_EVAL_ENABLED` и
   `ECONOMICS_WEEKLY_REPORT_COMPANIES`, а также посторонние новые имена сохранены.
6. Не запускать платный/nightly benchmark и повторный deploy ради удаления:
   отсутствие переменных уже проверено локальной deployment-матрицей. Итог —
   merge SHA, deploy conclusion, приёмка и результат удаления по именам.

**Откат:** до удаления Variables доступен прежний стандартный rollback после
отдельного согласования. После удаления сначала восстановить сохранённые шесть
значений, затем возвращать старый код/конфигурацию: иначе старый deployment
остановит сервисы. Откат не должен повторять Confirm или менять данные платежей.

## Чек-лист приёмки реализации

- [ ] Шесть Variables отсутствуют в GitHub и не читаются активным кодом/деплоем.
- [ ] Ozon/WB/ИИ/business/payment-intake workers запускаются без этих значений.
- [ ] SourceFacts принимает две компании и сохраняет карантин/ack/идемпотентность.
- [ ] Новый admin grant открывает Telegram-разбор без изменения GitHub/deploy.
- [ ] Отзыв grant прекращает новые LLM-вызовы; manual-flow старого черновика работает.
- [ ] Чужой callback/actor, отозванная привязка и finance read-only не создают платёж.
- [ ] Recovery охватывает все нужные компании, сбои изолированы, Confirm не вызывается.
- [ ] Nightly true/false, бюджет, модели, остальные Variables/Secrets сохранены.
- [ ] Full gates и свежие internal/external reviews имеют подтверждённые результаты.
- [ ] Выпуск/удаление имеют именованное разрешение и проверенный путь отката.
