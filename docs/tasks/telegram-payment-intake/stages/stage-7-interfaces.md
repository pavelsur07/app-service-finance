# Stage7 интерфейсы — до реализации

Basefcd5659469e441d20d87cb77666b6d859aefc8e8; Stage6closedGREEN.
Producerstore/evals.py owns:
- EvalClaim dataclass(run_id:UUID,suite:str,run_date:date,trace_id:UUID,attempt:int,configuration_fingerprint:str).
- EvalEnqueue dataclass(run_id:UUID,status:str,duplicate:bool,conflict:bool).
- EvalStore.enqueue(conn,*,run_id,suite,run_date,trace_id,idempotency_key,configuration_fingerprint,now:datetime)->EvalEnqueue.
- claim(conn,*,now:datetime,lease_seconds:int)->EvalClaim|None (stalequeued/expiredrunning terminalincomplete,noretry).
- status(conn,run_id:UUID)->dict|None and latest(conn,suite:str)->dict|None. Returnrun_id/suite/run_date/status/attempt/trace_id/configuration_fingerprint/created_at/updated_at/completed_at/error_code/report.
- heartbeat(conn,claim,*,now,lease_seconds)->bool; finish(conn,claim,*,status:str,report:dict,error_code:str|None,now)->bool fenced/terminalimmutable.
- reserve(conn,claim,*,call_id:UUID,model:str,amount:Decimal,day_limit:Decimal,month_limit:Decimal,max_calls:int,max_model_calls:int,now:datetime)->bool. Falsemeansnoallowance/fence, noHTTPyet. Atomiccallidentity/run/month/day, max164run82model; retryexistingcallidnopaidrepeatallowedbycaller.
- settle(conn,call_id:UUID,*,actual_cost:Decimal,input_tokens:int,output_tokens:int,now:datetime)->bool (idempotent, originalperiods, reportactualknowncostevenifaboveestimate, no silentclamp).
- budget(conn,*,now)->dict(day_spent/day_reserved/month_spent/month_reserved strings).
No network/credentials in store. ExplicitUTCnow supplied, budgetsZoneInfoEurope/Moscow.
Technicalschemafile0002_eval_runs.sql; tests/integration/conftest resetlist updates.

ConsumerrootAPI ownsapi/models.py+api/app.py+contractapi-eval-run/v1/config fields.
Newappkeywordeval_enabled=False preservesexistingcreate_appcalls. API servercomputes
configfingerprint; no requestmodels/prices/businesscompany/budgetfields.

Nightconsumerowns evals/payment_intake_nightly.py,evals/worker.py, andrunnerbudget
optionalfields. EvalLimits dataclass daily30/month900/calls164/modelcalls82/input65536/
output16000/deadline900/lease900. NightlyEvaluator(conn,store,*,routing,adapters,
prices:dict[str,tuple[Decimal,Decimal]],limits,assets_root=None,clock=UTCnow).
.run(claim)->EvalOutcome(status:str,report:dict,error_code:str|None).
Fingerprinthelperinputs: verifiedpackmanifest+runtimecode/prompt/schema/scorer/
taskrouteandprices(excludesecrets). rootAPIandevaluatorusetheSAMEhelper.
Worker(conn,store,evaluator,*,lease_seconds,poll_seconds,clock).run_once()->bool,
.run_forever() gracefulSIGTERM. Separateprocess sameimage, not businessworker.
Prices/fingerprintconstructionhelpernightmodule consumerrootAPIwilluse.
AdaptersdictionaryexistingLlmAdapter names/providers; routeactualmodelsindividual.
Allpaidrequestsreservefirst/settleknown, nofullschema/adapterrequests persisted.
Baselineassetmanifest beforecalls, sharedbudgetacross2models+datasets, noN0PASS.
Stoponprovidererrors optinlegacydefaultunchanged. Noauto-disable/gold/modelchanges.

Ownership: storeagentSQL/store/integrationfixtures+itsintegrationtests ONLY;
nightagentnightmodule/worker/runneroptionalbudgetfields+itsunit/integrationtests ONLY;
rootAPI/config/contracts/wiring,docs. No sharedfilechangeswithoutrelease.
