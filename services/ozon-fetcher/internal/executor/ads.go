package executor

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozonperf"
)

// Реклама — Ozon Performance API, свой ключ и свой слот очереди кабинета
// (docs/tasks/marketplace-data-ingestion/stages/ozon-6-ads-sync.md).
const (
	// ResourceAdsCampaigns — список кампаний, снимок дня (period_key — день МСК загрузки).
	ResourceAdsCampaigns = "ozon.ads.campaigns"
	// ResourceAdsExpense — расход аккаунта за день (period_key — день МСК данных).
	ResourceAdsExpense = "ozon.ads.expense"
	// ResourceAdsDaily — дневная статистика по кампаниям за день.
	ResourceAdsDaily = "ozon.ads.daily"
)

// adsPrefix — семейство ресурсов рекламы (слот очереди, миграция 00006).
const adsPrefix = "ozon.ads."

// IsAdsResource — ресурс Performance API.
func IsAdsResource(resourceType string) bool {
	return strings.HasPrefix(resourceType, adsPrefix)
}

// Performance — клиент Ozon Performance API.
type Performance interface {
	FetchCampaigns(ctx context.Context, creds ozonperf.Credentials, connectionRef string) (ozon.Page, error)
	FetchExpense(ctx context.Context, creds ozonperf.Credentials, connectionRef, day string) (ozon.Page, error)
	FetchDaily(ctx context.Context, creds ozonperf.Credentials, connectionRef, day string) (ozon.Page, error)
}

// WithPerformance — копия с клиентом рекламы.
func (e *Executor) WithPerformance(p Performance) *Executor {
	clone := *e
	clone.perf = p
	return &clone
}

// fetchAds — ресурсы рекламы; ключ — Performance кабинета.
func (r *taskRun) fetchAds(ctx context.Context, cabinet connections.Cabinet) error {
	if cabinet.Performance == nil || r.exec.perf == nil {
		return errNoCredentials
	}
	if !cabinet.Supports(r.task.ResourceType) {
		return errResourceNotEnabled
	}
	creds := *cabinet.Performance
	ref := r.task.ConnectionRef
	perf := r.exec.perf
	switch r.task.ResourceType {
	case ResourceAdsCampaigns:
		if len(r.hashes) > 0 {
			return nil
		}
		return r.withinSnapshotDay(ctx, func(ctx context.Context) error {
			return r.single(ctx, func() (ozon.Page, error) { return perf.FetchCampaigns(ctx, creds, ref) })
		})
	case ResourceAdsExpense, ResourceAdsDaily:
		if _, err := time.Parse(time.DateOnly, r.task.PeriodKey); err != nil {
			return fmt.Errorf("%w: %q", errBadPeriod, r.task.PeriodKey)
		}
		if r.task.ResourceType == ResourceAdsExpense {
			return r.single(ctx, func() (ozon.Page, error) { return perf.FetchExpense(ctx, creds, ref, r.task.PeriodKey) })
		}
		return r.single(ctx, func() (ozon.Page, error) { return perf.FetchDaily(ctx, creds, ref, r.task.PeriodKey) })
	}
	return fmt.Errorf("%w: %s", errUnknownResource, r.task.ResourceType)
}
