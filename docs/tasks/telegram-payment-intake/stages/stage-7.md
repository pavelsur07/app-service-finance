# Stage7 — technical quality jobs и бюджет

Risk HIGH-LOCAL. Stagebasefcd5659469e441d20d87cb77666b6d859aefc8e8.
DoD/spec до кода:stage-7-spec.md иstage-7-interfaces.md.
Статус: локальная приёмка готова, externalreviewPENDING; cronещёнет.

## Результат

Отдельный authAPI ai-eval-run/v1, technicalqueue/periodbudget/callledger0002SQL,
новыйeval-worker того жеimage. Businessai_task иCash неиспользуются.
Nightлимиты30/day900/monthMSK,164calls/job82/model; моделирабочегомаршрута
проверяютсяраздельно, datasetsделятобщийbudget. ReserveдоHTTP, knownsettleточный,
unknownнеосвобождается. Claim/heartbeat/finishfenced, expiredrunincompleteбезreplay.
APIнепринимаетcompany/payload/paths/model/price/budgetoverride, canonicaldaykey,
server/workerconfiguration_fingerprintобщий; queued/runningreportnull.

APIклоксегодняMSK/datevalidation, disabledefaultFalse, terminalsafe report,
latestqueryLiteral(payment_intake). Modeloutputs/gold/rawfinance/ПДнвreportнет.
Featureflagsdefaultoff, nocron/prod/paidrequests thisstage. Ночнойbudgetтехнический,
неучётсредствCash инеизменениеcompanyAIpricing. Тарифыпроектногоprices.yaml
сохраняются,оценочныйхарактерисходныхценвmetadata, providerinvoiceнегарантируется.

## Проверки

BaselineoldDBflow16PASS. Store24integrationPASS; night35unit9integrationPASS;
API+oldflow26PASS; models/settings27PASS; schemaконтрактные2PASS.
ПослеMINORqueryfix13API/contractPASS. Итогпослепоследнегоruntimeизменения:
makeai-service-lint Ruff114/mypy63PASS; test547PASS/1skip; eval9PASS/1skip.
Skipsстарые:site mount инетliveevalkeys. ScopedgitdiffcheckPASS.
Migration0002testedвPostgreSQLсначалакаждогоintegrationcase; idempotentmigration
старыйbusinessflowпрошли. No productionDBcredentials inlocalchecks.

Hoststdlibexternalcollectorregression RED(Python-onlydiffпуст)→GREEN:
новыеAIcode/migrations/schemaвходятврецензирование, evalspayloadsнет;
fakeCLIтольковtemporaryGit test, noactualreview/modelcall. Bash-nPASS.
Reviewerpermissionsнеизменены; tasktoolbugfixcommit0d349a486.

## Review

Selfreview storeI3+executorI4 исправлены, всеRED→GREEN; rootcollectorI1также.
FreshindependentwholeStage7B0/I0/M1(querysuiteignored), MINORclosedRED→GREEN,
reviewerподтвердилopenB0/I0/M0. Нетfinancialrule/gold/prompt/modelавтоизменений.
ExternalPENDING: oneClaude-mediumroundovercleanedwholeStage7diff,includingPython,
contract,SQLandtools. Sourcecorpus/env/secretsнепередаются.

## Дальше

Послеexternal/fix/StagecloseавтоматическиStage8:Symfonyreadmodel/client/short
schedulercommands,existingGlitchTipalerts,cadence+separateprodprocess+releasegates.
Новый0002 требуетявногоservice-migration шагапривыпуске; неавтомиграцияпристарте.
RootSSHexceptionдля4старыхbenchmarkзакончено; никакого5горучногоbenchmark.
