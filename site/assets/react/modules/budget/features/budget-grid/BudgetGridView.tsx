import type { ClipboardEvent, CSSProperties, KeyboardEvent, MouseEvent, ReactNode, RefObject } from 'react';
import { cn } from '@/react/shared/lib/cn';
import { formatPercent, formatValue } from './amount';
import type { BudgetGridRow, CellPosition } from './budget-grid.types';
import styles from './BudgetGrid.module.css';

const MONTH_LABELS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

export interface VisibleRow {
    row: BudgetGridRow;
    hasChildren: boolean;
    collapsed: boolean;
}

export interface CommentDraft {
    title: string;
    text: string;
}

export interface BudgetGridViewProps {
    months: string[];
    rows: VisibleRow[];
    canEdit: boolean;
    readOnlyHint: string | null;
    active: CellPosition | null;
    editing: { position: CellPosition; text: string } | null;
    invalidKeys: Set<string>;
    unsavedKeys: Set<string>;
    inputError: string | null;
    pasteMessage: string | null;
    warnings: string[];
    lockedMessage: string | null;
    unsavedLabels: string[];
    comment: CommentDraft | null;
    /** Панель над сеткой: разрез и итог помощника; раскрытая панель помощника (BudgetFillPanel). */
    toolbar: ReactNode;
    fillPanel: ReactNode;
    loading: boolean;
    loadError: string | null;
    scrollerRef: RefObject<HTMLDivElement>;
    inputRef: RefObject<HTMLInputElement>;
    onToggle: (rowId: string) => void;
    onCellMouseDown: (position: CellPosition) => void;
    onCellDoubleClick: (position: CellPosition) => void;
    onCellContextMenu: (position: CellPosition, event: MouseEvent) => void;
    onGridKeyDown: (event: KeyboardEvent<HTMLDivElement>) => void;
    onPaste: (event: ClipboardEvent<HTMLDivElement>) => void;
    onEditChange: (text: string) => void;
    onEditKeyDown: (event: KeyboardEvent<HTMLInputElement>) => void;
    onEditBlur: () => void;
    onCommentChange: (text: string) => void;
    onCommentSave: () => void;
    onCommentCancel: () => void;
}

interface ValueProps {
    value: string;
    row: BudgetGridRow;
    /** Пустая ячейка вместо нуля (лист без плана). */
    blankZero: boolean;
}

function Value({ value, row, blankZero }: ValueProps): JSX.Element | null {
    const formatted = formatValue(value, row.format);
    if (formatted.zero && blankZero) {
        return null;
    }

    return (
        <span className={cn('vf-amount', formatted.zero && 'vf-amount--zero')}>
            {formatted.whole}
            {formatted.minor !== null && <span className="vf-amount__minor">{formatted.minor}</span>}
        </span>
    );
}

/** Сетка «статьи × 12 месяцев» (ui-ux.md §3). Только разметка: состояние и клавиатура — в BudgetGridWidget. */
export function BudgetGridView(props: BudgetGridViewProps): JSX.Element {
    const { months, rows, active, editing } = props;

    return (
        <div>
            {props.toolbar !== null && (
                <div className="vf-card-bar d-print-none">
                    <div className="vf-card-bar__row">
                        {props.toolbar}
                    </div>
                </div>
            )}

            {props.fillPanel}

            {props.lockedMessage !== null && (
                <div className="alert alert-danger m-3" role="alert" data-budget-locked>
                    <div>
                        <strong>{props.lockedMessage}</strong>{' '}
                        Экран переключён в режим чтения, изменения не сохранены. Обновите страницу.
                    </div>
                    {props.unsavedLabels.length > 0 && (
                        <div className="mt-1">Несохранённые ячейки: {props.unsavedLabels.join('; ')}.</div>
                    )}
                </div>
            )}

            {props.readOnlyHint !== null && props.lockedMessage === null && (
                <div className="text-secondary px-3 py-2">{props.readOnlyHint}</div>
            )}

            {props.loadError !== null && <div className="alert alert-danger m-3" role="alert">{props.loadError}</div>}
            {props.loading && rows.length === 0 && <div className="text-secondary p-3">Загружаю план…</div>}

            {props.comment !== null && (
                <div className={styles.commentPanel}>
                    <label className="form-label" htmlFor="budget-comment">Комментарий: {props.comment.title}</label>
                    <textarea
                        id="budget-comment"
                        className="form-control"
                        rows={2}
                        maxLength={500}
                        autoFocus
                        value={props.comment.text}
                        onChange={(event) => props.onCommentChange(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                props.onCommentCancel();
                            }
                        }}
                    />
                    <div className="d-flex gap-2 mt-2">
                        <button type="button" className="btn btn-sm btn-primary" onClick={props.onCommentSave}>Сохранить</button>
                        <button type="button" className="btn btn-sm" onClick={props.onCommentCancel}>Отмена</button>
                        <span className="text-secondary ms-auto">{props.comment.text.length} / 500</span>
                    </div>
                </div>
            )}

            {rows.length > 0 && (
                <div
                    ref={props.scrollerRef}
                    className={styles.scroller}
                    tabIndex={0}
                    role="grid"
                    aria-label="План по статьям ОПиУ и месяцам"
                    onKeyDown={props.onGridKeyDown}
                    onPaste={props.onPaste}
                >
                    <table className={styles.grid}>
                        <thead>
                            <tr>
                                <th className={styles.name} scope="col">Статья, ₽</th>
                                {months.map((month, index) => <th key={month} scope="col">{MONTH_LABELS[index]}</th>)}
                                <th scope="col">Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(({ row, hasChildren, collapsed }, rowIndex) => (
                                <tr key={row.id} className={cn(row.type === 'SUBTOTAL' && styles.subtotal, row.type === 'KPI' && styles.kpi)}>
                                    <th className={styles.name} scope="row" title={row.name} style={{ '--budget-level': String(row.level - 1) } as CSSProperties}>
                                        {hasChildren ? (
                                            <button
                                                type="button"
                                                className={styles.toggle}
                                                aria-expanded={!collapsed}
                                                aria-label={collapsed ? `Развернуть «${row.name}»` : `Свернуть «${row.name}»`}
                                                onClick={() => props.onToggle(row.id)}
                                            >
                                                {collapsed ? '▸' : '▾'}
                                            </button>
                                        ) : <span className={styles.togglePlaceholder} />}
                                        {row.name}
                                        {row.rule !== null && (
                                            <span className={styles.ruleBadge} data-budget-rule>
                                                {formatPercent(row.rule.percent)} от {row.rule.baseName}
                                            </span>
                                        )}
                                    </th>
                                    {months.map((month, colIndex) => {
                                        const key = `${row.id}|${month}`;
                                        const isActive = active?.row === rowIndex && active.col === colIndex;
                                        const isEditing = editing?.position.row === rowIndex && editing.position.col === colIndex;
                                        const comment = row.comments[month];
                                        const editableCell = row.editable && props.canEdit;

                                        return (
                                            <td
                                                key={month}
                                                role="gridcell"
                                                aria-selected={isActive}
                                                title={row.rule !== null ? `Считается по правилу: ${formatPercent(row.rule.percent)} от «${row.rule.baseName}»` : comment}
                                                className={cn(
                                                    styles.cell,
                                                    editableCell ? styles.editable : row.editable && styles.readonlyCell,
                                                    isActive && styles.active,
                                                    props.invalidKeys.has(key) && styles.invalid,
                                                    props.unsavedKeys.has(key) && styles.unsaved,
                                                    comment !== undefined && styles.hasComment,
                                                )}
                                                onMouseDown={() => props.onCellMouseDown({ row: rowIndex, col: colIndex })}
                                                onDoubleClick={() => props.onCellDoubleClick({ row: rowIndex, col: colIndex })}
                                                onContextMenu={(event) => props.onCellContextMenu({ row: rowIndex, col: colIndex }, event)}
                                            >
                                                {isEditing && editing !== null ? (
                                                    <input
                                                        ref={props.inputRef}
                                                        className={styles.input}
                                                        inputMode="decimal"
                                                        size={1}
                                                        aria-label={`${row.name}, ${MONTH_LABELS[colIndex]}`}
                                                        value={editing.text}
                                                        onChange={(event) => props.onEditChange(event.target.value)}
                                                        onKeyDown={props.onEditKeyDown}
                                                        onBlur={props.onEditBlur}
                                                    />
                                                ) : (
                                                    <Value value={row.values[month] ?? '0'} row={row} blankZero={row.editable || row.rule !== null} />
                                                )}
                                            </td>
                                        );
                                    })}
                                    <td className={cn(styles.cell, styles.total)}>
                                        <Value value={row.total} row={row} blankZero={false} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {(props.inputError !== null || props.pasteMessage !== null || props.warnings.length > 0) && (
                <div className="px-3 py-2">
                    {props.inputError !== null && <div className="text-danger" role="alert">{props.inputError}</div>}
                    {props.pasteMessage !== null && <div className="text-secondary" role="status">{props.pasteMessage}</div>}
                    {props.warnings.map((warning) => <div key={warning} className="text-warning">{warning}</div>)}
                </div>
            )}
        </div>
    );
}
