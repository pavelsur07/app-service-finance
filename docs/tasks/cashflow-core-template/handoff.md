# cashflow-core-template — handoff (PR 1: Stage 1–2)

**Branch:** `feat/cashflow-core-template` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2934 · **CI:** CI_STATUS

## Summary of stages
- Stage 1 — новая компания получает ядро ДДС: 7 групп и 30 статей под системными группами
  деятельности, с направлением и описанием «что входит и почему». Последняя фраза описания —
  пометка «ОПиУ — напрямую, в …» или «ОПиУ — не попадает»; связь с ОПиУ не ставится.
  Системные «Поступления»/«Выбытия» новой компании получают описание.
- Stage 2 — ai-service принимает необязательное описание статьи (контракт ai-task v1.2.0).
  Промпт не меняется, сайт описание пока не шлёт.

## Files changed
- `site/config/cash/cashflow_templates/core.yaml`, `site/config/services.yaml`
- `site/src/Cash/Application/{CashflowCoreTemplateReader,ApplyCashflowCoreTemplateAction}.php`
- `site/src/Cash/EventListener/ApplyCashflowCoreTemplateOnCompanyCreated.php` (priority −10)
- `site/src/Cash/DTO/Template/CashflowCoreTemplateNode.php`, `site/src/Cash/Exception/CashflowCoreTemplateException.php`
- `site/src/Cash/Repository/Transaction/CashflowCategoryRepository.php` — `hasRegularByCompany()`
- `site/src/Cash/Service/Category/CashflowSystemCategoryService.php` — описания технических статей при создании
- `services/ai-service/.../categorize_transactions/schemas.py`, `services/_contracts/ai-task/v1/{schema.json,CHANGELOG.md}`
- тесты, `ARCHITECTURE.md`, `docs/tasks/cashflow-core-template/`

## Migrations
- none

## Public API / contract changes
- ai-task v1.2.0: `categorize_payload.categories[].description` — необязательное, аддитивно.

## Checks
- php-cs-fixer (весь проект) — 0 of 3923; strict-types — 0 of 3923
- PHPStan level 8 (весь проект) — No errors
- PHPUnit полный набор — OK (8566 tests, 48205 assertions)
- ai-service: ruff, ruff format, mypy — OK; pytest tests/unit — 178 passed

## Reviews
- internal: 1 итерация в отдельной сессии — BLOCKER нет; IMPORTANT (пометка называла статьи ОПиУ,
  которых нет в маркетплейсном дереве) исправлен; MINOR исправлены, кроме \DomainException
  «компания не найдена»/«группа не создана» — как в соседнем действии, это не ошибки шаблона.
- external: Codex, 1 раунд — REVIEW_GREEN.

## Risks, limitations, follow-ups
- Существующие компании не меняются; ядро для них — отдельная задача.
- Владельцу на заметку (текст, не код): «Возвраты покупателям» помечены «напрямую в ОПиУ» — у продавцов
  маркетплейсов возвраты уже приходят отчётом площадки; «Продажа оборудования» — в прочие доходы попадает
  вся цена продажи, а не прибыль сверх остаточной стоимости.
- PR 2 (Stage 3): сайт шлёт описание в ИИ, промпт v4, подсказка в форме — только после деплоя этого PR.

## Owner decision
Ready: PR #2934 "feat: ядро статей ДДС с описаниями для новой компании" — merge into master with automatic production deploy?
Reply: "merge and deploy #2934"
