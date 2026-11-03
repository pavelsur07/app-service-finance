package httpapi

import (
	"testing"

	"github.com/stretchr/testify/assert"

	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/connections"
	"github.com/pavelsur07/app-service-finance/services/wb-fetcher/internal/executor"
)

func TestExpectationCountsCabinetsPerResource(t *testing.T) {
	exp := Expectation([]connections.Cabinet{
		{Ref: "a", Token: "t", ResourceTypes: []string{executor.ResourceDaily, executor.ResourceDaily}},
		{Ref: "b", Token: "t", ResourceTypes: []string{}},
		{Ref: "c", Token: "", ResourceTypes: []string{executor.ResourceDaily}},
	})

	assert.Equal(t, []string{"a", "b"}, exp.Refs, "без токена кабинет не грузится")
	assert.Equal(t, 2, exp.Total)
	assert.Equal(t, map[string]int{executor.ResourceDaily: 1}, exp.ByResource)
	assert.Equal(t, "wb-fetcher", Service)
}
