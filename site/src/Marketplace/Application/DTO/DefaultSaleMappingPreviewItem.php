<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\DefaultSaleMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;

final readonly class DefaultSaleMappingPreviewItem
{
    public function __construct(
        private MarketplaceType $marketplace,
        private AmountSource $amountSource,
        private string $plCode,
        private ?string $plCategoryId,
        private ?string $plCategoryName,
        private ?string $existingMappingId,
        private ?string $existingPlCategoryName,
        private bool $isNegative,
        private bool $expectedNegative,
        private ?string $description,
        private DefaultSaleMappingPreviewStatus $status,
        private string $message,
    ) {
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getAmountSource(): AmountSource
    {
        return $this->amountSource;
    }

    public function getOperationType(): string
    {
        return $this->amountSource->getOperationType();
    }

    public function getPlCode(): string
    {
        return $this->plCode;
    }

    public function getPlCategoryId(): ?string
    {
        return $this->plCategoryId;
    }

    public function getPlCategoryName(): ?string
    {
        return $this->plCategoryName;
    }

    public function getExistingMappingId(): ?string
    {
        return $this->existingMappingId;
    }

    public function getExistingPlCategoryName(): ?string
    {
        return $this->existingPlCategoryName;
    }

    public function isNegative(): bool
    {
        return $this->isNegative;
    }

    public function isExpectedNegative(): bool
    {
        return $this->expectedNegative;
    }

    /**
     * Настроенное правило со знаком, противоречащим эталону. Автонастройка его
     * не трогает, но обязана показать расхождение: неверный знак у возврата
     * прибавляет его к выручке вместо вычитания.
     */
    public function hasSignMismatch(): bool
    {
        return null !== $this->existingMappingId && $this->isNegative !== $this->expectedNegative;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getStatus(): DefaultSaleMappingPreviewStatus
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
