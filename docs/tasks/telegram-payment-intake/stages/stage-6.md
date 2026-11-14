# Stage 6 — общие источники и runtime-эталоны

Risk HIGH-LOCAL. stage_base_commit:950ad65ccaa30d430b0b3a2f3f0826f13efd3cd7.
Definition of Done до кода: generic source boundary сохраняет Telegram identity/
reply/company/actor pin; runtime pack работает без checkout; новые данные проходят
human review и offline validation до любого model call. ТЗ nightly-implementation-plan.md.
Статус: Stage6закрыт,локальныегейтыиобязательныйexternalreviewGREEN.

## Изменения и проверки

Cash RequestPaymentIntakeCommand/Facade принимают opaque sourceKey, Telegram
проверяет bot/chat/message в своём helper с прежними байтами source/reply keys.
Новый источник не требует фиктивных Telegram IDs; entity/schema не менялись.
95tests/520assertionsPASS (включая concurrency/boundary/Telegramfunctional),
focusedCS/strict9filesPASS,focusedPHPStanNoerrors. Базовый набор66/434PASS.

Runtime loader/exporter/manifest отделены от checkout; оригинальные source41/
semantic12 не менялись. Generated snapshot содержит pinned schema/digests,
reviewed index пуст, новые финансовые gold не придуманы. Installedwheel+
detachedpack smokePASS; missing/tamperedassets не вызывают adapter.
Reviewed JSONL поддерживает произвольныеIDs/channel metadata вне modelpayload,
только completehumanreviewed rows, immutableversions. Unknownscaleamountnull
допустим с question/верифицируемым anchor; ambiguous repeatedanchors v1reject.
Инструкция adding-examples.md проверена по реальнымCLI/workingdir.

Итоговые Python modulegates после последнего исправления:461PASS/1skip,
eval9PASS/1skip,Ruff/format102files,mypy59filesPASS. Skips:site mount/нетlivekeys.
Все новые проверки без реальныхmodelcalls/productionwrites. FullPHP/handoff
не повторялся на work item. Глобальныйdiffcheck сownerAGENTS/CLAUDE whitespace
не используется; scopedtaskdiffcheckPASS, ownerchangesнеизменены.

## Внутреннее review

Подтверждены и исправлены3IMPORTANT: directevaluateпроверялassetsпослеadapter;
mixedpendinghumanfileмогвойтицеликомвpack; reviewednullamountошибочноотклонялся.
Все3имеютRED→GREEN. Последний независимыйreview B0/I1/M1found→B0/I0/M0open;
M1guidepath/order исправлен. Эти counts разных точек не число уникальных случаев.
Финансовые правила и strict legacysemantic12 не ослаблены.

## External review

Одинраунд Claude medium: точныймаркер REVIEW_GREEN, B0/I0.
HIGH-LOCALStage6combinedPHP/Pythondiff>500строк. Прогон в чистомdetachedreviewworktree,
чтобыunrelatedowneruntrackedsite/bin/capture-wbнепопалврецензирование.
Обычныйscriptполучаетsite-diff; Pythoncode/testdiff передаётсяконтекстом,
без raw anonymizedcorpus/productiondata. Secrets/envvaluesнепередаются.

## Дальше

После внешнегоreview/fix/checksзакрытьStage6иавтоматическиперейтикStage7:
durabletechnicaljob+reserve/settle30/day900/month+отдельныйeval-worker.
Обе модели текущегоparse_payment_messageмаршрута проверяются отдельно,
соднимобщимденежнымпределом,max82calls/model164/job. Ночнойcronещёнезапущен,
старые4root-evalpassesзакончены; пятыйпоэтомуразрешениюнезапускается.
