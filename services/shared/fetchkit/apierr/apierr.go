// Package apierr — классы ответов внешнего API и ошибка запроса: по классу
// общее ядро (fetch, ratelimit) выбирает реакцию — повтор, пауза, остановка
// ресурса. Сопоставление ответа классу делает клиент конкретного API.
package apierr

import (
	"fmt"
	"time"
)

// Class — класс ответа; определяет реакцию исполнителя.
type Class string

const (
	ClassRateLimit        Class = "rate_limit"
	ClassTransient        Class = "transient"
	ClassAuth             Class = "auth"
	ClassObsoleteEndpoint Class = "obsolete_endpoint"
	ClassInvalidRequest   Class = "invalid_request"
	ClassInvalidResponse  Class = "invalid_response"
	ClassNotReady         Class = "not_ready"
)

// APIError — неуспешный ответ API или сбой транспорта.
type APIError struct {
	Source     string // источник для текста ошибки: ozon, ozon-performance, wb
	Class      Class
	Endpoint   string
	Status     int // 0 — ответа не было (сеть, таймаут)
	Code       int // код из конверта ошибки источника, 0 — нет
	Message    string
	RetryAfter time.Duration
	Err        error // причина для сбоя транспорта
}

func (e *APIError) Error() string {
	src := e.Source
	if src == "" {
		src = "api"
	}
	if e.Status == 0 {
		return fmt.Sprintf("%s: %s %s: %v", src, e.Class, e.Endpoint, e.Err)
	}
	if e.Message != "" {
		// Текст ошибки источника (не тело целиком): без него отказ метода в
		// журнале неотличим от прочих 400.
		return fmt.Sprintf("%s: %s %s: HTTP %d code %d: %s", src, e.Class, e.Endpoint, e.Status, e.Code, e.Message)
	}
	if e.Err != nil {
		// Причина проверки формы ответа (invalid_response, not_ready при 200).
		return fmt.Sprintf("%s: %s %s: HTTP %d: %v", src, e.Class, e.Endpoint, e.Status, e.Err)
	}
	return fmt.Sprintf("%s: %s %s: HTTP %d code %d", src, e.Class, e.Endpoint, e.Status, e.Code)
}

func (e *APIError) Unwrap() error { return e.Err }
