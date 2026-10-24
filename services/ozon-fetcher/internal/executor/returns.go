package executor

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

// returns — возвраты дня МСК по дате логистического возврата постранично
// (ozon-10-returns.md); журнал — число возвратов этой попытки.
func (r *taskRun) returns(ctx context.Context, creds ozon.Credentials) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		// Все страницы дня уже сохранены прежней попыткой.
		return nil
	}
	from, err := time.ParseInLocation(time.DateOnly, r.task.PeriodKey, r.exec.cfg.Location)
	if err != nil {
		return fmt.Errorf("%w: %q", errBadPeriod, r.task.PeriodKey)
	}
	to := from.AddDate(0, 0, 1)
	count, pages := 0, 0
	var drift ozon.Drift
	defer func() { r.warnDrift(ozon.EndpointReturns, drift) }()
	err = r.exec.ozon.FetchReturns(ctx, creds, r.task.ConnectionRef, from, to, r.cursor, r.nextPage, func(p ozon.Page) error {
		if page, err := ozon.ParseReturnsPage(p.Body, nil, from, to); err == nil {
			count += page.Returns
			pages++
		}
		drift.Add(ozon.ReturnsDrift(p.Body))
		return r.store(ctx, p)
	})
	if err != nil {
		return err
	}
	r.log.Info("returns read", slog.String("stage", "fetch"), slog.Int("pages", pages), slog.Int("returns", count),
		slog.Bool("continued", r.task.Cursor != ""))
	return nil
}
