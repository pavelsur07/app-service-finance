package executor

import (
	"context"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

func (f *fakeOzon) FetchReturns(_ context.Context, creds ozon.Credentials, _ string, from, to time.Time, cursor string, firstPageNo int, onPage ozon.PageHandler) error {
	f.creds = append(f.creds, creds)
	f.returnFrom = append(f.returnFrom, cursor)
	f.returnDays = append(f.returnDays, from.Format(time.RFC3339)+".."+to.Format(time.RFC3339))
	for no := firstPageNo; ; no++ {
		p, ok := f.returnPages[cursor]
		if !ok {
			return f.returnErr
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

var returnsCabinet = func() connections.Cabinet {
	c := cabinet
	c.ResourceTypes = []string{ResourceReturns}
	return c
}()

// returnsPage — возвраты дня 17.10 МСК с id ids; next — курсор следующей страницы.
func returnsPage(next string, ids ...int64) ozon.Page {
	items := make([]string, len(ids))
	for i, id := range ids {
		items[i] = fmt.Sprintf(`{"id":%d,"company_id":1,"return_reason_name":"r","type":"Cancellation","schema":"Fbo","order_id":1,`+
			`"order_number":"1","place":{},"target_place":{},"storage":{},"product":{"sku":10},"logistic":{"return_date":"2026-10-17T09:00:00Z"},`+
			`"visual":{},"exemplars":[],"additional_info":{},"clearing_id":null,"posting_number":"p","return_clearing_id":null,"source_id":1,`+
			`"compensation_status":null,"courier_delivery":null}`, id)
	}
	return page(fmt.Sprintf(`{"returns":[%s],"has_next":%t}`, strings.Join(items, ","), next != ""), next)
}

func TestReturnsStoresEveryPageForMoscowDay(t *testing.T) {
	o := &fakeOzon{returnPages: map[string]ozon.Page{"": returnsPage("2", 1, 2), "2": returnsPage("", 3)}}
	h := newHarness(o)

	res := h.exec.Run(context.Background(), task(ResourceReturns, today), returnsCabinet, "trace")

	assert.Equal(t, "done", res.Outcome)
	assert.Equal(t, []string{"2026-10-17T00:00:00+03:00..2026-10-18T00:00:00+03:00"}, o.returnDays)
	assert.Equal(t, []string{"2", ""}, h.tasks.cursors)
	require.Len(t, h.raw.puts, 2)
	assert.Equal(t, ResourceReturns, h.raw.puts[0].ResourceType)
	assert.Contains(t, h.logs.String(), `"pages":2,"returns":3`)
	assert.NotContains(t, h.logs.String(), `"level":"WARN"`, "живая форма — без предупреждения")
}

func TestReturnsResumeAndDisabled(t *testing.T) {
	// Прерванный день — заново с первой страницы: id прежней попытки для
	// проверки уникальности не восстановить.
	o := &fakeOzon{returnPages: map[string]ozon.Page{"2": returnsPage("", 3)}}
	h := newHarness(o)
	tk := task(ResourceReturns, today)
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "2", 2, []string{"h1"}
	assert.Equal(t, "returns_restart", h.exec.Run(context.Background(), tk, returnsCabinet, "trace").Outcome)
	assert.False(t, h.tasks.finished.KeepCursor, "курсор и сохранённые страницы сбрасываются")
	assert.Empty(t, o.returnFrom)

	o2 := &fakeOzon{returnPages: map[string]ozon.Page{"": returnsPage("", 1)}}
	h2 := newHarness(o2)
	assert.Equal(t, "resource_disabled", h2.exec.Run(context.Background(), task(ResourceReturns, today), cabinet, "trace").Outcome)
	assert.Empty(t, o2.returnFrom)

	o3 := &fakeOzon{returnPages: map[string]ozon.Page{"": returnsPage("2", 1)}, returnErr: &ozon.APIError{Class: ozon.ClassRateLimit, Endpoint: ozon.EndpointReturns, Status: 429}}
	h3 := newHarness(o3)
	assert.Equal(t, "rate_limit", h3.exec.Run(context.Background(), task(ResourceReturns, today), returnsCabinet, "trace").Outcome)
}

func TestReturnsRecoveryAndRefreshRevision(t *testing.T) {
	// Все страницы сохранены, итог не записан: без запросов.
	o := &fakeOzon{returnPages: map[string]ozon.Page{}}
	h := newHarness(o)
	tk := task(ResourceReturns, today)
	tk.NextPageNo, tk.PageHashes = 3, []string{"h1", "h2"}
	assert.Equal(t, "done", h.exec.Run(context.Background(), tk, returnsCabinet, "trace").Outcome)
	assert.Empty(t, o.returnFrom)

	// Перечитывание: то же содержимое — unchanged, другой статус возврата — done (новая ревизия).
	same := returnsPage("", 1)
	o2 := &fakeOzon{returnPages: map[string]ozon.Page{"": same}}
	h2 := newHarness(o2)
	tk2 := task(ResourceReturns, today)
	tk2.ContentHash = contentHash([]string{sha256Hex(same.Body)})
	assert.Equal(t, "unchanged", h2.exec.Run(context.Background(), tk2, returnsCabinet, "trace").Outcome)

	changed := page(strings.Replace(string(same.Body), `"visual":{}`, `"visual":{"status":{"sys_name":"ReturnedToSeller"}}`, 1), "")
	h3 := newHarness(&fakeOzon{returnPages: map[string]ozon.Page{"": changed}})
	assert.Equal(t, "done", h3.exec.Run(context.Background(), tk2, returnsCabinet, "trace").Outcome)
	assert.NotEqual(t, tk2.ContentHash, h3.tasks.finished.ContentHash)
}
