# Stage5 — первый живой прогон14November

## Выполнение и безопасность

Владелец разрешил существующий ключ и затем отдельно разовый root-SSH запуск
в отдельном одноразовом контейнере. Получен полный результат41cases/45calls;
provider errors0. Сетевые/ролевые настройки, приложение, действующие контейнеры
и worker не менялись. Ключ унаследован только внутри server-side процесса;
локально не копировался/не печатался, DB credentials в контейнер не передавались.
Новый контейнер read-only, user10001, memory256MiB, CPU0.5, tmpfs32MiB, caps dropped.
После завершения удалён; image/start/restart/health API и worker совпадают с before.

Source runtime7b5e7b950; bundleSHA
87123e508601131a881cf0a3291808aa0ae8591215285dca38b99fe46230a1b2.
Execution harnessSHAe37fee14d3c8955e5dc2ccc9626cdf2025d5566f027125d52a0c9a7c2e32d811.
Metadata-only receipts находятся в ignored site/var/payment-intake-review/:
live-eval-2026-11-14.json, summary JSON, approved-root-execution-plan.json,
root-eval-containers-before/after.json. Исходные ответы/trace не сохранялись.

## Измерения первого прогона

-41case,45HTTP generations (включая4schema retries); ошибки провайдера0.
-36needs_review по политике HIGH risk,5rejected:tg009,011,026,027,041.
  needs_review не означает ошибку модели: ручная проверка обязательна всегда.
-63439input/5972output tokens; известный usage даёт консервативную peak-rate
  оценку2.15255083RUB, не подтверждённое списание/счёт провайдера. Резерв143.680608RUB.
-Case pipeline median1455ms,p953319ms,max4508ms. Это не Telegram end-to-end:
  очередь, доставка карточек и пользовательское подтверждение не измерялись.
-Исходный scorer: mention26/49, amount24/47, explicit direction1/7,
  scale abstention2/2, correction intent1/1, marked question presence12/19.

Последние метрики НЕ общая точность/precision. Даты/аналитика/question quality
не имеют полного gold и остаются null. Пропуски/rejected включены в denominator;
например1/7 не доказывает шесть ошибочных направлений сохранённых платежей.
Никакие деньги этим standalone eval не записывались.

## Обнаруженная ошибка измерения

Валидный unresolved candidate допускает amount_evidence=null, amount=null,
numeric text_evidence и вопрос о масштабе. Старый scorer игнорировал такой
кандидат. Регрессия на реальном Pipeline: RED0/1 → GREEN1/1. Fallback принимает
только полностью нормализуемую денежную цитату, не произвольное описание.
Task prompt/verifier/schema/extraction этим не менялись. Старый raw report
остаётся неизменным; corrected score первого полного run не выдумывается,
поскольку исходные ответы не сохранялись.

## Адресная диагностика

Добавлены safe per-case check codes/intent/counts/partial metrics и CLI--case-id
для уникальных известных ID. Raw text/output/evidence/description/questions/trace
не выводятся. Spec написан до кода; RED4+privacy2 и scorer1, GREEN14targeted;
full301pass/1skip, eval9pass/1skip, lint/format/mypy55green.
Independent targeted internal review B0/I0/M0. Полных PHP gates повторно не было:
Cash/Telegram/ServiceGateway и финансовые правила не менялись.

На момент первого отчёта campaign cap82 общий:45 использовано,37 осталось. Далее только
поднабор из тех же41 IDs, max18calls, тот же provider/key и ephemeral isolation.
Ledger проверяется перед запуском; лимит не обнуляется между invocation.
Изменения scorer/diagnostics не выдаются за улучшение модели. После будущего
prompt tuning этот набор станет regression/development, не независимым unseen
holdout; final pilot claim потребует корректной отдельной приёмки.

Stage5 и пилот пока не приняты: нужно разделить schema/verifier/abstention причины
и проверить качество после нужных исправлений. Новых разрешений на deploy нет.

## Итог диагностики и prompt v3

Последующие run не перезаписывают первый отчёт и используют общий ledger82:

| Прогон | Source / prompt | Cases | Calls | Verified mentions | Literal amounts | Explicit direction |
|---|---|---:|---:|---:|---:|---:|
| Адресная диагностика |1aa0f7baf / v1|10|14|11/19|10/18|0/7|
| Формальная схема в prompt |4160b4e08 / v2|10|12|6/19|5/18|6/7|
| Консервативные неизвестные поля, часть1 |bd490a8fb / v3|5|5|14/14|14/14|1/2|
| Контрольные пять, часть2 |bd490a8fb / v3|5|5|5/5|4/4|4/5|

V3 на объединении тех же10case:19/19verified candidates,18/18literal amounts,
5/7explicit direction;2направления оставленыnull, не подставлены неверно.
Один short-scale case остаётся amountnull+question (1/1scale abstention).
Marked-question presence4/4 — только наличие, не качество вопроса. Все10cases
needs_review/0rejected, schema_errors/failed_checks пусты, provider errors0.
Длинный список016 сохранил9/9кандидатов и сумм. Ни одна запись денег не создана.
Это настроенный development subset, не общий95%accuracy и не независимыйholdout.
Даты, аналитика, precision и question quality по-прежнему не имеют полногоgold.

Причины были установлены до исправления: модель не видела полный enum/schema;
общая инструкция unknown=null противоречила строковому description; дата по
прилагательному и paid из другого абзаца не соответствовали occurrence checks;
плюс между суммами конфликтовал с выведенным расходом. V2 дал ухудшение количества
сохранённых кандидатов — оно не скрыто. V3 уточнил инструкции на основе этих
наблюдений; schema, verifier и consumer не ослаблялись. v1/v2 immutable.

Bundlev3SHA202aad82c69189c11dcff036e4f38c07c2a461ba97b049ed3320c6cc6e3d96dd,
66files, sourcebd490a8fbd3ddd1d511046bae7c337e0fb958b34. Раздельные metadata-only
reportv3/v3-controls и summary, plan/harnessSHA сохранены в ignored review dir.
Cumulative81/82calls,1remaining; known token usage estimate4.81949560RUB за все
пять run, не счёт провайдера. Последние5+5calls укладываются в согласованный cap.
Новый полный41case-run требует расширения согласованного числа вызовов.

Local finalv3:303testsPASS/1skip,eval9PASS/1skip,ruff92files/mypy55PASS.
Skipped: service-only mount cannot check Symfony version; live categorize key
not supplied. Два существующих Sentry warnings. First offline-manifest output
path failedFileNotFoundError, relative-path retry41cases/0network_callsPASS.
Fresh focused reviews of scorer/diagnostics/v2/privacy/v3:eachB0/I0/M0; external
handoff review не повторялся после этих Work items (Stage5ещёоткрыт).
CI4160b4e08run43557018107 SUCCESS, full PHP/Python/empty-DB gates; production
schema/migrations/deploySKIPPED. V3localgatesPASS; exact-headCI проверяется отдельно.

## Финальный operational postcheck

Own eval containers0, APIhealthy, workerrunning, image/user/network иrestart0
совпадают с initial receipt. Сравнение всех полей вернулоFAIL: started_at обоих
контейнеров изменился на04:30:11/04:30:23UTC. Это нельзя записать как unchanged.
По времени это совпадает с отдельным master workflow_dispatch43555954635,
commit7f115df9c9208f4e13796c9952c5a9e27276ec82:deploy04:25:54–04:30:36UTC SUCCESS.
Связь с тем деплоем — вывод по времени и метаданным; наши harness не содержат
команд перезапуска действующих контейнеров. Последующий postcheck receipt хранит
реальное расхождение. Root exception не использовалось для ремонта/перезапуска.

Stage5/pilot остаются НЕ ПРИНЯТЫ. Нужны полный повтор41cases на v3, полный gold
для аналитики/дат и end-to-end acceptance перед пилотом. Deploy/миграции/worker/
feature flag этой задачей не выполнялись и не включались.

## Разрешённый полный повтор v3 — фактический результат

Владелец ответил «разрешаю» на подготовленное расширение до82дополнительных
вызовов, общий оценочный лимит300RUB, существующий ключ и то же исключение
root-SSH для одноразового контейнера. Запуск выполнен один раз исходным helper
с ранее зафиксированным SHA; bundle/source/prompt не менялись.

| Проверка | Результат полного v3 |
|---|---:|
| Сообщения / вызовы |41 / 41|
| Размеченные проверенные кандидаты |49/49|
| Размеченные точные суммы с известным масштабом |47/47|
| Размеченные направления |5/7; дваnull|
| Отказ угадывать неоднозначный масштаб |2/2|
| Intent исправления |1/1|
| Наличие вопросов в размеченных случаях |19/19|
| Schema/verifier rejected / ошибки провайдера |0 / 0|
| Вердикт HIGH risk |41needs_review|
| Задержка Python pipeline median / p95 / max |1569 / 1922 / 4477ms|

Модель выдала40payments и1correction intent; всего50строк:3без суммы,
39без направления. Наличие needs_review определяется HIGH risk и не означает,
что каждый ответ полностью корректен. Уtg008две строки при одном размеченном
платеже; одна строка без суммы. Исходный текст также содержит справочный итог,
который не должен стать отдельным платежом. Raw output не сохранялся, поэтому
его evidence и точное назначение второй строки восстановить нельзя. Критерий
«итог не отдельный платёж» не доказан; precision остаётсяnull, а не100%.

Уtg011/tg027 directionnull. Это безопасный пропуск с ручным уточнением, однако
обязательное ожиданиеtg027→inflow не выполнено. 5/7 не достигает предложенного
в плане порога95% для однозначных направлений; порог не меняется молча.
49/49 и47/47 относятся только к размеченным ограничениям. Аналитика, даты,
precision и качество вопросов не получили полногоgold; настроенный корпус не
стал независимымholdout. Живой Telegram→уточнение→Confirm/backlog/end-to-end
latency этим изолированным Python-run не проверялись.

Sourcebd490a8fbd3ddd1d511046bae7c337e0fb958b34, bundle66filesSHA
202aad82c69189c11dcff036e4f38c07c2a461ba97b049ed3320c6cc6e3d96dd.
Full reportSHA2365afad1b9a1ea36cf1dd484e7a62bba646119a637722639fc484861e4b77bc;
report и metadata-only summary лежат в ignored review dir под именами
live-eval-2026-11-14-v3-full.json и live-eval-2026-11-14-v3-full-summary.json.
Tokens109620input/7282output; run known-usage estimate3.42006538RUB,
консервативный резерв130.9089984RUB. Campaign122/163calls,41remaining;
all-runs known-usage estimate8.23956098RUB, не подтверждённое списание/invoice.
Новых автоматических запусков и изменений runtime-кода в этом продолжении нет.

Свежий precheck сравнивался со старым04:30receipt и вернулFALSE: внешний master
run43557409568,commitba3cc0d33a3a2f1d79c64afea21789c683293169 заменил image/start
в04:47UTC. Fresh before-v3-full receipt сохранён до запуска; APIhealthy иworker
running. Использован текущий runtimeimage
sha256:9d621cd8b6809b6d97362231fd0399261bbb904179e458e513ee6f66fc36bc47,
но source внутри контейнера — тот же проверенный immutable bundle. Локальный
bootstrap41/0 и remote bootstrap перед live прошли.

**Полный run postcheck PASS:** fresh before/after metadata API/worker совпали
точно (включаяimage/start/restart/health/user/network); evalcontainers0.
Оба receipt сохранены как root-eval-containers-{before,after}-v3-full.json.
Старое расхождение с исходным кампании baseline не переписано и не скрыто.

Независимый read-only acceptance review:B0/I0 новых подтверждённых дефектов
безопасности; M1устаревшие документы — исправлены этим отчётом и обновлением
Stage5/handoff/spec. Приёмочные пробелы выше остаются открытыми. Внешний review
не повторялся для documentation-only продолжения; прошлый handoff результат
fixed without re-run остаётся в истории. **Stage5/pilot НЕ ЗАКРЫТЫ.**
