// Мок для стенда wb-fetcher (docs/tasks/wb-fetcher-finance/stages/stage-2.md):
// снимок кабинетов Symfony и finance-api WB. Первый запрос отчёта — 429 с
// X-Ratelimit-Retry; первая страница дня — ровно limit строк (пагинация),
// вторая отвечает с задержкой PAGE2_DELAY (окно для остановки сервиса), третья — [].
package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strings"
	"sync/atomic"
	"time"
)

func main() {
	token := os.Getenv("INTERNAL_AUTH_TOKEN")
	delay, err := time.ParseDuration(envOr("PAGE2_DELAY", "20s"))
	if err != nil {
		log.Fatal(err)
	}
	var requests atomic.Int64

	mux := http.NewServeMux()
	mux.HandleFunc("GET /internal/marketplace-connections", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+token || r.URL.Query().Get("provider") != "wildberries" {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		writeJSON(w, map[string]any{"schema_version": "1", "generated_at": time.Now().Format(time.RFC3339), "connections": []any{
			map[string]any{"connection_ref": "wb-stand", "company_id": "c-stand", "provider": "wildberries",
				"updated_at": "2026-11-01T09:00:00+03:00", "resource_types": []string{"wb.finance.sales-report.daily"},
				"credentials": map[string]any{"seller": map[string]string{"client_id": "wb-stand", "api_key": "stand-token"}, "performance": nil}},
		}})
	})
	// Квота Symfony (wb-finance-quota v1): стенд один, слот выдаётся всегда.
	mux.HandleFunc("POST /internal/wb-finance/sales-reports/slot", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+token {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		writeJSON(w, map[string]any{"granted": true})
	})
	mux.HandleFunc("POST /internal/wb-finance/sales-reports/rate-limited", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+token {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		writeJSON(w, map[string]any{"cooldown_seconds": 85})
	})
	mux.HandleFunc("POST /api/finance/v1/sales-reports/detailed", func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			DateFrom, DateTo, Period string
			RrdID                    int64 `json:"rrdId"`
			Limit                    int
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		n := requests.Add(1)
		log.Printf("report request #%d auth=%t period=%q from=%q to=%q rrdId=%d limit=%d", n, //nolint:gosec // стенд: %q экранирует ввод
			r.Header.Get("Authorization") == "stand-token", req.Period, req.DateFrom, req.DateTo, req.RrdID, req.Limit)
		if n == 1 {
			w.Header().Set("X-Ratelimit-Retry", "5")
			w.WriteHeader(http.StatusTooManyRequests)
			return
		}
		day := strings.ReplaceAll(req.DateFrom, "-", "")
		switch req.RrdID {
		case 0:
			var b strings.Builder
			b.WriteString("[")
			for i := 1; i <= req.Limit; i++ {
				if i > 1 {
					b.WriteString(",")
				}
				fmt.Fprintf(&b, `{"rrdId":%d,"day":"%s"}`, i, day)
			}
			b.WriteString("]")
			_, _ = w.Write([]byte(b.String()))
		default:
			if req.RrdID > int64(req.Limit) { // после второй страницы — конец отчёта
				_, _ = w.Write([]byte("[]"))
				return
			}
			time.Sleep(delay)
			_, _ = fmt.Fprintf(w, `[{"rrdId":%d,"day":"%s"},{"rrdId":%d,"day":"%s"}]`, req.RrdID+1, day, req.RrdID+2, day)
		}
	})
	log.Fatal(http.ListenAndServe(":8090", mux)) //nolint:gosec // G114: стендовый мок
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}

func envOr(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}
