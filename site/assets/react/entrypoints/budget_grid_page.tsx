import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BudgetGridWidget } from '@/react/modules/budget/features/budget-grid/BudgetGridWidget';
import type { BudgetGridProps } from '@/react/modules/budget/features/budget-grid/budget-grid.types';
import { ErrorBoundary } from '../_legacy/shared/ui/ErrorBoundary';

/** Остров `budget-grid` на экране варианта бюджета (templates/budget/show.html.twig). Только монтирование. */
function mount(): void {
    const element = document.querySelector<HTMLElement>('[data-island="budget-grid"]');
    const raw = element?.dataset.props;
    if (!element || raw === undefined || element.dataset.mounted === '1') {
        return;
    }
    element.dataset.mounted = '1';
    const props = JSON.parse(raw) as BudgetGridProps;

    createRoot(element).render(
        <StrictMode>
            <ErrorBoundary widgetName="Сетка бюджета">
                <BudgetGridWidget {...props} />
            </ErrorBoundary>
        </StrictMode>,
    );
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
} else {
    mount();
}
