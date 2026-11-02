// Package dispatcher — диспетчер fetchkit для кабинетов Ozon: до N задач
// одновременно, по одной на кабинет и слот (гарантия БД, store.ClaimNext).
package dispatcher

import (
	"log/slog"
	"time"

	"github.com/pavelsur07/app-service-finance/services/ozon-fetcher/internal/connections"
	fkdispatcher "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/dispatcher"
)

type (
	Config     = fkdispatcher.Config
	Claimer    = fkdispatcher.Claimer
	Cabinets   = fkdispatcher.Cabinets[connections.Cabinet]
	Runner     = fkdispatcher.Runner[connections.Cabinet]
	Dispatcher = fkdispatcher.Dispatcher[connections.Cabinet]
)

func New(cfg Config, claimer Claimer, cabinets Cabinets, runner Runner, logger *slog.Logger, now func() time.Time, newID func() string) *Dispatcher {
	return fkdispatcher.New[connections.Cabinet](cfg, claimer, cabinets, runner, logger, now, newID)
}

// NewUUID — UUID v4 из crypto/rand.
func NewUUID() string { return fkdispatcher.NewUUID() }
