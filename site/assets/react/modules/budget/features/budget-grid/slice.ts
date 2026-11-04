import type { BudgetPair, SelectedPair } from './budget-grid.types';

/** Разрез сетки: `null` — все по измерению. Обе заданы — активная пара (ввод), иначе сумма (просмотр). */
export interface BudgetSlice {
    project: string | null;
    center: string | null;
}

export interface SliceOption {
    id: string;
    name: string;
}

export const sliceToPair = (slice: BudgetSlice): SelectedPair | null =>
    slice.project !== null && slice.center !== null
        ? { projectDirectionId: slice.project, responsibilityCenterId: slice.center }
        : null;

const unique = (options: SliceOption[]): SliceOption[] => {
    const seen = new Map<string, SliceOption>();
    for (const option of options) {
        if (!seen.has(option.id)) {
            seen.set(option.id, option);
        }
    }

    return [...seen.values()];
};

/** Проекты, допустимые при выбранном ЦФО (все — если ЦФО «все»). */
export const projectOptions = (pairs: BudgetPair[], center: string | null): SliceOption[] =>
    unique(pairs.filter((pair) => center === null || pair.responsibilityCenterId === center)
        .map((pair) => ({ id: pair.projectDirectionId, name: pair.projectName })));

/** ЦФО, допустимые при выбранном проекте (все — если проект «все»). */
export const centerOptions = (pairs: BudgetPair[], project: string | null): SliceOption[] =>
    unique(pairs.filter((pair) => project === null || pair.projectDirectionId === project)
        .map((pair) => ({ id: pair.responsibilityCenterId, name: pair.centerName })));

const defaultSlice = (pairs: BudgetPair[]): BudgetSlice => {
    const chosen = pairs.find((pair) => pair.system) ?? pairs[0];

    return chosen === undefined
        ? { project: null, center: null }
        : { project: chosen.projectDirectionId, center: chosen.responsibilityCenterId };
};

/**
 * Разрез из адреса (`?project=…&center=…`, `all` — все): обе части должны быть допустимы, иначе
 * пара по умолчанию — системная «Общий × Общий» или первая. У компании с одной парой — она.
 * Старый адрес `?all=1` — сумма всех пар.
 */
export function initialSlice(pairs: BudgetPair[], search: string): BudgetSlice {
    if (pairs.length <= 1) {
        return defaultSlice(pairs);
    }
    const query = new URLSearchParams(search);
    if (query.get('all') === '1') {
        return { project: null, center: null };
    }
    const project = query.get('project');
    const center = query.get('center');
    if (project === null || center === null) {
        return defaultSlice(pairs);
    }
    const slice: BudgetSlice = { project: project === 'all' ? null : project, center: center === 'all' ? null : center };
    const valid = pairs.some((pair) => (slice.project === null || pair.projectDirectionId === slice.project)
        && (slice.center === null || pair.responsibilityCenterId === slice.center));

    return valid ? slice : defaultSlice(pairs);
}

/** Параметры адреса и запроса сетки: `all` — все по измерению. */
export const sliceParams = (slice: BudgetSlice): Record<string, string> => ({
    project: slice.project ?? 'all',
    center: slice.center ?? 'all',
});

export const sliceKey = (slice: BudgetSlice): string => `${slice.project ?? 'all'}|${slice.center ?? 'all'}`;

/** Подпись суммы для подсказки режима просмотра. */
export function sliceSumLabel(pairs: BudgetPair[], slice: BudgetSlice): string {
    if (slice.project !== null) {
        const name = pairs.find((pair) => pair.projectDirectionId === slice.project)?.projectName ?? '';

        return `по проекту «${name}», все ЦФО`;
    }
    if (slice.center !== null) {
        const name = pairs.find((pair) => pair.responsibilityCenterId === slice.center)?.centerName ?? '';

        return `по ЦФО «${name}», все проекты`;
    }

    return 'по всем проектам и ЦФО';
}
