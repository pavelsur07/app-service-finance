# Stage 3 Report: автообработка и пересчёт сверки

Сделано: `ProcessOzonRealizationMessage` → `async_pipeline` (`messenger.yaml`, `debug:messenger` показывает ровно одно новое сообщение и его handler); `ProcessOzonRealizationHandler`:
проверка принадлежности документа компании, пропуск уже обработанной пары (`success`), окончательно закрытый этап «Продажи/возвраты» → `conflict` + `warning` (предварительное закрытие не блокирует: оно пересобирается),
`ProcessOzonRealizationAction` → `success` → пересчёт сверки за месяц (сбой пересчёта на успех не влияет), сбой обработки → `failed` с повтором через час и `error` (при закрытом EntityManager статус пишется напрямую через соединение — найдено тестом).
Загрузчик после `raw_loaded` ставит обработку; тот же отчёт после успеха не обрабатывается заново (хеш строк), изменённый — обрабатывается; сбой постановки → `failed` с повтором, а не «залипшее» `raw_loaded`.
Опрос подхватывает «залипшие» `raw_loaded/processing` (> 1 ч без изменений) и ставит именно обработку.
Проверки: integration handler обработки 6 OK (открытый месяц + сверка, повторная доставка, закрытый этап, предварительное закрытие, сбой с повтором, чужой документ); handler загрузки 12 OK; опрос 9 OK; модуль Marketplace (unit+integration+functional) 1420 OK; PHPStan `src/Marketplace` — 0; cs — 0; `lint:container` — OK.
Internal review: BLOCKER/IMPORTANT нет.
