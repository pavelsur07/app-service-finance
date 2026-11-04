import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/react/shared/lib/cn';
import styles from './BudgetGrid.module.css';

const ITEM_SELECTOR = '[role^="menuitem"]:not([aria-disabled="true"])';

export interface BudgetDropdownProps {
    /** Содержимое кнопки (подпись и шеврон). */
    label: ReactNode;
    buttonClassName: string;
    menuClassName?: string;
    ariaLabel?: string;
    disabled?: boolean;
    /** Пункты: `close` закрывает меню и возвращает фокус на кнопку. */
    children: (close: () => void) => ReactNode;
    align?: 'start' | 'end';
    dataAttr?: string;
}

/**
 * Выпадающее меню острова под состоянием React (CLAUDE.frontend.md: модалки и меню внутри
 * острова — не Bootstrap JS): Esc и клик мимо закрывают, стрелки ходят по пунктам.
 */
export function BudgetDropdown(props: BudgetDropdownProps): JSX.Element {
    const [open, setOpen] = useState(false);
    const rootRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuId = useId();

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onPointer = (event: PointerEvent): void => {
            if (rootRef.current !== null && !rootRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', onPointer);
        const root = rootRef.current;
        (root?.querySelector<HTMLElement>('[aria-checked="true"]') ?? root?.querySelector<HTMLElement>(ITEM_SELECTOR))?.focus();

        return () => document.removeEventListener('pointerdown', onPointer);
    }, [open]);

    const close = (): void => {
        setOpen(false);
        buttonRef.current?.focus({ preventScroll: true });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>): void => {
        if (!open) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            close();

            return;
        }
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }
        event.preventDefault();
        const items = [...(rootRef.current?.querySelectorAll<HTMLElement>(ITEM_SELECTOR) ?? [])];
        const index = items.indexOf(document.activeElement as HTMLElement);
        const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
        items[next]?.focus();
    };

    return (
        <div className="dropdown" ref={rootRef} onKeyDown={onKeyDown} data-budget-dropdown={props.dataAttr}>
            <button
                ref={buttonRef}
                type="button"
                className={props.buttonClassName}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                aria-label={props.ariaLabel}
                disabled={props.disabled}
                onClick={() => setOpen((current) => !current)}
            >
                {props.label}
            </button>
            {open && (
                <div id={menuId} role="menu" className={cn('dropdown-menu show', styles.menu, props.align === 'end' && styles.menuEnd, props.menuClassName)}>
                    {props.children(close)}
                </div>
            )}
        </div>
    );
}
