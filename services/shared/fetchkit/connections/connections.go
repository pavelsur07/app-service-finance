// Package connections — активные кабинеты и ключи из Symfony
// (контракт services/_contracts/marketplace-connections/v1). Кэш — только в
// памяти; при недоступности Symfony сервис работает на последнем снимке.
// Тип кабинета и разбор ключей — у сервиса (провайдер), здесь — запрос,
// проверка снимка, кэш и allowlist.
package connections

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"sync"
	"time"
)

const (
	path          = "/internal/marketplace-connections"
	schemaVersion = "1"
	maxBodyBytes  = 8 << 20
	// ForceRefreshMinInterval — 401 у нескольких кабинетов не превращается в шторм запросов.
	ForceRefreshMinInterval = 30 * time.Second
)

// Cabinet — кабинет провайдера. Ключи — только в памяти.
type Cabinet[C any] interface {
	CabinetRef() string
	CabinetCompany() string
	// Clone — глубокая копия: вызывающий не меняет кэш.
	Clone() C
}

// Wire — запись снимка; credentials разбирает провайдер.
type Wire struct {
	ConnectionRef string          `json:"connection_ref"`
	CompanyID     string          `json:"company_id"`
	Provider      string          `json:"provider"`
	UpdatedAt     string          `json:"updated_at"`
	Credentials   json.RawMessage `json:"credentials"`
	ResourceTypes []string        `json:"resource_types"`
}

// Decoder — кабинет из записи с проверенными общими полями. false — запись
// отвергнута (кабинет пропускается); ошибка — снимок отвергнут целиком
// (ключи не той формы — нарушение контракта).
type Decoder[C any] func(w Wire, updatedAt time.Time) (C, bool, error)

// Client — GET снимка провайдера у Symfony.
type Client[C Cabinet[C]] struct {
	baseURL  string
	token    string
	provider string
	decode   Decoder[C]
	http     *http.Client
}

func NewClient[C Cabinet[C]](baseURL, token, provider string, decode Decoder[C], httpClient *http.Client) *Client[C] {
	return &Client[C]{baseURL: baseURL, token: token, provider: provider, decode: decode, http: httpClient}
}

// ErrInvalidSnapshot — ответ не соответствует контракту v1; снимок отвергнут целиком.
var ErrInvalidSnapshot = errors.New("connections: invalid snapshot")

type wireSnapshot struct {
	SchemaVersion string `json:"schema_version"`
	GeneratedAt   string `json:"generated_at"`
	Connections   []Wire `json:"connections"`
}

// Fetch возвращает принятые кабинеты и ref'ы отвергнутых.
func (c *Client[C]) Fetch(ctx context.Context) ([]C, []string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+path+"?provider="+url.QueryEscape(c.provider), nil)
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
	return Parse(raw, c.provider, c.decode)
}

// Parse — проверка снимка v1: кабинеты провайдера и ref'ы отвергнутых.
func Parse[C Cabinet[C]](raw []byte, provider string, decode Decoder[C]) ([]C, []string, error) {
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

	cabinets := make([]C, 0, len(snap.Connections))
	var rejected []string
	seen := map[string]bool{}
	for _, w := range snap.Connections {
		cab, ok, err := toCabinet(w, provider, decode)
		if err != nil {
			return nil, nil, err
		}
		if !ok || seen[cab.CabinetRef()] {
			rejected = append(rejected, w.ConnectionRef)
			continue
		}
		seen[cab.CabinetRef()] = true
		cabinets = append(cabinets, cab)
	}
	sort.Slice(cabinets, func(i, j int) bool { return cabinets[i].CabinetRef() < cabinets[j].CabinetRef() })
	return cabinets, rejected, nil
}

func toCabinet[C Cabinet[C]](w Wire, provider string, decode Decoder[C]) (C, bool, error) {
	var zero C
	if w.ConnectionRef == "" || w.CompanyID == "" || w.Provider != provider || len(w.Credentials) == 0 || string(w.Credentials) == "null" {
		return zero, false, nil
	}
	updatedAt, err := time.Parse(time.RFC3339, w.UpdatedAt)
	if err != nil {
		return zero, false, nil
	}
	return decode(w, updatedAt)
}

// Fetcher — источник снимка (Client или фейк в тестах).
type Fetcher[C any] interface {
	Fetch(ctx context.Context) ([]C, []string, error)
}

// Cache — последний успешный снимок. Безопасен для параллельного чтения.
type Cache[C Cabinet[C]] struct {
	source Fetcher[C]
	logger *slog.Logger
	now    func() time.Time

	mu          sync.RWMutex
	byRef       map[string]C
	list        []C
	ready       bool
	lastAttempt time.Time
	refreshMu   sync.Mutex
	allow       map[string]bool // пусто — все кабинеты
}

func NewCache[C Cabinet[C]](source Fetcher[C], logger *slog.Logger, now func() time.Time) *Cache[C] {
	return &Cache[C]{source: source, logger: logger, now: now, byRef: map[string]C{}}
}

// WithAllowlist — грузить только кабинеты, чей connection_ref или company_id
// в списке (поэтапное включение на проде); пустой список — все.
func (c *Cache[C]) WithAllowlist(ids []string) *Cache[C] {
	c.allow = map[string]bool{}
	for _, id := range ids {
		if id = strings.TrimSpace(id); id != "" {
			c.allow[id] = true
		}
	}
	return c
}

func (c *Cache[C]) allowed(cab C) bool {
	return len(c.allow) == 0 || c.allow[cab.CabinetRef()] || c.allow[cab.CabinetCompany()]
}

// Ready — есть хотя бы один успешный снимок.
func (c *Cache[C]) Ready() bool {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.ready
}

// List — кабинеты последнего снимка; глубокая копия: вызывающий не меняет кэш.
func (c *Cache[C]) List() []C {
	c.mu.RLock()
	defer c.mu.RUnlock()
	out := make([]C, len(c.list))
	for i, cab := range c.list {
		out[i] = cab.Clone()
	}
	return out
}

func (c *Cache[C]) Get(ref string) (C, bool) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	cab, ok := c.byRef[ref]
	if !ok {
		var zero C
		return zero, false
	}
	return cab.Clone(), true
}

// Refresh запрашивает снимок; при сбое кэш не меняется.
func (c *Cache[C]) Refresh(ctx context.Context) error {
	c.refreshMu.Lock()
	defer c.refreshMu.Unlock()
	return c.refreshLocked(ctx)
}

func (c *Cache[C]) refreshLocked(ctx context.Context) error {
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

	kept := cabinets[:0:0]
	for _, cab := range cabinets {
		if c.allowed(cab) {
			kept = append(kept, cab)
		}
	}
	cabinets = kept
	byRef := make(map[string]C, len(cabinets))
	for _, cab := range cabinets {
		byRef[cab.CabinetRef()] = cab
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

// ForceRefresh — после 401 источника: ключ мог смениться. Не чаще
// ForceRefreshMinInterval; возвращает true, если запрос был.
func (c *Cache[C]) ForceRefresh(ctx context.Context) (bool, error) {
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
func (c *Cache[C]) Run(ctx context.Context, interval time.Duration) {
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
