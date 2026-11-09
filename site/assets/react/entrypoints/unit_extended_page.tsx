import { createRoot } from 'react-dom/client';
import UnitExtendedWidget from '@/react/modules/marketplace-analytics/features/unit-extended/UnitExtendedWidget';
import type { ListingTag, MarketplaceOption } from '@/react/modules/marketplace-analytics/features/unit-extended/unitExtended.types';
import { ErrorBoundary } from '@/react/shared/ui/ErrorBoundary';

function parseList<T>(raw: string | undefined): T[] {
    try {
        const value: unknown = JSON.parse(raw ?? '[]');

        return Array.isArray(value) ? (value as T[]) : [];
    } catch {
        return [];
    }
}

/** Остров «Unit — Экономика» (templates/marketplace_analytics/unit_extended/index.html.twig). Только монтирование. */
function mount(): void {
    const element = document.getElementById('react-unit-extended');
    if (!element || element.dataset.mounted === '1') {
        return;
    }
    element.dataset.mounted = '1';

    createRoot(element).render(
        <ErrorBoundary widgetName="UnitExtended">
            <UnitExtendedWidget
                marketplaces={parseList<MarketplaceOption>(element.dataset.marketplaces)}
                tags={parseList<ListingTag>(element.dataset.tags)}
            />
        </ErrorBoundary>,
    );
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
} else {
    mount();
}
