package apierr

import (
	"errors"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestErrorTextCarriesSource(t *testing.T) {
	cause := errors.New("connection reset")
	assert.Equal(t, "wb: transient /api/finance/v1/sales-reports/detailed: connection reset",
		(&APIError{Source: "wb", Class: ClassTransient, Endpoint: "/api/finance/v1/sales-reports/detailed", Err: cause}).Error())
	assert.Equal(t, "ozon: obsolete_endpoint /v3/x: HTTP 400 code 9: method is obsolete",
		(&APIError{Source: "ozon", Class: ClassObsoleteEndpoint, Endpoint: "/v3/x", Status: 400, Code: 9, Message: "method is obsolete"}).Error())
	assert.Equal(t, "api: rate_limit /x: HTTP 429 code 0", (&APIError{Class: ClassRateLimit, Endpoint: "/x", Status: 429}).Error())
	assert.ErrorIs(t, &APIError{Class: ClassTransient, Err: cause}, cause)
}
