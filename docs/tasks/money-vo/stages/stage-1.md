## Stage 1: Расширение API Money VO — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (→ Stage 3 — 🛑 STOP, HIGH risk)

### Что сделано
- В `Money` добавлены методы (только дополнение, существующие сигнатуры не тронуты):
  - `fromString(string $decimal, string $currency): self` — парсинг decimal-строк в минорные
    единицы, currency-aware масштаб через `Intl\Currencies`, округление half-up, bcmath.
  - `toDecimalString(): string` — обратное преобразование с числом знаков по валюте.
  - `multiply(string $factor, RoundingMode $mode = HALF_UP): self`
  - `percentage(string $percent, RoundingMode $mode = HALF_UP): self`
  - `abs()`, `equals(self)`, `isPositive()`, `isNegative()`
  - приватный `fractionDigits(string $currency): int` (fallback 2, как в CurrencyFormatExtension).
- Вся арифметика — bcmath, без float.

### Затронутые файлы
- `site/src/Shared/Domain/ValueObject/Money.php` — modified
- `site/tests/Unit/Shared/Domain/ValueObject/MoneyTest.php` — modified (новые кейсы)

### Self-review
- [x] Scope compliance — только Money API, существующие методы не менялись
- [x] Patterns / naming — `final readonly class`, bcmath, без float
- [x] Forbidden actions — none (нет dump/dd, нет new Service, нет flush)
- [x] Security — N/A (чистый VO, без companyId/IDOR-поверхности)
- [x] CS-Fixer — чисто на изменённых файлах; PHPStan — N/A (не установлен в проекте)
- [x] Tests — `composer test:unit --filter MoneyTest` зелёные (старые 13 + новые)
- [x] ARCHITECTURE.md — отложено до Stage 3 (вместе с Embeddable/типом)

### Команды для проверки
- `docker compose run --rm site-php-cli composer test:unit -- --filter MoneyTest`

### Риски / на что обратить внимание ревьюеру
- Переполнение `int` за пределами ~9.2·10¹⁶ ₽ — задокументированное ограничение.
- `fromString` без параметра RoundingMode (фиксированный half-up) — по плану.

### Открытые вопросы
- нет
