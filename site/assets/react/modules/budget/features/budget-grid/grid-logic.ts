import { inputToStored, parseAmountInput } from './amount';
import type { BudgetGridRow, CellPosition } from './budget-grid.types';

export interface VisibleRow {
    row: BudgetGridRow;
    hasChildren: boolean;
    collapsed: boolean;
}

/** Строки с учётом свёрнутых групп: потомки — строки ниже с уровнем больше, до первой не глубже. */
export function visibleRows(rows: BudgetGridRow[], collapsed: Set<string>): VisibleRow[] {
    const result: VisibleRow[] = [];
    let hideDeeperThan: number | null = null;
    rows.forEach((row, index) => {
        if (hideDeeperThan !== null && row.level > hideDeeperThan) {
            return;
        }
        hideDeeperThan = null;
        const hasChildren = (rows[index + 1]?.level ?? 0) > row.level;
        const isCollapsed = hasChildren && collapsed.has(row.id);
        if (isCollapsed) {
            hideDeeperThan = row.level;
        }
        result.push({ row, hasChildren, collapsed: isCollapsed });
    });

    return result;
}

export interface PastedCell {
    row: BudgetGridRow;
    month: string;
    /** Сумма в знаке отчёта (расход — с минусом). */
    amount: string;
}

export interface PastePlan {
    cells: PastedCell[];
    skipped: number;
}

/**
 * Вставка из Excel (BUDGET-SPEC §5, `planPaste` пакета): с ячейки `start` по видимым строкам и
 * месяцам. Нередактируемые ячейки, нечисловые и пустые значения пропускаются (пустая не
 * очищает) и считаются; выходящее за последнюю строку или декабрь отбрасывается без счёта.
 */
export function planPaste(
    text: string,
    start: CellPosition,
    rows: BudgetGridRow[],
    months: string[],
    editable: (position: CellPosition) => boolean,
): PastePlan {
    const lines = text.replace(/\r/g, '').split('\n');
    while (lines.length > 0 && lines[lines.length - 1] === '') {
        lines.pop();
    }
    const cells: PastedCell[] = [];
    let skipped = 0;
    lines.forEach((line, lineIndex) => {
        const row = rows[start.row + lineIndex];
        if (row === undefined) {
            return;
        }
        line.split('\t').forEach((value, valueIndex) => {
            const position = { row: start.row + lineIndex, col: start.col + valueIndex };
            const month = months[position.col];
            if (month === undefined) {
                return;
            }
            const parsed = value.trim() === '' ? null : parseAmountInput(value);
            if (parsed === null || !editable(position)) {
                skipped += 1;

                return;
            }
            cells.push({ row, month, amount: inputToStored(parsed, row.flow) });
        });
    });

    return { cells, skipped };
}
