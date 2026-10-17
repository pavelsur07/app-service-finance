package connections

import (
	"bytes"
	"context"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

const token = "test-internal-token-0123456789abcdef" //nolint:gosec // G101: фиктивный токен теста

const validSnapshot = `{
  "schema_version": "1",
  "generated_at": "2026-10-17T12:00:00+03:00",
  "connections": [
    {"connection_ref": "ref-b", "company_id": "company-b", "provider": "ozon", "updated_at": "2026-10-10T09:00:00+03:00",
     "credentials": {"seller": null, "performance": {"client_id": "perf-b", "client_secret": "perf-secret-b"}},
     "resource_types": ["ozon.ads.statistics"]},
    {"connection_ref": "ref-a", "company_id": "company-a", "provider": "ozon", "updated_at": "2026-10-11T09:00:00+03:00",
     "credentials": {"seller": {"client_id": "seller-a", "api_key": "seller-key-a"}, "performance": null},
     "resource_types": ["ozon.finance.accrual-by-day", "ozon.finance.accrual-types"]}
  ]
}`

func TestParseValidSnapshot(t *testing.T) {
	cabinets, rejected, err := parse([]byte(validSnapshot))

	require.NoError(t, err)
	assert.Empty(t, rejected)
	require.Len(t, cabinets, 2)
	a := cabinets[0]
	assert.Equal(t, "ref-a", a.Ref, "кабинеты упорядочены по ref")
	assert.Equal(t, "company-a", a.CompanyID)
	require.NotNil(t, a.Seller)
	assert.Equal(t, "seller-a", a.Seller.ClientID)
	assert.Equal(t, "seller-key-a", a.Seller.APIKey)
	assert.False(t, a.HasPerformance)
	assert.True(t, a.Supports("ozon.finance.accrual-by-day"))
	assert.False(t, a.Supports("ozon.finance.realization"))
	assert.True(t, a.UpdatedAt.Equal(time.Date(2026, 10, 11, 6, 0, 0, 0, time.UTC)))

	b := cabinets[1]
	assert.Nil(t, b.Seller)
	assert.True(t, b.HasPerformance)
}

func TestParseRejectsInvalidSnapshots(t *testing.T) {
	for name, body := range map[string]string{
		"not json":         `<html>`,
		"unknown version":  `{"schema_version":"2","connections":[]}`,
		"no version":       `{"connections":[]}`,
		"no connections":   `{"schema_version":"1","generated_at":"2026-10-17T12:00:00+03:00"}`,
		"no generated_at":  `{"schema_version":"1","connections":[]}`,
		"bad generated_at": `{"schema_version":"1","generated_at":"today","connections":[]}`,
	} {
		t.Run(name, func(t *testing.T) {
			_, _, err := parse([]byte(body))
			assert.ErrorIs(t, err, ErrInvalidSnapshot)
		})
	}
}

func TestParseSkipsBrokenCabinets(t *testing.T) {
	body := `{"schema_version":"1","generated_at":"2026-10-17T12:00:00+03:00","connections":[
	  {"connection_ref":"ok","company_id":"c","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":{"client_id":"1","api_key":"k"},"performance":null},"resource_types":[]},
	  {"connection_ref":"no-company","company_id":"","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":null,"performance":null},"resource_types":[]},
	  {"connection_ref":"wb","company_id":"c","provider":"wildberries","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":null,"performance":null},"resource_types":[]},
	  {"connection_ref":"bad-date","company_id":"c","provider":"ozon","updated_at":"yesterday","credentials":{"seller":null,"performance":null},"resource_types":[]},
	  {"connection_ref":"empty-key","company_id":"c","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":{"client_id":"1","api_key":""},"performance":null},"resource_types":[]},
	  {"connection_ref":"no-credentials","company_id":"c","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","resource_types":[]},
	  {"connection_ref":"ok","company_id":"c2","provider":"ozon","updated_at":"2026-10-11T09:00:00+03:00","credentials":{"seller":null,"performance":null},"resource_types":[]}
	]}`

	cabinets, rejected, err := parse([]byte(body))

	require.NoError(t, err)
	require.Len(t, cabinets, 1)
	assert.Equal(t, "ok", cabinets[0].Ref)
	assert.Equal(t, []string{"no-company", "wb", "bad-date", "empty-key", "no-credentials", "ok"}, rejected, "дубль ref тоже отвергается")
}

func TestClientFetchSendsTokenAndProvider(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/internal/marketplace-connections", r.URL.Path)
		assert.Equal(t, "ozon", r.URL.Query().Get("provider"))
		assert.Equal(t, "Bearer "+token, r.Header.Get("Authorization"))
		_, _ = io.WriteString(w, validSnapshot)
	}))
	defer srv.Close()

	cabinets, _, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())

	require.NoError(t, err)
	assert.Len(t, cabinets, 2)
}

func TestClientFetchFailsOnNon200(t *testing.T) {
	for _, status := range []int{401, 403, 500, 503} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(status)
		}))
		_, _, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())
		srv.Close()
		require.Error(t, err)
		assert.NotContains(t, err.Error(), token)
	}
}

type scriptedFetcher struct {
	mu      sync.Mutex
	results []fetchResult
	calls   int
}

type fetchResult struct {
	cabinets []Cabinet
	err      error
}

func (f *scriptedFetcher) Fetch(context.Context) ([]Cabinet, []string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	r := f.results[min(f.calls, len(f.results)-1)]
	f.calls++
	return r.cabinets, nil, r.err
}

type clock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *clock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.now
}

func (c *clock) Advance(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.now = c.now.Add(d)
}

func newCache(f Fetcher) (*Cache, *clock, *bytes.Buffer) {
	logs := &bytes.Buffer{}
	clk := &clock{now: time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)}
	return NewCache(f, slog.New(slog.NewJSONHandler(logs, nil)), clk.Now), clk, logs
}

func TestCacheKeepsLastSnapshotOnFailure(t *testing.T) {
	first := []Cabinet{{Ref: "ref-a"}, {Ref: "ref-b"}}
	f := &scriptedFetcher{results: []fetchResult{{cabinets: first}, {err: errors.New("HTTP 503")}}}
	cache, _, logs := newCache(f)

	assert.False(t, cache.Ready())
	assert.Empty(t, cache.List())

	require.NoError(t, cache.Refresh(context.Background()))
	require.Error(t, cache.Refresh(context.Background()))

	assert.True(t, cache.Ready())
	assert.Len(t, cache.List(), 2)
	_, ok := cache.Get("ref-b")
	assert.True(t, ok)
	assert.Contains(t, logs.String(), "keeping last snapshot")
}

func TestCacheReplacesSnapshotAndDropsVanishedCabinets(t *testing.T) {
	f := &scriptedFetcher{results: []fetchResult{{cabinets: []Cabinet{{Ref: "ref-a"}, {Ref: "ref-b"}}}, {cabinets: []Cabinet{{Ref: "ref-a"}}}}}
	cache, _, _ := newCache(f)

	require.NoError(t, cache.Refresh(context.Background()))
	require.NoError(t, cache.Refresh(context.Background()))

	assert.Len(t, cache.List(), 1)
	_, ok := cache.Get("ref-b")
	assert.False(t, ok)
}

func TestForceRefreshIsThrottled(t *testing.T) {
	f := &scriptedFetcher{results: []fetchResult{{cabinets: []Cabinet{{Ref: "ref-a"}}}}}
	cache, clk, _ := newCache(f)
	require.NoError(t, cache.Refresh(context.Background()))

	done, err := cache.ForceRefresh(context.Background())
	require.NoError(t, err)
	assert.False(t, done, "только что обновлялись")

	clk.Advance(ForceRefreshMinInterval)
	done, err = cache.ForceRefresh(context.Background())
	require.NoError(t, err)
	assert.True(t, done)
	assert.Equal(t, 2, f.calls)
}

func TestListAndGetReturnDeepCopies(t *testing.T) {
	cabinets, _, err := parse([]byte(validSnapshot))
	require.NoError(t, err)
	cache, _, _ := newCache(&scriptedFetcher{results: []fetchResult{{cabinets: cabinets}}})
	require.NoError(t, cache.Refresh(context.Background()))

	list := cache.List()
	list[0].Ref = "mutated"
	list[0].Seller.APIKey = "mutated"
	list[0].ResourceTypes[0] = "mutated"
	got, ok := cache.Get("ref-a")
	require.True(t, ok)
	got.Seller.APIKey = "mutated-too"
	got.ResourceTypes[0] = "mutated-too"

	fresh, _ := cache.Get("ref-a")
	assert.Equal(t, "ref-a", cache.List()[0].Ref)
	assert.Equal(t, "seller-key-a", fresh.Seller.APIKey)
	assert.Equal(t, "ozon.finance.accrual-by-day", fresh.ResourceTypes[0])
}

func TestInvalidSnapshotPreservesCache(t *testing.T) {
	srvBody := validSnapshot
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = io.WriteString(w, srvBody)
	}))
	defer srv.Close()
	cache, _, _ := newCache(NewClient(srv.URL, token, srv.Client()))
	require.NoError(t, cache.Refresh(context.Background()))

	srvBody = `{"schema_version":"1","connections":[]}`
	require.ErrorIs(t, cache.Refresh(context.Background()), ErrInvalidSnapshot)

	assert.Len(t, cache.List(), 2, "битый снимок не обнуляет кэш")
}

func TestRunRefreshesUntilCanceled(t *testing.T) {
	var calls atomic.Int32
	f := fetcherFunc(func(context.Context) ([]Cabinet, []string, error) {
		calls.Add(1)
		return []Cabinet{{Ref: "ref-a"}}, nil, nil
	})
	cache := NewCache(f, slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		cache.Run(ctx, 5*time.Millisecond)
		close(done)
	}()

	require.Eventually(t, func() bool { return calls.Load() >= 3 }, time.Second, time.Millisecond)
	cancel()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("Run did not stop")
	}
	assert.True(t, cache.Ready())
}

func TestCabinetCredentialsAreNotLogged(t *testing.T) {
	f := &scriptedFetcher{}
	cabinets, _, err := parse([]byte(validSnapshot))
	require.NoError(t, err)
	f.results = []fetchResult{{cabinets: cabinets}}
	cache, _, logs := newCache(f)

	require.NoError(t, cache.Refresh(context.Background()))
	slog.New(slog.NewJSONHandler(logs, nil)).Info("debug", slog.Any("cabinet", cache.List()[0]))

	assert.False(t, strings.Contains(logs.String(), "seller-key-a"), logs.String())
}

type fetcherFunc func(context.Context) ([]Cabinet, []string, error)

func (f fetcherFunc) Fetch(ctx context.Context) ([]Cabinet, []string, error) { return f(ctx) }
