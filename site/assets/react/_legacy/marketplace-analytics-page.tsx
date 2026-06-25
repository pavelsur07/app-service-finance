import React from 'react';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import type { MarketplaceOption } from './marketplace-analytics/types/analytics.types';
import UnitEconomicsWidget from './marketplace-analytics/widgets/UnitEconomicsWidget';

function mountMarketplaceAnalyticsPage() {
    const el = document.getElementById('react-marketplace-analytics');
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
        <ErrorBoundary widgetName="MarketplaceAnalytics">
            <UnitEconomicsWidget marketplaces={marketplaces} />
        </ErrorBoundary>
    );
}

window.addEventListener('DOMContentLoaded', mountMarketplaceAnalyticsPage);
