import React from 'react';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import type { MarketplaceOption } from './marketplace-analytics/types/analytics.types';
import UnitExtendedWidget from './marketplace-analytics/unit-extended/UnitExtendedWidget';

function mountUnitExtendedPage() {
    const el = document.getElementById('react-unit-extended');
    if (!el || (el as any).__reactRoot) return;

    let marketplaces: MarketplaceOption[] = [];
    try {
        marketplaces = JSON.parse(el.dataset.marketplaces ?? '[]');
    } catch {
        marketplaces = [];
    }

    const root = createRoot(el);
    (el as any).__reactRoot = root;

    root.render(
        <ErrorBoundary widgetName="UnitExtended">
            <UnitExtendedWidget marketplaces={marketplaces} />
        </ErrorBoundary>
    );
}

window.addEventListener('DOMContentLoaded', mountUnitExtendedPage);
