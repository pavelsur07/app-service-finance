# Исправления и ограничения итоговых гейтов

## PHPStan и baseline

Первый локальный `make site-stan` сообщил 11 `ignore.unmatched` вне Cash. Четыре исходных файла byte-identical базе задачи. Три записи Loan/Marketplace были восстановлены после последовательного анализа. Затем чистый CI PR #2978 (run43368032638, Static analysis) подтвердил ещё девять предупреждений в восьми исходных patterns Notification/Wildberries. Их удаление по локальному кэшу было ошибкой: все восемь восстановлены дословно из task base1f874580f. В итоге baseline других модулей сохранён; их исходники не менялись. Локальный result cache очищен для повторного полного анализа; CI новой головы обязан пройти перед merge.

Отдельный `phpstan-baseline-guard.sh` выявил четыре NEW key после извлечения методов, хотя суммарный count уменьшился. Предупреждения устранены в Cash, а не перенесены в новые suppressions:

- Локальные companyId/accountId уточнены PHPDoc согласно проверенным конструкторам Company/MoneyAccount: UUID присваивается немедленно и не обнуляется. Нет runtime cast/guard и изменения поведения при ошибке.
- Поле private getTargetId описано точным union четырёх реально передаваемых значений.
- `$plan?->changes ?? []` заменено эквивалентным `$plan->changes ?? []`.
- Удалены шесть разрешённых этим уточнением Cash patterns.

Serial локальный `make site-stan` ранее дал exit0; после исправления CI выполнен новый полный анализ с очищенным result cache. Канонический CI проверяется перед merge. Финальный ratchet: exit0, NEW/GREW отсутствуют; 79 обнаруженных git renames (80 логических переносов в moves.json), нормализована 41 строка базы. Сумма baseline occurrences 3260→3247. Ни одного нового suppression или ослабления правил нет.

Свежий scoped re-review этих исправлений: BLOCKER0/IMPORTANT0/MINOR0, семантических изменений нет. Логи: `/tmp/cash-final-serial-stan.log`, `/tmp/cash-baseline-guard-final-restored.log`.

## Стиль

Первый полный `make site-cs-check` нашёл девять несовпадений PHPDoc alignment в файлах задачи. Причина: ранние focused runs использовали dist config, а полный гейт — `.php-cs-fixer.php` с left alignment. Исправлено по действующему project config; полный повторный cs-check exit0, 0/4099. Strict-types exit0, 0/4099. После исправлений focused project-config CS: 0/11. Логи `/tmp/cash-final-site-cs-check.log`, `/tmp/cash-full-site-cs-strict-types.log`.

## Полный набор тестов

`make site-test` (vendor уже установлен, повторный composer install пропущен) завершился кодом137 после 7300+ тестов без показанного assertion failure, одновременно с тяжёлым анализом. Конкретная причина SIGKILL не подтверждена; наблюдались RAM6GB и заполненный swap. Этот запуск не объявляется зелёным.

Полный набор проверяется последовательными независимыми PHP-процессами из той же phpunit.xml: unit4656 + integration2768 + functional1403 =8827, без исключения тестов. Unit прошёл отдельно 4656/25723; integration2768/13202 PASS. Functional: 1403 tests/10437 assertions, полный вывод PHPUnit OK. Shell exit metadata потеряна при daemon restart; suite не перезапускался, поскольку завершённый лог сохранился. Все 8827 тестов дали OK, суммарно49362 assertions. После последних уточнений Cash дополнительно прошли 74 unit-теста/278 assertions.

Команды:

```bash
make site-test-unit
docker exec -w /app cash-refactor-cli php bin/phpunit -c phpunit.xml --testsuite integration
docker exec -w /app cash-refactor-cli php bin/phpunit -c phpunit.xml --testsuite functional
```

Make targets используют Compose override `/tmp/cash-refactor-compose.yml`: /app монтирует checkout реализации, APP_ENV=test, vendor взят из существующей локальной установки при неизменённом composer.lock. Production credentials и production команды не использовались. Логи `/tmp/cash-full-site-test.log`, `/tmp/cash-full-site-test-unit.log`, `/tmp/cash-final-suite-integration.log`, `/tmp/cash-final-suite-functional.log`.

## Внешнее review

Stage3: round1 REVIEW_GREEN, четыре MINOR исправлены без re-run. Combined Stage4/handoff: round1 REVIEW_GREEN. Его MINOR executable bit исправлен (644); удаление normalizedRows отклонено по FR-4: согласованное ТЗ явно сохраняет сигнатуру. Метод остаётся тонкой совместимой точкой входа; его удаление потребует отдельного изменения scope. BLOCKER/IMPORTANT внешних review:0/0. Типовые/style исправления гейтов подтверждены свежим scoped internal review; обязательного повторного внешнего round по политике нет.
