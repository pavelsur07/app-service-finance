package facts

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestSplitAccrualByDay(t *testing.T) {
	page := `{"accruals":[
		{"accrual_id":"1001","accrued_category":"POSTING","posting":{"posting_number":"P-1","products":[{"sku":"11","commission":{"sale_amount":"100.00"}}]}},
		{"accrual_id":1002,"accrued_category":"NON_ITEM"},
		{"accrued_category":"ITEM","amount":"-5.00"},
		{"accrual_id":"a b","accrued_category":"ITEM"},
		{"accrual_id":null,"accrued_category":"ITEM"}
	],"last_id":""}`

	records, err := Split(ResourceAccrualByDay, []byte(page))
	require.NoError(t, err)
	require.Len(t, records, 5)
	assert.Equal(t, "ozon:accrual-by-day:1001", records[0].SourceKey)
	assert.JSONEq(t, `{"accrual_id":"1001","accrued_category":"POSTING","posting":{"posting_number":"P-1","products":[{"sku":"11","commission":{"sale_amount":"100.00"}}]}}`,
		string(records[0].Payload), "запись уходит целиком, как в источнике")
	assert.Equal(t, "ozon:accrual-by-day:1002", records[1].SourceKey, "числовой идентификатор")
	for _, i := range []int{2, 3, 4} {
		assert.Regexp(t, `^ozon:accrual-by-day:fallback-[0-9a-f]{16}$`, records[i].SourceKey, "нет пригодного идентификатора — ключ от содержимого")
	}
	assert.NotEqual(t, records[2].SourceKey, records[3].SourceKey)

	again, err := Split(ResourceAccrualByDay, []byte(page))
	require.NoError(t, err)
	assert.Equal(t, records, again, "ключи не зависят от запуска")
}

func TestSplitEmptyDayAndRejects(t *testing.T) {
	records, err := Split(ResourceAccrualByDay, []byte(`{"accruals":[],"last_id":""}`))
	require.NoError(t, err)
	assert.Empty(t, records, "день без начислений — пустая страница, не ошибка")

	for name, tc := range map[string]struct{ resource, page string }{
		"чужой ресурс":          {"ozon.orders.fbo", `{"accruals":[]}`},
		"нет массива":           {ResourceAccrualByDay, `{"last_id":""}`},
		"не json":               {ResourceAccrualByDay, `nope`},
		"начисление не объект":  {ResourceAccrualByDay, `{"accruals":[1]}`},
		"accruals не массив":    {ResourceAccrualByDay, `{"accruals":{}}`},
		"accruals null — форма": {ResourceAccrualByDay, `{"accruals":null}`},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := Split(tc.resource, []byte(tc.page))
			require.Error(t, err)
		})
	}
}

// Ключ — только из безопасных символов, какой бы идентификатор ни пришёл.
func FuzzAccrualKey(f *testing.F) {
	for _, seed := range []string{`"1001"`, `1002`, `null`, `""`, `"a b"`, `"../x"`, `{"k":1}`, `"` + strings.Repeat("9", 200) + `"`} {
		f.Add(seed)
	}
	f.Fuzz(func(t *testing.T, id string) {
		raw, err := json.Marshal(map[string]json.RawMessage{"accrual_id": json.RawMessage(id)})
		if err != nil {
			t.Skip()
		}
		key, err := accrualKey(raw)
		require.NoError(t, err)
		assert.Regexp(t, `^ozon:accrual-by-day:[A-Za-z0-9_.-]{1,137}$`, key)
	})
}
