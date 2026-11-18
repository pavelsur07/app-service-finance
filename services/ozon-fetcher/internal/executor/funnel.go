package executor

import (
	"context"
	"errors"
	"log/slog"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

// ResourceFunnel — воронка продаж по SKU за день МСК (period_key — день):
// показы, переходы в карточку, корзины, заказы и их исходы
// (docs/tasks/marketplace-funnel/TASK.md, этап 4). Доступна по тарифу кабинета.
const ResourceFunnel = "ozon.analytics.funnel"

// FunnelSkippedPage — служебная страница дня, который Ozon не отдал из-за
// тарифа кабинета: день считается пройденным (не ошибка), перечитывание хвоста
// пробует снова раз в сутки и заменит страницу данными, когда тариф появится.
const FunnelSkippedPage = `{"subscription_required":true}`

// errFunnelRestart — прерванный день воронки: повтор SKU между страницами
// проверяется в одной попытке — день заново (почти всегда одна страница).
var errFunnelRestart = errors.New("executor: interrupted funnel day restarts from the first page")

// errFunnelRefused — Ozon отказал в метриках дню, у которого уже есть ревизия с
// данными (тариф закончился либо метрика закрыта всем): данные не затираются
// служебной страницей, день — ошибка.
var errFunnelRefused = errors.New("executor: ozon refused funnel metrics for a day that already has data")

func (r *taskRun) funnel(ctx context.Context, creds ozon.Credentials) error {
	if r.Cursor == "" && len(r.Hashes) > 0 {
		// Все страницы дня уже сохранены прежней попыткой.
		return nil
	}
	if r.Cursor != "" {
		return errFunnelRestart
	}
	pages := 0
	rows, err := r.exec.ozon.FetchFunnel(ctx, creds, r.Task.ConnectionRef, r.Task.PeriodKey, r.NextPage, func(p ozon.Page) error {
		pages++
		return r.Store(ctx, p)
	})
	if ozon.IsSubscriptionRequired(err) && pages > 0 {
		// Тариф пропал между страницами: сохранённые страницы — не весь день.
		return errFunnelRestart
	}
	if errors.Is(err, ozon.ErrFunnelShifted) {
		// Порядок выдачи сдвинулся между страницами: день заново, не отказ.
		return errFunnelRestart
	}
	if ozon.IsSubscriptionRequired(err) && r.Task.ContentHash != "" &&
		r.Task.ContentHash != contentHash([]string{sha256Hex([]byte(FunnelSkippedPage))}) {
		return errFunnelRefused
	}
	if ozon.IsSubscriptionRequired(err) {
		r.Log.Info("funnel skipped: cabinet tariff has no funnel metrics", slog.String("stage", "fetch"))
		return r.Store(ctx, ozon.Page{No: r.NextPage, Body: []byte(FunnelSkippedPage), ContentType: "application/json"})
	}
	if err != nil {
		return err
	}
	r.Log.Info("funnel read", slog.String("stage", "fetch"), slog.Int("pages", pages), slog.Int("rows", rows))
	return nil
}
