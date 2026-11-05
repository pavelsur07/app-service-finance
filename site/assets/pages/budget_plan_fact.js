/**
 * «План–факт» бюджета ОПиУ (templates/budget/plan_fact.html.twig), BUDGET-SPEC §6:
 * мультивыбор проектов и ЦФО и сворачивание групп дерева.
 */
/**
 * Пилюли «Проект» / «ЦФО»: «все / ни одной», без выбора «Применить» неактивна; выбраны все —
 * список не передаётся (вся компания). Открыто одно меню; Esc и клик мимо закрывают.
 */
function initPickers(root) {
  const pickers = [...root.querySelectorAll('[data-pf-pick]')];
  const boxes = (picker) => [...picker.querySelectorAll('input[type="checkbox"]')];
  // Вложенный проект выбранного родителя входит в фильтр: отмечен и неактивен (не отправляется).
  const cascade = (picker) => {
    const levels = [];
    for (const box of boxes(picker)) {
      const level = Number(box.dataset.pfLevel);
      while (levels.length > 0 && levels[levels.length - 1] >= level) levels.pop();
      const implied = levels.length > 0;
      if (implied) {
        box.checked = true;
      } else if (box.dataset.pfImplied === '1') {
        box.checked = false;
      }
      box.disabled = implied;
      box.dataset.pfImplied = implied ? '1' : '';
      if (box.checked && !implied) levels.push(level);
    }
  };
  const sync = (picker) => {
    cascade(picker);
    const none = !boxes(picker).some((box) => box.checked);
    picker.querySelector('[data-pf-pick-apply]').disabled = none;
    picker.querySelector('[data-pf-pick-hint]').hidden = !none;
  };
  for (const picker of pickers) {
    sync(picker);
    picker.addEventListener('change', () => sync(picker));
    picker.querySelector('[data-pf-pick-all]').addEventListener('click', () => {
      boxes(picker).forEach((box) => { box.checked = true; });
      sync(picker);
    });
    picker.querySelector('[data-pf-pick-none]').addEventListener('click', () => {
      boxes(picker).forEach((box) => { box.checked = false; box.dataset.pfImplied = ''; });
      sync(picker);
    });
    picker.querySelector('[data-pf-pick-form]').addEventListener('submit', (event) => {
      const list = boxes(picker);
      if (!list.some((box) => box.checked)) {
        event.preventDefault();
        return;
      }
      if (list.every((box) => box.checked)) {
        list.forEach((box) => { box.disabled = true; });
      }
    });
    picker.addEventListener('toggle', () => {
      if (!picker.open) return;
      pickers.filter((other) => other !== picker).forEach((other) => { other.open = false; });
      // Меню не выходит за правый край панели фильтров (пилюля ЦФО на телефоне — справа).
      const menu = picker.querySelector('[data-pf-pick-form]');
      menu.style.left = '';
      const bar = picker.closest('.vf-pf-bar') ?? document.documentElement;
      const overflow = menu.getBoundingClientRect().right - bar.getBoundingClientRect().right;
      if (overflow > 0) menu.style.left = `${-overflow}px`;
    });
  }
  document.addEventListener('click', (event) => {
    pickers.filter((picker) => picker.open && !picker.contains(event.target)).forEach((picker) => { picker.open = false; });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const open = pickers.find((picker) => picker.open);
    if (!open) return;
    open.open = false;
    open.querySelector('summary').focus();
  });
}

/** Статья — `<tbody data-pf-level>`; потомки группы — следующие tbody глубже неё. */
function applyCollapse(table) {
  const collapsedLevels = [];
  for (const body of table.querySelectorAll('tbody[data-pf-level]')) {
    const level = Number(body.dataset.pfLevel);
    while (collapsedLevels.length > 0 && collapsedLevels[collapsedLevels.length - 1] >= level) {
      collapsedLevels.pop();
    }
    body.hidden = collapsedLevels.length > 0;
    const toggle = body.querySelector('[data-pf-toggle]');
    if (toggle && toggle.getAttribute('aria-expanded') === 'false') {
      collapsedLevels.push(level);
    }
  }
}

function initCollapse(root) {
  const table = root.querySelector('.vf-pf__table');
  if (!table) return;
  table.addEventListener('click', (event) => {
    const toggle = event.target instanceof Element ? event.target.closest('[data-pf-toggle]') : null;
    if (!toggle) return;
    const body = toggle.closest('tbody');
    if (!body) return;
    const expanded = toggle.getAttribute('aria-expanded') !== 'false';
    const name = body.querySelector('.vf-pf__title')?.textContent ?? '';
    // В «Строками» у статьи два шеврона (первая строка и мобильная строка «Откл.») — состояние общее.
    for (const button of body.querySelectorAll('[data-pf-toggle]')) {
      button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      button.setAttribute('aria-label', `${expanded ? 'Развернуть' : 'Свернуть'} «${name}»`);
    }
    applyCollapse(table);
  });
}

export function initBudgetPlanFact() {
  const root = document.querySelector('[data-budget-plan-fact]');
  if (!root) return;
  initPickers(root);
  initCollapse(root);
}
