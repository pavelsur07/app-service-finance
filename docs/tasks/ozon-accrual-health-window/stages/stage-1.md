## Stage 1: оконный health-гейт — DONE

**Риск:** 🟡 MEDIUM
**Следующее действие:** continue autonomously (задача одноэтапная → handoff)

### Что сделано
- `unclassifiedOzonAccrualTransactions()` принимает опциональные `$from`/`$to`,
  фильтрует по `occurred_at` (`$to` inclusive → `+1 day` exclusive, паттерн
  `canonicalGroups` из verify-команды). Без аргументов — прежнее глобальное поведение.
- `OzonAccrualDailyMaintenanceCommand`: `health()`/`printHealth()` получают окно
  ремонта и передают его в запрос; оба вызова (execute и dry-run) обновлены.
- Формулировки «global taxonomy health» → «taxonomy health» (гейт больше не глобальный),
  включая scoped-warning в логе.
- Тесты: оконный запрос (2 строки in/out, global=2 vs windowed=1) и команда
  (FAILURE при неклассифицированной строке в окне, SUCCESS при строке вне окна).

### Затронутые файлы
- `site/src/Ingestion/Infrastructure/Query/ExternalCategoryAdminQuery.php` — modified
- `site/src/Ingestion/Command/OzonAccrualDailyMaintenanceCommand.php` — modified
- `site/tests/Integration/Ingestion/Infrastructure/Query/ExternalCategoryAdminQueryTest.php` — modified
- `site/tests/Integration/Ingestion/Command/OzonAccrualDailyMaintenanceCommandTest.php` — modified

### Self-review
- [x] Scope compliance — только оконный гейт, админка/status-команда не тронуты
- [x] Patterns / naming — `final readonly` query, паттерн окна как в verify-команде
- [x] Forbidden actions — none
- [x] Security (companyId, IDOR) — N/A: глобальный админский запрос без company-скоупа
  (существующий контракт), параметры биндятся, инъекций нет
- [x] CS-Fixer (точечно) / целевые тесты — green (9/9); PHPStan в проекте отсутствует
- [x] ARCHITECTURE.md — N/A (нет новых Facade/Enum/Entity)

### External Claude Code review
- Iterations: 1
- Result: REVIEW_GREEN (независимый read-only review субагентом; BLOCKER/IMPORTANT — нет, MINOR — 2)
- Confirmed findings fixed: граничные даты окна не покрывались тестом → тест усилен
  строками ровно на `$from`-день, `$to`-день и `$to`+1 (in/in/out), 9/9 green
- Rejected findings with reason: абсолютные count-ассерты предполагают чистый baseline
  тестовой БД — принято как есть: та же посылка у существующих тестов команды,
  БД готовится только миграциями (без фикстур), DAMA rollback изолирует тесты

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --filter "ExternalCategoryAdminQueryTest|OzonAccrualDailyMaintenanceCommandTest"`
- `make site-test`
- `make site-cs-check`

### Риски / на что обратить внимание ревьюеру
- Семантика гейта сузилась: ERROR теперь значит «неклассифицировано в окне ремонта».
  Хвост старше окна виден в админ-дашборде и `marketplace-categories:status`
  (они остались глобальными) и чинится разовым прогоном с `--from/--to`.

### Открытые вопросы
- нет
