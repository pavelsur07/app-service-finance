// Package ratelimit — лимит запросов на пару (кабинет, endpoint) с
// адаптацией на 429 (AIMD). Лимиты методов задаёт сервис (Config).
package ratelimit

import (
	"context"
	"sync"
	"time"

	"golang.org/x/time/rate"

	"github.com/pavelsur07/app-service-finance/services/shared/fetchkit/apierr"
)

// EndpointLimit — границы скорости endpoint'а, запросов в секунду.
type EndpointLimit struct {
	Start        float64
	Floor        float64
	Ceiling      float64
	DefaultPause time.Duration // пауза после 429 без Retry-After
	// SharedQuota — квота метода общая для всех клиентов источника: 429 вызван
	// чужой нагрузкой, поэтому темп не снижается и шторм не считается —
	// только пауза.
	SharedQuota bool
}

// Config — лимиты по endpoint'ам и правило роста.
type Config struct {
	Endpoints    map[string]EndpointLimit
	Fallback     EndpointLimit // для endpoint'а вне Endpoints
	GrowAfter    int           // успехов подряд до роста
	IncreaseStep float64       // прирост скорости, запросов/с
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

	floorHits   []time.Time // 429, пришедшие уже на минимальной скорости
	stormSentAt time.Time
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

// Storm — шторм 429: скорость пары уже на минимуме, а Ozon продолжает
// отказывать. Замедляться дальше некуда — нужен человек.
type Storm struct {
	ConnectionRef string
	Endpoint      string
	Hits          int           // 429 на минимуме за окно
	Window        time.Duration // окно подсчёта
	Floor         float64       // текущая (минимальная) скорость, запросов/с
}

// StormAlert — порог сигнала о шторме.
type StormAlert struct {
	Threshold int // столько 429 на минимуме за Window — сигнал
	Window    time.Duration
	Cooldown  time.Duration // не чаще раза на пару
	Notify    func(Storm)
}

// DefaultStormAlert — 3 отказа на минимуме за час, сигнал не чаще раза в час.
func DefaultStormAlert(notify func(Storm)) StormAlert {
	return StormAlert{Threshold: 3, Window: time.Hour, Cooldown: time.Hour, Notify: notify}
}

// Registry — лимиты всех пар. Безопасен для параллельного использования.
type Registry struct {
	cfg      Config
	now      func() time.Time
	sleep    func(context.Context, time.Duration) error
	onChange func(ref, endpoint string, rps float64)
	storm    StormAlert

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

// WithStormAlert включает сигнал о шторме 429 (вызывать до использования).
func (r *Registry) WithStormAlert(a StormAlert) *Registry {
	r.storm = a
	return r
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

// Wait ждёт паузу после 429 и токен пары. Пауза проверяется и после
// получения токена: запрос, стоявший в очереди за токеном, пока сосед получил
// 429, не должен проскочить паузу (SetLimit не переносит уже выданные резервы).
func (r *Registry) Wait(ctx context.Context, ref, endpoint string) error {
	p := r.get(ref, endpoint)
	for {
		if err := r.waitPause(ctx, p); err != nil {
			return err
		}
		if err := p.bucket.Wait(ctx); err != nil {
			return err
		}
		if p.pauseLeft(r.now()) <= 0 {
			return nil
		}
	}
}

func (r *Registry) waitPause(ctx context.Context, p *pair) error {
	for {
		wait := p.pauseLeft(r.now())
		if wait <= 0 {
			return nil
		}
		if err := r.sleep(ctx, wait); err != nil {
			return err
		}
	}
}

func (p *pair) pauseLeft(now time.Time) time.Duration {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.pausedUntil.Sub(now)
}

// Observe подстраивает скорость по исходу запроса (apiErr nil — успех).
func (r *Registry) Observe(ref, endpoint string, apiErr *apierr.APIError) {
	p := r.get(ref, endpoint)
	p.mu.Lock()
	changed := false
	var storm *Storm
	switch {
	case apiErr == nil:
		p.successes++
		if r.cfg.GrowAfter > 0 && p.successes >= r.cfg.GrowAfter {
			p.successes = 0
			changed = p.setCurrent(p.current + r.cfg.IncreaseStep)
		}
	case apiErr.Class == apierr.ClassRateLimit:
		p.successes = 0
		if !p.limit.SharedQuota {
			atFloor := p.current <= p.limit.Floor
			changed = p.setCurrent(p.current / 2)
			storm = r.trackStorm(p, ref, endpoint, atFloor)
		}
		pause := apiErr.RetryAfter
		if pause <= 0 {
			pause = p.limit.DefaultPause
		}
		if until := r.now().Add(pause); until.After(p.pausedUntil) {
			p.pausedUntil = until
		}
	default:
		// Серия успехов — подряд: любая ошибка её прерывает, скорость не меняет.
		p.successes = 0
	}
	current := p.current
	p.mu.Unlock()
	if changed {
		r.onChange(ref, endpoint, current)
	}
	if storm != nil {
		r.storm.Notify(*storm)
	}
}

// trackStorm учитывает 429 на минимальной скорости; возвращает сигнал, когда
// их за окно набралось Threshold и с прошлого сигнала прошло Cooldown.
// Вызывается под p.mu.
func (r *Registry) trackStorm(p *pair, ref, endpoint string, atFloor bool) *Storm {
	if r.storm.Notify == nil || r.storm.Threshold <= 0 {
		return nil
	}
	if !atFloor {
		p.floorHits = p.floorHits[:0]
		return nil
	}
	now := r.now()
	kept := p.floorHits[:0]
	for _, at := range p.floorHits {
		if now.Sub(at) < r.storm.Window {
			kept = append(kept, at)
		}
	}
	p.floorHits = append(kept, now)
	if len(p.floorHits) < r.storm.Threshold {
		return nil
	}
	if !p.stormSentAt.IsZero() && now.Sub(p.stormSentAt) < r.storm.Cooldown {
		return nil
	}
	p.stormSentAt = now
	return &Storm{ConnectionRef: ref, Endpoint: endpoint, Hits: len(p.floorHits), Window: r.storm.Window, Floor: p.current}
}
