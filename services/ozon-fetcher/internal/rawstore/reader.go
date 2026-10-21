package rawstore

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"strings"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
)

// Getter — чтение объекта S3 (фейк в тестах).
type Getter interface {
	GetObject(ctx context.Context, in *s3.GetObjectInput, opts ...func(*s3.Options)) (*s3.GetObjectOutput, error)
}

// MaxReadBytes — потолок несжатого объекта при чтении (как MaxBodyBytes клиента Ozon).
const MaxReadBytes = 64 << 20

// DefaultGetTimeout — предел одного чтения вместе с повторами SDK.
const DefaultGetTimeout = 2 * time.Minute

// ErrChecksumMismatch — содержимое объекта не совпало с ожидаемым sha256.
var ErrChecksumMismatch = errors.New("rawstore: checksum mismatch")

// Reader читает raw-объекты своего бакета: зависимый ресурс (начисления по
// отправлениям) берёт номера из уже загруженных страниц заказов.
type Reader struct {
	client     Getter
	bucket     string
	getTimeout time.Duration
}

func NewReader(client Getter, bucket string) *Reader {
	return &Reader{client: client, bucket: bucket, getTimeout: DefaultGetTimeout}
}

// Get возвращает исходные (несжатые) байты объекта uri (s3://bucket/key) и
// сверяет их sha256 с ожидаемым: расхождение — ErrChecksumMismatch.
func (r *Reader) Get(ctx context.Context, uri, wantSHA256 string) ([]byte, error) {
	key, ok := strings.CutPrefix(uri, "s3://"+r.bucket+"/")
	if !ok || key == "" {
		return nil, fmt.Errorf("rawstore: object %q is not in bucket %s", uri, r.bucket)
	}
	getCtx, cancel := context.WithTimeout(ctx, r.getTimeout)
	defer cancel()
	out, err := r.client.GetObject(getCtx, &s3.GetObjectInput{Bucket: aws.String(r.bucket), Key: aws.String(key)})
	if err != nil {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		return nil, fmt.Errorf("rawstore: get %s: %w", key, err)
	}
	defer func() { _ = out.Body.Close() }()

	zr, err := gzip.NewReader(io.LimitReader(out.Body, MaxReadBytes+1))
	if err != nil {
		return nil, fmt.Errorf("rawstore: gunzip %s: %w", key, err)
	}
	body, err := io.ReadAll(io.LimitReader(zr, MaxReadBytes+1))
	if err != nil {
		return nil, fmt.Errorf("rawstore: read %s: %w", key, err)
	}
	if len(body) > MaxReadBytes {
		return nil, fmt.Errorf("rawstore: object %s exceeds %d bytes", key, MaxReadBytes)
	}
	sum := sha256.Sum256(body)
	if got := hex.EncodeToString(sum[:]); !strings.EqualFold(got, wantSHA256) {
		return nil, fmt.Errorf("%w: %s: want %s, got %s", ErrChecksumMismatch, key, wantSHA256, got)
	}
	return bytes.Clone(body), nil
}
