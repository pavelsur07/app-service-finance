import { createPortal } from 'react-dom';
import { BudgetDropdown } from './BudgetDropdown';
import type { BudgetFillHelper, SaveState } from './budget-grid.types';
import styles from './BudgetGrid.module.css';

/** Короткие подписи пунктов меню (ref 15); подробности — в панели помощника. */
const MENU_HELPERS: { value: BudgetFillHelper; label: string; hint: string }[] = [
    { value: 'last_year_fact', label: 'Из факта прошлого года', hint: 'Факт того же месяца прошлого года в этой паре' },
    { value: 'average', label: 'Среднее за N месяцев', hint: 'Среднее факта за последние закрытые месяцы' },
    { value: 'distribute', label: 'Распределить годовую сумму', hint: 'Поровну на 12 месяцев' },
    { value: 'copy_right', label: 'Копировать месяц вправо', hint: 'Значение месяца — во все месяцы правее' },
    { value: 'percent_of', label: 'X% от другой статьи', hint: 'Разово считает суммы от плана базы' },
];

export interface SaveIndicatorProps {
    state: SaveState;
    error: string | null;
    locked: boolean;
    onRetry: () => void;
}

/** «Сохранено» · «Сохраняю…» · «Не сохранено — Повторить» (BUDGET-SPEC §5). */
export function SaveIndicator({ state, error, locked, onRetry }: SaveIndicatorProps): JSX.Element {
    const failed = locked || state === 'failed';
    const modifier = failed ? 'budget-save--failed' : state === 'saving' ? 'budget-save--saving' : '';

    return (
        <span className={`budget-save ${modifier}`} role="status" aria-live="polite" data-budget-save={failed ? 'failed' : state}>
            <span className="budget-save__dot" aria-hidden="true" />
            {failed ? 'Не сохранено' : state === 'saving' ? 'Сохраняю…' : 'Сохранено'}
            {failed && !locked && (
                <>
                    {' — '}
                    <button type="button" className="budget-save__retry" title={error ?? undefined} onClick={onRetry}>Повторить</button>
                </>
            )}
        </span>
    );
}

export interface HelpersMenuProps {
    /** Причина, по которой пункты недоступны (не черновик, нет права, сумма, сетка недоступна). */
    disabledReason: string | null;
    onHelper: (helper: BudgetFillHelper) => void;
    onRule: () => void;
    onImport: () => void;
}

/** «Помощники ▾»: пять помощников, правило «% от строки», загрузка из Excel (ref 15). */
export function HelpersMenu({ disabledReason, onHelper, onRule, onImport }: HelpersMenuProps): JSX.Element {
    const disabled = disabledReason !== null;
    const item = (key: string, label: string, hint: string | null, onClick: () => void, close: () => void): JSX.Element => (
        <button
            key={key}
            type="button"
            role="menuitem"
            className={`dropdown-item${disabled ? ' disabled' : ''}`}
            aria-disabled={disabled}
            data-budget-helper={key}
            onClick={() => {
                if (disabled) {
                    return;
                }
                close();
                onClick();
            }}
        >
            <span>{label}</span>
            {hint !== null && <span className="budget-menu-item__hint">{hint}</span>}
        </button>
    );

    return (
        <BudgetDropdown
            dataAttr="helpers"
            align="end"
            buttonClassName="btn"
            menuClassName="budget-helpers-menu"
            label={(
                <>
                    Помощники
                    <i className="ti ti-chevron-down ms-1" aria-hidden="true" />
                </>
            )}
        >
            {(close) => (
                <>
                    {disabled && <div className={`dropdown-header ${styles.menuReason}`} role="note">{disabledReason}</div>}
                    {MENU_HELPERS.map((helper) => item(helper.value, helper.label, helper.hint, () => onHelper(helper.value), close))}
                    <div className="dropdown-divider" role="separator" />
                    {item('rule', 'Правило «% от строки»', 'Строка считается от другой статьи сама', onRule, close)}
                    {item('import', 'Загрузить из Excel', 'План выбранной пары из файла выгрузки', onImport, close)}
                </>
            )}
        </BudgetDropdown>
    );
}

/** Портал в слот шапки Twig (`data-budget-head-slot`); нет слота — ничего. */
export function HeadSlot({ name, children }: { name: string; children: JSX.Element }): JSX.Element | null {
    const slot = document.querySelector(`[data-budget-head-slot="${name}"]`);

    return slot === null ? null : createPortal(children, slot);
}
