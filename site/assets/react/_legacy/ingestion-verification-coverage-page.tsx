import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { ErrorBoundary } from './shared/ui/ErrorBoundary';
import CoverageHeatmapWidget from './ingestion-verification/widgets/CoverageHeatmapWidget';

interface ReactMountElement extends HTMLElement {
    __reactRoot?: Root;
}

function mountIngestionVerificationCoveragePage(): void {
    const el = document.getElementById('ingestion-verification-coverage-root') as ReactMountElement | null;
    if (!el || el.__reactRoot) return;

    const root = createRoot(el);
    el.__reactRoot = root;

    root.render(
        <ErrorBoundary widgetName="IngestionVerificationCoverage">
            <CoverageHeatmapWidget />
        </ErrorBoundary>,
    );
}

window.addEventListener('DOMContentLoaded', mountIngestionVerificationCoveragePage);
