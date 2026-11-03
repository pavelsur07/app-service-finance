package connections

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log/slog"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	fkconn "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/connections"
)

const snapshot = `{"schema_version":"1","generated_at":"2026-11-02T12:00:00+03:00","connections":[
  {"connection_ref":"wb-1","company_id":"c1","provider":"wildberries","updated_at":"2026-11-01T09:00:00+03:00","credentials":{"seller":{"client_id":"wb-1","api_key":"secret-token"},"performance":null},"resource_types":["wb.finance.sales-report.daily"]},
  {"connection_ref":"wb-2","company_id":"c2","provider":"wildberries","updated_at":"2026-11-01T09:00:00+03:00","credentials":{"seller":null,"performance":null},"resource_types":[]},
  {"connection_ref":"oz","company_id":"c1","provider":"ozon","updated_at":"2026-11-01T09:00:00+03:00","credentials":{"seller":{"client_id":"1","api_key":"k"},"performance":null},"resource_types":[]}
]}`

func TestWildberriesCabinetFromSnapshot(t *testing.T) {
	cabinets, rejected, err := parse([]byte(snapshot))

	require.NoError(t, err)
	require.Len(t, cabinets, 1)
	cab := cabinets[0]
	assert.Equal(t, "wb-1", cab.Ref)
	assert.Equal(t, "c1", cab.CompanyID)
	assert.Equal(t, "secret-token", cab.Token.Reveal())
	assert.True(t, cab.Supports("wb.finance.sales-report.daily"))
	assert.False(t, cab.Supports("wb.finance.sales-report.weekly"))
	assert.Equal(t, time.Date(2026, 11, 1, 6, 0, 0, 0, time.UTC), cab.UpdatedAt.UTC())
	assert.ElementsMatch(t, []string{"wb-2", "oz"}, rejected, "без токена и чужой провайдер")
}

func TestTokenNeverPrinted(t *testing.T) {
	cabinets, _, err := parse([]byte(snapshot))
	require.NoError(t, err)
	cab := cabinets[0]

	var logs bytes.Buffer
	slog.New(slog.NewJSONHandler(&logs, nil)).Info("x", slog.Any("cabinet", cab), slog.Any("token", cab.Token))
	js, _ := json.Marshal(cab)

	for _, out := range []string{fmt.Sprintf("%v %+v %#v %s", cab, cab, cab, cab.Token), logs.String(), string(js)} {
		assert.NotContains(t, out, "secret-token")
	}
}

func TestCloneIsDeep(t *testing.T) {
	cab := Cabinet{Ref: "a", ResourceTypes: []string{"r"}}
	clone := cab.Clone()
	clone.ResourceTypes[0] = "x"
	assert.Equal(t, "r", cab.ResourceTypes[0])
}

func TestProviderIsWildberries(t *testing.T) {
	assert.Equal(t, "wildberries", Provider)
	var _ fkconn.Cabinet[Cabinet] = Cabinet{}
}
