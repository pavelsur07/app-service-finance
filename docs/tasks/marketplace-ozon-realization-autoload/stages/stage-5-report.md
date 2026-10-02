# Stage 5 Report: cron, гейт конца окна, документация

Сделано: гейт `app:marketplace:ozon-realization-check` (`--company-id`, `--report-only`; активен с 9-го по 16-е число; красное — нет отчёта, `auth_failed`, сбой; `conflict` — warning; применённой считается пара в `success` или любые строки `marketplace_ozon_realizations` за месяц),
`OzonRealizationAppliedQuery`; cron: `17 * * * *` опрос и `20 7 * * *` гейт вместо `0 6 5 * *`; `ARCHITECTURE.md`, `health-gates.md`, `prod-access.md`.
Проверки: integration гейта 5 OK (вне периода молчит, красный с одним агрегированным error, зелёный при `success`/ручном применении, `conflict` = warning, `--report-only`, `--company-id`); PHPStan `src/Marketplace/Ozon` — 0; cs — 0.
Internal review: BLOCKER/IMPORTANT нет.

## Правки по ревью в свежей сессии (до handoff)
- BLOCKER: `ProcessOzonRealizationAction` сбрасывает EntityManager каждые 250 строк, и статус пары после этого отсоединён: `persist()` вставлял дубликат пары, обработка отчёта >250 строк падала (у крупного кабинета ~14 тыс. строк). Статус перечитывается; регрессионный тест на 600 строк красный на старом коде (UniqueConstraintViolation), зелёный на новом.
- IMPORTANT: гейт не краснеет на неисправимое — `error` только 9-го числа, с 10-го по 16-е `warning`; удачно получивший успех статус не разжалуется неудачной перепроверкой (ручная загрузка, пустой ответ, сбой Ozon); загрузка вне окна опроса (ручная) возвращает сбой в Messenger для повтора.
- MINOR: успех не ставится, если за время обработки пришла новая версия отчёта (хеш); терминальный сбой после 4 попыток и при чужом/пропавшем документе; потолок Retry-After 1 час; отчёт без sku считается загруженным; `closesAt` убран как неиспользуемый; в лог идёт усечённое сообщение.
