// Package connections — кабинеты Ozon из Symfony (контракт
// services/_contracts/marketplace-connections/v1): тип кабинета и разбор ключей
// Seller и Performance поверх fetchkit/connections.
package connections

import (
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozonperf"
	fkconn "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/connections"
)

// Provider — значение provider в снимке и запросе.
const Provider = "ozon"

// ForceRefreshMinInterval — 401 у нескольких кабинетов не превращается в шторм запросов.
const ForceRefreshMinInterval = fkconn.ForceRefreshMinInterval

// ErrInvalidSnapshot — ответ не соответствует контракту v1; снимок отвергнут целиком.
var ErrInvalidSnapshot = fkconn.ErrInvalidSnapshot

// Cabinet — кабинет Ozon. Ключи — только в памяти, при печати скрыты.
type Cabinet struct {
	Ref            string
	CompanyID      string
	UpdatedAt      time.Time
	Seller         *ozon.Credentials     // nil — ключа Seller API нет
	Performance    *ozonperf.Credentials // nil — ключа Performance API нет
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

func (c Cabinet) CabinetRef() string     { return c.Ref }
func (c Cabinet) CabinetCompany() string { return c.CompanyID }

// Clone — глубокая копия: вызывающий не меняет кэш.
func (c Cabinet) Clone() Cabinet {
	if c.Seller != nil {
		seller := *c.Seller
		c.Seller = &seller
	}
	if c.Performance != nil {
		perf := *c.Performance
		c.Performance = &perf
	}
	c.ResourceTypes = append([]string(nil), c.ResourceTypes...)
	return c
}

// Client — GET снимка кабинетов Ozon у Symfony.
type Client = fkconn.Client[Cabinet]

// Cache — последний успешный снимок кабинетов Ozon.
type Cache = fkconn.Cache[Cabinet]

// Fetcher — источник снимка (Client или фейк в тестах).
type Fetcher = fkconn.Fetcher[Cabinet]

func NewClient(baseURL, token string, httpClient *http.Client) *Client {
	return fkconn.NewClient(baseURL, token, Provider, decode, httpClient)
}

func NewCache(source Fetcher, logger *slog.Logger, now func() time.Time) *Cache {
	return fkconn.NewCache(source, logger, now)
}

type wireCredentials struct {
	Seller *struct {
		ClientID string `json:"client_id"`
		APIKey   string `json:"api_key"`
	} `json:"seller"`
	Performance *struct {
		ClientID     string `json:"client_id"`
		ClientSecret string `json:"client_secret"`
	} `json:"performance"`
}

func parse(raw []byte) ([]Cabinet, []string, error) {
	return fkconn.Parse(raw, Provider, decode)
}

func decode(w fkconn.Wire, updatedAt time.Time) (Cabinet, bool, error) {
	var creds wireCredentials
	if err := json.Unmarshal(w.Credentials, &creds); err != nil {
		return Cabinet{}, false, fmt.Errorf("%w: credentials of %q", ErrInvalidSnapshot, w.ConnectionRef)
	}
	cab := Cabinet{Ref: w.ConnectionRef, CompanyID: w.CompanyID, UpdatedAt: updatedAt, ResourceTypes: w.ResourceTypes}
	if s := creds.Seller; s != nil {
		if s.ClientID == "" || s.APIKey == "" {
			return Cabinet{}, false, nil
		}
		cab.Seller = &ozon.Credentials{ClientID: s.ClientID, APIKey: s.APIKey}
	}
	if p := creds.Performance; p != nil {
		if p.ClientID == "" || p.ClientSecret == "" {
			return Cabinet{}, false, nil
		}
		cab.Performance = &ozonperf.Credentials{ClientID: p.ClientID, ClientSecret: p.ClientSecret}
		cab.HasPerformance = true
	}
	return cab, true, nil
}
