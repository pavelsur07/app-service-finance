package executor

import (
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/wb"
)

var weekCab = connections.Cabinet{Ref: "wb-1", CompanyID: "c1", Token: "tok", ResourceTypes: []string{ResourceDaily, ResourceWeekly}}

func weekTask(key string) store.Task {
	return store.Task{TaskKey: store.TaskKey{ConnectionRef: "wb-1", ResourceType: ResourceWeekly, PeriodKey: key}, AttemptID: "att", Attempts: 1, NextPageNo: 1}
}

func mskAt(s string) time.Time {
	v, err := time.ParseInLocation("2006-01-02 15:04", s, moscow)
	if err != nil {
		panic(err)
	}
	return v
}

func TestWeeklyRequestsMondayToSundayWithWeeklyPeriod(t *testing.T) {
	rep := &fakeReport{pages: []wb.ReportPage{page(7, false)}}

	res, h := runAt(t, mskAt("2026-11-02 05:00"), rep, weekTask("2026-W44"), weekCab)

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, rep.reqs, 1)
	assert.Equal(t, wb.ReportRequest{ConnectionRef: "wb-1", Token: "tok", DateFrom: "2026-10-26", DateTo: "2026-11-01",
		Period: wb.PeriodWeekly, RrdID: 0, Limit: wb.PageLimit}, rep.reqs[0])
	require.Len(t, h.raw.keys, 1)
	assert.True(t, strings.HasPrefix(h.raw.keys[0], "wb/wb-1/wb.finance.sales-report.weekly/2026-W44/"), h.raw.keys[0])
}

func TestEmptyWeekBeforeDeadlineIsNotReady(t *testing.T) {
	for _, now := range []string{"2026-11-02 05:00", "2026-11-03 23:59"} {
		at := mskAt(now)
		_, h := runAt(t, at, &fakeReport{}, weekTask("2026-W44"), weekCab)

		require.Len(t, h.tasks.finished, 1, now)
		out := h.tasks.finished[0]
		assert.Equal(t, store.StatusNotReady, out.Status, now)
		assert.Equal(t, at.Add(time.Hour), out.NextRunAt, now)
		assert.Empty(t, h.raw.keys, "пустой ответ до формирования не пишется в raw: "+now)
	}
}

func TestEmptyWeekAfterDeadlineIsDoneWithEmptyRevision(t *testing.T) {
	res, h := runAt(t, mskAt("2026-11-04 00:00"), &fakeReport{}, weekTask("2026-W44"), weekCab)

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, h.raw.keys, 1, "пустая ревизия сохраняется — «пусто» отличимо от «не грузили»")
}

func TestEmptyDayIsDoneAsBefore(t *testing.T) {
	res, _ := runAt(t, mskAt("2026-11-02 05:00"), &fakeReport{}, task("2026-11-01"), weekCab)

	assert.Equal(t, "done", res.Outcome, "not_ready только у недельного ресурса")
}

func TestWeeklyContinuesFromCursor(t *testing.T) {
	rep := &fakeReport{pages: []wb.ReportPage{page(100, true), page(150, false)}}
	tk := weekTask("2026-W44")
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "50", 2, []string{"h1"}

	res, _ := runAt(t, mskAt("2026-11-02 05:00"), rep, tk, weekCab)

	assert.Equal(t, "done", res.Outcome)
	require.Len(t, rep.reqs, 2)
	assert.Equal(t, int64(50), rep.reqs[0].RrdID)
	assert.Equal(t, int64(100), rep.reqs[1].RrdID)
}

func TestEmptyContinuationPageAfterCursorIsNotNotReady(t *testing.T) {
	tk := weekTask("2026-W44")
	tk.Cursor, tk.NextPageNo, tk.PageHashes = "100000", 2, []string{"h1"}

	res, _ := runAt(t, mskAt("2026-11-02 05:00"), &fakeReport{}, tk, weekCab)

	assert.Equal(t, "done", res.Outcome, "пустая страница после полной — конец отчёта, а не неготовность")
}

func TestBadWeekKeyIsInvalidTask(t *testing.T) {
	_, h := runAt(t, mskAt("2026-11-02 05:00"), &fakeReport{}, weekTask("2026-W54"), weekCab)

	assert.Equal(t, "invalid_task", h.tasks.finished[0].Label)
}
