import React from 'react';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import type { MarketplaceOption } from './marketplace-analytics/types/analytics.types';
import type { ListingTag } from './marketplace-analytics/unit-extended/unitExtended.types';
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

    let tags: ListingTag[] = [];
    try {
        tags = JSON.parse(el.dataset.tags ?? '[]');
    } catch {
        tags = [];
    }

    const root = createRoot(el);
    (el as any).__reactRoot = root;

    root.render(
        <ErrorBoundary widgetName="UnitExtended">
            <UnitExtendedWidget marketplaces={marketplaces} tags={tags} />
        </ErrorBoundary>
    );
}

window.addEventListener('DOMContentLoaded', mountUnitExtendedPage);
