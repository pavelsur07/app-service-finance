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

Согласованный campaign cap82 общий:45 использовано,37 осталось. Далее только
поднабор из тех же41 IDs, max18calls, тот же provider/key и ephemeral isolation.
Ledger проверяется перед запуском; лимит не обнуляется между invocation.
Изменения scorer/diagnostics не выдаются за улучшение модели. После будущего
prompt tuning этот набор станет regression/development, не независимым unseen
holdout; final pilot claim потребует корректной отдельной приёмки.

Stage5 и пилот пока не приняты: нужно разделить schema/verifier/abstention причины
и проверить качество после нужных исправлений. Новых разрешений на deploy нет.
