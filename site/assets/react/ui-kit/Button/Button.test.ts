import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { Button } from './Button';
import { cn } from '../../shared/lib/cn';

describe('shared UI compatibility with the existing React 18 application', () => {
    it('keeps button styling, loading and native attributes', () => {
        const html = renderToStaticMarkup(createElement(Button, {
            variant: 'secondary', size: 'sm', loading: true, className: 'extra',
            'aria-label': 'Сохранить', children: 'Сохранение',
        }));
        expect(html).toContain('class="btn btn-secondary btn-sm btn-loading extra"');
        expect(html).toContain('disabled=""');
        expect(html).toContain('aria-busy="true"');
        expect(html).toContain('aria-label="Сохранить"');
        expect(html).toContain('type="button"');
    });
    it('keeps the class helper public import', () => {
        expect(cn('btn', undefined, false, null, 0, 'extra')).toBe('btn extra');
    });
});
