// Package connections — активные кабинеты и ключи из Symfony
// (контракт services/_contracts/marketplace-connections/v1). Кэш — только в
// памяти; при недоступности Symfony сервис работает на последнем снимке.
package connections

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"sort"
	"sync"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

const (
	endpoint      = "/internal/marketplace-connections?provider=ozon"
	schemaVersion = "1"
	maxBodyBytes  = 8 << 20
	// ForceRefreshMinInterval — 401 у нескольких кабинетов не превращается в шторм запросов.
	ForceRefreshMinInterval = 30 * time.Second
)

// Cabinet — кабинет Ozon. Секрет performance в памяти не держим: сервису он не нужен.
type Cabinet struct {
	Ref            string
	CompanyID      string
	UpdatedAt      time.Time
	Seller         *ozon.Credentials // nil — ключа Seller API нет
	HasPerformance bool
	ResourceTypes  []string
}

// Supports — ресурс доступен с имеющимися ключами.
func (c Cabinet) Supports(resourceType string) bool {
	for _, rt := range c.ResourceTypes {
		if rt == resourceType {
			return true
		}
	}
	return false
}

// Client — GET снимка у Symfony.
type Client struct {
	baseURL string
	token   string
	http    *http.Client
}

func NewClient(baseURL, token string, httpClient *http.Client) *Client {
	return &Client{baseURL: baseURL, token: token, http: httpClient}
}

// ErrInvalidSnapshot — ответ не соответствует контракту v1; снимок отвергнут целиком.
var ErrInvalidSnapshot = errors.New("connections: invalid snapshot")

type wireSnapshot struct {
	SchemaVersion string        `json:"schema_version"`
	GeneratedAt   string        `json:"generated_at"`
	Connections   []wireCabinet `json:"connections"`
}

type wireCabinet struct {
	ConnectionRef string `json:"connection_ref"`
	CompanyID     string `json:"company_id"`
	Provider      string `json:"provider"`
	UpdatedAt     string `json:"updated_at"`
	Credentials   *struct {
		Seller *struct {
			ClientID string `json:"client_id"`
			APIKey   string `json:"api_key"`
		} `json:"seller"`
		Performance *struct {
			ClientID     string `json:"client_id"`
			ClientSecret string `json:"client_secret"`
		} `json:"performance"`
	} `json:"credentials"`
	ResourceTypes []string `json:"resource_types"`
}

// Fetch возвращает принятые кабинеты и ref'ы отвергнутых.
func (c *Client) Fetch(ctx context.Context) ([]Cabinet, []string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+endpoint, nil)
	if err != nil {
		return nil, nil, fmt.Errorf("connections: build request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, nil, fmt.Errorf("connections: request: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		return nil, nil, fmt.Errorf("connections: HTTP %d", resp.StatusCode)
	}
	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxBodyBytes+1))
	if err != nil {
		return nil, nil, fmt.Errorf("connections: read body: %w", err)
	}
	if len(raw) > maxBodyBytes {
		return nil, nil, fmt.Errorf("%w: body exceeds limit", ErrInvalidSnapshot)
	}
	return parse(raw)
}

func parse(raw []byte) ([]Cabinet, []string, error) {
	var snap wireSnapshot
	if err := json.Unmarshal(raw, &snap); err != nil {
		return nil, nil, fmt.Errorf("%w: not JSON", ErrInvalidSnapshot)
	}
	if snap.SchemaVersion != schemaVersion {
		return nil, nil, fmt.Errorf("%w: schema_version %q", ErrInvalidSnapshot, snap.SchemaVersion)
	}
	if _, err := time.Parse(time.RFC3339, snap.GeneratedAt); err != nil {
		return nil, nil, fmt.Errorf("%w: generated_at", ErrInvalidSnapshot)
	}
	if snap.Connections == nil {
		return nil, nil, fmt.Errorf("%w: no connections array", ErrInvalidSnapshot)
	}

	cabinets := make([]Cabinet, 0, len(snap.Connections))
	var rejected []string
	seen := map[string]bool{}
	for _, w := range snap.Connections {
		cab, ok := toCabinet(w)
		if !ok || seen[cab.Ref] {
			rejected = append(rejected, w.ConnectionRef)
			continue
		}
		seen[cab.Ref] = true
		cabinets = append(cabinets, cab)
	}
	sort.Slice(cabinets, func(i, j int) bool { return cabinets[i].Ref < cabinets[j].Ref })
	return cabinets, rejected, nil
}

func toCabinet(w wireCabinet) (Cabinet, bool) {
	if w.ConnectionRef == "" || w.CompanyID == "" || w.Provider != "ozon" || w.Credentials == nil {
		return Cabinet{}, false
	}
	updatedAt, err := time.Parse(time.RFC3339, w.UpdatedAt)
	if err != nil {
		return Cabinet{}, false
	}
	cab := Cabinet{Ref: w.ConnectionRef, CompanyID: w.CompanyID, UpdatedAt: updatedAt, ResourceTypes: w.ResourceTypes}
	if s := w.Credentials.Seller; s != nil {
		if s.ClientID == "" || s.APIKey == "" {
			return Cabinet{}, false
		}
		cab.Seller = &ozon.Credentials{ClientID: s.ClientID, APIKey: s.APIKey}
	}
	if p := w.Credentials.Performance; p != nil {
		if p.ClientID == "" || p.ClientSecret == "" {
			return Cabinet{}, false
		}
		cab.HasPerformance = true
	}
	return cab, true
}

// Fetcher — источник снимка (Client или фейк в тестах).
type Fetcher interface {
	Fetch(ctx context.Context) ([]Cabinet, []string, error)
}

// Cache — последний успешный снимок. Безопасен для параллельного чтения.
type Cache struct {
	source Fetcher
	logger *slog.Logger
	now    func() time.Time

	mu          sync.RWMutex
	byRef       map[string]Cabinet
	list        []Cabinet
	ready       bool
	lastAttempt time.Time
	refreshMu   sync.Mutex
}

func NewCache(source Fetcher, logger *slog.Logger, now func() time.Time) *Cache {
	return &Cache{source: source, logger: logger, now: now, byRef: map[string]Cabinet{}}
}

// Ready — есть хотя бы один успешный снимок.
func (c *Cache) Ready() bool {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.ready
}

// List — кабинеты последнего снимка; глубокая копия: вызывающий не меняет кэш.
func (c *Cache) List() []Cabinet {
	c.mu.RLock()
	defer c.mu.RUnlock()
	out := make([]Cabinet, len(c.list))
	for i, cab := range c.list {
		out[i] = cab.clone()
	}
	return out
}

func (c *Cache) Get(ref string) (Cabinet, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	cab, ok := c.byRef[ref]
	return cab.clone(), ok
}

func (c Cabinet) clone() Cabinet {
	if c.Seller != nil {
		seller := *c.Seller
		c.Seller = &seller
	}
	c.ResourceTypes = append([]string(nil), c.ResourceTypes...)
	return c
}

// Refresh запрашивает снимок; при сбое кэш не меняется.
func (c *Cache) Refresh(ctx context.Context) error {
	c.refreshMu.Lock()
	defer c.refreshMu.Unlock()
	return c.refreshLocked(ctx)
}

func (c *Cache) refreshLocked(ctx context.Context) error {
	started := c.now()
	c.mu.Lock()
	c.lastAttempt = started
	c.mu.Unlock()

	cabinets, rejected, err := c.source.Fetch(ctx)
	duration := c.now().Sub(started).Milliseconds()
	if err != nil {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		c.logger.Warn("connections refresh failed, keeping last snapshot",
			slog.String("stage", "connections"), slog.String("outcome", "error"),
			slog.Int64("duration_ms", duration), slog.String("error", err.Error()))
		return err
	}

	byRef := make(map[string]Cabinet, len(cabinets))
	for _, cab := range cabinets {
		byRef[cab.Ref] = cab
	}
	c.mu.Lock()
	c.byRef, c.list, c.ready = byRef, cabinets, true
	c.mu.Unlock()

	if len(rejected) > 0 {
		c.logger.Warn("connections snapshot: cabinets rejected",
			slog.String("stage", "connections"), slog.Int("rejected_count", len(rejected)), slog.Any("connection_refs", rejected))
	}
	c.logger.Info("connections refreshed",
		slog.String("stage", "connections"), slog.String("outcome", "ok"),
		slog.Int("cabinets", len(cabinets)), slog.Int64("duration_ms", duration))
	return nil
}

// ForceRefresh — после 401 от Ozon: ключ мог смениться. Не чаще
// ForceRefreshMinInterval; возвращает true, если запрос был.
func (c *Cache) ForceRefresh(ctx context.Context) (bool, error) {
	c.refreshMu.Lock()
	defer c.refreshMu.Unlock()
	c.mu.RLock()
	recent := !c.lastAttempt.IsZero() && c.now().Sub(c.lastAttempt) < ForceRefreshMinInterval
	c.mu.RUnlock()
	if recent {
		return false, nil
	}
	return true, c.refreshLocked(ctx)
}

// Run обновляет снимок сразу и затем раз в interval до отмены ctx.
func (c *Cache) Run(ctx context.Context, interval time.Duration) {
	_ = c.Refresh(ctx)
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			_ = c.Refresh(ctx)
		}
	}
}
