package executor

import (
	"context"
	"fmt"
	"log/slog"
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
	OrderReport(ctx context.Context, creds ozonperf.Credentials, connectionRef string, campaigns []string, from, to time.Time) (string, error)
	ReportStatus(ctx context.Context, creds ozonperf.Credentials, connectionRef, id string) (ozonperf.ReportStatus, error)
	DownloadReport(ctx context.Context, creds ozonperf.Credentials, connectionRef, id, link string) (ozon.Page, error)
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
	if !cabinet.Supports(r.Task.ResourceType) {
		return errResourceNotEnabled
	}
	creds := *cabinet.Performance
	ref := r.Task.ConnectionRef
	perf := r.exec.perf
	switch r.Task.ResourceType {
	case ResourceAdsCampaigns:
		if len(r.Hashes) > 0 {
			return nil
		}
		return r.withinSnapshotDay(ctx, func(ctx context.Context) error {
			return r.Single(ctx, r.logShape(func() (ozon.Page, error) { return perf.FetchCampaigns(ctx, creds, ref) }))
		})
	case ResourceAdsExpense, ResourceAdsDaily:
		if _, err := time.Parse(time.DateOnly, r.Task.PeriodKey); err != nil {
			return fmt.Errorf("%w: %q", errBadPeriod, r.Task.PeriodKey)
		}
		if r.Task.ResourceType == ResourceAdsExpense {
			return r.Single(ctx, r.logShape(func() (ozon.Page, error) { return perf.FetchExpense(ctx, creds, ref, r.Task.PeriodKey) }))
		}
		return r.Single(ctx, r.logShape(func() (ozon.Page, error) { return perf.FetchDaily(ctx, creds, ref, r.Task.PeriodKey) }))
	case ResourceAdsStatistics, ResourceAdsStatisticsTail:
		return r.adsReports(ctx, creds, cabinet)
	}
	return fmt.Errorf("%w: %s", errUnknownResource, r.Task.ResourceType)
}

// logShape пишет в журнал имена полей ответа (без значений): живая форма
// ответов рекламы снимается на шаге «один кабинет».
func (r *taskRun) logShape(fetch func() (ozon.Page, error)) func() (ozon.Page, error) {
	return func() (ozon.Page, error) {
		page, err := fetch()
		if err == nil {
			r.Log.Info("ads page shape", slog.String("stage", "fetch"), slog.String("shape", ozonperf.Shape(page.Body)),
				slog.Int("bytes", len(page.Body)), slog.String("content_type", page.ContentType))
		}
		return page, err
	}
}
