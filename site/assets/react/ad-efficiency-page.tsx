import React from 'react';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import type { MarketplaceOption } from './marketplace-ads/ad-efficiency/adEfficiency.types';
import AdEfficiencyPage from './marketplace-ads/ad-efficiency/AdEfficiencyPage';

function mountAdEfficiencyPage() {
    const el = document.getElementById('react-ad-efficiency');
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
        <ErrorBoundary widgetName="AdEfficiency">
            <AdEfficiencyPage marketplaces={marketplaces} />
        </ErrorBoundary>
    );
}

window.addEventListener('DOMContentLoaded', mountAdEfficiencyPage);
