// Package quota — общая с Symfony квота WB finance sales-reports
// (services/_contracts/wb-finance-quota/v1): слот берётся в том же бакете
// limiter.wb_finance, что у импорта Symfony, 429 от WB ставит общий cooldown.
package quota

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"time"
)

const (
	// SlotPath — взять слот запроса к sales-reports.
	SlotPath = "/internal/wb-finance/sales-reports/slot"
	// RateLimitedPath — сообщить о 429 от WB.
	RateLimitedPath = "/internal/wb-finance/sales-reports/rate-limited"
	// DefaultTimeout — ответ Symfony: Redis, без внешних вызовов.
	DefaultTimeout = 5 * time.Second

	maxPauseSeconds = 86400
	maxBodyBytes    = 4 << 10
)

// Client — HTTP-клиент эндпоинтов квоты Symfony (firewall internal_api).
type Client struct {
	baseURL string
	token   string
	http    *http.Client
}

func New(baseURL, token string, httpClient *http.Client) *Client {
	return &Client{baseURL: baseURL, token: token, http: httpClient}
}

// Reserve берёт слот кабинета: 0 — запрос можно отправлять, иначе — сколько ждать.
func (c *Client) Reserve(ctx context.Context, connectionRef string) (time.Duration, error) {
	var out struct {
		Granted     *bool `json:"granted"`
		WaitSeconds int   `json:"wait_seconds"`
	}
	if err := c.post(ctx, SlotPath, map[string]any{"connection_ref": connectionRef}, &out); err != nil {
		return 0, err
	}
	switch {
	case out.Granted == nil:
		return 0, errors.New("quota: slot answer has no granted")
	case *out.Granted:
		return 0, nil
	case out.WaitSeconds < 1:
		return 0, fmt.Errorf("quota: slot denied with wait_seconds %d", out.WaitSeconds)
	}
	return time.Duration(out.WaitSeconds) * time.Second, nil
}

// RateLimited сообщает о 429 от WB; pause ≤ 0 — пауза Symfony по умолчанию.
func (c *Client) RateLimited(ctx context.Context, connectionRef string, pause time.Duration) error {
	var seconds any
	if pause > 0 {
		seconds = min(int64((pause+time.Second-1)/time.Second), maxPauseSeconds)
	}
	var out struct {
		CooldownSeconds int `json:"cooldown_seconds"`
	}
	if err := c.post(ctx, RateLimitedPath, map[string]any{"connection_ref": connectionRef, "retry_after_seconds": seconds}, &out); err != nil {
		return err
	}
	if out.CooldownSeconds < 1 {
		return fmt.Errorf("quota: rate-limited answer has cooldown_seconds %d", out.CooldownSeconds)
	}
	return nil
}

func (c *Client) post(ctx context.Context, path string, in, out any) error {
	payload, err := json.Marshal(in)
	if err != nil {
		return fmt.Errorf("quota: encode: %w", err)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+path, bytes.NewReader(payload))
	if err != nil {
		return fmt.Errorf("quota: build request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("quota: request %s: %w", path, err)
	}
	defer func() { _ = resp.Body.Close() }()
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxBodyBytes))
	if err != nil {
		return fmt.Errorf("quota: read %s: %w", path, err)
	}
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("quota: %s HTTP %d", path, resp.StatusCode)
	}
	if err := json.Unmarshal(body, out); err != nil {
		return fmt.Errorf("quota: %s answer is not JSON", path)
	}
	return nil
}
