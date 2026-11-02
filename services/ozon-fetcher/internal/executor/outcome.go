package executor

import (
	"errors"
	"log/slog"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/fetch"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

// labelSnapshotMissed — снимок за прошедший день не загружен (WARNING, не ERROR).
const labelSnapshotMissed = "snapshot_missed"

// labelHistoryUnavailable — день вне глубины истории Ozon (WARNING, не ERROR):
// данные ушли до загрузки, пропуск виден в покрытии.
const labelHistoryUnavailable = "history_unavailable"

// Ожидания отчётов рекламы — штатные исходы (INFO).
const (
	labelReportPending = "report_pending"
	labelReportWait    = "report_wait"
	labelReportBusy    = "report_busy"
)

// Classify — исходы ошибок Ozon, которых нет в общей таблице ядра
// (fetch.Adapter): зависимые ресурсы, снимки, отчёты и ключ рекламы, курсор
// Ozon после паузы.
func (e *Executor) Classify(c *fetch.Classification) (store.Outcome, bool) {
	task, run, err, now, log := c.Task, c.Run, c.Err, c.Now, c.Log
	if errors.Is(err, errOrdersNotLoaded) {
		return store.Outcome{Status: store.StatusNotReady, Label: "orders_not_loaded", ErrorClass: "orders_not_loaded", NextRunAt: now.Add(c.NotReadyDelay())}, true
	}
	if errors.Is(err, errOrdersRevised) {
		return c.Retryable("orders_revised", false), true
	}
	if errors.Is(err, errOrdersRawInvalid) {
		log.Error("stored orders page is not readable", slog.String("stage", "fetch"), slog.String("error", err.Error()))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "orders_raw_invalid", ErrorClass: "orders_raw_invalid", NextRunAt: now}, true
	}
	var wait *adsReportWait
	if errors.As(err, &wait) {
		return store.Outcome{Status: store.StatusPending, Label: labelReportWait, ErrorClass: labelReportWait, KeepCursor: true, NextRunAt: wait.at}, true
	}
	if errors.Is(err, errAdsReportBusy) {
		return store.Outcome{Status: store.StatusPending, Label: labelReportBusy, ErrorClass: labelReportBusy, KeepCursor: true, NextRunAt: now.Add(e.cfg.AdsReportBusy)}, true
	}
	if errors.Is(err, errAdsReportPending) {
		return store.Outcome{Status: store.StatusPending, Label: labelReportPending, ErrorClass: labelReportPending, KeepCursor: true, NextRunAt: now.Add(e.cfg.AdsReportBusy)}, true
	}
	if errors.Is(err, errAdsCampaignsNotLoaded) {
		return store.Outcome{Status: store.StatusPending, Label: "ads_campaigns_not_loaded", ErrorClass: "ads_campaigns_not_loaded", KeepCursor: true, NextRunAt: now.Add(e.cfg.AdsCampaignsWait)}, true
	}
	if errors.Is(err, errAdsCampaignsRevised) {
		return c.Retryable("ads_campaigns_revised", false), true
	}
	if errors.Is(err, errAdsReportFailed) {
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "report_failed", ErrorClass: "report_failed", NextRunAt: now}, true
	}
	if errors.Is(err, errAdsRawInvalid) {
		log.Error("stored ads campaigns page is not readable", slog.String("stage", "fetch"), slog.String("error", err.Error()))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "ads_raw_invalid", ErrorClass: "ads_raw_invalid", NextRunAt: now}, true
	}
	var early *snapshotEarlyError
	if errors.As(err, &early) {
		return store.Outcome{Status: store.StatusPending, Label: "snapshot_early", ErrorClass: "snapshot_early", KeepCursor: true, NextRunAt: early.at}, true
	}
	if errors.Is(err, errSnapshotMissed) {
		return store.Outcome{Status: store.StatusFailedPermanent, Label: labelSnapshotMissed, ErrorClass: labelSnapshotMissed, NextRunAt: now}, true
	}
	if errors.Is(err, errCatalogNotLoaded) {
		return store.Outcome{Status: store.StatusNotReady, Label: "catalog_not_loaded", ErrorClass: "catalog_not_loaded", NextRunAt: now.Add(c.NotReadyDelay())}, true
	}
	if errors.Is(err, errCatalogRevised) {
		return c.Retryable("catalog_revised", false), true
	}
	if errors.Is(err, errCatalogRawInvalid) {
		log.Error("stored product list page is not readable", slog.String("stage", "fetch"), slog.String("error", err.Error()))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "catalog_raw_invalid", ErrorClass: "catalog_raw_invalid", NextRunAt: now}, true
	}
	var fresh *dataEarlyError
	if errors.As(err, &fresh) {
		return store.Outcome{Status: store.StatusPending, Label: "data_early", ErrorClass: "data_early", KeepCursor: true, NextRunAt: fresh.at}, true
	}
	if errors.Is(err, errHistoryUnavailable) {
		return store.Outcome{Status: store.StatusFailedPermanent, Label: labelHistoryUnavailable, ErrorClass: labelHistoryUnavailable, NextRunAt: now}, true
	}
	if errors.Is(err, errSearchNotReady) {
		return store.Outcome{Status: store.StatusNotReady, Label: "search_not_ready", ErrorClass: "search_not_ready", KeepCursor: true, NextRunAt: now.Add(c.NotReadyDelay())}, true
	}
	if errors.Is(err, errSearchShifted) {
		return c.Retryable("search_shifted", false), true
	}
	if errors.Is(err, errSnapshotRestart) {
		return c.Retryable("snapshot_restart", false), true
	}
	if errors.Is(err, errStocksNotLoaded) {
		return store.Outcome{Status: store.StatusNotReady, Label: "stocks_not_loaded", ErrorClass: "stocks_not_loaded", NextRunAt: now.Add(c.NotReadyDelay())}, true
	}
	if errors.Is(err, errStocksRevised) {
		return c.Retryable("stocks_revised", false), true
	}
	if errors.Is(err, errStocksRawInvalid) {
		log.Error("stored stocks page is not readable", slog.String("stage", "fetch"), slog.String("error", err.Error()))
		return store.Outcome{Status: store.StatusFailedPermanent, Label: "stocks_raw_invalid", ErrorClass: "stocks_raw_invalid", NextRunAt: now}, true
	}
	if errors.Is(err, errReturnsRestart) {
		return c.Retryable("returns_restart", false), true
	}

	var apiErr *ozon.APIError
	if !errors.As(err, &apiErr) {
		return store.Outcome{}, false
	}
	switch {
	case apiErr.Class == ozon.ClassAuth && IsAdsResource(task.ResourceType):
		// Ключ Performance: пауза только этой задачи рекламы — продажи
		// кабинета идут; подряд второй отказ — длинная пауза + ERROR.
		c.ForceRefresh()
		pause := e.cfg.AuthRetryPause
		if task.LastErrorClass == string(ozon.ClassAuth) {
			pause = e.cfg.AuthSuspend
			log.Error("ozon performance rejected cabinet keys again, ads task paused",
				slog.String("stage", "fetch"), slog.Int("http_status", apiErr.Status),
				slog.Time("paused_until", now.Add(pause)))
		}
		return store.Outcome{Status: store.StatusFailedRetryable, Label: string(apiErr.Class), ErrorClass: string(apiErr.Class), HTTPStatus: apiErr.Status, KeepCursor: true, NextRunAt: now.Add(pause)}, true

	case apiErr.Class == ozon.ClassInvalidRequest &&
		(IsPostingResource(task.ResourceType) || task.ResourceType == ResourceProductsList || isCursorSnapshot(task.ResourceType)) &&
		(task.Cursor != "" || run.Cursor != ""):
		// Запрос шёл с курсором — прежней попытки (после паузы 429, backoff,
		// рестарта) или своей предыдущей страницы: курсор Ozon мог истечь.
		// Повтор окна с первой страницы, а не потеря дня.
		out := c.Retryable("stale_cursor", false)
		out.HTTPStatus = apiErr.Status
		return out, true
	}
	return store.Outcome{}, false
}

// ResumesConnection — успех с ключом seller сбрасывает счётчик отказов
// кабинета; у рекламы свой ключ — счётчик seller она не трогает.
func (e *Executor) ResumesConnection(resourceType string) bool { return !IsAdsResource(resourceType) }

// LogLevel — пропущенный снимок и день вне истории Ozon — следствие простоя,
// о котором уже сигналят (WARNING, не ERROR); ожидание отчёта рекламы — штатно.
func (e *Executor) LogLevel(out store.Outcome) (slog.Level, bool) {
	switch out.Label {
	case labelSnapshotMissed, labelHistoryUnavailable:
		return slog.LevelWarn, true
	case labelReportPending, labelReportWait, labelReportBusy:
		return slog.LevelInfo, true
	}
	return 0, false
}
