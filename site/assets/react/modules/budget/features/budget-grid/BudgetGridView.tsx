import type { ClipboardEvent, CSSProperties, KeyboardEvent, MouseEvent, ReactNode, RefObject } from 'react';
import { cn } from '@/react/shared/lib/cn';
import { formatPercent, formatValue } from './amount';
import type { BudgetGridRow, CellPosition } from './budget-grid.types';
import type { VisibleRow } from './grid-logic';
import styles from './BudgetGrid.module.css';

const MONTH_LABELS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

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
    /** Панель над сеткой: разрез и итог помощника; панель загрузки из Excel или правила. */
    toolbar: ReactNode;
    fillPanel: ReactNode;
    loading: boolean;
    loadError: string | null;
    onReload: () => void;
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

const HOTKEYS = 'Клик или Enter — ввод · Enter/Tab — сохранить и дальше · Стрелки · Esc — отмена · Delete — очистить · Shift+Enter — комментарий · Ctrl+V — вставка из Excel';
const SKELETON_ROWS = 8;

/** Подсказка ячейки: полная сумма (узкая колонка может её сократить), правило, комментарий. */
function cellTitle(row: BudgetGridRow, value: string, comment: string | undefined): string | undefined {
    const formatted = formatValue(value, row.format);
    const parts = formatted.zero ? [] : [`${formatted.whole}${formatted.minor ?? ''}`];
    if (row.rule !== null) {
        parts.push(`Считается по правилу: ${formatPercent(row.rule.percent)} от «${row.rule.baseName}»`);
    }
    if (comment !== undefined) {
        parts.push(comment);
    }

    return parts.length === 0 ? undefined : parts.join('\n');
}

/** Ширины колонок только фиксированные (BUDGET-SPEC §6): статьи, 12 месяцев, «Итого». */
function Columns({ months }: { months: string[] }): JSX.Element {
    return (
        <colgroup>
            <col className={styles.colName} />
            {months.map((month) => <col key={month} className={styles.colMonth} />)}
            <col className={styles.colTotal} />
        </colgroup>
    );
}

function Skeleton(): JSX.Element {
    const months = Array.from({ length: 12 }, (_, index) => String(index));

    return (
        <div className={styles.scroller} aria-busy="true" aria-label="Загружаю план" data-budget-skeleton>
            <table className={styles.grid}>
                <Columns months={months} />
                <tbody>
                    {Array.from({ length: SKELETON_ROWS }, (_, index) => (
                        <tr key={index}>
                            <th className={styles.name} scope="row"><span className={styles.skeletonBar} /></th>
                            {months.map((month) => <td key={month} className={styles.cell}><span className={styles.skeletonBar} /></td>)}
                            <td className={cn(styles.cell, styles.total)}><span className={styles.skeletonBar} /></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Сетка «статьи × 12 месяцев» (BUDGET-SPEC §5, refs 10, 11, 16–18). Только разметка: состояние и клавиатура — в BudgetGridWidget. */
export function BudgetGridView(props: BudgetGridViewProps): JSX.Element {
    const { months, rows, active, editing } = props;
    const showSkeleton = props.loading && rows.length === 0 && props.loadError === null;

    return (
        <div className={styles.root}>
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

            {props.loadError !== null && (
                <div className="alert alert-danger m-3 d-flex flex-wrap align-items-center gap-3" role="alert" data-budget-load-error>
                    <div>
                        <strong>Не удалось загрузить план.</strong> {props.loadError}
                    </div>
                    <button type="button" className="btn btn-sm" onClick={props.onReload}>Повторить</button>
                </div>
            )}
            {showSkeleton && <Skeleton />}

            {props.comment !== null && (
                <div className={styles.commentPopover} role="dialog" aria-label={`Комментарий: ${props.comment.title}`} data-budget-comment>
                    <label className="form-label" htmlFor="budget-comment">Комментарий: {props.comment.title}</label>
                    <textarea
                        id="budget-comment"
                        className="form-control"
                        rows={3}
                        maxLength={500}
                        autoFocus
                        value={props.comment.text}
                        onChange={(event) => props.onCommentChange(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                props.onCommentCancel();
                            } else if (event.key === 'Enter' && !event.shiftKey) {
                                event.preventDefault();
                                props.onCommentSave();
                            }
                        }}
                    />
                    <div className={styles.commentHint}>
                        <span>Enter — сохранить, Shift+Enter — новая строка, Esc — закрыть</span>
                        <span>{props.comment.text.length}/500</span>
                    </div>
                    <div className="d-flex justify-content-end gap-2 mt-2">
                        <button type="button" className="btn btn-sm" onClick={props.onCommentCancel}>Отмена</button>
                        <button type="button" className="btn btn-sm btn-primary" onClick={props.onCommentSave}>Сохранить</button>
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
                    aria-rowcount={rows.length + 1}
                    onKeyDown={props.onGridKeyDown}
                    onPaste={props.onPaste}
                >
                    <table className={styles.grid}>
                        <Columns months={months} />
                        <thead>
                            <tr>
                                <th className={styles.name} scope="col">Статья, ₽</th>
                                {months.map((month, index) => <th key={month} scope="col">{MONTH_LABELS[index]}</th>)}
                                <th scope="col" className={styles.total}>Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(({ row, hasChildren, collapsed }, rowIndex) => (
                                <tr
                                    key={row.id}
                                    className={cn(
                                        hasChildren && styles.group,
                                        !hasChildren && row.type === 'SUBTOTAL' && styles.calc,
                                        row.type === 'KPI' && styles.kpi,
                                    )}
                                >
                                    <th className={styles.name} scope="row" title={row.name} style={{ '--budget-level': String(row.level - 1) } as CSSProperties}>
                                        <span className={styles.nameInner}>
                                            {hasChildren ? (
                                                <button
                                                    type="button"
                                                    className={styles.toggle}
                                                    aria-expanded={!collapsed}
                                                    aria-label={collapsed ? `Развернуть «${row.name}»` : `Свернуть «${row.name}»`}
                                                    onClick={() => props.onToggle(row.id)}
                                                >
                                                    <i className={cn('ti', collapsed ? 'ti-chevron-right' : 'ti-chevron-down')} aria-hidden="true" />
                                                </button>
                                            ) : <span className={styles.togglePlaceholder} />}
                                            <span className={styles.nameText}>
                                                {row.name}
                                                {row.rule !== null && (
                                                    <span className={styles.ruleBadge} data-budget-rule>
                                                        {formatPercent(row.rule.percent)} от {row.rule.baseName}
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                    </th>
                                    {months.map((month, colIndex) => {
                                        const key = `${row.id}|${month}`;
                                        const isActive = active?.row === rowIndex && active.col === colIndex;
                                        const isEditing = editing?.position.row === rowIndex && editing.position.col === colIndex;
                                        const comment = row.comments[month];
                                        const editableCell = row.editable && props.canEdit;
                                        const unsaved = props.unsavedKeys.has(key);

                                        return (
                                            <td
                                                key={month}
                                                role="gridcell"
                                                aria-selected={isActive}
                                                aria-invalid={props.invalidKeys.has(key) || undefined}
                                                title={cellTitle(row, row.values[month] ?? '0', comment)}
                                                className={cn(
                                                    styles.cell,
                                                    editableCell ? styles.editable : row.editable && styles.readonlyCell,
                                                    isActive && styles.active,
                                                    props.invalidKeys.has(key) && styles.invalid,
                                                )}
                                                onMouseDown={() => props.onCellMouseDown({ row: rowIndex, col: colIndex })}
                                                onDoubleClick={() => props.onCellDoubleClick({ row: rowIndex, col: colIndex })}
                                                onContextMenu={(event) => props.onCellContextMenu({ row: rowIndex, col: colIndex }, event)}
                                            >
                                                {unsaved && <span className={styles.unsavedDot} title="Не сохранено" data-budget-unsaved />}
                                                {comment !== undefined && <span className={styles.commentMark} aria-hidden="true" data-budget-comment-mark />}
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
                                    <td className={cn(styles.cell, styles.total)} title={cellTitle(row, row.total, undefined)}>
                                        <Value value={row.total} row={row} blankZero={false} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {rows.length > 0 && (
                <div className={styles.footer}>
                    {props.inputError !== null && <div className="text-danger" role="alert" data-budget-input-error>{props.inputError}</div>}
                    {props.pasteMessage !== null && <div role="status" data-budget-paste-message>{props.pasteMessage}</div>}
                    {props.warnings.map((warning) => <div key={warning} className="text-warning">{warning}</div>)}
                    {props.canEdit && <div className={styles.hotkeys}>{HOTKEYS}</div>}
                </div>
            )}
        </div>
    );
}
