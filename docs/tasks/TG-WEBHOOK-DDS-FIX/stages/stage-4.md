## Stage 4: Не раскрывать пользователю произвольные доменные ошибки — DONE

**Риск:** 🟡 MEDIUM (затрагивает Cash-сервис — смена типа исключения на наследника `\DomainException`, обратносовместимо).
**Следующее действие:** 🛑 STOP, ждать Владельца (Phase Final).

### Проблема (из ревью)
`handleTextMessage` форвардил пользователю `getMessage()` ЛЮБОГО `\DomainException`. Сейчас безопасно (единственный — «период закрыт»), но будущее доменное исключение с техническим текстом утекло бы в чат.

### Что сделано
- Новый маркер-интерфейс `App\Shared\Domain\Exception\UserFacingException` — «сообщение можно показывать пользователю».
- Новое типизированное исключение `App\Cash\Exception\FinancePeriodLockedException extends \DomainException implements UserFacingException`.
- `CashTransactionService::assertNotLockedForCompany()` бросает `FinancePeriodLockedException` вместо безымянного `\DomainException` (обратносовместимо: подкласс `\DomainException`, существующие `catch (\DomainException)` в web-контроллерах работают как раньше).
- `TelegramWebhookController`: вместо `catch (\DomainException)` теперь `catch (UserFacingException)`. Прочие доменные/любые исключения → лог + обобщённое «Не удалось сохранить операцию».
- `ARCHITECTURE.md` — политика обновлена (показываем только `UserFacingException`).

### Затронутые файлы
- `site/src/Shared/Domain/Exception/UserFacingException.php` — new
- `site/src/Cash/Exception/FinancePeriodLockedException.php` — new
- `site/src/Cash/Service/Transaction/CashTransactionService.php` — modified (тип исключения)
- `site/src/Telegram/Controller/TelegramWebhookController.php` — modified (catch по маркеру)
- `ARCHITECTURE.md` — modified

### Self-review
- [x] Scope compliance — точечный фикс пункта ревью
- [x] Patterns / naming — маркер в Shared/Domain/Exception (как `MoneyMismatchException`), исключение в Cash/Exception, `final`
- [x] Forbidden actions — нет; смена типа исключения обратносовместима
- [x] Security — техн. детали доменных ошибок больше не утекают пользователю Telegram
- [x] CS-Fixer — чисто на изменённых файлах
- [x] PHPStan — N/A
- [x] Тесты зелёные — `tests/Telegram/Functional` (7), `CashTransactionServiceTest` (2) — регресса нет
- [x] ARCHITECTURE.md обновлён

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Telegram/Functional tests/Integration/Cash/Service/Transaction/CashTransactionServiceTest.php`

### Риски / на что обратить внимание ревьюеру
- Существующий e2e-тест «закрытый период» (`TelegramWebhookCashTransactionTest`) подтверждает, что сообщение по-прежнему показывается (теперь через маркер).
- Обратная совместимость web: контроллеры Cash ловят `\DomainException` → `FinancePeriodLockedException` по-прежнему попадает в эти catch (parity сохранён).

### Открытые вопросы
- нет.
