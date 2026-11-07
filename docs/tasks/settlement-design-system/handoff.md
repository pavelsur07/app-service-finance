# Settlement UI — handoff (preparing)

**Branch:** `docs/settlement-design-system-plan` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2907 · base master

13страниц взаиморасчётов переоформлены по текущим «Транзакциям ДДС»: единая оболочка/вкладки, фильтры,6остатков, таблицы, статусы и DDS-карточки форм со сведениями/нижними действиями. Исправлены ошибки UI периода/контрагента/пагинации. Все исходные финансовые выражения и POST/auth contracts сохранены.

Files:site/templates/settlement; одна optional UI-переменная общей counterparty theme; scoped page CSS/JS + legacy-app import;11новых UI tests и только selectors2existing tests. Документация:plan/checkpoint/stages/acceptance.

**Migrations/API/business changes:** none. Rollback:revert UI commits, no data rollback.

**Checks/reviews:** acceptance.md; full suite/external result pending. Current PR remains Draft until gates/reviews resolved. Production untouched.

**Owner decision (after ready):** merge and deploy #2907.
