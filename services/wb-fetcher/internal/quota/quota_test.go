package quota

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

const ref = "01930000-0000-7000-8000-00000000c001"

type seen struct {
	path, auth string
	body       map[string]any
}

func serve(t *testing.T, status int, reply string) (*Client, *seen) {
	t.Helper()
	s := &seen{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s.path, s.auth = r.URL.Path, r.Header.Get("Authorization")
		b, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(b, &s.body)
		w.WriteHeader(status)
		_, _ = io.WriteString(w, reply)
	}))
	t.Cleanup(srv.Close)
	return New(srv.URL, "internal-token", srv.Client()), s
}

func TestReserveGranted(t *testing.T) {
	c, s := serve(t, http.StatusOK, `{"granted":true}`)

	wait, err := c.Reserve(context.Background(), ref)

	require.NoError(t, err)
	assert.Zero(t, wait)
	assert.Equal(t, SlotPath, s.path)
	assert.Equal(t, "Bearer internal-token", s.auth)
	assert.Equal(t, map[string]any{"connection_ref": ref}, s.body)
}

func TestReserveDeniedReturnsWait(t *testing.T) {
	c, _ := serve(t, http.StatusOK, `{"granted":false,"wait_seconds":42,"shared_cooldown":false}`)

	wait, err := c.Reserve(context.Background(), ref)

	require.NoError(t, err)
	assert.Equal(t, 42*time.Second, wait)
}

func TestReserveFailsOnBadAnswer(t *testing.T) {
	for name, tc := range map[string]struct {
		status int
		reply  string
	}{
		"500":             {http.StatusInternalServerError, `{}`},
		"401":             {http.StatusUnauthorized, `{}`},
		"не JSON":         {http.StatusOK, `<html>`},
		"нет granted":     {http.StatusOK, `{}`},
		"отказ без паузы": {http.StatusOK, `{"granted":false}`},
		"пауза 0":         {http.StatusOK, `{"granted":false,"wait_seconds":0}`},
	} {
		t.Run(name, func(t *testing.T) {
			c, _ := serve(t, tc.status, tc.reply)
			_, err := c.Reserve(context.Background(), ref)
			assert.Error(t, err)
		})
	}
}

func TestRateLimitedSendsPause(t *testing.T) {
	c, s := serve(t, http.StatusOK, `{"cooldown_seconds":30}`)

	require.NoError(t, c.RateLimited(context.Background(), ref, 30*time.Second))

	assert.Equal(t, RateLimitedPath, s.path)
	assert.Equal(t, map[string]any{"connection_ref": ref, "retry_after_seconds": float64(30)}, s.body)
}

func TestRateLimitedWithoutPauseSendsNull(t *testing.T) {
	c, s := serve(t, http.StatusOK, `{"cooldown_seconds":85}`)

	require.NoError(t, c.RateLimited(context.Background(), ref, 0))

	assert.Equal(t, map[string]any{"connection_ref": ref, "retry_after_seconds": nil}, s.body)
}

func TestRateLimitedRoundsUpAndCapsPause(t *testing.T) {
	c, s := serve(t, http.StatusOK, `{"cooldown_seconds":2}`)
	require.NoError(t, c.RateLimited(context.Background(), ref, 1500*time.Millisecond))
	assert.Equal(t, float64(2), s.body["retry_after_seconds"])

	require.NoError(t, c.RateLimited(context.Background(), ref, 48*time.Hour))
	assert.Equal(t, float64(86400), s.body["retry_after_seconds"], "контракт: не больше суток")
}

func TestRateLimitedFailsOnNon200(t *testing.T) {
	c, _ := serve(t, http.StatusUnprocessableEntity, `{"error":{"code":"invalid_request"}}`)

	assert.Error(t, c.RateLimited(context.Background(), ref, time.Minute))
}

func TestRateLimitedFailsWithoutCooldown(t *testing.T) {
	for _, reply := range []string{`{}`, `{"cooldown_seconds":0}`} {
		c, _ := serve(t, http.StatusOK, reply)
		assert.Error(t, c.RateLimited(context.Background(), ref, time.Minute), reply)
	}
}
