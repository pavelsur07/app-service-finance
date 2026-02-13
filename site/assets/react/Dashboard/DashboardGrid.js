import React, { useCallback, useEffect, useMemo, useState } from 'react';

const PRESETS = ['day', 'week', 'month'];

function formatAmount(value) {
  const numericValue = Number(value ?? 0);

  return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(numericValue)} ₽`;
}

function resolveDrilldown(payload) {
  const key = payload?.drilldown?.key ?? payload?.key;
  const params = payload?.drilldown?.params ?? payload?.params;

  return { key, params };
}

function DrilldownButton({ payload, label = 'Подробнее' }) {
  const { key, params } = resolveDrilldown(payload);

  if (!key) {
    return null;
  }

  return React.createElement(
    'button',
    {
      type: 'button',
      className: 'btn btn-sm btn-outline-secondary mt-2',
      onClick: () => console.log(key, params ?? {}),
    },
    label,
  );
}

function KpiCard({ title, value, meta, payload }) {
  return React.createElement('div', { className: 'col-sm-6 col-lg-3' },
    React.createElement('div', { className: 'card' },
      React.createElement('div', { className: 'card-body' },
        React.createElement('div', { className: 'subheader' }, title),
        React.createElement('div', { className: 'h2 mb-1' }, formatAmount(value)),
        React.createElement('div', { className: 'text-muted small' }, meta),
        React.createElement(DrilldownButton, { payload }),
      ),
    ),
  );
}

function renderSkeleton() {
  return React.createElement('div', { className: 'card' },
    React.createElement('div', { className: 'card-body' },
      React.createElement('div', { className: 'placeholder-glow' },
        React.createElement('span', { className: 'placeholder col-4 mb-2' }),
        React.createElement('span', { className: 'placeholder col-8 mb-2' }),
        React.createElement('span', { className: 'placeholder col-6' }),
      ),
    ),
  );
}

export function DashboardGrid({ defaultPreset = 'month' }) {
  const [preset, setPreset] = useState(PRESETS.includes(defaultPreset) ? defaultPreset : 'month');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [retryTick, setRetryTick] = useState(0);

  const isCustom = customFrom !== '' && customTo !== '';

  const queryString = useMemo(() => {
    if (isCustom) {
      return `from=${encodeURIComponent(customFrom)}&to=${encodeURIComponent(customTo)}`;
    }

    return `preset=${encodeURIComponent(preset)}`;
  }, [isCustom, customFrom, customTo, preset]);

  const fetchSnapshot = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`/api/dashboard/v1/snapshot?${queryString}`, {
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = await response.json();
      setData(payload);
    } catch (fetchError) {
      setError(fetchError instanceof Error ? fetchError.message : 'Не удалось загрузить dashboard snapshot');
      setData(null);
    } finally {
      setLoading(false);
    }
  }, [queryString]);

  useEffect(() => {
    fetchSnapshot();
  }, [fetchSnapshot, retryTick]);

  const widgets = data?.widgets ?? null;

  if (loading) {
    return React.createElement('div', { className: 'row g-3' },
      React.createElement('div', { className: 'col-12' }, renderSkeleton()),
      React.createElement('div', { className: 'col-12' }, renderSkeleton()),
    );
  }

  if (error) {
    return React.createElement('div', { className: 'alert alert-danger' },
      React.createElement('div', { className: 'mb-2' }, `Ошибка загрузки: ${error}`),
      React.createElement('button', {
        type: 'button',
        className: 'btn btn-danger',
        onClick: () => setRetryTick((prev) => prev + 1),
      }, 'Повторить'),
    );
  }

  if (!widgets) {
    return React.createElement('div', { className: 'empty' },
      React.createElement('div', { className: 'empty-header' }, '—'),
      React.createElement('p', { className: 'empty-title' }, 'Нет данных для отображения'),
      React.createElement('p', { className: 'empty-subtitle text-secondary' }, 'Попробуйте сменить фильтр периода.'),
    );
  }

  const alerts = Array.isArray(widgets.alerts?.items) ? widgets.alerts.items.slice(0, 5) : [];
  const topCashItems = Array.isArray(widgets.top_cash?.items) ? widgets.top_cash.items : [];
  const topPnlItems = Array.isArray(widgets.top_pnl?.items) ? widgets.top_pnl.items : [];

  return React.createElement(React.Fragment, null,
    React.createElement('div', { className: 'card mb-3' },
      React.createElement('div', { className: 'card-body' },
        React.createElement('div', { className: 'row g-2 align-items-end' },
          React.createElement('div', { className: 'col-sm-4' },
            React.createElement('label', { className: 'form-label' }, 'Preset'),
            React.createElement('select', {
              className: 'form-select',
              value: preset,
              onChange: (event) => {
                setPreset(event.target.value);
                setCustomFrom('');
                setCustomTo('');
              },
            }, PRESETS.map((item) => React.createElement('option', { key: item, value: item }, item))),
          ),
          React.createElement('div', { className: 'col-sm-3' },
            React.createElement('label', { className: 'form-label' }, 'From'),
            React.createElement('input', {
              type: 'date',
              className: 'form-control',
              value: customFrom,
              onChange: (event) => {
                setCustomFrom(event.target.value);
                if (event.target.value && customTo) {
                  setPreset('month');
                }
              },
            }),
          ),
          React.createElement('div', { className: 'col-sm-3' },
            React.createElement('label', { className: 'form-label' }, 'To'),
            React.createElement('input', {
              type: 'date',
              className: 'form-control',
              value: customTo,
              onChange: (event) => {
                setCustomTo(event.target.value);
                if (customFrom && event.target.value) {
                  setPreset('month');
                }
              },
            }),
          ),
          React.createElement('div', { className: 'col-sm-2' },
            React.createElement('button', {
              type: 'button',
              className: 'btn btn-outline-secondary w-100',
              onClick: () => {
                setCustomFrom('');
                setCustomTo('');
              },
            }, 'Очистить custom'),
          ),
        ),
      ),
    ),
    React.createElement('div', { className: 'row row-cards g-3 mb-3' },
      React.createElement(KpiCard, { title: 'Free Cash', value: widgets.free_cash?.value, meta: `Δ ${widgets.free_cash?.delta_pct ?? 0}%`, payload: widgets.free_cash }),
      React.createElement(KpiCard, { title: 'Inflow', value: widgets.inflow?.sum, meta: `Среднее в день: ${formatAmount(widgets.inflow?.avg_daily)}`, payload: widgets.inflow }),
      React.createElement(KpiCard, { title: 'Outflow', value: widgets.outflow?.sum_abs, meta: `CAPEX: ${formatAmount(widgets.outflow?.capex_abs)}`, payload: widgets.outflow }),
      React.createElement(KpiCard, { title: 'Revenue', value: widgets.revenue?.value, meta: `Δ ${widgets.revenue?.delta_pct ?? 0}%`, payload: widgets.revenue }),
    ),
    React.createElement('div', { className: 'row row-cards g-3 mb-3' },
      React.createElement('div', { className: 'col-md-6' },
        React.createElement('div', { className: 'card' },
          React.createElement('div', { className: 'card-body' },
            React.createElement('h3', { className: 'card-title' }, 'Cash Flow Split'),
            React.createElement('ul', { className: 'list-unstyled mb-0' },
              ['operating', 'investing', 'financing', 'total'].map((key) => React.createElement('li', { key }, `${key}: ${formatAmount(widgets.cashflow_split?.[key]?.net)}`)),
            ),
            React.createElement(DrilldownButton, { payload: widgets.cashflow_split }),
          ),
        ),
      ),
      React.createElement('div', { className: 'col-md-6' },
        React.createElement('div', { className: 'card' },
          React.createElement('div', { className: 'card-body' },
            React.createElement('h3', { className: 'card-title' }, 'Profit Snapshot'),
            React.createElement('ul', { className: 'list-unstyled mb-0' },
              React.createElement('li', null, `Revenue: ${formatAmount(widgets.profit?.revenue)}`),
              React.createElement('li', null, `EBITDA: ${formatAmount(widgets.profit?.ebitda)}`),
              React.createElement('li', null, `Margin: ${widgets.profit?.margin_pct ?? 0}%`),
            ),
            React.createElement(DrilldownButton, { payload: widgets.profit }),
          ),
        ),
      ),
    ),
    React.createElement('div', { className: 'row row-cards g-3 mb-3' },
      React.createElement('div', { className: 'col-md-6' },
        React.createElement('div', { className: 'card' },
          React.createElement('div', { className: 'card-body' },
            React.createElement('h3', { className: 'card-title' }, 'Top Cash'),
            topCashItems.length === 0
              ? React.createElement('div', { className: 'text-secondary' }, 'Нет данных')
              : React.createElement('ul', { className: 'list-unstyled mb-0' },
                topCashItems.map((item) => React.createElement('li', { key: item.category_id || item.category_name }, `${item.category_name}: ${formatAmount(item.sum_abs)}`)),
              ),
          ),
        ),
      ),
      React.createElement('div', { className: 'col-md-6' },
        React.createElement('div', { className: 'card' },
          React.createElement('div', { className: 'card-body' },
            React.createElement('h3', { className: 'card-title' }, 'Top P&L'),
            topPnlItems.length === 0
              ? React.createElement('div', { className: 'empty' },
                React.createElement('p', { className: 'empty-title mb-1' }, 'Top P&L пока недоступен'),
                React.createElement('p', { className: 'empty-subtitle text-secondary mb-0' }, 'В snapshot отсутствует widgets.top_pnl.items'),
              )
              : React.createElement('ul', { className: 'list-unstyled mb-0' },
                topPnlItems.map((item, index) => React.createElement('li', { key: item.id || index }, `${item.name}: ${formatAmount(item.value)}`)),
              ),
          ),
        ),
      ),
    ),
    React.createElement('div', { className: 'row row-cards g-3' },
      React.createElement('div', { className: 'col-12' },
        React.createElement('div', { className: 'card' },
          React.createElement('div', { className: 'card-body' },
            React.createElement('h3', { className: 'card-title' }, 'Alerts'),
            alerts.length === 0
              ? React.createElement('div', { className: 'text-secondary' }, 'Нет активных алертов')
              : React.createElement('ul', { className: 'list-group list-group-flush' },
                alerts.map((alert, index) => React.createElement('li', { key: `${alert.code}-${index}`, className: 'list-group-item px-0' }, alert.code)),
              ),
          ),
        ),
      ),
    ),
  );
}
