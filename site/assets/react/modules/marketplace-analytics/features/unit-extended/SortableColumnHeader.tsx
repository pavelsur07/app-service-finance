import React from 'react';
import { cn } from '@/react/shared/lib/cn';
import styles from './UnitExtendedTable.module.css';

interface SortableColumnHeaderProps {
    children: React.ReactNode;
    direction?: 'asc' | 'desc';
    onSort: () => void;
    className?: string;
    title?: string;
    accessibleLabel?: string;
    style?: React.CSSProperties;
    mirror?: boolean;
}

export function SortableColumnHeader({
    children, direction, onSort, className, title, accessibleLabel, style, mirror = false,
}: SortableColumnHeaderProps): React.ReactElement {
    const label = (
        <>
            {children}
            {direction && <i className={`ti ti-chevron-${direction === 'asc' ? 'up' : 'down'} ms-1`} aria-hidden="true" />}
        </>
    );

    return (
        <th
            scope="col"
            className={cn(className, 'cursor-pointer')}
            title={title}
            style={style}
            aria-sort={!mirror && direction ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}
            onClick={onSort}
        >
            {mirror ? label : (
                <button type="button" className={styles.sortButton} aria-label={accessibleLabel}>
                    {label}
                </button>
            )}
        </th>
    );
}
