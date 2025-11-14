<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Enum\DocumentType;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\ContextAwareDenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ContextAwareNormalizerInterface;

final class DocumentTypeNormalizer implements ContextAwareNormalizerInterface, ContextAwareDenormalizerInterface
{
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof DocumentType;
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        \assert($object instanceof DocumentType);

        return $object->value;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return DocumentType::class === $type;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): DocumentType
    {
        if (!\is_string($data) || '' === trim($data)) {
            throw new NotNormalizableValueException('Document type must be a non-empty string.');
        }

        try {
            return DocumentType::fromValue($data);
        } catch (\ValueError $e) {
            throw new NotNormalizableValueException(sprintf('Unknown document type "%s".', $data), previous: $e);
        }
    }
}
