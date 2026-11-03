package executor

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

// errReturnsRestart — прерванный день возвратов: уникальность id проверяется
// по всем страницам дня, а id прежней попытки не восстановить — день заново
// (почти всегда одна страница).
var errReturnsRestart = errors.New("executor: interrupted returns day restarts from the first page")

// returns — возвраты дня МСК по дате логистического возврата постранично
// (ozon-10-returns.md); журнал — число возвратов этой попытки.
func (r *taskRun) returns(ctx context.Context, creds ozon.Credentials) error {
	if r.Cursor == "" && len(r.Hashes) > 0 {
		// Все страницы дня уже сохранены прежней попыткой.
		return nil
	}
	if r.Cursor != "" {
		return errReturnsRestart
	}
	from, err := time.ParseInLocation(time.DateOnly, r.Task.PeriodKey, r.exec.cfg.Location)
	if err != nil {
		return fmt.Errorf("%w: %q", errBadPeriod, r.Task.PeriodKey)
	}
	to := from.AddDate(0, 0, 1)
	count, pages := 0, 0
	var drift ozon.Drift
	defer func() { r.warnDrift(ozon.EndpointReturns, drift) }()
	err = r.exec.ozon.FetchReturns(ctx, creds, r.Task.ConnectionRef, from, to, r.Cursor, r.NextPage, func(p ozon.Page) error {
		if page, err := ozon.ParseReturnsPage(p.Body, nil, from, to); err == nil {
			count += page.Returns
			pages++
		}
		drift.Add(ozon.ReturnsDrift(p.Body))
		return r.Store(ctx, p)
	})
	if err != nil {
		return err
	}
	r.Log.Info("returns read", slog.String("stage", "fetch"), slog.Int("pages", pages), slog.Int("returns", count),
		slog.Bool("continued", r.Task.Cursor != ""))
	return nil
}
