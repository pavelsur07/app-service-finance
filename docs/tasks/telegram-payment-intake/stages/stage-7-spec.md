# Stage 7 — технический ночной eval job и бюджет

Risk HIGH-LOCAL. ТЗ до кода; начало после закрытия Stage6. Stagebaseбудетзаписан
вcheckpoint. Owner: реализациясогласована,30RUB/day900/month,источникиобщие.

## Контракт

POST /internal/eval-runs: contract_version=v1, run_idUUID, suite=payment_intake,
run_dateISO,trace_idUUID,idempotency_key; headerIdempotency-Keyравенbody.
Никакихcompany/user/businesspayload/models/prices/budgets/filepaths в request.
APIсамвычисляетconfiguration_fingerprint по deployedcode/prompts/pack/route/prices
без секретов; сохраняет его в job. Ответ202 run_id/status/duplicate/fingerprint.
GET /internal/eval-runs/{uuid} и /internal/eval-runs/latest?suite=payment_intake
отдают технический статус и metadata-only report. ExistingBearertoken.
Одинаковыйsuite/day — одинjob иодинcanonicalkey; новыйkeyдляужепринятой
даты→409. Тотжеключсдругимсодержимым/fingerprint→409; точныйповторключа
сновымrun_idвозвращаетпервоначальныйjob.
Disabledflag→явныйdisabledresponse,ночнойhealthневыдаётdisabledзаPASS.

## Persistedstate

Forward-only0002_eval_runs.sql вai_service: ai_eval_run, ai_eval_budget,
ai_eval_call. Бизнесai_task/result/trace не используются, фиктивныхкомпаний нет.
Толькотехническиеjob/usage/лимиты, никакихплатёжныхсумм/rawinputs/outputs/ПДн.
Операционныйрублёвыйbudget — согласованныйресурсныйлимит, несредстваCash.

Jobstatuses queued/running/passed/failed/unavailable/incomplete/skipped_budget.
claim SKIPLOCKED; attempts/leasefence. Olddatedqueuedjobистекаетбезmodelcalls;
expiredrunningjob→incomplete,никакогонеизвестногоповторенияpaidcases.
Finishimmutable, heartbeat/reserveнеработаютпослепотериlease/attempt.

Beforecall one DBtransaction: стабильныйcalllock, validrunfence, monththenDay
rowslocks, callcount<=164, spent+reserved+newreservation<=30/day и900/month
Europe/Moscow. Сетьпослеcommit. Reservation/settlementидемпотентныпоключуcallID;
точнаяизвестнаяusage заменяетрезерв, неизвестнаядержитполныйрезервсвоегопериода.
Settlementиспользуетпериодызаписанногорезерва, непериодпозднегоответа.
CostDecimal/numeric,никакойfloatарифметики; округлениевверхдо8decimal дляоценки.
Истечениеleaseнеосвобождаетденьги. Следующийсуточныйjobможетидтивпределеquota,
ностарыйнеизвестныйcallникогданеповторяется иегоreservedучитываетсявmonth.

## Executor

Отдельныйeval-worker — процесссуществующегоimage,обычныйworkerнеблокируется.
Общийrunner/Pipeline/scorer; передcalls load_pack+allmanifest validation.
N=0/missing/partialassetsнеPASS. Каждыйconfiguredroute modelтестируетсяотдельно
черезсвойреальныйadapter/gateway/extraparams; никакогоскрытогоfallback внутри
оценкиодноймодели. Текущиймоделей2:deepseek-flashиgpt4.1-mini.
Max82calls/model164/job,единые30/day900/monthдлявсехнаборов/моделей/retries.
Нетключа/прайса/доступа→UNAVAILABLEсобъявленнымнепровереннымохватом, неуспех.

Ценычитаютсяизсуществующегоprices.yaml,парсятсяDecimalизстроковыхзначений;
фиксируютсяhash/оценочныйхарактер. Unknown/nonpositivepricesнезапускаютсеть.
Изменениеотдельногоnightbudgetнеизменяетcompanylimits/businessusage.
Опциональныйsettle_known_usageвLimits/BoundedAdapterсохраняетстарыйконсервативный
режимпоумолчанию; nightlyизвестныйизлишекрезерваосвобождается. Durableadapter
проверяетобщийbudget передкаждымреальнымзапросом. Приproviderfailure ночнойрежим
останавливаетмодель, неповторяетбесконечноfailcases; retriesограниченыexisting
schemaretry иобщимcall/timebudget. Отказвалидногокейса —qualityFAIL, providererror
—UNAVAILABLE, budgetstop —SKIPPED_BUDGET/INCOMPLETEсчестнымпокрытием.

Толькоcompletecoverage+всеразмеченныеметрикисовпали→passed. HIGHneeds_review
неошибка; unknown вместоexpectedpositiveсчитаетсяпромахомgold. Baselines/gold/
prompt/routeавтоматическинеобновляются. Нетавтоотключениявv1.
Reportметрики/denominators/caseIDs,actualcode/prompt/schema/pack/scorer/price/model
fingerprints, latency/tokens/costestimate, counts/reservations; rawoutputsнет.

## Чек-лист приёмки

- [ ] Baseline/Stagebaseзафиксированыдокода.
- [ ] REDcontract/API: auth/disabled/extra businessfields/date/keyconflicts/duplicate.
- [ ] REDstoreintegration: concurrentsamejobclaim, dupereserve/settle, month/day
  boundaryMSK,30/900/callcaps, unknowncost/leaseexpiry/fencing/stalejob.
- [ ] REDexecutor: noassets/N0→noadapter, correctvswronggold,2models/nohidden
  fallback, sharedbudgetacrossdatasets, providercutoff/unknowncost/noinfiniteretry.
- [ ] ВсеRED→GREEN, freshmodulelint/mypy/unit/integration/evals, миграциивtest.
- [ ] Independentinternal/externalreviewпополитике,нетopenBLOCKER/IMPORTANT.
- [ ] ADR/contract/StageReport/checkpoint/commit/push/DraftPR,продолжитьStage8.
- [ ] Никакихproductionwrites/ручногоrootbenchmark/запускаpaidjobдостандартного
  release; старое4passразрешениенепереиспользуется.
