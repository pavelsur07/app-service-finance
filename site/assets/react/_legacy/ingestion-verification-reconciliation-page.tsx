import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import ReconciliationSummaryWidget from './ingestion-verification/widgets/ReconciliationSummaryWidget';

interface ReactMountElement extends HTMLElement {
    __reactRoot?: Root;
}

function mountIngestionVerificationReconciliationPage(): void {
    const el = document.getElementById('ingestion-verification-reconciliation-root') as ReactMountElement | null;
    if (!el || el.__reactRoot) return;

    const root = createRoot(el);
    el.__reactRoot = root;

    root.render(
        <ErrorBoundary widgetName="IngestionVerificationReconciliation">
            <ReconciliationSummaryWidget />
        </ErrorBoundary>,
    );
}

window.addEventListener('DOMContentLoaded', mountIngestionVerificationReconciliationPage);
