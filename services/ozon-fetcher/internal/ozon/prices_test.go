package ozon

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// pricesBody — живая форма 24.10.2026 (сокращена): без result, курсор cursor.
func pricesBody(cursor string, total int, ids ...int) string {
	items := make([]string, len(ids))
	for i, id := range ids {
		items[i] = fmt.Sprintf(`{"product_id":%d,"offer_id":"A-%d","acquiring":0,"commissions":{"sales_percent_fbo":19},`+
			`"marketing_actions":{"actions":[],"ozon_actions_exist":false},"price":{"price":"1000.0000","old_price":"1500.0000",`+
			`"marketing_seller_price":"900.0000","min_price":"800.0000","currency_code":"RUB","vat":"0.2"},"price_indexes":{},"volume_weight":0.4}`, id, id)
	}
	return fmt.Sprintf(`{"items":[%s],"cursor":%q,"total":%d,"total_items":%d}`, strings.Join(items, ","), cursor, total, total)
}

func TestFetchPricesWalksCursorUntilEmptyPage(t *testing.T) {
	handler, bodies := productServer(t, EndpointPrices, func(body map[string]any) (int, string) {
		switch body["cursor"] {
		case nil:
			return http.StatusOK, pricesBody("C1", 3, 1, 2)
		case "C1":
			return http.StatusOK, pricesBody("C2", 3, 3)
		default:
			return http.StatusOK, pricesBody("", 3)
		}
	})
	var pages []Page
	err := clientFor(t, handler).FetchPrices(context.Background(), testCreds, "conn-1", "", 1, func(p Page) error {
		pages = append(pages, p)
		return nil
	})

	require.NoError(t, err)
	require.Len(t, pages, 3)
	assert.Equal(t, []string{"C1", "C2", ""}, []string{pages[0].Cursor, pages[1].Cursor, pages[2].Cursor})
	assert.Equal(t, map[string]any{"filter": map[string]any{"visibility": "ALL"}, "limit": float64(PricesPageLimit)}, (*bodies)[0], "первая страница — без курсора")
	assert.Equal(t, "C2", (*bodies)[2]["cursor"])

	page, err := ParsePricesPage(pages[0].Body)
	require.NoError(t, err)
	assert.Equal(t, PricesPage{Items: 2, Total: 3, Next: "C1"}, page)
}

func TestFetchPricesEmptyPageEndsEvenWithCursor(t *testing.T) {
	handler, _ := productServer(t, EndpointPrices, func(map[string]any) (int, string) {
		return http.StatusOK, pricesBody("C9", 0)
	})
	var pages []Page
	err := clientFor(t, handler).FetchPrices(context.Background(), testCreds, "conn-1", "", 1, func(p Page) error {
		pages = append(pages, p)
		return nil
	})
	require.NoError(t, err)
	require.Len(t, pages, 1)
	assert.Empty(t, pages[0].Cursor)
}

func TestFetchPricesRejectsBrokenPages(t *testing.T) {
	cases := map[string]string{
		"not object":              `[]`,
		"no items":                `{"total":1,"cursor":""}`,
		"no cursor":               `{"items":[{"product_id":1}],"total":1}`,
		"no total":                `{"items":[{"product_id":1}],"cursor":"C5"}`,
		"neg total":               `{"items":[],"total":-1,"cursor":""}`,
		"item without product_id": `{"items":[{"offer_id":"x"}],"total":1,"cursor":"C5"}`,
		"repeated cursor":         pricesBody("C0", 2, 1),
		"items without cursor":    pricesBody("", 1500, 1, 2),
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			handler, _ := productServer(t, EndpointPrices, func(map[string]any) (int, string) { return http.StatusOK, body })
			err := clientFor(t, handler).FetchPrices(context.Background(), testCreds, "conn-1", "C0", 2, func(Page) error { return nil })
			var apiErr *APIError
			require.ErrorAs(t, err, &apiErr)
			assert.Equal(t, ClassInvalidResponse, apiErr.Class)
		})
	}
}

func TestFetchPricesPageLimitIsAbsolute(t *testing.T) {
	handler, bodies := productServer(t, EndpointPrices, func(map[string]any) (int, string) {
		return http.StatusOK, pricesBody("C-next", 1, 1)
	})
	err := clientFor(t, handler).FetchPrices(context.Background(), testCreds, "conn-1", "C", MaxPricesPages+1, func(Page) error { return nil })
	var apiErr *APIError
	require.ErrorAs(t, err, &apiErr)
	assert.Equal(t, ClassInvalidResponse, apiErr.Class)
	assert.Empty(t, *bodies, "предел — до запроса")
}
