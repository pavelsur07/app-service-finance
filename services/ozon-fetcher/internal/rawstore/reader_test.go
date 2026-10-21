package rawstore

import (
	"bytes"
	"context"
	"errors"
	"io"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/aws-sdk-go-v2/service/s3/types"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// getterFromPutter — чтение из того, что записал fakePutter.
type getterFromPutter struct{ p *fakePutter }

func (g getterFromPutter) GetObject(_ context.Context, in *s3.GetObjectInput, _ ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	g.p.mu.Lock()
	defer g.p.mu.Unlock()
	body, ok := g.p.objects[aws.ToString(in.Key)]
	if !ok {
		return nil, &types.NoSuchKey{}
	}
	return &s3.GetObjectOutput{Body: io.NopCloser(bytes.NewReader(body))}, nil
}

func TestReaderReturnsOriginalBytesOfStoredObject(t *testing.T) {
	putter := &fakePutter{}
	store := New(putter, "raw", 1)
	stored, err := store.Put(context.Background(), page(`{"postings":[{"posting_number":"P-1"}]}`))
	require.NoError(t, err)

	got, err := NewReader(getterFromPutter{putter}, "raw").Get(context.Background(), stored.URI, stored.SHA256)

	require.NoError(t, err)
	assert.JSONEq(t, `{"postings":[{"posting_number":"P-1"}]}`, string(got))
}

func TestReaderRejectsChecksumMismatchForeignBucketAndMissingObject(t *testing.T) {
	putter := &fakePutter{}
	stored, err := New(putter, "raw", 1).Put(context.Background(), page(`{"a":1}`))
	require.NoError(t, err)
	reader := NewReader(getterFromPutter{putter}, "raw")

	_, err = reader.Get(context.Background(), stored.URI, sha256Of("other"))
	require.ErrorIs(t, err, ErrChecksumMismatch)

	require.ErrorIs(t, err, ErrObjectInvalid)

	_, err = NewReader(getterFromPutter{putter}, "other-bucket").Get(context.Background(), stored.URI, stored.SHA256)
	require.ErrorIs(t, err, ErrObjectInvalid)

	_, err = reader.Get(context.Background(), "s3://raw/missing.json.gz", stored.SHA256)
	require.ErrorIs(t, err, ErrObjectNotFound)

	putter.objects["broken.json.gz"] = []byte("not gzip")
	_, err = reader.Get(context.Background(), "s3://raw/broken.json.gz", stored.SHA256)
	require.ErrorIs(t, err, ErrObjectInvalid)
}

func TestReaderKeepsTransientErrorsRetryable(t *testing.T) {
	_, err := NewReader(failingGetter{errors.New("connection reset")}, "raw").Get(context.Background(), "s3://raw/a.json.gz", "x")
	require.Error(t, err)
	assert.NotErrorIs(t, err, ErrObjectInvalid)
	assert.NotErrorIs(t, err, ErrObjectNotFound)
}

type failingGetter struct{ err error }

func (f failingGetter) GetObject(context.Context, *s3.GetObjectInput, ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	return nil, f.err
}

func sha256Of(s string) string {
	stored, _ := New(&fakePutter{}, "x", 1).Put(context.Background(), page(s))
	return stored.SHA256
}
