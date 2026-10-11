### Stage 5: Deals — DONE

**Risk:** MEDIUM · **База:** `feat/ds-rollout-base` (#2625)

#### Страницы
`deals/index`, `deals/show` (+ `_tabs/{items,charges,adjustments}`).

#### Что сделано
- Суммы сделки, прямые расходы, маржа, позиции, расходы, корректировки — `vf_amount`.
- Цена и количество в позициях — вправо (DS: числа по правому краю); количество —
  `vf_number(2)`.
- «₽» в заголовки не добавлен: валюта сделки в шаблонах не выводится.

#### Проверки
- `lint:twig templates/deals` ✅; `phpunit tests/Functional/Deals` ✅.
- smoke: список сделок — 200 (локальных сделок нет).
