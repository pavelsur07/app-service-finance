// Package executor — адаптер Ozon к исполнителю fetchkit/fetch: ресурсы Ozon,
// их ошибки и исходы (docs/tasks/ozon-fetcher-ingestion/stages/stage-9.md).
// Механизм задачи (lease, страницы → S3 → журнал, ревизия, общая таблица
// исходов) — в ядре.
package executor

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/fetch"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/rawstore"
	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/store"
)

// Ресурсы первой волны (contract marketplace-connections v1).
const (
	ResourceAccrualByDay     = "ozon.finance.accrual-by-day"
	ResourceAccrualTypes     = "ozon.finance.accrual-types"
	ResourceRealization      = "ozon.finance.realization"
	ResourceMutualSettlement = "ozon.finance.mutual-settlement"

	// Отправления по дате создания; period_key — день МСК. Текущий статус —
	// в каждом отправлении, смены видны ревизиями перечитанных дней.
	ResourceOrdersFBO = "ozon.orders.fbo"
	ResourceOrdersFBS = "ozon.orders.fbs"

	// Начисления по отправлениям дня (period_key — день МСК создания
	// отправлений): номера — из загруженных страниц заказов того же дня.
	ResourceAccrualPostings = "ozon.finance.accrual-postings"

	// Каталог товаров — снимок дня (period_key — день МСК загрузки): список
	// всех товаров и подробности по product_id из списка того же дня.
	ResourceProductsList = "ozon.products.list"
	ResourceProductsInfo = "ozon.products.info"

	// Остатки — снимок дня (period_key — день МСК снимка) с 05:00; конец
	// недели — снимок понедельника («неделя готова» в понедельник 06:00).
	ResourceStocks = "ozon.stocks"

	// Цены и остатки по складам — снимки дня с 05:00
	// (ozon-8-prices-warehouse-stocks.md): цены — по всем товарам, остатки по
	// складам — по SKU снимка ozon.stocks того же дня.
	ResourcePrices           = "ozon.prices"
	ResourceStocksWarehouses = "ozon.stocks.warehouses"

	// Поисковая аналитика Premium по SKU за день МСК (ozon-9-search-analytics.md):
	// сводка и топ запросов; SKU — из снимка ozon.stocks дня исполнения.
	ResourceSearchQueries = "ozon.analytics.queries"
	ResourceSearchDetails = "ozon.analytics.queries.details"

	// Возвраты и отмены построчно — день МСК логистического возврата
	// (ozon-10-returns.md), перечитывается 30 дней.
	ResourceReturns = "ozon.returns"

	// Снимки дня с 05:00 (ozon-11-actions-supplies-clusters.md): акции с
	// товарами, активные поставки с составом, кластеры и склады Ozon.
	ResourceActions  = "ozon.actions"
	ResourceSupplies = "ozon.supplies"
	ResourceClusters = "ozon.clusters"

	// PeriodRef — period_key справочников.
	PeriodRef = "ref"
)

// postingResources — схема списка отправлений ресурса.
var postingResources = map[string]ozon.PostingScheme{
	ResourceOrdersFBO: ozon.SchemeFBO,
	ResourceOrdersFBS: ozon.SchemeFBS,
}

// gatedResources — ресурсы, которые Symfony выдаёт кабинетам по списку
// компаний: снятый с кабинета ресурс не грузится и по поставленным задачам.
var gatedResources = map[string]bool{
	ResourceOrdersFBO: true, ResourceOrdersFBS: true, ResourceAccrualPostings: true,
	ResourceProductsList: true, ResourceProductsInfo: true,
	ResourceStocks: true, ResourcePrices: true, ResourceStocksWarehouses: true,
	ResourceSearchQueries: true, ResourceSearchDetails: true,
	ResourceReturns: true, ResourceActions: true, ResourceSupplies: true, ResourceClusters: true,
}

// IsPostingResource — ресурс списков отправлений.
func IsPostingResource(resourceType string) bool {
	_, ok := postingResources[resourceType]
	return ok
}

// TaskStore — часть store.Tasks, нужная исполнителю.
type TaskStore interface {
	ExtendLease(ctx context.Context, key store.TaskKey, attemptID string, until time.Time) error
	RecordPage(ctx context.Context, key store.TaskKey, attemptID string, obj store.RawObject, nextCursor string) error
	FindObject(ctx context.Context, key store.TaskKey, sha256 string) (store.RawObject, bool, error)
	FinishTask(ctx context.Context, key store.TaskKey, attemptID string, out store.Outcome, now time.Time) (int, error)
	RecordAuthFailure(ctx context.Context, connectionRef string, now time.Time, pauseFor func(failures int) time.Duration) (int, error)
	ResumeConnection(ctx context.Context, connectionRef string) error
	StopResource(ctx context.Context, resourceType, reason string) error
	ContentPages(ctx context.Context, key store.TaskKey) (store.Content, error)
	ReloadWithoutContent(ctx context.Context, key store.TaskKey, now time.Time) (bool, error)
	SaveCursor(ctx context.Context, key store.TaskKey, attemptID, cursor string) error
	AdsReportInFlight(ctx context.Context, except store.TaskKey) (bool, error)
}

// RawReader — чтение сохранённой страницы (зависимые ресурсы).
type RawReader interface {
	Get(ctx context.Context, uri, sha256 string) ([]byte, error)
}

// RawStore — запись страницы в S3.
type RawStore interface {
	Put(ctx context.Context, obj rawstore.Object) (rawstore.Stored, error)
}

// Ozon — загрузки ресурсов первой волны.
type Ozon interface {
	FetchAccrualByDay(ctx context.Context, creds ozon.Credentials, connectionRef, day, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
	FetchAccrualTypes(ctx context.Context, creds ozon.Credentials, connectionRef string) (ozon.Page, error)
	FetchRealization(ctx context.Context, creds ozon.Credentials, connectionRef string, year, month int) (ozon.Page, error)
	FetchMutualSettlement(ctx context.Context, creds ozon.Credentials, connectionRef string, year, month int, poll ozon.ReportPoll, sleep func(context.Context, time.Duration) error) (ozon.Page, error)
	FetchPostingList(ctx context.Context, creds ozon.Credentials, connectionRef string, scheme ozon.PostingScheme, w ozon.PostingWindow, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
	FetchAccrualPostings(ctx context.Context, creds ozon.Credentials, connectionRef string, numbers []string) (ozon.Page, error)
	FetchProductList(ctx context.Context, creds ozon.Credentials, connectionRef, fromLastID string, firstPageNo int, onPage ozon.PageHandler) error
	FetchProductInfo(ctx context.Context, creds ozon.Credentials, connectionRef string, ids []int64) (ozon.Page, int, error)
	FetchStocks(ctx context.Context, creds ozon.Credentials, connectionRef, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
	FetchPrices(ctx context.Context, creds ozon.Credentials, connectionRef, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
	FetchWarehouseStocks(ctx context.Context, creds ozon.Credentials, connectionRef string, skus []int64) (ozon.Page, ozon.WarehouseStocks, error)
	FetchSearchPage(ctx context.Context, creds ozon.Credentials, connectionRef string, details bool, day time.Time, skus []int64, page int) (ozon.Page, ozon.SearchPage, error)
	FetchReturns(ctx context.Context, creds ozon.Credentials, connectionRef string, from, to time.Time, fromCursor string, firstPageNo int, onPage ozon.PageHandler) error
	FetchActions(ctx context.Context, creds ozon.Credentials, connectionRef string) (ozon.Page, []int64, error)
	FetchActionProducts(ctx context.Context, creds ozon.Credentials, connectionRef string, candidates bool, actionID int64, lastID string) (ozon.Page, ozon.ActionProductsPage, error)
	FetchSupplyOrderList(ctx context.Context, creds ozon.Credentials, connectionRef, lastID string) (ozon.Page, []int64, string, error)
	FetchSupplyOrders(ctx context.Context, creds ozon.Credentials, connectionRef string, ids []int64) (ozon.Page, []string, error)
	FetchSupplyBundle(ctx context.Context, creds ozon.Credentials, connectionRef string, bundleIDs []string, lastID string) (ozon.Page, ozon.SupplyBundlePage, error)
	FetchClusters(ctx context.Context, creds ozon.Credentials, connectionRef, clusterType string) (ozon.Page, ozon.ClustersPage, error)
}

// Refresher — обновление снимка кабинетов после 401.
type Refresher interface {
	ForceRefresh(ctx context.Context) (bool, error)
}

// Config — тайминги исполнителя.
type Config struct {
	Lease           time.Duration // срок lease; продление — раз в треть
	FinishTimeout   time.Duration // запись итога после отмены
	BackoffBase     time.Duration // transient: base·2^(n−1)
	BackoffMax      time.Duration
	RateLimitPause  time.Duration // 429 без Retry-After
	AuthRetryPause  time.Duration // пауза кабинета после первого auth: снимок с новым ключом успевает обновиться
	AuthSuspend     time.Duration // пауза кабинета на втором auth подряд
	NoCredentialsIn time.Duration // повтор задачи без ключа seller
	NotReadyDelay   map[string]time.Duration
	ReportPoll      ozon.ReportPoll
	Location        *time.Location // границы дня отправлений (Europe/Moscow)

	// Отчёты рекламы (ozon-7-ads-reports.md): окно заказа [From; To) после
	// полуночи МСК, опрос, длина попытки, срок до отказа от отчёта, повтор
	// после ошибки Ozon, ожидание чужого отчёта аккаунта, ожидание кампаний дня.
	AdsReportWindowFrom time.Duration
	AdsReportWindowTo   time.Duration
	AdsReportPoll       time.Duration
	AdsReportAttempt    time.Duration
	AdsReportAbandon    time.Duration
	AdsReportRetry      time.Duration
	AdsReportBusy       time.Duration
	// AdsReportReservation — срок резерва заказа без номера отчёта (исход заказа неизвестен).
	AdsReportReservation time.Duration
	AdsCampaignsWait     time.Duration
}

// DefaultConfig — значения плана (stage-9.md).
func DefaultConfig() Config {
	return Config{
		Lease:                2 * time.Minute,
		FinishTimeout:        10 * time.Second,
		BackoffBase:          time.Minute,
		BackoffMax:           time.Hour,
		RateLimitPause:       30 * time.Second,
		AuthRetryPause:       time.Minute,
		AuthSuspend:          time.Hour,
		NoCredentialsIn:      time.Hour,
		AdsReportWindowFrom:  30 * time.Minute,
		AdsReportWindowTo:    4 * time.Hour,
		AdsReportPoll:        30 * time.Second,
		AdsReportAttempt:     20 * time.Minute,
		AdsReportAbandon:     3 * time.Hour,
		AdsReportRetry:       30 * time.Minute,
		AdsReportBusy:        5 * time.Minute,
		AdsReportReservation: time.Hour,
		AdsCampaignsWait:     30 * time.Minute,
		NotReadyDelay: map[string]time.Duration{
			ResourceAccrualByDay:     time.Hour,
			ResourceAccrualTypes:     time.Hour,
			ResourceRealization:      24 * time.Hour,
			ResourceMutualSettlement: 24 * time.Hour,
			ResourceAccrualPostings:  time.Hour,        // заказы дня ещё не загружены
			ResourceProductsInfo:     10 * time.Minute, // список дня грузится за секунды
			ResourceStocksWarehouses: 10 * time.Minute, // снимок остатков дня — секунды после 05:00
			ResourceSearchQueries:    6 * time.Hour,    // Ozon ещё не посчитал день
			ResourceSearchDetails:    6 * time.Hour,
		},
		ReportPoll: ozon.DefaultReportPoll,
		// Москва без перехода на летнее время с 2014 г.; приложение передаёт
		// загруженную Europe/Moscow.
		Location: time.FixedZone("MSK", 3*60*60),
	}
}

// Executor — без состояния задачи; безопасен для параллельных Run.
type Executor struct {
	cfg       Config
	tasks     TaskStore
	raw       RawStore
	rawReader RawReader
	ozon      Ozon
	perf      Performance
	refresher Refresher
	logger    *slog.Logger
	now       func() time.Time
	jitter    func(time.Duration) time.Duration // [0; d)
	sleep     func(context.Context, time.Duration) error
}

func New(cfg Config, tasks TaskStore, raw RawStore, client Ozon, refresher Refresher, logger *slog.Logger,
	now func() time.Time, jitter func(time.Duration) time.Duration, sleep func(context.Context, time.Duration) error) *Executor {
	return &Executor{cfg: cfg, tasks: tasks, raw: raw, ozon: client, refresher: refresher, logger: logger, now: now, jitter: jitter, sleep: sleep}
}

// WithRawReader — копия с чтением сохранённых страниц (начисления по
// отправлениям, подробности товаров).
func (e *Executor) WithRawReader(r RawReader) *Executor {
	clone := *e
	clone.rawReader = r
	return &clone
}

// Result — итог для диспетчера и логов.
type Result = fetch.Result

// Run выполняет задачу через ядро; конфигурация читается на каждый запуск.
func (e *Executor) Run(ctx context.Context, task store.Task, cabinet connections.Cabinet, traceID string) Result {
	return fetch.New[connections.Cabinet](e.coreConfig(), e.tasks, e.raw, e.refresher, e, e.logger, e.now, e.jitter).
		Run(ctx, task, cabinet, traceID)
}

func (e *Executor) coreConfig() fetch.Config {
	return fetch.Config{
		Provider: ozon.Source, Lease: e.cfg.Lease, FinishTimeout: e.cfg.FinishTimeout,
		BackoffBase: e.cfg.BackoffBase, BackoffMax: e.cfg.BackoffMax, RateLimitPause: e.cfg.RateLimitPause,
		AuthRetryPause: e.cfg.AuthRetryPause, AuthSuspend: e.cfg.AuthSuspend, NoCredentialsIn: e.cfg.NoCredentialsIn,
		NotReadyDelay: e.cfg.NotReadyDelay,
	}
}

// Fetch — загрузка задачи ресурсом Ozon (fetch.Adapter).
func (e *Executor) Fetch(ctx context.Context, run *fetch.Run, cabinet connections.Cabinet) error {
	return (&taskRun{Run: run, exec: e}).fetch(ctx, cabinet)
}

// taskRun — состояние попытки ядра плюс исполнитель Ozon.
type taskRun struct {
	*fetch.Run
	exec *Executor
}

var (
	// errNoCredentials — у кабинета нет ключа seller для финансового ресурса.
	errNoCredentials = fetch.ErrNoCredentials
	// errResourceNotEnabled — ресурс снят с кабинета в снимке Symfony.
	errResourceNotEnabled = fetch.ErrResourceNotEnabled
)

func (r *taskRun) fetch(ctx context.Context, cabinet connections.Cabinet) error {
	if IsAdsResource(r.Task.ResourceType) {
		return r.fetchAds(ctx, cabinet)
	}
	if cabinet.Seller == nil {
		return errNoCredentials
	}
	creds := *cabinet.Seller
	ref := r.Task.ConnectionRef
	e := r.exec

	switch r.Task.ResourceType {
	case ResourceAccrualByDay:
		if r.Cursor == "" && len(r.Hashes) > 0 {
			// Все страницы дня уже сохранены прежней попыткой (упала запись
			// итога): загружать день заново — удвоить страницы и хеш.
			return nil
		}
		return e.ozon.FetchAccrualByDay(ctx, creds, ref, r.Task.PeriodKey, r.Cursor, r.NextPage, func(p ozon.Page) error {
			return r.Store(ctx, p)
		})
	case ResourceAccrualTypes:
		return r.Single(ctx, func() (ozon.Page, error) { return e.ozon.FetchAccrualTypes(ctx, creds, ref) })
	case ResourceRealization, ResourceMutualSettlement:
		year, month, err := parseMonth(r.Task.PeriodKey)
		if err != nil {
			return err
		}
		if r.Task.ResourceType == ResourceRealization {
			return r.Single(ctx, func() (ozon.Page, error) { return e.ozon.FetchRealization(ctx, creds, ref, year, month) })
		}
		return r.Single(ctx, func() (ozon.Page, error) {
			return e.ozon.FetchMutualSettlement(ctx, creds, ref, year, month, e.cfg.ReportPoll, e.sleep)
		})
	default:
		// Отправления, начисления по ним и каталог включаются по кабинетам (снимок
		// Symfony): снятый с кабинета ресурс не грузится и по уже поставленным задачам.
		if gatedResources[r.Task.ResourceType] && !cabinet.Supports(r.Task.ResourceType) {
			return errResourceNotEnabled
		}
		if scheme, ok := postingResources[r.Task.ResourceType]; ok {
			return r.postings(ctx, creds, scheme)
		}
		switch r.Task.ResourceType {
		case ResourceAccrualPostings:
			return r.accrualPostings(ctx, creds, cabinet)
		case ResourceProductsList:
			return r.productList(ctx, creds)
		case ResourceProductsInfo:
			return r.productInfo(ctx, creds, cabinet)
		case ResourceStocks:
			return r.stocks(ctx, creds)
		case ResourcePrices:
			return r.prices(ctx, creds)
		case ResourceStocksWarehouses:
			return r.warehouseStocks(ctx, creds, cabinet)
		case ResourceSearchQueries, ResourceSearchDetails:
			return r.searchAnalytics(ctx, creds, cabinet)
		case ResourceReturns:
			return r.returns(ctx, creds)
		case ResourceActions, ResourceSupplies, ResourceClusters:
			return r.referenceSnapshot(ctx, creds)
		}
		return fmt.Errorf("%w: %s", errUnknownResource, r.Task.ResourceType)
	}
}

// postings — отправления дня МСК [00:00; 00:00 следующего дня) постранично.
func (r *taskRun) postings(ctx context.Context, creds ozon.Credentials, scheme ozon.PostingScheme) error {
	if r.Cursor == "" && len(r.Hashes) > 0 {
		// Все страницы окна уже сохранены прежней попыткой.
		return nil
	}
	from, err := time.ParseInLocation(time.DateOnly, r.Task.PeriodKey, r.exec.cfg.Location)
	if err != nil {
		return fmt.Errorf("%w: %q", errBadPeriod, r.Task.PeriodKey)
	}
	w := ozon.PostingWindow{From: from, To: from.AddDate(0, 0, 1)}
	return r.exec.ozon.FetchPostingList(ctx, creds, r.Task.ConnectionRef, scheme, w, r.Cursor, r.NextPage, func(p ozon.Page) error {
		return r.Store(ctx, p)
	})
}

var (
	errUnknownResource = fetch.ErrUnknownResource
	errBadPeriod       = fetch.ErrBadPeriod
)

// contentHash — sha256 упорядоченного списка хешей страниц загрузки.
var contentHash = fetch.ContentHash

var sha256Hex = fetch.SHA256Hex

func parseMonth(periodKey string) (int, int, error) { return fetch.ParseMonth(periodKey) }
