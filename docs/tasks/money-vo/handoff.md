# Handoff — Money VO + Doctrine-маппинг (Embeddable + custom Type)

Ветка: `feature/money-vo-doctrine`. Базовая: `master`.
Plan: `/home/deploy/.claude/plans/tidy-wiggling-lovelace.md`.

## Summary по этапам

| Stage | Риск | Суть | Статус |
|---|---|---|---|
| 1 | 🟡 | Расширение API `Money` (fromString/toDecimalString/multiply/percentage/abs/equals/isPositive/isNegative) | ✅ |
| 2 | 🟢 | `RoundingMode` enum (HALF_UP/HALF_EVEN) | ✅ |
| 3 | 🔴 | `MoneyAmountType` + Embeddable-атрибуты + регистрация в doctrine.yaml + ARCHITECTURE.md | ✅ |
| 4 | 🟡 | Интеграционный тест маппинга (fixture-сущность, round-trip) | ✅ |

## Коммиты
1. `feat(shared): extend Money VO with decimal parsing and arithmetic` (Stage 1–2)
2. `feat(shared): map Money as Doctrine Embeddable with custom amount type` (Stage 3–4)

## Новый публичный контракт
- `App\Shared\Domain\ValueObject\Money` — новые методы (см. список). Существующие сигнатуры
  не менялись → обратная совместимость с Ingestion сохранена.
- `App\Shared\Domain\ValueObject\RoundingMode` — new enum.
- `App\Shared\Infrastructure\Doctrine\MoneyAmountType` — new DBAL-тип (`money_amount_minor`).
- `Money` теперь `#[ORM\Embeddable]` — встраивается через `#[ORM\Embedded(class: Money::class)]`.

## Миграции БД
- **Нет.** Embeddable не создаёт таблиц; боевые Entity не трогались. `schema:validate --skip-sync`
  — mapping OK по всему приложению.

## Изменения конфигурации
- `config/packages/doctrine.yaml`:
  - `dbal.types.money_amount_minor`;
  - `orm.mappings.SharedValueObject` (namespace `App\Shared\Domain\ValueObject`);
  - `when@test`: `orm.mappings.TestFixtures` (для интеграционного теста).

## Верификация
- `composer test:unit -- --filter '(MoneyTest|RoundingModeTest|FinancialTransactionTest)'` → 34 ✅
- `composer test -- --testsuite integration --filter MoneyEmbeddableMappingTest` → 3 ✅
- `doctrine:schema:validate --skip-sync` → mapping OK
- `composer cs:check` → изменённые файлы чистые
- PHPStan — **N/A**: в проекте не установлен (есть только `cs:check`); `make stan` недоступен.

> ⚠️ Окружение: embedded-DNS контейнера к `site-postgres` сбоит (`EAI_AGAIN`) —
> инфраструктурная проблема, не связана с задачей. Интеграционные тесты запускались с
> `-e DATABASE_URL=postgresql://app:secret@<postgres-ip>:5432/app`. Полный `make test`
> в этом окружении не прогонялся целиком по той же причине; прогнаны таргетированные наборы.

## Риски
- ORM-атрибуты на доменном VO `Shared/Domain/Money` (по решению Владельца — без обёртки).
- Переполнение `int` за ~±9.2·10¹⁶ ₽ (минор) — задокументированное ограничение.
- `fromString` использует half-up; `OzonMoneyParser` исторически — иное округление 3-го знака.

## Follow-ups (сознательно вне scope)
- Адаптировать `Ingestion\FinancialTransaction` на Embedded `Money` (потребует миграцию — отдельно).
- Унифицировать парсеры денег (`OzonMoneyParser`, телеграм-парсер, `FundController`) на
  `Money::fromString`.
- Внедрение Money в модуль Cash (устранение float-арифметики) — отдельная большая задача.
- При желании — `Money::allocate()` для распределения по долям.

## 🛑 Final Owner review
Merge — только после одобрения Владельцем (PR из `feature/money-vo-doctrine`).
