<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\YooKassa;

/**
 * Объект платежа ЮKassa (API v3) — только поля, нужные биллингу.
 */
final readonly class YooKassaPayment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_WAITING_FOR_CAPTURE = 'waiting_for_capture';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_CANCELED = 'canceled';

    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $id,
        public string $status,
        public bool $paid,
        public int $amountKopecks,
        public string $currency,
        public ?string $confirmationUrl,
        public array $metadata,
        public ?\DateTimeImmutable $capturedAt,
    ) {
    }

    /**
     * null — ответ не похож на платёж ЮKassa.
     *
     * @param array<mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $id = $payload['id'] ?? null;
        $status = $payload['status'] ?? null;
        $amount = $payload['amount'] ?? null;
        if (!\is_string($id) || '' === $id || !\is_string($status) || !\is_array($amount)) {
            return null;
        }
        if (!\in_array($status, [self::STATUS_PENDING, self::STATUS_WAITING_FOR_CAPTURE, self::STATUS_SUCCEEDED, self::STATUS_CANCELED], true)) {
            return null;
        }
        $kopecks = YooKassaAmount::parse($amount['value'] ?? null);
        $currency = $amount['currency'] ?? null;
        if (null === $kopecks || !\is_string($currency)) {
            return null;
        }

        $confirmation = $payload['confirmation'] ?? null;
        $confirmationUrl = \is_array($confirmation) && \is_string($confirmation['confirmation_url'] ?? null)
            ? $confirmation['confirmation_url']
            : null;

        $metadata = [];
        if (\is_array($payload['metadata'] ?? null)) {
            foreach ($payload['metadata'] as $key => $value) {
                if (\is_string($key) && \is_string($value)) {
                    $metadata[$key] = $value;
                }
            }
        }

        $capturedAt = null;
        if (\is_string($payload['captured_at'] ?? null)) {
            try {
                $capturedAt = new \DateTimeImmutable($payload['captured_at']);
            } catch (\Exception) {
                $capturedAt = null;
            }
        }

        return new self($id, $status, true === ($payload['paid'] ?? false), $kopecks, $currency, $confirmationUrl, $metadata, $capturedAt);
    }
}
