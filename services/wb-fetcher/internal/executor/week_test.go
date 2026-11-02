package executor

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func day(s string) time.Time {
	d, err := time.ParseInLocation(time.DateOnly, s, moscow)
	if err != nil {
		panic(err)
	}
	return d
}

func TestWeekKeyIsISOWeekOfDay(t *testing.T) {
	for d, want := range map[string]string{
		"2026-10-26": "2026-W44", // понедельник
		"2026-11-01": "2026-W44", // воскресенье той же недели
		"2026-11-02": "2026-W45",
		"2026-12-28": "2026-W53", // в 2026 году 53 недели
		"2027-01-03": "2026-W53",
		"2027-01-04": "2027-W01",
		"2021-01-03": "2020-W53",
	} {
		assert.Equal(t, want, WeekKey(day(d)), d)
	}
}

func TestParseWeekGivesMondayInMoscow(t *testing.T) {
	for key, monday := range map[string]string{
		"2026-W44": "2026-10-26",
		"2026-W53": "2026-12-28",
		"2027-W01": "2027-01-04",
		"2020-W53": "2020-12-28",
		"2026-W01": "2025-12-29",
	} {
		got, err := ParseWeek(key, moscow)
		require.NoError(t, err, key)
		assert.Equal(t, day(monday), got, key)
		assert.Equal(t, time.Monday, got.Weekday())
	}
}

func TestParseWeekRejectsMalformedAndNonexistentWeeks(t *testing.T) {
	for _, key := range []string{"2026-W54", "2026-W00", "2025-W53", "2026-44", "2026-W4", "2026-w44", "2026-W044", "W44-2026", ""} {
		_, err := ParseWeek(key, moscow)
		assert.Error(t, err, key)
	}
}
