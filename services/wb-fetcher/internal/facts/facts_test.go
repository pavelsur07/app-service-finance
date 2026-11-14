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
