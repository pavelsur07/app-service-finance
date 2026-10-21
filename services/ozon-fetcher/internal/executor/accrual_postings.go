package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strings"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/store"
)

// emptyAccrualPostingsPage — служебная страница дня без отправлений: день
// загружен, запроса к Ozon нет.
const emptyAccrualPostingsPage = `{"posting_accruals":[]}`

var (
	// errOrdersNotLoaded — заказы дня ещё не загружены (not_ready).
	errOrdersNotLoaded = errors.New("executor: orders of the day are not loaded yet")
	// errOrdersRawInvalid — сохранённая страница заказов не разбирается.
	errOrdersRawInvalid = errors.New("executor: stored orders page is not readable")
)

// accrualPostings — начисления по отправлениям дня: номера FBO+FBS из
// текущей ревизии заказов того же дня, без дублей по возрастанию, порции по
// ozon.MaxAccrualPostingNumbers. Курсор — последний номер сохранённой порции:
// продолжение после сбоя идёт со следующего номера.
func (r *taskRun) accrualPostings(ctx context.Context, creds ozon.Credentials, cabinet connections.Cabinet) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		// Все порции уже сохранены прежней попыткой.
		return nil
	}
	numbers, err := r.orderPostingNumbers(ctx, cabinet)
	if err != nil {
		return err
	}
	if len(numbers) == 0 {
		return r.store(ctx, ozon.Page{No: 1, Body: []byte(emptyAccrualPostingsPage), ContentType: "application/json"})
	}

	start := 0
	if r.cursor != "" {
		start = sort.SearchStrings(numbers, r.cursor)
		if start < len(numbers) && numbers[start] == r.cursor {
			start++
		}
	}
	for i := start; i < len(numbers); i += ozon.MaxAccrualPostingNumbers {
		chunk := numbers[i:min(i+ozon.MaxAccrualPostingNumbers, len(numbers))]
		page, err := r.exec.ozon.FetchAccrualPostings(ctx, creds, r.task.ConnectionRef, chunk)
		if err != nil {
			return err
		}
		page.No = r.nextPage
		if i+len(chunk) < len(numbers) {
			page.Cursor = chunk[len(chunk)-1]
		}
		if err := r.store(ctx, page); err != nil {
			return err
		}
	}
	return nil
}

// orderPostingNumbers — номера отправлений дня из страниц заказов кабинета.
func (r *taskRun) orderPostingNumbers(ctx context.Context, cabinet connections.Cabinet) ([]string, error) {
	e := r.exec
	if e.rawReader == nil {
		return nil, errors.New("executor: raw reader is not configured")
	}
	set := map[string]bool{}
	sources := 0
	for _, resource := range []string{ResourceOrdersFBO, ResourceOrdersFBS} {
		if !cabinet.Supports(resource) {
			continue
		}
		sources++
		key := store.TaskKey{ConnectionRef: r.task.ConnectionRef, ResourceType: resource, PeriodKey: r.task.PeriodKey}
		pages, err := e.tasks.ContentPages(ctx, key)
		if errors.Is(err, store.ErrNoContent) {
			return nil, fmt.Errorf("%w: %s", errOrdersNotLoaded, resource)
		}
		if err != nil {
			return nil, &storeError{err}
		}
		for _, p := range pages {
			body, err := e.rawReader.Get(ctx, p.StorageURI, p.SHA256)
			if err != nil {
				if ctx.Err() != nil {
					return nil, ctx.Err()
				}
				return nil, &storeError{err}
			}
			found, err := postingNumbersOf(body)
			if err != nil {
				return nil, fmt.Errorf("%w: %s page %d: %v", errOrdersRawInvalid, resource, p.PageNo, err)
			}
			for _, n := range found {
				set[n] = true
			}
		}
	}
	if sources == 0 {
		// Заказы с кабинета сняты — начислениям не из чего брать номера.
		return nil, errResourceNotEnabled
	}
	out := make([]string, 0, len(set))
	for n := range set {
		out = append(out, n)
	}
	sort.Strings(out)
	return out, nil
}

// postingNumbersOf — posting_number страницы списка отправлений (верхний
// уровень или result, как у клиента).
func postingNumbersOf(body []byte) ([]string, error) {
	type posting struct {
		PostingNumber string `json:"posting_number"`
	}
	var shape struct {
		Postings *[]posting `json:"postings"`
		Result   *struct {
			Postings *[]posting `json:"postings"`
		} `json:"result"`
	}
	if err := json.Unmarshal(body, &shape); err != nil {
		return nil, err
	}
	list := shape.Postings
	if list == nil && shape.Result != nil {
		list = shape.Result.Postings
	}
	if list == nil {
		return nil, errors.New(`no "postings" array`)
	}
	out := make([]string, 0, len(*list))
	for _, p := range *list {
		n := strings.TrimSpace(p.PostingNumber)
		if n == "" {
			return nil, errors.New("posting without posting_number")
		}
		out = append(out, n)
	}
	return out, nil
}
