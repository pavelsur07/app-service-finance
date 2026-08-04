# Task: Восстановить uniq_plcat_company_code

Источник: явный запрос Владельца в чате, как follow-up из задачи
`pl-category-import` (см. `docs/tasks/pl-category-import/handoff.md`, риск №1).

## Контекст и первопричина

`pl_categories` имел `CREATE UNIQUE INDEX uniq_plcat_company_code (company_id,
code)` с `Version20251001120000`. `Version20251105174115` (auto-generated
Doctrine `migrations:diff`) удалил его в `up()` и не восстановил.

Причина — не бизнес-решение, а рассинхрон маппинга: `PLCategory` объявляет
уникальность только через Symfony-валидатор `#[UniqueEntity(fields:
['company', 'code'])]`, но НЕ через Doctrine ORM `#[ORM\UniqueConstraint]`.
Для сравнения, `CashflowCategory` объявляет оба уровня (`#[ORM\UniqueConstraint(name:
'uniq_cashflow_category_company_code', ...)]` + `#[UniqueEntity]`). Из-за
этого `migrations:diff` не знал, что raw-SQL индекс должен существовать, и
сгенерировал его удаление. Без добавления ORM-атрибута к Entity история
повторится при следующем автогенерированном diff — это и есть корневая
причина, которую нужно чинить, а не только пересоздавать индекс.

## Согласовано с Владельцем

При существующих дублях `(company_id, code)` — авто-разрешить: оставить
`code` на одной строке дубля (детерминированно, по наименьшему `id` —
`createdAt` у `PLCategory` нет, id случайные uuid4, хронологии не
восстановить), у остальных обнулить. Залогировать через `$this->write()`
количество затронутых строк.

## Stage 1: Root-cause fix — ORM-маппинг + миграция с safe dedup
Risk: 🟠 HIGH-LOCAL (миграция; но только локальная/test БД — прод отдельным Production Gate)
owner_gate: no
release_candidate: no
independently_deployable: yes
stage_base_commit: fcd8b9874df93e2a878bd4506dc61e702c62be6

Definition of Done:
- `PLCategory` объявляет `#[ORM\UniqueConstraint(name: 'uniq_plcat_company_code',
  columns: ['company_id', 'code'])]` (парность с `#[UniqueEntity]`, как у
  `CashflowCategory`).
- Новая миграция: сначала безопасно разрешает существующие дубли (nullify
  все, кроме одной строки на group), логирует count через `$this->write()`,
  затем создаёт `uniq_plcat_company_code`.
- Прогнано локально: синтетический сценарий с дублями до и после — миграция
  не падает, индекс создаётся, дубли разрешены детерминированно.
- `doctrine:schema:validate` (или эквивалент) подтверждает, что ORM-маппинг
  и схема БД синхронизированы для этого индекса — предотвращает повтор
  auto-diff-дропа в будущем.
- Полный test suite зелёный.

Work items:
- 1.1 — Добавить `#[ORM\UniqueConstraint]` в `PLCategory.php`.
- 1.2 — Написать миграцию `VersionYYYYMMDDHHMMSS` (dedup + index), `down()`
  дропает индекс (данные, обнулённые в `up()`, не восстанавливаются — это
  явно задокументированная необратимость `down()`, как и для любого dedup).
- 1.3 — Локальный regression-тест дедупликации: создать сценарий с дублями
  на test БД (интеграционный тест или ручной прогон через psql), доказать,
  что миграция и до, и после её применения ведёт себя предсказуемо.
- 1.4 — Прогнать полный test suite, `doctrine:schema:validate`.

Stage checks:
- `doctrine:migrations:migrate --env=test` на сценарии с синтетическими
  дублями.
- `composer test` (полный набор).
- `doctrine:schema:validate` (или `doctrine:migrations:diff --dry-run` без
  новых операций над `pl_categories`, доказывающий, что маппинг и схема
  теперь согласованы).

Reviewer focus:
- Dedup-логика: детерминированность, отсутствие потери строк (только code
  обнуляется, не удаляется вся категория), корректность SQL (partition by
  company_id+code, keep min id).
- `down()` честно документирует необратимость.
- Никаких прод-действий в этом Stage — только локальная миграция.

## Production Gate (отдельно, после явного разрешения Владельца)

- Прогон миграции на PROD **не входит** в этот Stage и не выполняется
  автономно. Перед прод-прогоном: бэкап `pl_categories`, отдельное явное
  разрешение Владельца, сверка "до/после" (кол-во строк, кол-во ненулевых
  code, кол-во dedup-групп).
