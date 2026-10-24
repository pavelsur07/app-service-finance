package executor

import (
	"context"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

func (f *fakeOzon) FetchPrices(ctx context.Context, creds ozon.Credentials, _, cursor string, firstPageNo int, onPage ozon.PageHandler) error {
	f.creds = append(f.creds, creds)
	f.priceFrom = append(f.priceFrom, cursor)
	if f.productHang {
		<-ctx.Done()
		return ctx.Err()
	}
	for no := firstPageNo; ; no++ {
		p, ok := f.pricePages[cursor]
		if !ok {
			return f.priceErr
		}
		p.No = no
		if err := onPage(p); err != nil {
			return err
		}
		if p.Cursor == "" {
			return nil
		}
		cursor = p.Cursor
	}
}

var pricesCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourcePrices}
	return c
}()

func pricesPage(total int, cursor string, ids ...int64) ozon.Page {
	items := make([]string, len(ids))
	for i, id := range ids {
		items[i] = fmt.Sprintf(`{"product_id":%d,"offer_id":"A-%d","acquiring":0,"commissions":{},"marketing_actions":{},`+
			`"price":{"price":"100"},"price_indexes":{},"volume_weight":0.1}`, id, id)
	}
	return page(fmt.Sprintf(`{"items":[%s],"total":%d,"cursor":%q,"total_items":%d}`, strings.Join(items, ","), total, cursor, total), cursor)
}

func TestPricesStoresEveryPageAndLogsCounts(t *testing.T) {
	o := &fakeOzon{pricePages: map[string]ozon.Page{
		"":   pricesPage(3, "C1", 5, 7),
		"C1": pricesPage(3, "C2", 9),
		"C2": pricesPage(3, ""),
	}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourcePrices, today), pricesCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"C1", "C2", ""}, h.tasks.cursors)
	assert.Equal(t, ResourcePrices, h.raw.puts[0].ResourceType)
	assert.Contains(t, h.logs.String(), `"msg":"prices snapshot read"`)
	assert.Contains(t, h.logs.String(), `"pages":3,"items":3,"total":3`)
	assert.NotContains(t, h.logs.String(), `"level":"WARN"`)
}

func TestPricesCountMismatchIsWarned(t *testing.T) {
	o := &fakeOzon{pricePages: map[string]ozon.Page{"": pricesPage(5, "C1", 5), "C1": pricesPage(5, "")}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourcePrices, today), pricesCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Contains(t, h.logs.String(), `"msg":"prices snapshot: items differ from total"`)
}

func TestPricesResumeAndStaleCursor(t *testing.T) {
	o := &fakeOzon{pricePages: map[string]ozon.Page{"C1": pricesPage(2, "")}}
	h := newHarness(o)
	tk := task(ResourcePrices, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "C1", 2, []string{"h1"}

	res := h.exec.Run(context.Background(), tk, pricesCabinet, "trace")
	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"C1"}, o.priceFrom)
	assert.Equal(t, 2, h.tasks.pages[0].PageNo)

	o2 := &fakeOzon{pricePages: map[string]ozon.Page{}, priceErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointPrices, Status: 400}}
	h2 := newHarness(o2)
	res = h2.exec.Run(context.Background(), tk, pricesCabinet, "trace")
	assert.Equal(t, "stale_cursor", res.Outcome)
	assert.False(t, h2.tasks.finished.KeepCursor)
}

func TestPricesSnapshotWindow(t *testing.T) {
	o := &fakeOzon{pricePages: map[string]ozon.Page{"": pricesPage(0, "")}}
	h := newHarness(o)
	h.exec.now = func() time.Time { return msk(t, "2026-10-17 04:59") }
	res := h.exec.Run(context.Background(), task(ResourcePrices, "2026-10-17"), pricesCabinet, "trace")
	assert.Equal(t, "snapshot_early", res.Outcome)
	assert.True(t, h.tasks.finished.NextRunAt.Equal(msk(t, "2026-10-17 05:00")))

	h.exec.now = func() time.Time { return msk(t, "2026-10-18 00:01") }
	res = h.exec.Run(context.Background(), task(ResourcePrices, "2026-10-17"), pricesCabinet, "trace")
	assert.Equal(t, "snapshot_missed", res.Outcome)
	assert.Equal(t, store.StatusFailedPermanent, h.tasks.finished.Status)
	assert.Empty(t, o.priceFrom)
}

func TestPricesDisabledDoNotCallOzon(t *testing.T) {
	o := &fakeOzon{pricePages: map[string]ozon.Page{"": pricesPage(0, "")}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourcePrices, today), cabinet, "trace")

	assert.Equal(t, "resource_disabled", res.Outcome)
	assert.Empty(t, o.priceFrom)
}

func TestPricesShapeChangeIsWarnedOnce(t *testing.T) {
	changed := page(`{"items":[{"product_id":1,"offer_id":"A","acquiring":0,"commissions":{},"marketing_actions":{},"price":{},"price_indexes":{},"new_field":1}],"total":1,"cursor":"C1","total_items":1}`, "C1")
	o := &fakeOzon{pricePages: map[string]ozon.Page{"": changed, "C1": pricesPage(1, "")}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourcePrices, today), pricesCabinet, "trace")

	assert.Equal(t, "done", res.Outcome, "raw сохраняется, задача не падает")
	assert.Equal(t, 1, strings.Count(h.logs.String(), `"msg":"ozon response shape changed"`))
	assert.Contains(t, h.logs.String(), `"unknown_fields":["items[].new_field"],"missing_fields":["items[].volume_weight"]`)
}
