### Stage 3: деньги и даты — DONE

**Risk:** MEDIUM · **Stage base commit:** `6995bfa6` · **Work items:** 3.1 фильтр, 3.2 компонент суммы, 3.3 пресеты

#### What was done
- `src/Twig/VfMoneyExtension.php` (не legacy-зона) — единственный источник
  форматирования денег для легаси-шаблонов:
  - `|vf_money(decimals = 2, currency = null)` — `1 234 567,89`, тысячи U+00A0,
    запятая, минус U+2212; без знака валюты (₽ — в заголовке колонки);
  - `|vf_money_short(currency = '₽')` — KPI: `6,48 млн ₽`, `15,25 тыс. ₽`;
  - `|vf_amount(decimals = 2, tone = null)` — HTML для ячейки: копейки
    приглушены (`.vf-amount__minor`), `tone: 'income'` — зелёный, `'profit'` —
    danger при отрицательном значении, без tone — обычный цвет.
  - Арифметика — bcmath по строке, без float-потерь на крупных суммах;
    округление половины от нуля; `−0,00` не выводится.
- CSS `.vf-amount*` в `vf-custom-classes.css`: tabular-nums, nowrap, цвета из токенов.
- `partials/_period_presets.html.twig` — набор DS: Вчера, Эта неделя, Прошлая
  неделя, Этот месяц, Прошлый месяц, Квартал. Даты считает сервер, клик —
  делегированный обработчик в `legacy-app.js`. Подключён в фильтрах операций ДДС
  вместо инлайн-скрипта.
- Попутно ушли два бага старого инлайн-JS пресетов: `toISOString()` давал дату
  по UTC (00:00–03:00 МСК — вчерашнюю), а в воскресенье «Текущая неделя»
  начиналась со следующего понедельника.

Замена `number_format` по шаблонам — Stage 5 (пилот), затем раскатка по
модулям: 166 вхождений и 7 функциональных тестов, завязанных на текущий формат
(аудит §3), меняются вместе со своими страницами.

#### Files changed
- `site/src/Twig/VfMoneyExtension.php`, `site/tests/Unit/Twig/VfMoneyExtensionTest.php`, `site/templates/partials/_period_presets.html.twig` — new
- `site/assets/styles/vf-custom-classes.css`, `site/assets/legacy-app.js`, `site/templates/transaction/{_filters,index}.html.twig`, `docs/plan/ds-legacy-tabler-audit.md` — modified

#### Checks
- `phpunit tests/Unit/Twig` ✅ 19 тестов; PHPStan на новых файлах ✅ 0 ошибок;
  `php-cs-fixer --dry-run` ✅; `lint:twig` ✅
- `npm run lint` ✅, `npm run build` ✅
- smoke пресетов в Chromium (воскресенье 11.10.2026, МСК): Вчера 10.10–10.10;
  Эта неделя 05.10–11.10; Прошлая неделя 28.09–04.10; Этот месяц 01.10–11.10;
  Прошлый месяц 01.09–30.09; Квартал 01.10–11.10.
- Регрессионного автотеста на старый JS нет: тест-раннера на фронте нет;
  доказательство — smoke выше на граничный день (воскресенье).

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none. PHPStan нашёл `string|null` в
  `htmlspecialchars` — исправлено.

#### External review
- required: no (MEDIUM; финансовые формулы и знаки не меняются, только вывод)

#### Next
- Stage 4 автоматически

#### Сверка с листом DS-Money (2026-10-11)
- `vf_amount`: тон `profit` (красный для отрицательной прибыли) удалён — лист:
  «Красный не используем: он зарезервирован под ошибки»; ноль приглушён;
  `signed: true` — плюс у положительных (лист: «Плюс — только у приходов в
  списке операций»); `currency` — рубль не выводится, остальные символом после
  числа приглушённо (`$`, `€`, `£`, иначе код: «5 000,00 CNY»).
- `vf_money_short`: один знак после запятой («196,1 тыс. ₽», «1,2 млн ₽»).
- Таблицы — всегда два знака копеек (лист), а не «целые рубли» из поручения.
- Тесты: 21 unit-тест ✅, PHPStan и cs-fixer на изменённых файлах ✅.
