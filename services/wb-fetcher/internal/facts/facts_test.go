package facts

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestSplitDailyReport(t *testing.T) {
	tests := []struct {
		name string
		page string
		keys []string
	}{
		{"rows keyed by rrdId", `[{"rrdId":101,"nmId":5},{"rrdId":"102"}]`, []string{"wb:sales-report.daily:101", "wb:sales-report.daily:102"}},
		{"last page is empty", `[]`, []string{}},
		{"row without rrdId gets content key", `[{"nmId":7}]`, []string{"wb:sales-report.daily:fallback-7d7d89e7f270d572"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			records, err := Split(ResourceDaily, []byte(tt.page))
			require.NoError(t, err)
			keys := []string{}
			for _, r := range records {
				keys = append(keys, r.SourceKey)
			}
			assert.Equal(t, tt.keys, keys)
		})
	}
}

func TestSplitKeepsRowAsIs(t *testing.T) {
	records, err := Split(ResourceDaily, []byte(`[{"rrdId":1, "retailAmount": 10.5}]`))
	require.NoError(t, err)
	assert.JSONEq(t, `{"rrdId":1,"retailAmount":10.5}`, string(records[0].Payload))
}

func TestSplitRejectsUnknownResourceAndBadPage(t *testing.T) {
	_, err := Split("wb.finance.sales-report.weekly", []byte(`[]`))
	require.Error(t, err)
	_, err = Split(ResourceDaily, []byte(`{"rows":[]}`))
	require.Error(t, err)
	_, err = Split(ResourceDaily, []byte(`[1]`))
	require.Error(t, err)
}

func TestSplitAdsDayGroupsByCampaign(t *testing.T) {
	page := []byte(`{"schema":"wb-ads-daily-v1",
		"expenses":[{"advertId":10,"updSum":5},{"advertId":"9","updSum":1},{"advertId":10,"updSum":2}],
		"statistics":[{"advertId":10,"days":[{"apps":[{"nms":[{"nmId":1,"sum":3}]}]}]}]}`)

	records, err := Split(ResourceAds, page)
	require.NoError(t, err)

	require.Len(t, records, 2)
	assert.Equal(t, "wb:ads.daily:9", records[0].SourceKey)
	assert.JSONEq(t, `{"advertId":"9","expenses":[{"advertId":"9","updSum":1}],"statistics":null}`, string(records[0].Payload))
	assert.Equal(t, "wb:ads.daily:10", records[1].SourceKey)
	assert.JSONEq(t, `{"advertId":"10","expenses":[{"advertId":10,"updSum":5},{"advertId":10,"updSum":2}],
		"statistics":{"advertId":10,"days":[{"apps":[{"nms":[{"nmId":1,"sum":3}]}]}]}}`, string(records[1].Payload))
}

func TestSplitAdsRejectsOtherSchema(t *testing.T) {
	_, err := Split(ResourceAds, []byte(`{"schema":"v0","expenses":[],"statistics":[]}`))
	require.Error(t, err)

	records, err := Split(ResourceAds, []byte(`{"schema":"wb-ads-daily-v1","expenses":[],"statistics":[]}`))
	require.NoError(t, err)
	assert.Empty(t, records, "день без рекламы — ноль записей")
}

func TestSplitFunnelCardPerNmID(t *testing.T) {
	records, err := Split(ResourceFunnel, []byte(`{"schema":"wb-funnel-daily-v1","currency":"RUB","products":[
		{"product":{"nmId":7,"vendorCode":"a","stocks":{"wb":5}},"statistic":{"selected":{"openCount":3},"past":{"openCount":1}}}]}`))
	require.NoError(t, err)
	require.Len(t, records, 1)
	assert.Equal(t, "wb:analytics.funnel:7", records[0].SourceKey)
	assert.JSONEq(t, `{"product":{"nmId":7},"statistic":{"selected":{"openCount":3}}}`, string(records[0].Payload), "только цифры дня: остатки и прошлый период меняются без изменения дня")

	empty, err := Split(ResourceFunnel, []byte(`{"schema":"wb-funnel-daily-v1","currency":"RUB","products":[]}`))
	require.NoError(t, err)
	assert.Empty(t, empty)

	for _, page := range []string{`{"schema":"v0","products":[]}`, `{"schema":"wb-funnel-daily-v1","products":[{"product":{}}]}`} {
		_, err := Split(ResourceFunnel, []byte(page))
		require.Error(t, err, page)
	}
}
