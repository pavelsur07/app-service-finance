import { describe, expect, it } from 'vitest';
import { filterQuery } from './unitExtended.api';

const base = { marketplace: 'ozon', periodFrom: '2026-11-01', periodTo: '2026-11-09', tagIds: [], tagsMatchAll: true };

describe('filterQuery', () => {
    it('период и маркетплейс; без тегов tagsMatch не уходит', () => {
        expect(filterQuery(base).toString()).toBe('periodFrom=2026-11-01&periodTo=2026-11-09&marketplace=ozon');
    });

    it('пустой маркетплейс не отправляется, теги — tags[], «все теги» — tagsMatch=all', () => {
        const query = filterQuery({ ...base, marketplace: '', tagIds: ['a', 'b'] });
        expect(query.has('marketplace')).toBe(false);
        expect(query.getAll('tags[]')).toEqual(['a', 'b']);
        expect(query.get('tagsMatch')).toBe('all');
    });
});
