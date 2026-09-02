<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

use Webmozart\Assert\Assert;

/**
 * Активное SELLER-подключение в виде, пригодном для пересечения границы
 * модуля: скаляры, без Entity и без Doctrine.
 */
final readonly class ActiveSellerConnectionDTO
{
    public function __construct(
        public string $connectionRef,
        public string $companyId,
        public string $marketplace,
    ) {
        Assert::notEmpty($connectionRef);
        Assert::uuid($companyId);
        Assert::notEmpty($marketplace);
    }
}
