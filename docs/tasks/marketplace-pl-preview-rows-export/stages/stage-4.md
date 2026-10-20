# Stage 4: Controller и маршрут

stage_base_commit: `26a747f00`. Risk: HIGH-LOCAL — новый endpoint с данными компании (доступ, IDOR).

## 1. Какую задачу решаем

Дать пользователю скачать файл строками по тому же фильтру, что у экрана предпросмотра, с той же
защитой и тем же поведением при ошибке периода, что у широкой выгрузки (ТЗ §3.1, §5.5).

## 2. Что будет результатом

`Marketplace\Controller\PlPreviewRowsXlsxController` — `GET /marketplace/pl-preview/rows-xlsx`,
`marketplace_pl_preview_rows_xlsx`, `ROLE_USER` + `ModuleAccess::MARKETPLACE_READ`; компания —
`getActiveCompany()` до данных; фильтр — `PlPreviewFilter::fromQuery()` (`empty` не влияет);
ошибка периода — flash `danger` + redirect на вкладку с тем же фильтром; файл
`pl-rows_<from>_<to>.xlsx`, заголовки как у `PlPreviewXlsxController`; `generated_at` — часы
приложения в Europe/Moscow. Маршрут подхватывается атрибутом (каталог контроллеров модуля уже в
`config/routes.yaml`).

Functional-тест `PlPreviewRowsXlsxControllerTest` на `PlPreviewFixture`.

## 3. Что считаем выполненной работой

Маршрут отдаёт файл; тест покрывает доступ, изоляцию компании, ошибку периода и сверку с широкой
выгрузкой (критерии приёмки 1–2 на фикстуре); PHPStan (включая правила контроллеров) и CS чистые.

## 4. Чек-лист приёмки

- [x] Гость → redirect на login.
- [x] Участник без `MARKETPLACE_READ` → 403.
- [x] Владелец → 200, `Content-Type` xlsx, `Content-Disposition` с `pl-rows_2026-01-01_2026-01-31.xlsx`, `Cache-Control: no-store`.
- [x] По каждому каналу значения `tag` / `common` / `total` «Данных» = колонкам широкой выгрузки `/marketplace/pl-preview/xlsx?marketplace=<канал>&empty=1`.
- [x] Другая компания: ни статей, ни затрат, ни тегов этой компании в файле.
- [x] `from > to` → redirect на `/marketplace/pl-preview?…` с flash «Начало периода позже окончания».
- [x] Временный файл удаляется после отправки и при ошибке экспорта.
- [x] PHPStan (`ControllerUnscopedFindRule`, `ControllerEntityArgumentRule`) и CS без замечаний.

## Итог этапа

Сделано: `PlPreviewRowsXlsxController`, `tests/Functional/Marketplace/Controller/PlPreviewRowsXlsxControllerTest.php`
(5 тестов, 27 assertions). Сверка с широкой выгрузкой автоматизирована: для каждого канала файла
значения «статья × колонка» из «Данных» совпадают с листом «ОПиУ по тегам»
`/marketplace/pl-preview/xlsx?marketplace=<канал>&empty=1` (критерии приёмки 1–2 на фикстуре).
`check_channels_vs_all_REV` = 0, `rows_count` = числу строк «Данных».

Удаление временного файла — тот же код, что у `PlPreviewXlsxController` (`deleteFileAfterSend` и
`unlink` в catch); отдельным тестом не проверялось — подтверждено чтением кода.

Проверки: тест OK; PHPStan по контроллеру и тесту (в т. ч. правила контроллеров) — No errors;
php-cs-fixer — 0. Исправлено по ходу: неверный namespace `Module`/`AccessLevel`, коллизия id
пользователя в тесте.

Внутреннее ревью: компания — `getActiveCompany()` до Action; сущность не грузится по id из запроса;
доступ — те же атрибуты, что у широкой выгрузки; `companyId` в файл не пишется. BLOCKER/IMPORTANT нет.
