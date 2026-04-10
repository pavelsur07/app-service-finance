import React from "react";
import { createRoot } from "react-dom/client";
import { ErrorBoundary } from "./shared/ui/ErrorBoundary";
import ReconciliationWidget from "./reconciliation/widgets/ReconciliationWidget";

function mountReconciliationPage() {
    const el = document.getElementById("reconciliation-root");
    if (!el || (el as any).__reactRoot) return;

    const root = createRoot(el);
    (el as any).__reactRoot = root;

    root.render(
        <ErrorBoundary widgetName="Reconciliation">
            <ReconciliationWidget />
        </ErrorBoundary>,
    );
}

window.addEventListener("DOMContentLoaded", mountReconciliationPage);
