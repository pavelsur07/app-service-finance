package facts

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/csv"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"sort"
	"strings"

	fkhttp "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/httpapi"
)

// ResourceAdsStatistics — дневной отчёт статистики Performance
// (executor.ResourceAdsStatistics): CSV одной кампании или ZIP из CSV по
// кампаниям, либо {"reports":[]} у кабинета без кампаний.
const ResourceAdsStatistics = "ozon.ads.statistics"

const adsKeyPrefix = "ozon:ads-statistics:"

var (
	// campaignNo — «№ 14275771» в первой строке CSV или «14275771_…» в имени файла.
	campaignNo   = regexp.MustCompile(`№\s*(\d+)`)
	campaignFile = regexp.MustCompile(`^(\d+)_`)
	keyPart      = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,64}$`)
)

// splitAdsStatistics — запись = строка отчёта (кампания × день × SKU) как в
// CSV: колонки по заголовку, значения строками, плюс campaign_id. Итог
// «Всего» и шапка отбрасываются, смысл колонок разбирает Symfony.
func splitAdsStatistics(page []byte) ([]fkhttp.FactRecord, error) {
	if bytes.HasPrefix(page, []byte("{")) {
		var empty struct {
			Reports []json.RawMessage `json:"reports"`
		}
		if err := json.Unmarshal(page, &empty); err != nil || empty.Reports == nil || len(empty.Reports) > 0 {
			return nil, errors.New(`facts: ads statistics json page is not {"reports":[]}`)
		}
		return []fkhttp.FactRecord{}, nil
	}
	files := map[string][]byte{"": page}
	if bytes.HasPrefix(page, []byte("PK\x03\x04")) {
		zr, err := zip.NewReader(bytes.NewReader(page), int64(len(page)))
		if err != nil {
			return nil, fmt.Errorf("facts: ads statistics zip: %w", err)
		}
		files = map[string][]byte{}
		for _, f := range zr.File {
			if f.FileInfo().IsDir() {
				continue
			}
			rc, err := f.Open()
			if err != nil {
				return nil, fmt.Errorf("facts: ads statistics zip %q: %w", f.Name, err)
			}
			body, err := io.ReadAll(rc)
			_ = rc.Close()
			if err != nil {
				return nil, fmt.Errorf("facts: ads statistics zip %q: %w", f.Name, err)
			}
			files[f.Name] = body
		}
	}
	names := make([]string, 0, len(files))
	for name := range files {
		names = append(names, name)
	}
	sort.Strings(names)

	out := []fkhttp.FactRecord{}
	seen := map[string]int{}
	for _, name := range names {
		rows, err := adsCSVRows(name, files[name])
		if err != nil {
			return nil, fmt.Errorf("facts: ads statistics %q: %w", name, err)
		}
		for _, row := range rows {
			payload, err := json.Marshal(row)
			if err != nil {
				return nil, err
			}
			key := adsKeyPrefix + safePart(row["campaign_id"], payload) + ":" + safePart(row["sku"], payload)
			// Повтор строки кампании и SKU в отчёте дня — отдельная запись по порядку.
			seen[key]++
			if n := seen[key]; n > 1 {
				key = fmt.Sprintf("%s:%d", key, n)
			}
			out = append(out, fkhttp.FactRecord{SourceKey: key, Payload: payload})
		}
	}
	return out, nil
}

// adsCSVRows — строки данных одного CSV отчёта.
func adsCSVRows(fileName string, body []byte) ([]map[string]string, error) {
	r := csv.NewReader(bytes.NewReader(bytes.TrimPrefix(body, []byte("\xef\xbb\xbf"))))
	r.Comma, r.FieldsPerRecord, r.LazyQuotes = ';', -1, true
	records, err := r.ReadAll()
	if err != nil {
		return nil, err
	}
	campaign := ""
	if m := campaignFile.FindStringSubmatch(fileName[strings.LastIndex(fileName, "/")+1:]); m != nil {
		campaign = m[1]
	}
	var header []string
	var rows []map[string]string
	for _, rec := range records {
		if header == nil {
			if col := indexFold(rec, "sku"); col >= 0 {
				header = rec
				continue
			}
			if m := campaignNo.FindStringSubmatch(strings.Join(rec, ";")); m != nil {
				campaign = m[1]
			}
			continue
		}
		row := map[string]string{"campaign_id": campaign}
		for i, name := range header {
			if i < len(rec) && name != "" {
				row[name] = strings.TrimSpace(rec[i])
			}
		}
		if row[header[indexFold(header, "sku")]] == "" {
			continue // итог «Всего» и пустые строки
		}
		row["sku"] = row[header[indexFold(header, "sku")]]
		rows = append(rows, row)
	}
	if header == nil && len(records) > 0 {
		return nil, errors.New(`no header row with "sku"`)
	}
	return rows, nil
}

func indexFold(rec []string, name string) int {
	for i, v := range rec {
		if strings.EqualFold(strings.TrimSpace(v), name) {
			return i
		}
	}
	return -1
}

// safePart — часть ключа как есть, если безопасна, иначе хеш записи.
func safePart(v string, payload []byte) string {
	if keyPart.MatchString(v) {
		return v
	}
	sum := sha256.Sum256(payload)
	return "x" + hex.EncodeToString(sum[:])[:16]
}
