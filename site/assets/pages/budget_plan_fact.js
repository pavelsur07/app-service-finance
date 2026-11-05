/**
 * «План–факт» бюджета ОПиУ (templates/budget/plan_fact.html.twig), BUDGET-SPEC §6:
 * выбор пары «проект × ЦФО» одним списком и сворачивание групп дерева.
 */
function initPairSelect(root) {
  root.querySelector('[data-pf-pair]')?.addEventListener('change', (event) => {
    const [project = '', center = ''] = event.target.value.split('|');
    const form = event.target.form;
    form.elements.project.value = project;
    form.elements.center.value = center;
    form.submit();
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
  initPairSelect(root);
  initCollapse(root);
}
