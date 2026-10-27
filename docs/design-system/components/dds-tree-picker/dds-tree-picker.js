/**
 * DDS tree picker — выбор «Статьи ДДС» с поиском по дереву (до 4 уровней групп + лист).
 * Прогрессивное улучшение: исходный <select> (Symfony ChoiceType) остаётся источником значения
 * и отправляется формой как раньше. Без JS работает обычный select.
 *
 * Разметка (см. dds-tree-picker.html.twig):
 *   <div class="dds-picker" data-dds-picker
 *        data-tree='[{"id":"g1","name":"Выбытие","children":[{"id":"42","name":"Подписки ИИ","flow":"out"}]}]'
 *        data-placeholder="Не распределено"
 *        data-direction-source="input[name='cash_transaction[direction]']"   (необязательно)
 *        data-dir-in-value="inflow" data-dir-out-value="outflow">            (значения поля направления)
 *     <select class="dds-picker__native" name="…">…</select>
 *   </div>
 *
 * Правила:
 *  - выбрать можно ТОЛЬКО лист (узел без children); группа лишь раскрывается/сворачивается;
 *  - id листа в дереве == value option в select;
 *  - flow листа: "in" | "out" | "both"; при выбранном направлении показываем подходящие + "both";
 *  - при смене направления несовместимая статья сбрасывается (если в select есть пустая option) + заметка.
 * События: на select — стандартный `change`; на корне — `dds-picker:reset` (detail: {id,name,reason}).
 * Тексты узлов вставляются через textContent (XSS-безопасно).
 */
const SVG_NS = 'http://www.w3.org/2000/svg';
const FLOW_LABEL = { in: 'Приток', out: 'Отток', both: '' };
const COLLAPSE_KEY = 'dds-picker:collapsed';

function icon(path, size = 14, extraClass = '') {
  const s = document.createElementNS(SVG_NS, 'svg');
  s.setAttribute('width', size); s.setAttribute('height', size); s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2');
  s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
  if (extraClass) s.setAttribute('class', extraClass);
  const p = document.createElementNS(SVG_NS, 'path'); p.setAttribute('d', path); s.appendChild(p);
  return s;
}
function el(tag, attrs = {}, ...kids) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) { if (v === false || v == null) continue; e.setAttribute(k, v === true ? '' : v); }
  kids.flat().forEach((k) => e.append(k));
  return e;
}

export class DdsTreePicker {
  constructor(root) {
    this.root = root;
    this.select = root.querySelector('select');
    this.tree = JSON.parse(root.dataset.tree || '[]');
    this.placeholder = root.dataset.placeholder || 'Не распределено';
    this.dirSelector = root.dataset.directionSource || '';
    this.inValue = root.dataset.dirInValue || 'in';
    this.outValue = root.dataset.dirOutValue || 'out';
    this.uid = 'ddsp-' + Math.random().toString(36).slice(2, 8);
    this.state = { open: false, q: '', cursor: -1, showAll: false, collapsed: new Set(), note: '' };
    this.byId = new Map();
    this.index(this.tree, []);
    this.buildDom();
    this.bind();
    this.syncField();
  }

  /* ---------- данные ---------- */
  index(nodes, trail) {
    nodes.forEach((n) => {
      if (n.children) this.index(n.children, trail.concat(n.name));
      else this.byId.set(String(n.id), { node: n, path: trail.join(' › ') });
    });
  }
  get direction() {
    if (!this.dirSelector) return '';
    const nodes = document.querySelectorAll(this.dirSelector);
    if (!nodes.length) return '';
    let v = '';
    nodes.forEach((n) => { if ((n.type === 'radio' || n.type === 'checkbox') ? n.checked : true) v = v || n.value; });
    if (v === this.inValue) return 'in';
    if (v === this.outValue) return 'out';
    return '';
  }
  get value() { return this.select ? this.select.value : ''; }
  flowOk(n) { const d = this.direction; return this.state.showAll || !d || n.flow === 'both' || n.flow === d; }
  get canClear() { return !!this.select && [...this.select.options].some((o) => o.value === ''); }

  /* ---------- DOM ---------- */
  buildDom() {
    const r = this.root;
    this.valueEl = el('span', { class: 'dds-field__value' });
    this.pathEl = el('span', { class: 'dds-field__path' });
    this.btn = el('button', { type: 'button', class: 'dds-field__main', 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'aria-controls': this.uid + '-pop' },
      this.valueEl, this.pathEl, icon('M6 9l6 6 6-6', 14, 'dds-field__chev'));
    this.clearBtn = el('button', { type: 'button', class: 'dds-field__clear', 'aria-label': 'Очистить статью', hidden: true }, icon('M6 6l12 12M18 6L6 18'));
    this.field = el('div', { class: 'dds-field' }, this.btn, this.clearBtn);
    this.noteEl = el('div', { class: 'dds-field__note', role: 'status', hidden: true });

    this.input = el('input', { type: 'search', class: 'dds-pop__input', placeholder: 'Найти статью', autocomplete: 'off', 'aria-label': 'Поиск статьи по названию', role: 'combobox', 'aria-expanded': 'true', 'aria-controls': this.uid + '-tree', 'aria-autocomplete': 'list' });
    const search = el('div', { class: 'dds-pop__search' }, icon('M21 21l-4.3-4.3M11 18a7 7 0 100-14 7 7 0 000 14z', 16), this.input);
    this.dirNote = el('span');
    this.dirToggle = el('button', { type: 'button', class: 'dds-link' });
    this.expandBtn = el('button', { type: 'button', class: 'dds-link' }, 'Развернуть всё');
    this.collapseBtn = el('button', { type: 'button', class: 'dds-link' }, 'Свернуть всё');
    this.meta = el('div', { class: 'dds-pop__meta' }, el('span', {}, this.dirNote, ' ', this.dirToggle), el('span', {}, this.expandBtn, ' · ', this.collapseBtn));
    this.treeEl = el('div', { class: 'dds-tree', role: 'tree', id: this.uid + '-tree', 'aria-label': 'Статьи ДДС' });
    this.pop = el('div', { class: 'dds-pop', id: this.uid + '-pop', role: 'dialog', 'aria-label': 'Выбор статьи ДДС', hidden: true },
      el('div', { class: 'dds-pop__head' }, search, this.meta), this.treeEl,
      el('div', { class: 'dds-pop__foot' }, el('span', {}, '↑ ↓ — выбрать · Enter — подтвердить'), el('span', {}, 'Esc — закрыть')));
    r.append(this.field, this.noteEl, this.pop);
  }

  /* ---------- события ---------- */
  bind() {
    this.btn.addEventListener('click', () => (this.state.open ? this.close(true) : this.openPop()));
    this.clearBtn.addEventListener('click', () => { this.setValue('', true); this.btn.focus(); });
    this.input.addEventListener('input', () => { this.state.q = this.input.value; this.state.cursor = -1; this.render(); });
    this.input.addEventListener('keydown', (e) => this.onKey(e));
    this.dirToggle.addEventListener('click', () => { this.state.showAll = !this.state.showAll; this.render(); });
    this.expandBtn.addEventListener('click', () => { this.state.collapsed.clear(); this.render(); });
    this.collapseBtn.addEventListener('click', () => { this.collectGroups(this.tree).forEach((id) => this.state.collapsed.add(id)); this.render(); });
    this.onDocDown = (e) => { if (this.state.open && !this.root.contains(e.target)) this.close(false); };
    document.addEventListener('mousedown', this.onDocDown);
    this.pop.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); this.close(true); } });
    if (this.dirSelector) {
      this.onDir = () => this.onDirectionChange();
      document.querySelectorAll(this.dirSelector).forEach((n) => n.addEventListener('change', this.onDir));
    }
    if (this.select) this.select.addEventListener('change', () => this.syncField());
  }
  destroy() {
    document.removeEventListener('mousedown', this.onDocDown);
    if (this.onDir) document.querySelectorAll(this.dirSelector).forEach((n) => n.removeEventListener('change', this.onDir));
  }
  collectGroups(nodes, acc = []) { nodes.forEach((n) => { if (n.children) { acc.push(String(n.id)); this.collectGroups(n.children, acc); } }); return acc; }

  openPop() {
    this.state.open = true; this.state.q = ''; this.state.cursor = -1; this.input.value = '';
    this.pop.hidden = false; this.btn.setAttribute('aria-expanded', 'true');
    this.render();
    this.input.focus();
    const sel = this.treeEl.querySelector('[aria-selected="true"]'); if (sel) sel.scrollIntoView({ block: 'nearest' });
  }
  close(returnFocus) {
    this.state.open = false; this.pop.hidden = true; this.btn.setAttribute('aria-expanded', 'false');
    if (returnFocus) this.btn.focus();
  }
  setValue(id, fire) {
    if (!this.select) return;
    this.select.value = id;
    this.state.note = '';
    this.syncField();
    if (fire) this.select.dispatchEvent(new Event('change', { bubbles: true }));
  }
  syncField() {
    const cur = this.byId.get(String(this.value));
    this.valueEl.textContent = cur ? cur.node.name : this.placeholder;
    this.valueEl.classList.toggle('is-empty', !cur);
    this.pathEl.textContent = cur ? cur.path : '';
    this.clearBtn.hidden = !(cur && this.canClear);
    const note = this.state.note || (!cur ? 'Без статьи операция попадёт в «Не распределено».' : '');
    this.noteEl.textContent = note; this.noteEl.hidden = !note;
    this.noteEl.classList.toggle('is-warn', !!this.state.note);
  }
  onDirectionChange() {
    const cur = this.byId.get(String(this.value));
    const d = this.direction;
    if (cur && d && cur.node.flow !== 'both' && cur.node.flow !== d && this.canClear) {
      this.state.note = `Статья «${cur.node.name}» не подходит для ${d === 'in' ? 'притока' : 'оттока'} и сброшена. Выберите другую.`;
      const keep = this.state.note;
      this.select.value = '';
      this.syncField();
      this.select.dispatchEvent(new Event('change', { bubbles: true }));
      this.state.note = keep; this.syncField();
      this.root.dispatchEvent(new CustomEvent('dds-picker:reset', { detail: { id: String(cur.node.id), name: cur.node.name, reason: 'direction' } }));
    }
    if (this.state.open) this.render();
  }

  /* ---------- построение строк ---------- */
  build(nodes, depth, force, q) {
    const rows = []; let count = 0;
    nodes.forEach((n) => {
      const at = q ? n.name.toLowerCase().indexOf(q) : -1;
      if (n.children) {
        const sub = this.build(n.children, depth + 1, force || at >= 0, q);
        if (!sub.count) return;
        const open = q ? true : !this.state.collapsed.has(String(n.id));
        rows.push({ group: true, node: n, depth, count: sub.count, open });
        if (open) rows.push(...sub.rows);
        count += sub.count;
      } else {
        if (!this.flowOk(n)) return;
        if (q && !force && at < 0) return;
        count += 1; rows.push({ leaf: true, node: n, depth, at });
      }
    });
    return { rows, count };
  }

  render() {
    const q = this.state.q.trim().toLowerCase();
    const { rows } = this.build(this.tree, 0, false, q);
    const d = this.direction;
    this.dirNote.textContent = d ? (this.state.showAll ? 'Показаны все статьи.' : `Статьи для ${d === 'in' ? 'притока' : 'оттока'}.`) : '';
    this.dirToggle.textContent = d ? (this.state.showAll ? 'Только подходящие' : 'Показать все') : '';
    this.dirToggle.hidden = !d;
    const leaves = rows.filter((r) => r.leaf);
    if (this.state.cursor < 0 && q && leaves.length) this.state.cursor = 0;
    if (this.state.cursor >= leaves.length) this.state.cursor = leaves.length - 1;
    this.leaves = leaves;
    this.treeEl.replaceChildren();
    if (!rows.length) {
      this.treeEl.append(el('div', { class: 'dds-pop__empty' }, 'Статья не найдена. Проверьте название' + (d && !this.state.showAll ? ' или покажите все статьи.' : '.')));
      this.input.removeAttribute('aria-activedescendant');
      return;
    }
    const value = String(this.value);
    let li = -1;
    rows.forEach((r) => {
      const n = r.node;
      if (r.group) {
        const g = el('button', { type: 'button', class: 'dds-tree__row dds-tree__group', role: 'treeitem', 'aria-expanded': String(r.open), 'data-d': r.depth, tabindex: '-1' },
          icon('M9 6l6 6-6 6', 14, 'dds-tree__chev'), el('span', { class: 'dds-tree__name' }, n.name), el('span', { class: 'dds-tree__count' }, String(r.count)));
        g.addEventListener('click', () => { const id = String(n.id); if (this.state.collapsed.has(id)) this.state.collapsed.delete(id); else this.state.collapsed.add(id); this.render(); });
        this.treeEl.append(g);
      } else {
        li += 1;
        const isSel = String(n.id) === value;
        const name = el('span', { class: 'dds-tree__name' });
        if (r.at >= 0 && q) { name.append(n.name.slice(0, r.at), el('span', { class: 'dds-tree__hit' }, n.name.slice(r.at, r.at + q.length)), n.name.slice(r.at + q.length)); }
        else name.append(n.name);
        const b = el('button', { type: 'button', class: 'dds-tree__row dds-tree__leaf', role: 'treeitem', id: `${this.uid}-i${li}`, 'aria-selected': String(isSel), 'data-cur': String(li === this.state.cursor), 'data-d': r.depth, tabindex: '-1' },
          name, el('span', { class: 'dds-tree__tag' }, (!d || this.state.showAll) ? FLOW_LABEL[n.flow] || '' : ''), el('span', { class: 'dds-tree__check' }, isSel ? '✓' : ''));
        b.addEventListener('click', () => this.pick(n));
        this.treeEl.append(b);
      }
    });
    if (this.state.cursor >= 0) {
      const cur = this.treeEl.querySelector('[data-cur="true"]');
      if (cur) { this.input.setAttribute('aria-activedescendant', cur.id); cur.scrollIntoView({ block: 'nearest' }); }
    } else this.input.removeAttribute('aria-activedescendant');
  }
  pick(n) { this.setValue(String(n.id), true); this.close(true); }
  onKey(e) {
    const n = this.leaves ? this.leaves.length : 0;
    if (e.key === 'ArrowDown') { e.preventDefault(); this.state.cursor = n ? (this.state.cursor + 1) % n : -1; this.render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); this.state.cursor = n ? (this.state.cursor <= 0 ? n - 1 : this.state.cursor - 1) : -1; this.render(); }
    else if (e.key === 'Enter') { e.preventDefault(); const i = this.state.cursor >= 0 ? this.state.cursor : (this.state.q.trim() ? 0 : -1); if (i >= 0 && this.leaves[i]) this.pick(this.leaves[i].node); }
    else if (e.key === 'Escape') { e.preventDefault(); this.close(true); }
  }
}

export function initDdsTreePickers(root = document) {
  root.querySelectorAll('[data-dds-picker]').forEach((node) => {
    if (node.__ddsPicker) return;
    node.__ddsPicker = new DdsTreePicker(node);
  });
}
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initDdsTreePickers());
  else initDdsTreePickers();
}
