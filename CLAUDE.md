# CLAUDE.md — VashFinDir

> Этот файл читается Claude Code автоматически при старте.
> Паттерны с примерами кода → `PATTERNS.md`.
> **Режим работы — автономный.** Иерархия выполнения: `Work item → Stage → Release Gate → Production Gate`. Work item завершается таргетированными проверками и focused self-review без ручной остановки. Только верхнеуровневый Stage получает полный review, внешний review другим агентом и Stage Report. Локальный риск усиливает проверки, но не создаёт ручную остановку.

Stack
PHP — источник истины: актуальный Docker image/runtime
Symfony 7.4 — источник истины: `site/composer.json` и `composer.lock`
Doctrine ORM
PostgreSQL
Redis
Symfony Messenger
Twig
React
Vite / frontend tooling
Docker Compose
Makefile-based commands

## Карта файлов

| Файл | Назначение | Когда читать |
|---|---|---|
| `AGENTS.md` | Главные правила Codex: полномочия, STOP-точки, review, commit/push/PR | Всегда для задач Codex |
| `CLAUDE.md` | Backend PHP/Symfony правила и синхронизированное краткое описание workflow | Всегда автоматически для Claude Code |
| `CLAUDE.frontend.md` | Правила React / TypeScript / собственного UI Kit | При фронтенд-задаче |
| `PATTERNS.md` | Паттерны с примерами кода | По задаче, нужный раздел |
| `ARCHITECTURE.md` | Живые данные: Facade, Enum, Entity | Перед написанием кода |

### Приоритет инструкций

Для Codex workflow и решений о STOP действует порядок:
1. Явная инструкция Владельца в текущей задаче или текущем чате.
2. Ближайший к изменяемым файлам `AGENTS.md`.
3. Корневой `AGENTS.md`.
4. Согласованная спецификация задачи и ADR.
5. `PATTERNS.md` и `ARCHITECTURE.md`.
6. `CLAUDE.md` и `CLAUDE.frontend.md` как дополнительные правила реализации.
7. Существующий код и общие best practices.

`CLAUDE.md`, старый Stage Report, метка риска или формулировка «owner-reviewable» не могут вводить ручной STOP, которого нет в применимом `AGENTS.md` и текущей инструкции Владельца.
При конфликте соблюдать более приоритетный источник, не ослаблять безопасность, зафиксировать расхождение и продолжить, если реального STOP-условия нет.

---

## 🤖 Автономный режим — workflow

### Источник задачи

Каждая задача начинается со **спецификации**:
- либо `docs/tasks/<id>/TASK.md` в ветке,
- либо чёткий бриф от Владельца в чате (scope + ограничения + acceptance).

Если бриф достаточно ясен для безопасной реализации → продолжать без дополнительных вопросов. STOP нужен только когда отсутствующая информация существенно влияет на бизнес-правила, финансовую семантику, публичный контракт, безопасность данных, доступы, необратимое действие, production или scope. Догадки в таких вопросах и расширение scope автономно запрещены.

### Иерархия выполнения: Work item → Stage → Release Gate → Production Gate

Термины используются строго:

- **Work item** — внутренний шаг реализации внутри верхнеуровневого Stage. Идентификаторы `1.1`, `1.2.1`, `7.6.4`, `N.M` и `N.M.K` всегда являются Work item, даже если старое ТЗ называет их Stage.
- После Work item: выполнить таргетированные проверки, focused self-review, исправить замечания, обновить checkpoint и автономно продолжить. Work item не создаёт Stage Report, обязательный внешний review, owner review, отдельный PR, production preflight или deploy gate.
- **Stage** — только верхнеуровневый пункт `Stage <целое число>`. Он объединяет Work items в один проверяемый результат с общей Definition of Done.
- На границе Stage: полные релевантные проверки, внутренний review полного Stage diff, внешний read-only review другим агентом до `REVIEW_GREEN` (см. «Внешнее ревью»), Stage Report, commit/push и обновление единственного Draft PR задачи.
- **Release Gate** — явно объявленная точка решения Владельца после одного или нескольких Stage либо в финале задачи. Она управляет owner review, переводом PR в Ready, merge и release readiness.
- **Production Gate** — отдельная точка разрешения production preflight, migrations, deploy, backfill, queue processing, acceptance и других production-действий. Release Gate не разрешает Production Gate автоматически.
- Push/merge в `master` автоматически разворачивает deployable-изменения приложения после обязательных проверок. Перед merge, делегированным агенту, Владелец должен явно разрешить и merge, и вызванный им production deploy; разрешения только на merge недостаточно.

Для каждого Stage в плане указывать:

```yaml
owner_gate: no
release_candidate: no
independently_deployable: no
```

Значения по умолчанию — `no`. Если `owner_gate: no`, после завершения Stage автоматически продолжать к следующему. Если `owner_gate: yes`, остановиться только после зелёных review, commit/push, обновления Draft PR и получения доступного CI status. Draft PR нельзя переводить в Ready, merge/release/deploy нельзя выполнять без явной инструкции Владельца.

### Непрерывное автономное выполнение

После получения достаточно ясной задачи или завершения Phase 0 без STOP-условия выполнить весь цикл без дополнительных подтверждений Владельца:

1. Зафиксировать base commit и Definition of Done текущего верхнеуровневого Stage.
2. Реализовать все Work items Stage. После каждого — targeted checks, focused self-review, fixes и checkpoint без owner interaction.
3. Интегрировать все Work items и выполнить полный релевантный набор проверок Stage.
4. Провести отдельный внутренний review полного Stage diff от зафиксированного base commit, включая commits, staged/unstaged и task-owned untracked files.
5. Исправить findings в scope, повторить проверки и внутренний review до green.
6. Самостоятельно запустить внешний read-only review полного Stage diff другим агентом (см. «Внешнее ревью»).
7. Проверить findings, исправить подтверждённые замечания и повторять проверки и оба review до `REVIEW_GREEN`.
8. Подготовить один Stage Report и обновить checkpoint.
9. Commit только task-owned изменений Stage, non-force push task-ветки и создание/обновление единственного Draft PR задачи.
10. При `owner_gate: no` автоматически перейти к следующему Stage. При `owner_gate: yes` завершить объявленный Release Gate и запросить только конкретное решение Владельца.
11. После последнего Stage выполнить Final Release Gate. Не входить в Production Gate без отдельного явного разрешения.

Внутренний review, внешний review другим агентом, исправления review, повторные тесты, локальные миграции, commit, non-force push task-ветки и Draft PR заранее разрешены в рамках согласованной задачи. Это действия, которые нужно выполнить, а не следующие шаги, предлагаемые Владельцу.

Нельзя завершать выполнимую задачу сообщениями «следующий шаг — review», «запросить повторное review текущего diff», «сейчас нужно провести review этапа», «после зелёного review можно коммитить», «готов сделать push после подтверждения» или «PR можно создать после разрешения». Реализующий агент сам запускает оба review. Сначала выполнить review, исправления, проверки, commit, push и Draft PR, затем отчитаться.

В начале первой рабочей сессии логической задачи прочитать актуальные
корневой и применимые вложенные `AGENTS.md`, а также релевантный `CLAUDE.md`,
и сохранить hash их содержимого. Новое сообщение Владельца, compaction,
автоматическое продолжение или возобновление той же логической задачи сами по
себе не требуют повторного чтения неизменившихся инструкций.

Перед Stage review/commit, а также после switch, pull, merge, rebase или
fast-forward пересчитать hash. Перечитать только изменившиеся файлы. Если
предыдущие hash недоступны или недостоверны, перечитать применимые инструкции.

Если Claude Code запущен Codex как внешний reviewer через `claude -p`, он не выполняет реализацию, не запускает вложенный Claude/reviewer, не изменяет файлы и Git. Он только проверяет diff по переданному prompt и завершает ответ точной отдельной строкой `REVIEW_GREEN`, когда нет BLOCKER/IMPORTANT findings. Обратная сторона симметрична: Codex, запущенный Claude Code через `codex exec`, тоже только ревьюит.

### Фазы работы над задачей

```
Phase 0 (Plan)
  → Stage 1..N
      → Work item N.1 → targeted checks → focused self-review → continue
      → Work item N.M → targeted checks → focused self-review → continue
      → integrated Stage checks → internal review → external review (другой агент) → REVIEW_GREEN
      → Stage Report → commit/push → update the single Draft PR
      → owner_gate=no: continue | owner_gate=yes: Release Gate
  → Final Release Gate
  → explicit owner decision
  → Production Gate only when separately authorized
```

### Fast Path

Fast Path — облегчённый процесс только для результата, который легко проверить
и который не меняет исполняемое бизнес-поведение. Пригодность определяется до
начала действий.

Fast Path разрешён для:
- read-only анализа, объяснения, статуса и диагностики без PROD-проверки;
- явно запрошенной post-merge Git-уборки после проверки точной смерженной
  ветки;
- документации, комментариев, форматирования и только текстового UI copy без
  изменения code path, Twig control flow/variables, конфигурации, контрактов
  или generated artifacts;
- локальной обратимой операции с точной целью и немедленно проверяемым
  результатом.

Fast Path запрещён для финансовой семантики, application logic, БД/миграций,
данных, публичных контрактов, auth/security/permissions, зависимостей,
исполняемой конфигурации, CI/CD, инфраструктуры, Messenger/workers/scheduler,
интеграций, внешних side effects, production/staging, merge/release/deploy и
любых неоднозначных, широких или труднообратимых изменений.

Последовательность:
1. Явно назвать Fast Path и основание.
2. Проверить точную цель и working tree.
3. Выполнить минимальное действие.
4. Запустить самую узкую полезную проверку и focused self-review.
5. Сообщить результат и перечислить нетронутые посторонние изменения.

Fast Path не требует Phase 0, Stage plan, checkpoint, Stage Report, handoff,
полного test suite и внешнего ревью. При необходимости записи результата:
`N/A — Fast Path, no executable behavior change`. Read-only и Git housekeeping
не требуют task branch/PR. Документационная правка, предназначенная для
репозитория, всё ещё доставляется отдельной focused task branch, commit, push и
Draft PR, если Владелец явно не согласовал другой разрешённый путь.

Если обнаружено хотя бы одно исключение, до соответствующего изменения выйти
из Fast Path и продолжить по Small task или Large task workflow. Fast Path не
отменяет destructive-action checks, owner gates, Release Gate и Production
Gate.

### Phase 0 — Plan (всегда первая для большой задачи)

1. Прочитать: применимый `AGENTS.md`, `CLAUDE.md`, релевантные разделы `PATTERNS.md`, `ARCHITECTURE.md`, спецификацию задачи.
2. Найти 2–3 похожих модуля в репозитории, опереться на их паттерны.
3. Составить план:
   - 3–6 верхнеуровневых Stage 1..N с интегрированной целью каждого,
   - вложенные Work items с dotted identifiers (`N.M`, `N.M.K`),
   - карта изменений: какие Entity / Repository / Action / Facade / Controller / Message / миграции,
   - риск-классификация каждого Stage и HIGH-EXTERNAL действий внутри Work items,
   - Definition of Done, `owner_gate`, `release_candidate`, `independently_deployable` и Stage base commit,
   - явные Release Gates и Production Gate actions; не выводить их из нумерации,
   - какие тесты потребуются,
   - какие записи в `ARCHITECTURE.md` нужно обновить.
4. Сохранить план в `docs/tasks/<id>/plan.md`.
5. Продолжить автоматически к Stage 1, если scope, ограничения и acceptance criteria достаточно ясны, подход соответствует существующим паттернам и не требуется HIGH-EXTERNAL действие.

STOP после Phase 0 только если требования конфликтуют, отсутствует существенное бизнес-решение, варианты имеют materially different последствия для бизнеса/безопасности/данных, требуется выход за scope или HIGH-EXTERNAL действие, либо Владелец явно потребовал отдельного согласования плана.

Минимальный формат Stage в плане:

```md
## Stage 1: <интегрированный результат>
Risk: LOW | MEDIUM | HIGH-LOCAL | HIGH-EXTERNAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: <зафиксировать перед реализацией>
Definition of Done:
- ...
Work items:
- 1.1 — ...
- 1.2 — ...
Stage checks:
- ...
Reviewer focus:
- ...
```

Если план содержит `Stage 1.1` или `Stage 1.2.1`, до реализации переименовать их в Work items. Вложенная нумерация сама по себе никогда не создаёт review/owner/PR gate.

### Классификация верхнеуровневых Stage по риску

| Риск | Примеры Stage | Поведение после зелёного review |
|---|---|---|
| 🟢 **LOW** | Внутренний рефакторинг; unit-тесты; документация; косметика | Продолжать автономно |
| 🟡 **MEDIUM** | Новая Entity без миграции; новый Action/Facade/Message/Handler; UI-блок по существующему паттерну | Сохранить Stage Report и продолжать автономно |
| 🟠 **HIGH-LOCAL** | Локальная миграция; публичный endpoint, уже требуемый задачей; auth/RBAC/Voter в ветке; legacy-зона; dependency, явно необходимая задаче; Messenger routing | Усиленные тесты и review; commit, push и Draft PR без дополнительного подтверждения |
| 🔴 **HIGH-EXTERNAL** | Production/staging mutation; live external side effect; secrets/permissions; deploy; destructive data operation; merge/release | 🛑 STOP перед выполнением |

Если неясно, может ли действие затронуть production, shared data, внешнюю систему или необратимое состояние, считать его HIGH-EXTERNAL и остановиться. Не считать обычное локальное изменение требующим ручного разрешения только потому, что аналогичное production-действие рискованно.
Метки `HIGH`, «high-risk», legacy, finance, auth или migration для изменений только в task-ветке означают HIGH-LOCAL, пока не требуется реальное HIGH-EXTERNAL действие или отсутствующее существенное решение Владельца.
Фраза «Stage HIGH-risk, поэтому STOP для owner review» не является допустимой причиной остановки локальной разработки. Owner review выполняется только в объявленном Release Gate через Draft PR после обоих зелёных review, commit и push.

### Обязательные точки STOP

- Любая мутация production или staging данных.
- Миграция вне изолированной local/test БД.
- Production queue processing, deploy, release, merge или изменение production state/config/secrets/permissions.
- Live external API call с побочным эффектом, не разрешённым явно.
- Удаление или необратимое преобразование существующих данных.
- Выход за исходный scope.
- Неопределённое бизнес-правило, финансовая семантика, публичный контракт или security behavior, которые нельзя однозначно вывести из ТЗ и текущего проекта.
- Конфликт с чужими изменениями, который невозможно безопасно отделить.
- BLOCKER/IMPORTANT finding, который после root-cause analysis и смены безопасного подхода нельзя исправить внутри scope без нового решения, доступа или HIGH-EXTERNAL действия.

Не останавливаться только потому, что review нашёл замечания, тест упал, потребовалось несколько итераций исправлений, создана локальная миграция, изменяется legacy/messenger/auth код внутри явного scope либо следующим штатным действием является commit, push или Draft PR.

### Focused self-review Work item

После каждого Work item проверить только его scope, observable behavior, тесты, безопасность, совместимость и отсутствие посторонних изменений. Исправить findings, обновить checkpoint и продолжить. Не запускать обязательный внешний review и не создавать Stage Report/PR gate для Work item.

### Внешнее ревью

Реализующий агент обязан сам вызвать **другого** агента как внешнего read-only ревьюера. Роли симметричны:

| Реализацию ведёт | Внешний ревьюер | Команда |
|---|---|---|
| Codex | Claude Code | `claude -p ...` по протоколу `AGENTS.md` |
| Claude Code | Codex | `codex exec -s read-only --ephemeral ...` |

Внешнее ревью запускается только после того, как собственный внутренний review полного Stage diff стал зелёным. Оно не заменяет внутренний, а идёт поверх вычищенного диффа.

Базовая форма вызова Codex:

```bash
codex exec -s read-only --ephemeral -o <файл-результата> "<промпт ревью>"
```

**Песочница внутри песочницы.** Если агент уже работает в изолированном окружении, Codex не поднимает свою и падает на `bwrap: loopback: Failed RTM_NEWADDR` ещё до чтения файлов. Это не ревью и не блокер — это конфигурационный сбой с одной обязательной повторной попыткой: передать дифф и контекст прямо в промпт через stdin, тогда ревьюеру не нужен шелл.

```bash
{ cat prompt.txt; git diff <stage_base_commit>..HEAD -- site/; } \
  | codex exec -s read-only --ephemeral -o review.txt -
```

Требования к промпту: явный `stage_base_commit`; запрет на правку файлов, изменение Git, вложенных ревьюеров и внешние вызовы; перечень категорий проверки по диффу; классификация каждой находки как BLOCKER / IMPORTANT / MINOR / FOLLOW-UP с `file:line`, доказательством, влиянием и конкретным исправлением; требование завершить ответ ровно строкой `REVIEW_GREEN`, если нет BLOCKER и IMPORTANT.

Ревьюеру нельзя давать права записи и передавать секреты, значения окружения, учётные данные и production-дампы. Если ревьюер лишён шелла, факты, которые он не может добыть сам (структура схемы, объёмы данных, замеры с прода), кладутся в промпт, а само ограничение фиксируется в Stage Report — иначе лаконичный зелёный вердикт читается весомее, чем есть.

Цикл: проверить findings → исправить подтверждённые in-scope → повторить проверки и внутренний review → повторить внешнее ревью. До `REVIEW_GREEN`. Упавшая команда, таймаут, отказ в правах, обрезанный вывод или отсутствие точной строки-маркера зелёным ревью не являются.

### Полный self-review checklist верхнеуровневого Stage

Запускать после интеграции всех Work items. Если хоть один пункт красный — Stage не закрыт.

**Соответствие правилам:**
- [ ] Изменения строго в рамках цели Stage (нет out-of-scope правок)
- [ ] Структура файлов — раздел «Структура файлов» соблюдён
- [ ] Naming, модификаторы классов (`final` / `final readonly` / `class`) — раздел «Правила PHP» соблюдён
- [ ] Использованы **только** Facade и Enum из `ARCHITECTURE.md`
- [ ] Не задет ни один пункт раздела «Глобальные запреты»

**Безопасность:**
- [ ] Каждый Repository-метод принимает `string $companyId`
- [ ] В контроллерах есть `getActiveCompany()` перед обращением к данным
- [ ] Нет `$repo->find($id)` без companyId (IDOR-проверка)
- [ ] Нет логирования паролей / токенов / PII

**Качество кода:**
- [ ] `make site-cs-check` — чисто
- [ ] Статический анализ запущен через существующий project target, если он есть; не выдумывать `make stan`, когда target отсутствует
- [ ] Нет `dump()` / `dd()` / `var_dump()`
- [ ] Нет N+1 (проверено через Profiler / `doctrine.debug` при ручном smoke-тесте)
- [ ] На списочных эндпоинтах — Pagerfanta с лимитом ≤ 200
- [ ] Новые FK-поля имеют индексы в миграции

**Тесты:**
- [ ] Минимум по таблице «Тесты — минимум для Work item / Stage» написан
- [ ] Таргетированные тесты и `make site-test` (когда полный прогон уместен) — зелёные либо документирована доказанно несвязанная pre-existing ошибка
- [ ] Тесты не «приглажены» под код — проверяют поведение

**Документация:**
- [ ] Добавил Facade / Facade-метод / Enum / Entity → `ARCHITECTURE.md` обновлён
- [ ] Изменения публичного API → OpenAPI / README модуля обновлены

**Stage Report:**
- [ ] Создан Stage Report по шаблону ниже, сохранён в `docs/tasks/<id>/stages/stage-<N>.md`
- [ ] Внешнее ревью другим агентом завершено `REVIEW_GREEN` для executable changes; для documentation-only записано обоснованное N/A
- [ ] Коммит сделан с Conventional Commits префиксом, сообщение отражает цель Stage

### Формат Stage Report

Stage Report создаётся один раз для каждого верхнеуровневого `Stage <целое число>`. Для Work items `N.M` и `N.M.K` Stage Report запрещён.

```markdown
## Stage <N>: <название> — DONE

**Риск:** 🟢 LOW | 🟡 MEDIUM | 🟠 HIGH-LOCAL | 🔴 HIGH-EXTERNAL
**Owner gate:** yes | no
**Release candidate:** yes | no
**Independently deployable:** yes | no
**Следующее действие:** continue autonomously | 🛑 STOP, ждать Владельца

### Scope Stage
- Stage base commit: `<commit>`
- Work items completed: `N.1`, `N.2`, ...

### Что сделано
- ...

### Затронутые файлы
- `src/.../X.php` — new
- `src/.../Y.php` — modified
- `migrations/Version...php` — new

### Self-review
- [x] Scope compliance
- [x] Patterns / naming
- [x] Forbidden actions — none
- [x] Security (companyId, IDOR)
- [x] PHPStan / CS-Fixer / tests — green
- [x] ARCHITECTURE.md updated (или N/A)

### External review
- Reviewer: Codex CLI <версия> | Claude Code <версия>
- Iterations: <number>
- Result: REVIEW_GREEN | N/A — documentation only
- Confirmed findings fixed: ... (или «нет»)
- Rejected findings with reason: ... (или «нет»)
- Ограничения ревьюера: ... (например «без доступа к шеллу и прод-схеме, факты переданы в промпте»; если нет — «нет»)

### Команды для проверки
- `make site-test-unit`
- `make site-test`
- `make site-cs-check`

### Риски / на что обратить внимание ревьюеру
- ...

### Открытые вопросы
- ... (если нет — «нет»)
```

### Final Release Gate — Handoff

В конце последнего верхнеуровневого Stage:
1. Прогнать доступный полный набор: `make site-test && make site-cs-check`; для Codex Cloud — релевантные `make codex-*` targets. Статический анализ запускать только через существующий target.
2. Сверить построчно все «Глобальные запреты» и ограничения из спецификации.
3. Заполнить `docs/tasks/<id>/handoff.md`: суммарный отчёт по всем верхнеуровневым Stage и Work items + список миграций + список изменённых публичных контрактов + риски + follow-ups.
4. Провести финальный внутренний review полного diff и исправить замечания в scope.
5. Запустить финальный внешний read-only review полного task diff другим агентом (см. «Внешнее ревью»).
6. Повторить проверки и оба review до `REVIEW_GREEN`.
7. Закоммитить только изменения текущей задачи.
8. Выполнить non-force push task-ветки.
9. Создать или обновить Draft PR.
10. Оставить PR в Draft и передать Владельцу финальный отчёт, CI status и ссылку на Draft PR.

Не останавливаться перед commit, push, Draft PR или handoff. В Release Gate остановиться перед переводом Draft PR в Ready, merge или release. Даже зелёный Release Gate не разрешает Production Gate.

### Production Gate

После отдельной явной инструкции Владельца войти в Production Gate, а не только после одобрения Release Gate:

1. Выполнить только запрошенный read-only production preflight через разрешённые wrappers.
2. Отдельно получить разрешение на merge, если merge делегирован агенту.
3. Непосредственно перед каждым production migration, deploy, queue processing, backfill, recalculation, repair или другой мутацией получить отдельное явное разрешение.
4. Выполнить только разрешённое действие и явно согласованный production acceptance.

Разрешение на merge не разрешает deploy. Разрешение на deploy не разрешает migrations, backfill, history rewrite, recalculation или иную production mutation, если она не указана явно.
Так как merge в `master` запускает deploy workflow автоматически, оба разрешения можно дать одной инструкцией Владельца, но в ней должны быть явно названы и merge, и автоматический production deploy.

### Практики автономной работы

**Сначала подтвердить дефект в данных, потом трогать код.** Сообщение о проде описывает симптом, а не причину. До правки найти доказательство в данных: какие строки, каким процессом и когда записаны. Если данные дефект не подтверждают — сказать это и остановиться, а не чинить описание. Правка по симптому создаёт второй баг поверх несуществующего первого.

**Замеры «до» снимать до изменения.** Перед миграцией данных сохранить в Stage Report именно те величины, которыми потом будешь доказывать сохранность: не только счётчики строк, но и суммы денежных полей и контрольные инварианты. После прогона восстановить их уже нечем.

**Красный baseline — не регресс, но и не индульгенция.** Репозиторные проверки могут быть красными до задачи (`make site-cs-check` — сотни файлов). В этом случае проверять точечно только изменённые файлы, сравнивать с их состоянием до правки и записывать в отчёт факт красного baseline с цифрой. Не выдавать чужие нарушения за свои и не прятать за ними свои. `make stan` в проекте не существует — статический анализ только через существующие таргеты.

**Регрессионный тест доказывается красным.** Не «тест написан», а «прогнан на старом коде и упал». Если старый код на новом тесте не падает (изменение поведения, а не исправление ошибки), доказывать на комбинации «новое поведение + старое условие» и фиксировать это в Stage Report.

**Миграции данных.** Список дочерних таблиц брать из реальной схемы (`information_schema`, `pg_constraint`), а не из памяти или прошлой миграции. Для каждого уникального ключа, содержащего переносимую колонку, заранее решить судьбу конфликта. Денежные строки при слиянии суммировать, а не удалять. Порядок шагов проверять на частичных уникальных индексах: перенос идентичности возможен только после удаления дубля. До прода прогнать на локальной БД на сценарии, где есть и сливаемые данные, и контроли, которые меняться не должны. Необратимая миграция требует бэкапа затронутых таблиц и отдельного Production Gate.

**Проверка после прода.** После деплоя миграции сверить: целевой эффект достигнут, счётчики «до» сошлись, инварианты целы, висячих ссылок нет. Расхождение сначала объяснить, потом докладывать: часть аномалий существует до изменения и к нему не относится.

**Отказ в правах — не STOP-условие.** Заблокированные `push`, wrapper или команда не отменяют этап: доделать всё остальное, честно указать, что именно заблокировано и почему, и отдать точную команду Владельцу. Агент не расширяет себе права ни в `settings.local.json`, ни в prod-wrapper, ни в sudoers — это делает Владелец.

### Запрещено в автономном режиме

```
самовольно расширять scope                              — STOP и спросить
коммитить незакрытый Stage                             — полный Stage gate не пройден
считать N.M или N.M.K отдельным Stage                  — это Work items
делать внешний review/Stage Report/PR/owner gate на Work item — нельзя
ждать Владельца после owner_gate=no                    — продолжать автономно
пропускать self-review «потому что очевидно»            — checklist обязателен
пропускать внешнее ревью для executable changes          — нельзя
ревьюить свой diff самим собой вместо другого агента     — это не внешнее ревью
останавливаться только из-за HIGH-LOCAL/high-risk метки  — нельзя
заявлять REVIEW_GREEN при ошибке/timeout/missing marker  — нельзя
заявлять REVIEW_GREEN при упавшей песочнице ревьюера     — нельзя, нужен повтор
переписывать чужие модули по дороге («заодно»)         — отдельная задача
merge в основную ветку                                  — никогда, только PR
переводить Draft PR в Ready без явной инструкции        — нельзя
force-push в shared-ветки                               — никогда
запуск миграций на staging/prod                         — никогда
входить в Production Gate из-за green CI/Release Gate  — нельзя
заканчивать с review/commit/push/Draft PR как next step — сначала выполнить, затем отчитаться
передавать Владельцу запуск обязательного review        — реализующий агент запускает сам
расширять себе права (settings, sudoers, wrapper)       — только Владелец
```

---

## PROD-доступ Codex

Использовать только когда Владелец явно просит проверить или выполнить операцию на PROD.

Ожидаемый SSH alias: `vf-prod-codex`.

Правила:
- Не использовать root SSH для работы Codex на PROD.
- Не добавлять PROD-пользователя Codex в `docker` group.
- Не запускать произвольный `sudo docker`, произвольный `docker exec` или произвольные privileged shell-команды.
- Не печатать и не коммитить production IP, private keys, passwords, tokens, env values.

Логи и operational artifacts на PROD:
- Приложение, workers и scheduler пишут в stdout/stderr контейнеров; ротацией управляет Docker.
- Не добавлять cron-редиректы в host-файлы.
- Не писать логи, backup и audit-файлы в `/root`, home-каталог пользователя или относительный путь.
- Постоянные ручные логи: `/var/log/app-service-finance/maintenance/`; backup: `/var/backups/app-service-finance/`; временные аудиты: `/var/tmp/app-service-finance.*`.
- Создание каталогов, установка logrotate, перенос и удаление существующих файлов являются production mutation и требуют explicit owner gate.
- Runbook: `docs/maintenance/production-logging.md`.

Разрешённые wrappers:
- `sudo /usr/local/bin/codex-docker-ps`
- `sudo /usr/local/bin/codex-psql-ro`
- `sudo /usr/local/bin/codex-console <allowed-symfony-command> ...`

### Канонические формы вызова

Вызывать wrappers только в этих формах. Правила `permissions.allow` в `.claude/settings.local.json` написаны под них; любое отклонение — другой алиас, потерянный `sudo`, одинарные кавычки вместо двойных — под правило не подпадает и снова упирается в запрос разрешения.

```bash
ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-psql-ro -c \"SELECT ...;\"" < /dev/null
ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-docker-ps" < /dev/null
ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-console <allowed-command> ..." < /dev/null
```

Обязательные элементы формы:
- алиас ровно `vf-prod-codex`; `vf-prod` — root-доступ, он под `ask` и для работы агента не используется;
- `-o BatchMode=yes` — без интерактивных запросов пароля;
- удалённая команда в двойных кавычках, SQL внутри — в экранированных двойных;
- обязательный префикс `sudo` и полный путь `/usr/local/bin/...`;
- `< /dev/null` на конце — без закрытого stdin `codex-psql-ro` не отдаёт результат и вызов висит до таймаута;
- допускается префикс `timeout <ms|s>` перед `ssh`.

Если агент запущен в песочнице Bash, эти формы висят на connect молча: исходящий TCP глушится, и
вместо `Connection refused` не приходит ничего до таймаута. Признак именно песочницы, а не прода —
`ssh -o BatchMode=yes vf-prod-codex "true"` тоже не завершается. Лечится запуском конкретного
wrapper-вызова вне песочницы (в Claude Code — `dangerouslyDisableSandbox` у этого вызова Bash);
расширять права в `settings.local.json` для этого не нужно и нельзя.

Диагностику `ssh -vv` снимать в файл, а не в пайп: при срабатывании `timeout` буферизованный вывод
пайпа теряется и видно только `Terminated`. Вывод прогонять через
`sed -E 's/([0-9]{1,3}\.){3}[0-9]{1,3}/<PROD-IP>/g'` — production IP не печатать и не коммитить.

Модель разрешений: приоритет `deny > ask > allow`. Широкий паттерн в `ask` перекрывает любые `allow`, поэтому маски вида `Bash(*ssh*vf-prod*)` в `ask` запрещены — они накрывают и `vf-prod-codex`. Ограничения на стороне прода (непривилегированный пользователь, allowlist внутри wrapper, роль БД `codex_ro`) остаются единственной настоящей защитой; правила в settings только убирают лишние запросы.

Read-only проверки можно выполнять после запроса Владельца на PROD-проверку:
- Docker process/status через `codex-docker-ps`.
- Messenger stats через `codex-console messenger:stats`.
- Marketplace category status через `codex-console app:ingestion:marketplace-categories:status`.
- Ozon preview/verification через `codex-console`.
- Живость SMTP через `codex-console app:mailer:healthcheck` — SMTP handshake с AUTH, письмо не отправляется, данные не меняются. Мутирующего режима и флагов у команды нет. Учитывать: сбой пишет `error` и заводит issue в GlitchTip, поэтому ручной прогон при мёртвой почте создаёт алерт.
- Сверка строк разбивки ДДС через `codex-console app:cash:verify-transaction-splits` — только чтение, ненулевой exit code при расхождении. Wrapper запрещает этой команде любые аргументы.
- Read-only SQL через `codex-psql-ro` и роль БД `codex_ro`.

Перед выполнением команд, которые меняют данные, обрабатывают очереди, вызывают внешние API или меняют состояние приложения, нужно явное подтверждение Владельца непосредственно перед запуском:
- `messenger:consume`.
- Любая команда с `--execute`.
- `app:cash:backfill-transaction-splits --execute` — переносит категорию ДДС в строки разбивки. Wrapper допускает у неё только пустой список аргументов или ровно `--execute`, другие флаги отвергает. Без `--execute` команда read-only и только считает объём. Запускать в тихом окне с остановленными воркерами, после бэкапа `cash_transaction`, и сверять результат `app:cash:verify-transaction-splits`.
- `messenger:failed:remove <id> [--force]` — безвозвратно удаляет сообщение из failed-очереди. Wrapper требует первым аргументом только числовой id и допускает единственный дополнительный флаг `--force`; `--all` отвергается, поэтому очередь целиком снести нельзя. До удаления посмотреть на цель: класс сообщения и текст ошибки читаются через `codex-psql-ro` из `messenger_messages`. Удаление выбрасывает задачу, а не выполняет её.
- Repair, prune, backfill, rebuild, refresh, maintenance.
- SQL write (`INSERT`, `UPDATE`, `DELETE`, DDL, migrations).
- Изменения production Docker, workers, scheduler, queues, secrets, config, deploy.

### Добавление PROD-разрешений

Если нужной команды нет в wrapper:
1. Описать точную production operation и зачем она нужна.
2. Классифицировать: read-only, mutating/processing, dangerous/general.
3. STOP и получить отдельное одобрение Владельца на изменение production permission. По умолчанию изменение применяет Владелец/DevOps; Codex меняет wrapper только по явному поручению для этой точной операции.
4. Allowlist должен проверять точное имя команды и допустимые аргументы/флаги; одного имени недостаточно, если команда имеет read-only и mutating режимы.
5. Не расширять sudoers и не выдавать прямой Docker-доступ.
6. Проверить wrapper: `bash -n /usr/local/bin/codex-console`.
7. Проверить от restricted user: `sudo -u codex-prod sudo /usr/local/bin/codex-console <command> --help` или другой безопасный read-only вызов.
8. Durable policy changes фиксировать в `AGENTS.md` и `CLAUDE.md`; временные one-off разрешения не документировать как постоянные.

Запрещено добавлять dangerous/general permissions: arbitrary shell, arbitrary `docker exec`, unrestricted `docker`, write-capable `psql`, file editing on production, package installation. Для one-off production writes предпочтителен временный narrowly scoped wrapper, который Владелец удаляет после использования.

---

## Фронтенд-задача?

Если задача касается React / TypeScript / Vite / Tabler — пользователь укажет:

```
Фронтенд задача. Читай CLAUDE.frontend.md
```

→ Прочитай `CLAUDE.frontend.md` полностью. Правила ниже **не применяются** (только для PHP/Symfony).

**Автономный workflow (фазы, классификация рисков, self-review, Stage Report, STOP-точки) — применяется и к фронтенду тоже.** Специфика этапов фронта — в `CLAUDE.frontend.md`.

---

## До написания любого backend-кода

1. Убедись, что Phase 0 завершена и нет STOP-условия; отдельное одобрение плана требуется только если Владелец запросил его явно
2. Прочитай `ARCHITECTURE.md` — актуальные Facade-методы, Enum-значения, статус Entity
3. Уточни модуль, если не указан явно
4. Используй существующие Facade и Enum из `ARCHITECTURE.md`; не выдумывай уже существующие контракты
5. Если задача явно требует нового Facade/метода/Enum и контракт однозначно следует из ТЗ и существующих паттернов — создай его в scope задачи и обнови `ARCHITECTURE.md` в том же Work item или до закрытия Stage. Спроси только при существенной неоднозначности контракта
6. Нужен паттерн → читай соответствующий раздел `PATTERNS.md`

---

## Структура файлов

### ✅ Разрешено

```
src/{Module}/Controller/          src/{Module}/Application/
src/{Module}/Controller/Api/      src/{Module}/Application/Command/
src/{Module}/Entity/              src/{Module}/Application/DTO/
src/{Module}/Repository/          src/{Module}/Application/Processor/
src/{Module}/Facade/              src/{Module}/Application/Service/
src/{Module}/Enum/                src/{Module}/Application/Source/
src/{Module}/Form/                src/{Module}/Domain/
src/{Module}/DTO/                 src/{Module}/Domain/ValueObject/
src/{Module}/Message/             src/{Module}/Domain/Service/
src/{Module}/MessageHandler/      src/{Module}/Infrastructure/
src/{Module}/EventSubscriber/     src/{Module}/Infrastructure/Api/
src/{Module}/Exception/           src/{Module}/Infrastructure/Query/
tests/Builders/{Module}/          src/{Module}/Infrastructure/Normalizer/
```

### ❌ Запрещено — legacy-зона, не создавать новые файлы

```
src/Entity/   src/Service/   src/Repository/   src/Controller/
```

Любая правка в legacy-зоне — 🟠 HIGH-LOCAL risk: допустима только когда прямо требуется задачей, с усиленными тестами и review. Сам факт работы в legacy-зоне не является STOP-условием.

---

## Правила PHP

### Каждый файл

```php
<?php

declare(strict_types=1);
```

**Модификаторы классов:**

| Модификатор | Когда |
|---|---|
| `class` | Entity (Doctrine генерирует proxy наследованием — `final` ломает это) |
| `final class` | Builder, Action, Controller, Facade, Repository, Query, Handler |
| `final readonly class` | DTO, Message, stateless-сервисы |
| `enum` | без `final` — PHP enum implicitly final, `final enum` — синтаксическая ошибка |

Конструктор: `private readonly`, только constructor injection.

---

### Entity — новые модули

- UUID v7: `Uuid::uuid7()->toString()` — в конструкторе Entity
- `#[ORM\Table(name: '...')]` — явное имя таблицы всегда
- `string $companyId` вместо `#[ManyToOne] Company $company`
- `companyId` неизменяем (нет setter'а), валидируется через `Assert::uuid()`
- Ссылки на Entity других модулей: `string $counterpartyId`, не `#[ManyToOne]`
- `DateTimeImmutable` везде, не `DateTime`
- Паттерн → `PATTERNS.md` §11

---

### Безопасность — IDOR (критично)

- Каждый Repository-метод **обязан** принимать `string $companyId`
- В контроллере всегда: `$company = $this->activeCompanyService->getActiveCompany()`
- `$repo->find($id)` без companyId — **запрещено** (IDOR-уязвимость)
- Паттерн → `PATTERNS.md` §14

В focused Work-item review и полном Stage review этот пункт проверяется первым — IDOR в проде = инцидент.

---

### Controller

- Один контроллер = один action = метод `__invoke`
- Маршруты через `#[Route]` атрибуты, не YAML
- Ноль бизнес-логики — только HTTP in/out
- Паттерн → `PATTERNS.md` §2

---

### Action

- `final class`, метод `__invoke`, без `Request`, без `Response`
- `flush()` — только в Action, не в Repository
- Паттерн → `PATTERNS.md` §3

---

### Facade

- Единственная точка входа между модулями
- Запрещено импортировать `Service/`, `Repository/`, `Application/`, `Infrastructure/` чужого модуля
- Паттерн → `PATTERNS.md` §7

Новый Facade или Facade-метод → добавить в `ARCHITECTURE.md` **в том же Work item или до закрытия Stage**, не откладывать.

---

### Message (Messenger)

- `readonly class` только со scalar ID — не Entity
- Новый Message → добавить routing в `config/packages/messenger.yaml`
- **Транспорт — ровно один:**

| Транспорт | Когда |
|---|---|
| `async_sync` | Внешние HTTP-запросы (marketplace/банк API, email) |
| `async_pipeline` | Локальная обработка данных, DB-heavy (импорты, analytics recalc) |
| `async_ads` | Ozon Performance polling (handler может висеть до 10 мин) |

- Handler: нет `Request`/`Session`/`Security` — CLI-контекст
- Изменение `messenger.yaml` — 🟠 HIGH-LOCAL risk внутри явного scope: проверить routing, retry/failure behavior, совместимость сообщений и выполнить усиленный review; без отдельного STOP
- Паттерн → `PATTERNS.md` §10

---

### Формы

- `ChoiceType` с данными из Facade — не `EntityType` с чужой Entity
- Паттерн → `PATTERNS.md` §8

---

## Глобальные запреты

```
dump() / dd() / var_dump()                — нельзя в коммитах
new SomeService()                         — только constructor injection
flush() в Repository                      — только в Action
хардкод секретов / URL / API-ключей       — только через .env
бизнес-логика в Controller                — вынести в Action
бизнес-логика в Entity                    — только инварианты в конструкторе
import Service/Repository чужого модуля   — только через Facade
#[ManyToOne] на Entity чужого модуля      — только string $entityId
EntityType с чужой Entity в формах        — только ChoiceType + Facade
SELECT * в raw SQL                        — явное перечисление колонок
циклические зависимости между модулями    — нельзя
getRepository() чужого модуля             — только через Facade
расширение scope задачи в автономе        — STOP и спросить
закрытие Work item/Stage с красным review — нельзя; fix → tests → repeat review до green или реального STOP-условия
merge / force-push                        — никогда автономно
```

---

## Тесты — минимум для Work item / Stage

| Что сделано в Work item | Что написать в том же Work item или до закрытия Stage |
|---|---|
| Новый Action | happy-path тест + 1 негативный |
| Новый Domain Policy | unit-тесты на все ветки |
| Новая Entity | Builder в `tests/Builders/{Module}/` |
| Исправление бага | регрессионный тест, который красный на старом коде и зелёный на новом |
| Новый Facade-метод | functional-тест через вызывающий код или unit на Facade |

Work item без необходимых тестов не завершён. Stage не закрывается, пока тесты всех Work items не интегрированы и не прошли релевантные Stage checks.

Паттерны → `PATTERNS.md` §16, §17

---

## Новый модуль — конфигурация

```yaml
# config/routes.yaml
newmodule_controllers:
    resource:
        path: ../src/NewModule/Controller/
        namespace: App\NewModule\Controller
    type: attribute

# config/packages/doctrine.yaml
NewModule:
    type: attribute
    is_bundle: false
    dir: '%kernel.project_dir%/src/NewModule/Entity'
    prefix: 'App\NewModule\Entity'
    alias: NewModule

# config/packages/messenger.yaml (если есть async Messages)
App\NewModule\Message\SomeMessage: async_pipeline

# config/packages/twig.yaml (если есть шаблоны)
paths:
    '%kernel.project_dir%/templates/newmodule': NewModule
```

Изменения в `config/packages/messenger.yaml` — 🟠 HIGH-LOCAL risk: усиленные проверки и review обязательны, но отдельный STOP перед изменением или Draft PR не требуется.

---

## Обработка ошибок

### Где живут исключения

- Доменные исключения → `src/{Module}/Exception/`
- Каждое исключение — `final class`, extends `\RuntimeException` или базовый `AppException`
- Имя отражает причину: `DocumentNotFoundException`, `InsufficientBalanceException`

### Поток исключений

```
Domain / Action бросает исключение
    → ExceptionListener ловит (src/EventSubscriber/ или src/Shared/)
    → Маппит в HTTP-статус + JSON-ответ
    → Controller не содержит try/catch (кроме технических кейсов)
```

### Стандарт формата ошибок (вводим сейчас — новый код только так)

```json
{
  "error": {
    "code": "document_not_found",
    "message": "Документ не найден"
  }
}
```

- `code` — snake_case-идентификатор, стабильный (фронт и интеграции на него завязываются)
- `message` — человекочитаемый текст (можно показывать пользователю)
- HTTP-статус несёт семантику: 404 / 422 / 403 / 500 — не всё в 200

### Запрещено

```
try/catch с пустым телом          — глотать исключения молча нельзя
throw new \Exception('ошибка')    — только конкретные доменные классы
return null вместо исключения     — если сущность обязана существовать, бросить исключение
```

---

## Логирование

Стек: **Sentry** для ошибок. Monolog — только для структурированных INFO/DEBUG-событий локально.

### Уровни

| Уровень | Когда |
|---|---|
| `ERROR` | Необработанное исключение, сбой внешнего сервиса — Sentry отправляет алерт |
| `WARNING` | Нештатное, но ожидаемое (retry, таймаут API, невалидный входящий вебхук) |
| `INFO` | Бизнес-события: старт/финиш async-задачи, отправка документа, импорт завершён |
| `DEBUG` | Только для локальной отладки, не должен попадать в прод |

### Правило выбора: `error` vs `warning` (критично — это шум в GlitchTip)

В GlitchTip уходит **только `ERROR`+**. Поэтому уровень = ответ на вопрос **«нужно ли будить человека?»**, а не «насколько громко».

- `error` — **инцидент**: неустранимый сбой, баг, повреждение данных. Создаёт алерт.
- `warning` — **ожидаемо и обрабатывается само**: retry, 429, таймаут, невалидный вход + skip, доменное «данные не готовы». В GlitchTip **не идёт**.

Антипаттерны (ложные алерты): `error` на transient-сбое, который ретраится; `error` на ожидаемом доменном условии; `error` на невалидном входе со `skip`. В Messenger: `warning` + `RecoverableMessageHandlingException` пока ретраим, `error` + `UnrecoverableMessageHandlingException` — только на неустранимом. В циклах — один агрегированный `error` со счётчиком, не по записи.

Паттерны и примеры → `PATTERNS.md` §23.

### Health-гейты: охват проверки = охват починки

Проверка, которая роняет exit code или пишет `error`, не должна утверждать шире, чем то, что чинит соответствующий ей процесс. Расхождение по любой оси — временное окно, скоуп компании/магазина, фильтр статуса, подмножество сущностей — даёт вечно красный гейт: ремонт до этих данных не дотягивается, а гейт их всё равно считает. Это не поломка, а ложный алерт, и он обесценивает канал целиком.

- Гейт с ненулевым exit code строить на **свежести**, а не на накопленном объёме: «сколько плохих строк появилось в окне обработки», а не «сколько их всего». Глобальный счётчик исторических ошибок — метрика для дашборда, не для алерта.
- Для каждого состояния, которое гейт умеет пометить как плохое, обязана существовать операция, переводящая его в хорошее. Недостижимое состояние — дефект пайплайна, а не повод для ночного `error`.
- Одно доменное понятие («неклассифицировано», «просрочено») определять **в одном месте** и переиспользовать в мониторе, ремонтнике и верификаторе. Три копии предиката в трёх запросах рано или поздно разойдутся и дадут взаимоисключающие показания.
- Неизвестное значение из внешней системы деградирует в видимую очередь на ручной разбор (синтетическая «неизвестная» сущность), а не в `NULL`: `NULL` одновременно ломает данные и прячет факт поломки.
- Инструмент проверки, который сэмплирует данные, обязан печатать своё покрытие («проверено 8 записей из 42») — иначе частичный результат читается как полный и даёт ложные расхождения.

Хронически красный гейт — отдельный дефект: либо сделать его достижимо зелёным, либо удалить. Оставлять красным нельзя.

### Что логировать обязательно

```
- Старт и финиш каждого MessageHandler (с ID сообщения и companyId)
- Внешние HTTP-запросы: метод, URL, HTTP-статус ответа, время (без тела)
- Изменение критичных статусов Entity (смена статуса документа, закрытие периода)
```

### Что запрещено логировать

```
пароли, токены, API-ключи         — даже в DEBUG
персональные данные (ФИО, ИНН)    — только если явно требуется и задокументировано
тело HTTP-ответа внешних API      — только ID/статус, не весь payload
```

### Как инжектировать

```php
use Psr\Log\LoggerInterface;

public function __construct(
    private readonly LoggerInterface $logger,
) {}
```

Sentry подхватывает ERROR автоматически через Monolog-handler — отдельно бросать в Sentry не нужно.

---

## Производительность

### N+1 — запрещено

```php
// ❌ N+1: запрос в цикле
foreach ($documents as $document) {
    $counterparty = $this->counterpartyRepo->find($document->getCounterpartyId());
}

// ✅ Загрузить всё одним запросом через Query-класс с JOIN или WHERE IN
$counterparties = $this->counterpartyQuery->findByIds($counterpartyIds, $companyId);
```

Обнаружил N+1 → исправь в рамках текущего Work item или до закрытия его Stage. В Stage self-review это проверяется явно.

### Пагинация — обязательна для списков

Используем **Pagerfanta** (`pagerfanta/doctrine-dbal-adapter` или `doctrine-orm-adapter`).

```php
// Query-класс возвращает QueryBuilder, не массив:
public function createByCompanyQueryBuilder(string $companyId): QueryBuilder
{
    return $this->connection->createQueryBuilder()
        ->select('d.id, d.number, d.status')
        ->from('document', 'd')
        ->where('d.company_id = :companyId')
        ->setParameter('companyId', $companyId);
}
```

```php
// В Controller:
$qb = $this->documentQuery->createByCompanyQueryBuilder($company->getId());

$adapter    = new DoctrineDbalAdapter($qb, /* countQueryModifier */);
$pagerfanta = new Pagerfanta($adapter);
$pagerfanta->setMaxPerPage(min((int) $request->query->get('limit', 50), 200));
$pagerfanta->setCurrentPage(max(1, (int) $request->query->get('page', 1)));

return $this->json([
    'items'    => iterator_to_array($pagerfanta->getCurrentPageResults()),
    'total'    => $pagerfanta->getNbResults(),
    'pages'    => $pagerfanta->getNbPages(),
    'page'     => $pagerfanta->getCurrentPage(),
    'per_page' => $pagerfanta->getMaxPerPage(),
]);
```

Параметры запроса: `?page=1&limit=50`

- `limit` — максимум 200, дефолт 50; значения сверх лимита → 422
- `setCurrentPage()` бросает `OutOfRangeCurrentPageException` — ловить в ExceptionListener → 422
- Списочный endpoint без пагинации — **запрещено** (риск OOM на больших данных)
- Query-класс отдаёт `QueryBuilder`, не `array` — иначе Pagerfanta не сможет сделать COUNT

### Индексы при новых FK-полях

Добавил `string $counterpartyId` в Entity → в миграции обязательно:

```sql
CREATE INDEX idx_document_counterparty_id ON document (counterparty_id);
-- Составной индекс если фильтруем всегда по companyId + полю:
CREATE INDEX idx_document_company_counterparty ON document (company_id, counterparty_id);
```

### Прочее

```
batch-операции (>100 записей)  — flush() каждые N итераций, не в конце цикла
raw SQL с SELECT *             — запрещено (явное перечисление колонок)
Query без companyId            — запрещено (IDOR + полный скан таблицы)
```

---

## Закрытие Work item

В конце каждого Work item:

1. Выполнить таргетированные тесты и проверки изменённого поведения.
2. Провести focused self-review scope, correctness, security, compatibility и task-owned diff.
3. Исправить findings, повторить таргетированные проверки и обновить checkpoint.
4. Автономно продолжить к следующему Work item.

Не запускать обязательное внешнее ревью, не создавать Stage Report, отдельную branch/PR, owner gate или production preflight только из-за завершения Work item.

## Закрытие верхнеуровневого Stage

После интеграции всех Work items Stage — строго по порядку:

1. Прогнать полный релевантный набор Stage checks; для Codex Cloud — релевантные `make codex-*` targets. Статический анализ запускать только через существующий target.
2. Провести отдельный внутренний review полного Stage diff от `stage_base_commit`, включая Work-item commits, staged/unstaged и task-owned untracked files. Любой красный пункт — Stage не закрыт.
3. Исправить все BLOCKER/IMPORTANT и безопасные in-scope MINOR findings.
4. Повторить релевантные проверки и внутренний review до green. После трёх неудачных циклов выполнить root-cause analysis и сменить безопасный подход внутри scope; STOP только при реальном блокере.
5. Реализующий агент самостоятельно запускает внешнее read-only ревью другим агентом по разделу «Внешнее ревью»; не просит Владельца «запросить review».
6. Проверить findings, исправить подтверждённые замечания и повторить проверки, внутренний и внешний review до `REVIEW_GREEN`.
7. Добавил Facade / Facade-метод / Enum / новую Entity → **обнови `ARCHITECTURE.md` до закрытия Stage**. Это источник правды для Projects-чатов. Без обновления — Projects будет выдумывать интерфейсы.
8. Сохранить **Stage Report** в `docs/tasks/<id>/stages/stage-<N>.md` с результатами обоих review.
9. Сделать commit только task-owned файлов Stage: Conventional Commits, сообщение отражает цель Stage.
10. Выполнить non-force push текущей task-ветки.
11. Создать или обновить единственный Draft PR задачи; не переводить его в Ready без явного поручения.
12. `owner_gate: no` → продолжать к следующему Stage автономно. `owner_gate: yes` → завершить объявленный Release Gate и запросить конкретное решение Владельца. HIGH-EXTERNAL → STOP непосредственно перед внешним действием.

## Закрытие задачи (Final Release Gate)

1. Прогнать доступный полный набор: `make site-test && make site-cs-check`; для Codex Cloud — релевантные `make codex-*` targets. Статический анализ запускать только через существующий target.
2. Сверить построчно «Глобальные запреты» и ограничения из спецификации.
3. Провести финальный внутренний review полного task diff и исправить in-scope findings.
4. Реализующий агент самостоятельно запускает финальное внешнее read-only ревью другим агентом; повторяет проверки и оба review до `REVIEW_GREEN`.
5. Собрать `docs/tasks/<id>/handoff.md`:
   - summary всех верхнеуровневых Stage и Work items,
   - список миграций (up/down, деструктивные операции),
   - список изменённых публичных контрактов,
   - риски,
   - follow-ups, которые сознательно вынесены за scope.
6. Закоммитить оставшиеся изменения текущей задачи.
7. Выполнить non-force push task-ветки.
8. Создать или обновить Draft PR и оставить его в Draft.
9. Передать Владельцу финальный отчёт, CI status и ссылку на Draft PR; запросить точное Release Gate решение.

Дополнительное подтверждение перед review, исправлениями, тестами, commit, push, Draft PR и handoff не требуется. Перевод PR в Ready, merge и release — только в Release Gate по явной инструкции Владельца. Production preflight и каждая mutating production action — только в отдельном Production Gate.

## Design System

This project uses a custom design system. Everything visual goes through it.

**Visual reference:** `site/ui-kit/storybook.html` — open in browser to
see all components, tokens, and Money formats.

**Rules document:** `site/ui-kit/decisions.md` — read first, it's compact.

**Source audit:** `site/ui-kit/design-audit.md` — original analysis,
historical reference.

## Hard Rules

1. **Tokens only.** Use CSS variables from `storybook.html` `:root`.
   No raw hex, no out-of-scale font sizes, no arbitrary spacings.

2. **Existing components only.** Button, Input, Money, Badge, StatusPill,
   Avatar, Toggle, Table, KPI card, Card, Dropdown, Tabs, Drawer, Modal,
   Empty state, Direction indicator, Sidebar, EntityPicker, TreePicker, Tags.

   Full list with classes in `decisions.md`.

3. **Money rules are sacred.** Minus = U+2212, thin space = U+2009 between
   digit groups, ₽ as suffix, tabular-nums, color only for deltas (not
   balances). 12 canonical formats in `decisions.md` → Money rules.

4. **Icons inherit currentColor** inside components. Color is applied to
   the whole menu item by semantic role (default/primary/danger), not to
   icons individually. No emoji icons.

5. **No unnecessary new components.** If a pattern isn't covered:
    - First, adapt an existing component when this preserves semantics and accessibility
    - If the approved task clearly requires a new component and no existing component fits, add it autonomously using current UI Kit tokens and patterns; update `storybook.html`, `decisions.md`, tests/mapping, and CHANGELOG in the same stage
    - STOP only when the design/behavior choice is materially ambiguous or would expand scope
    - Never invent ad-hoc components in screen files

6. **Universal pickers.** For flat entity selection (contractor / project /
   company / account) — `EntityPicker`. For hierarchical (category / OPiU
   article / tagged projects) — `TreePicker`. Don't create one-off pickers.

## Where to put things

- New screens → `site/screens/<name>.html`, one screen per file
- Each screen imports tokens (copy `:root` block from storybook.html, or
  reference storybook tokens in comments)
- New UI Kit additions → `site/ui-kit/storybook.html` + update `decisions.md`
    + bump version in `README.md`

## Versioning

Current UI Kit version: v1.1
- v1.0 — initial UI Kit (Foundations, Money, base components)
- v1.1 — added Sidebar, EntityPicker, TreePicker, Tags

When updating UI Kit:
1. Update `storybook.html`
2. Regenerate `decisions.md`
3. Update README.md changelog
4. Commit with message `ui-kit: vX.X — what changed`
5. Tag: `git tag ui-kit-vX.X`
