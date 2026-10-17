<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO\Internal;

use App\Marketplace\Enum\MarketplaceConnectionType;

/**
 * Активное подключение с расшифрованным ключом — строка для снимка
 * `marketplace-connections` v1. `secret` = null: ключ не удалось расшифровать.
 */
final readonly class ActiveConnectionCredentials
{
    public function __construct(
        public string $connectionId,
        public string $companyId,
        public MarketplaceConnectionType $type,
        public ?string $clientId,
        public ?string $secret,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
