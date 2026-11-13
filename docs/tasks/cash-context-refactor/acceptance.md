# Приёмка рефакторинга Cash без изменения импорта 1С

Требования: [TASK.md](TASK.md). Порядок выполнения и команды: [plan.md](plan.md).
Все пункты ниже — будущие критерии, а не результаты уже выполненной реализации.
Для закрытия пункта записать commit, команду/тест и фактический результат в Stage
Report; на handoff собрать доказательства в `acceptance-results.md`.

## A. Граница и совместимость

- [ ] A1 / FR-1. Diff всех `protected-paths.txt` относительно implementation base
  пустой: нет изменений, удалений, переименований и новых файлов внутри защищённых путей.
- [ ] A2 / FR-1. Проверен граф зависимостей 1С: namespace, DI binding, routes,
  session keys, алгоритмы и порядок вызовов не изменены напрямую или косвенно.
  Цепочка Bank1CImportCommitController → CashFileImportPage → CashFileMappingValidator
  остаётся неизменной, несмотря на рефакторинг других файловых компонентов.
- [ ] A3 / FR-1. Неизменённые `ClientBank1CDescriptionOverrideTest`,
  `ClientBank1CImportCurrencySafetyTest`, `ClientBank1CImportServiceSoftDeleteTest`
  проходят; защищённые тесты/fixtures не редактировались и не исключались из запуска.
- [ ] A4 / FR-3. Контейнер собирается, нет unresolved service и потерянных consumers.
  `CashCategorizationResultConsumer` остаётся зарегистрированным через интерфейс/тег.
- [ ] A5 / NFR. Нет migration, зависимости, CI/CD, production-конфигурации или изменения UI.
  API, DTO и сериализованные сообщения совместимы с базой.

## B. Удаление и структура

- [ ] B1 / FR-2. У генератора внешнего ID и метода `createOperationFromTransaction`
  повторно доказано отсутствие runtime/test/dynamic consumers до удаления.
- [ ] B2 / FR-2. Удалены только два подтверждённых кандидата; живое создание
  DocumentOperation и вспомогательные методы сохранены и проверены integration-тестом.
- [ ] B3 / FR-2. Обе проверки `findMatchingRule()` сохранены через `match()->rule`;
  после этого удалён только wrapper, сигнатура `match()` не изменилась.
- [ ] B4 / FR-1. `AccountBalanceService` вместе с test-only методами и тестами неизменён.
- [ ] B5 / FR-3. Для каждого переноса есть запись source → destination; старые FQCN
  отсутствуют в исполняемом коде/config/tests, кроме явно оставленных protected classes.
- [ ] B6 / FR-3. Нет placeholder-папок, лишних интерфейсов и старых DI aliases.
  README/ARCHITECTURE отражают реальное размещение и исключение 1С.

## C. Файловый импорт

- [ ] C1 / FR-4. Golden fixtures дают прежние header/sample и import rows каждый
  в своём режиме: `;`, `,`, TAB, BOM, кавычки и разделители внутри описания,
  пустые строки/заголовки; различие двух legacy CSV-алгоритмов сохранено явно.
- [ ] C2 / FR-4. Для XLSX читается только первый лист; номера строк, даты и mapping
  совпадают с baseline. Текущая конвертация XLS не меняется.
- [ ] C3 / FR-4. Reader закрывается при завершении, исключении и освобождении
  прерванного generator; временный файл не удаляется до завершения чтения.
- [ ] C4 / FR-4. Storage keys, legacy fallback без расширения и hash содержимого
  сохранены; технический сбой storage не превращается в успешный импорт.
- [ ] C5 / FR-4. Повтор файла не создаёт дублей; две законные одинаковые строки
  остаются двумя операциями. Покрыты номер документа/пустой номер, перестановка
  строк и 199/200/201 строк вокруг текущей границы пачки.
- [ ] C6 / FR-4. Прерывание после закоммиченной пачки и повторная доставка дописывают
  только недостающее; суммы, число строк и остатки совпадают с непрерывным запуском.
- [ ] C7 / FR-4. Два jobs последовательно в одном сервисе не разделяют quotas,
  allowedRows и кэш контрагентов; чужие managed entities не отсоединяются.
- [ ] C8 / FR-4. Description plan сохраняет формат/version/row IDs/context hash;
  изменённый файл, mapping, snapshot или разрешённый набор не принимается молча.
- [ ] C9 / FR-4. Пустое описание не очищает текст; невыполненный выбор не применяется;
  конфликт не становится create; description + audit + progress сохраняются атомарно.
- [ ] C10 / FR-4. Суммы, валюта, даты, направления, категории, splits, external ID,
  источник и остатки остаются прежними; legacy jobs без плана проходят прежний путь.
- [ ] C11 / FR-1/4. Файловое сопоставление с существующими операциями источника 1С
  не меняет правила, а неизменённый тест защиты описания 1С остаётся зелёным.

## D. Автоправила

- [ ] D1 / FR-5. Матчинг проверен для каждой существующей пары field/operator,
  границ дат/сумм, нормализации текста, нескольких подходящих правил и отсутствия совпадений.
- [ ] D2 / FR-5. Победители по полям, specificity, порядок и конфликты идентичны baseline;
  никакое совпадение не использует сущности другой компании.
- [ ] D3 / FR-5. SAFE не перезаписывает ручное назначение; REPLACE_AUTO_ASSIGNED
  учитывает provenance; пары проект/ЦФО и системные fallback остаются прежними.
- [ ] D4 / FR-5. Preview не мутирует транзакцию. Его план совпадает с применёнными
  изменениями; строки, лимит и summary/breakdowns совпадают с baseline.
- [ ] D5 / FR-5. Повторное применение не меняет уже достигнутый результат; skip
  причины и обработка переводов/удалённых операций не изменены.
- [ ] D6 / FR-5. В batch preview правила и пары не загружаются на каждую строку:
  количество обращений к repository/Facade не растёт пропорционально числу операций.
- [ ] D7 / FR-5. Handler/Action используют выделенные компоненты без изменения
  транзакций, событий, routing, payload и debounce.

## E. Процесс и handoff

- [ ] E1. Baseline записан до изменения кода; старые failures отделены от регрессий.
- [ ] E2. Characterization-тесты прошли на исходном коде и после выделения компонентов;
  assertions не ослаблены ради рефакторинга. Нет новых skip/incomplete тестов без объяснения.
- [ ] E3. После каждого Stage выполнены его профильные проверки, complete-diff
  internal review и проверка protected paths; BLOCKER/IMPORTANT закрыты.
- [ ] E4. На handoff выполнены `make site-stan`, `make site-cs-check`,
  `make site-cs-strict-types`, `make site-test-unit`, `make site-test`.
  Есть фактические результаты, числа failures и объяснение ограничений окружения.
- [ ] E5. Финальный internal review выполнен в свежей сессии; external review
  проведён по AGENTS.md §7.2, есть REVIEW_GREEN либо допустимое доказательство исправлений.
- [ ] E6. В handoff есть commit, branch, PR, матрица A–E, результаты проверок,
  review metrics, сохранённые исключения и FOLLOW-UP. Нет заявления «всё зелёное» без вывода.
- [ ] E7. Реализация остаётся в одной task branch/PR; merge/deploy не выполнялись
  без отдельного решения владельца. Production acceptance — только после approval.

## Проверка защищённых путей

Перед первым изменением сохранить полный SHA базы реализации в `checkpoint.md`
и в переменную текущей shell-сессии. Не подставлять базу документов вместо базы реализации.

```bash
CASH_REFACTOR_BASE=$(git rev-parse HEAD)
export CASH_REFACTOR_BASE
```

После Work item, на закрытии Stage и перед handoff выполнить из корня репозитория:

```bash
python3 - <<'PY'
import os
import subprocess
from pathlib import Path

base = os.environ['CASH_REFACTOR_BASE']
paths = [p for p in Path('docs/tasks/cash-context-refactor/protected-paths.txt')
         .read_text().splitlines() if p and not p.startswith('#')]
changed = subprocess.check_output(
    ['git', 'diff', '--name-only', base, '--', *paths], text=True).splitlines()
untracked = subprocess.check_output(
    ['git', 'ls-files', '--others', '--exclude-standard', '--', *paths],
    text=True).splitlines()
violations = sorted(set(changed + untracked))
if violations:
    raise SystemExit('Protected paths changed:\n' + '\n'.join(violations))
print('Protected paths unchanged')
PY
```

Команда ловит staged/unstaged/committed изменения и новые неигнорируемые файлы
в перечисленных путях. Она дополняет ручное ревью зависимостей, не заменяет его.
