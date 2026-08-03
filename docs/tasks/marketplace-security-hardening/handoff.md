# Final Handoff — marketplace-security-hardening

## Сводка этапов
| Stage | Содержание | Коммит | Review |
|---|---|---|---|
| 1 | H3: все HTML-формовые мутации модуля на POST + CSRF | `787e49cc` | REVIEW_GREEN (3 ит.) |
| 2 | H4: tenant-сверка в Actions обработки raw-документов | `2d30d0cd` | REVIEW_GREEN (1 ит.) |
| 3 | M5/M10: пагинация /marketplace/products; транзакция тегов | `c2e7e351` | REVIEW_GREEN (2 ит.) |
| 4 | H1: шифрование api_key подключений (expand + backfill-команда) | `d43f7704` | REVIEW_GREEN (1 ит.) |

## Файлы
~60 файлов: 12+ контроллеров, 10 twig-шаблонов, 2 Application-Actions, entity MarketplaceConnection, codec, backfill-команда, миграция, 15+ новых/обновлённых тестов, документы задачи.

## Миграции
- `Version20260803200103` — **недеструктивная** (два nullable-столбца; `down()` откатывает). Применяется штатным deploy-workflow (job `migrations`).

## Публичные контракты
- GET на ранее GET-callable мутации (test/sync/sync-period/toggle/process-realization) теперь возвращает 405 — UI обновлён соответственно; внешних потребителей этих GET нет.
- JSON API не менялся.

## Проверки
- unit: 1722/1722 OK
- integration + functional (Marketplace + Admin): 352 OK
- lint:container, lint:twig — OK
- Миграция применена локально (dev + test)

## Ревью
- Внутренние review каждого Stage — без BLOCKER/IMPORTANT
- Внешний Claude review — REVIEW_GREEN по всем 4 Stage (подробности в `stages/stage-N.md`)

## Риски
- **Деплой Stage 4 без ключа шифрования на проде сломает создание новых подключений** (`MissingEncryptionKeyException` при encrypt). Нужен `var/secrets/encryption_keys.json` с версией `v1` ИЛИ `APP_ENCRYPTION_FALLBACK_KEY` (32 байта base64) ДО деплоя. Чтение legacy plaintext работает и без ключа, риск только на записи.
- Rollback кода безопасен (plaintext пишется всегда).

## Известные ограничения / FOLLOW-UP (вне scope)
- Contract-задача (после подтверждения backfill): прекратить plaintext-запись, дропнуть legacy-колонку, перевести маску в `index.html.twig:48` на `apiKeyFor`.
- `CostsDebugController` без CSRF (debug-эндпоинты); судьба debug-контроллеров — отдельное решение.
- JSON API-контроллеры `Controller/Api/*` без CSRF — отдельное дизайн-решение (прокидывание токена в fetch).
- Пагинация по `createdAt` без уникального tiebreaker (pre-existing паттерн).
- Stage 5 из plan.md (распил MarketplaceController, Money) — отдельный план.

## Что посмотреть владельцу
- `docs/tasks/marketplace-security-hardening/plan.md` (scope-уточнения Stage 1 и 4)
- Stage Reports `stages/stage-1..4.md`
- Миграцию и `ConnectionApiKeyCodec`

## Ветка и PR
- Branch: `task/marketplace-security-hardening`
- Draft PR: https://github.com/pavelsur07/app-service-finance/pull/2291

## Требуемое решение владельца (owner gate Stage 4)
1. Разместить ключ шифрования на проде (файл `var/secrets/encryption_keys.json` с `v1` или env `APP_ENCRYPTION_FALLBACK_KEY`).
2. Добавить в prod-wrapper `codex-console` allowlist: `app:marketplace:encrypt-connection-keys` (без аргументов = dry-run; ровно `--execute` = backfill).
3. Одобрить мерж PR #2291 и запуск backfill после деплоя.

Рекомендуемый ответ:
`Ключ на проде размещён, wrapper добавлен, мерж PR #2291 одобряю, после деплоя запусти backfill --execute`
