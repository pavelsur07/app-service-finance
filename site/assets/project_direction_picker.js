function qs(root, sel) { return root.querySelector(sel); }
function qsa(root, sel) { return Array.from(root.querySelectorAll(sel)); }

function getBootstrap() {
    // Tabler обычно включает Bootstrap bundle; если нет — graceful fallback
    return window.bootstrap || null;
}

function initOnePicker(picker) {
    if (picker.dataset.pdInited === '1') return;
    picker.dataset.pdInited = '1';

    const fieldId = picker.getAttribute('data-pd-field-id');
    const select = qs(picker, `#${CSS.escape(fieldId)}`);
    const display = qs(picker, '[data-pd-display]');
    const clearBtn = qs(picker, '[data-pd-clear]');
    const modal = picker.querySelector('.modal');
    const selectedLabelEl = qs(modal, '[data-pd-selected-label]');
    const search = qs(modal, '[data-pd-search]');
    const expandAllBtn = qs(modal, '[data-pd-expand-all]');
    const collapseAllBtn = qs(modal, '[data-pd-collapse-all]');

    if (!select || !display || !modal) return;

    const bs = getBootstrap();

    function setValue(id, label) {
        select.value = id || '';
        select.dispatchEvent(new Event('change', { bubbles: true }));

        display.value = label || '';
        if (selectedLabelEl) selectedLabelEl.textContent = label || '—';

        if (clearBtn) clearBtn.disabled = !id;
    }

    // sync from select -> display (important for existing code that sets select.value)
    function syncFromSelect() {
        const opt = select.options[select.selectedIndex];
        const label = opt ? (opt.textContent || '').trim() : '';
        // label в select может быть с отступами, поэтому берём без них, если есть data-label в дереве — но тут ок
        display.value = label.replace(/^[\u00A0—\s]+/, '');
        if (selectedLabelEl) selectedLabelEl.textContent = display.value || '—';
        if (clearBtn) clearBtn.disabled = !select.value;
        highlightSelected(select.value);
    }

    // highlight in tree
    function highlightSelected(id) {
        qsa(modal, '[data-pd-select]').forEach((btn) => {
            btn.classList.remove('pd-selected');
            btn.closest('.pd-row')?.classList.remove('pd-row-selected');
        });

        if (!id) return;

        const btn = modal.querySelector(`[data-pd-select][data-id="${CSS.escape(id)}"]`);
        if (btn) {
            btn.classList.add('pd-selected');
            btn.closest('.pd-row')?.classList.add('pd-row-selected');

            // ensure parents expanded
            let parent = btn.closest('.pd-node');
            while (parent) {
                const children = parent.querySelector(':scope > .pd-children');
                if (children) {
                    if (bs && bs.Collapse) {
                        const inst = bs.Collapse.getOrCreateInstance(children, { toggle: false });
                        inst.show();
                    } else {
                        children.classList.add('show');
                    }
                    const toggle = parent.querySelector(':scope .pd-toggle i');
                    if (toggle) toggle.className = 'ti ti-chevron-down';
                }
                parent = parent.parentElement?.closest('.pd-node');
            }
        }
    }

    // toggle children
    qsa(modal, '[data-pd-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetSel = btn.getAttribute('data-target');
            const target = targetSel ? modal.querySelector(targetSel) : null;
            if (!target) return;

            const icon = btn.querySelector('i');

            if (bs && bs.Collapse) {
                const inst = bs.Collapse.getOrCreateInstance(target, { toggle: false });
                const isShown = target.classList.contains('show');
                if (isShown) inst.hide(); else inst.show();
                if (icon) icon.className = isShown ? 'ti ti-chevron-right' : 'ti ti-chevron-down';
            } else {
                const isShown = target.classList.contains('show');
                target.classList.toggle('show', !isShown);
                if (icon) icon.className = isShown ? 'ti ti-chevron-right' : 'ti ti-chevron-down';
            }
        });
    });

    // select node (only leaves)
    qsa(modal, '[data-pd-select]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const disabled = btn.getAttribute('data-disabled') === '1';
            if (disabled) return;

            const id = btn.getAttribute('data-id');
            const label = btn.getAttribute('data-label') || btn.textContent.trim();
            setValue(id, label);

            if (bs && bs.Modal) {
                const inst = bs.Modal.getOrCreateInstance(modal);
                inst.hide();
            } else {
                modal.classList.remove('show');
            }
        });
    });

    // clear
    if (clearBtn) {
        clearBtn.addEventListener('click', () => setValue('', ''));
    }

    // expand/collapse all
    function showOne(el) {
        if (bs && bs.Collapse) {
            const inst = bs.Collapse.getOrCreateInstance(el, { toggle: false });
            inst.show();
        } else {
            el.classList.add('show');
        }
    }
    function hideOne(el) {
        if (bs && bs.Collapse) {
            const inst = bs.Collapse.getOrCreateInstance(el, { toggle: false });
            inst.hide();
        } else {
            el.classList.remove('show');
        }
    }

    if (expandAllBtn) {
        expandAllBtn.addEventListener('click', () => {
            qsa(modal, '.pd-children').forEach(showOne);
            qsa(modal, '.pd-toggle i').forEach((i) => { i.className = 'ti ti-chevron-down'; });
        });
    }
    if (collapseAllBtn) {
        collapseAllBtn.addEventListener('click', () => {
            qsa(modal, '.pd-children').forEach(hideOne);
            qsa(modal, '.pd-toggle i').forEach((i) => { i.className = 'ti ti-chevron-right'; });
        });
    }

    // search (simple: hide nodes not matching, keep parents if any child matches)
    function applySearch(query) {
        const q = (query || '').trim().toLowerCase();
        const nodes = qsa(modal, '[data-pd-node]');

        if (!q) {
            nodes.forEach((n) => n.classList.remove('d-none'));
            return;
        }

        // mark matches
        nodes.forEach((n) => {
            const btn = n.querySelector('[data-pd-select]');
            const label = (btn?.getAttribute('data-label') || btn?.textContent || '').toLowerCase();
            const isMatch = label.includes(q);
            n.classList.toggle('pd-match', isMatch);
        });

        // show node if match or has matching descendant
        function hasMatchingDesc(node) {
            if (node.classList.contains('pd-match')) return true;
            const children = node.querySelectorAll(':scope > .pd-children > .pd-node');
            for (const c of children) {
                if (hasMatchingDesc(c)) return true;
            }
            return false;
        }

        nodes.forEach((n) => {
            n.classList.toggle('d-none', !hasMatchingDesc(n));
        });
    }

    if (search) {
        search.addEventListener('input', () => applySearch(search.value));
    }

    // keep sync when select changes externally (document scripts)
    select.addEventListener('change', syncFromSelect);
    syncFromSelect();
}

export function initProjectDirectionPickers(root = document) {
    qsa(root, '[data-pd-picker]').forEach(initOnePicker);
}

// init on DOM ready + on Turbo navigations + after dynamic inserts (MutationObserver)
function boot() {
    initProjectDirectionPickers(document);

    const obs = new MutationObserver((mutations) => {
        for (const m of mutations) {
            m.addedNodes.forEach((n) => {
                if (!(n instanceof HTMLElement)) return;
                if (n.matches?.('[data-pd-picker]')) initOnePicker(n);
                initProjectDirectionPickers(n);
            });
        }
    });
    obs.observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
