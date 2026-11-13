<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Application\DTO;

use App\MarketplaceOperations\Enum\OperationKind;
use App\Shared\Domain\ValueObject\Money;
use Webmozart\Assert\Assert;

/**
 * Одна операция дня. Сторно — тот же вид и `sourceType` с обратным знаком суммы.
 */
final readonly class OperationInput
{
    /**
     * @param list<string> $sourceRefs ключи исходных фактов (`SourceFacts`), хотя бы один
     */
    public function __construct(
        public OperationKind $kind,
        /** Код маркетплейса как есть: `ozon:service:32`, `wb:delivery_rub`. */
        public string $sourceType,
        public Money $amount,
        public array $sourceRefs,
        /** Пусто — затрата без SKU. */
        public ?string $listingId = null,
        public ?int $quantity = null,
        /** Ключ разнесения, если сумма разнесена по SKU. */
        public ?string $allocationKey = null,
    ) {
        Assert::stringNotEmpty(trim($sourceType), 'source_type обязателен');
        Assert::maxLength($sourceType, 128);
        Assert::notEmpty($sourceRefs, 'нужна хотя бы одна ссылка на исходный факт');
        Assert::allStringNotEmpty($sourceRefs);
        Assert::nullOrUuid($listingId);
    }
}
