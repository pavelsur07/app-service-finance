function qs(root, sel) { return root.querySelector(sel); }
function qsa(root, sel) { return Array.from(root.querySelectorAll(sel)); }
function escapeSelector(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(value);
    }

    return String(value).replace(/([ #;?%&,.+*~':"!^$[\]()=>|/\\])/g, '\\$1');
}

function getBootstrap() {
    // Tabler обычно включает Bootstrap bundle; если нет — graceful fallback
    return window.bootstrap || null;
}

function resolveBootstrap() {
    return window.bootstrap || null;
}

function isCollapseShown(el) {
    return el.classList.contains('show');
}

function getCollapse(el) {
    const bs = resolveBootstrap();
    if (bs?.Collapse) {
        return bs.Collapse.getOrCreateInstance(el, { toggle: false });
    }
    return null;
}

function showCollapse(el) {
    const inst = getCollapse(el);
    if (inst) {
        inst.show();
    } else {
        el.classList.add('show');
    }
}

function hideCollapse(el) {
    const inst = getCollapse(el);
    if (inst) {
        inst.hide();
    } else {
        el.classList.remove('show');
    }
}

function toggleCollapse(el) {
    const nowShown = !isCollapseShown(el);
    if (nowShown) {
        showCollapse(el);
    } else {
        hideCollapse(el);
    }
    return nowShown;
}

function hideModalSafe(modal, fallbackFocusEl) {
    const bs = resolveBootstrap();
    if (bs?.Modal) {
        const inst = bs.Modal.getOrCreateInstance(modal);
        inst.hide();
        return;
    }

    if (fallbackFocusEl && modal.contains(document.activeElement)) {
        fallbackFocusEl.focus({ preventScroll: true });
    }

    modal.classList.remove('show');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    qsa(document, '.modal-backdrop').forEach((el) => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
}

function initOnePicker(picker) {
    if (picker.dataset.pdInited === '1') return;
    picker.dataset.pdInited = '1';

    const debug = picker.dataset.pdDebug === '1';
    const log = (...args) => {
        if (debug) {
            console.debug('[pd-picker]', ...args);
        }
    };

    const fieldId = picker.getAttribute('data-pd-field-id');
    const select = qs(picker, `#${escapeSelector(fieldId)}`);
    const display = qs(picker, '[data-pd-display]');
    const clearBtn = qs(picker, '[data-pd-clear]');
    const openBtn = qs(picker, '[data-pd-open]');
    const modal = picker.querySelector('.modal');
    const selectedLabelEl = qs(modal, '[data-pd-selected-label]');
    const search = qs(modal, '[data-pd-search]');
    const expandAllBtn = qs(modal, '[data-pd-expand-all]');
    const collapseAllBtn = qs(modal, '[data-pd-collapse-all]');

    if (!select || !display || !modal) return;

    function setValue(id, label) {
        log('setValue', { id, label });
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
        log('syncFromSelect', { value: select.value, label: display.value });
    }

    // highlight in tree
    function highlightSelected(id) {
        qsa(modal, '[data-pd-select]').forEach((btn) => {
            btn.classList.remove('pd-selected');
            btn.closest('.pd-row')?.classList.remove('pd-row-selected');
        });

        if (!id) return;

        const btn = modal.querySelector(`[data-pd-select][data-id="${escapeSelector(id)}"]`);
        if (btn) {
            btn.classList.add('pd-selected');
            btn.closest('.pd-row')?.classList.add('pd-row-selected');

            // ensure parents expanded
            let parent = btn.closest('.pd-node');
            while (parent) {
                const children = parent.querySelector(':scope > .pd-children');
                if (children) {
                    showCollapse(children);
                    const toggle = parent.querySelector(':scope .pd-toggle i');
                    if (toggle) toggle.className = 'ti ti-chevron-down';
                }
                parent = parent.parentElement?.closest('.pd-node');
            }
        }
    }

    function handleToggleClick(btn) {
        const targetSel = btn.getAttribute('data-target');
        let target = null;
        if (targetSel && targetSel.startsWith('#')) {
            target = document.getElementById(targetSel.slice(1));
        } else if (targetSel) {
            target = modal.querySelector(targetSel);
        }
        if (!target) {
            log('toggle click: target not found', { targetSel });
            return;
        }

        const icon = btn.querySelector('i');

        const nowShown = toggleCollapse(target);
        if (icon) icon.className = nowShown ? 'ti ti-chevron-down' : 'ti ti-chevron-right';
        log('toggle click', { targetSel, nowShown });
    }

    const fallbackFocusEl = openBtn || display;

    function ensureFocusOutsideModal() {
        if (fallbackFocusEl && modal.contains(document.activeElement)) {
            fallbackFocusEl.focus({ preventScroll: true });
        }
    }

    function handleSelectClick(btn) {
        const disabled = btn.getAttribute('data-disabled') === '1';
        if (disabled) {
            log('select click ignored (disabled)', { id: btn.getAttribute('data-id') });
            return;
        }

        const id = btn.getAttribute('data-id');
        const label = btn.getAttribute('data-label') || btn.textContent.trim();
        log('select click', { id, label });
        setValue(id, label);

        hideModalSafe(modal, fallbackFocusEl);
    }

    // delegate clicks to handle dynamic content and bubbling issues
    modal.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const selectBtn = target.closest('[data-pd-select]');
        if (selectBtn && modal.contains(selectBtn)) {
            handleSelectClick(selectBtn);
            return;
        }

        const toggleBtn = target.closest('[data-pd-toggle]');
        if (toggleBtn && modal.contains(toggleBtn)) {
            handleToggleClick(toggleBtn);
        }
    });

    // clear
    if (clearBtn) {
        clearBtn.addEventListener('click', () => setValue('', ''));
    }

    // expand/collapse all
    function showOne(el) {
        showCollapse(el);
    }
    function hideOne(el) {
        hideCollapse(el);
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

        const memo = new Map();

        // show node if match or has matching descendant
        function hasMatchingDesc(node) {
            if (memo.has(node)) {
                return memo.get(node);
            }

            if (node.classList.contains('pd-match')) {
                memo.set(node, true);
                return true;
            }
            const children = node.querySelectorAll(':scope > .pd-children > .pd-node');
            for (const c of children) {
                if (hasMatchingDesc(c)) {
                    memo.set(node, true);
                    return true;
                }
            }
            memo.set(node, false);
            return false;
        }

        nodes.forEach((n) => {
            n.classList.toggle('d-none', !hasMatchingDesc(n));
        });
    }

    if (search) {
        search.addEventListener('input', () => applySearch(search.value));
    }

    modal.addEventListener('hide.bs.modal', ensureFocusOutsideModal);

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
