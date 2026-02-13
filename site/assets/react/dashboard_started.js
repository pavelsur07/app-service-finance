import React from 'react';
import { createRoot } from 'react-dom/client';
import { DashboardGrid } from './Dashboard/DashboardGrid.js';

function mountReactStarted() {
  const el = document.getElementById('react-dashboard-started');
  if (!el || el.__reactRoot) {
    return;
  }

  const defaultPreset = el.dataset.defaultPreset || 'month';
  const root = createRoot(el);
  el.__reactRoot = root;

  root.render(React.createElement(DashboardGrid, { defaultPreset }));
}

window.addEventListener('DOMContentLoaded', mountReactStarted);
