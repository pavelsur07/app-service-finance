<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class ShopOptionView
{
    public function __construct(
        public string $shopRef,
        public string $label,
    ) {
    }

    /**
     * @return array{shop_ref: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'shop_ref' => $this->shopRef,
            'label' => $this->label,
        ];
    }
}
