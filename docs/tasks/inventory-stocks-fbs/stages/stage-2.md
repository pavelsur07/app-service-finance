### Stage 2: переключатель FBO / FBS в шаблоне — DONE

**Risk:** LOW
**Stage base commit:** `798e29e3b`
**Work items:** 2.1

#### What was done
- В карточке фильтров `/inventory/stocks` между «Источник» и «Остатки на дату» — Tabler `btn-group` с `btn-check`-радио
  `name="scheme"` (FBO | FBS), подпись «FBO — склад маркетплейса, FBS — склад продавца».
- Пагинация сохраняет `scheme` (партиал берёт `app.request.query.all`).

#### Files changed
- `site/templates/inventory/stocks/index.html.twig` — modified
- `site/tests/Functional/Inventory/Controller/StockReportControllerTest.php` — test `testSchemeToggleMarksActiveOptionAndKeepsItInPagination`

#### Definition of Done
- [x] активное положение из `scheme`, GET-форма отправляет его, «Сбросить» возвращает FBO
- [x] ссылки пагинации несут `scheme`

#### Checks
- `phpunit StockReportControllerTest` — OK (26 tests, 104 assertions)
- `lint:twig templates/inventory/stocks` — OK
- ручной smoke на стенде (nginx + php-fpm, puppeteer): оба положения отрисованы, скриншоты `stocks-fbo.png` / `stocks-fbs.png`
  (в workspace задачи, не в репозитории); dev-БД для этого домигрирована

#### Internal review
- iterations: 1; findings: none

#### External review
- required: no (LOW)

#### Risks / reviewer focus
- активное положение у Tabler 1.2 подсвечивается слабо (серый фон) — DS-правок не делалось, вне задачи

#### Next
- continue to Stage 3 automatically
