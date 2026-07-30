## Stage 4: Contract-фаза — NOT NULL и матчинг импорта по нормализованному ключу — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** yes (Release Gate 3)
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP — Release Gate: решение Владельца по Draft PR

### Scope Stage
- Stage base commit: `2a3b6fc740fccfacff88bfedd8c612ff3c9de36e` (merge Stage 1–3 в master)
- Work items completed: `4.1`, `4.2`, `4.3`

### Предусловие выполнено
Backfill на PROD выполнен 30.07.2026 по разрешению Владельца: 317 строк обработано,
повторный прогон — 0 обновлено, остаток `name_core IS NULL` = **0**. Только после
этого contract-миграция стала безопасной.

### Что сделано
- **Миграция `Version20260730160000`** — `ALTER COLUMN name_core SET NOT NULL`
  с предохранителем: перед изменением считает строки без `name_core` и падает с
  понятным сообщением («сначала выполните `app:counterparty:backfill-names`»),
  а не с невнятной ошибкой драйвера. `down()` — `DROP NOT NULL`.
- **`Counterparty::$nameCore`** сужен с `?string` до `string`, `getNameCore(): string`.
  Это закрытие отступления О1 из `plan.md`: nullable в PHP был нужен ровно на время
  между expand-миграцией и backfill, чтобы гидрация не падала на непересчитанной строке.
- **`CounterpartyRepository::findOneByNormalizedName($companyId, $nameCore, $legalFormHint)`** —
  матчинг по нормализованному ключу. ОПФ входит в ключ: `ООО "Балтийский лизинг"` и
  `АО "Балтийский лизинг"` — разные юрлица с разными ИНН (замер PROD). Условие по ОПФ
  строится ветвлением, а не через `:hint IS NULL` — PostgreSQL не выводит тип параметра,
  использованного только в `IS NULL` (эта грабля уже ловилась в Stage 2).
- **`CashFileImportService`** переключён с точного `name` на пару (`nameCore`,
  `legalFormHint`) — и в запросе, и в ключе кэша. Это закрывает D3 и попутно ту
  асимметрию регистра, которая была вынесена из MINOR M4 второго раунда ревью:
  ключ кэша больше не приводится к нижнему регистру отдельно от запроса, потому что
  `nameCore` регистронезависим по построению.

### Затронутые файлы
- `migrations/Version20260730160000.php` — new
- `src/Company/Entity/Counterparty.php` — modified (сужение типа)
- `src/Company/Repository/CounterpartyRepository.php` — modified (`findOneByNormalizedName`)
- `src/Cash/Service/Import/File/CashFileImportService.php` — modified (матчинг)
- `tests/Integration/Company/CounterpartyNormalizedMatchingTest.php` — new (контракт репозитория)
- `tests/Integration/Cash/Service/Import/File/CashFileImportCounterpartyMatchingTest.php` — new (путь импорта)
- `tests/Integration/Company/BackfillCounterpartyNamesActionTest.php` — modified

### Переписанные тесты и почему
Тесты backfill эмулировали непересчитанную строку через `name_core = NULL`. После
contract-миграции такое состояние невозможно на уровне БД, поэтому «непересчитанность»
теперь эмулируется устаревшим значением. Тест на гидрацию `NULL` удалён вместе с
самим состоянием — его место занял тест «устаревшая строка пересчитывается».
Смысл проверок сохранён: пересчёт, идемпотентность, неизменность `updatedAt`, пропуск
ненормализуемого названия.

### Self-review
- [x] Scope compliance — только contract-миграция и матчинг импорта
- [x] Security — `findOneByNormalizedName` первым параметром принимает `string $companyId`;
      тест «чужая компания не матчится» обязателен и есть
- [x] Матчинг не склеивает разные ОПФ — отдельный тест на реальный случай PROD
- [x] Архивный контрагент матчится (иначе импорт создал бы дубль рядом с архивным) — тест
- [x] Миграция обратима; прогон up → down → up на тестовой БД выполнен
- [x] Миграция не тронет данные, если backfill не выполнен — предохранитель + понятное сообщение
- [x] CS-Fixer — чисто по изменённым файлам (PHPStan в проекте нет)
- [x] Tests — `make site-test` после исправления findings: см. итоговый прогон ниже
- [x] `doctrine:schema:validate` — по `counterparty` расхождений нет
- [x] `ARCHITECTURE.md` — контракт сущности обновлён (`nameCore` обязателен)

### External review (Codex, по протоколу «Внешнее ревью» из CLAUDE.md)
- Прогон 1: `codex exec -s read-only --ephemeral` завершился
  `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted` — документированный
  сбой sandbox-in-sandbox, до чтения файлов дело не дошло. Не ревью и не блокер.
- Прогон 2 (обязательная повторная попытка): дифф и контекст переданы через stdin,
  ревьюеру шелл не нужен.
- **Ограничение зафиксировано:** ревьюер работал без шелла, то есть не мог сам
  проверить структуру схемы, объёмы данных и прогон тестов. Эти факты переданы в
  промпте как утверждения, а не проверены им независимо.
- Результат прогона 2: **1 IMPORTANT, 1 FOLLOW-UP**, `REVIEW_GREEN` не выдан.

| # | Finding | Вердикт | Действие |
|---|---|---|---|
| IMPORTANT | `CounterpartyNormalizedMatchingTest` проверял только запрос репозитория и не вызывал `CashFileImportService`: сам путь матчинга (нормализация → ключ кэша → поиск → ветка создания) оставался без теста, при этом имена тестов обещали проверку импорта | подтверждён | Добавлен `tests/Integration/Cash/Service/Import/File/CashFileImportCounterpartyMatchingTest` — 6 тестов на реальном коде сервиса: три написания дают одного контрагента; после сброса кэша контрагент находится в БД, а не только в кэше; ООО и АО дают двух; название без ОПФ и с ОПФ — разные; пустое название отклоняется. Репозиторные тесты переименованы так, чтобы не обещать лишнего |
| FOLLOW-UP | Схема lookup-then-insert не защищает от гонки при параллельных импортах одной компании | принят, за скоуп | Класс гонки существовал и до Stage 4, нормализованный ключ его не создаёт и не устраняет. Правильное решение — уникальное ограничение по (`company_id`, `name_core`, `legal_form_hint`) либо блокировка; это тот самый `UNIQUE`, который ТЗ §2 сознательно отложило до появления merge дублей. Зафиксировано как follow-up |

### Итоговые проверки
- `make site-test` — OK (2852 tests, 15729 assertions)
- Матчинг импорта покрыт тестом на реальном коде сервиса, а не только на запросе

### Команды для проверки
- `make site-test`
- `docker compose run --rm site-php-cli bin/console doctrine:migrations:migrate -n`
- `docker compose run --rm site-php-cli bin/console doctrine:schema:validate`

### Риски / на что обратить внимание ревьюеру
- Миграция на PROD пойдёт автоматически при merge (job `migrations` в `deploy.yml`).
  Предусловие уже выполнено, но если между merge и деплоем кто-то добавит строку без
  `name_core` — невозможно: колонка заполняется только через VO.
- Матчинг импорта изменил поведение: файлы, где название писали по-разному, теперь
  сойдутся в одного контрагента. Это цель, но первый импорт после релиза даст другое
  распределение привязок, чем предыдущие.

### Открытые вопросы
- Слияние существующего дубля `ООО "ПО ОБОРОНХИМ"` (две записи, у одной нет ИНН) —
  отдельная задача, автоматически ничего не сливается.
- Карточка `Ларкина Николь Николаевна` с ИНН `0` — при первой правке валидация её
  отклонит, нужен корректный ИНН или пустое поле.
