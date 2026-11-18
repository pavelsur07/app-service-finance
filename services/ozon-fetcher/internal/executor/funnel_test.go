package executor

import (
	"context"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

func (f *fakeOzon) FetchFunnel(_ context.Context, creds ozon.Credentials, _, day string, firstPageNo int, onPage ozon.PageHandler) (int, error) {
	f.creds = append(f.creds, creds)
	f.funnelDays = append(f.funnelDays, day)
	for i, p := range f.funnelPages {
		p.No = firstPageNo + i
		if err := onPage(p); err != nil {
			return 0, err
		}
	}
	return f.funnelRows, f.funnelErr
}

var funnelCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourceFunnel}
	return c
}()

func TestFunnelStoresEveryPageOfDay(t *testing.T) {
	o := &fakeOzon{funnelPages: []ozon.Page{page(`{"result":{"data":[1]}}`, "1000"), page(`{"result":{"data":[2]}}`, "")}, funnelRows: 1001}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceFunnel, today), funnelCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{today}, o.funnelDays)
	require.Len(t, h.raw.puts, 2)
	assert.Equal(t, ResourceFunnel, h.raw.puts[0].ResourceType)
	assert.Contains(t, h.logs.String(), `"pages":2,"rows":1001`)
}

func TestFunnelWithoutSubscriptionStoresServicePage(t *testing.T) {
	o := &fakeOzon{funnelErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointFunnel, Status: 400, Code: 3, Message: "deprecated metrics used"}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceFunnel, today), funnelCabinet, "trace")

	assert.Equal(t, "done", res.Outcome, "день пропущен, не ошибка")
	require.Len(t, h.raw.puts, 1)
	assert.JSONEq(t, FunnelSkippedPage, string(h.raw.puts[0].Body), "служебная страница вместо данных")
	assert.Contains(t, h.logs.String(), "funnel skipped")
	assert.NotContains(t, h.logs.String(), `"level":"ERROR"`, "нет тарифа — штатно")
	assert.NotContains(t, h.logs.String(), `"level":"WARN"`)

	// Перечитывание хвоста без тарифа — то же содержимое, новой ревизии нет.
	tk := task(ResourceFunnel, today)
	tk.ContentHash = contentHash([]string{sha256Hex([]byte(FunnelSkippedPage))})
	assert.Equal(t, "unchanged", newHarness(o).exec.Run(context.Background(), tk, funnelCabinet, "trace").Outcome)
}

func TestFunnelSubscriptionLostBetweenPagesRestartsDay(t *testing.T) {
	o := &fakeOzon{
		funnelPages: []ozon.Page{page(`{"result":{"data":[1]}}`, "1000")},
		funnelErr:   &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointFunnel, Status: 400, Code: 3, Message: "deprecated metrics used"},
	}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceFunnel, today), funnelCabinet, "trace")

	assert.Equal(t, "funnel_restart", res.Outcome, "часть дня не выдаётся за день: заново, затем служебная страница")
	assert.False(t, h.tasks.finished.KeepCursor)
	assert.NotContains(t, h.logs.String(), `"level":"ERROR"`)
}

func TestFunnelRefusalNeverOverwritesLoadedDay(t *testing.T) {
	o := &fakeOzon{funnelErr: &ozon.APIError{Class: ozon.ClassInvalidRequest, Endpoint: ozon.EndpointFunnel, Status: 400, Code: 3, Message: "deprecated metrics used"}}
	h := newHarness(o)
	tk := task(ResourceFunnel, today)
	tk.ContentHash = contentHash([]string{sha256Hex([]byte(`{"result":{"data":[1]}}`))})

	res := h.exec.Run(context.Background(), tk, funnelCabinet, "trace")

	assert.Equal(t, "funnel_refused", res.Outcome, "ревизия с данными не заменяется служебной страницей")
	assert.Equal(t, "failed_permanent", string(h.tasks.finished.Status))
	assert.Empty(t, h.raw.puts)

	shifted := newHarness(&fakeOzon{funnelErr: ozon.ErrFunnelShifted})
	assert.Equal(t, "funnel_restart", shifted.exec.Run(context.Background(), task(ResourceFunnel, today), funnelCabinet, "trace").Outcome)
}

func TestFunnelResumeDisabledAndRateLimit(t *testing.T) {
	// Прерванный день — заново: повтор SKU между страницами проверяется в одной попытке.
	o := &fakeOzon{}
	h := newHarness(o)
	tk := task(ResourceFunnel, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "1000", 2, []string{"h1"}
	assert.Equal(t, "funnel_restart", h.exec.Run(context.Background(), tk, funnelCabinet, "trace").Outcome)
	assert.Empty(t, o.funnelDays)

	// Все страницы сохранены, итог не записан: без запросов.
	tk.Cursor = ""
	assert.Equal(t, "done", h.exec.Run(context.Background(), tk, funnelCabinet, "trace").Outcome)
	assert.Empty(t, o.funnelDays)

	o2 := &fakeOzon{}
	assert.Equal(t, "resource_disabled", newHarness(o2).exec.Run(context.Background(), task(ResourceFunnel, today), cabinet, "trace").Outcome)
	assert.Empty(t, o2.funnelDays)

	o3 := &fakeOzon{funnelErr: &ozon.APIError{Class: ozon.ClassRateLimit, Endpoint: ozon.EndpointFunnel, Status: 429}}
	assert.Equal(t, "rate_limit", newHarness(o3).exec.Run(context.Background(), task(ResourceFunnel, today), funnelCabinet, "trace").Outcome)
}
