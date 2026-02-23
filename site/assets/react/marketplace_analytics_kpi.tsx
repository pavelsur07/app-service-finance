import React from 'react';
import { createRoot } from 'react-dom/client';
import { KpiRevenueWidget } from './marketplace-analytics/widgets/KpiRevenueWidget';

/**
 * Entry point для виджетов KPI аналитики маркетплейса
 * Монтирует React виджеты в DOM элементы по ID
 */
function mountMarketplaceAnalyticsKpi() {
    // Виджет 1: Выручка
    const revenueEl = document.getElementById('react-kpi-revenue');
    if (revenueEl && !revenueEl.__reactRoot) {
        const marketplace = revenueEl.dataset.marketplace || 'all';
        const locale = revenueEl.dataset.locale || 'ru';
        
        const root = createRoot(revenueEl);
        revenueEl.__reactRoot = root;
        
        root.render(<KpiRevenueWidget marketplace={marketplace} locale={locale} />);
    }
    
    // TODO: Виджеты 2-6 будут добавлены в следующих PR
    // const marginEl = document.getElementById('react-kpi-margin');
    // const unitsEl = document.getElementById('react-kpi-units');
    // etc...
}

window.addEventListener('DOMContentLoaded', mountMarketplaceAnalyticsKpi);
