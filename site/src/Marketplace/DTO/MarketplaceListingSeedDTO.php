<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

use Webmozart\Assert\Assert;

final readonly class MarketplaceListingSeedDTO
{
    public string $marketplaceSku;
    public ?string $supplierSku;
    public ?string $name;

    public function __construct(string $marketplaceSku, ?string $supplierSku = null, ?string $name = null)
    {
        $marketplaceSku = trim($marketplaceSku);
        Assert::notEmpty($marketplaceSku);

        $this->marketplaceSku = $marketplaceSku;
        $this->supplierSku = $this->optionalString($supplierSku);
        $this->name = $this->optionalString($name);
    }

    private function optionalString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
