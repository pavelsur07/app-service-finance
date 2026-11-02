package connections

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

type cab struct {
	ref, company, token string
	resources           []string
}

func (c cab) CabinetRef() string     { return c.ref }
func (c cab) CabinetCompany() string { return c.company }
func (c cab) Clone() cab {
	c.resources = append([]string(nil), c.resources...)
	return c
}

const snapshot = `{"schema_version":"1","generated_at":"2026-11-02T12:00:00+03:00","connections":[
  {"connection_ref":"wb-b","company_id":"c2","provider":"wildberries","updated_at":"2026-11-01T09:00:00+03:00","credentials":{"seller":{"client_id":"s1","api_key":"t2"},"performance":null},"resource_types":["wb.finance.sales-report.daily"]},
  {"connection_ref":"wb-a","company_id":"c1","provider":"wildberries","updated_at":"2026-11-01T09:00:00+03:00","credentials":{"seller":{"client_id":"s1","api_key":"t1"},"performance":null},"resource_types":[]},
  {"connection_ref":"oz","company_id":"c1","provider":"ozon","updated_at":"2026-11-01T09:00:00+03:00","credentials":{"seller":{"client_id":"1","api_key":"k"},"performance":null},"resource_types":[]},
  {"connection_ref":"bad","company_id":"c1","provider":"wildberries","updated_at":"2026-11-01T09:00:00+03:00","credentials":{"seller":{"client_id":"s","api_key":""},"performance":null},"resource_types":[]}
]}`

func decodeWB(calls *[]string) Decoder[cab] {
	return func(w Wire, _ time.Time) (cab, bool, error) {
		*calls = append(*calls, w.ConnectionRef)
		if w.ConnectionRef == "bad" {
			return cab{}, false, nil
		}
		return cab{ref: w.ConnectionRef, company: w.CompanyID, token: string(w.Credentials), resources: w.ResourceTypes}, true, nil
	}
}

func TestClientAsksForItsProviderAndKeepsOnlyIt(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/internal/marketplace-connections", r.URL.Path)
		assert.Equal(t, "wildberries", r.URL.Query().Get("provider"))
		assert.Equal(t, "Bearer tok", r.Header.Get("Authorization"))
		_, _ = io.WriteString(w, snapshot)
	}))
	defer srv.Close()
	var calls []string

	cabinets, rejected, err := NewClient(srv.URL, "tok", "wildberries", decodeWB(&calls), srv.Client()).Fetch(context.Background())

	require.NoError(t, err)
	require.Len(t, cabinets, 2)
	assert.Equal(t, "wb-a", cabinets[0].ref, "по ref")
	assert.Equal(t, []string{"wb-b", "wb-a", "bad"}, calls, "декодер — на каждую запись своего провайдера, чужой не доходит")
	assert.Equal(t, []string{"oz", "bad"}, rejected)
}

func TestDecoderErrorRejectsWholeSnapshot(t *testing.T) {
	boom := errors.New("bad credentials")
	_, _, err := Parse([]byte(snapshot), "wildberries", func(Wire, time.Time) (cab, bool, error) { return cab{}, false, boom })
	assert.ErrorIs(t, err, boom)
}

type static struct{ list []cab }

func (s static) Fetch(context.Context) ([]cab, []string, error) { return s.list, nil, nil }

func TestCacheAllowlistAndDeepCopies(t *testing.T) {
	cache := NewCache[cab](static{list: []cab{{ref: "a", company: "c1", resources: []string{"r"}}, {ref: "b", company: "c2"}}},
		slog.New(slog.NewTextHandler(io.Discard, nil)), time.Now).WithAllowlist([]string{" c1 "})
	require.NoError(t, cache.Refresh(context.Background()))

	list := cache.List()
	require.Len(t, list, 1)
	list[0].resources[0] = "changed"
	got, ok := cache.Get("a")
	require.True(t, ok)
	assert.Equal(t, []string{"r"}, got.resources)
	_, ok = cache.Get("b")
	assert.False(t, ok, "вне allowlist")
}
