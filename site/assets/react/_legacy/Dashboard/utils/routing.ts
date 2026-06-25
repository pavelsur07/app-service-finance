import { showToast } from './toast';

export const PRESETS = ['day', 'week', 'month'] as const;

export const DRILLDOWN_ROUTES: Record<string, string> = {
  'cash.transactions': '/finance/cash-transactions/',
  'cash.balances': '/cash/balances',
  'funds.reserved': '/funds/reserved',
  'pl.documents': '/pl/documents',
  'pl.report': '/pl/report',
};

export interface DrilldownTarget {
  key?: string;
  params?: Record<string, unknown>;
}

interface DrilldownPayload {
  key?: string;
  params?: Record<string, unknown>;
  drilldown?: DrilldownTarget;
}

export function resolveDrilldown(payload: DrilldownPayload | null | undefined): DrilldownTarget {
  const key = payload?.drilldown?.key ?? payload?.key;
  const params = payload?.drilldown?.params ?? payload?.params;

  return { key, params };
}

export function MapsToDrilldown({ key, params }: DrilldownTarget): void {
  if (!key) {
    return;
  }

  const basePath = DRILLDOWN_ROUTES[key];
  if (!basePath) {
    showToast('Раздел в разработке');
    return;
  }

  const url = new URL(basePath, window.location.origin);
  if (params && typeof params === 'object') {
    Object.entries(params).forEach(([paramKey, value]) => {
      if (value === null || value === undefined || value === '') {
        return;
      }

      url.searchParams.set(paramKey, String(value));
    });
  }

  if (window.location.origin === url.origin) {
    window.location.assign(`${url.pathname}${url.search}`);
    return;
  }

  // TODO: переключить на Symfony route paths, когда drilldown-страницы будут доступны во всех окружениях.
  // eslint-disable-next-line no-console
  console.log('Drilldown target is not in current origin', key, params ?? {});
}
