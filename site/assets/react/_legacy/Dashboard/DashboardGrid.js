import React, { useCallback, useState } from 'react';
import { formatAmount } from './utils/formatters.ts';
import { PRESETS, MapsToDrilldown, resolveDrilldown } from './utils/routing.ts';
import { showToast } from './utils/toast.ts';
import { useDashboardSnapshot } from './api/useDashboardSnapshot.ts';

function DrilldownButton({ payload, label = 'Подробнее', onOpen }) {
  const { key, params } = resolveDrilldown(payload);

  if (!key) {
    return null;
  }

  return React.createElement(
    'button',
    {
      type: 'button',
      className: 'btn btn-sm btn-outline-secondary mt-2',
      onClick: (event) => {
        event.stopPropagation();
        onOpen({ key, params });
      },
    },
    label,
  );
}

function KpiCard({ title, value, meta, payload, onOpen }) {
  const { key } = resolveDrilldown(payload);

  return React.createElement('div', { className: 'col-sm-6 col-lg-3' },
    React.createElement('div', {
      className: `card${key ? ' cursor-pointer' : ''}`,
      role: key ? 'button' : undefined,
      tabIndex: key ? 0 : undefined,
      onClick: key ? () => onOpen(resolveDrilldown(payload)) : undefined,
      onKeyDown: key ? (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          onOpen(resolveDrilldown(payload));
        }
      } : undefined,
    },
      React.createElement('div', { className: 'card-body' },
        React.createElement('div', { className: 'subheader' }, title),
        React.createElement('div', { className: 'h2 mb-1' }, formatAmount(value)),
        React.createElement('div', { className: 'text-muted small' }, meta),
        React.createElement(DrilldownButton, {
          payload,
          onOpen: (drilldown) => {
            onOpen(drilldown);
          },
        }),
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

  const openDrilldown = useCallback((drilldown) => {
    if (!drilldown?.key) {
      return;
    }

    MapsToDrilldown(drilldown);
  }, []);

  const { data, loading, error, retry } = useDashboardSnapshot(preset, customFrom, customTo);

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
        onClick: retry,
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
      React.createElement(KpiCard, { title: 'Free Cash', value: widgets.free_cash?.value, meta: `Δ ${widgets.free_cash?.delta_pct ?? 0}%`, payload: widgets.free_cash, onOpen: openDrilldown }),
      React.createElement(KpiCard, { title: 'Inflow', value: widgets.inflow?.sum, meta: `Среднее в день: ${formatAmount(widgets.inflow?.avg_daily)}`, payload: widgets.inflow, onOpen: openDrilldown }),
      React.createElement(KpiCard, { title: 'Outflow', value: widgets.outflow?.sum_abs, meta: `CAPEX: ${formatAmount(widgets.outflow?.capex_abs)}`, payload: widgets.outflow, onOpen: openDrilldown }),
      React.createElement(KpiCard, { title: 'Revenue', value: widgets.revenue?.value, meta: `Δ ${widgets.revenue?.delta_pct ?? 0}%`, payload: widgets.revenue, onOpen: openDrilldown }),
    ),
    React.createElement('div', { className: 'row row-cards g-3 mb-3' },
      React.createElement('div', { className: 'col-md-6' },
        React.createElement('div', { className: 'card' },
          React.createElement('div', { className: 'card-body' },
            React.createElement('h3', { className: 'card-title' }, 'Cash Flow Split'),
            React.createElement('ul', { className: 'list-unstyled mb-0' },
              ['operating', 'investing', 'financing', 'total'].map((key) => React.createElement('li', { key }, `${key}: ${formatAmount(widgets.cashflow_split?.[key]?.net)}`)),
            ),
            React.createElement(DrilldownButton, { payload: widgets.cashflow_split, onOpen: openDrilldown }),
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
            React.createElement(DrilldownButton, { payload: widgets.profit, onOpen: openDrilldown }),
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
                topCashItems.map((item) => React.createElement('li', {
                  key: item.category_id || item.category_name,
                  className: item.drilldown?.key ? 'cursor-pointer' : '',
                  role: item.drilldown?.key ? 'button' : undefined,
                  tabIndex: item.drilldown?.key ? 0 : undefined,
                  onClick: item.drilldown?.key ? () => openDrilldown(item.drilldown) : undefined,
                  onKeyDown: item.drilldown?.key ? (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault();
                      openDrilldown(item.drilldown);
                    }
                  } : undefined,
                }, `${item.category_name}: ${formatAmount(item.sum_abs)}`)),
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
                topPnlItems.map((item, index) => React.createElement('li', {
                  key: item.id || index,
                  className: item.drilldown?.key ? 'cursor-pointer' : '',
                  role: item.drilldown?.key ? 'button' : undefined,
                  tabIndex: item.drilldown?.key ? 0 : undefined,
                  onClick: item.drilldown?.key ? () => openDrilldown(item.drilldown) : undefined,
                  onKeyDown: item.drilldown?.key ? (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault();
                      openDrilldown(item.drilldown);
                    }
                  } : undefined,
                }, `${item.name ?? item.category_name}: ${formatAmount(item.value ?? item.sum)}`)),
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
