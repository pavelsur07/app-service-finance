package ozon

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Возвраты и отмены построчно по дню логистического возврата
// (docs/tasks/marketplace-data-ingestion/stages/ozon-10-returns.md §2).
const EndpointReturns = "/v1/returns/list"

// ReturnsPageLimit — максимум Ozon (501 → 400 «inside range (0, 500]»).
const ReturnsPageLimit = 500

// MaxReturnsPages — защитный предел страниц дня.
const MaxReturnsPages = 1000

// ReturnsPage — счётчики страницы.
type ReturnsPage struct {
	Returns int
	LastID  int64
	HasNext bool
}

// ParseReturnsPage проверяет страницу: массив returns и has_next; id —
// положительные, по возрастанию и больше afterID (повтор или откат —
// invalid_response); logistic.return_date — внутри [from; to); has_next при
// пустой странице — тихая обрезка.
func ParseReturnsPage(body []byte, afterID int64, from, to time.Time) (ReturnsPage, error) {
	var shape struct {
		Returns *[]struct {
			ID       *int64 `json:"id"`
			Logistic *struct {
				ReturnDate *time.Time `json:"return_date"`
			} `json:"logistic"`
		} `json:"returns"`
		HasNext *bool `json:"has_next"`
	}
	if err := json.Unmarshal(body, &shape); err != nil {
		return ReturnsPage{}, invalidResponse(EndpointReturns, "response is not a JSON object")
	}
	if shape.Returns == nil || shape.HasNext == nil {
		return ReturnsPage{}, invalidResponse(EndpointReturns, `response has no "returns" array and "has_next"`)
	}
	page := ReturnsPage{Returns: len(*shape.Returns), LastID: afterID, HasNext: *shape.HasNext}
	for _, r := range *shape.Returns {
		if r.ID == nil || *r.ID <= page.LastID {
			return ReturnsPage{}, invalidResponse(EndpointReturns, fmt.Sprintf("return id is missing or not above %d", page.LastID))
		}
		page.LastID = *r.ID
		if r.Logistic == nil || r.Logistic.ReturnDate == nil || r.Logistic.ReturnDate.Before(from) || !r.Logistic.ReturnDate.Before(to) {
			// Фильтр, молча проигнорированный Ozon, сохранил бы чужие дни.
			return ReturnsPage{}, invalidResponse(EndpointReturns, fmt.Sprintf("return %d is outside the requested day", *r.ID))
		}
	}
	if page.HasNext && page.Returns == 0 {
		return ReturnsPage{}, invalidResponse(EndpointReturns, "empty page with has_next")
	}
	return page, nil
}

// FetchReturns выгружает возвраты дня [from; to) по last_id, начиная с
// fromCursor ("" — с начала). Page.Cursor — last_id следующей страницы, ""
// у последней.
func (c *Client) FetchReturns(ctx context.Context, creds Credentials, connectionRef string, from, to time.Time, fromCursor string, firstPageNo int, onPage PageHandler) error {
	if !from.Before(to) {
		return fmt.Errorf("ozon: empty returns window %s..%s", from.Format(time.RFC3339), to.Format(time.RFC3339))
	}
	var lastID int64
	if s := strings.TrimSpace(fromCursor); s != "" {
		v, err := strconv.ParseInt(s, 10, 64)
		if err != nil || v <= 0 {
			return fmt.Errorf("ozon: bad returns cursor %q", fromCursor)
		}
		lastID = v
	}
	for no := firstPageNo; ; no++ {
		if no > MaxReturnsPages {
			return invalidResponse(EndpointReturns, fmt.Sprintf("more than %d pages with has_next", MaxReturnsPages))
		}
		body := map[string]any{
			"filter": map[string]any{"logistic_return_date": map[string]string{
				"time_from": from.Format(time.RFC3339),
				// Границы фильтра включительные, с миллисекундами (живая проверка
				// 24.10.2026): 23:59:59 отрезал бы возвраты последней секунды.
				"time_to": to.Add(-time.Nanosecond).Format(time.RFC3339Nano),
			}},
			"limit":   ReturnsPageLimit,
			"last_id": lastID,
		}
		resp, err := c.Post(ctx, creds, connectionRef, EndpointReturns, body)
		if err != nil {
			return err
		}
		page, err := ParseReturnsPage(resp.Body, lastID, from, to)
		if err != nil {
			return err
		}
		next := ""
		if page.HasNext {
			next = strconv.FormatInt(page.LastID, 10)
		}
		if err := onPage(Page{No: no, Body: resp.Body, Cursor: next, ContentType: "application/json"}); err != nil {
			return err
		}
		if !page.HasNext {
			return nil
		}
		lastID = page.LastID
	}
}

// Живая форма 24.10.2026.
var (
	returnsTop  = []string{"returns", "has_next"}
	returnsItem = []string{
		"id", "company_id", "return_reason_name", "type", "schema", "order_id", "order_number", "place", "target_place",
		"storage", "product", "logistic", "visual", "exemplars", "additional_info", "clearing_id", "posting_number",
		"return_clearing_id", "source_id", "compensation_status", "courier_delivery",
	}
)

// ReturnsDrift — расхождение страницы возвратов с живой формой.
func ReturnsDrift(body []byte) Drift { return shapeDrift(body, returnsTop, "returns", returnsItem) }
