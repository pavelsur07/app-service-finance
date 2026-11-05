import { addAmounts, isZero, negate, parseAmountInput } from './amount';
import type { BudgetFillHelper, BudgetFillResult, BudgetGridRow } from './budget-grid.types';

/** Чистая логика окна «Быстрое заполнение» (BUDGET-SPEC §5): выбор статей и месяцев, параметры, предпросмотр. */

export const MONTH_SHORT = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
export const MONTH_FULL = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

/** `2027-03` → индекс 0…11. */
export const monthIndex = (month: string): number => Number(month.slice(5, 7)) - 1;

export function plural(count: number, one: string, few: string, many: string): string {
    const mod10 = count % 10;
    const mod100 = count % 100;
    if (mod10 === 1 && mod100 !== 11) {
        return one;
    }
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
        return few;
    }

    return many;
}

export interface FillGroup {
    id: string;
    name: string;
    leaves: BudgetGridRow[];
}

export const OTHER_GROUP_ID = '__other';

/** Статья с правилом «% от строки» считается сама: сервер помощником её не меняет. */
export const isLocked = (row: BudgetGridRow): boolean => row.rule !== null;

/**
 * Статьи ввода по группам ОПиУ: группа — ближайшая строка выше с меньшим уровнем (родитель).
 * Статьи без родителя — в группе «Прочее» в конце. Порядок — как в дереве.
 */
export function fillGroups(rows: BudgetGridRow[]): FillGroup[] {
    const groups = new Map<string, FillGroup>();
    const other: FillGroup = { id: OTHER_GROUP_ID, name: 'Прочее', leaves: [] };
    const ancestors: BudgetGridRow[] = [];
    rows.forEach((row) => {
        while (ancestors.length > 0 && ancestors[ancestors.length - 1].level >= row.level) {
            ancestors.pop();
        }
        if (row.editable) {
            const parent = ancestors[ancestors.length - 1];
            if (parent === undefined) {
                other.leaves.push(row);
            } else {
                const group = groups.get(parent.id) ?? { id: parent.id, name: parent.name, leaves: [] };
                group.leaves.push(row);
                groups.set(parent.id, group);
            }
        }
        ancestors.push(row);
    });

    return other.leaves.length > 0 ? [...groups.values(), other] : [...groups.values()];
}

/** Поиск по названию статьи без учёта регистра; группы без найденных статей скрываются. */
export function filterGroups(groups: FillGroup[], query: string): FillGroup[] {
    const needle = query.trim().toLocaleLowerCase('ru');
    if (needle === '') {
        return groups;
    }

    return groups
        .map((group) => ({ ...group, leaves: group.leaves.filter((row) => row.name.toLocaleLowerCase('ru').includes(needle)) }))
        .filter((group) => group.leaves.length > 0);
}

export type TriState = 'on' | 'off' | 'mixed';

/** Состояние флажка группы по доступным (без правила) статьям: «N из M». */
export function groupState(group: FillGroup, selected: ReadonlySet<string>): { state: TriState; selected: number; total: number } {
    const free = group.leaves.filter((row) => !isLocked(row));
    const count = free.filter((row) => selected.has(row.id)).length;
    let state: TriState = 'mixed';
    if (count === 0) {
        state = 'off';
    } else if (count === free.length) {
        state = 'on';
    }

    return { state, selected: count, total: free.length };
}

/** Включить или выключить набор значений; заблокированные статьи вызывающий отфильтровывает сам. */
export function setSelected(selected: ReadonlySet<string>, values: string[], on: boolean): Set<string> {
    const next = new Set(selected);
    values.forEach((value) => (on ? next.add(value) : next.delete(value)));

    return next;
}

/** Доступные статьи групп (для «все / ни одной» по найденным и флажка группы). */
export const freeLeafIds = (groups: FillGroup[]): string[] => groups.flatMap((group) => group.leaves.filter((row) => !isLocked(row)).map((row) => row.id));

export interface QuickChip {
    label: string;
    months: string[];
    on: boolean;
}

/** Чипы «1–4 кв.»: включён, если выбраны все месяцы квартала из сетки. */
export function quarterChips(months: string[], selected: ReadonlySet<string>): QuickChip[] {
    return [0, 1, 2, 3].map((quarter) => {
        const inQuarter = months.filter((month) => Math.floor(monthIndex(month) / 3) === quarter);

        return { label: `${quarter + 1} кв.`, months: inQuarter, on: inQuarter.length > 0 && inQuarter.every((month) => selected.has(month)) };
    });
}

/** Помощники, у которых месяцы задаются не выбором: распределение — все 12, копирование — правее исходного. */
export const usesMonthPicker = (helper: BudgetFillHelper): boolean => helper !== 'distribute' && helper !== 'copy_right';

export interface FillTargets {
    categoryIds: string[];
    months: string[];
}

/** Какие ячейки затронет помощник: выбранные доступные статьи в порядке дерева × месяцы. */
export function fillTargets(
    helper: BudgetFillHelper,
    rows: BudgetGridRow[],
    selectedCategories: ReadonlySet<string>,
    months: string[],
    selectedMonths: ReadonlySet<string>,
    sourceMonth: string,
): FillTargets {
    const categoryIds = rows.filter((row) => row.editable && !isLocked(row) && selectedCategories.has(row.id)).map((row) => row.id);
    let targetMonths: string[];
    if (helper === 'distribute') {
        targetMonths = months;
    } else if (helper === 'copy_right') {
        targetMonths = months.filter((month) => month > sourceMonth);
    } else {
        targetMonths = months.filter((month) => selectedMonths.has(month));
    }

    return { categoryIds, months: targetMonths };
}

/** «Будет затронуто 66 ячеек: 11 статей × 6 месяцев» или подсказка, чего не хватает. */
export function targetsLine(targets: FillTargets, monthPicker: boolean): string {
    const cells = targets.categoryIds.length * targets.months.length;
    if (cells === 0) {
        if (targets.categoryIds.length > 0 && !monthPicker) {
            return 'Нет месяцев правее исходного.';
        }

        return monthPicker ? 'Выберите хотя бы одну статью и месяц.' : 'Выберите хотя бы одну статью.';
    }
    const articles = targets.categoryIds.length;
    const monthCount = targets.months.length;

    return `Будет затронуто ${cells} ${plural(cells, 'ячейка', 'ячейки', 'ячеек')}: `
        + `${articles} ${plural(articles, 'статья', 'статьи', 'статей')} × ${monthCount} ${plural(monthCount, 'месяц', 'месяца', 'месяцев')}`;
}

export interface HelperInputs {
    average: number;
    amount: string;
    sourceMonth: string;
    percent: string;
    baseId: string;
}

export type HelperFieldErrors = Partial<Record<'amount' | 'percent' | 'baseId', string>>;

export type HelperParamsCheck =
    | { ok: true; params: Record<string, unknown> }
    /** `errors` — что показать под полями; пустое поле ошибкой не считается, но кнопку блокирует. */
    | { ok: false; errors: HelperFieldErrors };

/** Параметры помощника для `POST …/fill` или ошибки полей. */
export function helperParams(helper: BudgetFillHelper, inputs: HelperInputs): HelperParamsCheck {
    switch (helper) {
        case 'average':
            return { ok: true, params: { months: inputs.average } };
        case 'distribute': {
            if (inputs.amount.trim() === '') {
                return { ok: false, errors: {} };
            }
            const parsed = parseAmountInput(inputs.amount);
            if (parsed === null || isZero(parsed)) {
                return { ok: false, errors: { amount: 'Введите годовую сумму, например 1 200 000.' } };
            }

            return { ok: true, params: { amount: parsed } };
        }
        case 'copy_right':
            return { ok: true, params: { sourceMonth: inputs.sourceMonth } };
        case 'percent_of': {
            const errors: HelperFieldErrors = {};
            const parsed = inputs.percent.trim() === '' ? '' : parseAmountInput(inputs.percent);
            if (parsed === null || (parsed !== '' && parsed.startsWith('-'))) {
                errors.percent = 'Введите процент, например 5 или 7,5.';
            }
            if (inputs.baseId === '') {
                errors.baseId = 'Нет статьи-базы: в дереве нет денежных строк.';
            }
            if (parsed === null || parsed === '' || Object.keys(errors).length > 0) {
                return { ok: false, errors };
            }

            return { ok: true, params: { percent: parsed, baseCategoryId: inputs.baseId } };
        }
        default:
            return { ok: true, params: {} };
    }
}

export interface PreviewCell {
    month: string;
    /** Новое значение; `null` — ячейка не меняется. */
    amount: string | null;
    /** Прежнее значение (для неизменённой — текущее). */
    previous: string;
    /** Перезаписывает заполненную ячейку. */
    overwrite: boolean;
}

export interface PreviewRow {
    id: string;
    name: string;
    cells: PreviewCell[];
    /** Сумма изменений по статье в знаке отчёта. */
    delta: string;
}

export interface PreviewTable {
    months: string[];
    rows: PreviewRow[];
}

/**
 * Таблица шага «Проверка»: статьи, где что-то меняется, × месяцы с изменениями (порядок сетки).
 * Сервер возвращает только меняющиеся ячейки; остальные ячейки прямоугольника — «без изменений».
 */
export function previewTable(result: BudgetFillResult, rows: BudgetGridRow[], months: string[]): PreviewTable {
    const byKey = new Map(result.cells.map((cell) => [`${cell.plCategoryId}|${cell.month}`, cell]));
    const touchedMonths = new Set(result.cells.map((cell) => cell.month));
    const touchedRows = new Set(result.cells.map((cell) => cell.plCategoryId));
    const columns = months.filter((month) => touchedMonths.has(month));

    return {
        months: columns,
        rows: rows.filter((row) => touchedRows.has(row.id)).map((row) => {
            const changes: string[] = [];
            const cells = columns.map((month): PreviewCell => {
                const cell = byKey.get(`${row.id}|${month}`);
                if (cell === undefined) {
                    return { month, amount: null, previous: row.values[month] ?? '0.00', overwrite: false };
                }
                changes.push(cell.amount, negate(cell.previous));

                return { month, amount: cell.amount, previous: cell.previous, overwrite: !isZero(cell.previous) };
            });

            return { id: row.id, name: row.name, cells, delta: addAmounts(changes) };
        }),
    };
}

/** «Изменится 12 ячеек, из них перезапишет заполненные: 12». */
export function previewLine(result: BudgetFillResult): string {
    if (result.changed === 0) {
        return 'Менять нечего: суммы уже такие.';
    }
    const head = `Изменится ${result.changed} ${plural(result.changed, 'ячейка', 'ячейки', 'ячеек')}`;

    return result.overwrite > 0 ? `${head}, из них перезапишет заполненные: ${result.overwrite}` : head;
}
