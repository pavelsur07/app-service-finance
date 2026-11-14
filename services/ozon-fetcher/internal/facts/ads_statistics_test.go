package facts

import (
	"archive/zip"
	"bytes"
	"encoding/json"
	"os"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestSplitAdsStatisticsCSV(t *testing.T) {
	page, err := os.ReadFile("testdata/ads_campaign.csv")
	require.NoError(t, err)

	records, err := Split(ResourceAdsStatistics, page)
	require.NoError(t, err)
	require.Len(t, records, 2, "шапка кампании, заголовок и итог «Всего» — не записи")
	assert.Equal(t, "ozon:ads-statistics:14275771:286085455", records[0].SourceKey)
	assert.Equal(t, "ozon:ads-statistics:14275771:286085455:2", records[1].SourceKey, "повтор SKU в отчёте — по порядку")

	var row map[string]string
	require.NoError(t, json.Unmarshal(records[0].Payload, &row))
	assert.Equal(t, "17.04.2026", row["День"])
	assert.Equal(t, "1152,19", row["Расход, ₽, с НДС"], "значения — строками, как в CSV")
	assert.Equal(t, "14275771", row["campaign_id"])

	again, err := Split(ResourceAdsStatistics, page)
	require.NoError(t, err)
	assert.Equal(t, records, again)
}

func TestSplitAdsStatisticsZipAndEmpty(t *testing.T) {
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	for name, body := range map[string]string{
		"2_2026-11-13-2026-11-13.csv": "День;sku;Расход, ₽, с НДС\n13.11.2026;22;5,00\nВсего;;5,00\n",
		"1_2026-11-13-2026-11-13.csv": "\xef\xbb\xbf;Кампания № 1, период 13.11.2026\nДень;sku;Расход, ₽, с НДС\n13.11.2026;11;1,50\n",
	} {
		w, err := zw.Create(name)
		require.NoError(t, err)
		_, _ = w.Write([]byte(body))
	}
	require.NoError(t, zw.Close())

	records, err := Split(ResourceAdsStatistics, buf.Bytes())
	require.NoError(t, err)
	require.Len(t, records, 2)
	assert.Equal(t, "ozon:ads-statistics:1:11", records[0].SourceKey, "файлы по имени, кампания из шапки")
	assert.Equal(t, "ozon:ads-statistics:2:22", records[1].SourceKey, "кампания из имени файла")

	empty, err := Split(ResourceAdsStatistics, []byte(`{"reports":[]}`))
	require.NoError(t, err)
	assert.Empty(t, empty, "кабинет без кампаний")

	for name, page := range map[string]string{
		"не тот json":   `{"reports":[1]}`,
		"нет заголовка": "a;b\n1;2\n",
		"битый zip":     "PK\x03\x04garbage",
	} {
		t.Run(name, func(t *testing.T) {
			_, err := Split(ResourceAdsStatistics, []byte(page))
			require.Error(t, err)
		})
	}
}
