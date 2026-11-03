// Package limits — лимиты методов Ozon для fetchkit/ratelimit
// (docs/ozon-api.md «Лимиты»).
package limits

import (
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozonperf"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/ratelimit"
)

// Registry реализует ozon.Limiter.
var _ ozon.Limiter = (*ratelimit.Registry)(nil)

// DefaultConfig — консервативные дефолты docs/ozon-api.md: Ozon не
// публикует лимиты финансовых методов.
func DefaultConfig() ratelimit.Config {
	return ratelimit.Config{
		Endpoints: map[string]ratelimit.EndpointLimit{
			// Реальный кабинет 17.10.2026: 429 уже при 0,3 запроса/с (docs/ozon-api.md).
			ozon.EndpointAccrualByDay:     {Start: 0.3, Floor: 0.05, Ceiling: 2, DefaultPause: 10 * time.Second},
			ozon.EndpointAccrualTypes:     {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozon.EndpointRealization:      {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 900 * time.Second},
			ozon.EndpointMutualSettlement: {Start: 0.1, Floor: 0.02, Ceiling: 0.5, DefaultPause: 900 * time.Second},
			ozon.EndpointReportInfo:       {Start: 0.5, Floor: 0.1, Ceiling: 2, DefaultPause: 30 * time.Second},
			// Списки отправлений: ключ кабинета общий с Symfony, пока тот не
			// отключён (docs/tasks/marketplace-data-ingestion/plan-ozon.md) — старт ниже обычного.
			ozon.EndpointPostingFBOList: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozon.EndpointPostingFBSList: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Замер 22.10.2026: 50 запросов на окно 5 с на всех клиентов метода,
			// окно пустеет за 1–3 с — запрос раз в окно (docs/ozon-api.md).
			ozon.EndpointAccrualPostings: {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			// Каталог: 2–3 запроса на кабинет в сутки.
			ozon.EndpointProductList:     {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozon.EndpointProductInfoList: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Реклама (Performance API): лимиты не опубликованы, единицы запросов на кабинет в сутки.
			ozonperf.EndpointCampaigns: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozonperf.EndpointExpense:   {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozonperf.EndpointDaily:     {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Заказ отчёта — 1 в 120 с на кабинет (разнос как у Symfony); 429 на
			// заказ исполнитель переносит на следующее окно.
			ozonperf.EndpointStatistics: {Start: 1.0 / 120, Floor: 1.0 / 120, Ceiling: 1.0 / 120, DefaultPause: 120 * time.Second},
			// Опрос статуса лимитом Ozon не ограничен (опыт Symfony); скачивание — редко.
			ozonperf.EndpointStatisticsStatus: {Start: 0.5, Floor: 0.1, Ceiling: 1, DefaultPause: 30 * time.Second},
			ozonperf.EndpointStatisticsReport: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Остатки: 2 запроса на снимок.
			ozon.EndpointStocks: {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			// Цены и остатки по складам: 2–5 запросов на снимок; квота ключа общая
			// с другими клиентами (замер 24.10.2026: ratelimit-remaining скачет
			// 49 → 21 → 44) — 429 только пауза (ozon-8-prices-warehouse-stocks.md).
			ozon.EndpointPrices:          {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			ozon.EndpointWarehouseStocks: {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			// Поисковая аналитика: квота общая, у детализации — предел «в секунду»
			// (429 code 8, замер 24.10.2026) — ozon-9-search-analytics.md.
			ozon.EndpointSearchQueries:        {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			ozon.EndpointSearchQueriesDetails: {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			// Возвраты: страница на день, ключ общий (ozon-10-returns.md).
			ozon.EndpointReturns: {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			// Акции, поставки, кластеры: единицы запросов на снимок, ключ общий
			// (ozon-11-actions-supplies-clusters.md).
			ozon.EndpointActions:           {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			ozon.EndpointActionProducts:    {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			ozon.EndpointActionCandidates:  {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			ozon.EndpointSupplyOrderList:   {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			ozon.EndpointSupplyOrderGet:    {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			ozon.EndpointSupplyOrderBundle: {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
			ozon.EndpointClusters:          {Start: 0.2, Floor: 0.2, Ceiling: 0.2, DefaultPause: 5 * time.Second, SharedQuota: true},
		},
		Fallback:     ratelimit.EndpointLimit{Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
		GrowAfter:    20,
		IncreaseStep: 0.1,
	}
}
