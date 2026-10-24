// Package ozon — HTTP-клиент Ozon Seller API: запрос, классификация ответов,
// лог без тела и ключей (services/ozon-fetcher/docs/ozon-api.md).
package ozon

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"time"
)

const (
	// DefaultBaseURL — Ozon Seller API.
	DefaultBaseURL = "https://api-seller.ozon.ru"
	// DefaultTimeout — запрос целиком, как REQUEST_TIMEOUT Symfony-клиента.
	DefaultTimeout = 120 * time.Second
	// MaxBodyBytes — потолок тела ответа. Больше — ошибка, а не обрезанные данные.
	MaxBodyBytes = 64 << 20
)

// ErrBodyTooLarge — тело ответа превысило MaxBodyBytes.
var ErrBodyTooLarge = errors.New("ozon: response body exceeds limit")

// Response — успешный ответ: исходные байты тела без перекодирования.
type Response struct {
	Status   int
	Header   http.Header
	Body     []byte
	Duration time.Duration
}

// Limiter — ограничение скорости на пару (кабинет, endpoint). Wait — до
// запроса, Observe — после: класс ответа (nil — успех) подстраивает лимит.
type Limiter interface {
	Wait(ctx context.Context, connectionRef, endpoint string) error
	Observe(connectionRef, endpoint string, apiErr *APIError)
}

type noLimit struct{}

func (noLimit) Wait(context.Context, string, string) error { return nil }
func (noLimit) Observe(string, string, *APIError)          {}

// Client — транспорт Seller API. Без состояния кабинета: ключи передаются в вызов.
type Client struct {
	baseURL string
	http    *http.Client
	logger  *slog.Logger
	now     func() time.Time
	limiter Limiter
}

// NewClient — httpClient с таймаутом настраивает вызывающий (см. DefaultTimeout).
// Без WithLimiter запросы не ограничиваются.
func NewClient(baseURL string, httpClient *http.Client, logger *slog.Logger, now func() time.Time) *Client {
	return &Client{baseURL: baseURL, http: httpClient, logger: logger, now: now, limiter: noLimit{}}
}

// WithLimiter возвращает копию клиента с ограничением скорости.
func (c *Client) WithLimiter(l Limiter) *Client {
	clone := *c
	clone.limiter = l
	return &clone
}

// Post отправляет JSON на endpoint. Неуспех — *APIError; отмена ctx — ctx.Err().
func (c *Client) Post(ctx context.Context, creds Credentials, connectionRef, endpoint string, body any) (Response, error) {
	return c.call(ctx, creds, connectionRef, http.MethodPost, endpoint, body)
}

// Get — запрос без тела (список акций).
func (c *Client) Get(ctx context.Context, creds Credentials, connectionRef, endpoint string) (Response, error) {
	return c.call(ctx, creds, connectionRef, http.MethodGet, endpoint, nil)
}

func (c *Client) call(ctx context.Context, creds Credentials, connectionRef, method, endpoint string, body any) (Response, error) {
	if err := c.limiter.Wait(ctx, connectionRef, endpoint); err != nil {
		return Response{}, err
	}
	resp, err := c.post(ctx, creds, connectionRef, method, endpoint, body)
	if err == nil {
		c.limiter.Observe(connectionRef, endpoint, nil)
		return resp, nil
	}
	var apiErr *APIError
	if errors.As(err, &apiErr) {
		c.limiter.Observe(connectionRef, endpoint, apiErr)
	}
	return resp, err
}

func (c *Client) post(ctx context.Context, creds Credentials, connectionRef, method, endpoint string, body any) (Response, error) {
	var payload io.Reader = http.NoBody
	if method != http.MethodGet {
		raw, err := json.Marshal(body)
		if err != nil {
			return Response{}, fmt.Errorf("ozon: encode request %s: %w", endpoint, err)
		}
		payload = bytes.NewReader(raw)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.baseURL+endpoint, payload)
	if err != nil {
		return Response{}, fmt.Errorf("ozon: build request %s: %w", endpoint, err)
	}
	req.Header.Set("Client-Id", creds.ClientID)
	req.Header.Set("Api-Key", creds.APIKey)
	req.Header.Set("Content-Type", "application/json")

	started := c.now()
	resp, err := c.http.Do(req)
	if err != nil {
		duration := c.now().Sub(started)
		if ctxErr := ctx.Err(); ctxErr != nil {
			c.log(ctx, connectionRef, endpoint, 0, duration, "canceled")
			return Response{}, ctxErr
		}
		c.log(ctx, connectionRef, endpoint, 0, duration, string(ClassTransient))
		return Response{}, &APIError{Class: ClassTransient, Endpoint: endpoint, Err: err}
	}
	defer func() { _ = resp.Body.Close() }()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, MaxBodyBytes+1))
	duration := c.now().Sub(started)
	if err != nil {
		if ctxErr := ctx.Err(); ctxErr != nil {
			c.log(ctx, connectionRef, endpoint, resp.StatusCode, duration, "canceled")
			return Response{}, ctxErr
		}
		c.log(ctx, connectionRef, endpoint, resp.StatusCode, duration, string(ClassTransient))
		return Response{}, &APIError{Class: ClassTransient, Endpoint: endpoint, Status: resp.StatusCode, Err: err}
	}
	if len(raw) > MaxBodyBytes {
		c.log(ctx, connectionRef, endpoint, resp.StatusCode, duration, string(ClassInvalidResponse))
		return Response{}, &APIError{Class: ClassInvalidResponse, Endpoint: endpoint, Status: resp.StatusCode, Err: ErrBodyTooLarge}
	}

	if apiErr := Classify(endpoint, resp.StatusCode, resp.Header, raw, c.now()); apiErr != nil {
		c.log(ctx, connectionRef, endpoint, resp.StatusCode, duration, string(apiErr.Class))
		return Response{}, apiErr
	}

	c.log(ctx, connectionRef, endpoint, resp.StatusCode, duration, "ok")
	return Response{Status: resp.StatusCode, Header: resp.Header, Body: raw, Duration: duration}, nil
}

func (c *Client) log(ctx context.Context, connectionRef, endpoint string, status int, duration time.Duration, outcome string) {
	level := slog.LevelInfo
	if outcome != "ok" && outcome != "canceled" {
		level = slog.LevelWarn
	}
	c.logger.Log(ctx, level, "ozon request",
		slog.String("stage", "api_fetch"),
		slog.String("connection_ref", connectionRef),
		slog.String("endpoint", endpoint),
		slog.Int("status", status),
		slog.Int64("duration_ms", duration.Milliseconds()),
		slog.String("outcome", outcome),
	)
}
