# Final Handoff — encryption-key-rotation (tooling)

## Сводка этапов
| Stage | Содержание | Коммит | Review |
|---|---|---|---|
| 1 | Карта версий ключей из `APP_ENCRYPTION_KEYS_JSON` + проброс конфига | `e45841c9` | REVIEW_GREEN |
| 2 | Команда ротации + codec rotateIfNeeded + тесты + runbook | `40e3eb9b` | REVIEW_GREEN |

## Что доставлено
- `FileBasedSecretKeyProvider` читает версии ключей из env-карты (приоритет: файл → env-карта → fallback); обратная совместимость с текущей схемой FALLBACK_KEY
- Прод-проводка: `APP_ENCRYPTION_KEYS_JSON`, `APP_ENCRYPTION_CURRENT_KEY_VERSION` через GitHub Secrets → deploy.yml → docker-compose.prod.yml → контейнеры
- Команда `app:marketplace:rotate-connection-keys` (dry-run/--execute, идемпотентная)
- Runbook: `docs/maintenance/encryption-key-rotation.md`

## Миграции
- Нет (код и конфигурация только).

## Публичные контракты
- Новые env-переменные (пустые дефолты — поведение идентично текущему).
- Новая console-команда (read-only по умолчанию).

## Проверки
- unit: 1731/1731 OK (12 тестов провайдера, включая 3 исходных)
- integration + functional Marketplace: 346 OK
- lint:container, compose config (с реалистичным JSON), yaml workflow — OK

## Ревью
- Внутренние review обоих Stage — без BLOCKER/IMPORTANT
- Внешний Claude review — REVIEW_GREEN ×2 (подробности `stages/stage-1.md`, `stage-2.md`)
- Инцидент: при Stage 1 файл существующих тестов провайдера был случайно перезаписан — 3 оригинальных теста восстановлены из git и сохранены в итоговой версии

## Риски
- До обновления секретов поведение прода не меняется (пустые дефолты)
- Сама ротация данных на проде — отдельное контролируемое действие по runbook'у, этой задачей НЕ выполняется

## FOLLOW-UP (вне scope)
- Contract-задача (прекращение plaintext-записи, drop legacy-колонки, маска ключа в UI)
- Wrapper `codex-console` для rotate-команды — при необходимости запуска агентом (пока не требуется: ротация выполняется владельцем по runbook'у через docker exec)

## Ветка и PR
- Branch: `task/encryption-key-rotation`
- Draft PR: (создаётся)

## Требуемое решение владельца
Мерж PR. После мержа ротация выполняется вами по runbook'у в удобное время
(секреты → деплой → dry-run → execute → контроль → уборка v1).

Рекомендуемый ответ: `мерж`
