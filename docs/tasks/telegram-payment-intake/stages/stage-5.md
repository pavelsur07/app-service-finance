# Stage 5 — общая приёмка

**Risk:** HIGH-LOCAL. **Base:** `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`.
ТЗ: [stage-5-spec.md](stage-5-spec.md). Статус: локальная интеграционная приёмка завершена;
Stage5 целиком не закрыт: полный live eval v3 выполнен, но приёмка качества
и готовность пилота ещё не подтверждены; актуальные результаты в конце отчёта.

## Что проверено

Интеграция Python → pull ServiceGateway → Cash draft → Telegram cards/Confirm
покрыта без живых внешних вызовов. Суммы передаются десятичными строками;
права, аналитика и окончательное сохранение проверяются Symfony. Непонятные
масштаб, направление, группировка, факт оплаты и источник средств остаются
явными runtime-вопросами. Повтор не заменяет финансовое решение пользователя.

Матрица отказов покрывает commit→enqueue, queued transport loss, result→ack,
сохранение при недоставленном ответе, частичный пакет, stale result, две
конкурирующие Confirm и Amend/Confirm, отзыв доступа, переключение компании,
удаление Telegram session/binding, повтор correction reply и изменение
черновика во время отправки последней карточки.

## Свежий internal review

Отдельные сессии проверяли полный diff и точечное закрытие замечаний.
Последний независимый reviewer выявил B0/I3: actorless legacy fallback,
повтор reply на другую строку, потеря новой доставки. Все три исправлены;
reviewer повторно проверил исходники и соответствующие regression tests,
открыто B0/I0/M0. Он не запускал повторно тяжёлые gates.

Предыдущий whole-task reviewer B0/I5: correction target, successor publish,
linked-pair edit, source edit ordering и per-clause evidence. Все исправлены
с targeted tests. Числа разных раундов не суммируются как уникальные дефекты.

## Проверки

- `make site-test` — **8942 tests / 50254 assertions PASS**, 11:32.694,
  memory 760.50 MB. Запуск без параллельного PHPStan, до последних четырёх
  PHP regression tests из внешнего handoff review; их доказательства ниже.
- Python после late verifier/runner fixes: `make ai-service-lint/test/evals` —
  ruff/format green, mypy55, **295 passed / 1 skipped**, eval9 passed / 1 skipped.
  Targeted verifier + runner64 passed, default manifest41 / network_calls0.
- CI commit0984d7b62: full static/unit/integration/functional/Python/style/
  empty-DB migrations PASS. Финальный CI после late fixes записывается отдельно.
Уже подтверждены канонический CS и strict types: 0 изменений из 4151 файлов.
Последний canonical CS и strict types: **0/4153**. Все проверки PHP привязаны к текущей копии через vf-payment-intake-php или
make run --rm; результат общего compose exec с другой копией не засчитывается.

Предыдущий full suite обнаружил два старых synthetic-company fixture, которые
не учитывали deletion_requested_at при усиленной общей проверке доступа. Fixtures
исправлены, narrow suite 36/111 green. JSONB mapping переведён на Types::JSONB,
устранив deprecation. Следующий full suite был OOM-killed (exit137), не green.
Повторные heavy gates выполняются последовательно из-за памяти хоста.
Полный локальный PHPStan даже с одним worker/уникальным cache закончился
незавершённым worker: cgroup oom_kill=3, Docker State.OOMKilled=true. Результат
не green и не основание менять baseline. Полный static gate в CI проходит;
локальные focused проверки всех task paths/late fixes фиксируются отдельно.

## Качество модели и ограничения

41 anonymized source example размечен частично: это проверяемые monetary facts,
вопросы и исключения, а не полные эталонные ответы. 24 synthetic verifier cases
проверяют детерминированные ограничения. Семейства list fragments и salary
siblings не делятся между prompt и holdout; source cases в prompt отсутствуют.
Offline verifier green не доказывает recall/точность живой модели.

На момент первоначальной локальной приёмки live eval, p95 и стоимость были PENDING.
Последующее полное измерение v3 и его ограничения записаны ниже.
Порог 95% из плана является предложением для согласованного измерения, а не
уже достигнутым результатом. Production pilot, worker, scheduler и processing
не включались. Флаг по умолчанию 0.

## Эксплуатация и выпуск

[runbook.md](../runbook.md) содержит recovery, dedicated queue worker,
предусловия пилота, миграционный dispatch и откат выключением флага.
Down миграций удаляет новые черновики/session/receipt metadata, поэтому не
служит автоматическим production rollback. Уже сохранённые деньги не удаляются.

Сначала завершаются все локальные проверки, review, commit/push и обновление
единственной Draft PR. Затем отдельно согласуются живой eval и его provider,
model/лимиты; миграции/deploy и production worker не разрешены этим документом.

## Финальный внешний handoff review

Один раунд по полному site diff с дополнительным Python/contracts/runner diff:
`site/bin/external-review.sh 8a1f51ca --effort medium --context
/tmp/payment-intake-handoff-context.md --out
site/var/payment-intake-review/handoff-round1`. Результат **B0/I1/M3**.

IMPORTANT: TRANSFER category не должна передаваться как невалидное направление;
используется действующий CashCategorizationPayloadMapper, без новой финансовой
семантики. MINOR: противоречивые знак/глагол требуют direction=null+question;
NULL actor старой ссылки не стирает actual actor; source recovery пропускает
неподходящую привязку, сохраняя fail-closed guard. Все подтверждённые замечания исправлены и проверены:
- TRANSFER: RED неверное direction/DomainException → GREEN **2 tests / 5 assertions**;
  Action, test и оба обязательных новых entity builder + unit helper PHPStan PASS.
- Telegram actor/recovery: обе регрессии RED → GREEN **2 tests / 16 assertions**;
  focused PHPStan и CS четырёх source/test paths PASS. Catch выставляет
  knownSource=true, закрывая также гонку появления источника после precheck.
- Python sign conflict: три случая RED → GREEN; full Python gates295/1skip.

Последний targeted independent review проверил эти изменения, runner и builders:
**open B0/I0**, один defense-in-depth MINOR о knownSource=true затем исправлен
и проверен родителем по final diff. Безопасные MINOR закрыты. Финальный внешний
результат: **fixed without re-run**, один раунд, не REVIEW_GREEN. Повтор после
IMPORTANT/MINOR не обязателен по AGENTS.md §7.2; BLOCKER в этом раунде не было.

Python дополнительно обнаружил и исправил I1 runner: после ответа с превышением
объявленного usage повторный schema attempt не должен вызывать провайдера.
Регрессия RED2 calls → GREEN1; текущие full Python gates и manifest обновлены.
Выбранный DeepSeek adapter делает один direct HTTP post, SDK retry отсутствует;
существующий Anthropic также явно max_retries=0. Core/adapter код не менялся.

## Локальная приёмка и оставшаяся граница

Stage1–4 закрыты; final source frozen, task-owned files идут в ту же PR2985.
Финальный CI контролируется на текущей голове PR; его результат/ссылка фиксируются
в PR description и итоговом сообщении, без подмены предыдущего SHA текущим.
Локальные proof logs находятся в /tmp/payment-intake-{test-serial,cs-final-complete,
strict-final-complete}.log, external artifact — site/var/payment-intake-review/.

Историческое состояние перед одобрением: Stage5 **PENDING LIVE EVAL**; test credential, provider/model/
лимиты и актуальный тариф требуют явно названного разрешения. Подготовлены
[live-eval.md](../live-eval.md), offline manifest и отдельные ТЗ/чек-лист/отчёт
подготовки. До измерений нет заявления об общей точности, p95 или готовности
пилота. Глобальный flag + существующие AI grants определяют охват; независимого
intake company allowlist пока нет. Узкий пилот без изменения общего AI-доступа
потребует отдельного company gate до включения.

Review metrics (наблюдения раундов, не число уникальных дефектов): independent
internal B0/I12 по трём исходным сессиям (4+5+3), runner self-review B0/I1;
external B1/I15 по пяти раундам Stage2/3/4/4-rerun/handoff. Открытых B/I нет.
Часть внешнего I6 Stage2 отклонена по git evidence, valid fixture часть исправлена.

## Исторический preflight14November — до разрешённого live-запуска

Owner approved the live trial and reuse of existing key, superseding the original
second-test-key prerequisite for this run. Tariff/CBR verified, conservative
reservation261.82RUB<300.64-file source bundle checksum and offline import/default
41case/network_calls0 passed. Live calls0: dev gateway403; available production
console wrapper only runs Symfony allowlist and has no Python eval entrypoint.
[Preflight](../live-eval-preflight-2026-11-14.md) defines the exact command, bundle
and in-place key use. Stage5 remains pending actual execution/measurement; no
application/config change, no grant/network/worker/deploy mutation.

## Разрешённый полный live v3 —14November

Выполнен после ownerapproval, тем же ключом и immutable sourcebd490a8fb:
41сообщение/41вызов,49/49размеченных кандидатов,47/47известных сумм,2/2scale
abstention,1/1correction intent,19/19marked-question presence;5/7directions.
Все41needs_review(HIGH policy),0schema/verifierrejections,0providererrors.
Python pipeline median1569ms,p951922ms,max4477ms; это не Telegramend-to-end.
Campaign122/163,knownusageestimate8.23956098RUB,неinvoice. Full-runfresh
API/worker before/after metadataPASS,owncontainers0; старые несовпаденияbaseline
из-за независимыхmasterdeploy сохранены в[отчёте](../live-eval-result-2026-11-14.md).

Не закрыто:tg011/tg027directionnull (tg027→inflow ожидание§10 не выполнено);
tg008twoitems vsoneannotatedcandidate, одинamountnull — исключение итога не
доказано без raw output. Всего50items,3amountnull,39directionnull. Precision/
аналитика/даты/qualityquestions остаютсяnull; tuneddevelopmentcorpus не unseen
holdout. Живой Telegram→clarify→Confirm, backlog и end-to-end latency отдельно
не выполнены. Нельзя объявлять Stage5/pilotaccepted по денежному recall100%.

Fresh independent acceptance review:B0/I0securitydefects,M1staledocsfixed;
доказательные пробелы выше открыты. Documentation-onlycontinuation не требует
нового externalreview; прежние reviewmetrics/fixed-without-rerun не меняются.
Новых processing/migrations/worker/flag/deploy не выполнялось; PR остаётсяDraft.
