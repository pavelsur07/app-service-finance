/**
 * Отладка Ozon Ads (templates/marketplace_ads/admin_debug.html.twig): кнопки запрашивают
 * отладочные эндпоинты по выбранной компании и показывают JSON-ответ и бейджи сводки.
 * Корень — [data-ozon-ads-debug].
 */
const pretty = (value) => {
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
};

const renderResult = (elId, payload, okFlag) => {
  const el = document.getElementById(elId);
  el.textContent = pretty(payload);
  el.style.borderLeft = okFlag ? '4px solid var(--vf-ok-text)' : '4px solid var(--vf-danger-text)';
};

const withBusy = (btn, cb) => {
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = 'Выполняется...';
  Promise.resolve(cb()).finally(() => {
    btn.disabled = false;
    btn.innerHTML = original;
  });
};

const doFetch = (url) => fetch(url, {
  credentials: 'same-origin',
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
}).then((r) => r.json()
  .then((data) => ({ ok: r.ok, status: r.status, data }))
  .catch(() => ({ ok: r.ok, status: r.status, data: { error: 'Invalid JSON response' } })));

const badge = (className, text) => {
  const el = document.createElement('span');
  el.className = className;
  el.textContent = text;
  return el;
};

export function initOzonAdsDebug() {
  const root = document.querySelector('[data-ozon-ads-debug]');
  const companySel = root?.querySelector('#debug-company-id');
  if (!companySel) return;
  const companyId = () => encodeURIComponent(companySel.value);

  document.getElementById('btn-fetch-campaigns').addEventListener('click', (e) => {
    withBusy(e.currentTarget, () => doFetch(`/debug/ozon-ads/all-campaigns?companyId=${companyId()}`).then((res) => {
      renderResult('result-campaigns', res.data, res.ok);
      const summary = document.getElementById('campaigns-summary');
      summary.innerHTML = '';
      if (!res.ok || !res.data) return;
      summary.appendChild(badge('badge bg-secondary me-2', `Всего кампаний: ${res.data.total_campaigns || 0}`));
      Object.entries(res.data.by_state || {}).forEach(([key, value]) => {
        summary.appendChild(badge('badge me-2 bg-info', `${key}: ${value}`));
      });
    }));
  });

  document.getElementById('btn-fetch-reports-list').addEventListener('click', (e) => {
    withBusy(e.currentTarget, () => doFetch(`/debug/ozon-ads/list-reports?companyId=${companyId()}`).then((res) => {
      renderResult('result-reports-list', res.data, res.ok);
      const summary = document.getElementById('reports-list-summary');
      summary.innerHTML = '';
      if (!res.ok || !res.data) return;
      summary.appendChild(badge('badge bg-secondary me-2', `Собрано из Ozon: ${res.data.total_items_collected || 0}`));
      const pending = (res.data.our_uuids || []).filter((item) => item && item.found_in_list).length;
      summary.appendChild(badge(`badge me-2 ${pending > 0 ? 'bg-warning' : 'bg-success'}`, `Наши UUID в списке Ozon: ${pending}`));
      summary.appendChild(badge('badge bg-info me-2', `Страниц опрошено: ${(res.data.list_pages || []).length}`));
    }));
  });
}
