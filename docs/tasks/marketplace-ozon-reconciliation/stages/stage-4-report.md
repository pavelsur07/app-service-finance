# Stage 4 Report: backend страницы и drill-down

Сделано: `ReconciliationMonth`, `OzonReconciliationViewFactory` + View-DTO, `OzonLedgerOperationsQuery` (QueryBuilder для Pagerfanta),
3 контроллера (`index`, `run` POST, `operations`), рабочие шаблоны (оформление и вкладка — Stage 5).
Проверки: unit (фабрика вида, месяц) +12; integration drill-down (сумма записей сходится с итогами, легаси-затраты вне, UNRECOGNIZED = неизвестные + без категории, чужая компания пусто);
functional 9: гость → логин, пустое состояние, свой снимок виден, чужой не виден (ни в списке месяцев, ни по прямой ссылке), POST без CSRF → 403,
POST с CSRF создаёт снимок и редиректит, невалидный месяц без снимка, пагинация drill-down по компании, матрица 422 (limit 0/201, page 0/99, kind/block/category/month, массивы).
Модуль целиком: `tests/Unit/Marketplace` + `Integration/Marketplace/{Ozon,Repository}` + `Functional/Marketplace` — OK 1093; PHPStan `src/Marketplace/Ozon` — 0; cs:check — 0.
Internal review: BLOCKER/IMPORTANT нет. IDOR: компания берётся из `ActiveCompanyService` до обращения к данным, все Query с `companyId`; drill-down принимает только enum/regex-значения.
Замечание: пересчёт по кнопке выполняется синхронно в запросе — на крупном кабинете это секунды (jsonb по ~30 документам). FOLLOW-UP: замерить на проде после деплоя; при необходимости вынести в Messenger (HIGH-LOCAL).
