## Stage 2: RoundingMode enum — DONE

**Риск:** 🟢 LOW
**Следующее действие:** continue autonomously

### Что сделано
- Создан `enum RoundingMode` (HALF_UP, HALF_EVEN) с методом
  `roundToInteger(string $value): string` — округление bcmath-decimal до целого
  (half-up = от нуля; half-even = банковское, к чётному). Без float.

### Затронутые файлы
- `site/src/Shared/Domain/ValueObject/RoundingMode.php` — new
- `site/tests/Unit/Shared/Domain/ValueObject/RoundingModeTest.php` — new

### Self-review
- [x] Scope compliance
- [x] Patterns / naming — `enum` без `final` (PHP implicitly final)
- [x] Forbidden actions — none
- [x] Security — N/A
- [x] CS-Fixer чисто; PHPStan — N/A (не установлен)
- [x] Tests — `RoundingModeTest` зелёный (граничные .5 для обеих стратегий)

### Команды для проверки
- `docker compose run --rm site-php-cli composer test:unit -- --filter RoundingModeTest`

### Риски / на что обратить внимание ревьюеру
- нет

### Открытые вопросы
- нет
