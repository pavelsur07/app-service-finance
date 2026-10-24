package executor

import (
	"context"
	"log/slog"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

// prices — снимок цен по cursor до пустой страницы, только в окне снимка дня
// (ozon-8-prices-warehouse-stocks.md). Журнал — число товаров этой попытки.
func (r *taskRun) prices(ctx context.Context, creds ozon.Credentials) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		// Все страницы уже сохранены прежней попыткой.
		return nil
	}
	items, total, pages := 0, int64(-1), 0
	var drift ozon.Drift
	// И при обрыве попытки (429 после изменённой страницы): продолжение её не перечитает.
	defer func() { r.warnDrift(ozon.EndpointPrices, drift) }()
	err := r.withinSnapshot(ctx, StocksWindowStart[r.task.ResourceType], func(ctx context.Context) error {
		return r.exec.ozon.FetchPrices(ctx, creds, r.task.ConnectionRef, r.cursor, r.nextPage, func(p ozon.Page) error {
			if page, err := ozon.ParsePricesPage(p.Body); err == nil {
				pages++
				items += page.Items
				if page.Items > 0 || total < 0 {
					total = page.Total
				}
				drift.Add(ozon.PricesDrift(p.Body))
			}
			return r.store(ctx, p)
		})
	})
	if err != nil {
		return err
	}
	r.log.Info("prices snapshot read", slog.String("stage", "fetch"), slog.Int("pages", pages),
		slog.Int("items", items), slog.Int64("total", total), slog.Bool("continued", r.task.Cursor != ""))
	if total >= 0 && r.task.Cursor == "" && int64(items) != total {
		r.log.Warn("prices snapshot: items differ from total", slog.String("stage", "fetch"),
			slog.Int("items", items), slog.Int64("total", total))
	}
	return nil
}

// warnDrift — один WARNING на задачу при изменении набора полей ответа
// (spec-ozon.md п. 14): только имена полей, raw сохранён как пришёл.
func (r *taskRun) warnDrift(endpoint string, d ozon.Drift) {
	if d.Empty() {
		return
	}
	r.log.Warn("ozon response shape changed", slog.String("stage", "fetch"), slog.String("endpoint", endpoint),
		slog.Any("unknown_fields", d.Unknown()), slog.Any("missing_fields", d.Missing()))
}
