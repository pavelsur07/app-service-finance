// Package httpapi — HTTP API wb-fetcher поверх fetchkit/httpapi: имя сервиса
// и ожидания покрытия по кабинетам WB.
package httpapi

import (
	fkhttp "github.com/pavelsur07/app-service-finance/services/shared/fetchkit/httpapi"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
)

// Service — имя сервиса в ответе покрытия (service-coverage v1).
const Service = "wb-fetcher"

// Expectation — сколько кабинетов ждёт каждый ресурс: кабинет с токеном,
// ресурс выдан в снимке.
func Expectation(cabinets []connections.Cabinet) fkhttp.Expectation {
	exp := fkhttp.Expectation{ByResource: map[string]int{}}
	for _, cab := range cabinets {
		if cab.Token == "" {
			continue
		}
		exp.Total++
		exp.Refs = append(exp.Refs, cab.Ref)
		seen := map[string]bool{}
		for _, rt := range cab.ResourceTypes {
			if !seen[rt] {
				seen[rt] = true
				exp.ByResource[rt]++
			}
		}
	}
	return exp
}
