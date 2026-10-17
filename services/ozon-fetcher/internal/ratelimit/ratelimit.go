// Package ratelimit — лимит запросов на пару (кабинет, endpoint) с
// адаптацией на 429 (AIMD). Реализует ozon.Limiter (docs/ozon-api.md «Лимиты»).
package ratelimit

import (
	"context"
	"sync"
	"time"

	"golang.org/x/time/rate"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/ozon"
)

// EndpointLimit — границы скорости endpoint'а, запросов в секунду.
type EndpointLimit struct {
	Start        float64
	Floor        float64
	Ceiling      float64
	DefaultPause time.Duration // пауза после 429 без Retry-After
}

// Config — лимиты по endpoint'ам и правило роста.
type Config struct {
	Endpoints    map[string]EndpointLimit
	Fallback     EndpointLimit // для endpoint'а вне Endpoints
	GrowAfter    int           // успехов подряд до роста
	IncreaseStep float64       // прирост скорости, запросов/с
}

// DefaultConfig — консервативные дефолты docs/ozon-api.md: Ozon не
// публикует лимиты финансовых методов.
func DefaultConfig() Config {
	return Config{
		Endpoints: map[string]EndpointLimit{
			ozon.EndpointAccrualByDay:     {Start: 1, Floor: 0.1, Ceiling: 5, DefaultPause: 10 * time.Second},
			ozon.EndpointAccrualTypes:     {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
			ozon.EndpointRealization:      {Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 900 * time.Second},
			ozon.EndpointMutualSettlement: {Start: 0.1, Floor: 0.02, Ceiling: 0.5, DefaultPause: 900 * time.Second},
			ozon.EndpointReportInfo:       {Start: 0.5, Floor: 0.1, Ceiling: 2, DefaultPause: 30 * time.Second},
		},
		Fallback:     EndpointLimit{Start: 0.2, Floor: 0.05, Ceiling: 1, DefaultPause: 60 * time.Second},
		GrowAfter:    20,
		IncreaseStep: 0.1,
	}
}

type key struct{ ref, endpoint string }

type pair struct {
	mu          sync.Mutex
	limit       EndpointLimit
	override    float64 // 0 — не задано
	current     float64
	successes   int
	pausedUntil time.Time
	bucket      *rate.Limiter
}

func (p *pair) ceiling() float64 {
	if p.override > 0 {
		return max(p.override, p.limit.Floor)
	}
	return p.limit.Ceiling
}

func (p *pair) clamp(v float64) float64 { return min(max(v, p.limit.Floor), p.ceiling()) }

// setCurrent меняет скорость; возвращает true, если она изменилась.
func (p *pair) setCurrent(v float64) bool {
	v = p.clamp(v)
	if v == p.current {
		return false
	}
	p.current = v
	p.bucket.SetLimit(rate.Limit(v))
	return true
}

// Registry — лимиты всех пар. Безопасен для параллельного использования.
type Registry struct {
	cfg      Config
	now      func() time.Time
	sleep    func(context.Context, time.Duration) error
	onChange func(ref, endpoint string, rps float64)

	mu    sync.Mutex
	pairs map[key]*pair
}

// New — now и sleep — параметры ради детерминированных тестов;
// onChange (может быть nil) получает каждое изменение скорости.
func New(cfg Config, now func() time.Time, sleep func(context.Context, time.Duration) error, onChange func(ref, endpoint string, rps float64)) *Registry {
	if onChange == nil {
		onChange = func(string, string, float64) {}
	}
	return &Registry{cfg: cfg, now: now, sleep: sleep, onChange: onChange, pairs: map[key]*pair{}}
}

func (r *Registry) get(ref, endpoint string) *pair {
	r.mu.Lock()
	defer r.mu.Unlock()
	k := key{ref, endpoint}
	if p, ok := r.pairs[k]; ok {
		return p
	}
	limit, ok := r.cfg.Endpoints[endpoint]
	if !ok {
		limit = r.cfg.Fallback
	}
	p := &pair{limit: limit}
	p.current = p.clamp(limit.Start)
	p.bucket = rate.NewLimiter(rate.Limit(p.current), 1)
	r.pairs[k] = p
	return p
}

// Seed — состояние пары из хранилища при старте: override (0 — нет) и
// сохранённая адаптированная скорость (0 — нет).
func (r *Registry) Seed(ref, endpoint string, override, saved float64) {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	defer p.mu.Unlock()
	p.override = max(override, 0)
	switch {
	case saved > 0:
		p.setCurrent(saved)
	case p.override > 0:
		p.setCurrent(p.override)
	default:
		p.setCurrent(p.current)
	}
}

// SetOverride — ручное значение по кабинету (0 — снять); ограничивает потолок.
func (r *Registry) SetOverride(ref, endpoint string, override float64) {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	p.override = max(override, 0)
	changed := p.setCurrent(p.current)
	current := p.current
	p.mu.Unlock()
	if changed {
		r.onChange(ref, endpoint, current)
	}
}

// Rate — текущая скорость пары, запросов/с.
func (r *Registry) Rate(ref, endpoint string) float64 {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.current
}

// Wait ждёт паузу после 429 и токен пары.
func (r *Registry) Wait(ctx context.Context, ref, endpoint string) error {
	p := r.get(ref, endpoint)
	for {
		p.mu.Lock()
		wait := p.pausedUntil.Sub(r.now())
		p.mu.Unlock()
		if wait <= 0 {
			break
		}
		if err := r.sleep(ctx, wait); err != nil {
			return err
		}
	}
	return p.bucket.Wait(ctx)
}

// Observe подстраивает скорость по исходу запроса (apiErr nil — успех).
func (r *Registry) Observe(ref, endpoint string, apiErr *ozon.APIError) {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	changed := false
	switch {
	case apiErr == nil:
		p.successes++
		if r.cfg.GrowAfter > 0 && p.successes >= r.cfg.GrowAfter {
			p.successes = 0
			changed = p.setCurrent(p.current + r.cfg.IncreaseStep)
		}
	case apiErr.Class == ozon.ClassRateLimit:
		p.successes = 0
		changed = p.setCurrent(p.current / 2)
		pause := apiErr.RetryAfter
		if pause <= 0 {
			pause = p.limit.DefaultPause
		}
		if until := r.now().Add(pause); until.After(p.pausedUntil) {
			p.pausedUntil = until
		}
	}
	current := p.current
	p.mu.Unlock()
	if changed {
		r.onChange(ref, endpoint, current)
	}
}

var _ ozon.Limiter = (*Registry)(nil)
