package executor

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"strconv"
	"strings"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

// emptyWarehouseStocksPage — служебная страница снимка без SKU: Ozon отвергает
// запрос без skus, день при этом загружен.
const emptyWarehouseStocksPage = `{"items":[]}`

var (
	// errStocksNotLoaded — снимок остатков дня ещё не загружен (not_ready).
	errStocksNotLoaded = errors.New("executor: stocks snapshot of the day is not loaded yet")
	// errStocksRawInvalid — сохранённая страница остатков повреждена, пропала или не разбирается.
	errStocksRawInvalid = errors.New("executor: stored stocks page is not readable")
	// errStocksRevised — снимок остатков дня перезагружен между попытками:
	// курсор относится к другому набору SKU, остатки по складам — заново.
	errStocksRevised = errors.New("executor: stocks snapshot of the day changed since the saved chunk")
)

// warehouseEnd — индекс курсора последней сохранённой порции: итог задачи не
// записан (сбой), а все порции уже в raw.
const warehouseEnd = "end"

// warehouseStocks — остатки по складам по SKU снимка ozon.stocks того же дня,
// по возрастанию, порции по ozon.MaxWarehouseStocksSKUs; курсор —
// «отпечаток списка SKU:индекс следующей порции», у последней порции —
// «отпечаток:end»: повтор после несостоявшейся записи итога сверяет отпечаток
// со снимком остатков, а не принимает сохранённое молча.
func (r *taskRun) warehouseStocks(ctx context.Context, creds ozon.Credentials, cabinet connections.Cabinet) error {
	return r.withinSnapshot(ctx, StocksWindowStart[r.task.ResourceType], func(ctx context.Context) error {
		return r.loadWarehouseStocks(ctx, creds, cabinet)
	})
}

func (r *taskRun) loadWarehouseStocks(ctx context.Context, creds ozon.Credentials, cabinet connections.Cabinet) error {
	skus, err := r.snapshotSKUs(ctx, cabinet)
	if err != nil {
		return err
	}
	keys := make([]string, len(skus))
	for i, sku := range skus {
		keys[i] = strconv.FormatInt(sku, 10)
	}
	fp := numbersFingerprint(keys)

	end := fp + ":" + warehouseEnd

	start := 0
	switch {
	case r.cursor == end:
		// Все порции этого набора SKU уже сохранены прежней попыткой.
		return nil
	case r.cursor != "":
		cursorFP, idx, ok := strings.Cut(r.cursor, ":")
		n, parseErr := strconv.Atoi(idx)
		if !ok || cursorFP != fp || parseErr != nil || n <= 0 || n >= len(skus) {
			return errStocksRevised
		}
		start = n
	case len(r.hashes) > 0:
		// Страницы без курсора — не от этого набора SKU.
		return errStocksRevised
	}
	if len(skus) == 0 {
		return r.store(ctx, ozon.Page{No: r.nextPage, Body: []byte(emptyWarehouseStocksPage), Cursor: end, ContentType: "application/json"})
	}
	var drift ozon.Drift
	var rows, withRows int
	for i := start; i < len(skus); i += ozon.MaxWarehouseStocksSKUs {
		chunk := skus[i:min(i+ozon.MaxWarehouseStocksSKUs, len(skus))]
		page, stats, err := r.exec.ozon.FetchWarehouseStocks(ctx, creds, r.task.ConnectionRef, chunk)
		if err != nil {
			return err
		}
		rows += stats.Rows
		withRows += stats.SKUs
		drift.Add(ozon.WarehouseStocksDrift(page.Body))
		page.No = r.nextPage
		page.Cursor = end
		if next := i + len(chunk); next < len(skus) {
			page.Cursor = fp + ":" + strconv.Itoa(next)
		}
		if err := r.store(ctx, page); err != nil {
			return err
		}
	}
	// SKU без строк — товар нигде не лежит; числа — для сверки без чтения raw.
	r.log.Info("warehouse stocks read", slog.String("stage", "fetch"), slog.Int("skus", len(skus)),
		slog.Int("requested", len(skus)-start), slog.Int("skus_with_rows", withRows), slog.Int("rows", rows),
		slog.Bool("continued", start > 0))
	r.warnDrift(ozon.EndpointWarehouseStocks, drift)
	return nil
}

// snapshotSKUs — SKU текущей ревизии снимка ozon.stocks того же дня, без дублей по возрастанию.
func (r *taskRun) snapshotSKUs(ctx context.Context, cabinet connections.Cabinet) ([]int64, error) {
	if !cabinet.Supports(ResourceStocks) {
		// Остатки сняты с кабинета — не из чего брать SKU.
		return nil, errResourceNotEnabled
	}
	key := store.TaskKey{ConnectionRef: r.task.ConnectionRef, ResourceType: ResourceStocks, PeriodKey: r.task.PeriodKey}
	content, err := r.exec.tasks.ContentPages(ctx, key)
	if errors.Is(err, store.ErrNoContent) {
		return nil, errStocksNotLoaded
	}
	if errors.Is(err, store.ErrMissingObject) {
		return nil, fmt.Errorf("%w: %v", errStocksRawInvalid, err)
	}
	if err != nil {
		return nil, &storeError{err}
	}
	set := map[int64]bool{}
	err = r.readContent(ctx, content, ResourceStocks, errStocksRawInvalid, func(body []byte) error {
		page, err := ozon.ParseStocksPage(body)
		if err != nil {
			return err
		}
		if page.BadSKUs > 0 {
			// Строка остатков без SKU — неполный список: успешный снимок по
			// складам без части товаров выглядел бы полным.
			return fmt.Errorf("%d stock rows without positive sku", page.BadSKUs)
		}
		for _, sku := range page.SKUs {
			set[sku] = true
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	out := make([]int64, 0, len(set))
	for sku := range set {
		out = append(out, sku)
	}
	slices.Sort(out)
	return out, nil
}
