## Stage 1: Root-cause fix — ORM-маппинг + миграция с safe dedup — DONE

**Риск:** 🟠 HIGH-LOCAL (локальная/test миграция; PROD — отдельный Production Gate)
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** yes
**Следующее действие:** continue to Final Release Gate

### Scope Stage
- Stage base commit: `fcd8b9874df93e2a878bd4506dc61e702c62be6`
- Work items completed: 1.1, 1.2, 1.3, 1.4

### Корневая причина

`uniq_plcat_company_code` (company_id, code) на `pl_categories` существовал с
`Version20251001120000`. `Version20251105174115` (auto-generated
`doctrine:migrations:diff`) удалил его в `up()` и не восстановил. Причина —
не бизнес-решение: `PLCategory` объявлял уникальность только через Symfony
`#[UniqueEntity(fields: ['company', 'code'])]` (валидатор, application-level),
без парного Doctrine ORM `#[ORM\UniqueConstraint]` (schema-level, то, что
`migrations:diff` реально сравнивает с БД). Соседняя `CashflowCategory`
объявляет оба уровня — это и есть образец, которому теперь следует `PLCategory`.

### Что сделано
- `PLCategory` получил `#[ORM\UniqueConstraint(name: 'uniq_plcat_company_code',
  columns: ['company_id', 'code'])]` — маппинг и схема теперь синхронизированы,
  повторного auto-diff-дропа не будет (подтверждено — см. проверки ниже).
- Новая миграция `Version20260804120000`:
  - read-only `COUNT` дублей `(company_id, code)` среди ненулевых code;
  - если дубли есть — `$this->write()` логирует количество, затем `addSql()`
    ставит в очередь `UPDATE`, обнуляющий `code` у всех строк дубля кроме
    строки с наименьшим `id` (детерминированно; `createdAt` у `PLCategory`
    нет, хронологию восстановить нечем; категории не удаляются, только
    поле `code`);
  - затем `addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_plcat_company_code ...')`.
  - `down()` дропает индекс; честно документирует, что обнулённые в `up()`
    коды не восстанавливаются.
- Согласовано с Владельцем заранее (см. `plan.md`): авто-разрешение дублей —
  оставить code на одной строке, обнулить у остальных, залогировать count.

### Затронутые файлы
- `src/Finance/Entity/PLCategory.php` — modified (+`#[ORM\UniqueConstraint]`)
- `migrations/Version20260804120000.php` — new
- `docs/tasks/pl-category-code-unique-index/plan.md` — new

### Self-review
- [x] Scope compliance — только эта одна связка индекс/маппинг; остальной
  обширный pre-existing schema drift (десятки несвязанных таблиц, обнаружен
  через `doctrine:migrations:diff --dry-run` при проверке) — не трогал, вне scope
- [x] Patterns / naming — миграция `final class extends AbstractMigration`,
  как все остальные в проекте; `#[ORM\UniqueConstraint]` — по образцу `CashflowCategory`
- [x] Forbidden actions — none
- [x] Security — не применимо (schema-only изменение, не runtime data access)
- [x] PHPStan/CS-Fixer/tests — CS-Fixer чисто; PHPStan не настроен в проекте
- [x] ARCHITECTURE.md — не требовалось (нет нового Facade/Enum/публичного контракта)

### Тестирование (методология — ручная проверка на реальной Postgres, как и для всех остальных 229 миграций в проекте — ни одна не имеет PHPUnit-теста)
1. Синтетические дубли (3 строки `code='DUP'` в одной компании) вставлены в test БД.
2. `doctrine:migrations:migrate --dry-run` — **данные не изменились**, миграция
   не помечена выполненной (проверено после исправления IMPORTANT-находки
   из внешнего ревью — см. ниже).
3. `doctrine:migrations:migrate` (реальный) — 2 из 3 дублей обнулены
   (наименьший `id` сохранил `code`), индекс создан (`pg_indexes` подтверждает).
4. `down()` — индекс корректно удалён, проверено через `pg_indexes`.
5. Повторный `up()` — успешен, идемпотентен.
6. `doctrine:migrations:diff --dry-run` после фикса — сгенерированный diff
   **не содержит** `uniq_plcat_company_code`/`pl_categories`-индекс — корневая
   причина закрыта. (Diff содержит большой пре-существующий drift по другим
   таблицам — удалён после проверки, не входит в scope.)
7. Синтетические тестовые данные удалены после проверки.
8. `composer test` (полный набор, 3045 тестов) — зелёный, дважды (до и после
   исправления находки внешнего ревью).

### External review
- Reviewer: Codex CLI (`codex exec -s read-only --ephemeral`)
- Iterations: 2
- Result: **REVIEW_GREEN**
- Confirmed findings fixed:
  1. IMPORTANT — прямой `$this->connection->executeStatement()` для dedup-UPDATE
     обходил очередь `addSql()` Doctrine Migrations: `--dry-run` подавляет
     исполнение только queued `addSql()`-запросов, не прямые вызовы DBAL —
     при существующих дублях `--dry-run` реально обнулил бы `code`, нарушая
     ожидаемую read-only семантику. Исправлено: UPDATE переведён на `addSql()`
     (оставлен только read-only `fetchOne()` COUNT); лог-сообщение изменено с
     настоящего на будущее время ("will null", не "nulling"), чтобы не вводить
     в заблуждение при dry-run. Проверено вручную на реальной БД (см. п.2 выше).
- Rejected findings with reason: нет
- Ограничения ревьюера: без доступа к shell/БД — весь контекст (корневая
  причина, согласованная политика дедупликации, результаты ручных прогонов)
  передан в промпте текстом.

### Команды для проверки
- `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 site-php-cli composer test` — 3045 тестов, зелёные
- `php-cs-fixer fix --dry-run --diff` на изменённых файлах — чисто

### Риски / на что обратить внимание
- **PROD не трогался и не будет тронут в рамках этой задачи.** Прогон на
  проде — отдельный Production Gate: требует явного разрешения Владельца,
  бэкапа `pl_categories`, и в идеале — предварительного `--dry-run` на самом
  проде, чтобы узнать реальное количество дублей (если есть) до реального прогона.
- Обнаружен (но не тронут) обширный pre-existing schema drift по десяткам
  других таблиц — отдельный, более крупный технический долг вне scope этой
  задачи.

### Открытые вопросы
- Нет.
