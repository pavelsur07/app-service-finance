## Stage 3: Doctrine custom Type + Embeddable-маппинг Money — DONE

**Риск:** 🔴 HIGH
**Следующее действие:** 🛑 STOP — обязательное ревью Владельцем (миграция конфига + правка
домена, используемого Ingestion)

### Что сделано
- `App\Shared\Infrastructure\Doctrine\MoneyAmountType` (new) — `extends BigIntType`,
  `NAME = 'money_amount_minor'`, `convertToPHPValue → ?int`. Мостик bigint→int для гидрации
  `readonly int $amountMinor`.
- `Money` помечен `#[ORM\Embeddable]`; на свойствах конструктора — `#[ORM\Column]`
  (`amountMinor` через `MoneyAmountType::NAME`, `currency` — string(3)). Публичный API и
  поведение VO не изменены.
- `config/packages/doctrine.yaml`:
  - зарегистрирован тип `money_amount_minor`;
  - **(уточнение к плану)** добавлен маппинг `SharedValueObject` (dir
    `src/Shared/Domain/ValueObject`) — в плане было ошибочно «регистрации не требует»: для
    Symfony per-namespace driver chain namespace embeddable обязан быть покрыт маппингом,
    иначе Doctrine не разрешит метаданные `Money`;
  - `when@test` → маппинг `TestFixtures` (для fixture-сущности Stage 4).
- `ARCHITECTURE.md` — добавлена секция «Shared Value Objects — Money» (API + Embeddable + тип).

### Затронутые файлы
- `site/src/Shared/Infrastructure/Doctrine/MoneyAmountType.php` — new
- `site/src/Shared/Domain/ValueObject/Money.php` — modified (ORM-атрибуты)
- `site/config/packages/doctrine.yaml` — modified
- `ARCHITECTURE.md` — modified

### Self-review
- [x] Scope compliance — боевые Entity не тронуты, миграций БД нет
- [x] Patterns — тип по образцу `EncryptedJsonType`; атрибутный маппинг как в проекте
- [x] Forbidden actions — none (не legacy-зона; messenger.yaml не трогался)
- [x] Security — N/A
- [x] `doctrine:schema:validate --skip-sync` → mapping OK по всему приложению
- [x] CS-Fixer чисто на изменённых файлах; PHPStan — N/A (не установлен)
- [x] Regression — `MoneyTest`, `RoundingModeTest`, `FinancialTransactionTest` зелёные (34)
- [x] ARCHITECTURE.md обновлён

### Команды для проверки
- `doctrine:schema:validate --skip-sync`
- `composer test:unit -- --filter '(MoneyTest|FinancialTransactionTest)'`

### Риски / на что обратить внимание ревьюеру
- 🔴 Изменён `config/packages/doctrine.yaml` (новый dbal-тип + 2 маппинга) — требует ревью.
- ORM-атрибуты добавлены на доменный VO в `Shared/Domain` (по решению Владельца — без обёртки).
- Embeddable отдельной таблицы не создаёт; прод-схема не затронута (validate mapping OK).

### Открытые вопросы
- нет
