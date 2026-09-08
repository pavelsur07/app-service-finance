# Внешнее ревью другим агентом

Политика — когда ревью обязательно, что считается зелёным, бюджет раундов —
в `AGENTS.md` §7.2. Здесь механика: команды, промпт, обработка сбоев.

## Кто кого ревьюит

| Реализацию ведёт | Внешний ревьюер | Команда |
|---|---|---|
| Codex | Claude Code | `claude -p ...` |
| Claude Code | Codex | `codex exec -s read-only --ephemeral ...` |

Ревьюер только читает и отвечает. Он не правит файлы, не трогает Git, не
вызывает внешние сервисы, не запускает вложенных ревьюеров. Ревьюеру нельзя
передавать секреты, значения окружения, учётные данные и production-дампы.

## Бюджет времени

Один раунд — примерно 10 минут. Ревью обёрнуто в `timeout 900`. Small-задача
получает один раунд на handoff; Large — по раунду на каждый HIGH-LOCAL Stage и
один на handoff. Повтор раунда нужен только после исправленного BLOCKER.
Больше трёх раундов на одну точку ревью — STOP с отчётом, а не четвёртый раунд.

## Стандартный промпт

Один и тот же текст для обоих ревьюеров. Сохранить в scratchpad как
`prompt.txt`, подставив базовый коммит:

```text
You are an independent senior reviewer invoked by another agent. Read-only:
do not edit files, do not change Git state, do not call external services,
do not start another reviewer. Do not read .env files, credentials, keys or
production dumps.

Review the diff <stage_base_commit>..HEAD of the repository app-service-finance
(Symfony 7.4, site/). Project rules: AGENTS.md, CLAUDE.md; patterns in
PATTERNS.md; contracts in ARCHITECTURE.md — consult only the sections the diff
touches.

Check, only where relevant to this diff: scope compliance, correctness and
edge cases, company isolation and IDOR (every Repository query takes
companyId), authorization, financial calculations and Money, transactions and
idempotency, migrations and indexes, Messenger retries and concurrency, error
handling and log levels, N+1, test quality, secrets and PII, unnecessary
complexity.

Report only BLOCKER and IMPORTANT findings, plus MINOR findings that are
trivially fixable (at most five). For each: severity, file:line, evidence,
impact, concrete fix. No general advice, no restating the diff, no requests
for a re-review.

If there is no BLOCKER and no IMPORTANT finding, end the response with the
exact standalone line:
REVIEW_GREEN
```

Если ревьюер лишён шелла (форма через stdin ниже), факты, которые он не может
добыть сам — структура схемы, объёмы данных, замеры с прода — дописываются в
конец промпта, а само ограничение фиксируется в Stage Report или handoff.

## Claude Code ревьюит Codex

Preflight (вывод `claude auth status` не печатать и не сохранять):

```bash
command -v claude >/dev/null && claude auth status >/dev/null
```

Вызов из корня репозитория:

```bash
timeout 900 claude -p \
  --safe-mode \
  --permission-mode dontAsk \
  --effort high \
  --tools "Read,Glob,Grep,Bash" \
  --allowedTools "Read" "Glob" "Grep" \
    "Bash(git status)" "Bash(git status *)" "Bash(git diff)" "Bash(git diff *)" \
    "Bash(git log)" "Bash(git log *)" "Bash(git show)" "Bash(git show *)" \
    "Bash(git rev-parse *)" "Bash(git merge-base *)" \
  --disallowedTools "Edit" "Write" "NotebookEdit" "WebFetch" "WebSearch" "mcp__*" \
  --strict-mcp-config \
  --no-session-persistence \
  --max-turns 40 \
  --output-format text \
  "$(cat prompt.txt)" > review.txt
```

Не использовать `--dangerously-skip-permissions`; не выдавать `Edit`, `Write`,
неограниченный `Bash`, web или MCP. `--safe-mode` отключает хуки, плагины,
skills, MCP и auto-memory ревьюера, чтобы локальные настройки не внесли запись
или интерактивный запрос.

`Reached max turns` — сбой конфигурации, не гейт: сузить промпт до точного базового
коммита и изменённых файлов, повторить один раз с `--max-turns 80`.

## Codex ревьюит Claude Code

Базовая форма — дифф передаётся через stdin, ревьюеру не нужен шелл:

```bash
{ cat prompt.txt; echo; echo '--- DIFF ---'; git diff <stage_base_commit>..HEAD -- site/; } \
  | timeout 900 codex exec -s read-only --ephemeral -o review.txt -
```

Из песочницы Bash сеть глушится — вызывать вне песочницы (в Claude Code —
`dangerouslyDisableSandbox` у этого вызова). Если Codex падает на
`bwrap: loopback: Failed RTM_NEWADDR`, это песочница внутри песочницы: та же
stdin-форма — обязательная повторная попытка, а не блокер.

Запускать ревью в фоне сразу после зелёного внутреннего review и параллельно
готовить Stage Report или handoff — так раунд не блокирует остальную работу.

## Обработка результата

1. Прочитать `review.txt`. Точная отдельная строка `REVIEW_GREEN` — зелёный раунд.
2. Иначе проверить каждую находку по коду. Ложное срабатывание отклоняется с
   записанной технической причиной.
3. Исправить подтверждённые BLOCKER и IMPORTANT, безопасные MINOR. Прогнать
   релевантные проверки и внутренний review исправлений.
4. Был исправлен BLOCKER → новый раунд на обновлённом полном диффе. Только
   IMPORTANT/MINOR → повтор не нужен, в отчёте: «fixed without re-run».
5. В Stage Report или handoff: число раундов, подтверждённые и отклонённые
   находки с причинами, ограничения ревьюера.

Упавшая команда, таймаут, отказ в правах, ошибка аутентификации, обрезанный
вывод или отсутствие маркера зелёным ревью не являются. Одна повторная попытка
после осмысленного исправления; если и она не завершилась — STOP с
санитизированным текстом ошибки. Заявлять `REVIEW_GREEN` без маркера или без
доказательства исправлений нельзя.
