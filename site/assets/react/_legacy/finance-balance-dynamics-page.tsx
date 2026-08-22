import React from "react";
import { createRoot, type Root } from "react-dom/client";
import "../../styles/components/financial-chart.css";
import { BalanceDynamicsWidget } from "./finance-balance-dynamics/BalanceDynamicsWidget";
import { ErrorBoundary } from "./shared/ui/ErrorBoundary";

interface ReactMountElement extends HTMLElement {
    __reactRoot?: Root;
}

function mountBalanceDynamics(): void {
    const element = document.getElementById("finance-balance-dynamics-root") as ReactMountElement | null;
    if (!element || element.__reactRoot) {
        return;
    }

    const endpoint = element.dataset.endpoint;
    const currency = element.dataset.currency;
    if (!endpoint || !currency) {
        return;
    }

    const root = createRoot(element);
    element.__reactRoot = root;
    root.render(
        <ErrorBoundary widgetName="Динамика остатка на счетах">
            <BalanceDynamicsWidget endpoint={endpoint} currency={currency} />
        </ErrorBoundary>
    );
}

window.addEventListener("DOMContentLoaded", mountBalanceDynamics);
