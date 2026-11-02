// Package connections — кабинеты WB из Symfony (контракт
// services/_contracts/marketplace-connections/v1, provider=wildberries) поверх
// fetchkit/connections. У WB один ключ — токен; seller.client_id — id
// подключения (единица лимита), совпадает с connection_ref.
package connections

import (
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"time"

	fkconn "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/connections"
)

// Provider — значение provider в снимке и запросе.
const Provider = "wildberries"

// ErrInvalidSnapshot — ответ не соответствует контракту v1.
var ErrInvalidSnapshot = fkconn.ErrInvalidSnapshot

// Token — токен WB. Печатается и логируется скрытым; значение — Reveal().
type Token string

func (t Token) Reveal() string                { return string(t) }
func (Token) String() string                  { return "[redacted]" }
func (Token) GoString() string                { return `"[redacted]"` }
func (Token) LogValue() slog.Value            { return slog.StringValue("[redacted]") }
func (Token) MarshalJSON() ([]byte, error)    { return []byte(`"[redacted]"`), nil }
func (t Token) Format(f fmt.State, verb rune) { _, _ = f.Write([]byte(t.String())) }

// Cabinet — кабинет WB. Токен — только в памяти.
type Cabinet struct {
	Ref           string
	CompanyID     string
	UpdatedAt     time.Time
	Token         Token
	ResourceTypes []string
}

// Supports — ресурс выдан кабинету в снимке Symfony.
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

// Clone — копия без общих срезов с кэшем.
func (c Cabinet) Clone() Cabinet {
	c.ResourceTypes = append([]string(nil), c.ResourceTypes...)
	return c
}

// Client — GET снимка кабинетов WB у Symfony.
type Client = fkconn.Client[Cabinet]

// Cache — последний успешный снимок кабинетов WB.
type Cache = fkconn.Cache[Cabinet]

func NewClient(baseURL, token string, httpClient *http.Client) *Client {
	return fkconn.NewClient(baseURL, token, Provider, decode, httpClient)
}

func NewCache(source fkconn.Fetcher[Cabinet], logger *slog.Logger, now func() time.Time) *Cache {
	return fkconn.NewCache(source, logger, now)
}

func parse(raw []byte) ([]Cabinet, []string, error) {
	return fkconn.Parse(raw, Provider, decode)
}

// decode — кабинет без токена seller отвергается: грузить нечем.
func decode(w fkconn.Wire, updatedAt time.Time) (Cabinet, bool, error) {
	var creds struct {
		Seller *struct {
			APIKey string `json:"api_key"`
		} `json:"seller"`
	}
	if err := json.Unmarshal(w.Credentials, &creds); err != nil {
		return Cabinet{}, false, fmt.Errorf("%w: credentials of %q", ErrInvalidSnapshot, w.ConnectionRef)
	}
	if creds.Seller == nil || creds.Seller.APIKey == "" {
		return Cabinet{}, false, nil
	}
	return Cabinet{Ref: w.ConnectionRef, CompanyID: w.CompanyID, UpdatedAt: updatedAt,
		Token: Token(creds.Seller.APIKey), ResourceTypes: w.ResourceTypes}, true, nil
}
