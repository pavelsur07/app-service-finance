package executor

import (
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"time"
)

// ResourceWeekly — детальный отчёт о реализации за ISO-неделю МСК
// (period_key YYYY-Www, пн…вс). Основа сверки с дневными отчётами.
const ResourceWeekly = "wb.finance.sales-report.weekly"

// WeekReadyAfter — от понедельника недели до крайнего срока формирования
// отчёта: WB формирует недельные отчёты до среды, срок — ср 00:00 МСК после
// недели. Пустой ответ раньше — not_ready, позже — пустая неделя.
const WeekReadyAfter = 9 * 24 * time.Hour

// moscow — пояс отчётов WB; без tzdata — фиксированный UTC+3 (МСК без
// перехода на летнее время с 2014 года).
var moscow = func() *time.Location {
	if loc, err := time.LoadLocation("Europe/Moscow"); err == nil {
		return loc
	}
	return time.FixedZone("MSK", 3*60*60)
}()

var weekKeyRe = regexp.MustCompile(`^(\d{4})-W(\d{2})$`)

var errBadWeek = errors.New("week key must be YYYY-Www of an existing ISO week")

// WeekKey — ISO-неделя дня d (в поясе d): YYYY-Www.
func WeekKey(d time.Time) string {
	y, w := d.ISOWeek()
	return fmt.Sprintf("%04d-W%02d", y, w)
}

// ParseWeek — понедельник 00:00 (loc) недели YYYY-Www; несуществующая неделя —
// ошибка.
func ParseWeek(key string, loc *time.Location) (time.Time, error) {
	m := weekKeyRe.FindStringSubmatch(key)
	if m == nil {
		return time.Time{}, errBadWeek
	}
	year, _ := strconv.Atoi(m[1])
	week, _ := strconv.Atoi(m[2])
	// 4 января всегда в первой ISO-неделе года.
	jan4 := time.Date(year, time.January, 4, 0, 0, 0, 0, loc)
	monday := jan4.AddDate(0, 0, -((int(jan4.Weekday())+6)%7)+(week-1)*7)
	if y, w := monday.ISOWeek(); week < 1 || y != year || w != week {
		return time.Time{}, errBadWeek
	}
	return monday, nil
}
