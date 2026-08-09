# Task: мультивалютные операции ДДС и переводы между счетами

Источник: бриф и согласованные бизнес-правила Владельца в чате.

## Контекст и принятые решения

- Денежные счета типов `BANK`, `CASH`, `EWALLET` поддерживают существующий
  список фиатных валют `RUB`, `USD`, `EUR`, `KZT`; криптосчета не участвуют.
- Кросс-валютный перевод v1 разрешён только для пар `RUB/USD` и `RUB/EUR` в
  обоих направлениях. `USD/EUR`, внешние FX-провайдеры и переоценка валюты —
  вне scope.
- Пользователь вводит обе фактические суммы. Система сохраняет эффективный
  курс как точную decimal-строку вместе с направлением котировки, датой и
  источником `manual_effective`; сервер не пересчитывает одну сумму из другой.
- Перевод — единый агрегат с двумя транзакциями: исходящая операция использует
  системную категорию `CF_TECH_OUT`, входящая — `CF_TECH_IN`. Обе категории
  являются обязательными системными дочерними категориями корня `CF_TECH`
  «Технические операции».
- Комиссия банка не входит в агрегат перевода и проводится отдельной обычной
  исходящей транзакцией.
- Исправление проведённого перевода: атомарно soft-delete пары и создание
  нового агрегата. Редактирование или удаление одной связанной ноги запрещено.
- Исторические `isTransfer=true` без агрегата остаются legacy-операциями;
  автоматический backfill/спаривание не выполняется.
- Дашборд и отчёты ДДС показывают выбранную валюту. Автоматическая агрегация
  разных валют и «сведение в RUB» запрещены.
- `PaymentPlan` пока не содержит валюты: v1 трактует существующие планы как
  RUB-only и не сопоставляет их с non-RUB транзакциями.
- Отрицательные остатки по счетам остаются допустимыми согласно текущему
  поведению.

## Stage 1: безопасный валютный фундамент Cash

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: 1b77472f66085752ed3dffd78e3a4f6ccbc9162b

Definition of Done:
- Один enum/контракт определяет поддерживаемые фиатные валюты
  `RUB/USD/EUR/KZT`; формы и write-paths не дублируют список строками.
- Валюта счёта задаётся при создании и не изменяется после сохранения.
- Все ссылки входящей транзакции разрешаются в пределах активной компании;
  счёт и транзакция не могут пересечь tenant boundary.
- Валюта транзакции всегда равна валюте счёта, в том числе при импорте.
- Смена счёта обычной транзакции атомарно меняет её derived currency.
- Форма транзакции отображает валюту выбранного счёта read-only.
- Non-RUB транзакции не сопоставляются с legacy PaymentPlan без валюты.
- Existing RUB behavior и публичный `CashFacade::createTransaction()` остаются
  backward-compatible.
- Документация Stage ограничена планом/checkpoint; архитектурная запись для
  новых публичных типов будет добавлена до закрытия Stage.
- Вне Stage: агрегат перевода, migration, UI перевода, dashboard selector.

Work items:
- 1.1 — Добавить `FiatCurrency` и перевести account/transaction forms и DTO
  validation на единый список; запретить изменение валюты существующего счёта.
- 1.2 — Укрепить ручной transaction write-path: company-scoped resolution
  счёта/контрагента/категории/проекта/ЦФО, инвариант currency=account currency,
  согласованная смена account+currency.
- 1.3 — Укрепить facade/import paths: нормализация поддерживаемой валюты,
  отказ при mismatch с целевым счётом, без частичной записи.
- 1.4 — Ограничить legacy PaymentPlanMatcher валютой RUB и исключить non-RUB
  auto-match; добавить regression/unit/integration/functional coverage.
- 1.5 — Обновить `ARCHITECTURE.md`, выполнить полные Stage checks и reviews.

Stage checks:
- Targeted PHPUnit для `FiatCurrency`, account form/service, transaction
  service/facade, payment-plan matcher и import paths.
- Bounded Cash unit/integration/functional tests.
- Doctrine schema validation без изменения production/staging.
- PHP CS Fixer dry-run для изменённых файлов и Twig lint при изменении формы.

Reviewer focus:
- Tenant isolation и отсутствие `getReference()` для пользовательских id.
- Точная валютная семантика без float и неявной конвертации.
- Backward compatibility RUB/import/legacy `isTransfer`.

## Stage 2: атомарный агрегат перевода и backend use case

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: e339b3dabdde1c8c893dc19e7bc0143699d08dac

Definition of Done:
- Добавлена expand-only таблица `cash_transfer` и Doctrine Entity с двумя
  уникальными FK на ноги перевода, company scope, idempotency и FX metadata.
- Один `CreateCashTransferAction` атомарно создаёт агрегат, две транзакции и две
  строки разбивки по системным категориям `CF_TECH_OUT`/`CF_TECH_IN`.
- Суммы положительны и точны в scale валюты; одинаковая валюта требует равных
  сумм, кросс-валютные пары ограничены `RUB/USD` и `RUB/EUR`.
- Обе ноги принадлежат одной компании, разным активным не-crypto счетам и не
  предшествуют opening date; закрытый финансовый период блокирует операцию.
- Для пары не запускаются VAT, PaymentPlanMatcher и auto-rules; балансы обоих
  счетов пересчитаны, snapshot cache инвалидирован один раз после commit.
- Повтор с тем же company-scoped idempotency key не создаёт дубль.
- Миграция проверена на isolated local/test DB; destructive/backfill SQL нет.
- Вне Stage: delete/restore пары, UI и dashboard currency selector.

Work items:
- 2.1 — Создать entity/repository/migration `cash_transfer` с constraints,
  indexes, soft-delete/audit metadata и совместимыми nullable FX-полями.
- 2.2 — Добавить exact effective-rate calculator на `Money`/BCMath и тесты
  направления котировки, округления и scale.
- 2.3 — Добавить command/result/action/facade contract создания перевода,
  system-category lookup по точным `systemCode` и company-scoped validation.
- 2.4 — Обеспечить единую DB-транзакцию, подавление auto-rules, idempotency,
  две balance recalculation и post-commit cache invalidation/audit.
- 2.5 — Добавить unit/integration tests, обновить `ARCHITECTURE.md`, проверить
  migration SQL и выполнить полные Stage checks/reviews.

Stage checks:
- Targeted unit/integration tests агрегата, FX math, idempotency и rollback.
- Doctrine migration up/down и schema validation на isolated test DB.
- Bounded Cash test suites и PHP CS Fixer dry-run.

Reviewer focus:
- Atomicity, race-safe idempotency, DB constraints/FK indexes.
- Точная Money/FX математика и воспроизводимость курса.
- Строгие системные категории и отсутствие side effects обычной транзакции.

## Stage 3: lifecycle, currency-safe read models и dashboard API

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: 9384ec7ec99ba0d1f921fa2e52bf8b8160cebce4

Definition of Done:
- Перевод удаляется и восстанавливается только целиком и атомарно; период,
  tenant scope, audit и пересчёт обоих счетов соблюдены.
- Generic edit/delete/restore/bulk-delete отвергают отдельную связанную ногу;
  legacy `isTransfer=true` без агрегата сохраняет старое поведение.
- ДДС-отчёт показывает технические поступление/выбытие в соответствующей
  валюте; same-currency transfer даёт нулевой net, cross-currency — отдельные
  движения по каждой валюте.
- Cash dashboard API принимает `currency`, default `RUB`, возвращает
  `cash_currency`; все cash widgets фильтруются до агрегирования, P&L widgets
  не меняют семантику.
- Transaction list/export поддерживают явный currency filter.
- Ни один изменённый read model не суммирует разные валюты.
- Вне Stage: UI создания/просмотра агрегата и verifier CLI.

Work items:
- 3.1 — Добавить company-scoped transfer queries и atomic delete/restore
  Actions/Facade; защитить generic и bulk transaction mutations.
- 3.2 — Покрыть cashflow/report reconciliation системных категорий, soft-delete
  и same/cross-currency cases.
- 3.3 — Протянуть currency filter через dashboard endpoint, snapshot context,
  cache key, warmup/telemetry и все cash widget repositories/builders.
- 3.4 — Добавить currency filter в transaction list/export и тесты tenant/
  pagination/backward compatibility.
- 3.5 — Обновить архитектурный контракт и выполнить полные Stage checks/reviews.

Stage checks:
- Cash transfer lifecycle integration/functional tests.
- Cashflow report and dashboard repository/API tests по RUB/USD/EUR.
- Export/list filter tests, cache-key tests, schema validation, CS checks.

Reviewer focus:
- Запрет half-deleted/half-restored пары и обхода через generic endpoints.
- Отсутствие cross-currency SUM на каждом read path.
- Dashboard cache isolation по company+period+currency.

## Stage 4: Twig UI перевода и валютный выбор ДДС

Risk: MEDIUM
owner_gate: no
release_candidate: yes
independently_deployable: yes
stage_base_commit: TBD — зафиксировать после commit Stage 3

Definition of Done:
- Пользователь может открыть форму, выбрать дату, исходный/целевой счёт,
  фактические суммы и примечание; currency каждого счёта показана read-only.
- Сервер остаётся единственным источником финансовой валидации и сохранённого
  эффективного курса; JavaScript не выполняет authoritative FX calculation.
- Есть show page агрегата с обеими ногами, курсом, системными категориями,
  статусом и безопасными ссылками.
- Связанная нога не предлагает обычные edit/delete actions, а ведёт к агрегату.
- Есть удаление пары, список удалённых переводов и восстановление пары.
- Dashboard имеет selector «Валюта ДДС», сохраняющий выбор в URL/API.
- UI использует существующую Symfony/Twig и UI Kit/соседние Cash patterns без
  новой frontend dependency, Vite entry или ad-hoc финансовой логики.
- Responsive/error/empty states покрыты functional/Twig checks.
- Вне Stage: внешний FX provider, автоматическая сумма, React redesign.

Work items:
- 4.1 — Добавить Symfony form/DTO для перевода с hidden idempotency key и
  company-scoped account choices.
- 4.2 — Добавить thin controllers/routes/templates create/show/delete/restore/
  deleted-list с CSRF и existing permissions.
- 4.3 — Интегрировать aggregate link/guards в transaction UI.
- 4.4 — Добавить dashboard currency selector и сохранить query context.
- 4.5 — Добавить functional/Twig/responsive checks и выполнить Stage reviews.

Stage checks:
- Functional tests create/show/delete/restore/access/CSRF/idempotent resubmit.
- Twig lint, PHP CS Fixer, bounded Cash tests, frontend build if assets change.

Reviewer focus:
- IDOR/CSRF и отсутствие редактирования одной ноги.
- Отсутствие JS/float как источника FX math.
- Доступность и корректное отображение валют/точных сумм.

## Stage 5: верификатор, документация и Final Release Gate

Risk: MEDIUM
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: TBD — зафиксировать после commit Stage 4

Definition of Done:
- Read-only `app:cash:verify-transfers` проверяет pair/company/account/currency/
  direction/system-category/same-currency equality/rate/deleted/idempotency и
  orphan invariants; legacy `isTransfer=true` учитывается как info, не error.
- `ARCHITECTURE.md` и Cash README описывают агрегат, public contracts,
  operational verification, ограничения v1 и безопасный rollout.
- Полный релевантный test/CS/build набор зелёный либо документированы только
  доказанно несвязанные baseline failures.
- Финальные internal и external reviews полного task diff завершены
  `REVIEW_GREEN`; handoff, commit, push и Draft PR обновлены.
- PR остаётся Draft. Ready/merge/release/deploy/production migration требуют
  отдельного решения Владельца.

Work items:
- 5.1 — Реализовать company-batched read-only verifier и tests exit status/
  diagnostics без PII/account numbers.
- 5.2 — Обновить архитектурную/операционную документацию и зафиксировать
  follow-ups вне scope.
- 5.3 — Выполнить full verification cascade, final reviews, handoff, commit,
  non-force push и обновление единственного Draft PR.

Stage checks:
- Targeted verifier unit/integration tests и `--help` smoke.
- Полный поддерживаемый backend test suite, CS check, Twig lint и frontend
  lint/typecheck/test/build/check:ui-kit если Stage 4 менял assets.
- Doctrine schema validation и просмотр migration SQL.

Reviewer focus:
- Verifier не мутирует данные и не создаёт ложный алерт для legacy операций.
- Документация совпадает с реальным контрактом и миграцией.
- Полный task diff не содержит scope creep, секретов или production actions.

## Release Gates и Production Gate

- Stages 1–4: `owner_gate: no`; после зелёных проверок/reviews, commit, push и
  обновления Draft PR работа продолжается автономно.
- Stage 5: Final Release Gate. Требуемое решение Владельца — review Draft PR и
  отдельное указание, переводить ли его в Ready. Самостоятельный merge запрещён.
- Production Gate не входит в реализацию. Отдельное явное разрешение требуется
  непосредственно перед production preflight, migration, deploy, acceptance,
  backfill/recalc/repair или другой production mutation. Approval merge не
  означает approval deploy.

## Ожидаемые области изменений

- `site/src/Cash/{Entity,Enum,Repository,Application,Facade,Service,Form,Controller,Command}`
- `site/src/Dashboard` и dashboard API/read-model code — только currency scope
- `site/templates/cash`, существующие dashboard templates
- `site/migrations`, `site/tests`, `ARCHITECTURE.md`, `site/src/Cash/README.md`
- `docs/tasks/cash-multicurrency-transfers/`

## Явно вне scope

- Криптовалюты, `USD/EUR`, автоматические курсы, FX revaluation/realized gain.
- Автоматическое создание банковской комиссии или включение её в перевод.
- Backfill/splicing исторических `isTransfer` операций.
- Добавление currency в PaymentPlan и импортное pairing переводов.
- Консолидированная отчётность с пересчётом в RUB.
- Production/staging действия, Ready/merge/release/deploy.
