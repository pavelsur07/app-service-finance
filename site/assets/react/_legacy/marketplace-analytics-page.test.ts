import { afterEach, expect, it, vi } from 'vitest';
import { createRoot } from 'react-dom/client';
import UnitEconomicsWidget from './marketplace-analytics/widgets/UnitEconomicsWidget';

vi.mock('react-dom/client', () => ({ createRoot: vi.fn() }));

afterEach(() => {
    vi.unstubAllGlobals();
});

it('mounts unit economics with the marketplaces supplied by the page', async () => {
    const marketplaces = [{ value: 'ozon', label: 'Ozon' }];
    const container = { dataset: { marketplaces: JSON.stringify(marketplaces) } };
    const events = new EventTarget();
    const render = vi.fn();
    vi.mocked(createRoot).mockReturnValue({ render, unmount: vi.fn() });
    vi.stubGlobal('window', events);
    vi.stubGlobal('document', {
        getElementById: (id: string) => id === 'react-marketplace-analytics' ? container : null,
    });

    await import('./marketplace-analytics-page');
    events.dispatchEvent(new Event('DOMContentLoaded'));

    expect(createRoot).toHaveBeenCalledWith(container);
    expect(render).toHaveBeenCalledWith(expect.objectContaining({
        props: expect.objectContaining({
            children: expect.objectContaining({ type: UnitEconomicsWidget, props: { marketplaces } }),
        }),
    }));
});
