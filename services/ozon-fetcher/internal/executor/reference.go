package executor

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"slices"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

// MaxReferencePages — потолок страниц снимка акций, поставок или кластеров.
const MaxReferencePages = 1000

// referenceMore — курсор непоследней страницы снимка: продолжения нет,
// прерванный снимок начинается заново.
const referenceMore = "more"

// errSnapshotRestart — прерванный снимок (429, сбой, пропавшая поставка):
// страницы разных моментов не смешиваются, снимок — заново.
var errSnapshotRestart = errors.New("executor: interrupted snapshot restarts from the beginning")

// referenceSnapshot — снимок дня из последовательности страниц
// (ozon-11-actions-supplies-clusters.md): каждая страница — в raw по порядку,
// последняя — без курсора.
func (r *taskRun) referenceSnapshot(ctx context.Context, creds ozon.Credentials) error {
	if r.cursor == "" && len(r.hashes) > 0 {
		// Все страницы сохранены прежней попыткой.
		return nil
	}
	if r.cursor != "" {
		return errSnapshotRestart
	}
	return r.withinSnapshot(ctx, StocksWindowStart[r.task.ResourceType], func(ctx context.Context) error {
		var pending *ozon.Page
		count := 0
		emit := func(p ozon.Page) error {
			if count++; count > MaxReferencePages {
				return ozon.InvalidResponse(r.task.ResourceType, fmt.Sprintf("snapshot has more than %d pages", MaxReferencePages))
			}
			if pending != nil {
				pending.No, pending.Cursor = r.nextPage, referenceMore
				if err := r.store(ctx, *pending); err != nil {
					return err
				}
			}
			pending = &p
			return nil
		}
		stats, err := r.referenceSteps(ctx, creds, emit)
		if err != nil {
			return err
		}
		pending.No, pending.Cursor = r.nextPage, ""
		if err := r.store(ctx, *pending); err != nil {
			return err
		}
		attrs := []any{slog.String("stage", "fetch"), slog.Int("pages", count)}
		for _, k := range slices.Sorted(func(yield func(string) bool) {
			for k := range stats {
				if !yield(k) {
					return
				}
			}
		}) {
			attrs = append(attrs, slog.Int(k, stats[k]))
		}
		r.log.Info("reference snapshot read", attrs...)
		return nil
	})
}

func (r *taskRun) referenceSteps(ctx context.Context, creds ozon.Credentials, emit func(ozon.Page) error) (map[string]int, error) {
	e, ref := r.exec, r.task.ConnectionRef
	stats := map[string]int{}
	switch r.task.ResourceType {
	case ResourceActions:
		p, ids, err := e.ozon.FetchActions(ctx, creds, ref)
		if err != nil {
			return nil, err
		}
		if err := emit(p); err != nil {
			return nil, err
		}
		slices.Sort(ids)
		stats["actions"] = len(ids)
		for _, id := range ids {
			for _, candidates := range []bool{false, true} {
				key := "participants"
				if candidates {
					key = "candidates"
				}
				got := 0
				for last := ""; ; {
					p, page, err := e.ozon.FetchActionProducts(ctx, creds, ref, candidates, id, last)
					if err != nil {
						return nil, err
					}
					if err := emit(p); err != nil {
						return nil, err
					}
					got += page.Products
					stats[key] += page.Products
					if page.Products == 0 {
						break
					}
					if page.LastID == "" {
						// Конец без пустой страницы допустим, только если набран total:
						// иначе обход оборвался бы молча.
						if got != page.Total {
							return nil, ozon.InvalidResponse(endpoint(candidates), fmt.Sprintf("action %d: %d of %d products without last_id", id, got, page.Total))
						}
						break
					}
					last = page.LastID
				}
			}
		}
	case ResourceSupplies:
		var ids []int64
		for last := ""; ; {
			p, page, next, err := e.ozon.FetchSupplyOrderList(ctx, creds, ref, last)
			if err != nil {
				return nil, err
			}
			if err := emit(p); err != nil {
				return nil, err
			}
			ids = append(ids, page...)
			if next == "" {
				break
			}
			last = next
		}
		slices.Sort(ids)
		ids = slices.Compact(ids)
		stats["orders"] = len(ids)
		var bundles []string
		for i := 0; i < len(ids); i += ozon.MaxSupplyOrderGet {
			p, b, err := e.ozon.FetchSupplyOrders(ctx, creds, ref, ids[i:min(i+ozon.MaxSupplyOrderGet, len(ids))])
			if errors.Is(err, ozon.ErrSupplyOrderMissing) {
				return nil, fmt.Errorf("%w: %v", errSnapshotRestart, err)
			}
			if err != nil {
				return nil, err
			}
			if err := emit(p); err != nil {
				return nil, err
			}
			bundles = append(bundles, b...)
		}
		slices.Sort(bundles)
		bundles = slices.Compact(bundles)
		stats["bundles"] = len(bundles)
		for i := 0; i < len(bundles); i += ozon.SupplyBundleLimit {
			chunk := bundles[i:min(i+ozon.SupplyBundleLimit, len(bundles))]
			for last := ""; ; {
				p, page, err := e.ozon.FetchSupplyBundle(ctx, creds, ref, chunk, last)
				if ozon.IsUnknownBundle(err) {
					// Поставку отменили между деталями и составом (400 «Bundles … not found»).
					return nil, fmt.Errorf("%w: %v", errSnapshotRestart, err)
				}
				if err != nil {
					return nil, err
				}
				if err := emit(p); err != nil {
					return nil, err
				}
				stats["items"] += page.Items
				if !page.HasNext {
					break
				}
				last = page.LastID
			}
		}
	case ResourceClusters:
		for _, t := range ozon.ClusterTypes {
			p, page, err := e.ozon.FetchClusters(ctx, creds, ref, t)
			if err != nil {
				return nil, err
			}
			if err := emit(p); err != nil {
				return nil, err
			}
			stats["clusters"] += page.Clusters
			stats["warehouses"] += page.Warehouses
		}
	default:
		return nil, fmt.Errorf("%w: %s", errUnknownResource, r.task.ResourceType)
	}
	return stats, nil
}

func endpoint(candidates bool) string {
	if candidates {
		return ozon.EndpointActionCandidates
	}
	return ozon.EndpointActionProducts
}
