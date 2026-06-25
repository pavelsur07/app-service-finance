import type { DrilldownTarget } from './utils/routing';

export interface DashboardWidgetBase {
  drilldown?: DrilldownTarget;
}

export interface DashboardMetricWidget extends DashboardWidgetBase {
  value?: number;
  delta_pct?: number;
}

export interface DashboardInflowWidget extends DashboardWidgetBase {
  sum?: number;
  avg_daily?: number;
}

export interface DashboardOutflowWidget extends DashboardWidgetBase {
  sum_abs?: number;
  capex_abs?: number;
}

export interface DashboardCashflowSplitItem {
  net?: number;
}

export interface DashboardCashflowSplitWidget extends DashboardWidgetBase {
  operating?: DashboardCashflowSplitItem;
  investing?: DashboardCashflowSplitItem;
  financing?: DashboardCashflowSplitItem;
  total?: DashboardCashflowSplitItem;
}

export interface DashboardProfitWidget extends DashboardWidgetBase {
  revenue?: number;
  ebitda?: number;
  margin_pct?: number;
}

export interface DashboardTopItem {
  id?: string | number;
  category_id?: string | number;
  category_name?: string;
  name?: string;
  sum_abs?: number;
  value?: number;
  sum?: number;
  drilldown?: DrilldownTarget;
}

export interface DashboardTopWidget extends DashboardWidgetBase {
  items?: DashboardTopItem[];
}

export interface DashboardAlertItem {
  code: string;
}

export interface DashboardAlertsWidget {
  items?: DashboardAlertItem[];
}

export interface DashboardWidgetData {
  free_cash?: DashboardMetricWidget;
  inflow?: DashboardInflowWidget;
  outflow?: DashboardOutflowWidget;
  revenue?: DashboardMetricWidget;
  cashflow_split?: DashboardCashflowSplitWidget;
  profit?: DashboardProfitWidget;
  top_cash?: DashboardTopWidget;
  top_pnl?: DashboardTopWidget;
  alerts?: DashboardAlertsWidget;
}

export interface DashboardSnapshotResponse {
  widgets?: DashboardWidgetData;
}
