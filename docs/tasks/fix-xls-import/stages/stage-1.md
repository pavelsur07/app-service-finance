## Stage 1: импорт .xls перестаёт молча умирать в очереди — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP, ждать решения Владельца по Release Gate

### Scope Stage
- Stage base commit: `f7332c6c`
- Ветка: `agent/fix-xls-import`

### Дефект

OpenSpout 4.28.5 поддерживает CSV, ODS и XLSX. Ридера для `.xls` у него нет и
никогда не было. При этом четыре места в проекте выбирали ридер по расширению
файла и на `xls` обращались к несуществующему классу:

- `Cash/Service/Import/File/FileTabularReader`
- `Cash/Service/Import/File/CashFileImportService`
- `Catalog/Infrastructure/XlsProductRowParser`
- `Marketplace/Inventory/Application/ImportInventoryCostPriceFromFileAction`

Форма загрузки `.xls` принимала, задача уходила в очередь и умирала там фатальной
ошибкой. Пользователь видел «импорт принят» и не получал ни результата, ни причины.

### Что сделано

Конвертация `.xls` → `.xlsx` через PhpSpreadsheet, который уже был в зависимостях.
Конвертер отдаёт потребителю путь читаемой копии, поэтому весь код обхода строк
остался общим для обоих форматов — поддерживается один путь вместо двух.

Конвертация выполняется **в дочернем процессе**. Это не осторожность впрок, а
единственный работающий вариант: см. раздел ниже.

Защита выстроена в четыре слоя:

1. Предел размера исходника (20 МБ) — отсекает аномальные загрузки до запуска процесса.
2. Предел числа ячеек, выведенный из `memory_limit` процесса, а не заданный константой.
3. Собственный `memory_limit` дочернего процесса — работает даже когда предел по
   ячейкам обмануть удалось.
4. Таймаут: повреждённый файл способен увести разбор в длинный цикл.

Коды возврата дочернего процесса разбираются в доменные исключения: слишком много
ячеек, файл не читается. Техническая причина берётся из stderr и кладётся в
`previous`, поэтому доезжает до Sentry, а пользователю остаётся понятный текст.

### Почему дочерний процесс, а не предел в том же процессе

Первые четыре итерации ревью закрывали дыры внутри процесса, и все они оказались
фиктивными. Тест на повреждённый файл показал, почему: обрезанный `.xls` с целой
OLE-сигнатурой — обычный результат прерванной загрузки — заставляет PhpSpreadsheet
запросить 803 МБ прямо в разборе заголовка:

```
PHP Fatal error: Allowed memory size of 1073741824 bytes exhausted
(tried to allocate 803209248 bytes) in .../Shared/OLERead.php on line 143
```

Файл занимает килобайты, до подсчёта ячеек дело не доходит, а фатальную ошибку PHP
нельзя перехватить. Любой `try/catch` в том же процессе бесполезен — воркер умирал
бы молча ровно так же, как до всей правки. Этот тест ронял весь прогон PHPUnit.

### Замеры памяти

| Лист (плотный, числа) | memory_limit | Размер .xls | Пик памяти | Байт на ячейку |
|---|---|---|---|---|
| 2000 × 20 = 40 000 ячеек | 1G | 0,70 МБ | 55,0 МБ | 747 |
| 4369 × 20 = 87 380 ячеек | 256M | 1,52 МБ | 83,5 МБ | 414 |

Вторая строка — проверка самого предела: при 256M конвертер разрешает 87 381
ячейку, и лист впритык под потолком отработал внутри бюджета. Скрипт
воспроизведения — `docs/tasks/fix-xls-import/measure.php`.

Первая строка объясняет, почему предел по размеру файла защитой не является:
файл в 0,7 МБ разворачивается в 55 МБ объектов.

### Затронутые файлы

- `site/src/Shared/Service/Storage/LegacyXlsConverter.php` — new
- `site/src/Shared/Service/Storage/TemporaryFileFactory.php` — new
- `site/src/Shared/Service/Storage/LegacyXlsTooLargeException.php` — new
- `site/src/Shared/Service/Storage/LegacyXlsTooManyCellsException.php` — new
- `site/src/Shared/Service/Storage/LegacyXlsUnreadableException.php` — new
- `site/src/Shared/Command/ConvertLegacyXlsCommand.php` — new
- `site/src/Cash/Service/Import/File/FileTabularReader.php` — modified
- `site/src/Cash/Service/Import/File/CashFileImportService.php` — modified
- `site/src/Catalog/Infrastructure/XlsProductRowParser.php` — modified
- `site/src/Marketplace/Inventory/Application/ImportInventoryCostPriceFromFileAction.php` — modified
- `site/tests/Integration/Shared/Service/Storage/LegacyXlsConverterTest.php` — new
- `site/tests/Unit/Cash/Service/Import/File/CashFileImportServiceTest.php` — modified
- `docs/tasks/fix-xls-import/memory-measurement.md`, `measure.php` — new

Миграций нет. Публичные контракты не менялись.

### Self-review

- [x] Scope compliance — только путь импорта файлов
- [x] Patterns / naming — `final readonly class`, `final class` для команды
- [x] Forbidden actions — none
- [x] Security — companyId не затрагивается, работа только с файлами
- [x] CS-Fixer / tests — green
- [x] ARCHITECTURE.md — N/A, новых Facade/Enum/Entity нет

Тесты работают на настоящем бинарном `.xls`, записанном PhpSpreadsheet, а не на
файле с подменённым расширением: дефект был именно в том, что формат принимался
по имени.

Регрессия доказана красным: `testTruncatedFileDoesNotKillTheProcess` до выноса
конвертации в отдельный процесс ронял весь прогон фатальной ошибкой.

### External review

- Reviewer: Codex CLI (read-only, ephemeral)
- Iterations: 6
- Result: REVIEW_GREEN
- Confirmed findings fixed:
  - предел размера исходника (итерация 1)
  - предел по числу ячеек вместо размера файла (итерация 2)
  - предел, выведенный из `memory_limit`, вместо числа из головы; формат сообщения
    о размере; отдельное исключение для нечитаемого файла (итерация 3)
  - оборачивание технических исключений PhpSpreadsheet (итерация 4)
  - перехват `ProcessSignaledException`; коды возврата уведены от `Command::INVALID`;
    ужесточён разбор stdout; убран мусорный файл в тесте (итерация 5)
- Rejected findings with reason: удаление `FileTabularReader::openReader()` названо
  обратной несовместимостью. Отклонено: grep по всему проекту показал ноль вызовов,
  метод был однострочным пробросом в приватный `openReaderByExtension()`, который
  жив и используется. Сохранение метода воспроизводило бы исходный дефект — он
  выдавал бы сырой ридер для `.xls` в обход конвертации.
- Ограничения ревьюера: без доступа к шеллу; результаты тестов, замеры памяти и
  результат grep переданы в промпте.

### Команды для проверки

- `docker compose run --rm site-php-cli php vendor/bin/phpunit tests/Integration/Shared/Service/Storage/LegacyXlsConverterTest.php`
- `docker compose run --rm site-php-cli php vendor/bin/phpunit`

Полный прогон: 2981 тест, единственное падение — `DashboardSnapshotGoldenTest`,
оно существовало до задачи и к этому диффу отношения не имеет.

### Риски / на что обратить внимание

- Каждая конвертация `.xls` запускает дочерний PHP-процесс с загрузкой ядра
  Symfony. Для async-импортов это несущественно, но на синхронном пути даёт
  заметную задержку старта.
- Пол памяти дочернего процесса — 128 МБ. Если воркер живёт с `memory_limit`
  ниже 256 МБ, дочерний процесс получит больше половины лимита родителя.
- Файлы `.xls` крупнее 20 МБ теперь отклоняются явно. Раньше они принимались и
  умирали в очереди, так что отказ — улучшение, но пользователь увидит новое
  сообщение.

### Открытые вопросы

- В failed-очереди прода лежит сообщение 1829 с этим дефектом. Его повторный
  запуск — мутирующее production-действие и требует отдельного Production Gate.
