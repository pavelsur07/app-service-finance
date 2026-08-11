## Stage 5: Меню и дашборд — DONE

**Риск:** 🟢 LOW (шаблоны, без изменения данных и контрактов)
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** no
**Следующее действие:** Final Release Gate

### Scope Stage

- Stage base commit: `a1f13db4`
- Work items completed: `5.1`, `5.2`, `5.3`

### Что сделано

**5.1 — сайдбары.** До этого этапа участник с ограниченным шаблоном видел полное меню и получал
403 по клику: гейты работали, а навигация врала.

| Файл | Что закрыто |
|---|---|
| `_sidebar_marketplace.html.twig` | весь раздел под `module.marketplace.read` |
| `_sidebar_report.html.twig` | «Отчёты» — finance, «Загрузка данных» (ingestion) — marketplace |
| `_sidebar.html.twig` | «Деньги», «Доходы и расходы», «Импорт», «Отладка» — finance; «Компания и доступы» — admin |

Два блока смешанные и гейтятся на двух уровнях: «Справочники» видны при `finance` **или**
`catalog`, а пункт «Каталог / Товары» отдельно под `catalog`; «Интеграции» видны при любом из
`admin`/`finance`/`marketplace`, а Telegram, банки и маркетплейс — каждый под своим модулем.

«Главная» намеренно не гейтится: за ней `HomeRedirectController`, он exempt и сам уводит на
доступный модуль. `app/_shell/sidebar.html.twig` не тронут — там единственный пункт ведёт туда же.
Админка использует собственный `admin/partials/_sidebar.html.twig` и модульными гейтами не затронута.

**5.2 — дашборд.** Гейтинг `/api/dashboard/v1/snapshot` по `module.finance.read` сделан ещё
в Stage 2 (F2 внешней ревизии Stage 1), отдельная работа не потребовалась.

**5.3 — тесты.** `SidebarModuleVisibilityTest`: finance-участник не видит marketplace-разделов,
marketplace-участник не видит финансовых, пункт каталога появляется только с `catalog:read`,
владелец видит всё. Проверки ограничены контейнером `#sidebar-menu` — слово «Маркетплейсы»
встречается и в заголовке страницы, поиск по всему HTML давал бы ложный green.

### Затронутые файлы

- `site/templates/partials/_sidebar.html.twig` — modified
- `site/templates/partials/_sidebar_marketplace.html.twig` — modified
- `site/templates/partials/_sidebar_report.html.twig` — modified
- `site/tests/Functional/Company/SidebarModuleVisibilityTest.php` — new
- `ARCHITECTURE.md` — modified (версия 1.79, устройство гейтинга меню)

### Self-review

- [x] Scope compliance — только шаблоны, тест и документ
- [x] Forbidden actions — `dump()/dd()` отсутствуют
- [x] Разметка — теги сбалансированы (8 `<li>`, 19 `<div>`), `lint:twig` зелёный на 229 шаблонах
- [x] Админка не затронута — у неё свой сайдбар
- [x] ARCHITECTURE.md обновлён

### Проверки

- `make site-test-unit` — OK (1874 tests, 10765 assertions)
- `composer test:functional` — OK (493 tests, 2898 assertions)
- `composer test:integration` — OK (967 tests, 4494 assertions)
- `bin/console lint:twig templates` — OK (229 файлов)
- Регрессия доказана красным: со снятым гейтом marketplace-сайдбара
  `testFinanceOnlyMemberDoesNotSeeMarketplaceSections` падает

### Внешнее ревью

- Reviewer: Codex CLI 0.147.0
- Итераций: 2
- Результат: **REVIEW_GREEN**
- Подтверждённые находки исправлены:
  - IMPORTANT: «Отладка» с финансовыми raw-отчётами лежала внутри «Компании и доступов» и потому
    требовала `admin && finance`. Участник с одним `finance:read` имел право на эти страницы,
    но не видел навигацию — меню продолжало врать, только в другую сторону. Блок вынесен отдельным
    пунктом верхнего уровня под `module.finance.read`, разметка переделана из вложенного `dropend`
    в полноценный `<li class="nav-item dropdown">`.
  - MINOR: проверки шли по всему HTML, поэтому «Маркетплейсы» могли совпасть с заголовком страницы.
    Assertions ограничены `#sidebar-menu`, добавлена проверка реальной ссылки
    `a[href="/catalog/products"]` и кейсы видимости «Отладки».
- Отклонённых находок: нет
- Ограничения ревьюера: песочница Codex не запускалась (`bwrap: loopback`), дифф и результаты
  прогонов передавались в промпте через stdin.

### Риски / на что обратить внимание ревьюеру

- Меню теперь зависит от шаблона доступа: у владельца и участника с «Полным доступом» оно
  прежнее, у ограниченного шаблона — короче. Это и есть цель этапа.
- Пункт «Отладка» переехал из «Компании и доступов» на верхний уровень. Видимое изменение
  навигации, осознанное.

### Открытые вопросы

нет
