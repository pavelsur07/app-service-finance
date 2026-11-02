package ozon

import (
	"bytes"
	"encoding/json"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

// Class — класс ответа Ozon; определяет реакцию исполнителя
// (docs/ozon-api.md «Классы ответов»). Общий тип ядра — fetchkit/apierr.
type Class = apierr.Class

const (
	ClassRateLimit        = apierr.ClassRateLimit
	ClassTransient        = apierr.ClassTransient
	ClassAuth             = apierr.ClassAuth
	ClassObsoleteEndpoint = apierr.ClassObsoleteEndpoint
	ClassInvalidRequest   = apierr.ClassInvalidRequest
	ClassInvalidResponse  = apierr.ClassInvalidResponse
	ClassNotReady         = apierr.ClassNotReady
)

// Source — источник в тексте ошибки ("ozon: …").
const Source = "ozon"

// obsoleteCode — код Ozon для снятого метода (400 {"code":9,...}, 09.09.2026).
const obsoleteCode = 9

const messageLimit = 200

// APIError — неуспешный ответ Ozon или сбой транспорта (fetchkit/apierr).
type APIError = apierr.APIError

// errorEnvelope — конверт ошибки Ozon: {"code": <int>, "message": "..."}.
type errorEnvelope struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

// Classify сопоставляет неуспешный HTTP-ответ классу. Для 2xx возвращает nil:
// форма успешного ответа проверяется на уровне ресурса.
func Classify(endpoint string, status int, header http.Header, body []byte, now time.Time) *APIError {
	if status >= 200 && status < 300 {
		return nil
	}

	var env errorEnvelope
	_ = json.Unmarshal(body, &env)
	apiErr := &APIError{Source: Source,
		Endpoint: endpoint,
		Status:   status,
		Code:     env.Code,
		Message:  truncate(env.Message, messageLimit),
	}

	switch {
	case status == http.StatusTooManyRequests:
		apiErr.Class = ClassRateLimit
		apiErr.RetryAfter = parseRetryAfter(header.Get("Retry-After"), now)
	case status >= 500:
		apiErr.Class = ClassTransient
	case status == http.StatusUnauthorized || status == http.StatusForbidden:
		apiErr.Class = ClassAuth
	case status == http.StatusBadRequest && isObsolete(env, body):
		apiErr.Class = ClassObsoleteEndpoint
	default:
		apiErr.Class = ClassInvalidRequest
	}
	return apiErr
}

// isObsolete — снятый метод: «obsolete method» в теле или code 9 без иного
// текста. code 9 — общий FAILED_PRECONDITION Ozon: состав поставок отвечает им
// на неизвестный bundle_id («Bundles … not found», 24.10.2026) — это не снятие
// метода, а остановка ресурса у всех кабинетов была бы ложной.
func isObsolete(env errorEnvelope, body []byte) bool {
	if bytes.Contains(bytes.ToLower(body), []byte("obsolete")) {
		return true
	}
	return env.Code == obsoleteCode && strings.TrimSpace(env.Message) == ""
}

// parseRetryAfter понимает секунды и HTTP-date; битое или прошедшее — 0.
func parseRetryAfter(value string, now time.Time) time.Duration {
	value = strings.TrimSpace(value)
	if value == "" {
		return 0
	}
	if seconds, err := strconv.Atoi(value); err == nil {
		if seconds <= 0 {
			return 0
		}
		return time.Duration(seconds) * time.Second
	}
	if at, err := http.ParseTime(value); err == nil {
		if d := at.Sub(now); d > 0 {
			return d
		}
	}
	return 0
}

func truncate(s string, limit int) string {
	if len([]rune(s)) <= limit {
		return s
	}
	return string([]rune(s)[:limit])
}
