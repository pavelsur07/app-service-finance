/** Контракт острова `budget-grid` (BudgetVersionShowController) и JSON сетки (BudgetGridView). */

export interface BudgetPair {
    projectDirectionId: string;
    responsibilityCenterId: string;
    label: string;
    system: boolean;
}

export interface BudgetGridProps {
    versionId: string;
    year: number;
    editable: boolean;
    canWrite: boolean;
    isOwner: boolean;
    pairs: BudgetPair[];
    gridUrl: string;
    cellsUrl: string;
    csrfToken: string;
    plCategoriesUrl: string;
}

export type BudgetFlow = 'income' | 'expense' | 'none';
export type BudgetRowType = 'LEAF_INPUT' | 'SUBTOTAL' | 'KPI';
export type BudgetValueFormat = 'MONEY' | 'PERCENT' | 'RATIO' | 'QTY';

export interface BudgetGridRow {
    id: string;
    code: string | null;
    name: string;
    level: number;
    type: BudgetRowType;
    flow: BudgetFlow;
    format: BudgetValueFormat;
    editable: boolean;
    /** `Y-m` → сумма строкой в знаке отчёта ОПиУ */
    values: Record<string, string>;
    total: string;
    /** `Y-m` → комментарий */
    comments: Record<string, string>;
}

export interface BudgetGridData {
    versionId: string;
    year: number;
    months: string[];
    rows: BudgetGridRow[];
    warnings: string[];
}

export interface SelectedPair {
    projectDirectionId: string;
    responsibilityCenterId: string;
}

/** Правка ячейки, ещё не подтверждённая сервером; пара — та, в которой её ввели. */
export interface PendingCell extends SelectedPair {
    rowId: string;
    month: string;
    /** Сумма в знаке отчёта, `-60000.00` */
    amount: string;
    comment: string | null;
}

export type SaveState = 'saved' | 'saving' | 'failed';

export interface CellPosition {
    row: number;
    col: number;
}
