/* Эталонная логика сетки «Бюджет ОПиУ» — чистые функции без фреймворка и DOM.
   Перенести в React-остров (assets/react/...) как есть и покрыть unit-тестами (Vitest/Jest).
   Деньги в UI форматируются так же, как vf_money/vf_amount (DS-Money). Здесь — только вспомогательная копия для сетки. */
export const NB = ' ';
export const MINUS = '−';

/** «600000», «600 000», «600000,50», «−5 000» → число; пусто → 0; мусор → NaN. */
export function parseAmount(text) {
  const t = String(text).replace(/[\s ]/g, '').replace(/−/g, '-').replace(',', '.');
  if (t === '') return 0;
  return /^[-+]?\d+(\.\d{1,2})?$/.test(t) ? Number(t) : NaN;
}

/** ТЗ §0 «Знак суммы»: EXPENSE — вводят положительное, хранится со знаком минус; INCOME — как есть; NONE — как ввёл. */
export function toStored(flow, value) {
  return flow === 'EXPENSE' ? -Math.abs(value) : value;
}

/** Значение для поля редактирования: для расхода — без минуса, запятая как разделитель, ноль — пусто. */
export function toEditText(flow, stored) {
  if (!stored) return '';
  const v = flow === 'EXPENSE' ? Math.abs(stored) : stored;
  return String(v).replace('.', ',');
}

const grp = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, NB);
/** Сетка: целые рубли, без копеек; настоящий минус U+2212; «−0» не бывает. */
export function formatCell(v) {
  const r = Math.round(Math.abs(v));
  return v < 0 && r !== 0 ? MINUS + grp(r) : grp(r);
}
export function formatPercent(x) {
  return x == null ? '—' : (x < 0 ? MINUS : '') + Math.abs(x).toFixed(1).replace('.', ',') + NB + '%';
}
export function plural(n, one, few, many) {
  const k = n % 100; if (k > 10 && k < 20) return many;
  const j = n % 10; return j === 1 ? one : j > 1 && j < 5 ? few : many;
}

const zero12 = () => new Array(12).fill(0);
const add12 = (a, b) => a.map((v, i) => v + b[i]);

/**
 * Пересчёт дерева ОПиУ по 12 месяцам. Узел: {id, type: 'leaf'|'group'|'calc'|'kpi', kids?, sum?: id[], num?, den?}.
 * ВАЖНО: правила итогов и вес статьи в родителе — по отчёту ОПиУ проекта (SUBTOTAL с учётом веса). Здесь вес = +1,
 * потому что расходы хранятся со знаком минус. Если в проекте есть веса ≠ 1 — подставить их в reduce.
 */
export function computeTree(tree, getLeaf) {
  const V = {};
  const go = (n) => {
    if (n.type === 'leaf') V[n.id] = getLeaf(n.id);
    else if (n.type === 'group') V[n.id] = n.kids.reduce((a, k) => { go(k); return add12(a, V[k.id]); }, zero12());
    else if (n.type === 'calc') V[n.id] = n.sum.reduce((a, id) => add12(a, V[id]), zero12());
    else V[n.id] = V[n.num].map((v, i) => (V[n.den][i] ? (v / V[n.den][i]) * 100 : null));
  };
  tree.forEach(go);
  return V;
}

/** «Итого» для KPI — отношение сумм за период, не сумма процентов. */
export function totalOf(V, n) {
  if (n.type === 'kpi') {
    const a = V[n.num].reduce((x, y) => x + y, 0), b = V[n.den].reduce((x, y) => x + y, 0);
    return b ? (a / b) * 100 : null;
  }
  return V[n.id].reduce((x, y) => x + y, 0);
}

/**
 * Вставка из Excel (ТЗ §3.5): text — буфер обмена, start — {rowIndex, month} в списке ВИДИМЫХ строк.
 * Возвращает {cells: [{id, month, value}], skipped}. Нередактируемые ячейки и нечисловые значения пропускаются.
 * Пустая ячейка из Excel пропускается и НЕ очищает значение.
 */
export function planPaste(text, start, visibleNodes) {
  const lines = text.replace(/\r/g, '').split('\n');
  while (lines.length && lines[lines.length - 1] === '') lines.pop();
  const cells = []; let skipped = 0;
  lines.forEach((ln, i) => {
    const node = visibleNodes[start.rowIndex + i]; if (!node) return;
    ln.split('\t').forEach((cell, j) => {
      const m = start.month + j; if (m > 11) return;
      if (node.type !== 'leaf') { skipped++; return; }
      const n = parseAmount(cell);
      if (isNaN(n) || cell.trim() === '') { skipped++; return; }
      cells.push({ id: node.id, month: m, value: toStored(node.flow, n) });
    });
  });
  return { cells, skipped };
}

/** Отклонение план–факт (ТЗ §5): факт − план в знаке отчёта; % = факт/план×100 (пусто при плане 0).
 *  Оценка: dev > 0 — «лучше плана» (для расходов это «меньше по модулю», т.к. они со знаком минус), dev < 0 — «хуже».
 *  Подсветка строки: |dev| / |plan| > 0.10. Будущие месяцы (факта нет) — отклонение не считается. */
export function deviation(plan, fact) {
  if (fact == null) return { dev: null, pct: null, better: null, highlight: false };
  const dev = fact - plan;
  return { dev, pct: plan ? (fact / plan) * 100 : null, better: dev === 0 ? null : dev > 0, highlight: plan ? Math.abs(dev) / Math.abs(plan) > 0.1 : false };
}

/** Помощник «Распределить годовую сумму»: поровну на 12 месяцев, остаток копеек — в декабрь. */
export function distributeYear(flow, annual) {
  const a = Math.abs(annual), per = Math.floor((a * 100) / 12) / 100;
  const last = Math.round((a - per * 11) * 100) / 100;
  return Array.from({ length: 12 }, (_, m) => toStored(flow, m === 11 ? last : per));
}
