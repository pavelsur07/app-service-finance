# Stage 2 — кабинет

`/onec/connections` (список, «нет загрузок больше суток»), карточка `/onec/connections/{id}`: организации, выгрузки «частей N из parts_total», предупреждения обработки, ссылки на сырые части. Перевыпуск токена (`ReissueTokenAction`) и отключение (`DisableConnectionAction`) — CSRF, `module.admin.write`, только владелец подключения, аудит `UPDATE`. Скачивание — `/onec/packages/{id}/raw` (владелец) и `/admin/onec/packages/{id}/raw` (ROLE_ADMIN). Пункт сайдбара ведёт на список.

Проверки: OneC + `tests/{Unit,Integration}/Company/Security` — 56 тестов / 328 проверок; PHPStan по каталогам — 0; cs-fixer — 0 после фикса; twigcs и lint:twig — чисто. Тесты Stage 2 написаны до кода, отдельный красный прогон не снимался.
