// Package httpapi — HTTP ozon-fetcher поверх fetchkit/httpapi: /health,
// /ready, /internal/fetch-status, /internal/coverage. Здесь — какие кабинеты
// ожидают ресурс: seller-ресурсы — с ключом Seller API, реклама — с ключом
// Performance.
package httpapi

import (
	"context"
	"log/slog"
	"net/http"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/executor"
	fkhttp "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/httpapi"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

// Service — имя сервиса в ответе покрытия.
const Service = "ozon-fetcher"

// S3PingCache — результат проверки S3 держится 30 с: /ready дёргают часто.
const S3PingCache = fkhttp.S3PingCache

// Deps — источники состояния; nil — часть выключена (режим scaffold).
type Deps struct {
	// Token — SERVICE_API_TOKEN; пусто — /internal/* закрыт.
	Token         string
	DBPing        func(ctx context.Context) error
	CabinetsReady func() bool
	S3Ping        func(ctx context.Context) error
	Status        func(ctx context.Context, now time.Time, windowFrom string) (store.StatusReport, error)
	Cabinets      func() []connections.Cabinet
	Coverage      func(ctx context.Context, refs []string, from, to string) ([]store.CoverageRow, error)
	Location      *time.Location
	Now           func() time.Time
	Logger        *slog.Logger
}

func New(d Deps) http.Handler {
	deps := fkhttp.Deps{Service: Service, Token: d.Token, DBPing: d.DBPing, CabinetsReady: d.CabinetsReady, S3Ping: d.S3Ping,
		Status: d.Status, Coverage: d.Coverage, Location: d.Location, Now: d.Now, Logger: d.Logger}
	if d.Cabinets != nil {
		deps.Expected = func() fkhttp.Expectation { return expectation(d.Cabinets()) }
	}
	return fkhttp.New(deps)
}

// expectation — кабинеты с ключом Seller или Performance; ресурс ожидается у
// кабинета, только если есть его ключ. cabinets_total — кабинеты с ключом
// Seller: запасное «ожидается» карты Symfony для отчётов продаж без строк.
func expectation(cabinets []connections.Cabinet) fkhttp.Expectation {
	exp := fkhttp.Expectation{ByResource: map[string]int{}}
	for _, cab := range cabinets {
		if cab.Seller == nil && !cab.HasPerformance {
			continue
		}
		if cab.Seller != nil {
			exp.Total++
		}
		exp.Refs = append(exp.Refs, cab.Ref)
		seen := map[string]bool{}
		for _, rt := range cab.ResourceTypes {
			ads := executor.IsAdsResource(rt)
			if (ads && !cab.HasPerformance) || (!ads && cab.Seller == nil) {
				continue
			}
			if !seen[rt] {
				seen[rt] = true
				exp.ByResource[rt]++
			}
		}
	}
	return exp
}
