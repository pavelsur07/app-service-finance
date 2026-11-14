# Этап 7 — технические задания проверки качества и бюджет

**Риск:** HIGH-LOCAL. **База:** fcd5659469e441d20d87cb77666b6d859aefc8e8.
ТЗ и интерфейсы записаны до кода в stage-7-spec.md и stage-7-interfaces.md.
Проверяемый checkpoint кода: 6199f353740227825a24045b780f643ca2ceb4bd.
**Статус: DONE.** Внутреннее и внешнее ревью закрыты; итоговые module checks пройдены.

## Результат и приёмка

- [x] Отдельный авторизованный API ai-eval-run/v1 и техническая очередь.
- [x] SQL 0002 создаёт задания, бюджет периодов и журнал резерва каждого вызова.
- [x] Общий лимит 30 ₽/сутки и 900 ₽/месяц по Москве; до 164 вызовов на запуск
      и 82 на модель. Настроенные модели проверяются раздельно с общим бюджетом.
- [x] Резерв фиксируется до HTTP; известная usage рассчитывается точно через
      Decimal. Неизвестный расход не освобождается после истечения аренды.
- [x] Повторы POST идемпотентны; claim/heartbeat/finish защищены арендой и attempt.
      Просроченный или прерванный платный run не воспроизводится автоматически.
- [x] Отдельный eval-worker того же образа; business ai_task и Cash не используются.
- [x] PASSED требует полного покрытия и успешных метрик; failed, unavailable,
      incomplete и skipped_budget различаются. Отчёт содержит только метаданные.
- [x] API не принимает company, сообщения, пути, модель, цены или повышение лимитов.
      API и worker используют общий fingerprint конфигурации.
- [x] Флаги по умолчанию выключены; реальных ночных платных запусков и прод-изменений нет.

Изменены api/app.py, новые api/eval_models.py и store/evals.py, config.py,
__main__.py, evals/payment_intake_nightly.py и evals/worker.py, opt-in бюджет
существующего runner, SQL 0002, контракт и unit/integration tests.
ARCHITECTURE.md обновлён. Collector внешнего ревью дополнен Python/SQL/schema;
права reviewer не расширялись, корпуса сообщений не передавались.

## Проверки

Baseline существующего business DB flow: 16 PASS. Store: 24 integration PASS;
исполнитель: 35 unit и 9 integration PASS. API со старым flow: 26 PASS;
request/settings: 27 PASS; schema contract: 2 PASS.

До внешнего ревью: make ai-service-lint — Ruff 114/mypy 63 PASS;
make ai-service-test — 547 PASS/1 skip; make ai-service-evals — 9 PASS/1 skip.
Старые skips: site не смонтирован и нет live eval keys.

После внешнего ревью: 7 новых регрессий доказаны RED, затем 15 целевых тестов
PASS. Первый RED harness завис из-за worked=true в старом цикле; собственный
тестовый контейнер остановлен, harness ограничен вторым claim, повтор дал
ожидаемые 7 FAIL. Это не успешная проверка и не прод-обработка.
make test-deploy-workflow: shell-инварианты PASS, collector unittest 1 PASS.
Итоговые make ai-service-lint ai-service-test ai-service-evals: Ruff/format 114
и mypy 63 PASS; **554 PASS/1 skip**, evals **9 PASS/1 skip**. Первый запуск
остановился на RUF043 в новом test regex; после raw string повтор полностью
зелёный. Логи /tmp/payment-intake-stage7-reviewed-module-final.log.

Миграция 0002 проходит на настоящем тестовом PostgreSQL в каждом integration
fixture; старый business flow сохранён. Прод-учётные данные не использовались.

## Ревью

Внутренние IMPORTANT: store 3, executor 4, collector 1 — исправлены до внешнего
ревью. Независимое ревью полного этапа: B0/I0/M1 (ignored suite query), MINOR
исправлен с RED→GREEN; reviewer подтвердил открытых B0/I0/M0.

Внешнее ревью Claude medium: один раунд, B0/I1/M4. Результат после исправлений —
**fixed without re-run**, согласно AGENTS.md §7.2; маркера REVIEW_GREEN не было.

- IMPORTANT: потеря DB connection оставляла daemon на мёртвом соединении.
  OperationalError/InterfaceError теперь выходят наружу при claim, evaluate
  и finish, чтобы supervisor запустил процесс с новым соединением. Шесть
  регрессий RED→GREEN, неизвестные резервы остаются в БД.
- MINOR: malformed YAML теперь даёт безопасный ValueError invalid_eval_prices;
  регрессия RED→GREEN, текст YAML не включается в ошибку API.
- MINOR: collector regression добавлен в существующий make test-deploy-workflow.
- MINOR про горячее изменение prices: предложенная hot reload не применяется.
  Прод использует immutable image, цены/код обновляются перезапуском API и worker
  одного образа. При рассинхроне заданий корректно получается incomplete, а не
  ложный успех. Этот порядок явно добавляется в runbook этапа 8.
- MINOR про disabled worker и restart:always относится к compose этапа 8.
  В его ТЗ закреплён restart:on-failure или запуск только при включённом флаге;
  завершение disabled worker с кодом 0 не должно образовывать restart loop.

Открытых BLOCKER/IMPORTANT после локальных исправлений нет. Данные review:
site/var/payment-intake-review/stage7-external/ (ignored). Reviewer только читал
код, SQL, контракт и тесты, без raw corpus, секретов и production data.

## Ограничения и дальше

Тарифы существующего общего prices.yaml оценочные; provider invoice ими не
гарантируется. Бизнес-тарифы компаний не менялись. Ночных cron пока нет.
Следующий этап 8 начинается автоматически:
Symfony client/read model, расписание, health и инструкция выпуска. SQL 0002
применяется существующим ai-service migrate перед запуском процессов; Doctrine
миграции требуют отдельного ручного dispatch при согласованном выпуске.
