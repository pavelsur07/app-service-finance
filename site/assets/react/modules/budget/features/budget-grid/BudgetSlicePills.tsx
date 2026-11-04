import { cn } from '@/react/shared/lib/cn';
import { BudgetDropdown } from './BudgetDropdown';
import type { BudgetPair } from './budget-grid.types';
import type { BudgetSlice, SliceOption } from './slice';
import { centerOptions, projectOptions } from './slice';
import styles from './BudgetGrid.module.css';

export interface BudgetSlicePillsProps {
    pairs: BudgetPair[];
    slice: BudgetSlice;
    onChange: (slice: BudgetSlice) => void;
}

interface PillProps {
    title: string;
    allLabel: string;
    options: SliceOption[];
    value: string | null;
    onSelect: (value: string | null) => void;
    dataAttr: string;
}

function Pill({ title, allLabel, options, value, onSelect, dataAttr }: PillProps): JSX.Element {
    const current = value === null ? allLabel : options.find((option) => option.id === value)?.name ?? allLabel;
    const items: { id: string | null; name: string }[] = [{ id: null, name: allLabel }, ...options];

    return (
        <BudgetDropdown
            dataAttr={dataAttr}
            buttonClassName={styles.pill}
            menuClassName={styles.pillMenu}
            ariaLabel={`${title}: ${current}`}
            label={(
                <>
                    {title}:
                    <span className={styles.pillValue}>{current}</span>
                    <i className="ti ti-chevron-down" aria-hidden="true" />
                </>
            )}
        >
            {(close) => items.map((item) => (
                <button
                    key={item.id ?? 'all'}
                    type="button"
                    role="menuitemradio"
                    aria-checked={item.id === value}
                    className={cn('dropdown-item', item.id === value && styles.menuItemActive)}
                    onClick={() => {
                        close();
                        if (item.id !== value) {
                            onSelect(item.id);
                        }
                    }}
                >
                    {item.name}
                </button>
            ))}
        </BudgetDropdown>
    );
}

/**
 * Фильтр разреза (BUDGET-SPEC §5, ref 10): пилюли «Проект» и «ЦФО»; выбранное значение одного
 * измерения ограничивает список другого активными парами; «все» — режим просмотра суммы.
 */
export function BudgetSlicePills({ pairs, slice, onChange }: BudgetSlicePillsProps): JSX.Element {
    return (
        <div className="d-flex flex-wrap align-items-center gap-2" data-budget-slice>
            <span className={styles.sliceLabel}>Разрез</span>
            <Pill
                dataAttr="project"
                title="Проект"
                allLabel="Все проекты"
                options={projectOptions(pairs, slice.center)}
                value={slice.project}
                onSelect={(project) => onChange({ ...slice, project })}
            />
            <Pill
                dataAttr="center"
                title="ЦФО"
                allLabel="Все ЦФО"
                options={centerOptions(pairs, slice.project)}
                value={slice.center}
                onSelect={(center) => onChange({ ...slice, center })}
            />
        </div>
    );
}
