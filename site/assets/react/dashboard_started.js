// Минимальный React bootstrap для демо на дашборде.
// Без JSX, чтобы работать без сборщика (importmap + browser ESM).
// Для установки зависимостей импортов локально выполните:
//   cd site
//   php bin/console importmap:require react react-dom/client
import React from 'react';
import { createRoot } from 'react-dom/client';

function mountReactStarted() {
  const el = document.getElementById('react-dashboard-started');
  if (!el) return;

  // Защита от повторного маунта (если скрипт вызовется повторно).
  if (el.__reactRoot) return;

  const root = createRoot(el);
  el.__reactRoot = root;

  root.render(
    React.createElement('h2', { className: 'm-0' }, 'React Started')
  );
}

// Важно: importmap-скрипт грузится в <head>, поэтому ждём DOM.
window.addEventListener('DOMContentLoaded', mountReactStarted);
