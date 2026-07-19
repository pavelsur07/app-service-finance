# MCP-сервер для ДДС (MVP)

Локальный MCP-сервер поверх `site-php-cli`, без внешних доступов и без HTTP.

## Цель

Дать модели read-доступ к транзакциям ДДС и CRUD к статьям ДДС и автоправилам ДДС
через stdio JSON-RPC внутри Symfony-команды.

## Транспорт

```
docker compose run --rm -T site-php-cli php bin/console app:mcp:serve --company-id=<uuid> [--allow-write]
```

- `companyId` берётся из аргумента процесса, а не из параметров tool — модель
  физически не может обратиться к чужой компании (замена `getActiveCompany()`).
- Без `--allow-write` регистрируются только read-tools.
- Своя реализация JSON-RPC-цикла (~120 строк) вместо новой зависимости: MVP нужны
  только `initialize`, `notifications/initialized`, `tools/list`, `tools/call`.

## Этапы

| Stage | Цель | Риск |
|---|---|---|
| 1 | Вынести write-логику статей и автоправил из контроллеров в Action | 🟠 HIGH-LOCAL |
| 2 | MCP-ядро: `McpServeCommand`, `ToolRegistry`, `McpToolInterface` | 🟡 MEDIUM |
| 3 | 5 tools MVP + методы `CashFacade` | 🟡 MEDIUM |
| 4 | Функциональные тесты протокола + `docs/mcp.md` | 🟢 LOW |

## Tools MVP

| tool | пишет | под капотом |
|---|---|---|
| `cash_transactions_list` | — | `CashTransactionRepository::paginateByCompanyWithFilters` |
| `cash_categories_tree` | — | `CashflowCategoryRepository::findTreeByCompany` |
| `cash_category_upsert` | ✍ | `SaveCashflowCategoryAction` |
| `cash_autorules_list` | — | `CashTransactionAutoRuleRepository::findByCompany` |
| `cash_autorule_upsert` | ✍ | `SaveCashTransactionAutoRuleAction` |

## Сознательно вне scope

- HTTP-транспорт, OAuth, MCP resources и prompts.
- Tool применения автоправил к транзакциям (`applyRule`): массовая
  перекатегоризация проводок необратима.
- Удаление статей и автоправил.
