package facts

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestSplitFunnelRowPerSKU(t *testing.T) {
	records, err := Split(ResourceFunnel, []byte(`{"result":{"data":[
		{"dimensions":[{"id":"11","name":"Товар"}],"metrics":[5,0,1]},
		{"dimensions":[{"id":"12","name":""}],"metrics":[0,0,0]}],"totals":[5,0,1]},"timestamp":"t"}`))

	require.NoError(t, err)
	require.Len(t, records, 2)
	assert.Equal(t, "ozon:analytics.funnel:11", records[0].SourceKey)
	assert.JSONEq(t, `{"dimensions":[{"id":"11","name":"Товар"}],"metrics":[5,0,1]}`, string(records[0].Payload), "строка как в источнике")
	assert.Equal(t, "ozon:analytics.funnel:12", records[1].SourceKey)
}

func TestSplitFunnelSkippedDayAndRejects(t *testing.T) {
	records, err := Split(ResourceFunnel, []byte(`{"subscription_required":true}`))
	require.NoError(t, err)
	require.Len(t, records, 1, "день без тарифа — «нет данных», а не пустой день")
	assert.Equal(t, FunnelUnavailableKey, records[0].SourceKey)

	empty, err := Split(ResourceFunnel, []byte(`{"result":{"data":null}}`))
	require.NoError(t, err)
	assert.Empty(t, empty)

	for _, page := range []string{`nope`, `{}`, `{"result":{"data":[{"dimensions":[]}]}}`, `{"result":{"data":[{"dimensions":[{"id":"a b"}]}]}}`} {
		_, err := Split(ResourceFunnel, []byte(page))
		require.Error(t, err, page)
	}
}
