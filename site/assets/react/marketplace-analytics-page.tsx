import React from 'react';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import UnitEconomicsWidget from './marketplace-analytics/widgets/UnitEconomicsWidget';

function mountMarketplaceAnalyticsPage() {
    const el = document.getElementById('react-marketplace-analytics');
    if (!el || (el as any).__reactRoot) return;

    const defaultMarketplace = el.dataset.defaultMarketplace ?? 'wildberries';

    const root = createRoot(el);
    (el as any).__reactRoot = root;

    root.render(
        <ErrorBoundary widgetName="MarketplaceAnalytics">
            <UnitEconomicsWidget defaultMarketplace={defaultMarketplace} />
        </ErrorBoundary>
    );
}

window.addEventListener('DOMContentLoaded', mountMarketplaceAnalyticsPage);
