import { describe, expect, it } from 'vitest';
import type { BudgetPair } from './budget-grid.types';
import { centerOptions, initialSlice, projectOptions, sliceKey, sliceParams, sliceSumLabel, sliceToPair } from './slice';

const pair = (project: string, center: string, system = false): BudgetPair => ({
    projectDirectionId: project,
    responsibilityCenterId: center,
    label: `${project} × ${center}`,
    projectName: `Проект ${project}`,
    centerName: `ЦФО ${center}`,
    system,
});

// Общий×Общий (системная), WB×Общий, WB×Склад, Сайт×Склад.
const PAIRS = [pair('wb', 'gen'), pair('gen', 'gen', true), pair('wb', 'stock'), pair('site', 'stock')];

describe('initialSlice', () => {
    it('по умолчанию — системная пара, иначе первая', () => {
        expect(initialSlice(PAIRS, '')).toEqual({ project: 'gen', center: 'gen' });
        expect(initialSlice([pair('a', 'b'), pair('c', 'd')], '')).toEqual({ project: 'a', center: 'b' });
    });

    it('пара и одно измерение из адреса', () => {
        expect(initialSlice(PAIRS, '?project=wb&center=stock')).toEqual({ project: 'wb', center: 'stock' });
        expect(initialSlice(PAIRS, '?project=wb&center=all')).toEqual({ project: 'wb', center: null });
        expect(initialSlice(PAIRS, '?project=all&center=stock')).toEqual({ project: null, center: 'stock' });
        expect(initialSlice(PAIRS, '?project=all&center=all')).toEqual({ project: null, center: null });
    });

    it('старый адрес ?all=1 — сумма всех пар', () => {
        expect(initialSlice(PAIRS, '?all=1')).toEqual({ project: null, center: null });
    });

    it('недопустимое — пара по умолчанию', () => {
        expect(initialSlice(PAIRS, '?project=site&center=gen')).toEqual({ project: 'gen', center: 'gen' });
        expect(initialSlice(PAIRS, '?project=nope&center=all')).toEqual({ project: 'gen', center: 'gen' });
        expect(initialSlice(PAIRS, '?project=wb')).toEqual({ project: 'gen', center: 'gen' });
    });

    it('одна пара — она, адрес не читается; пар нет — сумма', () => {
        expect(initialSlice([pair('a', 'b')], '?project=all&center=all')).toEqual({ project: 'a', center: 'b' });
        expect(initialSlice([], '')).toEqual({ project: null, center: null });
    });
});

describe('взаимное ограничение', () => {
    it('проекты при выбранном ЦФО — только из пар, без повторов', () => {
        expect(projectOptions(PAIRS, 'stock').map((o) => o.id)).toEqual(['wb', 'site']);
        expect(projectOptions(PAIRS, null).map((o) => o.id)).toEqual(['wb', 'gen', 'site']);
    });

    it('ЦФО при выбранном проекте', () => {
        expect(centerOptions(PAIRS, 'wb')).toEqual([{ id: 'gen', name: 'ЦФО gen' }, { id: 'stock', name: 'ЦФО stock' }]);
        expect(centerOptions(PAIRS, 'site').map((o) => o.id)).toEqual(['stock']);
    });
});

describe('разрез → пара, адрес, подпись', () => {
    it('пара только при обоих измерениях', () => {
        expect(sliceToPair({ project: 'wb', center: 'gen' })).toEqual({ projectDirectionId: 'wb', responsibilityCenterId: 'gen' });
        expect(sliceToPair({ project: 'wb', center: null })).toBeNull();
    });

    it('«все» — all в адресе и ключе', () => {
        expect(sliceParams({ project: null, center: 'gen' })).toEqual({ project: 'all', center: 'gen' });
        expect(sliceKey({ project: 'wb', center: null })).toBe('wb|all');
    });

    it('подпись суммы', () => {
        expect(sliceSumLabel(PAIRS, { project: 'wb', center: null })).toBe('по проекту «Проект wb», все ЦФО');
        expect(sliceSumLabel(PAIRS, { project: null, center: 'stock' })).toBe('по ЦФО «ЦФО stock», все проекты');
        expect(sliceSumLabel(PAIRS, { project: null, center: null })).toBe('по всем проектам и ЦФО');
    });
});
