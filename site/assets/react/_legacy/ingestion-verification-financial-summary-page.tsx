import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import FinancialSummaryWidget from './ingestion-verification/widgets/FinancialSummaryWidget';

interface ReactMountElement extends HTMLElement {
    __reactRoot?: Root;
}

function mountIngestionVerificationFinancialSummaryPage(): void {
    const el = document.getElementById('ingestion-verification-financial-summary-root') as ReactMountElement | null;
    if (!el || el.__reactRoot) return;

    const root = createRoot(el);
    el.__reactRoot = root;

    root.render(
        <ErrorBoundary widgetName="IngestionVerificationFinancialSummary">
            <FinancialSummaryWidget />
        </ErrorBoundary>,
    );
}

window.addEventListener('DOMContentLoaded', mountIngestionVerificationFinancialSummaryPage);
