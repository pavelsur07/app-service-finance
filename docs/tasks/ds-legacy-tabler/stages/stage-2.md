### Stage 2: базовые компоненты — DONE

**Risk:** MEDIUM · **Stage base commit:** `a9b632a0` · **Work items:** 2.1 палитра статусов, 2.2 надстройки компонентов, 2.3 красные кнопки, 2.4 мёртвые `.vf-*`

#### What was done
- Именованная палитра Tabler, которой свёрстаны статусы, сведена к семантикам
  DS: `green` → OK, `red` → danger-текст, `yellow`/`orange` → Warn, `blue` →
  Action blue; `--tblr-form-invalid/valid-*` → DS.
- `vf-custom-classes.css` переписан как слой надстроек над Tabler:
  `.text-danger`, `.alert-danger`, `.bg-danger-lt`, `.invalid-feedback`,
  звёздочка `required` → danger-текст (красный бренда остаётся только у
  `btn-danger`); `.status-*` и фокус полей, где Tabler хардкодит свою палитру;
  белый текст на сплошных бейджах.
- Мёртвые классы удалены: 72 `.vf-*`, ни один не используется в шаблонах и JS
  (аудит §6, динамической сборки имён нет).
- Не деструктивные красные кнопки → `btn-primary`: «Перейти к подключениям»
  (`home/dashboard`), «Обновить ключ» (`marketplace/connections`).
- Активный пункт подменю сайдбара: серый фон Tabler → Action blue
  (`--tblr-navbar-active-bg`).

#### Найдено попутно
- **Контраст сплошных бейджей (pre-existing):** у Tabler 1.2 текст бейджа —
  `--tblr-secondary`, поэтому `badge bg-success|danger|secondary` (~25 мест)
  показывали серый текст на цветном фоне, ~1,3:1. Исправлено: белый, ≥ 5,4:1.
- **500 на пустой форме операции (pre-existing, вне scope):**
  POST `/finance/cash-transactions/new` без суммы →
  `CashTransactionDTO::$amount must not be accessed before initialization`
  (`CashTransactionService.php:113`). FOLLOW-UP — отдельная задача.

#### Files changed
- `site/assets/styles/design-tokens.css`, `vf-custom-classes.css`, `legacy-app.css`
- `site/templates/home/dashboard.html.twig`, `marketplace/connections.html.twig`

#### Checks
- `npm run lint` ✅, `npm run build` ✅, `check:ui-kit` 9081 → 9081
- `lint:twig` на 2 шаблонах ✅; `BrokenConnectionVisibilityTest` ✅ (7 тестов)
- smoke, вычисленные стили (образцы в живой странице): `bg-green-lt`/`bg-success-lt`
  `#1d7a30` на `#e4f5e7`; `bg-yellow|orange|warning-lt` `#9a4a00` на `#fff4e5`;
  `bg-red-lt`/`bg-danger-lt`, `.text-danger`, `.text-red`, `.invalid-feedback`
  `#9a001b`; `btn-danger` фон `#b00020`; `badge bg-danger` белый на `#9a001b`;
  `.form-control:focus` граница `#1d4ed8` (после transition).

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none.

#### External review
- required: no (MEDIUM, diff < 500)

#### Next
- Stage 3 автоматически

#### Сверка с листами DS-Controls / DS-Feedback (2026-10-11)
- Кнопки: md 36px · 0 14px, sm 30px · 0 10px · 13px, радиус 4; Secondary
  (`btn-outline-secondary` и кнопка без варианта) — белая, граница Navy 300,
  hover `#eef1f8`; Primary hover Blue 700, Danger hover Red 700; Danger outline
  — текст danger, граница `#f0c2c9`, hover `#fdecee`; фокус — outline 2px.
- Поля: граница Navy 300, плейсхолдер Navy 500, disabled `#f7f8fc`, фокус —
  кольцо 3px 20%, ошибка — граница Red 600; подпись 13/600, подсказка 12/16.
- Бейджи: только светлые пары листа; сплошные `bg-success|danger|secondary|…`
  сведены к ним (решает и прежний `TODO(ds)` про сплошной danger и белый текст);
  нейтральный — Navy 100 / `#46525f`; info и категориальные из DS-Tokens.
- Баннеры: фон, рамка и текст в цвет смысла, 12px 16px, радиус 4.
- Карточки радиус 4 и граница Navy 200; модалки 8 и тень lg; меню — hover
  `#eef1f8`, тень md; крошки 13px; пустое состояние — иконка в круге Blue 50.
- Проверено в браузере вычисленными стилями: высоты 36/30, радиусы 4, цвета
  кнопок, бейджей и баннеров совпадают с листами.
