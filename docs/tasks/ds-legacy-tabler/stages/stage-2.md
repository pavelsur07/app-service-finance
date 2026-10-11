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
