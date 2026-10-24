package executor

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strconv"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

// Поисковая аналитика (ozon-9-search-analytics.md §3).
const (
	// SearchLagDays — день доступен с D−2: вчера Ozon отвечает «нет данных».
	SearchLagDays = 2
	// SearchHistoryDays — «нет данных» за день старше D−30 — история ушла
	// (глубина 24.10.2026 — D−33); младше — ещё не посчитан.
	SearchHistoryDays = 30
)

// emptySearchPage — служебная страница дня без SKU (Ozon отвергает запрос без
// skus) в форме выдачи ресурса.
func emptySearchPage(details bool) string {
	if details {
		return `{"queries":[]}`
	}
	return `{"items":[]}`
}

// dataEarlyError — день ещё не доступен в Ozon: задача ждёт at.
type dataEarlyError struct{ at time.Time }

func (e *dataEarlyError) Error() string {
	return "executor: data of the day is available from " + e.at.Format(time.RFC3339)
}

var (
	errHistoryUnavailable = errors.New("executor: day is outside ozon analytics history")
	errSearchNotReady     = errors.New("executor: ozon has no analytics for the day yet")
	// errSearchShifted — page_count порции изменился между страницами (Ozon
	// пересчитал день): страницы разных выдач не смешиваются, день заново.
	errSearchShifted = errors.New("executor: search analytics page count changed during the load")
)

// searchCursor — «отпечаток SKU:порция:страница:page_count»; последняя страница — «отпечаток:end».
type searchCursor struct {
	chunk, page, pageCount int
}

func parseSearchCursor(cursor, fp string, chunks int) (searchCursor, error) {
	parts := strings.Split(cursor, ":")
	if len(parts) != 4 || parts[0] != fp {
		return searchCursor{}, errStocksRevised
	}
	var c searchCursor
	var err1, err2, err3 error
	c.chunk, err1 = strconv.Atoi(parts[1])
	c.page, err2 = strconv.Atoi(parts[2])
	c.pageCount, err3 = strconv.Atoi(parts[3])
	if err1 != nil || err2 != nil || err3 != nil || c.chunk < 0 || c.chunk >= chunks || c.page < 0 || c.pageCount < 0 ||
		(c.pageCount > 0 && c.page >= c.pageCount) || (c.pageCount == 0 && c.page != 0) {
		return searchCursor{}, errStocksRevised
	}
	return c, nil
}

// searchAnalytics — сводка или топ запросов по SKU за день МСК: SKU снимка
// ozon.stocks дня исполнения порциями по ozon.MaxSearchSKUs, страницы порции
// до page_count.
func (r *taskRun) searchAnalytics(ctx context.Context, creds ozon.Credentials, cabinet connections.Cabinet) error {
	details := r.task.ResourceType == ResourceSearchDetails
	endpoint := ozon.EndpointSearchQueries
	if details {
		endpoint = ozon.EndpointSearchQueriesDetails
	}
	loc := r.exec.cfg.Location
	day, err := time.ParseInLocation(time.DateOnly, r.task.PeriodKey, loc)
	if err != nil {
		return fmt.Errorf("%w: %q", errBadPeriod, r.task.PeriodKey)
	}
	now := r.exec.now().In(loc)
	today := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, loc)
	if day.After(today.AddDate(0, 0, -SearchLagDays)) {
		return &dataEarlyError{at: day.AddDate(0, 0, SearchLagDays)}
	}

	skus, err := r.executionDaySKUs(ctx, cabinet, today)
	if err != nil {
		return err
	}
	keys := make([]string, len(skus))
	for i, sku := range skus {
		keys[i] = strconv.FormatInt(sku, 10)
	}
	fp := numbersFingerprint(keys)
	end := fp + ":" + warehouseEnd
	chunks := (len(skus) + ozon.MaxSearchSKUs - 1) / ozon.MaxSearchSKUs

	at := searchCursor{pageCount: -1}
	switch {
	case r.cursor == end:
		return nil
	case r.cursor != "":
		if at, err = parseSearchCursor(r.cursor, fp, chunks); err != nil {
			return err
		}
	case len(r.hashes) > 0:
		return errStocksRevised
	}
	if len(skus) == 0 {
		return r.store(ctx, ozon.Page{No: r.nextPage, Body: []byte(emptySearchPage(details)), Cursor: end, ContentType: "application/json"})
	}

	var drift ozon.Drift
	defer func() { r.warnDrift(endpoint, drift) }()
	rows, pages := 0, 0
	for chunk := at.chunk; chunk < chunks; chunk++ {
		part := skus[chunk*ozon.MaxSearchSKUs : min((chunk+1)*ozon.MaxSearchSKUs, len(skus))]
		page, expected := 0, -1
		resumed := chunk == at.chunk && r.task.Cursor != ""
		if resumed && at.pageCount > 0 {
			page, expected = at.page, at.pageCount
		}
		chunkRows, total := 0, -1
		// Хеши страниц порции: страницы порции сохраняются подряд, поэтому
		// после рестарта на странице N это последние N хешей загрузки.
		seen := map[string]bool{}
		if resumed && at.page > 0 && at.page <= len(r.hashes) {
			for _, h := range r.hashes[len(r.hashes)-at.page:] {
				seen[h] = true
			}
		}
		for {
			p, stats, err := r.exec.ozon.FetchSearchPage(ctx, creds, r.task.ConnectionRef, details, day, part, page)
			if ozon.IsSearchNoData(err) {
				if day.Before(today.AddDate(0, 0, -SearchHistoryDays)) {
					return fmt.Errorf("%w: %s", errHistoryUnavailable, r.task.PeriodKey)
				}
				return fmt.Errorf("%w: %s", errSearchNotReady, r.task.PeriodKey)
			}
			if err != nil {
				return err
			}
			if expected >= 0 && stats.PageCount != expected {
				return errSearchShifted
			}
			expected = stats.PageCount
			switch {
			case stats.PageCount == 0 && (page != 0 || stats.Rows != 0):
				return ozon.InvalidResponse(endpoint, fmt.Sprintf("page %d has %d rows with page_count 0", page, stats.Rows))
			case stats.PageCount > 0 && page >= stats.PageCount:
				return ozon.InvalidResponse(endpoint, fmt.Sprintf("page %d outside page_count %d", page, stats.PageCount))
			case stats.PageCount > 0 && stats.Rows == 0:
				// Пустая страница до конца выдачи — тихая обрезка.
				return ozon.InvalidResponse(endpoint, fmt.Sprintf("empty page %d of %d", page, stats.PageCount))
			}
			if sum := sha256Hex(p.Body); seen[sum] {
				// Повтор страницы: настоящая страница потеряна, хотя число строк сходится.
				return ozon.InvalidResponse(endpoint, fmt.Sprintf("page %d repeats an earlier page of the chunk", page))
			} else {
				seen[sum] = true
			}
			drift.Add(ozon.SearchDrift(details, p.Body))
			chunkRows += stats.Rows
			total = stats.Total
			next := page + 1
			p.No = r.nextPage
			switch {
			case next < stats.PageCount:
				p.Cursor = fmt.Sprintf("%s:%d:%d:%d", fp, chunk, next, stats.PageCount)
			case chunk+1 < chunks:
				p.Cursor = fmt.Sprintf("%s:%d:0:0", fp, chunk+1)
			default:
				p.Cursor = end
			}
			if err := r.store(ctx, p); err != nil {
				return err
			}
			pages++
			if next >= stats.PageCount {
				break
			}
			page = next
		}
		rows += chunkRows
		// Сверка с total — только у порции, прочитанной с первой страницы в этой попытке.
		if (!resumed || at.page == 0) && total >= 0 && chunkRows != total {
			r.log.Warn("search analytics: rows differ from total", slog.String("stage", "fetch"),
				slog.Int("chunk", chunk), slog.Int("rows", chunkRows), slog.Int("total", total))
		}
	}
	r.log.Info("search analytics read", slog.String("stage", "fetch"), slog.Int("skus", len(skus)),
		slog.Int("pages", pages), slog.Int("rows", rows), slog.Bool("continued", r.task.Cursor != ""))
	return nil
}

// executionDaySKUs — SKU снимка ozon.stocks дня исполнения; до снимка дня
// (раньше 05:00) — вчерашнего.
func (r *taskRun) executionDaySKUs(ctx context.Context, cabinet connections.Cabinet, today time.Time) ([]int64, error) {
	skus, err := r.snapshotSKUs(ctx, cabinet, today.Format(time.DateOnly))
	if errors.Is(err, errStocksNotLoaded) {
		return r.snapshotSKUs(ctx, cabinet, today.AddDate(0, 0, -1).Format(time.DateOnly))
	}
	return skus, err
}
