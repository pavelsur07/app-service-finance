import React from 'react';
import { createRoot } from 'react-dom/client';
// Обрати внимание: мы убрали расширение .js в конце. Vite сам найдет .tsx файл!
import { DashboardGrid } from './Dashboard/DashboardGrid';

function mountReactStarted() {
    const el = document.getElementById('react-dashboard-started');
    if (!el || el.__reactRoot) {
        return;
    }

    const defaultPreset = el.dataset.defaultPreset || 'month';
    const root = createRoot(el);
    el.__reactRoot = root;

    // Смотри, как чисто! Вместо React.createElement используем нормальный JSX
    root.render(<DashboardGrid defaultPreset={defaultPreset} />);
}

window.addEventListener('DOMContentLoaded', mountReactStarted);
