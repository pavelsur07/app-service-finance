import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import IssuesListWidget from './ingestion-verification/widgets/IssuesListWidget';

interface ReactMountElement extends HTMLElement {
    __reactRoot?: Root;
}

function mountIngestionVerificationIssuesPage(): void {
    const el = document.getElementById('ingestion-verification-issues-root') as ReactMountElement | null;
    if (!el || el.__reactRoot) return;

    const root = createRoot(el);
    el.__reactRoot = root;

    root.render(
        <ErrorBoundary widgetName="IngestionVerificationIssues">
            <IssuesListWidget />
        </ErrorBoundary>,
    );
}

window.addEventListener('DOMContentLoaded', mountIngestionVerificationIssuesPage);
