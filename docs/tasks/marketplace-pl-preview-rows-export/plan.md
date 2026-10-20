# marketplace-pl-preview-rows-export: выгрузка предпросмотра ОПиУ строками для план-факта

Постановка — `docs/plan/TZ_vygruzka_plan_fakt.md` (v1.1, формат 1.0). Класс задачи — Medium:
новая выгрузка поверх готового расчёта, без записи в БД и без новых формул сумм.
Каждый этап начинается с плана в `stages/stage-N.md` из четырёх частей (задача, результат,
что считаем выполненным, чек-лист приёмки); в конце того же файла — итог этапа.
Этапы — локальными коммитами, push и PR — одним шагом на handoff.

## Решения по открытым вопросам ТЗ (§8), принятые по умолчанию

| № | Вопрос | Решение в работе |
|---|---|---|
| 1 | Строки `all` | Не выгружаем. Только каналы + контроль `check_channels_vs_all_*` в «Паспорте» |
| 4 | Подытоги и проценты в «Данных» | Все строки дерева, потребитель фильтрует по `category_type` / `value_format` |
| 5 | Каналы без явного `marketplace` | Каналы с SELLER-подключением у компании (`MarketplaceConnectionRepository`, свой модуль) |
| 2, 3, 6 | Реализация Ozon, СПП, Lamoda | Вне scope выгрузки; видны в «Предупреждениях» / в разбивке по каналам после выгрузки |

Если Владелец ответит иначе — меняется только этап 1 (каналы) или фильтр строк этапа 2.

## Stage 1: Action — отчёты по каналам
Risk: LOW (только чтение, существующий расчёт)

`Marketplace\Application\Action\BuildPlPreviewRowsExportAction`: из фильтра определяет каналы
(§3.2), по каждому вызывает `BuildMarketplacePlPreviewAction`, плюс один вызов с
`marketplace = null` для контроля. Каналы — SELLER-подключения ∪ каналы с данными периода в
общем отчёте. Возвращает `final readonly` DTO
`PlPreviewRowsExport(from, to, list<MarketplacePlPreviewReport> $channels, ?MarketplacePlPreviewReport $all)`;
`all = null`, если канал задан явно. Порядок каналов — по значению enum (детерминизм файла).
Момент выгрузки передаёт контроллер (часы), не Action.

Тесты: happy-path на двух каналах (`PlPreviewFixture`); негатив — `from > to` и период
> 366 дней → `MarketplacePlPreviewPeriodException`; компания без подключений → пустой список
каналов, не ошибка.

## Stage 2: Exporter — листы «Данные» и «Паспорт»
Risk: MEDIUM (контракт формата для внешнего потребителя)

`Marketplace\Infrastructure\Export\PlPreviewRowsXlsxExporter` (OpenSpout, как
`PlPreviewXlsxExporter`). Раскладка «канал × колонка × строка дерева» — отдельным чистым
методом, который отдаёт строки до записи в xlsx: так полноту сетки, порядок и уникальность
ключа проверяет unit-тест без чтения файла. «Паспорт» — пары ключ/значение по §4.2, включая
`rows_count*`, `cost_*_<marketplace>` и `check_channels_vs_all_*`.

Тесты: чтение сгенерированного xlsx — шапка, типы ячеек (числа числом, даты датой, «=…»
текстом), `value` для `PERCENT`/`RATIO` без округления, нулевые строки есть, дублей ключа
нет, `iso_week` пуст для периода не пн–вс, `rows_count` = число строк «Данных».

## Stage 3: Exporter — «Покрытие», «Реклама», «Предупреждения»
Risk: LOW

Плоские листы по §4.3–4.5 из `costCoverage` / `salesCoverage` / `unplaced`,
`adReconciliation`, `warnings`. Пять листов в фиксированном порядке.

Тесты: на той же фикстуре суммы совпадают с листами «Не попадёт в ОПиУ», «Исключено»,
«Сверка» широкой выгрузки (критерий приёмки 6); пустые «Предупреждения» — только шапка.

## Stage 4: Controller и маршрут
Risk: LOW

`Marketplace\Controller\PlPreviewRowsXlsxController`, `GET /marketplace/pl-preview/rows-xlsx`,
`marketplace_pl_preview_rows_xlsx`, `#[IsGranted(ModuleAccess::MARKETPLACE_READ)]`,
`getActiveCompany()` до данных, фильтр — `PlPreviewFilter::fromQuery()` (параметр `empty`
игнорируется). Ошибка периода — flash `danger` + redirect на `marketplace_pl_preview_index` с тем
же фильтром. Файл `pl-rows_<from>_<to>.xlsx`, заголовки — как у широкой выгрузки.

Тесты (functional, по образцу `PlPreviewControllerTest`): 200 и xlsx-заголовки; без
`MARKETPLACE_READ` → 403; данные чужой компании в файл не попадают; ошибка периода → redirect.

## Stage 5: UI — выпадающее меню «Скачать xlsx» с двумя форматами
Risk: LOW

Сейчас в `templates/marketplace/pl_preview/index.html.twig` одна ссылка
`Скачать xlsx` (`data-testid="pl-preview-xlsx"`). Заменить её на кнопку с меню Bootstrap —
тот же механизм, что у фильтра «Теги» на этой странице, без нового JS.

```
[ ⤓ Скачать xlsx ▾ ]
   ┌───────────────────────────────────────┐
   │ Таблица как на экране                 │
   │   статьи × теги, для просмотра        │
   │ Строками для план-факта               │
   │   одна сумма — одна строка, по каналам │
   └───────────────────────────────────────┘
```

Правила (best practice):
- Кнопка — `<button type="button" class="btn btn-outline-secondary dropdown-toggle"
  data-bs-toggle="dropdown" aria-expanded="false">`, иконка `ti-file-spreadsheet`, подпись
  остаётся «Скачать xlsx»: пользователь находит выгрузку там же, где раньше.
- Меню `dropdown-menu dropdown-menu-end` (кнопка в конце строки фильтра — меню не вылезет за
  экран). Пункты — обычные `<a class="dropdown-item" href="…">`, GET-ссылки с тем же
  `base_query`: открываются средней кнопкой, копируются, работают без JS.
- Каждый пункт — название формата + серая подпись `small text-secondary`, *зачем* он нужен,
  а не как устроен внутри. Первым — существующий формат (привычное действие не сдвигается).
- `data-testid`: старый `pl-preview-xlsx` остаётся на пункте широкой выгрузки (на нём держится
  `PlPreviewControllerTest`), новый — `pl-preview-rows-xlsx`; кнопке — `pl-preview-export`.
- Показывается при тех же условиях, что сейчас (`report is not null`); при ошибке периода
  меню не выводится.
- Визуал — по DS «Ваш Финдир» (`CLAUDE.frontend.md`), без новых цветов и стилей; проверка
  локальным скриншотом в светлой и тёмной теме и на узком экране.

Тесты: functional — обе ссылки в меню, `href` несёт текущий фильтр (даты, канал, теги),
маршруты отвечают 200.

## Stage 6: документация и приёмка на данных
Risk: LOW

`ARCHITECTURE.md`, раздел «Marketplace: предпросмотр ОПиУ по тегам» — маршрут, Action, Exporter,
формат 1.0; строка в Changelog. Сверка критерия 7: периоды 01–04.10, 05–11.10, 12–18.10.2026 —
сумма каналов по `total` против присланных `pl-preview_*.xlsx` (выручка, CM1, CM2, CM3, EBITDA);
расхождения объяснить до доклада.

Handoff: `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test`
(рост baseline не допускается), внутреннее ревью, `handoff.md`, push + PR,
вопрос «merge and deploy».
