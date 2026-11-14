# Живые проверки после подготовки ответа — 14 ноября 2026

Владелец разрешил до четырёх проходов с исправлениями без повторных вопросов.
Общий оценочный предел 300 рублей, тот же ключ и одноразовая изоляция.
Выполнено 4 из 4; started=4.
Кампания целиком: 375 вызовов, usage estimate 32.74599378 рубля.
Не invoice. Верхний call cap 491 не отменяет ограничение четырёх
проходов и денежную проверку перед каждым запуском. Непонятный расход блокирует повтор.

| Проход | Prompt | Вызовы | Source кандидаты | Source суммы | Source направления | Synthetic exact row | Не needs_review |
|---|---|---|---|---|---|---|---|
| 1 | parse_payment_message/v4 | 53 | 49/49 | 47/47 | 7/7 | 9/13 | 1 |
| 2 | parse_payment_message/v5 | 53 | 49/49 | 47/47 | 7/7 | 13/13 | 0 |
| 3 | parse_payment_message/v5 | 53 | 49/49 | 47/47 | 7/7 | 13/13 | 0 |
| 4 | parse_payment_message/v5 | 53 | 49/49 | 47/47 | 7/7 | 13/13 | 0 |

Первый проход: prompt v4 плюс новый preparer. После его semantic misses
добавлен prompt v5 и derived reference_local_date. Исторические v1–v4, первичные metadata-only отчёты
и bundles сохранены; gold и денежные checks не ослаблены. Поздние проходы
измеряют конкретные версии; усреднение по разным алгоритмам не применяется.

41 исходное сообщение — development corpus с частичными annotations.
12 synthetic cases — отдельные явные выборы аналитики/дат, 13 строк.
Оба набора использованы при настройке; независимый holdout не заявляется.
Source precision, реальная analytics/date accuracy и качество вопросов остаются
неизмеренными. Наличие questions не означает их полноту/релевантность.
Живой Telegram E2E, очереди и production pilot этой изолированной проверкой
не подтверждаются; приложение, миграции, workers и флаг не менялись.

## Проход 1

Source commit `8ed64c7b2a48dc62adeda5fd3f087dd4860fb25b`; bundle
`02efd5e0695705a5ce4f011570661d489cc4f070a653506ddb6e3541a6e22b41`. Report `live-eval-2026-11-14-prepared-output.json`,
SHA256 `1ca417b098fc9e4baadc537c0d662bd92be268b58814fc41858f4872a91a9e51`. Complete=True.

| Синтетическое поле | Совпадение |
|---|---|
| amount | 12/13 |
| currency | 12/13 |
| direction | 12/13 |
| execution_status | 12/13 |
| date | 11/13 |
| category_id | 12/13 |
| project_id | 12/12 |
| cfo_id | 10/12 |

Synthetic recall 12/13, precision 12/12,
exact candidate count 11/12, expected-null 8/9,
pair abstention 0/1, required-question presence 8/9.
Это дроби на явно размеченном синтетическом наборе, а не production accuracy.
Для forbidden-pair случая отдельные project/CFO denominators исключены;
разрешённые abstention tuples оцениваются отдельно без выбора приоритета.

Misses: sem-004: cfo_id_agreement, synthetic_exact_row_tuple; sem-005: cfo_id_agreement, expected_null_abstention, synthetic_exact_row_tuple; sem-007: verified_candidate_recall, exact_candidate_count, amount_agreement, currency_agreement, direction_agreement, execution_status_agreement, date_agreement, category_id_agreement, pair_abstention, required_question_presence, synthetic_exact_row_tuple, payment_intent_agreement, forbidden_pair; sem-009: date_agreement, synthetic_exact_row_tuple.
Source tg008 item_count=1;
source total items=49.
source: median 1511 / p95 1929 / max 4225 мс / semantic: median 1533 / p95 2089 / max 2089 мс. Это Python pipeline, не Telegram E2E.
Usage estimate этого прохода 4.80564709 рубля;
fresh metadata postcheck PASS. Raw inputs/outputs в report отсутствуют.

## Проход 2

Source commit `99cf27cde6b1d0fa7213b008d11506466d70a091`; bundle
`15c72eb45fc1041347d04e1c98b3648c0b1b04c22ac9a6f4550a91084f467614`. Report `live-eval-2026-11-14-pass-2.json`,
SHA256 `908a596e4bd748c8eb41f36c27ae93d87512d9ea42f237025156d6fe423e01aa`. Complete=True.

| Синтетическое поле | Совпадение |
|---|---|
| amount | 13/13 |
| currency | 13/13 |
| direction | 13/13 |
| execution_status | 13/13 |
| date | 13/13 |
| category_id | 13/13 |
| project_id | 12/12 |
| cfo_id | 12/12 |

Synthetic recall 13/13, precision 13/13,
exact candidate count 12/12, expected-null 9/9,
pair abstention 1/1, required-question presence 9/9.
Это дроби на явно размеченном синтетическом наборе, а не production accuracy.
Для forbidden-pair случая отдельные project/CFO denominators исключены;
разрешённые abstention tuples оцениваются отдельно без выбора приоритета.

Misses: нет по измеренным метрикам.
Source tg008 item_count=1;
source total items=49.
source: median 1549 / p95 1931 / max 5652 мс / semantic: median 1570 / p95 1872 / max 1872 мс. Это Python pipeline, не Telegram E2E.
Usage estimate этого прохода 5.33702479 рубля;
fresh metadata postcheck PASS. Raw inputs/outputs в report отсутствуют.

## Проход 3

Source commit `99cf27cde6b1d0fa7213b008d11506466d70a091`; bundle
`15c72eb45fc1041347d04e1c98b3648c0b1b04c22ac9a6f4550a91084f467614`. Report `live-eval-2026-11-14-pass-3.json`,
SHA256 `2b1aecc1c783621a61eacaa2c8e3aa13f186a54cabf013b4a1359efc0dd56b25`. Complete=True.

| Синтетическое поле | Совпадение |
|---|---|
| amount | 13/13 |
| currency | 13/13 |
| direction | 13/13 |
| execution_status | 13/13 |
| date | 13/13 |
| category_id | 13/13 |
| project_id | 12/12 |
| cfo_id | 12/12 |

Synthetic recall 13/13, precision 13/13,
exact candidate count 12/12, expected-null 9/9,
pair abstention 1/1, required-question presence 9/9.
Это дроби на явно размеченном синтетическом наборе, а не production accuracy.
Для forbidden-pair случая отдельные project/CFO denominators исключены;
разрешённые abstention tuples оцениваются отдельно без выбора приоритета.

Misses: нет по измеренным метрикам.
Source tg008 item_count=1;
source total items=49.
source: median 1466 / p95 1958 / max 5538 мс / semantic: median 1474.5 / p95 1692 / max 1692 мс. Это Python pipeline, не Telegram E2E.
Usage estimate этого прохода 5.34402468 рубля;
fresh metadata postcheck PASS. Raw inputs/outputs в report отсутствуют.

## Проход 4

Source commit `99cf27cde6b1d0fa7213b008d11506466d70a091`; bundle
`15c72eb45fc1041347d04e1c98b3648c0b1b04c22ac9a6f4550a91084f467614`. Report `live-eval-2026-11-14-pass-4.json`,
SHA256 `8dd35c698a981f774932861be11473736c3223720b6b3156b2c7fdac7269f0d5`. Complete=True.

| Синтетическое поле | Совпадение |
|---|---|
| amount | 13/13 |
| currency | 13/13 |
| direction | 13/13 |
| execution_status | 13/13 |
| date | 13/13 |
| category_id | 13/13 |
| project_id | 12/12 |
| cfo_id | 12/12 |

Synthetic recall 13/13, precision 13/13,
exact candidate count 12/12, expected-null 9/9,
pair abstention 1/1, required-question presence 9/9.
Это дроби на явно размеченном синтетическом наборе, а не production accuracy.
Для forbidden-pair случая отдельные project/CFO denominators исключены;
разрешённые abstention tuples оцениваются отдельно без выбора приоритета.

Misses: нет по измеренным метрикам.
Source tg008 item_count=1;
source total items=49.
source: median 1433 / p95 1904 / max 5801 мс / semantic: median 1446.5 / p95 1955 / max 1955 мс. Это Python pipeline, не Telegram E2E.
Usage estimate этого прохода 5.33189811 рубля;
fresh metadata postcheck PASS. Raw inputs/outputs в report отсутствуют.
