## Stage 4: Интеграционный тест маппинга Money — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** Phase Final (Handoff) — 🛑 STOP

### Что сделано
- Test-only fixture `App\Tests\Fixtures\Doctrine\MoneyHolder` с
  `#[ORM\Embedded(class: Money::class, columnPrefix: false)]` (таблица `test_money_holder`).
- `MoneyEmbeddableMappingTest` (KernelTestCase/IntegrationTestCase): таблица создаётся и
  удаляется через `SchemaTool` в setUp/tearDown — в прод-схему/миграции не попадает. Кейсы:
  - метадата: два поля `amount.amountMinor` / `amount.currency`, колонки `amount_minor` /
    `currency`, тип поля `money_amount_minor`;
  - round-trip persist→flush→clear→find: `Money::fromString('123.45','RUB')` читается обратно,
    `amountMinor()` возвращает **int 12345** (ключевая проверка гидрации bigint→int для
    `final readonly`), валюта 'RUB';
  - round-trip отрицательной суммы (USD).

### Затронутые файлы
- `site/tests/Fixtures/Doctrine/MoneyHolder.php` — new
- `site/tests/Integration/Shared/MoneyEmbeddableMappingTest.php` — new

### Self-review
- [x] Scope compliance — только тест + fixture, прод-код не тронут
- [x] Гидрация `final readonly` Money через Embeddable подтверждена тестом (риск из плана закрыт)
- [x] CS-Fixer чисто; PHPStan — N/A
- [x] Tests — `MoneyEmbeddableMappingTest` зелёный (3 теста, 12 assertions)
- [x] Прод-схема не затронута (таблица fixture создаётся/удаляется в тесте)

### Команды для проверки
- `composer test -- --testsuite integration --filter MoneyEmbeddableMappingTest`
  (в этом окружении DNS контейнера к site-postgres сбоит — запускалось с
  `-e DATABASE_URL=postgresql://app:secret@<postgres-ip>:5432/app`)

### Риски / на что обратить внимание ревьюеру
- 2 PHPUnit-deprecation в прогоне — конфиг-уровень PHPUnit (предсуществующие,
  `--fail-on-deprecation` не падает), не из кода задачи.

### Открытые вопросы
- нет
